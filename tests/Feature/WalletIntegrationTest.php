<?php

namespace Tests\Feature;

use App\Jobs\ExtractReceipt;
use App\Jobs\MatchWalletEvent;
use App\Models\Account;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\FakeReceiptExtractor;
use App\Services\LedgerService;
use App\Services\PurchaseMatcher;
use App\Services\ReceiptExtractor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WalletIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['spendtrace.legacy_wallet_server_enabled' => true]);
        $this->withoutVite();
        config(['spendtrace.provider' => 'fake']);
    }

    private function connection(?User $user = null, ?Account $account = null): array
    {
        $account ??= Account::factory()->create($user ? ['user_id' => $user->id] : []);
        $token = 'st_'.bin2hex(random_bytes(32));
        $connection = WalletConnection::factory()->create(['account_id' => $account->id, 'user_id' => $account->user_id, 'token_hash' => hash('sha256', $token)]);

        return [$connection, $token, User::findOrFail($account->user_id)];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['event_id' => (string) Str::uuid(), 'observed_at' => '2026-11-11T14:36:00-05:00', 'transaction_at' => '2026-11-11T14:35:00-05:00',
            'merchant' => 'Example Shop', 'amount_decimal' => '86.42', 'currency' => 'USD'], $overrides);
    }

    private function ingest(string $token, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/integrations/wallet/events', $payload, ['Authorization' => 'Bearer '.$token]);
    }

    private function receipt(WalletConnection $connection, array $overrides = []): Transaction
    {
        $receipt = Receipt::factory()->create(['user_id' => $connection->user_id, 'status' => 'ready']);

        return Transaction::factory()->create(array_replace(['receipt_id' => $receipt->id, 'user_id' => $connection->user_id,
            'account_id' => $connection->account_id, 'merchant' => 'Example Shop', 'purchase_date' => '2026-11-11', 'purchase_time' => '14:35:00',
            'total_decimal' => '86.42', 'total_minor' => 8642, 'currency' => 'USD'], $overrides));
    }

    public function test_credentials_are_hashed_rotatable_revocable_and_isolated(): void
    {
        [$c, $old, $user] = $this->connection();
        [$other, $otherToken, $otherUser] = $this->connection();
        $this->actingAs($otherUser)->postJson('/integrations/wallet/'.$c->id.'/token')->assertNotFound();
        $response = $this->actingAs($user)->postJson('/integrations/wallet/'.$c->id.'/token')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $new = $response->json('token');
        $this->assertSame(hash('sha256', $new), $c->fresh()->token_hash);
        $this->assertStringNotContainsString($new, json_encode($c->fresh()));
        $this->ingest($old, $this->payload())->assertUnauthorized();
        $this->ingest($new, $this->payload(['test' => true]))->assertCreated();
        $this->actingAs($user)->delete('/integrations/wallet/'.$c->id.'/token')->assertRedirect();
        $this->ingest($new, $this->payload())->assertUnauthorized();
        $this->ingest($otherToken, $this->payload(['test' => true]))->assertCreated();
    }

    public function test_owner_only_setup_status_history_configuration_and_deletion(): void
    {
        [$c, $token, $owner] = $this->connection();
        [$other, , $attacker] = $this->connection();
        $this->get('/integrations/wallet')->assertRedirect('/login');
        $this->actingAs($attacker)->get('/integrations/wallet')->assertInertia(fn (Assert $p) => $p->component('Wallet')->has('connections', 1)->where('connections.0.id', $other->id)->missing('connections.0.token_hash'));
        $this->getJson('/integrations/wallet/'.$c->id.'/status')->assertNotFound();
        $this->patch('/integrations/wallet/'.$c->id, ['name' => 'stolen', 'enabled' => true])->assertNotFound();
        $this->delete('/integrations/wallet/'.$c->id)->assertNotFound();
        $this->delete('/integrations/wallet/'.$c->id.'/token')->assertNotFound();
        $this->post('/integrations/wallet', ['name' => 'bad', 'account_id' => $c->account_id, 'enabled' => true])->assertSessionHasErrors('account_id');
        $this->actingAs($owner)->patch('/integrations/wallet/'.$c->id, ['name' => 'new', 'enabled' => true, 'account_id' => $other->account_id])->assertSessionHasErrors('account_id');
        $this->assertSame($c->account_id, $c->fresh()->account_id);
        $this->ingest($token, $this->payload())->assertCreated();
        $this->actingAs($owner)->getJson('/integrations/wallet/'.$c->id.'/status')->assertOk()->assertJsonCount(1, 'events')->assertJsonMissingPath('connection.token_hash');
        $this->delete('/integrations/wallet/'.$c->id)->assertRedirect();
        $this->ingest($token, $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('wallet_events', 1);
        $this->assertDatabaseCount('transactions', 1);
    }

    #[TestWith(['user_id'])]
    #[TestWith(['account_id'])]
    #[TestWith(['connection_id'])]
    #[TestWith(['wallet_connection_id'])]
    #[TestWith(['transaction_id'])]
    public function test_ownership_fields_are_rejected_even_when_null(string $field): void
    {
        [, $token] = $this->connection();
        $this->ingest($token, $this->payload([$field => null]))->assertUnprocessable()->assertJsonValidationErrors('payload');
        $this->assertDatabaseCount('wallet_events', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_bearer_credentials_are_required_even_with_browser_session_and_disabled_tokens_fail(): void
    {
        [$c, $token, $user] = $this->connection();
        $this->actingAs($user)->postJson('/api/v1/integrations/wallet/events', $this->payload())->assertUnauthorized();
        $c->update(['enabled' => false]);
        $this->ingest($token, $this->payload())->assertUnauthorized();
        $this->assertDatabaseCount('wallet_events', 0);
    }

    #[TestWith(['amount_decimal', 86.42])]
    #[TestWith(['amount_decimal', '1,000.00'])]
    #[TestWith(['amount_decimal', '1e3'])]
    #[TestWith(['amount_decimal', '0.1234567'])]
    #[TestWith(['amount_decimal', '1.234'])]
    #[TestWith(['currency', 'usd'])]
    #[TestWith(['observed_at', '2026-11-11T14:30:00'])]
    #[TestWith(['transaction_at', 'yesterday'])]
    #[TestWith(['event_id', 'not-a-uuid'])]
    public function test_invalid_contract_is_rejected(string $field, mixed $value): void
    {
        [, $token] = $this->connection();
        $this->ingest($token, $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('wallet_events', 0);
    }

    public function test_size_content_type_and_rate_limits(): void
    {
        [, $token] = $this->connection();
        $this->ingest($token, $this->payload(['merchant' => str_repeat('x', 8200)]))->assertStatus(413);
        $this->post('/api/v1/integrations/wallet/events', [], ['Authorization' => 'Bearer '.$token])->assertStatus(415);
        $this->call('POST', '/api/v1/integrations/wallet/events', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_AUTHORIZATION' => 'Bearer '.$token], '{invalid')->assertUnprocessable();
        [$c] = $this->connection();
        RateLimiter::increment('wallet-connection:'.$c->id, 60, 60);
        $token2 = 'st_'.str_repeat('a', 64);
        $c->update(['token_hash' => hash('sha256', $token2)]);
        $this->ingest($token2, $this->payload())->assertStatus(429);
    }

    #[TestWith([null, null, '1.234500', null, 'unknown'])]
    #[TestWith([null, 'EUR', '86.42', 8642, 'connection_default'])]
    #[TestWith(['KWD', 'USD', '1.234', 1234, 'payload'])]
    #[TestWith(['JPY', null, '1200', 1200, 'payload'])]
    public function test_exact_decimals_and_currency_provenance(?string $currency, ?string $default, string $amount, ?int $minor, string $source): void
    {
        [$c, $token] = $this->connection();
        $c->update(['default_currency' => $default]);
        $this->ingest($token, $this->payload(['currency' => $currency, 'amount_decimal' => $amount]))->assertCreated()->assertJsonMissingPath('transaction_id');
        $event = $c->events()->sole();
        $this->assertSame($source, $event->currency_source);
        $this->assertSame($amount, $event->transaction->total_decimal);
        $this->assertSame($minor, $event->transaction->total_minor);
        $this->assertSame($c->account_id, $event->transaction->account_id);
        $this->assertSame($c->user_id, $event->transaction->user_id);
        $this->assertSame('unreconciled', $event->transaction->reconciliation_status);
        $this->assertNull($event->transaction->receipt_id);
    }

    public function test_idempotency_is_connection_scoped_and_conflicting_reuse_is_rejected(): void
    {
        [$c, $token, $user] = $this->connection();
        [$other, $second] = $this->connection($user);
        $c->update(['default_currency' => 'USD']);
        $payload = $this->payload(['currency' => null]);
        $this->ingest($token, $payload)->assertCreated();
        $c->update(['default_currency' => 'EUR']);
        $this->ingest($token, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->ingest($token, array_replace($payload, ['merchant' => 'different']))->assertConflict();
        $this->assertSame('USD', $c->events()->sole()->currency);
        $this->ingest($second, $payload)->assertCreated();
        $this->assertDatabaseCount('wallet_events', 2);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertSame($other->account_id, $other->events()->sole()->transaction->account_id);
    }

    public function test_wallet_corrections_survive_attachment_and_later_extraction_conflicts(): void
    {
        [$c, $token, $user] = $this->connection();
        $this->ingest($token, $this->payload())->assertCreated();
        $event = $c->events()->sole();
        $event->transaction->update(['category' => 'Health', 'corrected_fields' => ['category']]);
        $receipt = $this->receipt($c, ['category' => 'Shopping']);
        app(PurchaseMatcher::class)->forReceipt($receipt);
        $this->assertSame('Health', $receipt->fresh()->category);
        $receipt->receipt->update(['generation' => 2, 'status' => 'queued']);
        $data = array_replace((new FakeReceiptExtractor)->extract(new Receipt), ['merchant' => 'Example Shop', 'purchase_date' => '2026-11-11',
            'currency' => 'EUR', 'total_minor' => 10000, 'category' => 'Other']);
        $this->mock(ReceiptExtractor::class)->shouldReceive('extract')->once()->andReturn($data);
        app()->call([new ExtractReceipt($receipt->receipt_id, 2), 'handle']);
        $receipt->refresh();
        $this->assertSame('USD', $receipt->currency);
        $this->assertSame('86.42', $receipt->total_decimal);
        $this->assertSame('Health', $receipt->category);
        $this->assertStringContainsString('conflicts with the attached Wallet', implode(' ', $receipt->review_reasons));
        $this->assertSame('EUR', $receipt->receipt->runs()->sole()->output['currency']);
        $this->actingAs($user)->post('/wallet-matches/'.$event->id.'/separate')->assertRedirect();
        $this->assertSame('Health', $receipt->fresh()->category);
        $this->assertSame('Health', Transaction::find($event->original_transaction_id)->category);
    }

    public function test_wallet_only_purchase_can_be_edited_but_not_redirected_to_another_account(): void
    {
        [$c, $token, $user] = $this->connection();
        $other = Account::factory()->create(['user_id' => $user->id]);
        $this->ingest($token, $this->payload())->assertCreated();
        $purchase = $c->events()->sole()->transaction;
        $data = ['revision' => $purchase->revision, 'merchant' => 'Corrected Shop', 'purchase_date' => '2026-11-11', 'purchase_time' => '14:35',
            'amount' => '86.42', 'currency' => 'USD', 'account_id' => $other->id, 'category' => 'Health', 'review_status' => 'needs_review', 'excluded' => false, 'items' => []];
        $this->actingAs($user)->patch('/transactions/'.$purchase->id, $data)->assertSessionHasErrors('account_id');
        $this->patch('/transactions/'.$purchase->id, array_replace($data, ['account_id' => $c->account_id]))->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame('Corrected Shop', $purchase->fresh()->merchant);
        $this->get('/transactions/'.$purchase->id)->assertInertia(fn (Assert $p) => $p->where('transaction.receipt', null)->has('transaction.observations', 1));
    }

    public function test_accepted_queued_events_survive_connection_disabling_and_deletion(): void
    {
        Queue::fake([MatchWalletEvent::class]);
        [$c, $token, $user] = $this->connection();
        $receipt = $this->receipt($c);
        $this->ingest($token, $this->payload())->assertCreated();
        $event = $c->events()->sole();
        Queue::assertPushed(MatchWalletEvent::class);
        $this->actingAs($user)->delete('/integrations/wallet/'.$c->id)->assertRedirect();
        app()->call([unserialize(serialize(new MatchWalletEvent($event->id))), 'handle']);
        $this->assertSame($receipt->id, $event->fresh()->transaction_id);
        $this->assertSame('matched', $event->fresh()->status);
        $this->ingest($token, $this->payload())->assertUnauthorized();
    }

    #[TestWith(['14:40:00', 'matched'])]
    #[TestWith(['14:40:01', 'needs_review'])]
    public function test_five_minute_match_tolerance_is_not_expanded(string $time, string $status): void
    {
        [$c, $token] = $this->connection();
        $this->receipt($c, ['purchase_time' => $time]);
        $this->ingest($token, $this->payload())->assertCreated()->assertJsonPath('status', $status);
    }

    public function test_refunds_are_not_automatically_matched(): void
    {
        [$c, $token] = $this->connection();
        $this->receipt($c, ['total_decimal' => '-86.42', 'total_minor' => -8642]);
        $this->ingest($token, $this->payload(['amount_decimal' => '-86.42']))->assertCreated()->assertJsonPath('status', 'needs_review');
        $this->assertSame(2, Transaction::whereNull('merged_into_id')->count());
    }

    public function test_test_requests_are_durable_but_do_not_affect_spending(): void
    {
        [$c, $token] = $this->connection();
        $this->ingest($token, $this->payload(['test' => true]))->assertCreated()->assertJsonPath('status', 'test_received');
        $this->assertNotNull($c->fresh()->last_test_at);
        $this->assertNull($c->fresh()->last_event_at);
        $this->assertDatabaseCount('wallet_events', 1);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_receipt_first_matching_preserves_items_corrections_and_counts_one_purchase(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 20:00:00'));
        [$c, $token, $user] = $this->connection();
        $receipt = $this->receipt($c, ['merchant' => 'EXAMPLE-Shop', 'category' => 'Health', 'corrected_fields' => ['merchant', 'category', 'items']]);
        $receipt->items()->create(['description' => 'User corrected item', 'amount_decimal' => '86.42', 'amount_minor' => 8642]);
        $this->ingest($token, $this->payload())->assertCreated()->assertJsonPath('status', 'matched');
        $event = $c->events()->sole();
        $this->assertSame($receipt->id, $event->transaction_id);
        $this->assertSame('EXAMPLE-Shop', $receipt->fresh()->merchant);
        $this->assertSame('Health', $receipt->fresh()->category);
        $this->assertSame('User corrected item', $receipt->items()->sole()->description);
        $this->assertSame('86.42', app(LedgerService::class)->summary($user)['totals']->sole()['amount']);
        $this->actingAs($user)->get('/transactions/'.$event->original_transaction_id)->assertRedirect('/transactions/'.$receipt->id);
        $this->get('/wallet-matches')->assertInertia(fn (Assert $p) => $p->component('WalletMatches')->where('events.data.0.status', 'matched'));
        $this->post('/wallet-matches/'.$event->id.'/separate')->assertRedirect();
        $this->assertSame('172.84', app(LedgerService::class)->summary($user)['totals']->sole()['amount']);
        $this->assertSame(1, $receipt->items()->count());
        $this->assertSame('separate', $event->fresh()->status);
        app(PurchaseMatcher::class)->process($event->id);
        $this->assertSame('separate', $event->fresh()->status);
    }

    public function test_wallet_first_receipt_extraction_uses_shared_matcher_and_preserves_history(): void
    {
        [$c, $token] = $this->connection();
        $this->ingest($token, $this->payload())->assertCreated();
        $receipt = $this->receipt($c, ['account_id' => null]);
        $receipt->receipt->update(['status' => 'queued']);
        $data = array_replace((new FakeReceiptExtractor)->extract(new Receipt), [
            'merchant' => 'Example Shop', 'purchase_date' => '2026-11-11', 'purchase_time' => '14:35:00', 'currency' => 'USD',
            'total_minor' => 8642, 'items' => [['description' => 'Original item', 'quantity' => null, 'amount_minor' => 8642, 'category' => null]],
        ]);
        $this->mock(ReceiptExtractor::class)->shouldReceive('extract')->once()->andReturn($data);
        app()->call([new ExtractReceipt($receipt->receipt_id, 1), 'handle']);
        $this->assertSame('ready', $receipt->receipt->fresh()->status);
        $this->assertSame('matched', $c->events()->sole()->status);
        $this->assertSame(1, $receipt->receipt->runs()->count());
        $this->assertSame('Original item', $receipt->items()->sole()->description);
        $this->assertSame(2, $receipt->observations()->count());
    }

    #[TestWith(['currency', 'EUR'])]
    #[TestWith(['total_decimal', '86.43'])]
    #[TestWith(['purchase_time', '14:41:00'])]
    #[TestWith(['purchase_date', '2026-11-13'])]
    #[TestWith(['merchant', 'Example Other Shop'])]
    public function test_conflicting_evidence_does_not_auto_merge(string $field, mixed $value): void
    {
        [$c, $token] = $this->connection();
        $receipt = $this->receipt($c, [$field => $value]);
        $this->ingest($token, $this->payload())->assertCreated();
        $this->assertNotSame('matched', $c->events()->sole()->status);
        $this->assertNull($receipt->fresh()->merged_into_id);
        $this->assertSame(2, Transaction::whereNull('merged_into_id')->count());
    }

    public function test_known_account_and_cross_user_conflicts_never_auto_or_manually_attach(): void
    {
        [$c, $token, $user] = $this->connection();
        [$second] = $this->connection($user);
        [$foreign, , $foreignUser] = $this->connection();
        $differentAccountReceipt = $this->receipt($second);
        $foreignReceipt = $this->receipt($foreign);
        $this->ingest($token, $this->payload())->assertCreated();
        $event = $c->events()->sole();
        $this->assertSame('unmatched', $event->status);
        $this->actingAs($user)->post('/wallet-matches/'.$event->id.'/attach', ['transaction_id' => $foreignReceipt->id, 'confirmed' => true])->assertNotFound();
        $this->post('/wallet-matches/'.$event->id.'/attach', ['transaction_id' => $differentAccountReceipt->id, 'confirmed' => true])->assertSessionHasErrors('match');
        $this->actingAs($foreignUser)->post('/wallet-matches/'.$event->id.'/attach', ['transaction_id' => $foreignReceipt->id, 'confirmed' => true])->assertNotFound();
        $this->post('/wallet-matches/'.$event->id.'/separate')->assertNotFound();
        $this->post('/wallet-matches/'.$event->id.'/retry')->assertNotFound();
        $this->get('/wallet-matches')->assertInertia(fn (Assert $p) => $p->has('events.data', 0));
        $this->get('/transactions/'.$event->transaction_id)->assertNotFound();
        $this->assertSame($c->account_id, $event->transaction->account_id);
        $this->assertSame(3, Transaction::whereNull('merged_into_id')->count());
    }

    #[TestWith([null, null, null, 'needs_review'])]
    #[TestWith(['14:35:00', null, null, 'needs_review'])]
    #[TestWith(['14:35:00', null, '2026-11-11T14:35:00-05:00', 'matched'])]
    #[TestWith([null, 'known', null, 'matched'])]
    public function test_unknown_accounts_need_independent_precise_evidence(?string $time, ?string $known, ?string $actual, string $status): void
    {
        [$c, $token] = $this->connection();
        $this->receipt($c, ['purchase_time' => $time, 'account_id' => $known ? $c->account_id : null]);
        $this->ingest($token, $this->payload(['transaction_at' => $actual]))->assertCreated();
        $this->assertSame($status, $c->events()->sole()->status);
    }

    public function test_equal_unknown_currency_numbers_require_manual_confirmation(): void
    {
        [$c, $token, $user] = $this->connection();
        $receipt = $this->receipt($c, ['currency' => null, 'total_minor' => null]);
        $this->ingest($token, $this->payload(['currency' => null]))->assertCreated()->assertJsonPath('status', 'needs_review');
        $event = $c->events()->sole();
        $this->actingAs($user)->post('/wallet-matches/'.$event->id.'/attach', ['transaction_id' => $receipt->id])->assertSessionHasErrors('confirmed');
        $this->post('/wallet-matches/'.$event->id.'/attach', ['transaction_id' => $receipt->id, 'confirmed' => true])->assertRedirect();
        $this->assertSame('matched', $event->fresh()->status);
        $this->assertNull($receipt->fresh()->currency);
    }

    public function test_multiple_receipts_or_repeated_wallet_purchases_remain_ambiguous(): void
    {
        [$c, $token] = $this->connection();
        $this->receipt($c);
        $this->receipt($c);
        $this->ingest($token, $this->payload())->assertCreated()->assertJsonPath('status', 'needs_review');
        [$other, $second] = $this->connection();
        $this->ingest($second, $this->payload())->assertCreated();
        $this->ingest($second, $this->payload())->assertCreated();
        $receipt = $this->receipt($other);
        app(PurchaseMatcher::class)->forReceipt($receipt);
        $this->assertSame(['needs_review', 'needs_review'], $other->events()->pluck('status')->all());
        $this->assertSame(6, Transaction::whereNull('merged_into_id')->count());
    }

    public function test_timezone_boundary_uses_supplied_offset_or_known_receipt_timezone(): void
    {
        [$c, $token] = $this->connection();
        $receipt = $this->receipt($c, ['purchase_date' => '2026-11-10', 'purchase_time' => '23:58:00', 'purchase_timezone' => 'America/New_York']);
        $this->ingest($token, $this->payload(['transaction_at' => '2026-11-11T04:59:00Z']))->assertCreated()->assertJsonPath('status', 'matched');
        $this->assertSame($receipt->id, $c->events()->sole()->transaction_id);
    }

    public function test_queued_processing_resolves_ownership_from_connection_and_is_reentrant(): void
    {
        Queue::fake([MatchWalletEvent::class]);
        [$c, $token, $user] = $this->connection();
        [$other] = $this->connection();
        $foreign = $this->receipt($other);
        [$otherAccount] = $this->connection($user);
        $differentAccount = $this->receipt($otherAccount);
        $own = $this->receipt($c);
        $this->ingest($token, $this->payload())->assertCreated()->assertJsonPath('status', 'pending');
        $event = $c->events()->sole();
        Queue::assertPushed(MatchWalletEvent::class, fn ($job) => $job->eventId === $event->id);
        $job = unserialize(serialize(new MatchWalletEvent($event->id)));
        app()->call([$job, 'handle']);
        app()->call([$job, 'handle']);
        $this->assertSame($own->id, $event->fresh()->transaction_id);
        $this->assertSame(0, $foreign->observations()->count());
        $this->assertSame(0, $differentAccount->observations()->count());
        $this->assertSame(1, $own->observations()->where('source_type', 'wallet')->count());
        $this->assertSame(3, Transaction::whereNull('merged_into_id')->count());
    }
}
