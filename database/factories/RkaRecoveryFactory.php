<?php

namespace Database\Factories;

use App\Models\Recovery;
use App\Models\RkaRecovery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RkaRecovery>
 */
class RkaRecoveryFactory extends Factory
{
    protected $model = RkaRecovery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'segmen' => Recovery::SEGMEN_MICRO,
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            'target' => fake()->numberBetween(1_000_000, 500_000_000),
        ];
    }
}
