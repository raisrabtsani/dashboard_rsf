<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            MasterSeeder::class,
        ]);

        // Catatan: pembuatan "Test User" bawaan Breeze sengaja dihapus supaya
        // `db:seed` tetap idempoten (email unik akan bentrok saat dijalankan
        // ulang). Akun sungguhan diseed lewat UserSeeder — lihat PRD F14.4.
    }
}
