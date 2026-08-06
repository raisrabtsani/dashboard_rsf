<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Services\Concerns\MelaporkanImport;
use App\Models\Uker;
use App\Support\Bulan;
use App\Support\PetaKolom;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Basis importer target RKA merchant (EDC / QRIS) — per (kpi, tahun, bulan).
 *
 * Nama KPI dinormalkan ke kode kanonik (sama seperti importer aktual). Target
 * kosong dilewati; datanya di-UPSERT karena boleh direvisi sepanjang tahun.
 */
abstract class MerchantRkaImportService
{
    use MelaporkanImport;

    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'kpi' => ['indikator', 'metrik', 'nama kpi', 'parameter'],
        'tahun' => ['thn', 'year'],
        'bulan' => ['bln', 'month', 'periode'],
        'target' => ['rka', 'nilai', 'nominal'],
    ];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'kpi', 'tahun', 'bulan', 'target'];

    abstract protected function modelClass(): string;

    abstract protected function serviceClass(): string;

    /**
     * @return array{tahun: list<int>, baris: int, dilewati: int, total_target: float}
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        ['baris' => $baris, 'dilewati' => $dilewati] = $this->baca($path, $namaAsli ?? basename($path));

        $model = $this->modelClass();

        DB::transaction(function () use ($baris, $model) {
            $baris->chunk(1000)->each(fn (Collection $potongan) => $model::query()->upsert(
                $potongan->values()->all(),
                ['uker_id', 'kpi', 'tahun', 'bulan'],
                ['cabang_id', 'target', 'updated_at'],
            ));
        });

        return [
            'tahun' => $baris->pluck('tahun')->unique()->sort()->values()->all(),
            'baris' => $baris->count(),
            'dilewati' => $dilewati,
            'total_target' => (float) $baris->sum(fn (array $b) => $b['target']),
            'laporan' => $this->laporanImport(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ringkasan(): array
    {
        $model = $this->modelClass();

        return $model::query()
            ->groupBy('tahun', 'kpi')
            ->orderByDesc('tahun')
            ->orderBy('kpi')
            ->selectRaw('tahun, kpi, COUNT(*) as jumlah_baris, SUM(target) as total_target')
            ->get()
            ->map(fn ($r) => [
                'tahun' => (int) $r->tahun,
                // Halaman admin memakai key `produk` sebagai label kelompok generik.
                'produk' => $r->kpi,
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total_target' => (float) $r->total_target,
            ])
            ->all();
    }

    public function hapusTahun(int $tahun): int
    {
        $model = $this->modelClass();

        return $model::query()->where('tahun', $tahun)->delete();
    }

    /**
     * @return array{baris: Collection<int, array<string, mixed>>, dilewati: int}
     */
    private function baca(string $path, string $namaBerkas): array
    {
        $mentah = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $baris = PetaKolom::petakan($mentah, self::ALIAS, self::KOLOM, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $service = $this->serviceClass();
        $now = Carbon::now();
        $dilewati = 0;

        $hasil = $this->petakanBarisAman($baris, function (array $r, int $i) use ($ukerValid, $service, $now, &$dilewati) {
            $nomor = $i + 2;

            $ukerId = (int) trim((string) $r['id_uker']);
            $tahun = (int) trim((string) $r['tahun']);

            if (! $ukerValid->has($ukerId)) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$ukerId} tidak ada di master uker.");
            }

            $kode = $service::kanonikKpi((string) $r['kpi']);

            if ($kode === null) {
                throw ImportException::berkas(sprintf(
                    "Baris %d: KPI '%s' tidak dikenal. KPI yang valid: %s.",
                    $nomor,
                    trim((string) $r['kpi']),
                    implode(', ', array_column($service::KPI, 'label')),
                ));
            }

            if ($tahun < 2000 || $tahun > 2100) {
                throw ImportException::berkas("Baris {$nomor}: tahun '{$tahun}' tidak masuk akal.");
            }

            if (trim((string) $r['target']) === '') {
                $dilewati++;

                return null;
            }

            return [
                'cabang_id' => $ukerValid[$ukerId],
                'uker_id' => $ukerId,
                'kpi' => $kode,
                'tahun' => $tahun,
                'bulan' => Bulan::uraiAtauGagal((string) $r['bulan'], $nomor),
                'target' => $this->angka($r['target'], $nomor),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });


        return ['baris' => $hasil, 'dilewati' => $dilewati];
    }

    private function angka(mixed $nilai, int $nomor): float
    {
        $bersih = str_replace([' ', ',', "\u{00A0}"], '', trim((string) $nilai));

        if ($bersih === '' || ! is_numeric($bersih)) {
            throw ImportException::berkas("Baris {$nomor}: target '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $baris
     */
    private function tolakBarisKembar(Collection $baris, string $namaBerkas): void
    {
        $kembar = $baris
            ->groupBy(fn (array $b) => implode('|', [$b['uker_id'], $b['kpi'], $b['tahun'], $b['bulan']]))
            ->filter(fn (Collection $g) => $g->count() > 1);

        if ($kembar->isNotEmpty()) {
            throw ImportException::berkas(sprintf(
                '%s memuat %d kombinasi uker+kpi+tahun+bulan yang kembar, contoh: %s. Gabungkan dulu baris kembar tersebut.',
                $namaBerkas,
                $kembar->count(),
                $kembar->keys()->take(3)->implode('; '),
            ));
        }
    }
}
