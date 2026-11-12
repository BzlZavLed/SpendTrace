<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;
use App\Models\WalletAlias;
use App\Models\WalletConnection;
use App\Models\WalletDevice;
use App\Services\Money;
use App\Services\WalletPurchaseRecorder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WalletEventController extends Controller
{
    public function __invoke(Request $request, WalletPurchaseRecorder $recorder): JsonResponse
    {
        $allowed = ['event_id', 'observed_at', 'transaction_at', 'merchant', 'amount_decimal', 'currency', 'test', 'card_key', 'wallet_alias'];
        if (array_diff(array_keys($request->all()), $allowed)) {
            throw ValidationException::withMessages(['payload' => 'Unexpected fields. Ownership is determined by the ingestion credential.']);
        }
        $timestamp = ['required', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})$/D'];
        $data = $request->validate([
            'card_key' => 'sometimes|required|string|max:100|prohibits:wallet_alias',
            'wallet_alias' => 'sometimes|required|array:label,last_four,network|prohibits:card_key',
            'wallet_alias.label' => 'required_with:wallet_alias|string|max:100',
            'wallet_alias.last_four' => ['nullable', 'string', 'regex:/^\d{4}$/D'],
            'wallet_alias.network' => 'nullable|string|max:30',
            'event_id' => 'required|uuid', 'observed_at' => $timestamp,
            'transaction_at' => ['nullable', ...array_slice($timestamp, 1)],
            'merchant' => 'required|string|max:200', 'amount_decimal' => ['required', 'string', 'regex:/^-?\d{1,9}(?:\.\d{1,6})?$/D'],
            'currency' => ['present', 'nullable', Rule::in(array_keys(config('spendtrace.currencies')))], 'test' => 'sometimes|boolean',
        ]);
        $payload = ['event_id' => strtolower($data['event_id']), 'observed_at' => $data['observed_at'], 'transaction_at' => $data['transaction_at'] ?? null,
            'merchant' => $data['merchant'], 'amount_decimal' => Money::decimal($data['amount_decimal']), 'currency' => $data['currency'], 'test' => (bool) ($data['test'] ?? false)];
        if (isset($data['card_key'])) {
            $payload['card_key'] = $data['card_key'];
        }
        if (isset($data['wallet_alias'])) {
            $payload['wallet_alias'] = $data['wallet_alias'];
        }
        $hash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));
        $authenticated = $request->attributes->get('wallet_connection');
        [$event, $created] = DB::transaction(function () use ($authenticated, $payload, $hash, $request, $recorder): array {
            User::whereKey($authenticated->user_id)->lockForUpdate()->firstOrFail();
            $connection = WalletConnection::whereKey($authenticated->id)->lockForUpdate()->first();
            $device = $request->attributes->get('wallet_device');
            $device = $device ? WalletDevice::whereKey($device->id)->where('wallet_connection_id', $authenticated->id)->whereNull('revoked_at')->first() : null;
            $expectedHash = $request->attributes->get('wallet_device') ? $device?->token_hash : $connection?->token_hash;
            abort_unless($connection && $connection->enabled && hash_equals($expectedHash ?? '', hash('sha256', $request->bearerToken())), 401, 'Invalid ingestion credentials.');
            $existing = $connection->events()->where('event_id', $payload['event_id'])->first();
            if ($existing) {
                abort_unless(hash_equals($existing->payload_hash, $hash), 409, 'This event ID was already used with different data.');

                return [$existing, false];
            }
            $accountId = null;
            if (! $payload['test']) {
                if (isset($payload['wallet_alias'])) {
                    $identifier = $payload['wallet_alias'];
                    // Name-only routing requires an explicit owner confirmation; conflicting accounts never auto-route.
                    $mappings = WalletAlias::where('wallet_connection_id', $connection->id)
                        ->where('user_id', $connection->user_id)->where('fingerprint', WalletAlias::fingerprint($identifier))->get();
                    $ids = $mappings->pluck('account_id')->unique();
                    if (empty($identifier['last_four']) && ! $mappings->contains(fn ($mapping) => $mapping->name_confirmed_at !== null)) {
                        $ids = collect();
                    }
                    $accountId = $ids->count() === 1 ? (int) $ids->first() : null;
                } else {
                    $accountId = isset($payload['card_key'])
                        ? collect($connection->card_mappings ?? [])->first(fn ($mapping) => $mapping['card_key'] === $payload['card_key'])['account_id'] ?? null
                        : $connection->account_id;
                    if (! $accountId) {
                        throw ValidationException::withMessages(['card_key' => 'Send a card_key configured on this connection. No purchase was recorded.']);
                    }
                }
                if ($accountId && ! Account::whereKey($accountId)->where('user_id', $connection->user_id)->exists()) {
                    throw ValidationException::withMessages(['card_key' => 'Send a card_key configured on this connection. No purchase was recorded.']);
                }
                $accountId = $accountId ? (int) $accountId : null;
            }
            $currency = $payload['currency'] ?? $connection->default_currency;
            $minor = Money::parse($payload['amount_decimal'], $currency, 'amount_decimal');
            $event = $connection->events()->create(['user_id' => $connection->user_id, 'account_id' => $accountId, 'wallet_device_id' => $device?->id, 'event_id' => $payload['event_id'], 'payload_hash' => $hash, 'payload' => $payload,
                'currency' => $currency, 'currency_source' => $payload['currency'] ? 'payload' : ($currency ? 'connection_default' : 'unknown'),
                'is_test' => $payload['test'], 'status' => $payload['test'] ? 'test_received' : ($accountId ? 'pending' : 'needs_account')]);
            $connection->update([$payload['test'] ? 'last_test_at' : 'last_event_at' => now()]);
            if ($device) {
                $device->update([$payload['test'] ? 'last_test_at' : 'last_event_at' => now()]);
            }
            $recorder->record($event);

            return [$event, true];
        }, 3);
        $recorder->dispatch($event);

        // No ledger identifiers or account information are returned to ingestion credentials.
        return response()->json(['event_id' => $event->event_id, 'accepted' => true, 'replayed' => ! $created,
            'status' => $event->fresh()->status], $created ? 201 : 200);
    }
}
