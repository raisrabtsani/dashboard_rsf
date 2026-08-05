<?php

namespace Database\Factories;

use App\Models\Qris;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Qris>
 */
class QrisFactory extends Factory
{
    protected $model = Qris::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'kpi' => 'USER_QRIS',
            'tanggal' => now()->toDateString(),
            'actual' => fake()->numberBetween(1, 1_000),
        ];
    }
}
