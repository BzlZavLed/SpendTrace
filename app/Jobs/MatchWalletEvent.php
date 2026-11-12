<?php

namespace App\Jobs;

use App\Models\WalletEvent;
use App\Services\PurchaseMatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class MatchWalletEvent implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 30;

    public function __construct(public int $eventId) {}

    public function handle(PurchaseMatcher $matcher): void
    {
        $matcher->process($this->eventId);
    }

    public function failed(?Throwable $exception): void
    {
        WalletEvent::whereKey($this->eventId)->whereNotIn('status', ['matched', 'separate'])->update(['status' => 'failed']);
        Log::warning('Wallet matching failed', ['exception_class' => $exception ? $exception::class : null]);
    }
}
