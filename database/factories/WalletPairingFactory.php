<?php

namespace Database\Factories;

use App\Models\WalletConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletPairingFactory extends Factory
{
    public function definition(): array
    {
        return ['wallet_connection_id' => WalletConnection::factory(), 'device_name' => 'Test iPhone', 'code_hash' => hash('sha256', bin2hex(random_bytes(16))), 'expires_at' => now()->addMinutes(10)];
    }
}
