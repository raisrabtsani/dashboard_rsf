<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Services\Concerns\MelaporkanImport;
use App\Models\Pinjaman;
use App\Models\Uker;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import data AKTUAL Pinjaman dari CSV/Excel.
 *
 * Format sumber, nilai RUPIAH PENUH:
 *   id_cabang | id_uker | SEGMEN_2025 | Segmentasi | Kualitas Kredit |
 *   Baki Debet | Month, Day, Year of Posisi
 *
 * BEDA PENTING dari importer Simpanan: proteksi duplikat dihitung per
 * (tanggal, SEGMEN), bukan per tanggal saja. Segmen baru — mis. Menengah yang
 * dikelola Region — kerap datang menyusul di berkas terpisah untuk tanggal yang
 * sudah ada. Memblokir seluruh berkas hanya karena tanggalnya sudah ada akan
 * membuat segmen baru itu mustahil dimasukkan tanpa menghapus data yang benar.
 */
class PinjamanCsvImportService
{
    use MelaporkanImport;

    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'segmen' => ['segment', 'SEGMEN_2025', 'segmen2025'],
        'segmentasi' => ['Segmentasi'],
        'kualitas' => ['kolektibilitas', 'kol', 'Kualitas Kredit', 'kualitas_kredit'],
        'tanggal' => [
            'tgl',
            'date',
            'periode',
            'posisi',
            'tanggal_posisi',
            'Month, Day, Year of Posisi',
        ],
        'baki_debet' => ['bakidebet', 'Baki Debet', 'baki', 'os', 'outstanding', 'nilai', 'nominal'],
    ];

    /**
     * Header yang ditampilkan pada halaman upload dan dipakai saat ekspor ulang.
     * Urutan mengikuti format sumber terbaru.
     *
     * @var list<string>
     */
    public const KOLOM = [
        'id_cabang',
        'id_uker',
        'SEGMEN_2025',
        'Segmentasi',
        'Kualitas Kredit',
        'Baki Debet',
        'Month, Day, Year of Posisi',
    ];

    /** @var list<string> */
    private const KOLOM_WAJIB = [
        'id_cabang',
        'id_uker',
        'segmen',
        'segmentasi',
        'kualitas',
        'baki_debet',
        'tanggal',
    ];

    /**
     * Validasi berkas tanpa menyimpan ke database.
     *
     * Baris yang salah dicatat satu per satu agar dapat diunduh sebagai CSV
     * error. Baris valid baru disimpan setelah user menekan tombol Upload Data.
     *
     * @return array{tanggal: list<string>, baris: int, segmen: list<string>, total: float, laporan: array<string,mixed>}
     */
    public function validasi(string $path, ?string $namaAsli = null): array
    {
        $baris = $this->baca($path, $namaAsli ?? basename($path));

        return [
            'tanggal' => $baris->pluck('tanggal')->unique()->sort()->values()->all(),
            'baris' => $baris->count(),
            'segmen' => $baris->pluck('segmen')->unique()->sort()->values()->all(),
            'total' => (float) $baris->sum(fn (array $b) => $b['baki_debet']),
            'laporan' => $this->laporanImport(),
        ];
    }

    /**
     * @return array{tanggal: list<string>, baris: int, dilewati: int, segmen: list<string>, total: float}
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        $baris = $this->baca($path, $namaAsli ?? basename($path));

        $masuk = $baris;
        $dilewati = collect();

        DB::transaction(function () use ($masuk) {
            $masuk->chunk(1000)->each(
                fn (Collection $potongan) => Pinjaman::query()->upsert(
                    $potongan->values()->all(),
                    ['uker_id', 'segmen', 'segmentasi', 'kualitas', 'tanggal'],
                    ['cabang_id', 'baki_debet', 'updated_at'],
                ),
            );
        });

        return [
            'tanggal' => $masuk->pluck('tanggal')->unique()->sort()->values()->all(),
            'baris' => $masuk->count(),
            'dilewati' => $dilewati->count(),
            'segmen' => $masuk->pluck('segmen')->unique()->sort()->values()->all(),
            'total' => (float) $masuk->sum(fn (array $b) => $b['baki_debet']),
            'laporan' => $this->laporanImport(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 1000): array
    {
        return Pinjaman::query()
            ->groupBy('tanggal')
            ->orderByDesc('tanggal')
            ->limit($batas)
            ->selectRaw('tanggal, COUNT(*) as jumlah_baris, SUM(baki_debet) as total, MAX(updated_at) as diunggah')
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
        return Pinjaman::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->orderBy('cabang_id')
            ->orderBy('uker_id')
            ->orderBy('segmen')
            ->orderBy('kualitas')
            ->get()
            ->map(fn (Pinjaman $p) => [
                'id_cabang' => $p->cabang_id,
                'id_uker' => $p->uker_id,
                'SEGMEN_2025' => $p->segmen,
                'Segmentasi' => $p->segmentasi,
                'Kualitas Kredit' => $p->kualitas,
                'Baki Debet' => $p->baki_debet,
                'Month, Day, Year of Posisi' => $p->tanggal,
            ])
            ->all();
    }

    public function hapusTanggal(string $tanggal): int
    {
        return Pinjaman::query()->where('tanggal', Carbon::parse($tanggal)->toDateString())->delete();
    }

    public function hapusBulan(int $tahun, int $bulan): int
    {
        $awal = Carbon::create($tahun, $bulan, 1)->startOfMonth();

        return Pinjaman::query()
            ->whereBetween('tanggal', [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()])
            ->delete();
    }

    /**
     * Pasangan (tanggal, segmen) yang sudah ada di database.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return Collection<int, string>
     */
    private function pasanganTanggalSegmenTersimpan(Collection $baris): Collection
    {
        return Pinjaman::query()
            ->whereIn('tanggal', $baris->pluck('tanggal')->unique()->all())
            ->whereIn('segmen', $baris->pluck('segmen')->unique()->all())
            ->distinct()
            ->get(['tanggal', 'segmen'])
            ->map(fn ($r) => $this->kunci(Carbon::parse($r->tanggal)->toDateString(), $r->segmen))
            ->unique()
            ->values();
    }

    private function kunci(string $tanggal, string $segmen): string
    {
        return $tanggal.'|'.$segmen;
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function baca(string $path, string $namaBerkas): Collection
    {
        $mentah = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $baris = PetaKolom::petakan($mentah, self::ALIAS, self::KOLOM_WAJIB, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $now = Carbon::now();

        return $this->petakanBarisAman($baris, function (array $r, int $i) use ($ukerValid, $now) {
            $nomor = $i + 2;

            $idCabangSumber = trim((string) ($r['id_cabang'] ?? ''));
            $idUkerSumber = trim((string) ($r['id_uker'] ?? ''));
            $segmen = trim((string) ($r['segmen'] ?? ''));
            $segmentasi = trim((string) ($r['segmentasi'] ?? ''));
            $kualitas = trim((string) ($r['kualitas'] ?? ''));

            if ($idCabangSumber === '') {
                return null;
            }

            if (! ctype_digit($idCabangSumber)) {
                return null;
            }

            if ($idUkerSumber === '') {
                return null;
            }

            if (! ctype_digit($idUkerSumber)) {
                return null;
            }

            $ukerId = (int) $idUkerSumber;

            if (! $ukerValid->has($ukerId)) {
                return null;
            }

            if ($segmen === '') {
                return null;
            }

            if ($segmentasi === '') {
                return null;
            }

            if ($kualitas === '') {
                return null;
            }

            if (! in_array($kualitas, Pinjaman::KUALITAS, true)) {
                throw ImportException::berkas(
                    "Baris {$nomor}: kualitas '{$kualitas}' tidak dikenal. Gunakan: ".implode(', ', Pinjaman::KUALITAS).'.',
                );
            }

            return [
                // Master adalah sumber kebenaran hubungan uker->cabang.
                'cabang_id' => $ukerValid[$ukerId],
                'uker_id' => $ukerId,
                'segmen' => $segmen,
                'segmentasi' => $segmentasi,
                'kualitas' => $kualitas,
                'tanggal' => $this->tanggal($r['tanggal'], $nomor),
                'baki_debet' => $this->angka($r['baki_debet'], $nomor),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        });
    }

    private function tanggal(mixed $nilai, int $nomor): string
    {
        $mentah = trim((string) $nilai);

        if ($mentah === '') {
            throw ImportException::berkas("Baris {$nomor}: kolom tanggal kosong.");
        }

        foreach (['m/d/Y', 'n/j/Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                $tanggal = Carbon::createFromFormat($format, $mentah);

                if ($tanggal !== false && $tanggal->format($format) === $mentah) {
                    return $tanggal->toDateString();
                }
            } catch (Throwable) {
                // Coba format berikutnya.
            }
        }

        try {
            return Carbon::parse($mentah)->toDateString();
        } catch (Throwable) {
            throw ImportException::berkas("Baris {$nomor}: tanggal '{$mentah}' tidak bisa dibaca. Gunakan format MM/DD/YYYY, contoh 01/31/2026.");
        }
    }

    private function angka(mixed $nilai, int $nomor): float
    {
        $bersih = str_replace([' ', ',', "\u{00A0}"], '', trim((string) $nilai));

        if ($bersih === '' || ! is_numeric($bersih)) {
            throw ImportException::berkas("Baris {$nomor}: baki_debet '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }
}
