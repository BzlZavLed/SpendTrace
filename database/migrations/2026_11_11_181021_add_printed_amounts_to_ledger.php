<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('total_decimal', 20)->nullable();
        });
        Schema::table('transaction_items', function (Blueprint $table): void {
            $table->string('amount_decimal', 20)->nullable();
        });
        // Only known currency precision makes legacy minor units safe to convert.
        $digits = ['USD' => 2, 'CAD' => 2, 'EUR' => 2, 'GBP' => 2, 'JPY' => 0, 'KWD' => 3];
        $format = static function (int $minor, int $places): string {
            $factor = 10 ** $places;

            return ($minor < 0 ? '-' : '').intdiv(abs($minor), $factor).($places ? '.'.str_pad((string) (abs($minor) % $factor), $places, '0', STR_PAD_LEFT) : '');
        };
        DB::table('transactions')->whereIn('currency', array_keys($digits))->orderBy('id')->chunkById(100, function ($transactions) use ($digits, $format): void {
            foreach ($transactions as $transaction) {
                $places = $digits[$transaction->currency];
                if ($transaction->total_minor !== null) {
                    DB::table('transactions')->where('id', $transaction->id)->update(['total_decimal' => $format((int) $transaction->total_minor, $places)]);
                }
                foreach (DB::table('transaction_items')->where('transaction_id', $transaction->id)->whereNotNull('amount_minor')->get() as $item) {
                    DB::table('transaction_items')->where('id', $item->id)->update(['amount_decimal' => $format((int) $item->amount_minor, $places)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('transaction_items', fn (Blueprint $table) => $table->dropColumn('amount_decimal'));
        Schema::table('transactions', fn (Blueprint $table) => $table->dropColumn('total_decimal'));
    }
};
