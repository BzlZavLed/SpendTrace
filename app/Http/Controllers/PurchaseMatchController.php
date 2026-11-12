<?php

namespace App\Http\Controllers;

use App\Jobs\MatchWalletEvent;
use App\Models\Transaction;
use App\Models\WalletEvent;
use App\Services\LedgerService;
use App\Services\PurchaseMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseMatchController extends Controller
{
    public function index(Request $request, PurchaseMatcher $matcher, LedgerService $ledger): Response
    {
        $events = WalletEvent::where('is_test', false)->whereHas('connection', fn ($q) => $q->where('user_id', $request->user()->id))->latest()->paginate(20);

        return Inertia::render('WalletMatches', ['events' => $events->through(function ($event) use ($matcher, $ledger, $request): array {
            $transaction = Transaction::whereKey($event->transaction_id)->where('user_id', $request->user()->id)->with(['account', 'receipt', 'items'])->first();

            return ['id' => $event->id, 'status' => $event->status, 'payload' => $event->payload, 'currency' => $event->currency, 'currency_source' => $event->currency_source,
                'connection_name' => $event->connection->name, 'account_id' => $event->account_id,
                'transaction' => $transaction ? $ledger->present($transaction) : null,
                'candidates' => $event->status === 'matched' ? [] : $matcher->candidates($event, $event->status === 'separate')->values()->map(fn ($t) => $ledger->present($t))];
        })]);
    }

    public function attach(Request $request, WalletEvent $event, PurchaseMatcher $matcher): RedirectResponse
    {
        $this->owned($request, $event);
        $data = $request->validate(['transaction_id' => 'required|integer', 'confirmed' => 'accepted']);
        $matcher->manual($request->user()->id, $event->id, $data['transaction_id']);

        return back()->with('notice', 'Receipt and Wallet observation attached. One purchase is counted; bank status is unchanged.');
    }

    public function separate(Request $request, WalletEvent $event, PurchaseMatcher $matcher): RedirectResponse
    {
        $this->owned($request, $event);
        $matcher->separate($request->user()->id, $event->id);

        return back()->with('notice', 'Kept as separate purchases. Source records and corrections are preserved.');
    }

    public function retry(Request $request, WalletEvent $event): RedirectResponse
    {
        $this->owned($request, $event);
        MatchWalletEvent::dispatch($event->id);

        return back()->with('notice', 'Matching queued.');
    }

    private function owned(Request $request, WalletEvent $event): void
    {
        abort_unless($event->connection?->user_id === $request->user()->id && ! $event->is_test, 404);
    }
}
