<?php

namespace Database\Factories;

use App\Models\Receipt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReceiptFactory extends Factory
{
    protected $model = Receipt::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'provider' => 'fake', 'status' => 'queued', 'generation' => 1];
    }
}
