<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireLegacyWalletServer
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('spendtrace.legacy_wallet_server_enabled', false), 410, 'Wallet capture has moved to the native SpendTrace app.');

        return $next($request);
    }
}
