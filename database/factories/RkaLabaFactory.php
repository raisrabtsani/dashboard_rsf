<?php

namespace Database\Factories;

use App\Models\RkaLaba;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RkaLaba>
 */
class RkaLabaFactory extends Factory
{
    protected $model = RkaLaba::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Cabang 12 (KC Bogor) — ada di master Jakarta 2. `segmen` menyimpan
            // label uko.
            'cabang_id' => 12,
            'uker_id' => 12,
            'segmen' => 'Micro',
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            'target' => fake()->numberBetween(100_000_000, 5_000_000_000),
        ];
    }
}
