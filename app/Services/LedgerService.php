<?php

namespace App\Services;

use App\Models\Account;
use App\Models\Transaction;
use App\Models\User;

class LedgerService
{
    public function duplicates(Transaction $transaction): array
    {
        if ($transaction->merchant === null || $transaction->purchase_date === null || $transaction->total_minor === null || $transaction->currency === null) {
            return [];
        }

        return Transaction::where('user_id', $transaction->user_id)->where('id', '!=', $transaction->id)
            ->where('purchase_date', $transaction->purchase_date)->where('total_minor', $transaction->total_minor)
            ->where('currency', $transaction->currency)->whereRaw('LOWER(TRIM(merchant)) = ?', [mb_strtolower(trim($transaction->merchant))])
            ->when($transaction->account_id, fn ($q) => $q->where(fn ($q) => $q->whereNull('account_id')->orWhere('account_id', $transaction->account_id)))
            ->pluck('id')->all();
    }

    public function suggest(int $userId, ?string $suffix): array
    {
        if ($suffix === null) {
            return [null, ['No printed card suffix; choose an account if known.']];
        }
        $ids = Account::where('user_id', $userId)->whereHas('identifiers', fn ($q) => $q->where('last_four', $suffix))->pluck('id');

        return match ($ids->count()) {
            1 => [$ids->first(), ['Account suggested from printed suffix; confirm it.']],
            0 => [null, ['No account matches the printed suffix.']],
            default => [null, ['Multiple accounts share this suffix; choose the correct account.']],
        };
    }

    public function summary(User $user): array
    {
        $month = now(config('spendtrace.timezone'))->startOfMonth();
        $base = Transaction::where('user_id', $user->id)->where('excluded', false);
        $totals = (clone $base)->whereBetween('purchase_date', [$month->toDateString(), now(config('spendtrace.timezone'))->toDateString()])
            ->whereNotNull('currency')->whereNotNull('total_minor')->selectRaw('currency, SUM(total_minor) as amount')->groupBy('currency')->get()
            ->map(fn ($row) => ['currency' => $row->currency, 'amount' => Money::format((int) $row->amount, $row->currency)]);

        return ['month' => $month->format('F Y'), 'totals' => $totals,
            'unresolved' => (clone $base)->where(fn ($q) => $q->where('review_status', 'needs_review')->orWhereNull('purchase_date')->orWhereNull('total_minor')->orWhereNull('currency'))->count(),
        ];
    }

    public function present(Transaction $transaction): array
    {
        return [...$transaction->toArray(), 'amount' => Money::format($transaction->total_minor, $transaction->currency),
            'items' => $transaction->items->map(fn ($item) => [...$item->toArray(), 'amount' => Money::format($item->amount_minor, $transaction->currency)])->all()];
    }
}
