<?php

namespace Database\Factories;

use App\Models\Simpanan;
use App\Models\SimpananHourly;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SimpananHourly>
 */
class SimpananHourlyFactory extends Factory
{
    protected $model = SimpananHourly::class;

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
            'tanggal' => now()->endOfMonth()->toDateString(),
            'jam' => 10,
            'saldo' => fake()->numberBetween(1_000_000_000, 50_000_000_000),
        ];
    }
}
