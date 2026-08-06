<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Services\Concerns\MelaporkanImport;
use App\Models\Simpanan;
use App\Models\SimpananHourly;
use App\Models\Uker;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import posisi DPK per jam.
 *
 * Format berkasnya SAMA PERSIS dengan berkas Simpanan harian:
 *   id_cabang | id_uker | produk | segmentasi | tanggal | saldo
 *
 * Berkas sumber TIDAK membawa informasi jam — jamnya ditetapkan admin di form
 * upload dan dikirim terpisah. Karena satu tanggal diunggah berkali-kali dalam
 * sehari (tiap jam), data di-UPSERT pada (uker, produk, segmentasi, tanggal, jam):
 * mengunggah ulang jam yang sama memperbaiki angkanya, bukan menggandakan.
 */
class SimpananHourlyCsvImportService
{
    use MelaporkanImport;

    /**
     * Format hourly tetap memakai id_cabang karena perubahan kali ini hanya
     * berlaku untuk Upload Simpanan harian. Konstanta dibuat mandiri agar tidak
     * ikut berubah ketika format Simpanan harian disederhanakan.
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
     * @return array{tanggal: list<string>, jam: int, baris: int, sumber: int, total: float, bukan_eom: list<string>}
     */
    public function impor(string $path, int $jam, ?string $namaAsli = null): array
    {
        if ($jam < 0 || $jam > 23) {
            throw ImportException::berkas("Jam '{$jam}' tidak valid. Gunakan 0-23.");
        }

        $mentah = $this->baca($path, $namaAsli ?? basename($path), $jam);

        // Baris per rekening dijumlahkan, sama seperti importer Simpanan harian.
        $agregat = $this->jumlahkan($mentah, $jam);

        $tanggal = $agregat->pluck('tanggal')->unique()->sort()->values();

        DB::transaction(function () use ($agregat) {
            $agregat->chunk(1000)->each(fn (Collection $potongan) => SimpananHourly::query()->upsert(
                $potongan->values()->all(),
                // Harus cocok persis dengan indeks simpanan_hourly_unique.
                ['uker_id', 'produk', 'segmentasi', 'tanggal', 'jam'],
                ['cabang_id', 'saldo', 'updated_at'],
            ));
        });

        return [
            'tanggal' => $tanggal->all(),
            'jam' => $jam,
            'baris' => $agregat->count(),
            'sumber' => $mentah->count(),
            'total' => (float) $agregat->sum(fn (array $b) => $b['saldo']),
            // Peringatan, bukan penolakan: domain ini memang untuk hari EOM,
            // tapi sesekali dipakai uji coba di hari biasa.
            'laporan' => $this->laporanImport(),
            'bukan_eom' => $tanggal->reject(
                fn (string $t) => Carbon::parse($t)->isSameDay(Carbon::parse($t)->endOfMonth()),
            )->values()->all(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 1000): array
    {
        return SimpananHourly::query()
            ->groupBy('tanggal', 'jam')
            ->orderByDesc('tanggal')
            ->orderByDesc('jam')
            ->limit($batas)
            ->selectRaw('tanggal, jam, COUNT(*) as jumlah_baris, SUM(saldo) as total, MAX(updated_at) as diunggah')
            ->get()
            ->map(fn ($r) => [
                'tanggal' => Carbon::parse($r->tanggal)->toDateString(),
                'jam' => (int) $r->jam,
                'periode' => Carbon::parse($r->tanggal)->toDateString().' '.sprintf('%02d:00', $r->jam),
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total' => Satuan::toJuta($r->total),
                'diunggah' => $r->diunggah === null ? null : Carbon::parse($r->diunggah)->toDateTimeString(),
            ])
            ->all();
    }

    public function hapusJam(string $tanggal, int $jam): int
    {
        return SimpananHourly::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->where('jam', $jam)
            ->delete();
    }

    public function hapusTanggal(string $tanggal): int
    {
        return SimpananHourly::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->delete();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $mentah
     * @return Collection<int, array<string, mixed>>
     */
    private function jumlahkan(Collection $mentah, int $jam): Collection
    {
        $now = Carbon::now();

        return $mentah
            ->groupBy(fn (array $r) => implode('|', [
                $r['uker_id'], $r['produk'], $r['segmentasi'], $r['tanggal'],
            ]))
            ->map(function (Collection $grup) use ($now, $jam) {
                $pertama = $grup->first();

                return [
                    'cabang_id' => $pertama['cabang_id'],
                    'uker_id' => $pertama['uker_id'],
                    'produk' => $pertama['produk'],
                    'segmentasi' => $pertama['segmentasi'],
                    'tanggal' => $pertama['tanggal'],
                    'jam' => $jam,
                    'saldo' => (float) $grup->sum(fn (array $r) => $r['saldo']),
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function baca(string $path, string $namaBerkas, int $jam): Collection
    {
        $sumber = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $baris = PetaKolom::petakan($sumber, self::ALIAS, self::KOLOM, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');

        return $this->petakanBarisAman($baris, function (array $r, int $i) use ($ukerValid) {
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
                'saldo' => $this->angka($r['saldo'], $nomor),
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
            throw ImportException::berkas("Baris {$nomor}: saldo '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }
}
