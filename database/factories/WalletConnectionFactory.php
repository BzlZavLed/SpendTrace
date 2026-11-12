<?php

namespace Database\Factories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletConnectionFactory extends Factory
{
    public function definition(): array
    {
        return ['account_id' => Account::factory(), 'user_id' => fn ($a) => Account::findOrFail($a['account_id'])->user_id,
            'name' => 'Test iPhone', 'enabled' => true, 'default_currency' => null, 'token_hash' => null];
    }
}
