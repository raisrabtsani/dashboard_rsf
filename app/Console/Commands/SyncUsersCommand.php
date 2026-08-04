<?php

namespace App\Console\Commands;

use App\Services\UserCsvImportService;
use Illuminate\Console\Command;
use Throwable;

class SyncUsersCommand extends Command
{
    protected $signature = 'users:sync
                            {--file= : Path CSV alternatif (default database/seeders/data/user.csv)}';

    protected $description = 'Upsert akun dari user.csv tanpa truncate — password user lama tidak diubah';

    public function handle(UserCsvImportService $service): int
    {
        $path = $this->option('file') ?: $service->path();

        $this->info("Membaca {$path} ...");

        try {
            $hasil = $service->sync($path);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Baru', 'Diperbarui', 'Total'],
            [[$hasil['baru'], $hasil['diperbarui'], $hasil['baru'] + $hasil['diperbarui']]],
        );

        $this->info('Selesai. Password user yang sudah ada tidak diubah.');

        return self::SUCCESS;
    }
}
