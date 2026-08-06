<?php

namespace Database\Factories;

use App\Models\Laba;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Laba>
 */
class LabaFactory extends Factory
{
    protected $model = Laba::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            // Cabang 12 (KC Bogor) — ada di master Jakarta 2. `segmen` menyimpan
            // label uko; "Micro" adalah salah satu jenis kantor operasional.
            'cabang_id' => 12,
            'uker_id' => 12,
            'segmen' => 'Micro',
            'tahun' => (int) now()->year,
            'bulan' => (int) now()->month,
            // Rupiah penuh, kumulatif YTD.
            'laba' => fake()->numberBetween(100_000_000, 5_000_000_000),
        ];
    }
}
