<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Models\TransactionObservation;
use App\Models\User;
use App\Models\WalletEvent;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseMatcher
{
    public static function merchant(?string $value): string
    {
        return trim(preg_replace('/[\p{P}\p{Z}\s]+/u', ' ', mb_strtolower($value ?? '')));
    }

    /** Caller and all mutations serialize on the owner's row, before locking other rows. */
    public function process(int $eventId): void
    {
        $event = WalletEvent::find($eventId);
        if (! $event || $event->is_test) {
            return;
        }
        DB::transaction(function () use ($event): void {
            $connection = $event->connection;
            if (! $connection) {
                return;
            }
            User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $event = $event->fresh();
            if (in_array($event->status, ['matched', 'separate'], true) || ! $this->validOrigin($event)) {
                return;
            }
            $candidates = $this->candidates($event);
            $strong = $candidates->filter(fn ($t) => $this->strong($event, $t));
            if ($strong->count() === 1 && $this->competingEvents($event, $strong->first()) === 0) {
                $this->attach($event, $strong->first());
            } else {
                $event->update(['status' => $candidates->isEmpty() ? 'unmatched' : 'needs_review']);
            }
        }, 3);
    }

    public function forReceipt(Transaction $receiptTransaction): void
    {
        WalletEvent::where('is_test', false)->whereIn('status', ['pending', 'unmatched', 'needs_review', 'failed'])
            ->whereHas('connection', fn ($q) => $q->where('user_id', $receiptTransaction->user_id))
            ->orderBy('id')->pluck('id')->each(fn ($id) => $this->process($id));
    }

    public function candidates(WalletEvent $event, bool $includeDismissed = false): Collection
    {
        if (! $this->validOrigin($event)) {
            return collect();
        }
        $connection = $event->connection;
        $origin = Transaction::find($event->original_transaction_id);

        return Transaction::where('user_id', $connection->user_id)->whereNull('merged_into_id')->whereNotNull('receipt_id')
            ->where('excluded', false)->whereHas('receipt', fn ($q) => $q->where('user_id', $connection->user_id)->where('status', 'ready'))
            ->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $event->account_id))
            ->when(! $includeDismissed, fn ($q) => $q->whereNotIn('id', $event->dismissed_ids ?? []))
            ->whereDoesntHave('observations', fn ($q) => $q->where('source_type', 'wallet'))
            ->whereBetween('purchase_date', [CarbonImmutable::parse($event->payload['transaction_at'] ?? $event->payload['observed_at'])->subDay()->toDateString(), CarbonImmutable::parse($event->payload['transaction_at'] ?? $event->payload['observed_at'])->addDay()->toDateString()])
            ->with(['items', 'account', 'receipt'])->get()
            ->filter(fn ($t) => $this->compatible($event, $t)
                && self::merchant($t->merchant) !== '' && self::merchant($t->merchant) === self::merchant($origin->merchant));
    }

    private function validOrigin(WalletEvent $event): bool
    {
        $c = $event->connection;

        return $c && $event->user_id === $c->user_id && $event->account_id !== null && ! $event->is_test && Account::whereKey($event->account_id)->where('user_id', $c->user_id)->exists()
            && Transaction::whereKey($event->original_transaction_id)->where('user_id', $c->user_id)->where('account_id', $event->account_id)->whereNull('receipt_id')->exists();
    }

    private function compatible(WalletEvent $event, Transaction $receipt): bool
    {
        $c = $event->connection;
        $origin = Transaction::find($event->original_transaction_id);
        if (! $origin || $receipt->user_id !== $c->user_id || ($receipt->account_id !== null && $receipt->account_id !== $event->account_id)
            || ($receipt->currency && $origin->currency && $receipt->currency !== $origin->currency)) {
            return false;
        }
        $a = $receipt->total_decimal ?? Money::format($receipt->total_minor, $receipt->currency);
        $b = $origin->total_decimal;

        return $a !== null && $b !== null && Money::scaled($a) === Money::scaled($b);
    }

    private function strong(WalletEvent $event, Transaction $receipt): bool
    {
        $origin = Transaction::find($event->original_transaction_id);
        if (! $this->compatible($event, $receipt) || ! $receipt->currency || ! $origin->currency
            || Money::scaled($origin->total_decimal) <= 0 || $origin->excluded) {
            return false;
        }
        $time = CarbonImmutable::parse($event->payload['transaction_at'] ?? $event->payload['observed_at']);
        if ((in_array('purchase_date', $origin->corrected_fields ?? [], true) && $origin->purchase_date !== $time->toDateString())
            || (in_array('purchase_time', $origin->corrected_fields ?? [], true) && $origin->purchase_time !== $time->format('H:i:s'))) {
            return false;
        }

        if ($receipt->purchase_timezone) {
            $time = $time->setTimezone($receipt->purchase_timezone);
        }
        if (! $receipt->purchase_time) {
            return $receipt->account_id === $event->account_id && $receipt->purchase_date === $time->toDateString();
        }
        $printed = CarbonImmutable::parse($receipt->purchase_date.' '.$receipt->purchase_time, $time->getTimezone());

        // Unknown receipt timezone uses only the event's supplied offset, never an expanded window.
        return abs($printed->getTimestamp() - $time->getTimestamp()) <= 300
            && ($receipt->account_id !== null || $event->payload['transaction_at'] !== null);
    }

    private function competingEvents(WalletEvent $event, Transaction $receipt): int
    {
        return WalletEvent::where('id', '!=', $event->id)->where('is_test', false)
            ->whereIn('status', ['pending', 'unmatched', 'needs_review', 'failed'])
            ->whereHas('connection', fn ($q) => $q->where('user_id', $event->connection->user_id))
            ->get()->filter(fn ($other) => $this->validOrigin($other)
                && ! in_array($receipt->id, $other->dismissed_ids ?? [], true)
                && self::merchant(Transaction::find($other->original_transaction_id)->merchant) === self::merchant($receipt->merchant)
                && $this->strong($other, $receipt))->count();
    }

    /** Public entry point also used by the authenticated manual-review controller. */
    public function manual(int $userId, int $eventId, int $transactionId): void
    {
        DB::transaction(function () use ($userId, $eventId, $transactionId): void {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $event = WalletEvent::whereKey($eventId)->whereHas('connection', fn ($q) => $q->where('user_id', $userId))->firstOrFail();
            $target = Transaction::whereKey($transactionId)->where('user_id', $userId)->whereNull('merged_into_id')->firstOrFail();
            $this->attach($event, $target);
        }, 3);
    }

    private function attach(WalletEvent $event, Transaction $target): void
    {
        if (! $this->validOrigin($event) || $event->status === 'matched' || ! $this->compatible($event, $target)
            || ! $target->receipt_id || $target->excluded || $target->merged_into_id
            || ! Receipt::whereKey($target->receipt_id)->where('user_id', $event->connection->user_id)->where('status', 'ready')->exists()
            || $target->observations()->where('source_type', 'wallet')->exists()) {
            throw ValidationException::withMessages(['match' => 'Cannot attach these records. Check their accounts, currencies, exact totals, receipt status, and existing attachments.']);
        }
        $origin = Transaction::whereKey($event->original_transaction_id)->lockForUpdate()->firstOrFail();
        if ($origin->excluded) {
            throw ValidationException::withMessages(['match' => 'An excluded Wallet purchase cannot be attached.']);
        }
        $before = [];
        $applied = [];
        $corrected = $target->corrected_fields ?? [];
        $reasons = $target->review_reasons ?? [];
        foreach (['merchant', 'purchase_date', 'purchase_time', 'total_decimal', 'total_minor', 'currency', 'category', 'account_id'] as $field) {
            if ($origin->$field !== null && $target->$field !== null && (string) $origin->$field !== (string) $target->$field) {
                $reasons[] = 'Wallet and receipt differ on '.$field.'; original source values are retained.';
            }
            if (! in_array($field, $corrected, true) && ($target->$field === null || in_array($field, $origin->corrected_fields ?? [], true))) {
                // Do not import shortcut execution time into a date-only receipt.
                if (in_array($field, ['purchase_date', 'purchase_time']) && ! $event->payload['transaction_at']) {
                    continue;
                }
                $before[$field] = $target->$field;
                $target->$field = $origin->$field;
                $applied[$field] = $origin->$field;
                if (in_array($field, $origin->corrected_fields ?? [], true)) {
                    $corrected[] = $field;
                }
            }
        }
        if (in_array('items', $origin->corrected_fields ?? [], true) && ! in_array('items', $corrected, true)) {
            $target->items()->delete();
            $target->items()->createMany($origin->items->map(fn ($item) => $item->only(['description', 'quantity', 'amount_decimal', 'amount_minor', 'category']))->all());
            $corrected[] = 'items';
            $reasons[] = 'Items corrected on the Wallet purchase take precedence; extracted items remain in receipt history.';
        }
        $target->corrected_fields = array_values(array_unique($corrected));
        $target->review_reasons = array_values(array_unique([...$reasons, 'Wallet and receipt attached; this is not bank reconciliation.']));
        $target->review_status = 'needs_review';
        $target->revision++;
        $target->save();
        $origin->update(['merged_into_id' => $target->id, 'revision' => $origin->revision + 1]);
        TransactionObservation::where('transaction_id', $origin->id)->where('source_type', 'wallet')->where('source_id', (string) $event->id)->update(['transaction_id' => $target->id]);
        $event->update(['transaction_id' => $target->id, 'status' => 'matched', 'match_snapshot' => ['before' => $before, 'applied' => $applied]]);
    }

    public function separate(int $userId, int $eventId): void
    {
        DB::transaction(function () use ($userId, $eventId): void {
            User::whereKey($userId)->lockForUpdate()->firstOrFail();
            $event = WalletEvent::whereKey($eventId)->whereHas('connection', fn ($q) => $q->where('user_id', $userId))->firstOrFail();
            abort_unless($this->validOrigin($event), 404);
            $dismissed = $event->dismissed_ids ?? [];
            if ($event->status === 'matched') {
                $target = Transaction::whereKey($event->transaction_id)->where('user_id', $userId)->lockForUpdate()->firstOrFail();
                abort_unless($target->account_id === null || $target->account_id === $event->account_id, 404);
                $dismissed[] = $target->id;
                foreach ($event->match_snapshot['before'] ?? [] as $field => $value) {
                    if (! in_array($field, $target->corrected_fields ?? [], true) && $target->$field === ($event->match_snapshot['applied'][$field] ?? null)) {
                        $target->$field = $value;
                    }
                }
                $target->review_reasons = [...($target->review_reasons ?? []), 'Wallet attachment removed. Both purchases are counted separately.'];
                $target->revision++;
                $target->save();
                Transaction::whereKey($event->original_transaction_id)->where('user_id', $userId)->update(['merged_into_id' => null]);
                TransactionObservation::where('transaction_id', $target->id)->where('source_type', 'wallet')->where('source_id', (string) $event->id)->update(['transaction_id' => $event->original_transaction_id]);
            }
            $event->update(['status' => 'separate', 'transaction_id' => $event->original_transaction_id, 'dismissed_ids' => array_values(array_unique($dismissed)), 'match_snapshot' => null]);
        }, 3);
    }
}
