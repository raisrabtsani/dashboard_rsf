<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Models\RkaSimpanan;
use App\Models\Simpanan;
use App\Models\Uker;
use App\Support\Bulan;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Import target RKA Simpanan dari CSV/Excel.
 *
 * Nama kolom bersifat TOLERAN (lihat ALIAS): berkas dari unit bisnis disusun di
 * Excel sehingga kapitalisasi dan istilahnya tidak konsisten — "Produk"/"produk",
 * " RKA "/"target". Nilai boleh berformat "1,234,567" atau " 1 234 567 ", dan
 * bulan boleh angka 1-12 maupun nama ("Januari").
 *
 * Beda perilaku dengan importer aktual: RKA di-UPSERT (target boleh direvisi
 * sepanjang tahun berjalan), sedangkan data aktual harus dihapus dulu.
 */
class RkaSimpananCsvImportService
{
    /**
     * Nama kanonik => alias yang diterima (pencocokan abai huruf besar/kecil,
     * spasi, dan underscore).
     *
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'produk' => [],
        'segmentasi' => ['segmen'],
        'tahun' => ['thn', 'year'],
        'bulan' => ['bln', 'month', 'periode'],
        'target' => ['rka', 'nilai', 'nominal'],
    ];

    /** Kolom yang wajib ada; `segmentasi` opsional. */
    public const WAJIB = ['id_cabang', 'id_uker', 'produk', 'tahun', 'bulan', 'target'];

    /** Ditampilkan di halaman admin sebagai panduan format. */
    public const KOLOM = ['id_cabang', 'id_uker', 'produk', 'segmentasi', 'tahun', 'bulan', 'target'];

    /**
     * @return array{tahun: list<int>, baris: int, dilewati: int, total_target: float}
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        ['baris' => $baris, 'dilewati' => $dilewati] = $this->baca($path, $namaAsli ?? basename($path));

        if ($baris->isEmpty()) {
            throw ImportException::berkas('Tidak ada baris target yang bisa diimpor dari berkas ini.');
        }

        DB::transaction(function () use ($baris) {
            $baris->chunk(1000)->each(fn (Collection $potongan) => RkaSimpanan::query()->upsert(
                $potongan->values()->all(),
                // Harus cocok dengan indeks rka_simpanan_seg_unique.
                ['uker_id', 'produk', 'segmentasi', 'tahun', 'bulan'],
                ['cabang_id', 'target', 'updated_at'],
            ));
        });

        return [
            'tahun' => $baris->pluck('tahun')->unique()->sort()->values()->all(),
            'baris' => $baris->count(),
            'dilewati' => $dilewati,
            'total_target' => (float) $baris->sum(fn (array $b) => $b['target']),
        ];
    }

    /**
     * Ringkasan RKA per tahun & produk untuk ditampilkan di halaman admin.
     *
     * @return list<array<string, mixed>>
     */
    public function ringkasan(): array
    {
        return RkaSimpanan::query()
            ->groupBy('tahun', 'produk')
            ->orderByDesc('tahun')
            ->orderBy('produk')
            ->selectRaw('tahun, produk, COUNT(*) as jumlah_baris, SUM(target) as total_target')
            ->get()
            ->map(fn ($r) => [
                'tahun' => (int) $r->tahun,
                'produk' => $r->produk,
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total_target' => Satuan::toJuta($r->total_target),
            ])
            ->all();
    }

    public function hapusTahun(int $tahun): int
    {
        return RkaSimpanan::query()->where('tahun', $tahun)->delete();
    }

    /**
     * @return array{baris: Collection<int, array<string, mixed>>, dilewati: int}
     */
    private function baca(string $path, string $namaBerkas): array
    {
        $mentah = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $baris = PetaKolom::petakan($mentah, self::ALIAS, self::WAJIB, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $now = Carbon::now();
        $dilewati = 0;

        $hasil = $baris->map(function (array $r, int $i) use ($ukerValid, $now, &$dilewati) {
            // +2: baris 1 header, index mulai 0 — nomor cocok dengan tampilan Excel.
            $nomor = $i + 2;

            $ukerId = (int) trim((string) $r['id_uker']);
            $cabangId = (int) trim((string) $r['id_cabang']);
            $produk = trim((string) $r['produk']);
            $tahun = (int) trim((string) $r['tahun']);

            if (! $ukerValid->has($ukerId)) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$ukerId} tidak ada di master uker.");
            }

            if (! in_array($produk, Simpanan::PRODUK, true)) {
                throw ImportException::berkas(
                    "Baris {$nomor}: produk '{$produk}' tidak dikenal. Gunakan: ".implode(', ', Simpanan::PRODUK).'.',
                );
            }

            if ($tahun < 2000 || $tahun > 2100) {
                throw ImportException::berkas("Baris {$nomor}: tahun '{$tahun}' tidak masuk akal.");
            }

            // Sel target kosong = uker itu memang TIDAK punya target untuk produk
            // ini (mis. unit mikro tidak menjual Giro). Barisnya dilewati, bukan
            // disimpan sebagai 0: "tidak punya target" berbeda dari "target nol",
            // dan tanpa baris RKA pencapaian otomatis tampil null, bukan 0%.
            if (trim((string) $r['target']) === '') {
                $dilewati++;

                return null;
            }

            return [
                // cabang_id diambil dari master, bukan dari berkas: master adalah
                // sumber kebenaran hubungan uker->cabang.
                'cabang_id' => $ukerValid[$ukerId],
                'uker_id' => $ukerId,
                'produk' => $produk,
                'segmentasi' => trim((string) ($r['segmentasi'] ?? '')),
                'tahun' => $tahun,
                'bulan' => Bulan::uraiAtauGagal((string) $r['bulan'], $nomor),
                'target' => $this->angka($r['target'], $nomor),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        })->filter()->values();

        $this->tolakBarisKembar($hasil, $namaBerkas);

        return ['baris' => $hasil, 'dilewati' => $dilewati];
    }

    /**
     * Nilai dari Excel kerap berformat: " 10,093,170,076 ".
     */
    private function angka(mixed $nilai, int $nomor): float
    {
        $bersih = str_replace([' ', ',', "\u{00A0}"], '', trim((string) $nilai));

        if ($bersih === '' || ! is_numeric($bersih)) {
            throw ImportException::berkas("Baris {$nomor}: target '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }

    /**
     * Tolak berkas yang punya dua baris untuk kombinasi kunci yang sama.
     *
     * upsert() hanya menyimpan satu di antaranya, jadi tanpa pemeriksaan ini
     * sebagian target hilang tanpa pemberitahuan.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     */
    private function tolakBarisKembar(Collection $baris, string $namaBerkas): void
    {
        $kembar = $baris
            ->groupBy(fn (array $b) => implode('|', [
                $b['uker_id'], $b['produk'], $b['segmentasi'], $b['tahun'], $b['bulan'],
            ]))
            ->filter(fn (Collection $g) => $g->count() > 1);

        if ($kembar->isNotEmpty()) {
            throw ImportException::berkas(sprintf(
                '%s memuat %d kombinasi uker+produk+segmentasi+tahun+bulan yang kembar, contoh: %s. '.
                'Gabungkan dulu baris kembar tersebut.',
                $namaBerkas,
                $kembar->count(),
                $kembar->keys()->take(3)->implode('; '),
            ));
        }
    }
}
