<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\PurchaseMatchController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\WalletAliasController;
use App\Http\Controllers\WalletConnectionController;
use App\Http\Controllers\WalletPairingController;
use App\Http\Middleware\RequireLegacyWalletServer;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::get('/register', [AuthController::class, 'show'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function (): void {
    Route::middleware(RequireLegacyWalletServer::class)->group(function (): void {
        Route::post('/integrations/wallet/{connection}/connect', [WalletPairingController::class, 'connect'])->middleware('throttle:10,1')->name('wallet.connect');
        Route::post('/integrations/wallet/{connection}/pairings', [WalletPairingController::class, 'store'])->middleware('throttle:10,1');
        Route::post('/integrations/wallet/{connection}/device-config', [WalletPairingController::class, 'importConfiguration'])->middleware('throttle:10,1');
        Route::delete('/integrations/wallet/{connection}/devices/{device}', [WalletPairingController::class, 'revoke']);
        Route::post('/wallet-aliases/{event}/resolve', [WalletAliasController::class, 'resolve']);
        Route::post('/integrations/wallet/{connection}/aliases', [WalletAliasController::class, 'store']);
        Route::delete('/integrations/wallet/{connection}/aliases/{alias}', [WalletAliasController::class, 'destroy']);

        Route::get('/integrations/wallet', [WalletConnectionController::class, 'index'])->name('wallet.index');
        Route::post('/integrations/wallet', [WalletConnectionController::class, 'store']);
        Route::patch('/integrations/wallet/{connection}', [WalletConnectionController::class, 'update']);
        Route::delete('/integrations/wallet/{connection}', [WalletConnectionController::class, 'destroy']);
        Route::post('/integrations/wallet/{connection}/token', [WalletConnectionController::class, 'token'])->middleware('throttle:10,1');
        Route::delete('/integrations/wallet/{connection}/token', [WalletConnectionController::class, 'revoke']);
        Route::get('/integrations/wallet/{connection}/status', [WalletConnectionController::class, 'status']);
        Route::get('/wallet-matches', [PurchaseMatchController::class, 'index'])->name('wallet.matches');
        Route::post('/wallet-matches/{event}/attach', [PurchaseMatchController::class, 'attach']);
        Route::post('/wallet-matches/{event}/separate', [PurchaseMatchController::class, 'separate']);
        Route::post('/wallet-matches/{event}/retry', [PurchaseMatchController::class, 'retry'])->middleware('throttle:10,1');

    });

    Route::get('/', LedgerController::class)->name('ledger');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');
    Route::get('/scan', [ReceiptController::class, 'create'])->name('receipts.create');
    Route::post('/receipts', [ReceiptController::class, 'store'])->middleware('throttle:20,1')->name('receipts.store');
    Route::post('/receipts/{receipt}/retry', [ReceiptController::class, 'retry'])->middleware('throttle:5,1')->name('receipts.retry');
    Route::get('/receipt-images/{image}', [ReceiptController::class, 'image'])->name('receipts.image');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::patch('/transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
});
