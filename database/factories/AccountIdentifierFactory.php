<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountIdentifier;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountIdentifierFactory extends Factory
{
    protected $model = AccountIdentifier::class;

    public function definition(): array
    {
        return ['account_id' => Account::factory(), 'kind' => 'physical', 'last_four' => '1234'];
    }
}
