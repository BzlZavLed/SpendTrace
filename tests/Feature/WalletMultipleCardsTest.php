<?php

namespace Tests\Feature;

use App\Jobs\MatchWalletEvent;
use App\Models\Account;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletConnection;
use App\Services\LedgerService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class WalletMultipleCardsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function wallet(): array
    {
        $user = User::factory()->create();
        $first = Account::factory()->create(['user_id' => $user->id]);
        $second = Account::factory()->create(['user_id' => $user->id]);
        $token = 'st_'.bin2hex(random_bytes(32));
        $connection = WalletConnection::factory()->create(['user_id' => $user->id, 'account_id' => null,
            'token_hash' => hash('sha256', $token), 'card_mappings' => [
                ['card_key' => 'everyday-visa', 'account_id' => $first->id],
                ['card_key' => 'travel-mastercard', 'account_id' => $second->id],
            ]]);

        return [$user, $connection, $token, $first, $second];
    }

    private function payload(array $overrides = []): array
    {
        return array_replace(['event_id' => (string) Str::uuid(), 'card_key' => 'everyday-visa', 'observed_at' => '2026-11-11T14:36:00-05:00',
            'transaction_at' => '2026-11-11T14:35:00-05:00', 'merchant' => 'Example Shop', 'amount_decimal' => '86.42', 'currency' => 'USD'], $overrides);
    }

    private function ingest(string $token, array $payload): TestResponse
    {
        return $this->postJson('/api/v1/integrations/wallet/events', $payload, ['Authorization' => 'Bearer '.$token]);
    }

    private function receipt(User $user, Account $account): Transaction
    {
        $receipt = Receipt::factory()->create(['user_id' => $user->id, 'status' => 'ready']);

        return Transaction::factory()->create(['receipt_id' => $receipt->id, 'user_id' => $user->id, 'account_id' => $account->id,
            'merchant' => 'Example Shop', 'purchase_date' => '2026-11-11', 'purchase_time' => '14:35:00', 'currency' => 'USD',
            'total_decimal' => '86.42', 'total_minor' => 8642]);
    }

    public function test_one_token_routes_different_cards_to_their_owned_accounts(): void
    {
        [$user, $connection, $token, $first, $second] = $this->wallet();
        $this->ingest($token, $this->payload())->assertCreated();
        $this->ingest($token, $this->payload(['card_key' => 'travel-mastercard']))->assertCreated();
        $events = $connection->events()->orderBy('id')->get();
        $this->assertSame([$first->id, $second->id], $events->pluck('account_id')->all());
        $this->assertSame([$user->id, $user->id], $events->pluck('user_id')->all());
        $this->assertSame($first->id, $events[0]->transaction->account_id);
        $this->assertSame($second->id, $events[1]->transaction->account_id);
        $this->assertDatabaseCount('wallet_connections', 1);
        $this->assertDatabaseCount('transactions', 2);
    }

    public function test_multiple_card_settings_only_accept_owned_accounts_and_unique_keys(): void
    {
        [$user, $connection, , $first, $second] = $this->wallet();
        $foreign = Account::factory()->create();
        $data = ['name' => 'My entire Wallet', 'enabled' => true, 'card_mappings' => [['card_key' => 'work', 'account_id' => $foreign->id]]];
        $this->actingAs($user)->post('/integrations/wallet', $data)->assertSessionHasErrors('card_mappings.0.account_id');
        $this->patch('/integrations/wallet/'.$connection->id, $data)->assertSessionHasErrors('card_mappings.0.account_id');
        $this->assertSame($first->id, $connection->fresh()->card_mappings[0]['account_id']);
        $data['card_mappings'] = [['card_key' => 'duplicate', 'account_id' => $first->id], ['card_key' => 'duplicate', 'account_id' => $second->id]];
        $this->post('/integrations/wallet', $data)->assertSessionHasErrors('card_mappings.0.card_key');
        $data['card_mappings'] = [['card_key' => 'first', 'account_id' => $first->id], ['card_key' => 'second', 'account_id' => $second->id]];
        $this->post('/integrations/wallet', $data)->assertSessionHasNoErrors()->assertRedirect();
        $created = WalletConnection::latest('id')->first();
        $this->assertNull($created->account_id);
        $this->assertSame(2, count($created->card_mappings));
        $this->get('/integrations/wallet')->assertInertia(fn (Assert $page) => $page->component('Wallet')->where('connections.0.card_mappings.1.card_key', 'second')->missing('connections.0.token_hash'));
        $this->actingAs(User::find($foreign->user_id))->patch('/integrations/wallet/'.$connection->id, $data)->assertNotFound();
    }

    #[TestWith(['unknown-card'])]
    #[TestWith(['Everyday-Visa'])]
    #[TestWith([''])]
    #[TestWith([null])]
    public function test_unknown_or_invalid_card_keys_are_not_routed_to_legacy_fallback(?string $key): void
    {
        [, $connection, $token, $first] = $this->wallet();
        $connection->update(['account_id' => $first->id]);
        $this->ingest($token, $this->payload(['card_key' => $key]))->assertUnprocessable()->assertJsonValidationErrors('card_key');
        $this->assertDatabaseCount('wallet_events', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_new_connection_requires_a_card_for_real_purchases_but_allows_connectivity_tests(): void
    {
        [, $connection, $token] = $this->wallet();
        $payload = $this->payload();
        unset($payload['card_key']);
        $this->ingest($token, $payload)->assertUnprocessable()->assertJsonValidationErrors('card_key');
        $this->ingest($token, $payload + ['test' => true])->assertCreated()->assertJsonPath('status', 'test_received');
        $this->assertNull($connection->events()->sole()->account_id);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_numeric_card_keys_are_compared_exactly_not_as_numbers(): void
    {
        [, $connection, $token, $first, $second] = $this->wallet();
        $connection->update(['card_mappings' => [['card_key' => '01', 'account_id' => $first->id], ['card_key' => '1', 'account_id' => $second->id]]]);
        $this->ingest($token, $this->payload(['card_key' => '1']))->assertCreated();
        $this->assertSame($second->id, $connection->events()->sole()->account_id);
    }

    public function test_mapping_changes_cannot_redirect_queued_events_or_idempotent_replays(): void
    {
        Queue::fake([MatchWalletEvent::class]);
        [$user, $connection, $token, $first, $second] = $this->wallet();
        $firstReceipt = $this->receipt($user, $first);
        $secondReceipt = $this->receipt($user, $second);
        $foreign = Account::factory()->create();
        $foreignReceipt = $this->receipt(User::find($foreign->user_id), $foreign);
        $payload = $this->payload();
        $this->ingest($token, $payload)->assertCreated();
        $event = $connection->events()->sole();
        Queue::assertPushed(MatchWalletEvent::class, fn ($job) => $job->eventId === $event->id);
        $this->actingAs($user)->patch('/integrations/wallet/'.$connection->id, ['name' => 'Changed mappings', 'enabled' => true,
            'card_mappings' => [['card_key' => 'everyday-visa', 'account_id' => $second->id]]])->assertSessionHasNoErrors();
        $this->ingest($token, $payload)->assertOk()->assertJsonPath('replayed', true);
        app()->call([unserialize(serialize(new MatchWalletEvent($event->id))), 'handle']);
        $this->assertSame($firstReceipt->id, $event->fresh()->transaction_id);
        $this->assertSame($first->id, $event->fresh()->account_id);
        $this->assertSame(0, $secondReceipt->observations()->count());
        $this->assertSame(0, $foreignReceipt->observations()->count());
        $this->ingest($token, $this->payload())->assertCreated();
        $this->assertSame($second->id, $connection->events()->latest('id')->first()->account_id);
    }

    public function test_manual_matching_and_undo_use_event_account_not_mutable_mapping(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 20:00:00'));
        [$user, $connection, $token, $first, $second] = $this->wallet();
        $this->ingest($token, $this->payload())->assertCreated();
        $event = $connection->events()->sole();
        $firstReceipt = $this->receipt($user, $first);
        $secondReceipt = $this->receipt($user, $second);
        $connection->update(['card_mappings' => []]);
        $this->actingAs($user)->post('/wallet-matches/'.$event->id.'/attach', ['transaction_id' => $secondReceipt->id, 'confirmed' => true])->assertSessionHasErrors('match');
        $this->post('/wallet-matches/'.$event->id.'/attach', ['transaction_id' => $firstReceipt->id, 'confirmed' => true])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame($firstReceipt->id, $event->fresh()->transaction_id);
        $this->assertSame('172.84', app(LedgerService::class)->summary($user)['totals']->sole()['amount']);
        $this->get('/wallet-matches')->assertInertia(fn (Assert $page) => $page->where('events.data.0.account_id', $first->id));
        $this->post('/wallet-matches/'.$event->id.'/separate')->assertRedirect();
        $this->assertSame('259.26', app(LedgerService::class)->summary($user)['totals']->sole()['amount']);
    }

    public function test_card_key_is_part_of_idempotency_and_cannot_override_ownership(): void
    {
        [, $connection, $token] = $this->wallet();
        $foreign = Account::factory()->create();
        $payload = $this->payload();
        $this->ingest($token, $payload)->assertCreated();
        $this->ingest($token, array_replace($payload, ['card_key' => 'travel-mastercard']))->assertConflict();
        foreach (['user_id' => $foreign->user_id, 'account_id' => $foreign->id, 'connection_id' => $connection->id] as $key => $value) {
            $this->ingest($token, $this->payload([$key => $value]))->assertUnprocessable()->assertJsonValidationErrors('payload');
        }
        $this->assertDatabaseCount('wallet_events', 1);
    }

    public function test_card_keys_and_event_ids_are_scoped_to_the_authenticated_connection(): void
    {
        [$user, $connection, $token, $first] = $this->wallet();
        [$otherUser, $otherConnection, $otherToken, $otherFirst] = $this->wallet();
        $payload = $this->payload();
        $this->ingest($token, $payload)->assertCreated();
        $this->ingest($otherToken, $payload)->assertCreated();
        $this->assertSame($first->id, $connection->events()->sole()->account_id);
        $this->assertSame($otherFirst->id, $otherConnection->events()->sole()->account_id);
        $otherConnection->update(['card_mappings' => [['card_key' => 'other-owner-only', 'account_id' => $otherFirst->id]]]);
        $this->ingest($token, $this->payload(['card_key' => 'other-owner-only']))->assertUnprocessable()->assertJsonValidationErrors('card_key');
        $this->actingAs($user)->getJson('/integrations/wallet/'.$otherConnection->id.'/status')->assertNotFound();
        $this->actingAs($otherUser)->get('/wallet-matches')->assertInertia(fn (Assert $page) => $page->has('events.data', 1)->where('events.data.0.account_id', $otherFirst->id));
        $this->assertDatabaseCount('wallet_events', 2);
    }

    public function test_ingestion_rechecks_mapping_ownership_even_if_stored_configuration_is_invalid(): void
    {
        [, $connection, $token] = $this->wallet();
        $foreign = Account::factory()->create();
        $connection->update(['card_mappings' => [['card_key' => 'everyday-visa', 'account_id' => $foreign->id]]]);
        $this->ingest($token, $this->payload())->assertUnprocessable()->assertJsonValidationErrors('card_key');
        $this->assertDatabaseCount('wallet_events', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_migration_backfills_legacy_events_without_changing_replay_hashes(): void
    {
        [, $connection, $token, $first] = $this->wallet();
        $connection->update(['account_id' => $first->id]);
        $payload = $this->payload();
        unset($payload['card_key']);
        $this->ingest($token, $payload)->assertCreated();
        $event = $connection->events()->sole();
        $hash = $event->payload_hash;
        $migration = require database_path('migrations/2026_11_11_210348_support_multiple_cards_per_wallet_connection.php');
        $onboarding = require database_path('migrations/2026_11_11_221251_add_wallet_shortcut_pairing_and_aliases.php');
        $onboarding->down();
        $migration->down();
        $migration->up();
        $onboarding->up();
        $this->assertSame($first->id, $event->fresh()->account_id);
        $this->assertSame($connection->user_id, $event->fresh()->user_id);
        $this->assertSame($hash, $event->fresh()->payload_hash);
        $this->ingest($token, $payload)->assertOk()->assertJsonPath('replayed', true);
        $this->assertDatabaseCount('transactions', 1);
    }
}
