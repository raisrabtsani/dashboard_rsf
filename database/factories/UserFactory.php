<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * Default-nya user level uker (paling sempit) supaya test yang lupa
     * menetapkan tipe tidak diam-diam mendapat akses penuh.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => null,
            'email_verified_at' => null,
            'password' => static::$password ??= Hash::make('password'),
            'role' => User::ROLE_USER,
            'tipe' => User::TIPE_UNIT,
            'cabang_id' => null,
            'uker_id' => null,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_ADMIN,
            'tipe' => User::TIPE_RO,
        ]);
    }

    public function ro(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_USER,
            'tipe' => User::TIPE_RO,
        ]);
    }

    public function bo(int $cabangId): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_USER,
            'tipe' => User::TIPE_BO,
            'cabang_id' => $cabangId,
            'uker_id' => $cabangId,
        ]);
    }

    public function uker(int $cabangId, int $ukerId, string $tipe = User::TIPE_UNIT): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => User::ROLE_USER,
            'tipe' => $tipe,
            'cabang_id' => $cabangId,
            'uker_id' => $ukerId,
        ]);
    }
}
