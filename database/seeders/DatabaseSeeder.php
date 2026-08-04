<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Urutannya mengikat: users.cabang_id & users.uker_id punya foreign key ke
     * tabel cabang & uker, jadi MasterSeeder wajib lebih dulu.
     *
     * Catatan: UserSeeder melakukan TRUNCATE tabel users. Untuk pemutakhiran
     * akun di produksi pakai `php artisan users:sync`, bukan `db:seed`.
     */
    public function run(): void
    {
        $this->call([
            MasterSeeder::class,
            UserSeeder::class,
        ]);
    }
}
