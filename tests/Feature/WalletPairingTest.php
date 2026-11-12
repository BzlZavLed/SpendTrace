<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\WalletConnection;
use App\Models\WalletDevice;
use App\Models\WalletPairing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WalletPairingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['spendtrace.legacy_wallet_server_enabled' => true]);
        $this->withoutVite();
        config(['spendtrace.wallet_public_url' => 'https://spendtrace.example', 'spendtrace.wallet_shortcut_url' => null, 'spendtrace.wallet_shortcut_verified' => false]);
    }

    private function issue(WalletConnection $connection): string
    {
        return $this->actingAs(User::find($connection->user_id))->postJson('/integrations/wallet/'.$connection->id.'/pairings', ['device_name' => 'My iPhone'])
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json('code');
    }

    private function exchange(string $code): TestResponse
    {
        return $this->postJson('https://spendtrace.example/api/v1/integrations/wallet/pair', ['code' => $code]);
    }

    private function payload(): array
    {
        return ['event_id' => (string) Str::uuid(), 'observed_at' => '2026-11-11T14:36:00-05:00', 'merchant' => 'Test', 'amount_decimal' => '0', 'currency' => null, 'test' => true];
    }

    public function test_pairing_is_hashed_single_use_and_device_credentials_are_revocable(): void
    {
        $connection = WalletConnection::factory()->create();
        $code = $this->issue($connection);
        $pairing = WalletPairing::sole();
        $this->assertSame(hash('sha256', $code), $pairing->code_hash);
        $response = $this->exchange($code)->assertOk()->assertJsonPath('endpoint', 'https://spendtrace.example/api/v1/integrations/wallet/events')->assertJsonPath('currency', null);
        $token = $response->json('token');
        $device = WalletDevice::sole();
        $this->assertSame(hash('sha256', $token), $device->token_hash);
        $this->assertStringNotContainsString($token, json_encode($device));
        $this->exchange($code)->assertUnauthorized();
        $this->postJson('https://spendtrace.example/api/v1/integrations/wallet/events', $this->payload(), ['Authorization' => 'Bearer '.$token])->assertCreated()->assertJsonPath('status', 'test_received');
        $this->assertNotNull($device->fresh()->last_test_at);
        $this->assertDatabaseCount('transactions', 0);
        $this->delete('/integrations/wallet/'.$connection->id.'/devices/'.$device->id)->assertRedirect();
        $this->postJson('https://spendtrace.example/api/v1/integrations/wallet/events', $this->payload(), ['Authorization' => 'Bearer '.$token])->assertUnauthorized();
        $this->assertDatabaseCount('wallet_devices', 1);
    }

    public function test_expired_disabled_deleted_and_invalid_codes_are_rejected(): void
    {
        $connection = WalletConnection::factory()->create();
        $code = $this->issue($connection);
        $this->travel(10)->minutes();
        $this->exchange($code)->assertUnauthorized();
        $this->exchange(str_repeat('f', 32))->assertUnauthorized();
        $this->travelBack();
        $connection->update(['enabled' => false]);
        $this->exchange($code)->assertUnauthorized();
        $connection->delete();
        $this->exchange($code)->assertUnauthorized();
        $this->assertDatabaseCount('wallet_devices', 0);
    }

    public function test_pairing_enforces_https_body_only_codes_and_rate_limits(): void
    {
        $connection = WalletConnection::factory()->create();
        $code = $this->issue($connection);
        $this->postJson('http://spendtrace.example/api/v1/integrations/wallet/pair', ['code' => $code])->assertStatus(426);
        $this->postJson('https://spendtrace.example/api/v1/integrations/wallet/pair?code='.$code, [])->assertUnprocessable();
        $this->postJson('https://spendtrace.example/api/v1/integrations/wallet/pair', ['code' => $code, 'account_id' => $connection->account_id])->assertUnprocessable();
        for ($i = 0; $i < 7; $i++) {
            $this->exchange(str_repeat('a', 32))->assertUnauthorized();
        }
        $this->exchange($code)->assertStatus(429);
        $this->assertDatabaseCount('wallet_devices', 0);
    }

    public function test_owner_is_bound_by_code_not_browser_user_and_other_users_cannot_manage_devices(): void
    {
        $connection = WalletConnection::factory()->create();
        $other = WalletConnection::factory()->create();
        $code = $this->issue($connection);
        $this->actingAs(User::find($other->user_id))->postJson('/integrations/wallet/'.$connection->id.'/pairings', ['device_name' => 'Intruder'])->assertNotFound();
        $token = $this->exchange($code)->assertOk()->json('token');
        $device = WalletDevice::sole();
        $this->assertSame($connection->id, $device->wallet_connection_id);
        $this->delete('/integrations/wallet/'.$connection->id.'/devices/'.$device->id)->assertNotFound();
        $this->delete('/integrations/wallet/'.$other->id.'/devices/'.$device->id)->assertNotFound();
        $this->postJson('https://spendtrace.example/integrations/wallet/'.$connection->id.'/device-config', ['device_name' => 'Intruder'])->assertNotFound();
        $this->get('/integrations/wallet')->assertInertia(fn (Assert $p) => $p->has('connections', 1)->where('connections.0.id', $other->id)->has('connections.0.devices', 0));
        $payload = $this->payload();
        $payload['test'] = false;
        $this->postJson('https://spendtrace.example/api/v1/integrations/wallet/events', $payload, ['Authorization' => 'Bearer '.$token])->assertCreated();
        $this->assertSame($connection->user_id, $connection->events()->sole()->transaction->user_id);
        $this->assertSame($connection->account_id, $connection->events()->sole()->account_id);
    }

    public function test_import_question_fallback_issues_a_separate_revocable_device_without_master_token(): void
    {
        $connection = WalletConnection::factory()->create();
        $this->actingAs(User::find($connection->user_id));
        $response = $this->postJson('https://spendtrace.example/integrations/wallet/'.$connection->id.'/device-config', ['device_name' => 'Import phone'])->assertOk();
        $device = WalletDevice::sole();
        $this->assertSame('import_question', $device->registration_method);
        $this->assertSame(hash('sha256', $response->json('token')), $device->token_hash);
        $this->assertNull($connection->fresh()->token_hash);
        $this->assertDatabaseCount('wallet_pairings', 0);
        $this->get('/integrations/wallet')->assertInertia(fn (Assert $p) => $p->missing('connections.0.devices.0.token_hash')->where('connections.0.devices.0.registration_method', 'import_question'));
    }

    public function test_no_working_looking_install_link_is_shown_without_a_verified_release(): void
    {
        $connection = WalletConnection::factory()->create();
        $this->actingAs(User::find($connection->user_id));
        $this->get('/integrations/wallet')->assertInertia(fn (Assert $p) => $p->where('shortcut.available', false)->where('shortcut.url', null));
        config(['spendtrace.wallet_shortcut_url' => 'https://example.com/placeholder', 'spendtrace.wallet_shortcut_verified' => true]);
        $this->get('/integrations/wallet')->assertInertia(fn (Assert $p) => $p->where('shortcut.available', false)->where('shortcut.url', null));
        config(['spendtrace.wallet_public_url' => 'http://localhost:8000']);
        $this->postJson('/integrations/wallet/'.$connection->id.'/pairings', ['device_name' => 'Phone'])->assertUnprocessable();
        $this->assertDatabaseCount('wallet_pairings', 0);
    }

    public function test_untrusted_clients_cannot_claim_https_with_forwarded_headers(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.45'])
            ->postJson('http://spendtrace.example/api/v1/integrations/wallet/pair', ['code' => str_repeat('0', 32)], ['X-Forwarded-Proto' => 'https'])
            ->assertStatus(426);
        $this->assertDatabaseCount('wallet_devices', 0);
    }
}
