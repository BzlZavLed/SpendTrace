<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletConnection;
use App\Models\WalletEvent;
use App\Models\WalletPairing;
use App\Services\FakeReceiptExtractor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Real, independent PHP processes against a disposable PostgreSQL database. */
class WalletConcurrencyTest extends TestCase
{
    private bool $prepared = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['spendtrace.legacy_wallet_server_enabled' => true]);
        if (DB::getDriverName() !== 'pgsql' || ! str_ends_with(DB::connection()->getDatabaseName(), '_test')) {
            $this->markTestSkipped('Concurrency checks require a disposable PostgreSQL database ending in _test.');
        }
        $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        $this->prepared = true;
    }

    protected function tearDown(): void
    {
        if ($this->prepared) {
            $this->artisan('migrate:fresh', ['--force' => true])->assertSuccessful();
        }
        parent::tearDown();
    }

    public function test_concurrent_ingestion_and_receipt_extraction_are_idempotent_and_owner_scoped(): void
    {
        $account = Account::factory()->create();
        $token = 'st_'.bin2hex(random_bytes(32));
        $connection = WalletConnection::factory()->create(['account_id' => $account->id, 'user_id' => $account->user_id, 'token_hash' => hash('sha256', $token)]);
        $payload = ['event_id' => (string) Str::uuid(), 'merchant' => 'Concurrent Shop', 'amount_decimal' => '20.10', 'currency' => 'USD',
            'observed_at' => '2026-11-11T12:00:00-05:00', 'transaction_at' => '2026-11-11T12:00:00-05:00'];
        $requestCode = '$request = Illuminate\Http\Request::create("/api/v1/integrations/wallet/events", "POST", [], [], [], ["CONTENT_TYPE"=>"application/json", "HTTP_ACCEPT"=>"application/json", "HTTP_AUTHORIZATION"=>'.var_export('Bearer '.$token, true).'], '.var_export(json_encode($payload), true).'); $response=$app->handle($request); echo $response->getStatusCode();';
        $statuses = $this->concurrently($account->user_id, [$requestCode, $requestCode]);
        sort($statuses);
        $this->assertSame(['200', '201'], $statuses);
        $this->assertDatabaseCount('wallet_events', 1);
        $this->assertDatabaseCount('transactions', 1);
        $event = WalletEvent::sole();

        $receipt = Receipt::factory()->create(['user_id' => $account->user_id]);
        $purchase = Transaction::factory()->create(['receipt_id' => $receipt->id, 'user_id' => $account->user_id]);
        $foreign = Transaction::factory()->create(['merchant' => 'Concurrent Shop', 'total_decimal' => '20.10', 'total_minor' => 2010, 'currency' => 'USD', 'purchase_date' => '2026-11-11']);
        $output = array_replace((new FakeReceiptExtractor)->extract(new Receipt), ['merchant' => 'Concurrent Shop', 'total_minor' => 2010, 'currency' => 'USD',
            'purchase_date' => '2026-11-11', 'purchase_time' => '12:00:00', 'items' => [['description' => 'One item', 'quantity' => null, 'amount_minor' => 2010, 'category' => null]]]);
        $extractCode = '$output='.var_export($output, true).'; $app->instance(App\Services\ReceiptExtractor::class, new class($output) implements App\Services\ReceiptExtractor { public function __construct(private array $output) {} public function extract(App\Models\Receipt $receipt): array { return $this->output; } }); $app->call([new App\Jobs\ExtractReceipt('.$receipt->id.', 1), "handle"]); echo "done";';
        $matchCode = '$app->call([new App\Jobs\MatchWalletEvent('.$event->id.'), "handle"]); echo "done";';
        $this->assertSame(['done', 'done', 'done'], $this->concurrently($account->user_id, [$extractCode, $matchCode, $matchCode]));
        $this->assertSame('matched', $event->fresh()->status);
        $this->assertSame($purchase->id, $event->fresh()->transaction_id);
        $this->assertSame($connection->account_id, $purchase->fresh()->account_id);
        $this->assertSame(2, Transaction::whereNull('merged_into_id')->count());
        $this->assertSame(1, $purchase->items()->count());
        $this->assertSame(1, $receipt->runs()->count());
        $this->assertSame(0, $foreign->observations()->count());
    }

    public function test_pairing_code_is_redeemed_once_by_concurrent_clients(): void
    {
        $connection = WalletConnection::factory()->create();
        $code = bin2hex(random_bytes(16));
        $pairing = WalletPairing::factory()->create(['wallet_connection_id' => $connection->id, 'code_hash' => hash('sha256', $code)]);
        $requestCode = 'config(["spendtrace.wallet_public_url"=>"https://spendtrace.example"]); $request=Illuminate\Http\Request::create("https://spendtrace.example/api/v1/integrations/wallet/pair", "POST", [], [], [], ["CONTENT_TYPE"=>"application/json", "HTTP_ACCEPT"=>"application/json"], '.var_export(json_encode(['code' => $code]), true).'); $response=$app->handle($request); echo $response->getStatusCode();';
        $statuses = $this->concurrently($connection->user_id, [$requestCode, $requestCode]);
        sort($statuses);
        $this->assertSame(['200', '401'], $statuses);
        $this->assertDatabaseCount('wallet_devices', 1);
        $this->assertNotNull($pairing->fresh()->redeemed_at);
    }

    private function concurrently(int $userId, array $programs): array
    {
        $environment = array_merge(getenv(), ['APP_ENV' => 'testing', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'database',
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => config('database.connections.pgsql.host'), 'DB_PORT' => (string) config('database.connections.pgsql.port'),
            'DB_DATABASE' => DB::connection()->getDatabaseName(), 'DB_USERNAME' => config('database.connections.pgsql.username'), 'DB_PASSWORD' => config('database.connections.pgsql.password') ?? '', 'DB_URL' => '']);
        $processes = [];
        DB::beginTransaction();
        User::whereKey($userId)->lockForUpdate()->firstOrFail();
        try {
            foreach ($programs as $program) {
                $bootstrap = 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo "ready\n"; flush(); ';
                $process = proc_open([PHP_BINARY, '-r', $bootstrap.$program], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $environment);
                $this->assertIsResource($process);
                fclose($pipes[0]);
                stream_set_timeout($pipes[1], 20);
                $processes[] = [$process, $pipes];
            }
            foreach ($processes as [$process, $pipes]) {
                $this->assertSame("ready\n", fgets($pipes[1]));
            }
        } finally {
            DB::commit();
        }
        $results = [];
        foreach ($processes as [$process, $pipes]) {
            $results[] = trim(stream_get_contents($pipes[1]));
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $this->assertSame(0, proc_close($process), $error);
        }

        return $results;
    }
}
