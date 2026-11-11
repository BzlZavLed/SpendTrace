<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\TransactionController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthController::class, 'show'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:6,1');
    Route::get('/register', [AuthController::class, 'show'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1');
});
Route::middleware('auth')->group(function (): void {
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
