<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Models\Laba;
use App\Models\Uker;
use App\Support\Bulan;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Import data AKTUAL Laba dari CSV/Excel — BULANAN, nilai KUMULATIF YTD.
 *
 * Format (long), nilai RUPIAH PENUH (boleh negatif = rugi):
 *   id_cabang | id_uker | segmen | tahun | bulan | laba
 *
 * Berbeda dari importer harian Simpanan: tidak ada tanggal, dan datanya
 * di-UPSERT pada kunci `laba_unique` (uker, segmen, tahun, bulan) sehingga
 * mengunggah ulang bersifat idempoten (menimpa, bukan menggandakan). Sel laba
 * kosong dilewati (dianggap "tidak ada laporan", bukan 0).
 */
class LabaCsvImportService
{
    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'segmen' => ['segment', 'segmentasi'],
        'tahun' => ['thn', 'year'],
        'bulan' => ['bln', 'month', 'periode'],
        'laba' => ['profit', 'nilai', 'nominal', 'ytd'],
    ];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'segmen', 'tahun', 'bulan', 'laba'];

    /**
     * @return array{tahun: list<int>, baris: int, dilewati: int, total: float}
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        ['baris' => $baris, 'dilewati' => $dilewati] = $this->baca($path, $namaAsli ?? basename($path));

        if ($baris->isEmpty()) {
            throw ImportException::berkas('Tidak ada baris laba yang bisa diimpor dari berkas ini.');
        }

        DB::transaction(function () use ($baris) {
            $baris->chunk(1000)->each(fn (Collection $potongan) => Laba::query()->upsert(
                $potongan->values()->all(),
                ['uker_id', 'segmen', 'tahun', 'bulan'],
                ['cabang_id', 'laba', 'updated_at'],
            ));
        });

        return [
            'tahun' => $baris->pluck('tahun')->unique()->sort()->values()->all(),
            'baris' => $baris->count(),
            'dilewati' => $dilewati,
            'total' => (float) $baris->sum(fn (array $b) => $b['laba']),
        ];
    }

    /**
     * Riwayat upload per periode (tahun, bulan) — diturunkan dari datanya sendiri.
     *
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 60): array
    {
        return Laba::query()
            ->groupBy('tahun', 'bulan')
            ->orderByDesc('tahun')
            ->orderByDesc('bulan')
            ->limit($batas)
            ->selectRaw('tahun, bulan, COUNT(*) as jumlah_baris, SUM(laba) as total, MAX(updated_at) as diunggah')
            ->get()
            ->map(fn ($r) => [
                'tahun' => (int) $r->tahun,
                'bulan' => (int) $r->bulan,
                'periode' => sprintf('%04d-%02d', $r->tahun, $r->bulan),
                'nama' => Bulan::NAMA[(int) $r->bulan].' '.$r->tahun,
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total' => Satuan::toJuta($r->total),
                'diunggah' => $r->diunggah === null ? null : Carbon::parse($r->diunggah)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function barisUntukUnduh(int $tahun, int $bulan): array
    {
        return Laba::query()
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->orderBy('cabang_id')
            ->orderBy('uker_id')
            ->orderBy('segmen')
            ->get()
            ->map(fn (Laba $l) => [
                'id_cabang' => $l->cabang_id,
                'id_uker' => $l->uker_id,
                'segmen' => $l->segmen,
                'tahun' => $l->tahun,
                'bulan' => $l->bulan,
                'laba' => $l->laba,
            ])
            ->all();
    }

    public function hapusPeriode(int $tahun, int $bulan): int
    {
        return Laba::query()->where('tahun', $tahun)->where('bulan', $bulan)->delete();
    }

    public function hapusTahun(int $tahun): int
    {
        return Laba::query()->where('tahun', $tahun)->delete();
    }

    /**
     * @return array{baris: Collection<int, array<string, mixed>>, dilewati: int}
     */
    private function baca(string $path, string $namaBerkas): array
    {
        $mentah = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $baris = PetaKolom::petakan($mentah, self::ALIAS, self::KOLOM, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $now = Carbon::now();
        $dilewati = 0;

        $hasil = $baris->map(function (array $r, int $i) use ($ukerValid, $now, &$dilewati) {
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

            // Sel kosong = tidak ada laporan laba untuk periode ini (bukan 0).
            if (trim((string) $r['laba']) === '') {
                $dilewati++;

                return null;
            }

            return [
                // Master adalah sumber kebenaran hubungan uker->cabang.
                'cabang_id' => $ukerValid[$ukerId],
                'uker_id' => $ukerId,
                'segmen' => $segmen,
                'tahun' => $tahun,
                'bulan' => Bulan::uraiAtauGagal((string) $r['bulan'], $nomor),
                'laba' => $this->angka($r['laba'], $nomor),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->filter()->values();

        $this->tolakBarisKembar($hasil, $namaBerkas);

        return ['baris' => $hasil, 'dilewati' => $dilewati];
    }

    /**
     * Laba boleh NEGATIF (rugi) dan boleh 0. Yang ditolak hanya yang bukan angka.
     */
    private function angka(mixed $nilai, int $nomor): float
    {
        $bersih = str_replace([' ', ',', "\u{00A0}"], '', trim((string) $nilai));

        if ($bersih === '' || ! is_numeric($bersih)) {
            throw ImportException::berkas("Baris {$nomor}: laba '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }

    /**
     * upsert() hanya menyimpan satu dari baris berkunci sama.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     */
    private function tolakBarisKembar(Collection $baris, string $namaBerkas): void
    {
        $kembar = $baris
            ->groupBy(fn (array $b) => implode('|', [$b['uker_id'], $b['segmen'], $b['tahun'], $b['bulan']]))
            ->filter(fn (Collection $g) => $g->count() > 1);

        if ($kembar->isNotEmpty()) {
            throw ImportException::berkas(sprintf(
                '%s memuat %d kombinasi uker+segmen+tahun+bulan yang kembar, contoh: %s. '.
                'Gabungkan dulu baris kembar tersebut.',
                $namaBerkas,
                $kembar->count(),
                $kembar->keys()->take(3)->implode('; '),
            ));
        }
    }
}
