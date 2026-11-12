<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletAlias;
use App\Models\WalletConnection;
use App\Models\WalletEvent;
use App\Services\WalletPurchaseRecorder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WalletAliasController extends Controller
{
    public function resolve(Request $request, WalletEvent $event, WalletPurchaseRecorder $recorder): RedirectResponse
    {
        abort_unless($event->user_id === $request->user()->id, 404);
        $data = $request->validate(['account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'remember' => 'required|boolean', 'confirmed' => 'accepted', 'name_unique' => 'sometimes|boolean']);
        DB::transaction(function () use ($request, $event, $data, $recorder): void {
            User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $current = WalletEvent::whereKey($event->id)->where('user_id', $request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'needs_account' && ! $current->is_test && ! $current->original_transaction_id && $current->account_id === null, 409, 'This observation was already assigned or cannot be assigned.');
            $identifier = $current->payload['wallet_alias'] ?? null;
            abort_unless($identifier, 422, 'No Wallet identifier was supplied.');
            if ($data['remember']) {
                if (empty($identifier['last_four']) && ! ($data['name_unique'] ?? false)) {
                    throw ValidationException::withMessages(['remember' => 'Confirm that this name identifies only one card in this Wallet connection, or assign without remembering.']);
                }
                WalletAlias::updateOrCreate(['wallet_connection_id' => $current->wallet_connection_id, 'fingerprint' => WalletAlias::fingerprint($identifier), 'account_id' => $data['account_id']],
                    ['user_id' => $current->user_id, 'identifier' => $identifier, 'name_confirmed_at' => empty($identifier['last_four']) ? now() : null]);
            }
            $current->update(['account_id' => $data['account_id']]);
            $recorder->record($current);
        }, 3);
        $recorder->dispatch($event->fresh());

        return back()->with('notice', 'Purchase assigned to your account. The original Wallet observation is preserved.');
    }

    public function store(Request $request, WalletConnection $connection): RedirectResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 404);
        $data = $request->validate([
            'label' => 'required|string|max:100',
            'account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'name_unique' => 'accepted',
        ]);
        DB::transaction(function () use ($connection, $data): void {
            User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            WalletConnection::whereKey($connection->id)->where('user_id', $connection->user_id)->lockForUpdate()->firstOrFail();
            $identifier = ['label' => trim($data['label'])];
            WalletAlias::updateOrCreate([
                'wallet_connection_id' => $connection->id,
                'fingerprint' => WalletAlias::fingerprint($identifier),
                'account_id' => $data['account_id'],
            ], ['user_id' => $connection->user_id, 'identifier' => $identifier, 'name_confirmed_at' => now()]);
        }, 3);

        return back()->with('notice', 'Card name mapping saved. Conflicting mappings require review; existing purchases keep their accounts.');
    }

    public function destroy(Request $request, WalletConnection $connection, WalletAlias $alias): RedirectResponse
    {
        abort_unless($connection->user_id === $request->user()->id && $alias->user_id === $request->user()->id && $alias->wallet_connection_id === $connection->id, 404);
        DB::transaction(function () use ($connection, $alias): void {
            User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $alias->delete();
        });

        return back()->with('notice', 'Alias mapping removed. Existing purchases keep their assigned accounts.');
    }
}
