<?php

namespace App\Providers;

use App\Services\FakeReceiptExtractor;
use App\Services\OpenAiReceiptExtractor;
use App\Services\ReceiptExtractor;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ReceiptExtractor::class, fn () => match (config('spendtrace.provider')) {
            'fake' => new FakeReceiptExtractor, 'openai' => new OpenAiReceiptExtractor,
            default => throw new \RuntimeException('Unknown receipt provider.'),
        });
    }

    public function boot(): void
    {
        RateLimiter::for('wallet-pair-exchange', fn (Request $request) => Limit::perMinute(10)->by('wallet-pair:'.$request->ip()));
    }
}
