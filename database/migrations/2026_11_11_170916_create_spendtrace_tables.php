<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('institution', 100);
            $t->string('nickname', 100);
            $t->string('card_network', 30)->nullable();
            $t->timestamps();
        });
        Schema::create('account_identifiers', function (Blueprint $t) {
            $t->id();
            $t->foreignId('account_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);
            $t->string('last_four', 4);
            $t->string('device_label', 100)->nullable();
            $t->timestamps();
        });
        Schema::create('receipts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('status', 20)->default('queued');
            $t->unsignedInteger('generation')->default(1);
            $t->string('provider', 30);
            $t->string('extraction_version', 30)->default('receipt-v1');
            $t->string('error')->nullable();
            $t->timestamps();
        });
        Schema::create('receipt_images', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('path');
            $t->string('sha256', 64);
            $t->string('mime', 30);
            $t->unsignedInteger('bytes');
            $t->timestamps();
            $t->unique(['user_id', 'sha256']);
        });
        Schema::create('extraction_runs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('receipt_id')->constrained()->cascadeOnDelete();
            $t->unsignedInteger('generation');
            $t->string('provider');
            $t->string('version');
            $t->json('output')->nullable();
            $t->string('error')->nullable();
            $t->timestamps();
            $t->unique(['receipt_id', 'generation']);
        });
        Schema::create('transactions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('receipt_id')->unique()->constrained()->cascadeOnDelete();
            $t->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $t->string('merchant', 200)->nullable();
            $t->date('purchase_date')->nullable();
            $t->time('purchase_time')->nullable();
            $t->string('purchase_timezone')->nullable();
            $t->bigInteger('total_minor')->nullable();
            $t->string('currency', 3)->nullable();
            $t->string('category', 50)->nullable();
            $t->string('review_status', 20)->default('needs_review');
            $t->string('reconciliation_status', 20)->default('unreconciled');
            $t->json('review_reasons')->nullable();
            $t->json('duplicate_ids')->nullable();
            $t->json('corrected_fields')->nullable();
            $t->unsignedInteger('revision')->default(0);
            $t->boolean('excluded')->default(false);
            $t->timestamps();
            $t->index(['user_id', 'purchase_date']);
        });
        Schema::create('transaction_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $t->string('description', 300)->nullable();
            $t->decimal('quantity', 12, 3)->nullable();
            $t->bigInteger('amount_minor')->nullable();
            $t->string('category', 50)->nullable();
            $t->timestamps();
        });
        Schema::create('transaction_observations', function (Blueprint $t) {
            $t->id();
            $t->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $t->string('source_type', 30);
            $t->string('source_id', 100);
            $t->json('payload')->nullable();
            $t->timestampTz('observed_at')->nullable();
            $t->timestamps();
            $t->unique(['transaction_id', 'source_type', 'source_id'], 'observation_source_unique');
        });
    }

    public function down(): void
    {
        foreach (['transaction_observations', 'transaction_items', 'transactions', 'extraction_runs', 'receipt_images', 'receipts', 'account_identifiers', 'accounts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
