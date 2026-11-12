<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletConnection;
use App\Models\WalletDevice;
use App\Models\WalletPairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WalletHandoffTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['spendtrace.legacy_wallet_server_enabled' => true]);
        config([
            'spendtrace.wallet_public_url' => 'https://spendtrace.example',
            'spendtrace.wallet_shortcut_url' => 'https://www.icloud.com/shortcuts/'.str_repeat('a', 32),
            'spendtrace.wallet_shortcut_version' => '1.0.0',
            'spendtrace.wallet_shortcut_verified' => true,
            'spendtrace.wallet_shortcut_handoff_verified' => true,
        ]);
    }

    public function test_handoff_contains_only_single_use_setup_and_preserves_connection_ownership(): void
    {
        $connection = WalletConnection::factory()->create();
        $other = WalletConnection::factory()->create();
        $response = $this->actingAs(User::find($connection->user_id))
            ->postJson('https://spendtrace.example/integrations/wallet/'.$connection->id.'/connect')
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('Referrer-Policy', 'no-referrer');
        $url = $response->json('launch_url');
        $this->assertStringStartsWith('shortcuts://run-shortcut?', $url);
        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        $this->assertSame('SpendTrace — Capture Purchase', $query['name']);
        $this->assertSame('text', $query['input']);
        $input = json_decode($query['text'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(['action', 'pairing_url', 'code'], array_keys($input));
        $this->assertSame('spendtrace-connect-v1', $input['action']);
        $this->assertSame('https://spendtrace.example/api/v1/integrations/wallet/pair', $input['pairing_url']);
        $this->assertSame(hash('sha256', $input['code']), WalletPairing::sole()->code_hash);
        $this->assertDatabaseCount('wallet_devices', 0);
        $this->actingAs(User::find($other->user_id));
        $this->postJson($input['pairing_url'], ['code' => $input['code']])->assertOk();
        $this->assertSame($connection->id, WalletDevice::sole()->wallet_connection_id);
        $this->postJson($input['pairing_url'], ['code' => $input['code']])->assertUnauthorized();
    }

    public function test_handoff_rejects_foreign_connections_ownership_overrides_http_and_unverified_releases(): void
    {
        $connection = WalletConnection::factory()->create();
        $this->actingAs(User::factory()->create())->postJson('https://spendtrace.example/integrations/wallet/'.$connection->id.'/connect')->assertNotFound();
        $this->actingAs(User::find($connection->user_id));
        foreach (['user_id', 'account_id', 'connection_id'] as $field) {
            $this->postJson('https://spendtrace.example/integrations/wallet/'.$connection->id.'/connect', [$field => 1])->assertUnprocessable();
        }
        $this->postJson('http://spendtrace.example/integrations/wallet/'.$connection->id.'/connect')->assertStatus(426);
        config(['spendtrace.wallet_shortcut_handoff_verified' => false]);
        $this->postJson('https://spendtrace.example/integrations/wallet/'.$connection->id.'/connect')->assertStatus(409);
        $this->assertDatabaseCount('wallet_pairings', 0);
        $this->assertDatabaseCount('wallet_devices', 0);
    }
}
