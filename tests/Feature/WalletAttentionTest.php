<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\User;
use App\Models\WalletConnection;
use App\Models\WalletEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WalletAttentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_browser_no_longer_exposes_wallet_attention_even_when_purchases_are_waiting(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $owner->id]);
        $connection = WalletConnection::factory()->create(['user_id' => $owner->id, 'account_id' => $account->id]);
        $event = WalletEvent::factory()->create([
            'user_id' => $owner->id, 'wallet_connection_id' => $connection->id,
            'status' => 'needs_account', 'is_test' => false,
            'payload' => ['merchant' => 'Cafe', 'amount_decimal' => '12.30', 'wallet_alias' => ['label' => 'Personal Visa'], 'private_extra' => 'not shared'],
        ]);
        $foreign = WalletEvent::factory()->create(['status' => 'needs_account', 'is_test' => false, 'payload' => ['wallet_alias' => ['label' => 'Foreign card']]]);
        Account::factory()->create(['user_id' => $foreign->user_id]);

        $this->actingAs($owner)->get('/accounts')->assertInertia(fn (Assert $page) => $page->missing('walletAttention'));

    }

    public function test_tests_and_assigned_purchases_do_not_trigger_attention_and_guests_receive_none(): void
    {
        $owner = User::factory()->create();
        $account = Account::factory()->create(['user_id' => $owner->id]);
        $connection = WalletConnection::factory()->create(['user_id' => $owner->id, 'account_id' => $account->id]);
        foreach ([['is_test' => true], ['account_id' => $account->id], ['status' => 'unmatched']] as $overrides) {
            WalletEvent::factory()->create(array_merge([
                'user_id' => $owner->id, 'wallet_connection_id' => $connection->id,
                'status' => 'needs_account', 'is_test' => false,
                'payload' => ['wallet_alias' => ['label' => 'Card']],
            ], $overrides));
        }

        $this->get('/login')->assertInertia(fn (Assert $page) => $page->missing('walletAttention'));
        $this->actingAs($owner)->get('/accounts')->assertInertia(fn (Assert $page) => $page->missing('walletAttention'));
    }
}
