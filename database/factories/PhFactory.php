<?php

namespace Database\Factories;

use App\Models\Ph;
use App\Support\Segmen;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Ph>
 */
class PhFactory extends Factory
{
    protected $model = Ph::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'segmen' => Segmen::MICRO,
            'periode' => now()->endOfMonth()->toDateString(),
            'saldo' => fake()->numberBetween(100_000_000, 5_000_000_000),
        ];
    }
}
