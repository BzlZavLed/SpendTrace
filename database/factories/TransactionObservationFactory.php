<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionObservationFactory extends Factory
{
    protected $model = TransactionObservation::class;

    public function definition(): array
    {
        return ['transaction_id' => Transaction::factory(), 'source_type' => 'receipt', 'source_id' => fake()->uuid()];
    }
}
