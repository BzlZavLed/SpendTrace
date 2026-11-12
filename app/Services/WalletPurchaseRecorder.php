<?php

namespace App\Services;

use App\Jobs\MatchWalletEvent;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\WalletEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

class WalletPurchaseRecorder
{
    /** Must run inside the owner's locked database transaction. */
    public function record(WalletEvent $event): void
    {
        if ($event->is_test || $event->original_transaction_id || ! $event->account_id) {
            return;
        }
        abort_unless($event->connection?->user_id === $event->user_id
            && Account::whereKey($event->account_id)->where('user_id', $event->user_id)->exists(), 404);
        $payload = $event->payload;
        $time = CarbonImmutable::parse($payload['transaction_at'] ?? $payload['observed_at']);
        $transaction = Transaction::create(['user_id' => $event->user_id, 'account_id' => $event->account_id,
            'merchant' => $payload['merchant'], 'total_decimal' => $payload['amount_decimal'],
            'total_minor' => Money::parse($payload['amount_decimal'], $event->currency), 'currency' => $event->currency,
            'purchase_date' => $time->toDateString(), 'purchase_time' => $time->format('H:i:s'), 'purchase_timezone' => $time->getTimezone()->getName(),
            'review_reasons' => array_values(array_filter(['Wallet observation; not bank reconciliation.',
                ! $payload['transaction_at'] ? 'Date and time are when the shortcut ran, not a confirmed purchase timestamp.' : null,
                ! $event->currency ? 'Currency is unknown; excluded from currency totals.' : null])), 'corrected_fields' => []]);
        $event->update(['original_transaction_id' => $transaction->id, 'transaction_id' => $transaction->id, 'status' => 'pending']);
        $transaction->observations()->create(['source_type' => 'wallet', 'source_id' => (string) $event->id,
            'payload' => $payload + ['currency_source' => $event->currency_source, 'resolved_currency' => $event->currency], 'observed_at' => $payload['observed_at']]);
    }

    public function dispatch(WalletEvent $event): void
    {
        if (! $event->is_test && in_array($event->status, ['pending', 'failed'], true) && $event->account_id) {
            try {
                MatchWalletEvent::dispatch($event->id);
            } catch (\Throwable $exception) {
                Log::warning('Wallet matching dispatch unavailable', ['exception_class' => $exception::class]);
            }
        }
    }
}
