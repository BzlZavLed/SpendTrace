<?php

namespace Database\Factories;

use App\Models\ExtractionRun;
use App\Models\Receipt;
use Illuminate\Database\Eloquent\Factories\Factory;

class ExtractionRunFactory extends Factory
{
    protected $model = ExtractionRun::class;

    public function definition(): array
    {
        return ['receipt_id' => Receipt::factory(), 'generation' => 1, 'provider' => 'fake', 'version' => 'receipt-v1'];
    }
}
