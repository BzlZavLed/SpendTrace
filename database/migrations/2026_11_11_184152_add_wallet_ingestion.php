<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->unique(['user_id', 'id']);
        });
        Schema::table('transactions', function (Blueprint $table): void {
            $table->unsignedBigInteger('receipt_id')->nullable()->change();
            $table->foreignId('merged_into_id')->nullable()->constrained('transactions')->restrictOnDelete();
        });
        Schema::create('wallet_connections', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->foreign(['user_id', 'account_id'])->references(['user_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->string('name', 100);
            $table->boolean('enabled')->default(false);
            $table->string('default_currency', 3)->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestampTz('last_event_at')->nullable();
            $table->timestampTz('last_test_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('wallet_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_connection_id')->constrained()->restrictOnDelete();
            $table->uuid('event_id');
            $table->string('payload_hash', 64);
            $table->json('payload');
            $table->string('currency', 3)->nullable();
            $table->string('currency_source', 20);
            $table->boolean('is_test')->default(false);
            $table->foreignId('original_transaction_id')->nullable()->constrained('transactions')->restrictOnDelete();
            $table->foreignId('transaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('pending');
            $table->json('match_snapshot')->nullable();
            $table->json('dismissed_ids')->nullable();
            $table->timestamps();
            $table->unique(['wallet_connection_id', 'event_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wallet_events');
        Schema::dropIfExists('wallet_connections');
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('merged_into_id');
        });
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'id']);
        });
    }
};
