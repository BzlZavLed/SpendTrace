<?php

namespace Database\Factories;

use App\Models\WalletConnection;
use Illuminate\Database\Eloquent\Factories\Factory;

class WalletEventFactory extends Factory
{
    public function definition(): array
    {
        return ['wallet_connection_id' => WalletConnection::factory(), 'user_id' => fn ($a) => WalletConnection::findOrFail($a['wallet_connection_id'])->user_id, 'event_id' => fake()->uuid(),
            'payload_hash' => hash('sha256', 'test'), 'payload' => [], 'currency_source' => 'unknown', 'is_test' => true, 'status' => 'test_received'];
    }
}
