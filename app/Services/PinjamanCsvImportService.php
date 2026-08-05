<?php

namespace App\Services;

use App\Exceptions\ImportException;
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
 * Format (long), nilai RUPIAH PENUH:
 *   id_cabang | id_uker | segmen | segmentasi | kualitas | tanggal | baki_debet
 *
 * BEDA PENTING dari importer Simpanan: proteksi duplikat dihitung per
 * (tanggal, SEGMEN), bukan per tanggal saja. Segmen baru — mis. Menengah yang
 * dikelola Region — kerap datang menyusul di berkas terpisah untuk tanggal yang
 * sudah ada. Memblokir seluruh berkas hanya karena tanggalnya sudah ada akan
 * membuat segmen baru itu mustahil dimasukkan tanpa menghapus data yang benar.
 */
class PinjamanCsvImportService
{
    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'segmen' => ['segment'],
        'segmentasi' => [],
        'kualitas' => ['kolektibilitas', 'kol'],
        'tanggal' => ['tgl', 'date', 'periode'],
        'baki_debet' => ['bakidebet', 'baki', 'os', 'outstanding', 'nilai', 'nominal'],
    ];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'segmen', 'segmentasi', 'kualitas', 'tanggal', 'baki_debet'];

    /**
     * @return array{tanggal: list<string>, baris: int, dilewati: int, segmen: list<string>, total: float}
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        $baris = $this->baca($path, $namaAsli ?? basename($path));

        $adaSebelumnya = $this->pasanganTanggalSegmenTersimpan($baris);

        [$masuk, $dilewati] = $baris->partition(
            fn (array $b) => ! $adaSebelumnya->contains($this->kunci($b['tanggal'], $b['segmen'])),
        );

        if ($masuk->isEmpty()) {
            throw ImportException::bentrok(
                'Semua kombinasi tanggal + segmen di berkas ini sudah ada: '.
                $adaSebelumnya->take(5)->implode('; ').
                '. Hapus dulu tanggal tersebut bila ingin menggantinya.',
            );
        }

        DB::transaction(function () use ($masuk) {
            $masuk->chunk(1000)->each(
                fn (Collection $potongan) => Pinjaman::query()->insert($potongan->values()->all()),
            );
        });

        return [
            'tanggal' => $masuk->pluck('tanggal')->unique()->sort()->values()->all(),
            'baris' => $masuk->count(),
            'dilewati' => $dilewati->count(),
            'segmen' => $masuk->pluck('segmen')->unique()->sort()->values()->all(),
            'total' => (float) $masuk->sum(fn (array $b) => $b['baki_debet']),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 60): array
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
                'segmen' => $p->segmen,
                'segmentasi' => $p->segmentasi,
                'kualitas' => $p->kualitas,
                'tanggal' => $p->tanggal,
                'baki_debet' => $p->baki_debet,
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
        $baris = PetaKolom::petakan($mentah, self::ALIAS, self::KOLOM, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $now = Carbon::now();

        return $baris->map(function (array $r, int $i) use ($ukerValid, $now) {
            $nomor = $i + 2;

            $ukerId = (int) trim((string) $r['id_uker']);
            $kualitas = trim((string) $r['kualitas']);
            $segmen = trim((string) $r['segmen']);

            if (! $ukerValid->has($ukerId)) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$ukerId} tidak ada di master uker.");
            }

            if (! in_array($kualitas, Pinjaman::KUALITAS, true)) {
                throw ImportException::berkas(
                    "Baris {$nomor}: kualitas '{$kualitas}' tidak dikenal. Gunakan: ".implode(', ', Pinjaman::KUALITAS).'.',
                );
            }

            if ($segmen === '') {
                throw ImportException::berkas("Baris {$nomor}: kolom segmen kosong.");
            }

            return [
                // Master adalah sumber kebenaran hubungan uker->cabang.
                'cabang_id' => $ukerValid[$ukerId],
                'uker_id' => $ukerId,
                'segmen' => $segmen,
                'segmentasi' => trim((string) $r['segmentasi']),
                'kualitas' => $kualitas,
                'tanggal' => $this->tanggal($r['tanggal'], $nomor),
                'baki_debet' => $this->angka($r['baki_debet'], $nomor),
                'created_at' => $now,
                'updated_at' => $now,
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
            throw ImportException::berkas("Baris {$nomor}: baki_debet '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }
}
