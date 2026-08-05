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
            'cabang_id' => 159,
            'uker_id' => 159,
            'segmen' => 'Micro',
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            'target' => fake()->numberBetween(100_000_000, 5_000_000_000),
        ];
    }
}
