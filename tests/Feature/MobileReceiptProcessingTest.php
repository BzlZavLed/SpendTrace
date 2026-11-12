<?php

namespace Tests\Feature;

use App\Models\Receipt;
use App\Models\User;
use App\Services\FakeReceiptExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MobileReceiptProcessingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['spendtrace.ai_preview_enabled' => true]);
    }

    public function test_ai_access_is_enforced_server_side_and_cannot_be_granted_by_the_client(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $token = $this->token($user);
        config(['spendtrace.ai_preview_enabled' => false]);
        $this->postJson('/api/v1/mobile/receipt-session', ['email' => $user->email, 'password' => 'password', 'ai_access_expires_at' => now()->addYear()->toIso8601String()])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/mobile/receipt-extraction', ['consent' => true])->assertForbidden();
        $user->forceFill(['ai_access_expires_at' => now()->addDay()])->save();
        $this->token($user);
        $this->withToken($token)->postJson('/api/v1/mobile/receipt-extraction', ['consent' => true])->assertUnprocessable();
        $other = User::factory()->create();
        $this->travel(1)->minutes();
        $this->postJson('/api/v1/mobile/receipt-session', ['email' => $other->email, 'password' => 'password'])->assertForbidden();
        $this->travel(2)->days();
        $this->withToken($token)->postJson('/api/v1/mobile/receipt-extraction')->assertForbidden();
        Http::assertNothingSent();
    }

    private function token(User $user): string
    {
        return $this->postJson('/api/v1/mobile/receipt-session', ['email' => $user->email, 'password' => 'password'])->assertOk()->json('token');
    }

    public function test_cloud_processing_requires_authentication_and_explicit_consent(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/api/v1/mobile/receipt-extraction')->assertUnauthorized();
        $token = $this->token(User::factory()->create());
        $this->withToken($token)->postJson('/api/v1/mobile/receipt-extraction', ['image' => UploadedFile::fake()->image('receipt.jpg')])->assertUnprocessable()->assertJsonValidationErrors('consent');
        Http::assertNothingSent();
    }

    public function test_expired_and_password_changed_credentials_are_rejected(): void
    {
        Http::preventStrayRequests();
        $user = User::factory()->create();
        $token = $this->token($user);
        $this->travel(31)->days();
        $this->withToken($token)->postJson('/api/v1/mobile/receipt-extraction')->assertUnauthorized();
        $this->travelBack();
        $user->update(['password' => 'changed-password']);
        $this->withToken($token)->postJson('/api/v1/mobile/receipt-extraction')->assertUnauthorized();
        $this->withToken('tampered')->postJson('/api/v1/mobile/receipt-extraction')->assertUnauthorized();
        Http::assertNothingSent();
    }

    public function test_ownership_and_record_overrides_are_rejected_even_when_null(): void
    {
        Http::preventStrayRequests();
        $token = $this->token(User::factory()->create());
        $other = User::factory()->create();
        foreach (['user_id' => $other->id, 'account_id' => 99, 'connection_id' => null, 'receipt_id' => 45] as $field => $value) {
            $this->withToken($token)->postJson('/api/v1/mobile/receipt-extraction', [
                'consent' => true, 'image' => UploadedFile::fake()->image('receipt.jpg'), $field => $value,
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        Http::assertNothingSent();
    }

    public function test_cloud_processing_returns_details_without_persisting_a_web_receipt_or_expense(): void
    {
        config(['spendtrace.openai_key' => 'test-only-key']);
        $data = (new FakeReceiptExtractor)->extract(new Receipt);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($data)]]]]])]);
        $token = $this->token(User::factory()->create());
        $this->withToken($token)->postJson('/api/v1/mobile/receipt-extraction', [
            'consent' => true, 'image' => UploadedFile::fake()->image('receipt.jpg'),
        ])->assertOk()->assertJsonPath('merchant', $data['merchant'])->assertHeader('Cache-Control', 'no-store, private');
        Http::assertSent(fn ($request): bool => $request['store'] === false && $request['input'][0]['content'][1]['type'] === 'input_image');
        $this->assertDatabaseCount('receipts', 0);
        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_provider_failure_is_safe_and_does_not_fall_back_or_retry(): void
    {
        config(['spendtrace.openai_key' => 'test-only-key']);
        Http::fake(['api.openai.com/*' => Http::response(['error' => ['message' => 'private provider details']], 500)]);
        $token = $this->token(User::factory()->create());
        $this->withToken($token)->postJson('/api/v1/mobile/receipt-extraction', ['consent' => true, 'image' => UploadedFile::fake()->image('receipt.jpg')])
            ->assertStatus(502)->assertDontSee('private provider details');
        Http::assertSentCount(1);
        $this->assertDatabaseCount('receipts', 0);
    }
}
