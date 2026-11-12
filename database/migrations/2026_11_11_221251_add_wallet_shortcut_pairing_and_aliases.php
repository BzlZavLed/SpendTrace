<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wallet_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_connection_id')->constrained()->restrictOnDelete();
            $table->string('name', 100);
            $table->string('registration_method', 20)->default('pairing_code');
            $table->string('token_hash', 64)->unique();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_test_at')->nullable();
            $table->timestampTz('last_event_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_pairings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('wallet_connection_id')->constrained()->restrictOnDelete();
            $table->string('device_name', 100);
            $table->string('code_hash', 64)->unique();
            $table->timestampTz('expires_at');
            $table->timestampTz('redeemed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_aliases', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('wallet_connection_id');
            $table->unsignedBigInteger('account_id');
            $table->foreign(['user_id', 'wallet_connection_id'])->references(['user_id', 'id'])->on('wallet_connections')->restrictOnDelete();
            $table->foreign(['user_id', 'account_id'])->references(['user_id', 'id'])->on('accounts')->restrictOnDelete();
            $table->string('fingerprint', 64);
            $table->json('identifier');
            $table->timestamps();
            $table->unique(['wallet_connection_id', 'fingerprint', 'account_id'], 'wallet_alias_mapping_unique');
        });
        Schema::table('wallet_events', function (Blueprint $table): void {
            $table->foreignId('wallet_device_id')->nullable()->constrained()->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_events', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('wallet_device_id');
        });
        Schema::dropIfExists('wallet_aliases');
        Schema::dropIfExists('wallet_pairings');
        Schema::dropIfExists('wallet_devices');
    }
};
