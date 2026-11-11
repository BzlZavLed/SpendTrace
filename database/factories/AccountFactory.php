<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return ['user_id' => User::factory(), 'institution' => 'Capital One', 'nickname' => 'Everyday', 'card_network' => null];
    }
}
