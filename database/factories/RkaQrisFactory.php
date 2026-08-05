<?php

namespace Database\Factories;

use App\Models\RkaQris;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RkaQris>
 */
class RkaQrisFactory extends Factory
{
    protected $model = RkaQris::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'kpi' => 'USER_QRIS',
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            'target' => fake()->numberBetween(1, 1_000),
        ];
    }
}
