<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\WalletAlias;
use App\Models\WalletConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletAliasFactory extends Factory
{
    public function definition(): array
    {
        return ['wallet_connection_id' => WalletConnection::factory(),
            'user_id' => fn ($a) => WalletConnection::findOrFail($a['wallet_connection_id'])->user_id,
            'account_id' => fn ($a) => Account::factory()->create(['user_id' => $a['user_id']])->id,
            'identifier' => ['label' => 'Everyday Card', 'last_four' => '1234'],
            'fingerprint' => fn ($a) => WalletAlias::fingerprint($a['identifier'])];
    }
}
