<?php

namespace Database\Factories;

use App\Models\Simpanan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Simpanan>
 */
class SimpananFactory extends Factory
{
    protected $model = Simpanan::class;

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
            'tanggal' => now()->toDateString(),
            // Rupiah penuh.
            'saldo' => fake()->numberBetween(1_000_000_000, 50_000_000_000),
        ];
    }
}
