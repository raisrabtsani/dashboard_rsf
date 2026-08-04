<?php

namespace Database\Seeders;

use App\Services\UserCsvImportService;
use Illuminate\Database\Seeder;

/**
 * Seed massal akun dari database/seeders/data/user.csv.
 *
 * PERINGATAN: seeder ini melakukan TRUNCATE tabel users — semua akun beserta
 * password yang sudah diganti user akan hilang. Hanya untuk inisialisasi awal
 * atau lingkungan dev/test.
 *
 * Untuk pemutakhiran di produksi pakai `php artisan users:sync`, yang meng-upsert
 * tanpa truncate dan tidak menyentuh password user lama.
 *
 * Wajib dijalankan SETELAH MasterSeeder: users.cabang_id & users.uker_id punya
 * foreign key ke tabel cabang & uker.
 */
class UserSeeder extends Seeder
{
    public function __construct(private readonly UserCsvImportService $service) {}

    public function run(): void
    {
        $jumlah = $this->service->seedMassal();

        $this->command?->info("Akun: {$jumlah} user diseed dari ".UserCsvImportService::FILE.' (tabel users di-truncate).');
    }
}
