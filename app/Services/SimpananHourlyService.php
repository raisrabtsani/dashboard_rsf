<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Region;
use App\Models\RkaSimpanan;
use App\Models\Simpanan;
use App\Models\SimpananHourly;
use App\Models\Uker;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Delta;
use App\Support\Satuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Dashboard DPK per jam pada tanggal EOM.
 *
 * Posisi intraday dibaca dari `simpanan_hourly`. Target dan pembanding harian
 * tetap memakai tabel `rka_simpanan` serta `simpanan`, sehingga halaman hourly
 * dapat menampilkan RKA, H-1, DTD, MTD, YTD, dan YOY tanpa tabel tambahan.
 */
class SimpananHourlyService
{
    use MenyaringOrganisasi;

    public const EXCLUDED_REGION_ID = Region::OFFICE_ID;

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
            'produk' => Simpanan::PRODUK,
            'tanggal' => $this->tanggalTersedia(),
            'tanggal_maks' => $this->tanggalTerakhir(),
        ];
    }

    /** @return list<string> */
    public function tanggalTersedia(): array
    {
        return SimpananHourly::query()
            ->distinct()
            ->orderByDesc('tanggal')
            ->pluck('tanggal')
            ->map(fn ($tanggal) => Carbon::parse($tanggal)->toDateString())
            ->values()
            ->all();
    }

    public function tanggalTerakhir(): ?string
    {
        $tanggal = SimpananHourly::query()->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * @return list<int>
     */
    public function jamTersedia(
        string $tanggal,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk = null,
    ): array {
        return $this->dasarHourly($areaId, $cabangId, $ukerId, $produk)
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->distinct()
            ->orderBy('jam')
            ->pluck('jam')
            ->map(fn ($jam) => (int) $jam)
            ->values()
            ->all();
    }

    /**
     * Kartu Total DPK, Tabungan, Giro, Deposito, dan CASA.
     *
     * @return array<string, mixed>
     */
    public function snapshot(
        string $tanggal,
        ?int $jam,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk = null,
    ): array {
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $produk = $this->normalisasiProduk($produk);
        $jamTersedia = $this->jamTersedia(
            $posisi->toDateString(),
            $areaId,
            $cabangId,
            $ukerId,
            $produk,
        );
        $jamAktif = $jam ?? (end($jamTersedia) ?: null);
        $jamSebelum = $jamAktif === null
            ? null
            : collect($jamTersedia)->filter(fn (int $nilai) => $nilai < $jamAktif)->max();

        $nilaiSekarang = $jamAktif === null
            ? []
            : $this->agregat($this->saldoHourlyPerProduk(
                $posisi->toDateString(),
                $jamAktif,
                $areaId,
                $cabangId,
                $ukerId,
                $produk,
            ));
        $nilaiJamSebelum = $jamSebelum === null
            ? []
            : $this->agregat($this->saldoHourlyPerProduk(
                $posisi->toDateString(),
                $jamSebelum,
                $areaId,
                $cabangId,
                $ukerId,
                $produk,
            ));

        $referensi = $this->tanggalReferensi($posisi, $areaId, $cabangId, $ukerId, $produk);
        $saldoHarian = $this->saldoHarianPerTanggalProduk(
            collect($referensi)->filter()->unique()->values()->all(),
            $areaId,
            $cabangId,
            $ukerId,
            $produk,
        );
        $target = $this->agregat($this->targetPerProduk(
            $posisi->year,
            $posisi->month,
            $areaId,
            $cabangId,
            $ukerId,
            $produk,
        ));

        $kartu = [];

        foreach (self::KARTU as $key => $judul) {
            $aktual = $nilaiSekarang[$key] ?? 0.0;
            $rka = $target[$key] ?? 0.0;
            $delta = [
                'h1' => Delta::hitung($aktual, $jamSebelum === null ? null : ($nilaiJamSebelum[$key] ?? 0.0)),
            ];

            foreach ($referensi as $jenis => $tanggalRef) {
                $pembanding = $tanggalRef === null
                    ? null
                    : ($this->agregat($saldoHarian[$tanggalRef] ?? [])[$key] ?? 0.0);
                $delta[$jenis] = Delta::hitung($aktual, $pembanding);
            }

            $kartu[] = [
                'key' => $key,
                'judul' => $judul,
                'nilai' => Satuan::toJuta($aktual),
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($aktual / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($aktual - $rka),
                'delta' => $delta,
            ];
        }

        return [
            'tanggal' => $posisi->toDateString(),
            'jam' => $jamAktif,
            'jam_sebelum' => $jamSebelum,
            'jam_tersedia' => $jamTersedia,
            'tanggal_referensi' => $referensi,
            'punya_rka' => true,
            'diperbarui' => Carbon::now()->format('H:i:s'),
            'kartu' => $kartu,
        ];
    }

    /**
     * Tren intraday per produk.
     *
     * @return array<string, mixed>
     */
    public function chart(
        string $tanggal,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk = null,
    ): array {
        $posisi = Carbon::parse($tanggal)->toDateString();
        $produk = $this->normalisasiProduk($produk);

        $baris = $this->dasarHourly($areaId, $cabangId, $ukerId, $produk)
            ->where('tanggal', $posisi)
            ->groupBy('jam', 'produk')
            ->orderBy('jam')
            ->selectRaw('jam, produk, SUM(saldo) as total')
            ->get()
            ->groupBy(fn ($row) => (int) $row->jam);

        $jam = $baris->keys()->sort()->values();
        $seri = [];

        foreach (self::KARTU as $key => $judul) {
            $seri[] = [
                'key' => $key,
                'judul' => $judul,
                'titik' => $jam->map(function (int $jamAktif) use ($baris, $key) {
                    $perProduk = $baris[$jamAktif]
                        ->pluck('total', 'produk')
                        ->map(fn ($nilai) => (float) $nilai)
                        ->all();

                    return Satuan::toJuta($this->agregat($perProduk)[$key] ?? 0.0);
                })->all(),
            ];
        }

        return [
            'tanggal' => $posisi,
            'jam' => $jam->all(),
            'label' => $jam->map(fn (int $nilai) => sprintf('%02d:00', $nilai))->all(),
            'seri' => $seri,
        ];
    }

    /**
     * Tabel kinerja per cabang atau per unit kerja.
     *
     * @return array<string, mixed>
     */
    public function branchPencapaian(
        string $tanggal,
        ?int $jam,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk = null,
    ): array {
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $produk = $this->normalisasiProduk($produk);
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';
        $jamTersedia = $this->jamTersedia(
            $posisi->toDateString(),
            $areaId,
            $cabangId,
            $ukerId,
            $produk,
        );
        $jamAktif = $jam ?? (end($jamTersedia) ?: null);
        $jamSebelum = $jamAktif === null
            ? null
            : collect($jamTersedia)->filter(fn (int $nilai) => $nilai < $jamAktif)->max();

        if ($jamAktif === null) {
            return [
                'tanggal' => $posisi->toDateString(),
                'jam' => null,
                'jam_sebelum' => null,
                'grouping' => $perUker ? 'uker' : 'cabang',
                'tanggal_referensi' => [],
                'baris' => [],
            ];
        }

        $sekarang = $this->saldoHourlyPerEntitas(
            $posisi->toDateString(),
            $jamAktif,
            $kolom,
            $areaId,
            $cabangId,
            $ukerId,
            $produk,
        );
        $sebelumnya = $jamSebelum === null
            ? collect()
            : $this->saldoHourlyPerEntitas(
                $posisi->toDateString(),
                $jamSebelum,
                $kolom,
                $areaId,
                $cabangId,
                $ukerId,
                $produk,
            );

        $referensi = $this->tanggalReferensi($posisi, $areaId, $cabangId, $ukerId, $produk);
        $harian = [];

        foreach ($referensi as $jenis => $tanggalRef) {
            $harian[$jenis] = $tanggalRef === null
                ? collect()
                : $this->saldoHarianPerEntitas(
                    $tanggalRef,
                    $kolom,
                    $areaId,
                    $cabangId,
                    $ukerId,
                    $produk,
                );
        }

        $target = $this->targetPerEntitas(
            $posisi->year,
            $posisi->month,
            $kolom,
            $areaId,
            $cabangId,
            $ukerId,
            $produk,
        );
        $nama = $perUker
            ? Uker::query()->whereIn('id', $sekarang->keys())->pluck('nama', 'id')
            : Cabang::query()->whereIn('id', $sekarang->keys())->pluck('nama', 'id');

        $baris = $sekarang->map(function ($total, $entitasId) use ($sebelumnya, $harian, $target, $nama) {
            $aktual = (float) $total;
            $rka = (float) ($target[$entitasId] ?? 0);

            return [
                'id' => (int) $entitasId,
                'nama' => $nama[$entitasId] ?? (string) $entitasId,
                'nilai' => Satuan::toJuta($aktual),
                'saldo_bulan_lalu' => Satuan::toJuta($harian['mtd'][$entitasId] ?? null),
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($aktual / $rka * 100, 2) : null,
                'delta' => [
                    'h1' => Delta::hitung($aktual, $sebelumnya->has($entitasId) ? (float) $sebelumnya[$entitasId] : null),
                    'dtd' => Delta::hitung($aktual, $harian['dtd']->has($entitasId) ? (float) $harian['dtd'][$entitasId] : null),
                    'mtd' => Delta::hitung($aktual, $harian['mtd']->has($entitasId) ? (float) $harian['mtd'][$entitasId] : null),
                    'ytd' => Delta::hitung($aktual, $harian['ytd']->has($entitasId) ? (float) $harian['ytd'][$entitasId] : null),
                    'yoy' => Delta::hitung($aktual, $harian['yoy']->has($entitasId) ? (float) $harian['yoy'][$entitasId] : null),
                ],
            ];
        })->values()->sortByDesc('nilai')->values()->all();

        return [
            'tanggal' => $posisi->toDateString(),
            'jam' => $jamAktif,
            'jam_sebelum' => $jamSebelum,
            'tanggal_referensi' => $referensi,
            'grouping' => $perUker ? 'uker' : 'cabang',
            'baris' => $baris,
        ];
    }

    /**
     * @return array{dtd: string|null, mtd: string|null, ytd: string|null, yoy: string|null}
     */
    private function tanggalReferensi(
        Carbon $posisi,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk,
    ): array {
        return [
            'dtd' => $this->tanggalHarianTersedia($posisi->copy()->subDay(), $areaId, $cabangId, $ukerId, $produk),
            'mtd' => $this->tanggalHarianTersedia($posisi->copy()->subMonthNoOverflow()->endOfMonth(), $areaId, $cabangId, $ukerId, $produk),
            'ytd' => $this->tanggalHarianTersedia($posisi->copy()->subYear()->endOfYear(), $areaId, $cabangId, $ukerId, $produk),
            'yoy' => $this->tanggalHarianTersedia($posisi->copy()->subYear(), $areaId, $cabangId, $ukerId, $produk),
        ];
    }

    private function tanggalHarianTersedia(
        Carbon $batas,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk,
    ): ?string {
        $tanggal = $this->dasarHarian($areaId, $cabangId, $ukerId, $produk)
            ->where('tanggal', '<=', $batas->toDateString())
            ->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /** @return array<string, float> */
    private function saldoHourlyPerProduk(
        string $tanggal,
        int $jam,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk,
    ): array {
        return $this->dasarHourly($areaId, $cabangId, $ukerId, $produk)
            ->where('tanggal', $tanggal)
            ->where('jam', $jam)
            ->groupBy('produk')
            ->selectRaw('produk, SUM(saldo) as total')
            ->pluck('total', 'produk')
            ->map(fn ($nilai) => (float) $nilai)
            ->all();
    }

    /**
     * @param  list<string>  $tanggal
     * @return array<string, array<string, float>>
     */
    private function saldoHarianPerTanggalProduk(
        array $tanggal,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk,
    ): array {
        if ($tanggal === []) {
            return [];
        }

        return $this->dasarHarian($areaId, $cabangId, $ukerId, $produk)
            ->whereIn('tanggal', $tanggal)
            ->groupBy('tanggal', 'produk')
            ->selectRaw('tanggal, produk, SUM(saldo) as total')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->tanggal)->toDateString())
            ->map(fn (Collection $rows) => $rows
                ->pluck('total', 'produk')
                ->map(fn ($nilai) => (float) $nilai)
                ->all())
            ->all();
    }

    /** @return Collection<int, float> */
    private function saldoHourlyPerEntitas(
        string $tanggal,
        int $jam,
        string $kolom,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk,
    ): Collection {
        return $this->dasarHourly($areaId, $cabangId, $ukerId, $produk)
            ->where('tanggal', $tanggal)
            ->where('jam', $jam)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(saldo) as total")
            ->pluck('total', 'entitas_id')
            ->map(fn ($nilai) => (float) $nilai);
    }

    /** @return Collection<int, float> */
    private function saldoHarianPerEntitas(
        string $tanggal,
        string $kolom,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk,
    ): Collection {
        return $this->dasarHarian($areaId, $cabangId, $ukerId, $produk)
            ->where('tanggal', $tanggal)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(saldo) as total")
            ->pluck('total', 'entitas_id')
            ->map(fn ($nilai) => (float) $nilai);
    }

    /** @return array<string, float> */
    private function targetPerProduk(
        int $tahun,
        int $bulan,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk,
    ): array {
        return $this->dasarTarget($areaId, $cabangId, $ukerId, $produk)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->groupBy('produk')
            ->selectRaw('produk, SUM(target) as total')
            ->pluck('total', 'produk')
            ->map(fn ($nilai) => (float) $nilai)
            ->all();
    }

    /** @return Collection<int, float> */
    private function targetPerEntitas(
        int $tahun,
        int $bulan,
        string $kolom,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk,
    ): Collection {
        return $this->dasarTarget($areaId, $cabangId, $ukerId, $produk)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(target) as total")
            ->pluck('total', 'entitas_id')
            ->map(fn ($nilai) => (float) $nilai);
    }

    /** @param array<string, float> $perProduk */
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

    private function normalisasiProduk(?string $produk): ?string
    {
        return in_array($produk, Simpanan::PRODUK, true) ? $produk : null;
    }

    private function dasarHourly(
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk = null,
    ): Builder {
        return $this->filterOrganisasi(
            SimpananHourly::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        )->when($this->normalisasiProduk($produk) !== null, fn (Builder $query) => $query->where('produk', $produk));
    }

    private function dasarHarian(
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk = null,
    ): Builder {
        return $this->filterOrganisasi(
            Simpanan::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        )->when($this->normalisasiProduk($produk) !== null, fn (Builder $query) => $query->where('produk', $produk));
    }

    private function dasarTarget(
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $produk = null,
    ): Builder {
        return $this->filterOrganisasi(
            RkaSimpanan::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        )->when($this->normalisasiProduk($produk) !== null, fn (Builder $query) => $query->where('produk', $produk));
    }
}
