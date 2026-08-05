<?php

namespace Database\Factories;

use App\Models\SimpananWholesale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SimpananWholesale>
 */
class SimpananWholesaleFactory extends Factory
{
    protected $model = SimpananWholesale::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'produk' => 'Tabungan',
            'segmentasi' => 'Wholesale',
            'tanggal' => now()->toDateString(),
            'saldo' => fake()->numberBetween(1_000_000_000, 50_000_000_000),
        ];
    }
}
