<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Accounts', ['accounts' => Account::where('user_id', $request->user()->id)->with('identifiers')->orderBy('nickname')->get(), 'institutions' => config('spendtrace.institutions')]);
    }

    public function store(Request $request): RedirectResponse
    {
        return $this->save($request);
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        abort_unless($account->user_id === $request->user()->id, 404);

        return $this->save($request, $account);
    }

    private function save(Request $request, ?Account $account = null): RedirectResponse
    {
        $data = $request->validate(['institution' => ['required', Rule::in(config('spendtrace.institutions'))], 'nickname' => 'required|string|max:100', 'card_network' => 'nullable|string|max:30',
            'identifiers' => 'present|array|max:20', 'identifiers.*.kind' => ['required', Rule::in(['physical', 'wallet'])], 'identifiers.*.last_four' => ['required', 'regex:/^\d{4}$/D'], 'identifiers.*.device_label' => 'nullable|string|max:100']);
        DB::transaction(function () use ($request, $account, $data): void {
            $values = array_intersect_key($data, array_flip(['institution', 'nickname', 'card_network']));
            if ($account) {
                $account->update($values);
            } else {
                $account = Account::create($values + ['user_id' => $request->user()->id]);
            }
            $account->identifiers()->delete();
            $account->identifiers()->createMany($data['identifiers']);
        });

        return back()->with('notice', 'Account saved. Only last four digits are stored.');
    }
}
