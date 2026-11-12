<?php

namespace App\Jobs;

use App\Models\ExtractionRun;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\User;
use App\Models\WalletEvent;
use App\Services\ExtractionValidator;
use App\Services\LedgerService;
use App\Services\Money;
use App\Services\PurchaseMatcher;
use App\Services\ReceiptExtractionException;
use App\Services\ReceiptExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

class ExtractReceipt implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public int $receiptId, public int $generation) {}

    public function handle(ReceiptExtractor $extractor, ExtractionValidator $validator, LedgerService $ledger): void
    {
        $stage = 'load_receipt';
        try {
            $receipt = Receipt::findOrFail($this->receiptId);
            if ($receipt->generation !== $this->generation || in_array($receipt->status, ['ready', 'failed'], true)) {
                return;
            }
            $ownerId = $receipt->user_id;
            $stage = 'provider_request';
            $raw = $extractor->extract($receipt->load('images'));
            $stage = 'validate_output';
            $data = $validator->validate($raw);
            // Network extraction must not hold the owner lock and block incoming Wallet events.
            DB::transaction(function () use ($data, $validator, $ledger, $ownerId, &$stage): void {
                User::whereKey($ownerId)->lockForUpdate()->firstOrFail();
                $receipt = Receipt::whereKey($this->receiptId)->where('user_id', $ownerId)->lockForUpdate()->firstOrFail();
                if ($receipt->generation !== $this->generation || in_array($receipt->status, ['ready', 'failed'], true)) {
                    return;
                }
                $receipt->extraction_version = config('spendtrace.extraction_version');
                $stage = 'save_ledger';
                ExtractionRun::create(['receipt_id' => $receipt->id, 'generation' => $this->generation,
                    'provider' => config('spendtrace.provider'), 'version' => $receipt->extraction_version, 'output' => $data]);
                $transaction = $receipt->transaction()->lockForUpdate()->firstOrFail();
                $corrected = $transaction->corrected_fields ?? [];
                [$accountId, $accountReasons] = $ledger->suggest($receipt->user_id, $data['card_last_four']);
                $values = array_intersect_key($data, array_flip(['merchant', 'purchase_date', 'purchase_time', 'total_minor', 'total_decimal', 'currency', 'category']));
                $wallet = WalletEvent::where('transaction_id', $transaction->id)->where('status', 'matched')->first();
                if ($wallet) {
                    if ($accountId !== null && $accountId !== $wallet->account_id) {
                        $accountReasons[] = 'Receipt account conflicts with the attached Wallet event. Review and separate the attachment.';
                    }
                    $accountId = $wallet->account_id;
                }
                $values['account_id'] = $accountId;
                if ($wallet) {
                    $walletPurchase = Transaction::whereKey($wallet->original_transaction_id)->where('user_id', $receipt->user_id)->firstOrFail();
                    $amountConflict = $data['total_decimal'] !== null && $walletPurchase->total_decimal !== null
                        && Money::scaled($data['total_decimal']) !== Money::scaled($walletPurchase->total_decimal);
                    $currencyConflict = $data['currency'] !== null && $walletPurchase->currency !== null && $data['currency'] !== $walletPurchase->currency;
                    if ($amountConflict || $currencyConflict) {
                        $accountReasons[] = 'New extraction conflicts with the attached Wallet amount or currency. Saved purchase amounts were preserved; compare sources or undo the attachment.';
                    }
                    if ($amountConflict || $currencyConflict || ($data['currency'] === null && $walletPurchase->currency !== null)) {
                        unset($values['total_minor'], $values['total_decimal'], $values['currency']);
                    }
                }
                foreach ($values as $field => $value) {
                    if (! in_array($field, $corrected, true) && ! ($field === 'total_decimal' && in_array('total_minor', $corrected, true))) {
                        $transaction->$field = $value;
                    }
                }
                if (! in_array('items', $corrected, true)) {
                    $transaction->items()->delete();
                    $items = $data['items'];
                    if ($transaction->currency !== $data['currency']) {
                        foreach ($items as &$item) {
                            try {
                                $item['amount_minor'] = Money::parse($item['amount_decimal'], $transaction->currency);
                            } catch (ValidationException $exception) {
                                $item['amount_minor'] = null;
                                $data['review_reasons'][] = 'An extracted item amount needs review against your corrected currency.';
                            }
                        }
                        unset($item);
                    }
                    $transaction->items()->createMany($items);
                }
                $transaction->review_reasons = [...$validator->reasons($data), ...$accountReasons];
                $transaction->review_status = 'needs_review';
                $transaction->revision++;
                $transaction->save();
                $transaction->duplicate_ids = $ledger->duplicates($transaction);
                if ($transaction->duplicate_ids) {
                    $transaction->review_reasons = [...$transaction->review_reasons, 'Possible duplicate purchase. Compare before excluding it.'];
                }
                $transaction->save();
                $transaction->observations()->firstOrCreate(['source_type' => 'receipt', 'source_id' => (string) $receipt->id], ['observed_at' => $receipt->created_at]);
                $receipt->update(['status' => 'ready', 'provider' => config('spendtrace.provider'), 'error' => null]);
                app(PurchaseMatcher::class)->forReceipt($transaction);
            });
        } catch (Throwable $e) {
            $this->logFailure($e, $stage);
            $this->recordFailure();
        }
    }

    public function failed(?Throwable $exception): void
    {
        if ($exception) {
            $this->logFailure($exception, 'queue_failure');
        }
        $this->recordFailure();
    }

    private function logFailure(Throwable $exception, string $stage): void
    {
        $context = [
            'receipt_id' => $this->receiptId,
            'generation' => $this->generation,
            'stage' => $stage,
            'exception_class' => $exception::class,
            'source' => basename($exception->getFile()).':'.$exception->getLine(),
        ];
        if ($exception instanceof ReceiptExtractionException) {
            $context += $exception->diagnostics;
            $context['diagnostic'] = $exception->getMessage();
        } elseif ($exception instanceof ValidationException) {
            $context['diagnostic'] = 'Extracted fields failed validation; values omitted.';
            $context['invalid_fields'] = array_values(array_filter(array_keys($exception->errors()), fn ($field) => preg_match('/^(merchant|purchase_date|purchase_time|currency|card_last_four|category|arithmetic_complete|printed_item_count|total_(?:minor|decimal)|subtotal_(?:minor|decimal)|tax_(?:minor|decimal)|tip_(?:minor|decimal)|discount_(?:minor|decimal)|review_reasons(?:\.\d+)?|items(?:\.\d+(?:\.(?:description|quantity|amount_(?:minor|decimal)|category))?)?)$/D', $field)));
        } elseif ($exception instanceof ConnectionException) {
            $context['diagnostic'] = 'Could not connect to OpenAI or the request timed out. Check connectivity and provider availability.';
        } else {
            $context['diagnostic'] = 'Extraction failed at the indicated stage. Inspect the exception class and source location; exception text is withheld to protect receipt data.';
        }
        Log::error('Receipt extraction failed', $context);
    }

    private function recordFailure(): void
    {
        DB::transaction(function (): void {
            $receipt = Receipt::whereKey($this->receiptId)->lockForUpdate()->first();
            if (! $receipt || $receipt->generation !== $this->generation || $receipt->status === 'ready') {
                return;
            }
            $error = 'Extraction failed. Your originals are safe. Retry, or enter the details manually.';
            $receipt->update(['status' => 'failed', 'error' => $error]);
            ExtractionRun::firstOrCreate(['receipt_id' => $receipt->id, 'generation' => $this->generation],
                ['provider' => config('spendtrace.provider'), 'version' => config('spendtrace.extraction_version'), 'error' => $error]);
        });
    }
}
