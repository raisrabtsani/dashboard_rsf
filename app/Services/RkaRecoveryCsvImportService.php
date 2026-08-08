<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Services\Concerns\MelaporkanImport;
use App\Models\RkaRecovery;
use App\Models\Uker;
use App\Support\Bulan;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Import target RKA Recovery dari CSV/Excel.
 *
 * Format (long), nilai RUPIAH PENUH:
 *   id_cabang | id_uker | segmen | tahun | bulan | target
 *
 * Sama seperti RKA Simpanan: nama kolom toleran, bulan boleh nama, sel target
 * kosong berarti "tidak punya target" (dilewati, bukan disimpan 0), dan datanya
 * di-UPSERT karena target boleh direvisi sepanjang tahun berjalan.
 *
 * Berkas dapat memuat banyak baris target untuk unit kerja dan periode yang sama.
 * Importer menjalankan SUMIF utama berdasarkan (id_uker, bulan, tahun). Rincian
 * segmen tetap dipisahkan di dalam grup tersebut supaya filter segmen dashboard
 * Recovery tidak rusak. Total sumber dan total hasil wajib sama.
 *
 * Segmen disimpan MENTAH apa adanya — dinormalkan ke kanonik saat baca di
 * RecoveryService, sama seperti data aktualnya.
 */
class RkaRecoveryCsvImportService
{
    use MelaporkanImport;

    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'segmen' => ['segment', 'segmentasi'],
        'tahun' => ['thn', 'year'],
        'bulan' => ['bln', 'month', 'periode'],
        'target' => ['rka', 'nilai', 'nominal'],
    ];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'segmen', 'tahun', 'bulan', 'target'];

    /**
     * @return array{
     *   tahun:list<int>,baris:int,sumber:int,dilewati:int,total_target:float,
     *   sumif:array{kriteria:list<string>,kombinasi:int,baris_tergabung:int,
     *   total_sumber:float,total_hasil:float}
     * }
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        ['baris' => $mentah, 'dilewati' => $dilewati] = $this->baca($path, $namaAsli ?? basename($path));

        $baris = $this->jumlahkan($mentah);
        $auditSumif = $this->auditSumif($mentah, $baris);

        DB::transaction(function () use ($baris) {
            $baris->chunk(1000)->each(fn (Collection $potongan) => RkaRecovery::query()->upsert(
                $potongan->values()->all(),
                ['uker_id', 'segmen', 'tahun', 'bulan'],
                ['cabang_id', 'target', 'updated_at'],
            ));
        });

        return [
            'tahun' => $baris->pluck('tahun')->unique()->sort()->values()->all(),
            'baris' => $baris->count(),
            'sumber' => $mentah->count(),
            'dilewati' => $dilewati,
            'total_target' => (float) $baris->sum(fn (array $b) => $b['target']),
            'sumif' => $auditSumif,
            'laporan' => $this->laporanImport(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function ringkasan(): array
    {
        return RkaRecovery::query()
            ->groupBy('tahun', 'segmen')
            ->orderByDesc('tahun')
            ->orderBy('segmen')
            ->selectRaw('tahun, segmen, COUNT(*) as jumlah_baris, SUM(target) as total_target')
            ->get()
            ->map(fn ($r) => [
                'tahun' => (int) $r->tahun,
                // Halaman admin memakai key `produk` sebagai label kelompok generik.
                'produk' => $r->segmen,
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total_target' => Satuan::toJuta($r->total_target),
            ])
            ->all();
    }

    public function hapusTahun(int $tahun): int
    {
        return RkaRecovery::query()->where('tahun', $tahun)->delete();
    }

    /**
     * @return array{baris: Collection<int, array<string, mixed>>, dilewati: int}
     */
    private function baca(string $path, string $namaBerkas): array
    {
        $mentah = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $baris = PetaKolom::petakan($mentah, self::ALIAS, self::KOLOM, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $dilewati = 0;

        $hasil = $this->petakanBarisAman($baris, function (array $r, int $i) use ($ukerValid, &$dilewati) {
            $nomor = $i + 2;

            $ukerId = (int) trim((string) $r['id_uker']);
            $segmen = trim((string) $r['segmen']);
            $tahun = (int) trim((string) $r['tahun']);

            if (! $ukerValid->has($ukerId)) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$ukerId} tidak ada di master uker.");
            }

            if ($segmen === '') {
                throw ImportException::berkas("Baris {$nomor}: kolom segmen kosong.");
            }

            if ($tahun < 2000 || $tahun > 2100) {
                throw ImportException::berkas("Baris {$nomor}: tahun '{$tahun}' tidak masuk akal.");
            }

            // Sel kosong = tidak punya target (bukan target nol).
            if (trim((string) $r['target']) === '') {
                $dilewati++;

                return null;
            }

            return [
                'cabang_id' => $ukerValid[$ukerId],
                'uker_id' => $ukerId,
                'segmen' => $segmen,
                'tahun' => $tahun,
                'bulan' => Bulan::uraiAtauGagal((string) $r['bulan'], $nomor),
                'target' => $this->angka($r['target'], $nomor),
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
     * SUMIF utama berdasarkan id_uker + bulan + tahun. Rincian segmen tetap
     * dipertahankan di dalam setiap kombinasi agar target per segmen tetap dapat
     * dipakai dashboard.
     *
     * @param  Collection<int, array<string, mixed>>  $mentah
     * @return Collection<int, array<string, mixed>>
     */
    private function jumlahkan(Collection $mentah): Collection
    {
        $now = Carbon::now();

        return $mentah
            ->groupBy(fn (array $r) => implode('|', [$r['uker_id'], $r['bulan'], $r['tahun']]))
            ->flatMap(function (Collection $grupUkerPeriode) use ($now) {
                return $grupUkerPeriode
                    ->groupBy('segmen')
                    ->map(function (Collection $grupSegmen) use ($now) {
                        $pertama = $grupSegmen->first();

                        return [
                            'cabang_id' => $pertama['cabang_id'],
                            'uker_id' => $pertama['uker_id'],
                            'segmen' => $pertama['segmen'],
                            'tahun' => $pertama['tahun'],
                            'bulan' => $pertama['bulan'],
                            'target' => (float) $grupSegmen->sum(fn (array $r) => $r['target']),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    })
                    ->values();
            })
            ->values();
    }

    /**
     * Audit SUMIF memastikan seluruh target sumber ikut terjumlahkan.
     *
     * @param  Collection<int, array<string, mixed>>  $mentah
     * @param  Collection<int, array<string, mixed>>  $agregat
     * @return array{kriteria:list<string>,kombinasi:int,baris_tergabung:int,total_sumber:float,total_hasil:float}
     */
    private function auditSumif(Collection $mentah, Collection $agregat): array
    {
        $kombinasi = $mentah
            ->groupBy(fn (array $r) => implode('|', [$r['uker_id'], $r['bulan'], $r['tahun']]))
            ->count();

        $totalSumber = round((float) $mentah->sum(fn (array $r) => $r['target']), 2);
        $totalHasil = round((float) $agregat->sum(fn (array $r) => $r['target']), 2);

        if (abs($totalSumber - $totalHasil) > 0.01) {
            throw ImportException::berkas(sprintf(
                'Audit SUMIF RKA Recovery gagal: total sumber Rp %s tidak sama dengan total hasil Rp %s.',
                number_format($totalSumber, 2, ',', '.'),
                number_format($totalHasil, 2, ',', '.'),
            ));
        }

        return [
            'kriteria' => ['id_uker', 'bulan', 'tahun'],
            'kombinasi' => $kombinasi,
            'baris_tergabung' => max(0, $mentah->count() - $kombinasi),
            'total_sumber' => $totalSumber,
            'total_hasil' => $totalHasil,
        ];
    }
}
