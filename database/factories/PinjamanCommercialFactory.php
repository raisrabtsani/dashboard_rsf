<?php

namespace Database\Factories;

use App\Models\Pinjaman;
use App\Models\PinjamanCommercial;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PinjamanCommercial>
 */
class PinjamanCommercialFactory extends Factory
{
    protected $model = PinjamanCommercial::class;

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
            'tanggal' => now()->toDateString(),
            'baki_debet' => fake()->numberBetween(1_000_000_000, 50_000_000_000),
        ];
    }
}
