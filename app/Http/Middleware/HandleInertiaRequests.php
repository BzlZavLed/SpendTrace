<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'auth' => ['user' => $request->user()?->only('id', 'name', 'email')],
            'provider' => config('spendtrace.provider'),
            'notice' => fn () => $request->session()->get('notice'),
            'categories' => config('spendtrace.categories'), 'currencies' => config('spendtrace.currencies'),
        ];
    }
}
