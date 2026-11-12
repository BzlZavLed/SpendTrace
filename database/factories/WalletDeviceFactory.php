<?php

namespace Database\Factories;

use App\Models\WalletConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletDeviceFactory extends Factory
{
    public function definition(): array
    {
        return ['wallet_connection_id' => WalletConnection::factory(), 'name' => 'Test iPhone', 'token_hash' => hash('sha256', bin2hex(random_bytes(32)))];
    }
}
