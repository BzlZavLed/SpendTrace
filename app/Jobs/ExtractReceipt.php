<?php

namespace App\Jobs;

use App\Models\ExtractionRun;
use App\Models\Receipt;
use App\Services\ExtractionValidator;
use App\Services\LedgerService;
use App\Services\ReceiptExtractor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
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
        try {
            DB::transaction(function () use ($extractor, $validator, $ledger): void {
                $receipt = Receipt::whereKey($this->receiptId)->lockForUpdate()->firstOrFail();
                if ($receipt->generation !== $this->generation || $receipt->status === 'ready' || $receipt->status === 'failed') {
                    return;
                }
                $data = $validator->validate($extractor->extract($receipt->load('images')));
                ExtractionRun::create(['receipt_id' => $receipt->id, 'generation' => $this->generation,
                    'provider' => config('spendtrace.provider'), 'version' => $receipt->extraction_version, 'output' => $data]);
                $transaction = $receipt->transaction()->lockForUpdate()->firstOrFail();
                $corrected = $transaction->corrected_fields ?? [];
                [$accountId, $accountReasons] = $ledger->suggest($receipt->user_id, $data['card_last_four']);
                $values = array_intersect_key($data, array_flip(['merchant', 'purchase_date', 'purchase_time', 'total_minor', 'currency', 'category']));
                $values['account_id'] = $accountId;
                foreach ($values as $field => $value) {
                    if (! in_array($field, $corrected, true)) {
                        $transaction->$field = $value;
                    }
                }
                if (! in_array('items', $corrected, true)) {
                    $transaction->items()->delete();
                    $transaction->items()->createMany($data['items']);
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
            });
        } catch (Throwable $e) {
            $this->recordFailure();
        }
    }

    public function failed(?Throwable $exception): void
    {
        $this->recordFailure();
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
                ['provider' => config('spendtrace.provider'), 'version' => $receipt->extraction_version, 'error' => $error]);
        });
    }
}
