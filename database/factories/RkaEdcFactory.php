<?php

namespace Database\Factories;

use App\Models\RkaEdc;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RkaEdc>
 */
class RkaEdcFactory extends Factory
{
    protected $model = RkaEdc::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'kpi' => 'TID',
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            'target' => fake()->numberBetween(1, 1_000),
        ];
    }
}
