<?php

namespace Tests\Feature;

use App\Jobs\ExtractReceipt;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ExtractionValidator;
use App\Services\FakeReceiptExtractor;
use App\Services\LedgerService;
use App\Services\ReceiptExtractor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class ReceiptAmountsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['spendtrace.provider' => 'fake']);
    }

    private function receiptOutput(?string $currency, string $amount): array
    {
        $data = (new FakeReceiptExtractor)->extract(new Receipt);
        foreach (['total', 'subtotal', 'tax', 'tip', 'discount'] as $name) {
            unset($data[$name.'_minor']);
        }

        return array_replace($data, [
            'merchant' => 'Repair shop', 'purchase_date' => '2026-11-10', 'currency' => $currency,
            'total_decimal' => $amount, 'subtotal_decimal' => $amount,
            'tax_decimal' => '0', 'tip_decimal' => '0', 'discount_decimal' => '0',
            'review_reasons' => [], 'arithmetic_complete' => true, 'printed_item_count' => 1,
            'items' => [['description' => 'Service', 'quantity' => null, 'amount_decimal' => $amount, 'category' => null]],
        ]);
    }

    private function extract(Transaction $transaction, array $data): void
    {
        $this->mock(ReceiptExtractor::class)->shouldReceive('extract')->once()->andReturn($data);
        app()->call([new ExtractReceipt($transaction->receipt_id, $transaction->receipt->generation), 'handle']);
    }

    #[TestWith([null, '1234.56', null])]
    #[TestWith([null, '1200', null])]
    #[TestWith([null, '1.234500', null])]
    #[TestWith([null, '-15.25', null])]
    #[TestWith(['EUR', '1234.56', 123456])]
    #[TestWith(['GBP', '9.90', 990])]
    #[TestWith(['CAD', '0.10', 10])]
    #[TestWith(['JPY', '1200', 1200])]
    #[TestWith(['KWD', '1.234', 1234])]
    #[TestWith(['USD', '0', 0])]
    public function test_printed_amounts_reach_the_page_independently_of_currency(?string $currency, string $amount, ?int $minor): void
    {
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->receiptOutput($currency, $amount));
        $transaction->refresh();
        $this->assertSame('ready', $transaction->receipt->status);
        $this->assertSame($amount, $transaction->total_decimal);
        $this->assertSame($minor, $transaction->total_minor);
        $this->assertSame($amount, $transaction->items->sole()->amount_decimal);
        $this->assertSame($minor, $transaction->items->sole()->amount_minor);
        $this->assertSame($currency, $transaction->currency);
        $this->actingAs(User::find($transaction->user_id))->get('/transactions/'.$transaction->id)
            ->assertInertia(fn (Assert $page) => $page->where('transaction.amount', $amount)
                ->where('transaction.items.0.amount', $amount)->where('transaction.currency', $currency));
    }

    public function test_unknown_currency_amounts_can_be_edited_and_later_included_in_summary(): void
    {
        $this->travelTo(Carbon::parse('2026-11-11 12:00:00', 'America/New_York'));
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->receiptOutput(null, '1234.56'));
        $transaction->refresh();
        $user = User::find($transaction->user_id);
        $this->actingAs($user);
        $payload = [
            'revision' => $transaction->revision, 'merchant' => 'Repair shop', 'purchase_date' => '2026-11-10',
            'amount' => '1234.50', 'currency' => null, 'category' => 'Home', 'review_status' => 'needs_review', 'excluded' => false,
            'items' => [['description' => 'Corrected service', 'quantity' => null, 'amount' => '1234.50', 'category' => 'Home']],
        ];
        $this->patch('/transactions/'.$transaction->id, $payload)->assertSessionHasNoErrors();
        $transaction->refresh();
        $this->assertSame('1234.50', $transaction->total_decimal);
        $this->assertSame('1234.50', $transaction->items->sole()->amount_decimal);
        $this->assertNull($transaction->total_minor);
        $this->assertCount(0, app(LedgerService::class)->summary($user)['totals']);
        $payload['currency'] = 'EUR';
        $payload['revision'] = $transaction->revision;
        $this->patch('/transactions/'.$transaction->id, $payload)->assertSessionHasNoErrors();
        $transaction->refresh();
        $this->assertSame('1234.50', $transaction->total_decimal);
        $this->assertSame(123450, $transaction->total_minor);
        $this->assertSame(123450, $transaction->items->sole()->amount_minor);
        $this->assertSame(['currency' => 'EUR', 'amount' => '1234.50'], app(LedgerService::class)->summary($user)['totals']->sole());
    }

    public function test_currency_changes_do_not_rescale_printed_values_and_can_be_cleared(): void
    {
        $transaction = Transaction::factory()->create();
        $this->extract($transaction, $this->receiptOutput('USD', '1200'));
        $transaction->refresh();
        $payload = ['revision' => $transaction->revision, 'merchant' => 'Repair shop', 'purchase_date' => '2026-11-10',
            'amount' => '1200', 'currency' => 'JPY', 'review_status' => 'needs_review', 'excluded' => false,
            'items' => [['description' => 'Service', 'quantity' => null, 'amount' => '1200', 'category' => null]]];
        $this->actingAs(User::find($transaction->user_id))->patch('/transactions/'.$transaction->id, $payload)->assertSessionHasNoErrors();
        $transaction->refresh();
        $this->assertSame(1200, $transaction->total_minor);
        $this->assertSame('1200', $transaction->items->sole()->amount_decimal);
        $payload['currency'] = null;
        $payload['revision'] = $transaction->revision;
        $this->patch('/transactions/'.$transaction->id, $payload)->assertSessionHasNoErrors();
        $transaction->refresh();
        $this->assertNull($transaction->currency);
        $this->assertNull($transaction->total_minor);
        $this->assertSame('1200', $transaction->total_decimal);
        $this->assertSame('1200', $transaction->items->sole()->amount_decimal);
    }

    public function test_arithmetic_works_for_unknown_currency_and_conflicting_currency_is_flagged(): void
    {
        $validator = new ExtractionValidator;
        $input = array_replace($this->receiptOutput(null, '10.375'), ['subtotal_decimal' => '10', 'tax_decimal' => '1.375', 'discount_decimal' => '1']);
        $data = $validator->validate($input);
        $reasons = $validator->reasons($data);
        $this->assertContains('Missing currency.', $reasons);
        $this->assertNotContains('Incomplete amounts for arithmetic check.', $reasons);
        $this->assertNotContains('Subtotal, tax, tip, and discounts do not match the total.', $reasons);
        $input['total_decimal'] = '11.375';
        $this->assertContains('Subtotal, tax, tip, and discounts do not match the total.', $validator->reasons($validator->validate($input)));
        $input['currency'] = 'USD';
        $data = $validator->validate($input);
        $this->assertNull($data['currency']);
        $this->assertNull($data['total_minor']);
        $this->assertNull($data['items'][0]['amount_minor']);
        $this->assertSame('11.375', $data['total_decimal']);
        $this->assertSame('10.375', $data['items'][0]['amount_decimal']);
        $this->assertContains('Printed amounts have decimal precision incompatible with the suggested currency. Amounts are preserved; confirm the currency.', $data['review_reasons']);
    }

    public function test_reprocessing_preserves_corrected_printed_amounts_without_a_currency(): void
    {
        $transaction = Transaction::factory()->create(['currency' => null, 'total_decimal' => '12.345', 'total_minor' => null,
            'corrected_fields' => ['currency', 'total_minor', 'total_decimal', 'items']]);
        $transaction->items()->create(['description' => 'My corrected item', 'amount_decimal' => '12.345', 'amount_minor' => null]);
        $this->extract($transaction, $this->receiptOutput('EUR', '999.00'));
        $transaction->refresh();
        $this->assertNull($transaction->currency);
        $this->assertNull($transaction->total_minor);
        $this->assertSame('12.345', $transaction->total_decimal);
        $this->assertSame('12.345', $transaction->items->sole()->amount_decimal);
        $this->assertSame('999.00', $transaction->receipt->runs->sole()->output['total_decimal']);
    }
}
