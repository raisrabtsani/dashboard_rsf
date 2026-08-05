<?php

namespace Database\Factories;

use App\Models\Edc;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Edc>
 */
class EdcFactory extends Factory
{
    protected $model = Edc::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cabang_id' => 159,
            'uker_id' => 159,
            'kpi' => 'TID',
            'tanggal' => now()->toDateString(),
            'actual' => fake()->numberBetween(1, 1_000),
        ];
    }
}
