<?php

namespace Database\Factories;

use App\Models\Transaction;
use App\Models\TransactionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

class TransactionItemFactory extends Factory
{
    protected $model = TransactionItem::class;

    public function definition(): array
    {
        return ['transaction_id' => Transaction::factory(), 'description' => 'Test item', 'quantity' => '1.000', 'amount_minor' => 100];
    }
}
