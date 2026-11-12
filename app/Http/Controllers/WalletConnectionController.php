<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\User;
use App\Models\WalletConnection;
use App\Models\WalletEvent;
use App\Services\WalletShortcutRelease;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class WalletConnectionController extends Controller
{
    public function index(Request $request, WalletShortcutRelease $release): Response
    {
        return Inertia::render('Wallet', [
            'connections' => WalletConnection::where('user_id', $request->user()->id)->with(['devices', 'aliases'])->latest('id')->get()->map(fn ($c) => $c->toArray() + ['has_token' => $c->token_hash !== null]),
            'shortcut' => $release->present(),
            'unresolved' => WalletEvent::where('user_id', $request->user()->id)->where('status', 'needs_account')->latest('id')->paginate(20),
            'accounts' => Account::where('user_id', $request->user()->id)->get(),
            'endpoint' => rtrim(config('spendtrace.wallet_public_url') ?: config('app.url'), '/').'/api/v1/integrations/wallet/events',
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100', 'account_id' => ['nullable', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)],
            'default_currency' => ['nullable', Rule::in(array_keys(config('spendtrace.currencies')))], 'enabled' => 'required|boolean', 'user_id' => 'missing', 'token_hash' => 'missing', ...$this->mappingRules($request)]);
        WalletConnection::create($data + ['user_id' => $request->user()->id]);

        return back()->with('notice', 'Connection saved. Create a token and configure your phone; enabling alone does not prove capture works.');
    }

    public function update(Request $request, WalletConnection $connection): RedirectResponse
    {
        $this->owned($request, $connection);
        $data = $request->validate(['name' => 'required|string|max:100', 'enabled' => 'required|boolean',
            'default_currency' => ['nullable', Rule::in(array_keys(config('spendtrace.currencies')))], 'account_id' => 'missing', 'user_id' => 'missing', 'token_hash' => 'missing', ...$this->mappingRules($request)]);
        $this->locked($connection, fn ($c) => $c->update($data));

        return back()->with('notice', 'Connection updated. Existing events keep their original account and currency provenance.');
    }

    public function token(Request $request, WalletConnection $connection): JsonResponse
    {
        $this->owned($request, $connection);
        $token = 'st_'.bin2hex(random_bytes(32));
        $this->locked($connection, fn ($c) => $c->update(['token_hash' => hash('sha256', $token)]));

        return response()->json(['token' => $token])->header('Cache-Control', 'private, no-store');
    }

    public function revoke(Request $request, WalletConnection $connection): RedirectResponse
    {
        $this->owned($request, $connection);
        $this->locked($connection, fn ($c) => $c->update(['token_hash' => null]));

        return back()->with('notice', 'Ingestion credential revoked. Saved purchases remain available.');
    }

    public function destroy(Request $request, WalletConnection $connection): RedirectResponse
    {
        $this->owned($request, $connection);
        $this->locked($connection, function ($c): void {
            $c->update(['enabled' => false, 'token_hash' => null]);
            $c->delete();
        });

        return back()->with('notice', 'Connection deleted. Historical observations and purchases are preserved.');
    }

    public function status(Request $request, WalletConnection $connection): JsonResponse
    {
        $this->owned($request, $connection);

        return response()->json(['connection' => $connection->toArray() + ['has_token' => $connection->token_hash !== null],
            'events' => $connection->events()->latest()->limit(30)->get(['event_id', 'status', 'is_test', 'created_at'])]);
    }

    private function mappingRules(Request $request): array
    {
        return ['card_mappings' => 'sometimes|array|list|max:100', 'card_mappings.*' => 'array:card_key,account_id',
            'card_mappings.*.card_key' => 'required|string|max:100|distinct:strict',
            'card_mappings.*.account_id' => ['required', 'integer', Rule::exists('accounts', 'id')->where('user_id', $request->user()->id)]];
    }

    private function owned(Request $request, WalletConnection $connection): void
    {
        abort_unless($connection->user_id === $request->user()->id, 404);
    }

    private function locked(WalletConnection $connection, \Closure $callback): void
    {
        DB::transaction(function () use ($connection, $callback): void {
            User::whereKey($connection->user_id)->lockForUpdate()->firstOrFail();
            $callback(WalletConnection::whereKey($connection->id)->where('user_id', $connection->user_id)->lockForUpdate()->firstOrFail());
        });
    }
}
