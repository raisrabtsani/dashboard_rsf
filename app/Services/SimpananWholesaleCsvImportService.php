<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Services\Concerns\MelaporkanImport;
use App\Models\Simpanan;
use App\Models\SimpananWholesale;
use App\Models\Uker;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import data AKTUAL DPK Wholesale. Struktur & aturannya mengikuti
 * SimpananCsvImportService (produk Tabungan/Giro/Deposito, saldo dijumlahkan per
 * granularitas, tolak bila tanggalnya sudah ada) — hanya tabelnya yang berbeda.
 */
class SimpananWholesaleCsvImportService
{
    use MelaporkanImport;

    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'produk' => [],
        'segmentasi' => ['segmen'],
        'tanggal' => ['tgl', 'date', 'periode', 'posisi', 'tanggal_posisi'],
        'saldo' => ['nilai', 'nominal', 'outstanding'],
    ];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'produk', 'segmentasi', 'tanggal', 'saldo'];

    /**
     * @return array{tanggal: list<string>, baris: int, sumber: int, total_saldo: float}
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
                fn (Collection $potongan) => SimpananWholesale::query()->upsert(
                    $potongan->values()->all(),
                    ['uker_id', 'produk', 'segmentasi', 'tanggal'],
                    ['cabang_id', 'saldo', 'updated_at'],
                ),
            );
        });

        return [
            'tanggal' => $tanggal->all(),
            'baris' => $agregat->count(),
            'sumber' => $mentah->count(),
            'total_saldo' => (float) $agregat->sum(fn (array $b) => $b['saldo']),
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
                $r['uker_id'], $r['produk'], $r['segmentasi'], $r['tanggal'],
            ]))
            ->map(function (Collection $grup) use ($now) {
                $pertama = $grup->first();

                return [
                    'cabang_id' => $pertama['cabang_id'],
                    'uker_id' => $pertama['uker_id'],
                    'produk' => $pertama['produk'],
                    'segmentasi' => $pertama['segmentasi'],
                    'tanggal' => $pertama['tanggal'],
                    'saldo' => (float) $grup->sum(fn (array $r) => $r['saldo']),
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
        return SimpananWholesale::query()
            ->groupBy('tanggal')
            ->orderByDesc('tanggal')
            ->limit($batas)
            ->selectRaw('tanggal, COUNT(*) as jumlah_baris, SUM(saldo) as total_saldo, MAX(updated_at) as diunggah')
            ->get()
            ->map(fn ($r) => [
                'tanggal' => Carbon::parse($r->tanggal)->toDateString(),
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total_saldo' => Satuan::toJuta($r->total_saldo),
                'diunggah' => $r->diunggah === null ? null : Carbon::parse($r->diunggah)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function barisUntukUnduh(string $tanggal): array
    {
        return SimpananWholesale::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->orderBy('cabang_id')
            ->orderBy('uker_id')
            ->orderBy('produk')
            ->get(['cabang_id', 'uker_id', 'produk', 'segmentasi', 'tanggal', 'saldo'])
            ->map(fn (SimpananWholesale $s) => [
                'id_cabang' => $s->cabang_id,
                'id_uker' => $s->uker_id,
                'produk' => $s->produk,
                'segmentasi' => $s->segmentasi,
                'tanggal' => $s->tanggal,
                'saldo' => $s->saldo,
            ])
            ->all();
    }

    public function hapusTanggal(string $tanggal): int
    {
        return SimpananWholesale::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->delete();
    }

    public function hapusBulan(int $tahun, int $bulan): int
    {
        $awal = Carbon::create($tahun, $bulan, 1)->startOfMonth();

        return SimpananWholesale::query()
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

                $ukerMentah = trim((string) ($r['id_uker'] ?? ''));

                // Nilai kode uker nol dari spreadsheet dapat terbaca sebagai
                // 0, 0.0, 00, atau 0000. Semua variasi tersebut dilewati agar
                // baris valid lainnya tetap diproses.
                if ($ukerMentah === '' || (is_numeric($ukerMentah) && (int) (float) $ukerMentah === 0)) {
                    return null;
                }

                $ukerId = is_numeric($ukerMentah)
                    ? (int) (float) $ukerMentah
                    : (int) $ukerMentah;
                $produk = trim((string) $r['produk']);

                // Kode uker yang belum tersedia di master dilewati saja.
                // Baris valid lainnya tetap diproses dan diimpor.
                if (! $ukerValid->has($ukerId)) {
                    return null;
                }

                // Induk cabang selalu mengikuti master uker. Nilai id_cabang dari
                // berkas hanya dianggap informasi tambahan dan tidak dipakai
                // untuk menentukan relasi organisasi.
                $cabangId = (int) $ukerValid[$ukerId];

                if (! in_array($produk, Simpanan::PRODUK, true)) {
                    throw ImportException::berkas(
                        "Baris {$nomor}: produk '{$produk}' tidak dikenal. Gunakan: ".implode(', ', Simpanan::PRODUK).'.',
                    );
                }

                return [
                    'cabang_id' => $cabangId,
                    'uker_id' => $ukerId,
                    'produk' => $produk,
                    'segmentasi' => trim((string) $r['segmentasi']),
                    'tanggal' => $this->tanggal($r['tanggal'], $nomor),
                    'saldo' => $this->saldo($r['saldo'], $nomor),
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

    private function saldo(mixed $nilai, int $nomor): float
    {
        $bersih = str_replace([' ', ',', "\u{00A0}"], '', trim((string) $nilai));

        if ($bersih === '' || ! is_numeric($bersih)) {
            throw ImportException::berkas("Baris {$nomor}: saldo '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }

    /**
     * @param  Collection<int, string>  $tanggal
     */
    private function tolakBilaTanggalSudahAda(Collection $tanggal): void
    {
        $sudahAda = SimpananWholesale::query()
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
