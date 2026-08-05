<?php

namespace Database\Factories;

use App\Models\Pinjaman;
use App\Models\RkaPinjamanCommercial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RkaPinjamanCommercial>
 */
class RkaPinjamanCommercialFactory extends Factory
{
    protected $model = RkaPinjamanCommercial::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'segmen' => 'Commercial',
            'segmentasi' => 'Commercial',
            'kualitas' => Pinjaman::KUALITAS_LANCAR,
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            'target' => fake()->numberBetween(1_000_000_000, 50_000_000_000),
        ];
    }
}
