<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('wallet_events', function (Blueprint $table): void {
            $table->dropForeign(['wallet_connection_id']);
        });
        Schema::table('wallet_connections', function (Blueprint $table): void {
            $table->unsignedBigInteger('account_id')->nullable()->change();
            $table->json('card_mappings')->nullable();
            $table->unique(['user_id', 'id']);
        });
        Schema::table('wallet_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('account_id')->nullable();
        });
        DB::table('wallet_events')->orderBy('id')->chunkById(100, function ($events): void {
            foreach ($events as $event) {
                $connection = DB::table('wallet_connections')->where('id', $event->wallet_connection_id)->first();
                DB::table('wallet_events')->where('id', $event->id)->update(['user_id' => $connection->user_id, 'account_id' => $event->is_test ? null : $connection->account_id]);
            }
        });
        Schema::table('wallet_events', function (Blueprint $table): void {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
            $table->foreign(['user_id', 'wallet_connection_id'])->references(['user_id', 'id'])->on('wallet_connections')->restrictOnDelete();
            $table->foreign(['user_id', 'account_id'])->references(['user_id', 'id'])->on('accounts')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('wallet_events', function (Blueprint $table): void {
            $table->dropForeign(['user_id', 'wallet_connection_id']);
            $table->dropForeign(['user_id', 'account_id']);
            $table->dropColumn(['user_id', 'account_id']);
        });
        Schema::table('wallet_connections', function (Blueprint $table): void {
            $table->dropUnique(['user_id', 'id']);
            $table->dropColumn('card_mappings');
        });
        Schema::table('wallet_events', function (Blueprint $table): void {
            $table->foreign('wallet_connection_id')->references('id')->on('wallet_connections')->restrictOnDelete();
        });
    }
};
