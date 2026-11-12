<?php

namespace App\Http\Middleware;

use App\Models\WalletConnection;
use App\Models\WalletDevice;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWallet
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_if(strlen($request->getContent()) > 8192 || (int) $request->header('Content-Length') > 8192, 413, 'Request too large.');
        abort_unless($request->isJson(), 415, 'Use application/json.');
        $token = $request->bearerToken();
        $device = null;
        $connection = null;
        if (is_string($token) && strlen($token) === 67) {
            if (str_starts_with($token, 'st_')) {
                $connection = WalletConnection::where('token_hash', hash('sha256', $token))->where('enabled', true)->first();
            } elseif (str_starts_with($token, 'wd_')) {
                abort_unless($request->isSecure(), 426, 'HTTPS is required for device credentials.');
                $device = WalletDevice::where('token_hash', hash('sha256', $token))->whereNull('revoked_at')->first();
                $connection = $device?->connection;
                if (! $connection?->enabled) {
                    $connection = null;
                }
            }
        }
        abort_unless($connection, 401, 'Invalid ingestion credentials.');
        $key = 'wallet-connection:'.$connection->id;
        abort_if(RateLimiter::tooManyAttempts($key, 60), 429, 'Too many submissions.');
        RateLimiter::hit($key, 60);
        $request->attributes->set('wallet_connection', $connection);
        $request->attributes->set('wallet_device', $device);

        return $next($request)->header('Cache-Control', 'private, no-store');
    }
}
