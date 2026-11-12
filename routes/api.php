<?php

use App\Http\Controllers\WalletEventController;
use App\Http\Controllers\WalletPairingController;
use App\Http\Middleware\AuthenticateWallet;
use Illuminate\Support\Facades\Route;

Route::post('/v1/integrations/wallet/events', WalletEventController::class)
    ->middleware(['throttle:120,1', AuthenticateWallet::class])->name('wallet.ingest');

Route::post('/v1/integrations/wallet/pair', [WalletPairingController::class, 'exchange'])->middleware('throttle:wallet-pair-exchange')->name('wallet.pair');
