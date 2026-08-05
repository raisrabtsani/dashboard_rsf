<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Models\Recovery;
use App\Models\Uker;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import data AKTUAL Recovery dari CSV/Excel.
 *
 * Format (long), nilai RUPIAH PENUH:
 *   id_cabang | id_uker | segmen | tanggal | actual
 *
 * DUA hal yang membedakannya dari importer Simpanan/Pinjaman:
 *
 *  1. Berkas sumber berisi BANYAK baris per kombinasi (satu baris per
 *     debitur/akun). actual DIJUMLAHKAN (SUM) per (uker, segmen, tanggal)
 *     SEBELUM disimpan — bukan last-wins, bukan MAX.
 *  2. Datanya di-UPSERT pada kunci `recovery_unique` (uker_id, segmen, tanggal),
 *     jadi mengunggah ulang berkas yang sama bersifat idempoten (menimpa dengan
 *     jumlah yang sama, tidak menggandakan). Segmen dari berkas berbeda untuk
 *     tanggal yang sama juga bisa masuk berdampingan.
 *
 * Segmen disimpan MENTAH apa adanya. Normalisasi taksonomi ke segmen kanonik
 * (Small/Medium/SME -> SME) dilakukan saat baca di RecoveryService, bukan di sini.
 */
class RecoveryCsvImportService
{
    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'segmen' => ['segment', 'segmentasi'],
        'tanggal' => ['tgl', 'date', 'periode', 'posisi', 'tanggal_posisi'],
        'actual' => ['aktual', 'realisasi', 'recovery', 'nilai', 'nominal'],
    ];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'segmen', 'tanggal', 'actual'];

    /**
     * @return array{tanggal: list<string>, baris: int, sumber: int, segmen: list<string>, total: float}
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        $mentah = $this->baca($path, $namaAsli ?? basename($path));

        // Kunci utama: jumlahkan actual per (uker, segmen, tanggal) SEBELUM upsert.
        $agregat = $this->jumlahkan($mentah);

        DB::transaction(function () use ($agregat) {
            $agregat->chunk(1000)->each(fn (Collection $potongan) => Recovery::query()->upsert(
                $potongan->values()->all(),
                // Harus cocok persis dengan indeks recovery_unique.
                ['uker_id', 'segmen', 'tanggal'],
                ['cabang_id', 'actual', 'updated_at'],
            ));
        });

        return [
            'tanggal' => $agregat->pluck('tanggal')->unique()->sort()->values()->all(),
            'baris' => $agregat->count(),
            'sumber' => $mentah->count(),
            'segmen' => $agregat->pluck('segmen')->unique()->sort()->values()->all(),
            'total' => (float) $agregat->sum(fn (array $b) => $b['actual']),
        ];
    }

    /**
     * Riwayat upload diturunkan dari datanya sendiri, bukan tabel log terpisah.
     *
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 60): array
    {
        return Recovery::query()
            ->groupBy('tanggal')
            ->orderByDesc('tanggal')
            ->limit($batas)
            ->selectRaw('tanggal, COUNT(*) as jumlah_baris, SUM(actual) as total, MAX(updated_at) as diunggah')
            ->get()
            ->map(fn ($r) => [
                'tanggal' => Carbon::parse($r->tanggal)->toDateString(),
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total' => Satuan::toJuta($r->total),
                'diunggah' => $r->diunggah === null ? null : Carbon::parse($r->diunggah)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function barisUntukUnduh(string $tanggal): array
    {
        return Recovery::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->orderBy('cabang_id')
            ->orderBy('uker_id')
            ->orderBy('segmen')
            ->get()
            ->map(fn (Recovery $r) => [
                'id_cabang' => $r->cabang_id,
                'id_uker' => $r->uker_id,
                'segmen' => $r->segmen,
                'tanggal' => $r->tanggal,
                'actual' => $r->actual,
            ])
            ->all();
    }

    public function hapusTanggal(string $tanggal): int
    {
        return Recovery::query()->where('tanggal', Carbon::parse($tanggal)->toDateString())->delete();
    }

    /**
     * Hapus seluruh tanggal dalam satu bulan (rentang tanggal, BUKAN MONTH()/YEAR()).
     */
    public function hapusBulan(int $tahun, int $bulan): int
    {
        $awal = Carbon::create($tahun, $bulan, 1)->startOfMonth();

        return Recovery::query()
            ->whereBetween('tanggal', [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()])
            ->delete();
    }

    /**
     * Jumlahkan baris mentah per (uker, segmen, tanggal) menjadi satu baris siap upsert.
     *
     * @param  Collection<int, array<string, mixed>>  $mentah
     * @return Collection<int, array<string, mixed>>
     */
    private function jumlahkan(Collection $mentah): Collection
    {
        $now = Carbon::now();

        return $mentah
            ->groupBy(fn (array $r) => $r['uker_id'].'|'.$r['segmen'].'|'.$r['tanggal'])
            ->map(function (Collection $grup) use ($now) {
                $pertama = $grup->first();

                return [
                    'cabang_id' => $pertama['cabang_id'],
                    'uker_id' => $pertama['uker_id'],
                    'segmen' => $pertama['segmen'],
                    'tanggal' => $pertama['tanggal'],
                    'actual' => (float) $grup->sum(fn (array $r) => $r['actual']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function baca(string $path, string $namaBerkas): Collection
    {
        $sumber = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $baris = PetaKolom::petakan($sumber, self::ALIAS, self::KOLOM, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');

        return $baris->map(function (array $r, int $i) use ($ukerValid) {
            $nomor = $i + 2;

            $ukerId = (int) trim((string) $r['id_uker']);
            $segmen = trim((string) $r['segmen']);

            if (! $ukerValid->has($ukerId)) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$ukerId} tidak ada di master uker.");
            }

            // Segmen TIDAK divalidasi terhadap daftar kanonik: taksonomi berubah
            // antar tahun dan disimpan mentah. Yang penting tidak kosong.
            if ($segmen === '') {
                throw ImportException::berkas("Baris {$nomor}: kolom segmen kosong.");
            }

            return [
                // Master adalah sumber kebenaran hubungan uker->cabang.
                'cabang_id' => $ukerValid[$ukerId],
                'uker_id' => $ukerId,
                'segmen' => $segmen,
                'tanggal' => $this->tanggal($r['tanggal'], $nomor),
                'actual' => $this->angka($r['actual'], $nomor),
            ];
        })->values();
    }

    private function tanggal(mixed $nilai, int $nomor): string
    {
        $mentah = trim((string) $nilai);

        if ($mentah === '') {
            throw ImportException::berkas("Baris {$nomor}: kolom tanggal kosong.");
        }

        try {
            return Carbon::parse($mentah)->toDateString();
        } catch (Throwable) {
            throw ImportException::berkas("Baris {$nomor}: tanggal '{$mentah}' tidak bisa dibaca.");
        }
    }

    private function angka(mixed $nilai, int $nomor): float
    {
        $bersih = str_replace([' ', ',', "\u{00A0}"], '', trim((string) $nilai));

        if ($bersih === '' || ! is_numeric($bersih)) {
            throw ImportException::berkas("Baris {$nomor}: actual '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }
}
