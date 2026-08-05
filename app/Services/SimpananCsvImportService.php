<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Models\Simpanan;
use App\Models\Uker;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import data AKTUAL Simpanan dari CSV/Excel.
 *
 * Seluruh parsing & validasi ada di sini — controller admin hanya menerima
 * berkas lalu memanggil service ini. Salin bentuknya untuk domain berikutnya.
 *
 * Format berkas (long, satu baris per produk), nilai RUPIAH PENUH:
 *   id_cabang | id_uker | produk | segmentasi | tanggal | saldo
 */
class SimpananCsvImportService
{
    /**
     * Nama kanonik => alias yang diterima (abai huruf besar/kecil, spasi, underscore).
     *
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
     * Import satu berkas.
     *
     * @return array{tanggal: list<string>, baris: int, sumber: int, total_saldo: float}
     *
     * @throws ImportException 422 bila berkas cacat, 409 bila tanggalnya sudah ada
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        $mentah = $this->baca($path, $namaAsli ?? basename($path));

        // Berkas sumber berisi BANYAK baris per kombinasi granularitas — satu
        // baris per rekening/nasabah. Saldo DIJUMLAHKAN per
        // (uker, produk, segmentasi, tanggal) sebelum disimpan; menyimpan apa
        // adanya akan melanggar simpanan_unique dan membuang sebagian besar saldo.
        $agregat = $this->jumlahkan($mentah);

        $tanggal = $agregat->pluck('tanggal')->unique()->sort()->values();

        $this->tolakBilaTanggalSudahAda($tanggal);

        DB::transaction(function () use ($agregat) {
            $agregat->chunk(1000)->each(
                fn (Collection $potongan) => Simpanan::query()->insert($potongan->values()->all()),
            );
        });

        return [
            'tanggal' => $tanggal->all(),
            'baris' => $agregat->count(),
            'sumber' => $mentah->count(),
            'total_saldo' => (float) $agregat->sum(fn (array $b) => $b['saldo']),
        ];
    }

    /**
     * Jumlahkan saldo per kombinasi granularitas tabel `simpanan`.
     *
     * SUM, bukan last-wins dan bukan MAX: tiap baris berkas adalah komponen
     * (rekening), bukan versi berbeda dari angka yang sama.
     *
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
            })
            ->values();
    }

    /**
     * Riwayat upload diturunkan dari datanya sendiri, bukan tabel log terpisah.
     *
     * Konsekuensinya riwayat tidak akan pernah berbohong: kalau datanya dihapus,
     * riwayatnya ikut hilang.
     *
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 60): array
    {
        return Simpanan::query()
            ->groupBy('tanggal')
            ->orderByDesc('tanggal')
            ->limit($batas)
            ->selectRaw('tanggal, COUNT(*) as jumlah_baris, SUM(saldo) as total_saldo, MAX(updated_at) as diunggah')
            ->get()
            ->map(fn ($r) => [
                'tanggal' => Carbon::parse($r->tanggal)->toDateString(),
                'jumlah_baris' => (int) $r->jumlah_baris,
                // Riwayat menampilkan satuan juta, sama seperti dashboard.
                'total_saldo' => Satuan::toJuta($r->total_saldo),
                'diunggah' => $r->diunggah === null ? null : Carbon::parse($r->diunggah)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * Baris untuk diunduh ulang, dalam format kolom yang sama dengan berkas unggahan.
     *
     * @return list<array<string, mixed>>
     */
    public function barisUntukUnduh(string $tanggal): array
    {
        return Simpanan::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->orderBy('cabang_id')
            ->orderBy('uker_id')
            ->orderBy('produk')
            ->get(['cabang_id', 'uker_id', 'produk', 'segmentasi', 'tanggal', 'saldo'])
            ->map(fn (Simpanan $s) => [
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
        return Simpanan::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->delete();
    }

    /**
     * Hapus seluruh tanggal dalam satu bulan.
     *
     * Memakai rentang tanggal, BUKAN MONTH()/YEAR() — query harus jalan di
     * MySQL (produksi) maupun SQLite (test).
     */
    public function hapusBulan(int $tahun, int $bulan): int
    {
        $awal = Carbon::create($tahun, $bulan, 1)->startOfMonth();

        return Simpanan::query()
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

        return $baris
            ->map(function (array $r, int $i) use ($ukerValid, $now) {
                // +2: baris 1 header, index mulai 0 — supaya nomor baris di pesan
                // error cocok dengan yang dilihat user di Excel.
                $nomor = $i + 2;

                $ukerId = (int) trim((string) $r['id_uker']);
                $cabangId = (int) trim((string) $r['id_cabang']);
                $produk = trim((string) $r['produk']);

                if (! $ukerValid->has($ukerId)) {
                    throw ImportException::berkas("Baris {$nomor}: id_uker {$ukerId} tidak ada di master uker.");
                }

                if ($ukerValid[$ukerId] !== $cabangId) {
                    throw ImportException::berkas(
                        "Baris {$nomor}: id_uker {$ukerId} bukan milik cabang {$cabangId} (seharusnya {$ukerValid[$ukerId]}).",
                    );
                }

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
                    // Disimpan apa adanya: berkas sumber memakai rupiah penuh.
                    'saldo' => $this->saldo($r['saldo'], $nomor),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })
            ->values();
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
        // Excel/CSV sering membawa pemisah ribuan dan spasi.
        $bersih = str_replace([' ', ','], '', trim((string) $nilai));

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
        $sudahAda = Simpanan::query()
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
