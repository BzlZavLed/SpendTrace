<?php

use App\Http\Controllers\MobileReceiptController;
use App\Http\Controllers\WalletEventController;
use App\Http\Controllers\WalletPairingController;
use App\Http\Middleware\AuthenticateWallet;
use App\Http\Middleware\RequireLegacyWalletServer;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireLegacyWalletServer::class)->group(function (): void {
    Route::post('/v1/integrations/wallet/events', WalletEventController::class)
        ->middleware(['throttle:120,1', AuthenticateWallet::class])->name('wallet.ingest');

    Route::post('/v1/integrations/wallet/pair', [WalletPairingController::class, 'exchange'])->middleware('throttle:wallet-pair-exchange')->name('wallet.pair');

});

Route::post('/v1/mobile/receipt-session', [MobileReceiptController::class, 'session'])->middleware('throttle:5,1');
Route::post('/v1/mobile/receipt-extraction', [MobileReceiptController::class, 'extract'])->middleware('throttle:10,1');
