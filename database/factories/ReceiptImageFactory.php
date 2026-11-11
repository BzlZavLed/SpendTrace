<?php

namespace Database\Factories;

use App\Models\Receipt;
use App\Models\ReceiptImage;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReceiptImageFactory extends Factory
{
    protected $model = ReceiptImage::class;

    public function definition(): array
    {
        return ['receipt_id' => Receipt::factory(), 'user_id' => fn (array $a) => Receipt::findOrFail($a['receipt_id'])->user_id, 'path' => 'test/receipt.jpg', 'sha256' => hash('sha256', fake()->uuid()), 'mime' => 'image/jpeg', 'bytes' => 100];
    }
}
