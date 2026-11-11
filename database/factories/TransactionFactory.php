<?php

namespace Database\Factories;

use App\Models\Receipt;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return ['receipt_id' => Receipt::factory(), 'user_id' => fn (array $a) => Receipt::findOrFail($a['receipt_id'])->user_id, 'revision' => 0, 'corrected_fields' => [], 'review_reasons' => []];
    }
}
