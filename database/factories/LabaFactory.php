<?php

namespace Database\Factories;

use App\Models\Laba;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Laba>
 */
class LabaFactory extends Factory
{
    protected $model = Laba::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'segmen' => 'Micro',
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            // Rupiah penuh, kumulatif YTD.
            'laba' => fake()->numberBetween(100_000_000, 5_000_000_000),
        ];
    }
}
