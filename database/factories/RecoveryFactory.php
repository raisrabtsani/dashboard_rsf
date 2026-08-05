<?php

namespace Database\Factories;

use App\Models\Recovery;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Recovery>
 */
class RecoveryFactory extends Factory
{
    protected $model = Recovery::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            // Nilai mentah — segmen dinormalkan saat baca, bukan di sini.
            'segmen' => Recovery::SEGMEN_MICRO,
            'tanggal' => now()->toDateString(),
            // Rupiah penuh.
            'actual' => fake()->numberBetween(1_000_000, 500_000_000),
        ];
    }
}
