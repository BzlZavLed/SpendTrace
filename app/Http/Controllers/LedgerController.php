<?php

namespace App\Http\Controllers;

use App\Models\Transaction;
use App\Services\LedgerService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function __invoke(Request $request, LedgerService $ledger): Response
    {
        $query = Transaction::whereNull('merged_into_id')->where('user_id', $request->user()->id)->with(['receipt', 'account', 'items']);

        return Inertia::render('Ledger', [
            'summary' => $ledger->summary($request->user()),
            'transactions' => (clone $query)->latest('id')->paginate(20)->through(fn ($t) => $ledger->present($t)),
            'needsReview' => (clone $query)->where('review_status', 'needs_review')->where('excluded', false)->latest('id')->limit(6)->get()->map(fn ($t) => $ledger->present($t)),
        ]);
    }
}
