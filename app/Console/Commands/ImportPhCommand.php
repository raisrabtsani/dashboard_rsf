<?php

namespace App\Console\Commands;

use App\Exceptions\ImportException;
use App\Services\PhCsvImportService;
use Illuminate\Console\Command;

class ImportPhCommand extends Command
{
    protected $signature = 'import:ph
                            {berkas : Path berkas CSV/Excel PH}
                            {--lewati : Lewati periode yang sudah ada (perilaku upload admin)}';

    protected $description = 'Import PH dari berkas. Default MENIMPA periode yang sudah ada';

    public function handle(PhCsvImportService $service): int
    {
        $path = $this->argument('berkas');

        if (! is_file($path)) {
            $this->error("Berkas tidak ditemukan: {$path}");

            return self::FAILURE;
        }

        // Beda sengaja dengan upload admin: CLI dipakai untuk perbaikan data,
        // jadi default-nya MENIMPA periode yang sudah ada.
        $timpa = ! $this->option('lewati');

        $this->info($timpa ? 'Mode: MENIMPA periode yang sudah ada.' : 'Mode: melewati periode yang sudah ada.');

        try {
            $hasil = $service->impor($path, basename($path), $timpa);
        } catch (ImportException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Periode', 'Baris', 'Baris berkas', 'Fallback cabang', 'Dilewati'],
            [[
                implode(', ', $hasil['periode']),
                number_format($hasil['baris'], 0, ',', '.'),
                number_format($hasil['sumber'], 0, ',', '.'),
                number_format($hasil['fallback'], 0, ',', '.'),
                $hasil['dilewati'] === [] ? '—' : implode(', ', $hasil['dilewati']),
            ]],
        );

        return self::SUCCESS;
    }
}
