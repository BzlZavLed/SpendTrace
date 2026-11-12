<?php

namespace Tests\Feature;

use App\Jobs\ExtractReceipt;
use App\Models\Account;
use App\Models\ExtractionRun;
use App\Models\Receipt;
use App\Models\ReceiptImage;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExtractionValidator;
use App\Services\FakeReceiptExtractor;
use App\Services\LedgerService;
use App\Services\Money;
use App\Services\OpenAiReceiptExtractor;
use App\Services\ReceiptExtractor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ReceiptFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['spendtrace.provider' => 'fake']);
        Http::preventStrayRequests();
    }

    private function extractionOutput(array $overrides = []): array
    {
        return array_replace((new FakeReceiptExtractor)->extract(new Receipt), [
            'merchant' => 'Neighborhood Market', 'purchase_date' => '2026-11-10', 'currency' => 'USD', 'total_minor' => 1234,
            'subtotal_minor' => 1200, 'tax_minor' => 84, 'tip_minor' => 0, 'discount_minor' => 50,
            'arithmetic_complete' => true, 'review_reasons' => [], 'card_last_four' => '1234',
            'items' => [['description' => 'Apples', 'quantity' => '2', 'amount_minor' => 1200, 'category' => 'Groceries']],
        ], $overrides);
    }

    private function extract(Transaction $transaction, array $data): void
    {
        $this->mock(ReceiptExtractor::class)->shouldReceive('extract')->once()->andReturn($data);
        app()->call([new ExtractReceipt($transaction->receipt_id, $transaction->receipt->generation), 'handle']);
    }

    private function payload(Transaction $transaction, array $overrides = []): array
    {
        return array_replace(['revision' => $transaction->revision, 'merchant' => $transaction->merchant, 'purchase_date' => $transaction->purchase_date, 'purchase_time' => $transaction->purchase_time,
            'amount' => Money::format($transaction->total_minor, $transaction->currency), 'currency' => $transaction->currency, 'account_id' => $transaction->account_id, 'category' => $transaction->category,
            'review_status' => 'needs_review', 'excluded' => false,
            'items' => $transaction->items->map(fn ($i) => ['description' => $i->description, 'quantity' => $i->quantity, 'amount' => Money::format($i->amount_minor, $transaction->currency), 'category' => $i->category])->all()], $overrides);
    }

    public function test_upload_requires_authentication(): void
    {
        Queue::fake([ExtractReceipt::class]);
        $this->post('/receipts', ['images' => [UploadedFile::fake()->image('receipt.jpg')]])->assertRedirect('/login');
        $this->assertDatabaseCount('receipts', 0);
        Queue::assertNothingPushed();
    }

    public function test_upload_saves_multiple_private_originals_and_queues_one_job(): void
    {
        Storage::fake('local');
        Queue::fake([ExtractReceipt::class]);
        $user = User::factory()->create();
        $response = $this->actingAs($user)->post('/receipts', ['images' => [UploadedFile::fake()->image('one.jpg', 50, 50), UploadedFile::fake()->image('two.png', 60, 60)]]);
        $transaction = Transaction::sole();
        $response->assertRedirect('/transactions/'.$transaction->id);
        $this->assertSame('unreconciled', $transaction->reconciliation_status);
        $this->assertNull($transaction->purchase_date);
        $this->assertDatabaseCount('receipt_images', 2);
        foreach (ReceiptImage::all() as $image) {
            Storage::disk('local')->assertExists($image->path);
            $this->get('/receipt-images/'.$image->id)->assertOk()->assertHeader('Content-Type', $image->mime);
        }
        Queue::assertPushed(ExtractReceipt::class, fn ($job) => $job->receiptId === $transaction->receipt_id && $job->generation === 1);
        Queue::assertPushed(ExtractReceipt::class, 1);
    }

    public function test_invalid_files_and_heic_have_actionable_validation(): void
    {
        $this->actingAs(User::factory()->create());
        $this->post('/receipts', ['images' => [UploadedFile::fake()->create('bad.jpg', 1, 'image/jpeg')]])->assertSessionHasErrors('images');
        $this->post('/receipts', ['images' => [UploadedFile::fake()->create('phone.heic', 1, 'image/heic')]])->assertSessionHasErrors(['images' => 'HEIC/HEIF needs conversion: export as JPEG from Photos or Files, or use iPhone Settings → Camera → Formats → Most Compatible for new photos.']);
        $this->post('/receipts', ['images' => [UploadedFile::fake()->image('huge.jpg')->size(10241)]])->assertSessionHasErrors('images.0');
        $this->assertDatabaseCount('receipts', 0);
    }

    public function test_foreign_records_and_images_are_not_accessible(): void
    {
        Storage::fake('local');
        $transaction = Transaction::factory()->create();
        $image = ReceiptImage::factory()->create(['receipt_id' => $transaction->receipt_id, 'user_id' => $transaction->user_id]);
        $account = Account::factory()->create(['user_id' => $transaction->user_id]);
        $this->actingAs(User::factory()->create());
        $this->get('/transactions/'.$transaction->id)->assertNotFound();
        $this->patch('/transactions/'.$transaction->id, [])->assertNotFound();
        $this->get('/receipt-images/'.$image->id)->assertNotFound();
        $this->post('/receipts/'.$transaction->receipt_id.'/retry')->assertNotFound();
        $this->patch('/accounts/'.$account->id, [])->assertNotFound();
    }

    public function test_successful_extraction_suggests_an_account_and_is_idempotent(): void
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create(['user_id' => $transaction->user_id]);
        $account->identifiers()->create(['kind' => 'wallet', 'last_four' => '1234', 'device_label' => 'iPhone']);
        $this->extract($transaction, $this->extractionOutput());
        $transaction->refresh();
        $this->assertSame(1234, $transaction->total_minor);
        $this->assertSame($account->id, $transaction->account_id);
        $this->assertSame('2026-11-10', $transaction->purchase_date);
        $this->assertSame('ready', $transaction->receipt->status);
        $this->assertSame('unreconciled', $transaction->reconciliation_status);
        $this->assertSame('needs_review', $transaction->review_status);
        app()->call([new ExtractReceipt($transaction->receipt_id, 1), 'handle']);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('transaction_items', 1);
        $this->assertDatabaseCount('extraction_runs', 1);
        $this->assertDatabaseCount('transaction_observations', 1);
    }

    public function test_failed_extraction_preserves_images_and_retry_can_succeed(): void
    {
        Storage::fake('local');
        $transaction = Transaction::factory()->create();
        $image = ReceiptImage::factory()->create(['receipt_id' => $transaction->receipt_id, 'user_id' => $transaction->user_id]);
        Storage::disk('local')->put($image->path, 'original');
        $this->mock(ReceiptExtractor::class)->shouldReceive('extract')->once()->andThrow(new \RuntimeException('Sensitive provider response should not be saved'));
        app()->call([new ExtractReceipt($transaction->receipt_id, 1), 'handle']);
        $this->assertSame('failed', $transaction->receipt->fresh()->status);
        Storage::disk('local')->assertExists($image->path);
        $this->assertStringNotContainsString('Sensitive', ExtractionRun::sole()->error);
        Queue::fake([ExtractReceipt::class]);
        $this->actingAs(User::find($transaction->user_id))->post('/receipts/'.$transaction->receipt_id.'/retry')->assertRedirect();
        $transaction->refresh();
        $this->assertSame(2, $transaction->receipt->generation);
        Queue::assertPushed(ExtractReceipt::class, fn ($j) => $j->generation === 2);
        $this->extract($transaction, $this->extractionOutput());
        $this->assertSame('ready', $transaction->receipt->fresh()->status);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('extraction_runs', 2);
    }

    public function test_reprocessing_preserves_user_corrections_and_original_extraction(): void
    {
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->extractionOutput());
        $transaction->refresh();
        $this->actingAs(User::find($transaction->user_id))->patch('/transactions/'.$transaction->id, $this->payload($transaction, ['merchant' => 'Corrected market', 'amount' => '12.99', 'items' => [['description' => 'Corrected apples', 'quantity' => '1', 'amount' => '12.00', 'category' => 'Groceries']]]))->assertSessionHasNoErrors();
        Queue::fake([ExtractReceipt::class]);
        $this->post('/receipts/'.$transaction->receipt_id.'/retry');
        Queue::assertPushed(ExtractReceipt::class);
        $transaction->refresh();
        $this->extract($transaction, $this->extractionOutput(['merchant' => 'Wrong new extraction', 'total_minor' => 9999]));
        $transaction->refresh();
        $this->assertSame('Corrected market', $transaction->merchant);
        $this->assertSame(1299, $transaction->total_minor);
        $this->assertSame('Corrected apples', $transaction->items->sole()->description);
        $this->assertSame('Neighborhood Market', ExtractionRun::orderBy('id')->first()->output['merchant']);
        $this->assertSame('Wrong new extraction', ExtractionRun::orderByDesc('id')->first()->output['merchant']);
    }

    public function test_unknown_fields_remain_null_and_fake_mode_is_visible(): void
    {
        $transaction = Transaction::factory()->create();
        app()->call([new ExtractReceipt($transaction->receipt_id, 1), 'handle']);
        $transaction->refresh();
        $this->assertNull($transaction->total_minor);
        $this->assertNull($transaction->purchase_date);
        $this->assertNull($transaction->currency);
        $this->assertCount(0, $transaction->items);
        $this->assertContains('Missing total minor.', $transaction->review_reasons);
        $this->actingAs(User::find($transaction->user_id))->get('/')->assertInertia(fn (Assert $page) => $page->component('Ledger')->where('provider', 'fake')->where('summary.unresolved', 1));
    }

    public function test_arithmetic_checks_discount_tax_and_tip_without_assuming_layout(): void
    {
        $v = new ExtractionValidator;
        $data = $this->extractionOutput();
        $this->assertSame([], $v->reasons($data));
        $this->assertContains('Subtotal, tax, tip, and discounts do not match the total.', $v->reasons($this->extractionOutput(['total_minor' => 9999])));
        $this->assertContains('Receipt arithmetic could not be fully checked.', $v->reasons($this->extractionOutput(['arithmetic_complete' => false])));
        $this->assertContains('Incomplete amounts for arithmetic check.', $v->reasons($this->extractionOutput(['tax_minor' => null])));
    }

    public function test_malformed_provider_output_fails_without_a_partial_ledger_write(): void
    {
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->extractionOutput(['total_minor' => 12.34, 'purchase_date' => '2026-02-30']));
        $transaction->refresh();
        $this->assertNull($transaction->total_minor);
        $this->assertSame('failed', $transaction->receipt->status);
        $this->assertDatabaseCount('transaction_items', 0);
    }

    public function test_exact_duplicates_reopen_saved_receipt_and_partial_overlap_is_rejected(): void
    {
        Storage::fake('local');
        Queue::fake([ExtractReceipt::class]);
        $this->actingAs(User::factory()->create());
        $file = UploadedFile::fake()->image('receipt.jpg', 50, 50);
        $this->post('/receipts', ['images' => [$file]])->assertRedirect();
        $transaction = Transaction::sole();
        $this->post('/receipts', ['images' => [$file]])->assertRedirect('/transactions/'.$transaction->id)->assertSessionHas('notice');
        $this->post('/receipts', ['images' => [$file, UploadedFile::fake()->image('other.png')]])->assertSessionHasErrors('images');
        $this->assertDatabaseCount('receipts', 1);
        $this->assertDatabaseCount('receipt_images', 1);
        Queue::assertPushed(ExtractReceipt::class, 1);
    }

    public function test_ambiguous_suffix_and_likely_duplicate_are_flagged_not_merged(): void
    {
        $transaction = Transaction::factory()->create();
        foreach ([1, 2] as $i) {
            $a = Account::factory()->create(['user_id' => $transaction->user_id]);
            $a->identifiers()->create(['kind' => 'physical', 'last_four' => '1234']);
        }
        $existing = Transaction::factory()->create(['user_id' => $transaction->user_id, 'merchant' => 'Neighborhood Market', 'purchase_date' => '2026-11-10', 'total_minor' => 1234, 'currency' => 'USD']);
        $this->extract($transaction, $this->extractionOutput());
        $transaction->refresh();
        $this->assertNull($transaction->account_id);
        $this->assertContains('Multiple accounts share this suffix; choose the correct account.', $transaction->review_reasons);
        $this->assertContains($existing->id, $transaction->duplicate_ids);
        $this->assertDatabaseCount('transactions', 2);
        $this->assertFalse($transaction->excluded);
    }

    public function test_summary_uses_purchase_month_not_upload_date_and_never_adds_items(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'America/New_York'));
        $user = User::factory()->create();
        $t = Transaction::factory()->create(['user_id' => $user->id, 'merchant' => 'Market', 'purchase_date' => '2026-11-10', 'total_minor' => 1234, 'currency' => 'USD']);
        $t->items()->create(['description' => 'Item', 'amount_minor' => 1234]);
        Transaction::factory()->create(['user_id' => $user->id, 'purchase_date' => '2026-10-31', 'total_minor' => 9000, 'currency' => 'USD']);
        Transaction::factory()->create(['user_id' => $user->id, 'purchase_date' => null, 'total_minor' => 8000, 'currency' => 'USD']);
        Transaction::factory()->create(['user_id' => $user->id, 'purchase_date' => '2026-11-10', 'total_minor' => 7000, 'currency' => 'USD', 'excluded' => true]);
        Transaction::factory()->create(['user_id' => $user->id, 'purchase_date' => '2026-11-10', 'total_minor' => 500, 'currency' => 'JPY']);
        Transaction::factory()->create(['purchase_date' => '2026-11-10', 'total_minor' => 6000, 'currency' => 'USD']);
        Transaction::factory()->create(['user_id' => $user->id, 'purchase_date' => '2026-11-30', 'total_minor' => 60000, 'currency' => 'USD', 'review_status' => 'reviewed']);
        $summary = app(LedgerService::class)->summary($user);
        $this->assertSame(['JPY' => '500', 'USD' => '12.34'], $summary['totals']->pluck('amount', 'currency')->sortKeys()->all());
        $this->assertSame(4, $summary['unresolved']);
    }

    public function test_edits_reject_foreign_accounts_stale_revisions_and_inexact_money(): void
    {
        $transaction = Transaction::factory()->create();
        $account = Account::factory()->create();
        $this->actingAs(User::find($transaction->user_id));
        $this->patch('/transactions/'.$transaction->id, $this->payload($transaction, ['account_id' => $account->id]))->assertSessionHasErrors('account_id');
        $this->patch('/transactions/'.$transaction->id, $this->payload($transaction, ['revision' => 77]))->assertSessionHasErrors('revision');
        $this->patch('/transactions/'.$transaction->id, $this->payload($transaction, ['currency' => 'USD', 'amount' => '12.345']))->assertSessionHasErrors('amount');
        $this->assertNull($transaction->fresh()->total_minor);
    }

    public function test_manual_entry_with_no_items_can_be_reviewed_but_not_reconciled(): void
    {
        $transaction = Transaction::factory()->create();
        $this->actingAs(User::find($transaction->user_id));
        $this->patch('/transactions/'.$transaction->id, $this->payload($transaction, ['merchant' => 'Cafe', 'purchase_date' => '2026-11-09', 'purchase_time' => '12:30', 'currency' => 'USD', 'amount' => '0.10', 'review_status' => 'reviewed', 'reconciliation_status' => 'reconciled']))->assertSessionHasNoErrors();
        $transaction->refresh();
        $this->assertSame(10, $transaction->total_minor);
        $this->assertSame('reviewed', $transaction->review_status);
        $this->assertSame('unreconciled', $transaction->reconciliation_status);
        $this->assertSame('12:30:00', $transaction->purchase_time);
    }

    public function test_accounts_support_multiple_identifiers_but_not_full_card_numbers(): void
    {
        $this->actingAs(User::factory()->create());
        $data = ['institution' => 'Synchrony', 'nickname' => 'Sam’s Club', 'card_network' => null, 'identifiers' => [['kind' => 'physical', 'last_four' => '1234', 'device_label' => null], ['kind' => 'wallet', 'last_four' => '9876', 'device_label' => 'iPhone']]];
        $this->post('/accounts', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('account_identifiers', 2);
        $data['identifiers'][0]['last_four'] = '4111111111111111';
        $this->post('/accounts', $data)->assertSessionHasErrors('identifiers.0.last_four');
        $this->assertDatabaseCount('accounts', 1);
    }

    public function test_real_provider_sends_images_with_strict_schema_and_handles_failure(): void
    {
        Storage::fake('local');
        config(['spendtrace.openai_key' => 'test-key']);
        $transaction = Transaction::factory()->create();
        $image = ReceiptImage::factory()->create(['receipt_id' => $transaction->receipt_id, 'user_id' => $transaction->user_id]);
        Storage::disk('local')->put($image->path, 'image-bytes');
        Http::fake(['https://api.openai.com/v1/responses' => Http::sequence()->push(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($this->extractionOutput())]]]]])->push(['error' => 'private details'], 429)]);
        $data = (new OpenAiReceiptExtractor)->extract($transaction->receipt);
        $this->assertSame(1234, $data['total_minor']);
        Http::assertSent(fn ($r) => $r['store'] === false && $r['text']['format']['strict'] === true && str_starts_with($r['input'][0]['content'][1]['image_url'], 'data:image/jpeg;base64,'));
        $this->app->instance(ReceiptExtractor::class, new OpenAiReceiptExtractor);
        app()->call([new ExtractReceipt($transaction->receipt_id, 1), 'handle']);
        $this->assertSame('failed', $transaction->receipt->fresh()->status);
        $this->assertStringNotContainsString('private details', $transaction->receipt->fresh()->error);
    }

    public function test_registration_login_and_logout(): void
    {
        $this->post('/register', ['name' => 'Tester', 'email' => 'tester@example.test', 'password' => 'a-long-test-password', 'password_confirmation' => 'a-long-test-password'])->assertRedirect('/');
        $this->assertAuthenticated();
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['email' => 'tester@example.test', 'password' => 'a-long-test-password'])->assertRedirect('/');
        $this->assertAuthenticated();
    }

    public function test_database_worker_processes_a_saved_upload_after_the_request_finishes(): void
    {
        Storage::fake('local');
        config(['queue.default' => 'database']);
        $this->actingAs(User::factory()->create())->post('/receipts', ['images' => [UploadedFile::fake()->image('receipt.jpg')]])->assertRedirect();
        $this->assertSame('queued', Receipt::sole()->status);
        $this->assertDatabaseCount('jobs', 1);
        $this->artisan('queue:work', ['--once' => true, '--tries' => 1, '--timeout' => 120])->assertSuccessful();
        $this->assertSame('ready', Receipt::sole()->status);
        $this->assertDatabaseCount('jobs', 0);
        $this->assertDatabaseCount('transactions', 1);
        $this->assertDatabaseCount('extraction_runs', 1);
    }

    public function test_obsolete_job_cannot_replace_a_newer_attempt(): void
    {
        $transaction = Transaction::factory()->create();
        $transaction->receipt->update(['generation' => 2]);
        $this->mock(ReceiptExtractor::class)->shouldNotReceive('extract');
        app()->call([new ExtractReceipt($transaction->receipt_id, 1), 'handle']);
        $this->assertSame('queued', $transaction->receipt->fresh()->status);
        $this->assertDatabaseCount('extraction_runs', 0);
        $this->assertNull($transaction->fresh()->merchant);
    }

    public function test_same_image_belongs_independently_to_each_user(): void
    {
        Storage::fake('local');
        Queue::fake([ExtractReceipt::class]);
        $file = UploadedFile::fake()->image('receipt.jpg');
        $this->actingAs(User::factory()->create())->post('/receipts', ['images' => [$file]])->assertRedirect();
        $this->actingAs(User::factory()->create())->post('/receipts', ['images' => [$file]])->assertRedirect();
        $this->assertDatabaseCount('receipt_images', 2);
        $this->assertDatabaseCount('transactions', 2);
        Queue::assertPushed(ExtractReceipt::class, 2);
    }

    public function test_editing_only_items_preserves_their_currency_on_reprocessing(): void
    {
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->extractionOutput());
        $transaction->refresh();
        $this->actingAs(User::find($transaction->user_id))->patch('/transactions/'.$transaction->id, $this->payload($transaction, [
            'items' => [['description' => 'User corrected item', 'quantity' => '1', 'amount' => '12.00', 'category' => null]],
        ]))->assertSessionHasNoErrors();
        Queue::fake([ExtractReceipt::class]);
        $this->post('/receipts/'.$transaction->receipt_id.'/retry')->assertRedirect();
        Queue::assertPushed(ExtractReceipt::class);
        $transaction->refresh();
        $this->extract($transaction, $this->extractionOutput(['currency' => 'JPY', 'total_minor' => 500]));
        $transaction->refresh();
        $this->assertSame('USD', $transaction->currency);
        $this->assertSame(1234, $transaction->total_minor);
        $this->assertSame(1200, $transaction->items->sole()->amount_minor);
        $this->assertSame('User corrected item', $transaction->items->sole()->description);
    }

    public function test_provider_failure_logs_diagnostics_without_secrets_or_response_content(): void
    {
        config(['spendtrace.openai_key' => 'sk-secret-never-log']);
        $transaction = Transaction::factory()->create();
        Http::fake(['https://api.openai.com/v1/responses' => Http::response([
            'error' => ['code' => 'insufficient_quota', 'message' => 'Private receipt merchant and card 1234 sk-secret-never-log'],
        ], 429, ['x-request-id' => 'req_test123'])]);
        Log::spy();
        $this->app->instance(ReceiptExtractor::class, new OpenAiReceiptExtractor);
        app()->call([new ExtractReceipt($transaction->receipt_id, 1), 'handle']);
        Log::shouldHaveReceived('error')->once()->with('Receipt extraction failed', \Mockery::on(function ($context) use ($transaction) {
            $this->assertSame($transaction->receipt_id, $context['receipt_id']);
            $this->assertSame('provider_request', $context['stage']);
            $this->assertSame(429, $context['http_status']);
            $this->assertSame('req_test123', $context['request_id']);
            $this->assertSame('insufficient_quota', $context['provider_error_code']);
            $this->assertStringNotContainsString('sk-secret-never-log', json_encode($context));
            $this->assertStringNotContainsString('Private receipt', json_encode($context));

            return true;
        }));
        $this->assertSame('failed', $transaction->receipt->fresh()->status);
    }

    public function test_validation_failure_logs_field_names_without_extracted_values(): void
    {
        Log::spy();
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->extractionOutput(['currency' => 'PRIVATE-RECEIPT-VALUE']));
        Log::shouldHaveReceived('error')->once()->with('Receipt extraction failed', \Mockery::on(function ($context) {
            $this->assertSame('validate_output', $context['stage']);
            $this->assertContains('currency', $context['invalid_fields']);
            $this->assertStringNotContainsString('PRIVATE-RECEIPT-VALUE', json_encode($context));

            return true;
        }));
    }

    public function test_optical_abbreviations_are_saved_as_items_with_nullable_quantities(): void
    {
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->extractionOutput([
            'merchant' => 'Costco Wholesale', 'category' => 'Health', 'printed_item_count' => 2,
            'subtotal_minor' => 22198, 'total_minor' => 22198, 'tax_minor' => 0, 'discount_minor' => 0,
            'items' => [
                ['description' => '1879786 SV1.67 GST B', 'quantity' => null, 'amount_minor' => 15699, 'category' => 'Health'],
                ['description' => '2059979 KS 326.72.0', 'quantity' => null, 'amount_minor' => 6499, 'category' => 'Health'],
            ],
        ]));
        $transaction->refresh();
        $this->assertSame('Health', $transaction->category);
        $this->assertSame(22198, $transaction->total_minor);
        $this->assertSame([15699, 6499], $transaction->items->pluck('amount_minor')->all());
        $this->assertSame([null, null], $transaction->items->pluck('quantity')->all());
        $this->assertSame('1879786 SV1.67 GST B', $transaction->items->first()->description);
        $this->assertSame('receipt-v3', $transaction->receipt->runs->sole()->version);
        $this->assertSame('unreconciled', $transaction->reconciliation_status);
    }

    public function test_missing_sale_lines_are_flagged_without_inventing_products(): void
    {
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->extractionOutput(['printed_item_count' => 2, 'items' => []]));
        $transaction->refresh();
        $this->assertCount(0, $transaction->items);
        $this->assertContains('No item lines were extracted. Compare the item section with the original receipt.', $transaction->review_reasons);
        $this->assertContains('Receipt reports 2 items sold, but only 0 item lines were extracted. Check for missing lines; the printed count may include multiple units per line.', $transaction->review_reasons);
        $this->assertSame('needs_review', $transaction->review_status);
    }

    public function test_extraction_accepts_printed_minute_precision_without_losing_items(): void
    {
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->extractionOutput(['purchase_time' => '14:36']));
        $transaction->refresh();
        $this->assertSame('ready', $transaction->receipt->status);
        $this->assertSame('14:36:00', $transaction->purchase_time);
        $this->assertCount(1, $transaction->items);
        $validator = new ExtractionValidator;
        $this->expectException(ValidationException::class);
        $validator->validate($this->extractionOutput(['purchase_time' => '25:36']));
    }

    public function test_landscape_receipt_sends_an_alternate_reading_view_without_changing_original(): void
    {
        Storage::fake('local');
        config(['spendtrace.openai_key' => 'test-key']);
        $transaction = Transaction::factory()->create();
        $image = ReceiptImage::factory()->create(['receipt_id' => $transaction->receipt_id, 'user_id' => $transaction->user_id]);
        $file = UploadedFile::fake()->image('sideways.jpg', 120, 80);
        $original = file_get_contents($file->getPathname());
        Storage::disk('local')->put($image->path, $original);
        Http::fake(['https://api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [['content' => [['type' => 'output_text', 'text' => json_encode($this->extractionOutput())]]]]])]);
        (new OpenAiReceiptExtractor)->extract($transaction->receipt);
        Http::assertSent(function ($request) use ($original) {
            $images = array_values(array_filter($request['input'][0]['content'], fn ($part) => $part['type'] === 'input_image'));
            $this->assertCount(2, $images);
            $this->assertSame($original, base64_decode(explode(',', $images[0]['image_url'], 2)[1]));
            $size = getimagesizefromstring(base64_decode(explode(',', $images[1]['image_url'], 2)[1]));
            $this->assertSame(80, $size[0]);
            $this->assertSame(120, $size[1]);

            return true;
        });
        $this->assertSame($original, Storage::disk('local')->get($image->path));
    }

    public function test_receipt_page_returns_formatted_line_amounts_when_currency_is_known(): void
    {
        $transaction = Transaction::factory()->create(['currency' => 'USD', 'total_minor' => 22198]);
        $transaction->items()->createMany([
            ['description' => 'Optical item one', 'amount_minor' => 15699],
            ['description' => 'Optical item two', 'amount_minor' => 6499],
        ]);
        $this->actingAs(User::find($transaction->user_id))->get('/transactions/'.$transaction->id)
            ->assertInertia(fn (Assert $page) => $page->component('Transaction')
                ->where('transaction.amount', '221.98')
                ->where('transaction.items.0.amount', '156.99')
                ->where('transaction.items.1.amount', '64.99'));
    }

    public function test_unknown_currency_does_not_erase_extracted_amounts_when_form_is_saved(): void
    {
        $transaction = Transaction::factory()->create(['currency' => null, 'total_minor' => 22198]);
        $transaction->items()->create(['description' => 'Optical item', 'amount_minor' => 15699]);
        $this->actingAs(User::find($transaction->user_id))->get('/transactions/'.$transaction->id)
            ->assertInertia(fn (Assert $page) => $page->where('transaction.currency', null)
                ->where('transaction.total_minor', 22198)->where('transaction.items.0.amount_minor', 15699));
        $this->patch('/transactions/'.$transaction->id, $this->payload($transaction))
            ->assertSessionHasErrors('currency');
        $this->assertSame(22198, $transaction->fresh()->total_minor);
        $this->assertSame(15699, $transaction->fresh()->items->sole()->amount_minor);
    }
}
