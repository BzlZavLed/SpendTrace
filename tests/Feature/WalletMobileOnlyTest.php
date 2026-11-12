<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletConnection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WalletMobileOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_wallet_capture_and_setup_are_retired_without_deleting_records(): void
    {
        config(['spendtrace.legacy_wallet_server_enabled' => false]);
        $connection = WalletConnection::factory()->create();
        $this->actingAs(User::find($connection->user_id));
        foreach ([
            ['GET', '/integrations/wallet'],
            ['POST', '/integrations/wallet'],
            ['GET', '/integrations/wallet/'.$connection->id.'/status'],
            ['POST', '/integrations/wallet/'.$connection->id.'/connect'],
            ['POST', '/integrations/wallet/'.$connection->id.'/pairings'],
            ['POST', '/integrations/wallet/'.$connection->id.'/device-config'],
            ['POST', '/integrations/wallet/'.$connection->id.'/token'],
            ['POST', '/integrations/wallet/'.$connection->id.'/aliases'],
            ['DELETE', '/integrations/wallet/'.$connection->id],
            ['GET', '/wallet-matches'],
            ['POST', '/api/v1/integrations/wallet/pair'],
            ['POST', '/api/v1/integrations/wallet/events'],
        ] as [$method, $url]) {
            $this->json($method, $url)->assertGone();
        }
        $this->postJson('/wallet-aliases/123/resolve')->assertGone();
        $this->getJson('/integrations/wallet/999999/status')->assertGone();
        $this->assertDatabaseCount('wallet_connections', 1);
        $this->assertDatabaseCount('wallet_devices', 0);
        $this->assertDatabaseCount('wallet_pairings', 0);
        $this->assertDatabaseCount('wallet_events', 0);
        $this->get('/accounts')->assertInertia(fn (Assert $page) => $page->missing('walletAttention'));
    }
}
