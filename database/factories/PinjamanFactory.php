<?php

namespace Database\Factories;

use App\Models\Pinjaman;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Pinjaman>
 */
class PinjamanFactory extends Factory
{
    protected $model = Pinjaman::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'segmen' => 'Mikro',
            'segmentasi' => 'Ritel',
            'kualitas' => Pinjaman::KUALITAS_LANCAR,
            'tanggal' => now()->toDateString(),
            'baki_debet' => fake()->numberBetween(1_000_000_000, 50_000_000_000),
        ];
    }
}
