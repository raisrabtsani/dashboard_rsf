<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Services\Concerns\MelaporkanImport;
use App\Models\Pinjaman;
use App\Models\RkaPinjaman;
use App\Models\Uker;
use App\Support\Bulan;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Import target RKA Pinjaman dari CSV/Excel.
 *
 * Format bisnis terbaru, nilai RUPIAH PENUH:
 *   id_cabang | id_uker | Segmentasi | Produk | Kualitas | Target | Bulan | Tahun
 *
 * Pada format tersebut, `Segmentasi` adalah segmen utama (Micro/Small/Consumer/
 * Medium), sedangkan `Produk` disimpan ke dimensi `segmentasi` agar tetap cocok
 * dengan struktur tabel dan data aktual. Format lama `segmen | segmentasi` tetap
 * diterima untuk kompatibilitas.
 *
 * Sama seperti RKA Simpanan: nama kolom toleran, bulan boleh nama, sel target
 * kosong berarti "tidak punya target" (dilewati, bukan disimpan 0), dan
 * datanya di-UPSERT karena target boleh direvisi sepanjang tahun berjalan.
 */
class RkaPinjamanCsvImportService
{
    use MelaporkanImport;

    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'segmen' => ['segment'],
        'segmentasi' => ['produk', 'product'],
        'kualitas' => ['kolektibilitas', 'kol'],
        'tahun' => ['thn', 'year'],
        'bulan' => ['bln', 'month', 'periode'],
        'target' => ['rka', 'nilai', 'nominal'],
    ];

    /** `segmentasi` opsional. */
    public const WAJIB = ['id_cabang', 'id_uker', 'segmen', 'kualitas', 'tahun', 'bulan', 'target'];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'Segmentasi', 'Produk', 'Kualitas', 'Target', 'Bulan', 'Tahun'];

    /**
     * @return array{tahun: list<int>, baris: int, dilewati: int, total_target: float}
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        ['baris' => $baris, 'dilewati' => $dilewati] = $this->baca($path, $namaAsli ?? basename($path));

        DB::transaction(function () use ($baris) {
            $baris->chunk(1000)->each(fn (Collection $potongan) => RkaPinjaman::query()->upsert(
                $potongan->values()->all(),
                ['uker_id', 'segmen', 'segmentasi', 'kualitas', 'tahun', 'bulan'],
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
        return RkaPinjaman::query()
            ->groupBy('tahun', 'kualitas')
            ->orderByDesc('tahun')
            ->orderBy('kualitas')
            ->selectRaw('tahun, kualitas, COUNT(*) as jumlah_baris, SUM(target) as total_target')
            ->get()
            ->map(fn ($r) => [
                'tahun' => (int) $r->tahun,
                'produk' => $r->kualitas,
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total_target' => Satuan::toJuta($r->total_target),
            ])
            ->all();
    }

    public function hapusTahun(int $tahun): int
    {
        return RkaPinjaman::query()->where('tahun', $tahun)->delete();
    }

    /**
     * @return array{baris: Collection<int, array<string, mixed>>, dilewati: int}
     */
    private function baca(string $path, string $namaBerkas): array
    {
        $mentah = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $mentah = $this->normalisasiFormatSegmentasiProduk($mentah);
        $baris = PetaKolom::petakan($mentah, self::ALIAS, self::WAJIB, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $now = Carbon::now();
        $dilewati = 0;

        $hasil = $this->petakanBarisAman($baris, function (array $r, int $i) use ($ukerValid, $now, &$dilewati) {
            $nomor = $i + 2;

            $ukerId = (int) trim((string) $r['id_uker']);
            $kualitas = $this->normalisasiKualitas($r['kualitas']);
            $segmen = $this->normalisasiSegmen($r['segmen']);
            $tahun = (int) trim((string) $r['tahun']);

            if (! $ukerValid->has($ukerId)) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$ukerId} tidak ada di master uker.");
            }

            $kualitasValid = [RkaPinjaman::KUALITAS_OS, ...Pinjaman::KUALITAS];

            if (! in_array($kualitas, $kualitasValid, true)) {
                throw ImportException::berkas(
                    "Baris {$nomor}: kualitas '{$kualitas}' tidak dikenal. Gunakan: ".implode(', ', $kualitasValid).'.',
                );
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
                'segmentasi' => trim((string) ($r['segmentasi'] ?? '')),
                'kualitas' => $kualitas,
                'tahun' => $tahun,
                'bulan' => Bulan::uraiAtauGagal((string) $r['bulan'], $nomor),
                'target' => $this->angka($r['target'], $nomor),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });


        return ['baris' => $hasil, 'dilewati' => $dilewati];
    }

    /**
     * Format RKA dari unit bisnis memakai judul:
     *   Segmentasi = segmen utama, Produk = rincian produk.
     *
     * Karena format lama juga mempunyai kolom bernama `segmentasi`, pemetaan
     * harus ditentukan dari kombinasi header. Bila kolom `segmen` sudah ada,
     * baris dibiarkan memakai format lama.
     *
     * @param  Collection<int, array<string, string>>  $baris
     * @return Collection<int, array<string, string>>
     */
    private function normalisasiFormatSegmentasiProduk(Collection $baris): Collection
    {
        if ($baris->isEmpty()) {
            return $baris;
        }

        $header = array_keys($baris->first());
        $headerNormal = [];

        foreach ($header as $kolom) {
            $headerNormal[$this->normalisasiHeader($kolom)] = $kolom;
        }

        $punyaSegmen = collect(['segmen', 'segment', 'segmen2025'])
            ->contains(fn (string $nama) => isset($headerNormal[$nama]));
        $kolomSegmentasi = $headerNormal['segmentasi'] ?? null;
        $kolomProduk = $headerNormal['produk'] ?? $headerNormal['product'] ?? null;

        if ($punyaSegmen || $kolomSegmentasi === null || $kolomProduk === null) {
            return $baris;
        }

        return $baris->map(function (array $r) use ($kolomSegmentasi, $kolomProduk) {
            $segmen = $r[$kolomSegmentasi] ?? '';
            $produk = $r[$kolomProduk] ?? '';

            unset($r[$kolomSegmentasi], $r[$kolomProduk]);

            $r['segmen'] = $segmen;
            $r['segmentasi'] = $produk;

            return $r;
        });
    }

    private function normalisasiHeader(string $nilai): string
    {
        return preg_replace('/[\s_]+/', '', strtolower(trim($nilai)));
    }

    private function normalisasiSegmen(mixed $nilai): string
    {
        $mentah = trim((string) $nilai);

        return match (strtolower($mentah)) {
            'micro', 'mikro' => 'Micro',
            'small', 'kecil' => 'Small',
            'consumer', 'konsumer', 'konsumtif' => 'Consumer',
            'medium', 'menengah' => 'Medium',
            default => $mentah,
        };
    }

    private function normalisasiKualitas(mixed $nilai): string
    {
        $mentah = trim((string) $nilai);

        return match (strtoupper($mentah)) {
            'OS', 'OUTSTANDING' => RkaPinjaman::KUALITAS_OS,
            'LANCAR' => Pinjaman::KUALITAS_LANCAR,
            'SML' => Pinjaman::KUALITAS_SML,
            'NPL' => Pinjaman::KUALITAS_NPL,
            default => $mentah,
        };
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
            ->groupBy(fn (array $b) => implode('|', [
                $b['uker_id'], $b['segmen'], $b['segmentasi'], $b['kualitas'], $b['tahun'], $b['bulan'],
            ]))
            ->filter(fn (Collection $g) => $g->count() > 1);

        if ($kembar->isNotEmpty()) {
            throw ImportException::berkas(sprintf(
                '%s memuat %d kombinasi kunci yang kembar, contoh: %s. Gabungkan dulu baris kembar tersebut.',
                $namaBerkas,
                $kembar->count(),
                $kembar->keys()->take(3)->implode('; '),
            ));
        }
    }
}
