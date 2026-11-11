<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Receipt;
use App\Models\Transaction;
use App\Services\LedgerService;
use App\Services\Money;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TransactionController extends Controller
{
    public function show(Request $request, Transaction $transaction, LedgerService $ledger): Response
    {
        abort_unless($transaction->user_id === $request->user()->id, 404);
        $transaction->load(['receipt.images', 'receipt.runs', 'items', 'account']);

        return Inertia::render('Transaction', ['transaction' => $ledger->present($transaction), 'accounts' => Account::where('user_id', $request->user()->id)->orderBy('nickname')->get(), 'duplicates' => Transaction::where('user_id', $request->user()->id)->whereIn('id', $ledger->duplicates($transaction))->get(['id', 'merchant', 'purchase_date'])]);
    }

    public function update(Request $request, Transaction $transaction, LedgerService $ledger): RedirectResponse
    {
        abort_unless($transaction->user_id === $request->user()->id, 404);
        if (preg_match('/^\d{2}:\d{2}$/D', (string) $request->input('purchase_time'))) {
            $request->merge(['purchase_time' => $request->input('purchase_time').':00']);
        }
        $data = $request->validate([
            'revision' => 'required|integer', 'merchant' => 'nullable|string|max:200', 'purchase_date' => 'nullable|date_format:Y-m-d', 'purchase_time' => 'nullable|date_format:H:i:s',
            'amount' => 'nullable|string|max:15', 'currency' => ['nullable', Rule::in(array_keys(config('spendtrace.currencies')))],
            'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)], 'category' => ['nullable', Rule::in(config('spendtrace.categories'))],
            'review_status' => ['required', Rule::in(['needs_review', 'reviewed'])], 'excluded' => 'required|boolean',
            'items' => 'present|array|max:200', 'items.*.description' => 'nullable|string|max:300', 'items.*.quantity' => ['nullable', 'regex:/^\d{1,8}(?:\.\d{1,3})?$/D'],
            'items.*.amount' => 'nullable|string|max:15', 'items.*.category' => ['nullable', Rule::in(config('spendtrace.categories'))],
        ]);
        $data['total_minor'] = Money::parse($data['amount'] ?? null, $data['currency'] ?? null);
        if ($data['review_status'] === 'reviewed' && ! $data['excluded'] && (! ($data['merchant'] ?? null) || ! ($data['purchase_date'] ?? null) || $data['total_minor'] === null || ! ($data['currency'] ?? null))) {
            throw ValidationException::withMessages(['review_status' => 'Merchant, purchase date, total, and currency are required to mark reviewed.']);
        }
        $items = array_map(fn ($item) => ['description' => $item['description'] ?? null, 'quantity' => $item['quantity'] ?? null, 'amount_minor' => Money::parse($item['amount'] ?? null, $data['currency'] ?? null, 'items'), 'category' => $item['category'] ?? null], $data['items']);
        DB::transaction(function () use ($transaction, $data, $items, $ledger): void {
            Receipt::whereKey($transaction->receipt_id)->lockForUpdate()->firstOrFail();
            $current = Transaction::whereKey($transaction->id)->lockForUpdate()->firstOrFail();
            if ($current->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'This receipt changed during editing. Reload to see the latest extraction before saving.']);
            }
            $fields = ['merchant', 'purchase_date', 'purchase_time', 'total_minor', 'currency', 'account_id', 'category'];
            $corrected = $current->corrected_fields ?? [];
            foreach ($fields as $field) {
                $value = $data[$field] ?? null;
                if ((string) $current->$field !== (string) $value) {
                    $corrected[] = $field;
                }
                $current->$field = $value;
            }
            if (in_array('currency', $corrected, true) || in_array('total_minor', $corrected, true)) {
                $corrected = [...$corrected, 'currency', 'total_minor'];
            }
            $existingItems = $current->items->map(fn ($item) => $item->only(['description', 'quantity', 'amount_minor', 'category']))->all();
            if ($existingItems != $items || in_array('currency', $corrected, true)) {
                $corrected[] = 'items';
                $current->items()->delete();
                $current->items()->createMany($items);
            }
            if (in_array('items', $corrected, true)) {
                $corrected = [...$corrected, 'currency', 'total_minor'];
            }
            if ($data['review_status'] === 'reviewed') {
                $corrected = [...$corrected, ...$fields, 'items'];
            }
            $current->corrected_fields = array_values(array_unique($corrected));
            $current->review_status = $data['review_status'];
            $current->excluded = $data['excluded'];
            $current->revision++;
            $current->save();
            $current->duplicate_ids = $ledger->duplicates($current);
            $current->save();
        });

        return back()->with('notice', 'Changes saved. This purchase remains bank-unreconciled.');
    }
}
