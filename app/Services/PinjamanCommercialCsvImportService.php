<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Services\Concerns\MelaporkanImport;
use App\Models\Pinjaman;
use App\Models\PinjamanCommercial;
use App\Models\Uker;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import data AKTUAL Pinjaman Commercial. Kolomnya mengikuti tabel `pinjaman`
 * (segmen/segmentasi/kualitas/baki_debet). Baki debet DIJUMLAHKAN per granularitas
 * (uker+segmen+segmentasi+kualitas+tanggal) sebelum disimpan — berkas sumber berisi
 * baris per debitur (lihat CLAUDE.md §4). Tanggal yang sudah ada ditolak (hapus dulu).
 */
class PinjamanCommercialCsvImportService
{
    use MelaporkanImport;

    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'segmen' => ['segment'],
        'segmentasi' => [],
        'kualitas' => ['kolektibilitas', 'kol'],
        'tanggal' => ['tgl', 'date', 'periode', 'posisi', 'tanggal_posisi'],
        'baki_debet' => ['bakidebet', 'baki', 'os', 'outstanding', 'nilai', 'nominal'],
    ];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'segmen', 'segmentasi', 'kualitas', 'tanggal', 'baki_debet'];

    /**
     * @return array{tanggal: list<string>, baris: int, sumber: int, total: float}
     *
     * @throws ImportException 422 bila berkas cacat, 409 bila tanggalnya sudah ada
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        $mentah = $this->baca($path, $namaAsli ?? basename($path));
        $agregat = $this->jumlahkan($mentah);
        $tanggal = $agregat->pluck('tanggal')->unique()->sort()->values();


        DB::transaction(function () use ($agregat) {
            $agregat->chunk(1000)->each(
                fn (Collection $potongan) => PinjamanCommercial::query()->upsert(
                    $potongan->values()->all(),
                    ['uker_id', 'segmen', 'segmentasi', 'kualitas', 'tanggal'],
                    ['cabang_id', 'baki_debet', 'updated_at'],
                ),
            );
        });

        return [
            'tanggal' => $tanggal->all(),
            'baris' => $agregat->count(),
            'sumber' => $mentah->count(),
            'total' => (float) $agregat->sum(fn (array $b) => $b['baki_debet']),
            'laporan' => $this->laporanImport(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $mentah
     * @return Collection<int, array<string, mixed>>
     */
    private function jumlahkan(Collection $mentah): Collection
    {
        $now = Carbon::now();

        return $mentah
            ->groupBy(fn (array $r) => implode('|', [
                $r['uker_id'], $r['segmen'], $r['segmentasi'], $r['kualitas'], $r['tanggal'],
            ]))
            ->map(function (Collection $grup) use ($now) {
                $pertama = $grup->first();

                return [
                    'cabang_id' => $pertama['cabang_id'],
                    'uker_id' => $pertama['uker_id'],
                    'segmen' => $pertama['segmen'],
                    'segmentasi' => $pertama['segmentasi'],
                    'kualitas' => $pertama['kualitas'],
                    'tanggal' => $pertama['tanggal'],
                    'baki_debet' => (float) $grup->sum(fn (array $r) => $r['baki_debet']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 1000): array
    {
        return PinjamanCommercial::query()
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
        return PinjamanCommercial::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->orderBy('cabang_id')
            ->orderBy('uker_id')
            ->orderBy('kualitas')
            ->get()
            ->map(fn (PinjamanCommercial $p) => [
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
        return PinjamanCommercial::query()->where('tanggal', Carbon::parse($tanggal)->toDateString())->delete();
    }

    public function hapusBulan(int $tahun, int $bulan): int
    {
        $awal = Carbon::create($tahun, $bulan, 1)->startOfMonth();

        return PinjamanCommercial::query()
            ->whereBetween('tanggal', [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()])
            ->delete();
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

        return $this->petakanBarisAman($baris, function (array $r, int $i) use ($ukerValid, $now) {
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
        });
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

    /**
     * @param  Collection<int, string>  $tanggal
     */
    private function tolakBilaTanggalSudahAda(Collection $tanggal): void
    {
        $sudahAda = PinjamanCommercial::query()
            ->whereIn('tanggal', $tanggal->all())
            ->distinct()
            ->orderBy('tanggal')
            ->pluck('tanggal')
            ->map(fn ($t) => Carbon::parse($t)->toDateString());

        if ($sudahAda->isNotEmpty()) {
            throw ImportException::bentrok(
                'Tanggal berikut sudah ada dan tidak ditimpa otomatis: '.$sudahAda->implode(', ').
                '. Hapus dulu tanggal tersebut lalu unggah ulang.',
            );
        }
    }
}
