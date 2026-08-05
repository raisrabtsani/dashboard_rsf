<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Region;
use App\Models\Simpanan;
use App\Models\SimpananHourly;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Satuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * DPK per JAM pada tanggal akhir bulan.
 *
 * DUA TABEL: posisi per jam dibaca dari `simpanan_hourly`, sedangkan PEMBANDING
 * delta diambil dari `simpanan` HARIAN — yaitu posisi hari sebelumnya, bukan
 * jam sebelumnya. Yang ingin dilihat pengguna adalah "sudah bergerak berapa
 * jauh dari posisi kemarin", bukan riak antar jam.
 *
 * Tidak punya RKA sendiri.
 */
class SimpananHourlyService
{
    use MenyaringOrganisasi;

    /**
     * Rollup Region Office dikecualikan — sama seperti SimpananService, karena
     * datanya bersumber dari tabel simpanan yang sama.
     */
    public const EXCLUDED_REGION_ID = Region::OFFICE_ID;

    /** Kartu: 3 produk + 2 agregat turunan, identik dengan dashboard Simpanan. */
    public const KARTU = SimpananService::KARTU;

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(?int $areaId, ?int $cabangId): array
    {
        return [
            'area' => Area::query()->orderBy('nama')->get(['id', 'nama'])->toArray(),
            'cabang' => $this->cabangPerArea($areaId),
            'uker' => $cabangId === null ? [] : $this->ukerPerCabang($cabangId),
            'tanggal' => $this->tanggalTersedia(),
            'tanggal_maks' => $this->tanggalTerakhir(),
        ];
    }

    /**
     * Tanggal EOM yang sudah punya data per jam, terbaru dulu.
     *
     * @return list<string>
     */
    public function tanggalTersedia(): array
    {
        return SimpananHourly::query()
            ->distinct()
            ->orderByDesc('tanggal')
            ->pluck('tanggal')
            ->map(fn ($t) => Carbon::parse($t)->toDateString())
            ->values()
            ->all();
    }

    public function tanggalTerakhir(): ?string
    {
        $tanggal = SimpananHourly::query()->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * Jam yang sudah terisi pada satu tanggal.
     *
     * @return list<int>
     */
    public function jamTersedia(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        return $this->dasarHourly($areaId, $cabangId, $ukerId)
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->distinct()
            ->orderBy('jam')
            ->pluck('jam')
            ->map(fn ($j) => (int) $j)
            ->values()
            ->all();
    }

    /**
     * Kartu KPI posisi per jam, dibandingkan dengan posisi HARIAN hari sebelumnya.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $tanggal, ?int $jam, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $jamTersedia = $this->jamTersedia($posisi->toDateString(), $areaId, $cabangId, $ukerId);

        // Tanpa jam eksplisit, pakai jam TERAKHIR yang sudah masuk.
        $jamAktif = $jam ?? (end($jamTersedia) ?: null);

        $nilai = $jamAktif === null
            ? []
            : $this->agregat($this->saldoPerProduk($posisi->toDateString(), $jamAktif, $areaId, $cabangId, $ukerId));

        // PEMBANDING dari tabel harian, bukan dari jam sebelumnya.
        $tanggalBaseline = $this->tanggalHarianSebelum($posisi, $areaId, $cabangId, $ukerId);
        $baseline = $tanggalBaseline === null
            ? []
            : $this->agregat($this->saldoHarianPerProduk($tanggalBaseline, $areaId, $cabangId, $ukerId));

        $kartu = [];

        foreach (self::KARTU as $key => $judul) {
            $aktual = $nilai[$key] ?? null;
            $pembanding = $baseline[$key] ?? null;

            $kartu[] = [
                'key' => $key,
                'judul' => $judul,
                'nilai' => $aktual === null ? null : Satuan::toJuta($aktual),
                'baseline' => $pembanding === null ? null : Satuan::toJuta($pembanding),
                'delta' => [
                    // Satu-satunya delta domain ini: vs posisi harian sebelumnya.
                    'dtd' => $this->selisih($aktual, $pembanding),
                ],
            ];
        }

        return [
            'tanggal' => $posisi->toDateString(),
            'jam' => $jamAktif,
            'jam_tersedia' => $jamTersedia,
            'tanggal_baseline' => $tanggalBaseline,
            // Tidak ada RKA di domain ini.
            'punya_rka' => false,
            'diperbarui' => Carbon::now()->format('H:i:s'),
            'kartu' => $kartu,
        ];
    }

    /**
     * Tren antar jam pada tanggal tersebut, satu seri per kartu.
     *
     * @return array<string, mixed>
     */
    public function chart(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($tanggal)->toDateString();

        $baris = $this->dasarHourly($areaId, $cabangId, $ukerId)
            ->where('tanggal', $posisi)
            ->groupBy('jam', 'produk')
            ->orderBy('jam')
            ->selectRaw('jam, produk, SUM(saldo) as total')
            ->get()
            ->groupBy(fn ($r) => (int) $r->jam);

        $jam = $baris->keys()->sort()->values();

        $seri = [];

        foreach (self::KARTU as $key => $judul) {
            $seri[] = [
                'key' => $key,
                'judul' => $judul,
                'titik' => $jam->map(function (int $j) use ($baris, $key) {
                    $perProduk = $baris[$j]->pluck('total', 'produk')->map(fn ($v) => (float) $v)->all();

                    return Satuan::toJuta($this->agregat($perProduk)[$key]);
                })->all(),
            ];
        }

        return [
            'tanggal' => $posisi,
            'jam' => $jam->all(),
            'label' => $jam->map(fn (int $j) => sprintf('%02d:00', $j))->all(),
            'seri' => $seri,
        ];
    }

    /**
     * Tabel per cabang untuk jam aktif, dibandingkan posisi harian sebelumnya.
     *
     * @return array<string, mixed>
     */
    public function branchPencapaian(string $tanggal, ?int $jam, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($tanggal)->toDateString();
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';

        $jamTersedia = $this->jamTersedia($posisi, $areaId, $cabangId, $ukerId);
        $jamAktif = $jam ?? (end($jamTersedia) ?: null);

        if ($jamAktif === null) {
            return ['tanggal' => $posisi, 'jam' => null, 'grouping' => $perUker ? 'uker' : 'cabang', 'baris' => []];
        }

        $sekarang = $this->dasarHourly($areaId, $cabangId, $ukerId)
            ->where('tanggal', $posisi)
            ->where('jam', $jamAktif)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(saldo) as total")
            ->pluck('total', 'entitas_id');

        $tanggalBaseline = $this->tanggalHarianSebelum(Carbon::parse($posisi), $areaId, $cabangId, $ukerId);

        $baseline = $tanggalBaseline === null
            ? collect()
            : $this->dasarHarian($areaId, $cabangId, $ukerId)
                ->where('tanggal', $tanggalBaseline)
                ->groupBy($kolom)
                ->selectRaw("{$kolom} as entitas_id, SUM(saldo) as total")
                ->pluck('total', 'entitas_id');

        $nama = $this->namaEntitas($perUker, $sekarang->keys());

        $baris = $sekarang->map(function ($total, $entitasId) use ($baseline, $nama) {
            $nilai = (float) $total;
            $awal = $baseline->has($entitasId) ? (float) $baseline[$entitasId] : null;

            return [
                'id' => (int) $entitasId,
                'nama' => $nama[$entitasId] ?? (string) $entitasId,
                'nilai' => Satuan::toJuta($nilai),
                'baseline' => $awal === null ? null : Satuan::toJuta($awal),
                'delta' => $this->selisih($nilai, $awal),
            ];
        })->values()->sortByDesc('nilai')->values()->all();

        return [
            'tanggal' => $posisi,
            'jam' => $jamAktif,
            'tanggal_baseline' => $tanggalBaseline,
            'grouping' => $perUker ? 'uker' : 'cabang',
            'baris' => $baris,
        ];
    }

    /**
     * @param  Collection<int, mixed>  $id
     * @return Collection<int, string>
     */
    private function namaEntitas(bool $perUker, Collection $id): Collection
    {
        return $perUker
            ? \App\Models\Uker::query()->whereIn('id', $id)->pluck('nama', 'id')
            : \App\Models\Cabang::query()->whereIn('id', $id)->pluck('nama', 'id');
    }

    /**
     * Tanggal HARIAN terakhir yang tersedia SEBELUM tanggal posisi.
     *
     * Dicari di tabel `simpanan`, bukan `simpanan_hourly`.
     */
    private function tanggalHarianSebelum(Carbon $posisi, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        $tanggal = $this->dasarHarian($areaId, $cabangId, $ukerId)
            ->where('tanggal', '<', $posisi->toDateString())
            ->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * @return array<string, float>
     */
    private function saldoPerProduk(string $tanggal, int $jam, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        return $this->dasarHourly($areaId, $cabangId, $ukerId)
            ->where('tanggal', $tanggal)
            ->where('jam', $jam)
            ->groupBy('produk')
            ->selectRaw('produk, SUM(saldo) as total')
            ->pluck('total', 'produk')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function saldoHarianPerProduk(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        return $this->dasarHarian($areaId, $cabangId, $ukerId)
            ->where('tanggal', $tanggal)
            ->groupBy('produk')
            ->selectRaw('produk, SUM(saldo) as total')
            ->pluck('total', 'produk')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * @param  array<string, float>  $perProduk
     * @return array<string, float>
     */
    private function agregat(array $perProduk): array
    {
        $tabungan = (float) ($perProduk[Simpanan::PRODUK_TABUNGAN] ?? 0);
        $giro = (float) ($perProduk[Simpanan::PRODUK_GIRO] ?? 0);
        $deposito = (float) ($perProduk[Simpanan::PRODUK_DEPOSITO] ?? 0);

        return [
            'tabungan' => $tabungan,
            'giro' => $giro,
            'deposito' => $deposito,
            'total' => $tabungan + $giro + $deposito,
            'casa' => $tabungan + $giro,
        ];
    }

    /**
     * @return array{nilai: float|null, persen: float|null}
     */
    private function selisih(?float $aktual, ?float $pembanding): array
    {
        if ($aktual === null || $pembanding === null) {
            return ['nilai' => null, 'persen' => null];
        }

        return [
            'nilai' => Satuan::toJuta($aktual - $pembanding),
            'persen' => $pembanding == 0.0 ? null : round(($aktual - $pembanding) / abs($pembanding) * 100, 2),
        ];
    }

    /**
     * @return Builder<SimpananHourly>
     */
    private function dasarHourly(?int $areaId, ?int $cabangId, ?int $ukerId): Builder
    {
        return $this->filterOrganisasi(
            SimpananHourly::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        );
    }

    /**
     * @return Builder<Simpanan>
     */
    private function dasarHarian(?int $areaId, ?int $cabangId, ?int $ukerId): Builder
    {
        return $this->filterOrganisasi(
            Simpanan::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        );
    }
}
