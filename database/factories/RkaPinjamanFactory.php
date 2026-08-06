<?php

namespace Database\Factories;

use App\Models\Pinjaman;
use App\Models\RkaPinjaman;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RkaPinjaman>
 */
class RkaPinjamanFactory extends Factory
{
    protected $model = RkaPinjaman::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 12,
            'uker_id' => 12,
            'segmen' => 'Micro',
            'segmentasi' => 'Ritel',
            'kualitas' => Pinjaman::KUALITAS_LANCAR,
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            'target' => fake()->numberBetween(1_000_000_000, 50_000_000_000),
        ];
    }
}
