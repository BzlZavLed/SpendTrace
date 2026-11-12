<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\WalletConnection;
use App\Models\WalletDevice;
use App\Models\WalletPairing;
use App\Services\WalletShortcutRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WalletPairingController extends Controller
{
    public function store(Request $request, WalletConnection $connection, WalletShortcutRelease $release): JsonResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 404);
        $data = $request->validate(['device_name' => 'required|string|max:100']);
        $origin = $release->origin();
        abort_unless($origin, 422, 'Configure a reachable HTTPS origin before pairing.');
        $code = bin2hex(random_bytes(16));
        $pairing = DB::transaction(function () use ($connection, $data, $code): WalletPairing {
            User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $current = WalletConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->enabled, 422, 'Enable the connection before pairing.');

            return WalletPairing::create(['wallet_connection_id' => $current->id, 'device_name' => $data['device_name'],
                'code_hash' => hash('sha256', $code), 'expires_at' => now()->addMinutes(10)]);
        });

        return response()->json(['code' => $code, 'expires_at' => $pairing->expires_at->toIso8601String(),
            'pairing_url' => $origin.'/api/v1/integrations/wallet/pair'])->header('Cache-Control', 'private, no-store');
    }

    public function connect(Request $request, WalletConnection $connection, WalletShortcutRelease $release): JsonResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 404);
        abort_unless($request->isSecure(), 426, 'Open SpendTrace over HTTPS to connect your iPhone.');
        $request->validate(['user_id' => 'missing', 'account_id' => 'missing', 'connection_id' => 'missing']);
        abort_unless($release->present()['connect_available'], 409, 'The shortcut update is not ready for installation yet.');
        $request->merge(['device_name' => 'My iPhone']);
        $setup = $this->store($request, $connection, $release)->getData(true);
        $input = json_encode(['action' => 'spendtrace-connect-v1', 'pairing_url' => $setup['pairing_url'], 'code' => $setup['code']], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $url = 'shortcuts://run-shortcut?'.http_build_query([
            'name' => 'SpendTrace — Capture Purchase', 'input' => 'text', 'text' => $input,
        ], '', '&', PHP_QUERY_RFC3986);

        return response()->json(['launch_url' => $url, 'expires_at' => $setup['expires_at']])
            ->header('Cache-Control', 'private, no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function exchange(Request $request, WalletShortcutRelease $release): JsonResponse
    {
        abort_unless($request->isSecure(), 426, 'HTTPS is required for pairing.');
        abort_if(strlen($request->getContent()) > 2048, 413, 'Request too large.');
        abort_unless($request->isJson(), 415, 'Use application/json.');
        abort_if($request->query() !== [], 422, 'Pairing codes must be sent in the JSON body, never the URL.');
        abort_if(array_diff(array_keys($request->all()), ['code']) !== [], 422, 'Only a pairing code is accepted.');
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[a-f0-9]{32}$/D']]);
        $origin = $release->origin();
        abort_unless($origin, 503, 'Pairing is unavailable.');
        $pairing = WalletPairing::where('code_hash', hash('sha256', $data['code']))->first();
        abort_unless($pairing && $pairing->connection, 401, 'Invalid or expired pairing code.');
        $token = 'wd_'.bin2hex(random_bytes(32));
        DB::transaction(function () use ($pairing, $token): void {
            $owner = $pairing->connection->user_id;
            User::whereKey($owner)->lockForUpdate()->firstOrFail();
            $connection = WalletConnection::whereKey($pairing->wallet_connection_id)->where('user_id', $owner)->lockForUpdate()->first();
            $current = WalletPairing::whereKey($pairing->id)->lockForUpdate()->firstOrFail();
            abort_unless($connection && $connection->enabled && ! $current->redeemed_at && $current->expires_at->isFuture(), 401, 'Invalid or expired pairing code.');
            WalletDevice::create(['wallet_connection_id' => $connection->id, 'name' => $current->device_name, 'token_hash' => hash('sha256', $token)]);
            $current->update(['redeemed_at' => now()]);
        }, 3);

        return response()->json(['token' => $token, 'endpoint' => $origin.'/api/v1/integrations/wallet/events',
            'schema_version' => 'wallet-shortcut-v1', 'shortcut_version' => config('spendtrace.wallet_shortcut_version'),
            'currency' => null, 'retry_policy' => 'none'])->header('Cache-Control', 'private, no-store');
    }

    public function importConfiguration(Request $request, WalletConnection $connection, WalletShortcutRelease $release): JsonResponse
    {
        abort_unless($connection->user_id === $request->user()->id, 404);
        abort_unless($request->isSecure(), 426, 'HTTPS is required for device credentials.');
        $data = $request->validate(['device_name' => 'required|string|max:100']);
        $origin = $release->origin();
        abort_unless($origin, 422, 'Configure a reachable HTTPS origin before issuing device credentials.');
        $token = 'wd_'.bin2hex(random_bytes(32));
        DB::transaction(function () use ($connection, $data, $token): void {
            User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $current = WalletConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->enabled, 422, 'Enable the connection first.');
            WalletDevice::create(['wallet_connection_id' => $current->id, 'name' => $data['device_name'],
                'registration_method' => 'import_question', 'token_hash' => hash('sha256', $token)]);
        });

        return response()->json(['token' => $token, 'endpoint' => $origin.'/api/v1/integrations/wallet/events',
            'schema_version' => 'wallet-shortcut-v1', 'shortcut_version' => config('spendtrace.wallet_shortcut_version'),
            'currency' => null, 'retry_policy' => 'none'])->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, WalletConnection $connection, WalletDevice $device): RedirectResponse
    {
        abort_unless($connection->user_id === $request->user()->id && $device->wallet_connection_id === $connection->id, 404);
        DB::transaction(function () use ($connection, $device): void {
            User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            WalletDevice::whereKey($device->id)->where('wallet_connection_id', $connection->id)->update(['revoked_at' => now()]);
        });

        return back()->with('notice', 'Device credential revoked. Existing purchases and observations remain available.');
    }
}
