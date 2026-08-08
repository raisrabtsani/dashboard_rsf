<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Services\Concerns\MelaporkanImport;
use App\Models\Ph;
use App\Models\Uker;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Segmen;
use App\Support\Spreadsheet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Import PH (pinjaman hapus buku) — FLOW BULANAN.
 *
 * Format (long), nilai RUPIAH PENUH:
 *   id_cabang | id_uker | segmen | periode | saldo
 *
 * TIGA perilaku khas domain ini:
 *
 *  1. Berkas berisi banyak baris per debitur/akun. Importer melakukan SUMIF
 *     utama berdasarkan (id_uker, periode). Di dalam tiap hasil SUMIF, rincian
 *     segmen tetap dipisahkan agar filter segmen PH/Net DG tidak rusak.
 *     Total semua rincian wajib sama dengan total sumber; bila berbeda, impor
 *     dihentikan agar tidak ada saldo yang hilang.
 *  2. id_uker adalah sumber kebenaran organisasi. Jika id_uker valid, cabang_id
 *     selalu diambil dari master uker meskipun kolom id_cabang di berkas berbeda.
 *     Baris yang id_uker-nya kosong/tidak dikenal TIDAK dibuang — di-fallback ke
 *     level cabang (uker_id = cabang_id) supaya nilainya tetap masuk total.
 *     Membuangnya akan membuat Net DG salah tanpa ada tandanya.
 *  3. Periode yang datanya SUDAH ADA di-LEWATI (skip), bukan membatalkan seluruh
 *     berkas. Berkas bulanan dari unit bisnis lazimnya kumulatif sepanjang tahun,
 *     jadi menolak seluruhnya berarti bulan baru tidak pernah bisa masuk.
 *     Command CLI `import:ph` sebaliknya MENIMPA — lihat ImportPhCommand.
 */
class PhCsvImportService
{
    use MelaporkanImport;

    /** @var array<string, list<string>> */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'segmen' => ['segment', 'segmentasi'],
        'periode' => ['tanggal', 'tgl', 'date', 'bulan', 'posisi'],
        'saldo' => ['nilai', 'nominal', 'ph', 'hapus_buku'],
    ];

    /** @var list<string> */
    public const KOLOM = ['id_cabang', 'id_uker', 'segmen', 'periode', 'saldo'];

    /**
     * @return array{
     *   periode:list<string>,baris:int,sumber:int,dilewati:list<string>,fallback:int,
     *   koreksi_cabang:int,total:float,sumif:array{kriteria:list<string>,kombinasi:int,
     *   baris_tergabung:int,total_sumber:float,total_hasil:float}
     * }
     */
    public function impor(string $path, ?string $namaAsli = null, bool $timpa = false): array
    {
        $mentah = $this->baca($path, $namaAsli ?? basename($path));

        if ($mentah->isEmpty()) {
            throw ImportException::berkas('Tidak ada baris PH valid yang dapat dihitung. Periksa id_uker, periode, segmen, dan saldo.');
        }

        $agregat = $this->jumlahkan($mentah);
        $auditSumif = $this->auditSumif($mentah, $agregat);

        $periodeBerkas = $agregat->pluck('periode')->unique()->sort()->values();
        $dilewati = collect();
        $masuk = $agregat;

        DB::transaction(function () use ($masuk) {
            $masuk->chunk(1000)->each(fn (Collection $potongan) => Ph::query()->upsert(
                $potongan->values()->all(),
                ['uker_id', 'segmen', 'periode'],
                ['cabang_id', 'saldo', 'updated_at'],
            ));
        });

        return [
            'periode' => $masuk->pluck('periode')->unique()->sort()->values()->all(),
            'baris' => $masuk->count(),
            'sumber' => $mentah->count(),
            'dilewati' => $dilewati->all(),
            'fallback' => $mentah->where('fallback', true)->count(),
            'koreksi_cabang' => $mentah->where('cabang_dikoreksi', true)->count(),
            'total' => (float) $masuk->sum(fn (array $b) => $b['saldo']),
            'sumif' => $auditSumif,
            'laporan' => $this->laporanImport(),
        ];
    }

    /**
     * Riwayat diturunkan dari datanya sendiri, bukan tabel log terpisah.
     *
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 1000): array
    {
        return Ph::query()
            ->groupBy('periode')
            ->orderByDesc('periode')
            ->limit($batas)
            ->selectRaw('periode, COUNT(*) as jumlah_baris, SUM(saldo) as total, MAX(updated_at) as diunggah')
            ->get()
            ->map(fn ($r) => [
                'periode' => Carbon::parse($r->periode)->toDateString(),
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total' => Satuan::toJuta($r->total),
                'diunggah' => $r->diunggah === null ? null : Carbon::parse($r->diunggah)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function barisUntukUnduh(string $periode): array
    {
        return Ph::query()
            ->where('periode', Carbon::parse($periode)->endOfMonth()->toDateString())
            ->orderBy('cabang_id')
            ->orderBy('uker_id')
            ->orderBy('segmen')
            ->get(['cabang_id', 'uker_id', 'segmen', 'periode', 'saldo'])
            ->map(fn (Ph $p) => [
                'id_cabang' => $p->cabang_id,
                'id_uker' => $p->uker_id,
                'segmen' => $p->segmen,
                'periode' => $p->periode,
                'saldo' => $p->saldo,
            ])
            ->all();
    }

    public function hapusPeriode(string $periode): int
    {
        return Ph::query()
            ->where('periode', Carbon::parse($periode)->endOfMonth()->toDateString())
            ->delete();
    }

    public function hapusTahun(int $tahun): int
    {
        return Ph::query()
            ->whereBetween('periode', ["{$tahun}-01-01", "{$tahun}-12-31"])
            ->delete();
    }

    /**
     * @param  Collection<int, string>  $periode
     * @return Collection<int, string>
     */
    private function periodeSudahAda(Collection $periode): Collection
    {
        return Ph::query()
            ->whereIn('periode', $periode->all())
            ->distinct()
            ->orderBy('periode')
            ->pluck('periode')
            ->map(fn ($p) => Carbon::parse($p)->toDateString())
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $mentah
     * @return Collection<int, array<string, mixed>>
     */
    private function jumlahkan(Collection $mentah): Collection
    {
        $now = Carbon::now();

        // Tahap 1 = SUMIF utama sesuai permintaan bisnis:
        // seluruh saldo dengan id_uker dan periode yang sama masuk ke satu grup.
        // Tahap 2 hanya mempertahankan rincian segmen di dalam grup tersebut,
        // karena dashboard PH masih menyediakan filter segmen.
        return $mentah
            ->groupBy(fn (array $r) => implode('|', [$r['uker_id'], $r['periode']]))
            ->flatMap(function (Collection $grupUkerPeriode) use ($now) {
                return $grupUkerPeriode
                    ->groupBy('segmen')
                    ->map(function (Collection $grupSegmen) use ($now) {
                        $pertama = $grupSegmen->first();

                        return [
                            'cabang_id' => $pertama['cabang_id'],
                            'uker_id' => $pertama['uker_id'],
                            'segmen' => $pertama['segmen'],
                            'periode' => $pertama['periode'],
                            'saldo' => (float) $grupSegmen->sum(fn (array $r) => $r['saldo']),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    })
                    ->values();
            })
            ->values();
    }

    /**
     * Audit SUMIF untuk memastikan tidak ada saldo sumber yang tertinggal.
     *
     * @param  Collection<int, array<string, mixed>>  $mentah
     * @param  Collection<int, array<string, mixed>>  $agregat
     * @return array{kriteria:list<string>,kombinasi:int,baris_tergabung:int,total_sumber:float,total_hasil:float}
     */
    private function auditSumif(Collection $mentah, Collection $agregat): array
    {
        $kombinasi = $mentah
            ->groupBy(fn (array $r) => implode('|', [$r['uker_id'], $r['periode']]))
            ->count();

        $totalSumber = round((float) $mentah->sum(fn (array $r) => $r['saldo']), 2);
        $totalHasil = round((float) $agregat->sum(fn (array $r) => $r['saldo']), 2);

        if (abs($totalSumber - $totalHasil) > 0.01) {
            throw ImportException::berkas(sprintf(
                'Audit SUMIF gagal: total sumber Rp %s tidak sama dengan total hasil Rp %s.',
                number_format($totalSumber, 2, ',', '.'),
                number_format($totalHasil, 2, ',', '.'),
            ));
        }

        return [
            'kriteria' => ['id_uker', 'periode'],
            'kombinasi' => $kombinasi,
            'baris_tergabung' => max(0, $mentah->count() - $kombinasi),
            'total_sumber' => $totalSumber,
            'total_hasil' => $totalHasil,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function baca(string $path, string $namaBerkas): Collection
    {
        $sumber = Spreadsheet::baca($path, namaAsli: $namaBerkas);
        $baris = PetaKolom::petakan($sumber, self::ALIAS, self::KOLOM, $namaBerkas);

        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $cabangValid = $ukerValid->values()->unique();

        return $this->petakanBarisAman($baris, function (array $r, int $i) use ($ukerValid, $cabangValid) {
            $nomor = $i + 2;

            $cabangMentah = trim((string) $r['id_cabang']);
            $cabangIdBerkas = (int) $cabangMentah;
            $ukerMentah = trim((string) $r['id_uker']);
            $ukerId = (int) $ukerMentah;
            $ukerDikenal = $ukerMentah !== '' && $ukerValid->has($ukerId);
            $fallback = ! $ukerDikenal;
            $cabangDikoreksi = false;

            if ($ukerDikenal) {
                // SUMIF wajib konsisten berdasarkan id_uker. Karena itu cabang
                // diturunkan dari master uker, bukan dijadikan kriteria grup.
                $cabangId = (int) $ukerValid[$ukerId];
                $cabangDikoreksi = $cabangMentah === '' || $cabangIdBerkas !== $cabangId;
            } else {
                if ($cabangMentah === '' || ! $cabangValid->contains($cabangIdBerkas)) {
                    throw ImportException::berkas(
                        "Baris {$nomor}: id_uker '{$ukerMentah}' tidak dikenal dan id_cabang '{$cabangMentah}' tidak valid.",
                    );
                }

                // Fallback hanya dipakai jika id_uker memang kosong/tidak dikenal.
                $cabangId = $cabangIdBerkas;
                $ukerId = $cabangId;

                if (! $ukerValid->has($ukerId)) {
                    throw ImportException::berkas(
                        "Baris {$nomor}: id_uker '{$ukerMentah}' tidak valid dan cabang {$cabangId} ".
                        'tidak punya baris uker level BO untuk menampungnya.',
                    );
                }
            }

            $segmen = Segmen::kanonik((string) $r['segmen']);

            if ($segmen === null) {
                throw ImportException::berkas(
                    "Baris {$nomor}: segmen '{$r['segmen']}' tidak dikenal. ".
                    'Gunakan: '.implode(', ', Segmen::SEMUA).'.',
                );
            }

            return [
                'cabang_id' => $cabangId,
                'uker_id' => $ukerId,
                'segmen' => $segmen,
                // Dinormalkan ke akhir bulan — periode adalah penanda BULAN.
                'periode' => $this->periode($r['periode'], $nomor),
                'saldo' => $this->angka($r['saldo'], $nomor),
                'fallback' => $fallback,
                'cabang_dikoreksi' => $cabangDikoreksi,
            ];
        });
    }

    private function periode(mixed $nilai, int $nomor): string
    {
        $mentah = trim((string) $nilai);

        if ($mentah === '') {
            throw ImportException::berkas("Baris {$nomor}: kolom periode kosong.");
        }

        try {
            return Carbon::parse($mentah)->endOfMonth()->toDateString();
        } catch (Throwable) {
            throw ImportException::berkas("Baris {$nomor}: periode '{$mentah}' tidak bisa dibaca.");
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
