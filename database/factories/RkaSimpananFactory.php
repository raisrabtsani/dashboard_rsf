<?php

namespace Database\Factories;

use App\Models\RkaSimpanan;
use App\Models\Simpanan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RkaSimpanan>
 */
class RkaSimpananFactory extends Factory
{
    protected $model = RkaSimpanan::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'produk' => Simpanan::PRODUK_TABUNGAN,
            'segmentasi' => 'Ritel',
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            'target' => fake()->numberBetween(1_000_000_000, 50_000_000_000),
        ];
    }
}
