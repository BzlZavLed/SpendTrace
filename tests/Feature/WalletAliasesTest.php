<?php

namespace Tests\Feature;

use App\Jobs\MatchWalletEvent;
use App\Models\Account;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletAlias;
use App\Models\WalletConnection;
use App\Models\WalletDevice;
use App\Services\PurchaseMatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WalletAliasesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['spendtrace.legacy_wallet_server_enabled' => true]);
        $this->withoutVite();
    }

    private function device(): array
    {
        $connection = WalletConnection::factory()->create();
        $token = 'wd_'.bin2hex(random_bytes(32));
        $device = WalletDevice::factory()->create(['wallet_connection_id' => $connection->id, 'token_hash' => hash('sha256', $token)]);

        return [$connection, $device, $token];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['event_id' => (string) Str::uuid(), 'observed_at' => '2026-11-11T14:36:00-05:00', 'merchant' => 'Example Shop',
            'amount_decimal' => '86.42', 'currency' => 'USD', 'wallet_alias' => ['label' => 'Everyday Card', 'last_four' => '1234', 'network' => 'Visa']], $overrides);
    }

    private function ingest(string $token, array $payload): TestResponse
    {
        return $this->postJson('https://spendtrace.example/api/v1/integrations/wallet/events', $payload, ['Authorization' => 'Bearer '.$token]);
    }

    public function test_unknown_alias_is_durable_without_purchase_and_owner_can_assign_once(): void
    {
        [$connection, $device, $token] = $this->device();
        $payload = $this->payload();
        $this->ingest($token, $payload)->assertCreated()->assertJsonPath('status', 'needs_account');
        $event = $connection->events()->sole();
        $this->assertSame($device->id, $event->wallet_device_id);
        $this->assertDatabaseCount('transactions', 0);
        $this->ingest($token, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('wallet_events', 1);
        $this->actingAs(User::find($connection->user_id))->get('/integrations/wallet')->assertInertia(fn (Assert $p) => $p->has('unresolved.data', 1)->where('unresolved.data.0.payload.amount_decimal', '86.42'));
        $data = ['account_id' => $connection->account_id, 'remember' => true, 'confirmed' => true];
        $this->post('/wallet-aliases/'.$event->id.'/resolve', $data)->assertSessionHasNoErrors()->assertRedirect();
        $this->post('/wallet-aliases/'.$event->id.'/resolve', $data)->assertConflict();
        $this->assertSame($connection->account_id, $event->fresh()->account_id);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('wallet_aliases', 1);
        $this->ingest($token, $this->payload())->assertCreated()->assertJsonPath('status', 'unmatched');
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_ambiguous_alias_and_label_only_alias_never_guess_an_account(): void
    {
        [$connection, , $token] = $this->device();
        $identifier = $this->payload()['wallet_alias'];
        WalletAlias::factory()->create(['wallet_connection_id' => $connection->id, 'user_id' => $connection->user_id, 'account_id' => $connection->account_id, 'identifier' => $identifier]);
        WalletAlias::factory()->create(['wallet_connection_id' => $connection->id, 'user_id' => $connection->user_id, 'identifier' => $identifier]);
        $this->ingest($token, $this->payload())->assertCreated()->assertJsonPath('status', 'needs_account');
        WalletAlias::factory()->create(['wallet_connection_id' => $connection->id, 'user_id' => $connection->user_id, 'account_id' => $connection->account_id, 'identifier' => ['label' => 'Everyday Card']]);
        $this->ingest($token, $this->payload(['wallet_alias' => ['label' => 'Everyday Card']]))->assertCreated()->assertJsonPath('status', 'needs_account');
        $this->assertDatabaseCount('transactions', 0);
        $event = $connection->events()->latest('id')->first();
        $this->actingAs(User::find($connection->user_id))->post('/wallet-aliases/'.$event->id.'/resolve', ['account_id' => $connection->account_id, 'remember' => true, 'confirmed' => true])->assertSessionHasErrors('remember');
        $this->post('/wallet-aliases/'.$event->id.'/resolve', ['account_id' => $connection->account_id, 'remember' => false, 'confirmed' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_alias_resolution_and_mapping_management_are_user_scoped(): void
    {
        [$connection, , $token] = $this->device();
        [$other] = $this->device();
        $foreignAlias = WalletAlias::factory()->create(['wallet_connection_id' => $other->id, 'user_id' => $other->user_id, 'account_id' => $other->account_id, 'identifier' => $this->payload()['wallet_alias']]);
        $this->ingest($token, $this->payload())->assertCreated()->assertJsonPath('status', 'needs_account');
        $event = $connection->events()->sole();
        $this->actingAs(User::find($other->user_id))->post('/wallet-aliases/'.$event->id.'/resolve', ['account_id' => $other->account_id, 'remember' => true, 'confirmed' => true])->assertNotFound();
        $this->get('/integrations/wallet')->assertInertia(fn (Assert $p) => $p->has('unresolved.data', 0));
        $this->actingAs(User::find($connection->user_id))->post('/wallet-aliases/'.$event->id.'/resolve', ['account_id' => $other->account_id, 'remember' => true, 'confirmed' => true])->assertSessionHasErrors('account_id');
        $this->delete('/integrations/wallet/'.$other->id.'/aliases/'.$foreignAlias->id)->assertNotFound();
        $this->delete('/integrations/wallet/'.$connection->id.'/aliases/'.$foreignAlias->id)->assertNotFound();
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_owner_can_register_a_name_and_conflicting_accounts_stop_future_routing(): void
    {
        [$connection, , $token] = $this->device();
        $owner = User::find($connection->user_id);
        $url = '/integrations/wallet/'.$connection->id.'/aliases';
        $data = ['label' => 'Everyday Card', 'account_id' => $connection->account_id, 'name_unique' => true];
        $this->actingAs($owner)->post($url, array_replace($data, ['name_unique' => false]))->assertSessionHasErrors('name_unique');
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->post($url, $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('wallet_aliases', 1);
        $payload = $this->payload(['wallet_alias' => ['label' => ' everyday   CARD ']]);
        $this->ingest($token, $payload)->assertCreated()->assertJsonPath('status', 'unmatched');
        $original = $connection->events()->sole();
        $this->assertSame($connection->account_id, $original->account_id);
        $otherAccount = Account::factory()->create(['user_id' => $owner->id]);
        $this->post($url, array_replace($data, ['account_id' => $otherAccount->id]))->assertSessionHasNoErrors();
        $this->ingest($token, $this->payload(['wallet_alias' => ['label' => 'Everyday Card']]))->assertCreated()->assertJsonPath('status', 'needs_account');
        $this->ingest($token, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertSame($connection->account_id, $original->fresh()->account_id);
        $this->assertDatabaseCount('transactions', 1);
    }

    public function test_name_registration_cannot_cross_user_or_connection_boundaries(): void
    {
        [$connection, , $token] = $this->device();
        [$foreign] = $this->device();
        $sameOwnerConnection = WalletConnection::factory()->create(['user_id' => $connection->user_id, 'account_id' => $connection->account_id]);
        $data = ['label' => 'Everyday Card', 'account_id' => $connection->account_id, 'name_unique' => true];
        $this->actingAs(User::find($connection->user_id))->post('/integrations/wallet/'.$foreign->id.'/aliases', $data)->assertNotFound();
        $this->post('/integrations/wallet/'.$connection->id.'/aliases', array_replace($data, ['account_id' => $foreign->account_id]))->assertSessionHasErrors('account_id');
        $this->post('/integrations/wallet/'.$sameOwnerConnection->id.'/aliases', $data)->assertSessionHasNoErrors();
        $this->ingest($token, $this->payload(['wallet_alias' => ['label' => 'Everyday Card']]))->assertCreated()->assertJsonPath('status', 'needs_account');
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_remembering_a_discovered_name_requires_confirmation_and_does_not_reassign_queued_events(): void
    {
        Queue::fake();
        [$connection, , $token] = $this->device();
        $payload = $this->payload(['wallet_alias' => ['label' => 'Everyday Card']]);
        $this->ingest($token, $payload)->assertCreated();
        $event = $connection->events()->sole();
        $data = ['account_id' => $connection->account_id, 'remember' => true, 'confirmed' => true, 'name_unique' => true];
        $this->actingAs(User::find($connection->user_id))->post('/wallet-aliases/'.$event->id.'/resolve', array_replace($data, ['name_unique' => false]))->assertSessionHasErrors('remember');
        $this->post('/wallet-aliases/'.$event->id.'/resolve', $data)->assertSessionHasNoErrors();
        $alias = WalletAlias::sole();
        $this->assertNotNull($alias->name_confirmed_at);
        Queue::assertPushed(MatchWalletEvent::class);
        $this->delete('/integrations/wallet/'.$connection->id.'/aliases/'.$alias->id)->assertRedirect();
        $otherAccount = Account::factory()->create(['user_id' => $connection->user_id]);
        $this->post('/integrations/wallet/'.$connection->id.'/aliases', ['label' => 'Everyday Card', 'account_id' => $otherAccount->id, 'name_unique' => true])->assertSessionHasNoErrors();
        (new MatchWalletEvent($event->id))->handle(app(PurchaseMatcher::class));
        $this->assertSame($connection->account_id, $event->fresh()->account_id);
        $this->assertSame($connection->account_id, $event->fresh()->transaction->account_id);
        $this->ingest($token, $this->payload(['wallet_alias' => ['label' => 'Everyday Card']]))->assertCreated();
        $this->assertSame($otherAccount->id, $connection->events()->latest('id')->first()->account_id);
        $this->ingest($token, $this->payload(['wallet_alias' => ['label' => 'Everyday Card'], 'test' => true]))->assertCreated()->assertJsonPath('status', 'test_received');
        $this->assertDatabaseCount('transactions', 2);
    }

    #[TestWith(['currency', 'null'])]
    #[TestWith(['amount_decimal', '86,42'])]
    #[TestWith(['amount_decimal', 86.42])]
    #[TestWith(['wallet_alias', ['label' => 'Card', 'account_id' => 123]])]
    #[TestWith(['wallet_alias', ['label' => 'Card', 'last_four' => '12345']])]
    #[TestWith(['card_key', 'legacy-card'])]
    public function test_alias_input_does_not_loosen_existing_contract(string $field, mixed $value): void
    {
        [, , $token] = $this->device();
        $this->ingest($token, $this->payload([$field => $value]))->assertUnprocessable();
        $this->assertDatabaseCount('wallet_events', 0);
    }

    public function test_test_mode_excludes_spending_and_unknown_currency_preserves_exact_amount(): void
    {
        [$connection, , $token] = $this->device();
        $this->ingest($token, $this->payload(['test' => true]))->assertCreated()->assertJsonPath('status', 'test_received');
        $this->assertDatabaseCount('transactions', 0);
        $this->ingest($token, $this->payload(['currency' => null, 'amount_decimal' => '1.234500']))->assertCreated()->assertJsonPath('status', 'needs_account');
        $event = $connection->events()->latest('id')->first();
        $this->actingAs(User::find($connection->user_id))->post('/wallet-aliases/'.$event->id.'/resolve', ['account_id' => $connection->account_id, 'remember' => false, 'confirmed' => true])->assertRedirect();
        $this->assertSame('1.234500', $event->fresh()->transaction->total_decimal);
        $this->assertNull($event->fresh()->transaction->total_minor);
        $this->assertNull($event->fresh()->transaction->currency);
    }

    public function test_assigning_an_unresolved_event_still_matches_owned_receipts_within_its_account(): void
    {
        [$connection, , $token] = $this->device();
        $receipt = Receipt::factory()->create(['user_id' => $connection->user_id, 'status' => 'ready']);
        $purchase = Transaction::factory()->create(['receipt_id' => $receipt->id, 'user_id' => $connection->user_id, 'account_id' => $connection->account_id,
            'merchant' => 'Example Shop', 'purchase_date' => '2026-11-11', 'purchase_time' => null, 'currency' => 'USD', 'total_decimal' => '86.42', 'total_minor' => 8642]);
        $this->ingest($token, $this->payload())->assertCreated();
        $event = $connection->events()->sole();
        $this->actingAs(User::find($connection->user_id))->post('/wallet-aliases/'.$event->id.'/resolve', ['account_id' => $connection->account_id, 'remember' => true, 'confirmed' => true])->assertRedirect();
        $this->assertSame('matched', $event->fresh()->status);
        $this->assertSame($purchase->id, $event->fresh()->transaction_id);
        $this->assertSame(1, Transaction::whereNull('merged_into_id')->count());
    }
}
