<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Region;
use App\Models\RkaSimpanan;
use App\Models\Simpanan;
use App\Models\Uker;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Delta;
use App\Support\Satuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seluruh query & kalkulasi dashboard Simpanan (DPK).
 *
 * Kontrak service (berlaku untuk semua domain):
 *  - TIDAK menyentuh Request maupun auth() — hanya menerima parameter biasa.
 *    Lingkup data sudah dikunci middleware `scope` sebelum controller memanggil.
 *  - TIDAK mengembalikan response HTTP; hanya array/Collection.
 *  - Semua nilai uang dikembalikan dalam JUTA lewat App\Support\Satuan.
 *  - Query wajib portable MySQL/SQLite: tidak ada MONTH()/YEAR() mentah —
 *    bulan diturunkan di PHP setelah group by tanggal.
 */
class SimpananService
{
    use MenyaringOrganisasi;

    /**
     * Rollup Region Office dikecualikan dari seluruh angka Simpanan
     * (855 bukan kantor operasional dan tidak punya saldo DPK sendiri).
     */
    public const EXCLUDED_REGION_ID = Region::OFFICE_ID;

    /**
     * Kartu snapshot: 3 produk + 2 agregat turunan.
     */
    public const KARTU = [
        'tabungan' => 'Tabungan',
        'giro' => 'Giro',
        'deposito' => 'Deposito',
        'total' => 'Total DPK',
        'casa' => 'CASA',
    ];

    /**
     * Opsi filter: area, cabang (opsional per area), uker (opsional per cabang),
     * plus rentang tanggal yang datanya tersedia.
     *
     * @return array<string, mixed>
     */
    public function filterOptions(?int $areaId, ?int $cabangId): array
    {
        $segmentasi = Simpanan::query()
            ->whereNotNull('segmentasi')
            ->where('segmentasi', '!=', '')
            ->distinct()
            ->orderBy('segmentasi')
            ->pluck('segmentasi')
            ->merge(
                RkaSimpanan::query()
                    ->whereNotNull('segmentasi')
                    ->where('segmentasi', '!=', '')
                    ->distinct()
                    ->pluck('segmentasi'),
            )
            ->filter()
            ->unique(fn ($nilai) => mb_strtolower(trim((string) $nilai)))
            ->sortBy(fn ($nilai) => mb_strtolower((string) $nilai))
            ->values()
            ->all();

        $periode = Simpanan::query()
            ->whereNotNull('tanggal')
            ->distinct()
            ->orderByDesc('tanggal')
            ->pluck('tanggal')
            ->map(fn ($tanggal) => Carbon::parse($tanggal)->toDateString())
            ->values()
            ->all();

        return [
            'area' => Area::query()->orderBy('nama')->get(['id', 'nama'])->toArray(),
            'cabang' => $this->cabangPerArea($areaId),
            'uker' => $cabangId === null ? [] : $this->ukerPerCabang($cabangId),
            'segmentasi' => $segmentasi,
            'produk' => [...Simpanan::PRODUK, 'CASA'],
            'periode' => $periode,
            'tanggal_maks' => $this->tanggalTerakhir(),
            'tanggal_min' => Simpanan::query()->min('tanggal'),
        ];
    }

    public function tanggalTerakhir(): ?string
    {
        $tanggal = Simpanan::query()->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * Tanggal data terakhir yang tersedia pada atau sebelum $tanggal, dalam
     * lingkup filter. Dipakai halaman Ringkasan agar kartu domain ini memakai
     * posisi terbaru yang benar-benar ada (bukan tanggal yang mungkin kosong).
     * Null bila tak ada data sama sekali di lingkup itu.
     */
    public function tanggalTersediaHingga(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        return $this->tanggalTersedia(Carbon::parse($tanggal), $areaId, $cabangId, $ukerId);
    }

    /**
     * Kartu KPI per produk + Total DPK + CASA, lengkap dengan delta & pencapaian.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $segmentasi = null): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();

        // Tanggal pembanding di-resolve ke tanggal TERSEDIA terakhir <= target,
        // supaya akhir pekan / hari libur tidak bikin delta kosong.
        $referensi = [
            'dtd' => $this->tanggalTersedia($posisi->copy()->subDay(), $areaId, $cabangId, $ukerId, $segmentasi),
            'mtd' => $this->tanggalTersedia($posisi->copy()->subMonthNoOverflow()->endOfMonth(), $areaId, $cabangId, $ukerId, $segmentasi),
            'ytd' => $this->tanggalTersedia($posisi->copy()->subYear()->endOfYear(), $areaId, $cabangId, $ukerId, $segmentasi),
            'yoy' => $this->tanggalTersedia($posisi->copy()->subYear(), $areaId, $cabangId, $ukerId, $segmentasi),
        ];

        $tanggalDibaca = collect($referensi)->push($posisi->toDateString())->filter()->unique()->values();
        $saldo = $this->saldoPerTanggalProduk($tanggalDibaca->all(), $areaId, $cabangId, $ukerId, $segmentasi);
        $target = $this->targetPerProduk($posisi->year, $posisi->month, $areaId, $cabangId, $ukerId, $segmentasi);

        $nilaiPosisi = $this->agregatKartu($saldo[$posisi->toDateString()] ?? []);
        $nilaiTarget = $this->agregatKartu($target);

        $kartu = [];

        foreach (self::KARTU as $key => $judul) {
            $aktual = $nilaiPosisi[$key];
            $rka = $nilaiTarget[$key];

            $delta = [];

            foreach ($referensi as $jenis => $tanggalRef) {
                $pembanding = $tanggalRef === null
                    ? null
                    : $this->agregatKartu($saldo[$tanggalRef] ?? [])[$key];

                $delta[$jenis] = Delta::hitung($aktual, $pembanding);
            }

            $kartu[] = [
                'key' => $key,
                'judul' => $judul,
                'nilai' => Satuan::toJuta($aktual),
                'delta' => $delta,
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($aktual / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($aktual - $rka),
            ];
        }

        return [
            'tanggal' => $posisi->toDateString(),
            'tanggal_referensi' => $referensi,
            'kartu' => $kartu,
            'segmentasi' => $this->rincianSegmentasi(
                $posisi->toDateString(),
                $areaId,
                $cabangId,
                $ukerId,
                $segmentasi,
            ),
        ];
    }

    /**
     * Tren saldo harian, dipecah jadi satu seri per bulan untuk line chart.
     *
     * Bulan DITURUNKAN DI PHP — query hanya group by tanggal, supaya jalan sama
     * persis di MySQL (produksi) dan SQLite (test).
     *
     * @return array<string, mixed>
     */
    public function chart(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $segmentasi = null): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();
        // Semua grafik tren DPK memakai 6 seri tetap:
        // Desember tahun sebelumnya + 5 bulan berjalan sampai bulan posisi.
        $rentang = [
            $posisi->copy()->subYear()->month(12)->startOfMonth()->toDateString(),
            $posisi->toDateString(),
        ];

        $total = $this->dasar($areaId, $cabangId, $ukerId, $segmentasi)
            ->whereBetween('tanggal', $rentang)
            ->groupBy('tanggal')
            ->orderBy('tanggal')
            ->selectRaw('tanggal, SUM(saldo) as total')
            ->pluck('total', 'tanggal');

        // Rincian per produk per tanggal — untuk chart Tabungan/Giro/Deposito/CASA.
        $perProduk = $this->dasar($areaId, $cabangId, $ukerId, $segmentasi)
            ->whereBetween('tanggal', $rentang)
            ->groupBy('tanggal', 'produk')
            ->selectRaw('tanggal, produk, SUM(saldo) as total')
            ->get();

        $map = [
            Simpanan::PRODUK_TABUNGAN => [],
            Simpanan::PRODUK_GIRO => [],
            Simpanan::PRODUK_DEPOSITO => [],
        ];

        foreach ($perProduk as $r) {
            $tgl = Carbon::parse($r->tanggal)->toDateString();
            $map[$r->produk][$tgl] = ($map[$r->produk][$tgl] ?? 0) + (float) $r->total;
        }

        // CASA = Tabungan + Giro per tanggal.
        $casa = [];

        foreach ($map[Simpanan::PRODUK_TABUNGAN] + $map[Simpanan::PRODUK_GIRO] as $tgl => $_) {
            $casa[$tgl] = ($map[Simpanan::PRODUK_TABUNGAN][$tgl] ?? 0) + ($map[Simpanan::PRODUK_GIRO][$tgl] ?? 0);
        }

        return [
            'tahun' => $posisi->year,
            'seri' => $this->seriBulanan($total),
            'seri_produk' => [
                'tabungan' => $this->seriBulanan(collect($map[Simpanan::PRODUK_TABUNGAN])),
                'giro' => $this->seriBulanan(collect($map[Simpanan::PRODUK_GIRO])),
                'deposito' => $this->seriBulanan(collect($map[Simpanan::PRODUK_DEPOSITO])),
                'casa' => $this->seriBulanan(collect($casa)),
            ],
        ];
    }

    /**
     * Bangun seri bulanan (satu deret per bulan) dari peta {tanggal => total rupiah}.
     *
     * Bulan diturunkan di PHP (portable MySQL/SQLite). Dipakai chart total & per produk.
     *
     * @param  Collection<string, mixed>  $perTanggal
     * @return list<array<string, mixed>>
     */
    private function seriBulanan(Collection $perTanggal): array
    {
        return collect($perTanggal)
            ->mapWithKeys(fn ($total, $tgl) => [Carbon::parse($tgl)->toDateString() => $total])
            // Gunakan key tahun-bulan agar Desember tahun lalu tidak tergabung
            // dengan Desember tahun berjalan.
            ->groupBy(fn ($total, string $tgl) => Carbon::parse($tgl)->format('Y-m'), preserveKeys: true)
            ->map(function (Collection $bulanan, string $periode) {
                $tanggalPeriode = Carbon::createFromFormat('Y-m', $periode)->startOfMonth();
                $bulan = (int) $tanggalPeriode->month;

                return [
                    'periode' => $periode,
                    'tahun' => (int) $tanggalPeriode->year,
                    'bulan' => $bulan,
                    'nama' => self::NAMA_BULAN[$bulan],
                    'titik' => $bulanan->map(fn ($total, string $tgl) => [
                        'tanggal' => $tgl,
                        'hari' => (int) Carbon::parse($tgl)->day,
                        'nilai' => Satuan::toJuta($total),
                    ])->values()->all(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();
    }

    /** @var array<int, string> */
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * Tabel kinerja per cabang; bila cabang_id dikirim, grouping otomatis
     * berpindah ke per-UKER di cabang itu (drill-down BO).
     *
     * @return array<string, mixed>
     */
    public function branchPencapaian(
        string $tanggal,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $segmentasi = null,
        ?string $produk = null,
    ): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';

        $aktual = $this->dasar($areaId, $cabangId, $ukerId, $segmentasi, $produk)
            ->where('tanggal', $posisi->toDateString())
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(saldo) as total")
            ->pluck('total', 'entitas_id');

        $targetQuery = RkaSimpanan::query()
            ->when(! $perUker, fn (Builder $q) => $q->where('cabang_id', '!=', self::EXCLUDED_REGION_ID))
            ->when($perUker, fn (Builder $q) => $q->where('cabang_id', $cabangId))
            ->when($ukerId !== null, fn (Builder $q) => $q->where('uker_id', $ukerId))
            ->when($areaId !== null && ! $perUker, fn (Builder $q) => $q->whereIn('cabang_id', $this->cabangDiArea($areaId)));

        $target = $this->filterDimensi($targetQuery, $segmentasi, $produk)
            ->where('tahun', $posisi->year)
            ->where('bulan', $posisi->month)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(target) as total")
            ->pluck('total', 'entitas_id');

        // Tanggal pembanding memakai tanggal data terakhir yang benar-benar tersedia.
        // Ini menjaga delta tetap muncul saat posisi jatuh pada akhir pekan/libur.
        $tanggalPembanding = [
            'dtd' => $this->tanggalTersedia(
                $posisi->copy()->subDay(),
                $areaId,
                $cabangId,
                $ukerId,
                $segmentasi,
                $produk,
            ),
            'mtd' => $this->tanggalTersedia(
                $posisi->copy()->startOfMonth()->subDay(),
                $areaId,
                $cabangId,
                $ukerId,
                $segmentasi,
                $produk,
            ),
            'ytd' => $this->tanggalTersedia(
                $posisi->copy()->startOfYear()->subDay(),
                $areaId,
                $cabangId,
                $ukerId,
                $segmentasi,
                $produk,
            ),
            'yoy' => $this->tanggalTersedia(
                $posisi->copy()->subYear(),
                $areaId,
                $cabangId,
                $ukerId,
                $segmentasi,
                $produk,
            ),
        ];

        $pembanding = collect($tanggalPembanding)->map(
            fn (?string $tanggalBanding) => $this->saldoPerEntitas(
                $tanggalBanding,
                $kolom,
                $areaId,
                $cabangId,
                $ukerId,
                $segmentasi,
                $produk,
            ),
        );

        $entitas = $perUker
            ? Uker::query()
                ->with('cabang.area')
                ->whereIn('id', $aktual->keys())
                ->get()
                ->keyBy('id')
            : Cabang::query()
                ->with('area')
                ->whereIn('id', $aktual->keys())
                ->get()
                ->keyBy('id');

        $baris = $aktual->map(function ($total, $entitasId) use ($target, $entitas, $pembanding, $perUker) {
            $rka = (float) ($target[$entitasId] ?? 0);
            $nilai = (float) $total;
            $kantor = $entitas->get($entitasId);
            $area = $perUker ? $kantor?->cabang?->area : $kantor?->area;

            return [
                'id' => (int) $entitasId,
                'nama' => $kantor?->nama ?? (string) $entitasId,
                'area_id' => $area?->id,
                'area_nama' => $area?->nama,
                'nilai' => Satuan::toJuta($nilai),
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($nilai / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($nilai - $rka),
                'dtd' => Delta::hitung($nilai, $pembanding->get('dtd')?->get($entitasId)),
                'mtd' => Delta::hitung($nilai, $pembanding->get('mtd')?->get($entitasId)),
                'ytd' => Delta::hitung($nilai, $pembanding->get('ytd')?->get($entitasId)),
                'yoy' => Delta::hitung($nilai, $pembanding->get('yoy')?->get($entitasId)),
            ];
        })->values()->sortByDesc('nilai')->values()->all();

        return [
            'tanggal' => $posisi->toDateString(),
            'tanggal_pembanding' => $tanggalPembanding,
            'grouping' => $perUker ? 'uker' : 'cabang',
            'baris' => $baris,
        ];
    }

    /**
     * Ambil saldo per cabang/uker pada satu tanggal pembanding.
     *
     * @return Collection<int|string, float>
     */
    private function saldoPerEntitas(
        ?string $tanggal,
        string $kolom,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $segmentasi,
        ?string $produk,
    ): Collection {
        if ($tanggal === null) {
            return collect();
        }

        return $this->dasar($areaId, $cabangId, $ukerId, $segmentasi, $produk)
            ->where('tanggal', $tanggal)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(saldo) as total")
            ->pluck('total', 'entitas_id')
            ->map(fn ($nilai) => (float) $nilai);
    }

    /**
     * Query dasar dengan filter organisasi + pengecualian rollup 855.
     */
    private function dasar(
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $segmentasi = null,
        ?string $produk = null,
    ): Builder
    {
        $query = $this->filterOrganisasi(
            Simpanan::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        );

        return $this->filterDimensi($query, $segmentasi, $produk);
    }

    /**
     * Tanggal data terakhir yang tersedia pada atau sebelum $batas.
     */
    private function tanggalTersedia(
        Carbon $batas,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $segmentasi = null,
        ?string $produk = null,
    ): ?string
    {
        $tanggal = $this->dasar($areaId, $cabangId, $ukerId, $segmentasi, $produk)
            ->where('tanggal', '<=', $batas->toDateString())
            ->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * Saldo per (tanggal, produk) untuk semua tanggal pembanding sekaligus.
     *
     * @param  list<string>  $tanggal
     * @return array<string, array<string, float>>
     */
    private function saldoPerTanggalProduk(
        array $tanggal,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $segmentasi = null,
    ): array
    {
        if ($tanggal === []) {
            return [];
        }

        return $this->dasar($areaId, $cabangId, $ukerId, $segmentasi)
            ->whereIn('tanggal', $tanggal)
            ->groupBy('tanggal', 'produk')
            ->selectRaw('tanggal, produk, SUM(saldo) as total')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->tanggal)->toDateString())
            ->map(fn (Collection $rows) => $rows->pluck('total', 'produk')->map(fn ($v) => (float) $v)->all())
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function targetPerProduk(
        int $tahun,
        int $bulan,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $segmentasi = null,
    ): array
    {
        $query = RkaSimpanan::query()
            ->where('cabang_id', '!=', self::EXCLUDED_REGION_ID)
            ->when($areaId !== null, fn (Builder $q) => $q->whereIn('cabang_id', $this->cabangDiArea($areaId)))
            ->when($cabangId !== null, fn (Builder $q) => $q->where('cabang_id', $cabangId))
            ->when($ukerId !== null, fn (Builder $q) => $q->where('uker_id', $ukerId));

        return $this->filterDimensi($query, $segmentasi, null)
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->groupBy('produk')
            ->selectRaw('produk, SUM(target) as total')
            ->pluck('total', 'produk')
            ->map(fn ($v) => (float) $v)
            ->all();
    }


    /**
     * Terapkan filter segmentasi dan produk pada tabel aktual maupun RKA.
     */
    private function filterDimensi(Builder $query, ?string $segmentasi, ?string $produk): Builder
    {
        $segmentasi = $segmentasi === null ? null : trim($segmentasi);
        $produk = $produk === null ? null : trim($produk);

        return $query
            ->when(
                $segmentasi !== null && $segmentasi !== '',
                fn (Builder $q) => $q->whereRaw('LOWER(TRIM(segmentasi)) = ?', [mb_strtolower($segmentasi)]),
            )
            ->when(
                mb_strtoupper((string) $produk) === 'CASA',
                fn (Builder $q) => $q->whereIn('produk', [Simpanan::PRODUK_TABUNGAN, Simpanan::PRODUK_GIRO]),
            )
            ->when(
                $produk !== null && $produk !== '' && mb_strtoupper($produk) !== 'CASA',
                fn (Builder $q) => $q->whereRaw('LOWER(TRIM(produk)) = ?', [mb_strtolower($produk)]),
            );
    }

    /**
     * Ringkasan aktual per segmentasi untuk tabel Rincian Segmentasi.
     *
     * @return list<array<string, mixed>>
     */
    private function rincianSegmentasi(
        string $tanggal,
        ?int $areaId,
        ?int $cabangId,
        ?int $ukerId,
        ?string $segmentasi,
    ): array
    {
        $rows = $this->dasar($areaId, $cabangId, $ukerId, $segmentasi)
            ->where('tanggal', $tanggal)
            ->whereNotNull('segmentasi')
            ->where('segmentasi', '!=', '')
            ->groupBy('segmentasi', 'produk')
            ->selectRaw('segmentasi, produk, SUM(saldo) as total')
            ->get()
            ->groupBy('segmentasi');

        return $rows->map(function (Collection $produk, string $nama) {
            $map = $produk->pluck('total', 'produk')->map(fn ($nilai) => (float) $nilai);
            $tabungan = (float) ($map[Simpanan::PRODUK_TABUNGAN] ?? 0);
            $giro = (float) ($map[Simpanan::PRODUK_GIRO] ?? 0);
            $deposito = (float) ($map[Simpanan::PRODUK_DEPOSITO] ?? 0);

            return [
                'nama' => $nama,
                'total_dpk' => Satuan::toJuta($tabungan + $giro + $deposito),
                'tabungan' => Satuan::toJuta($tabungan),
                'giro' => Satuan::toJuta($giro),
                'deposito' => Satuan::toJuta($deposito),
                'casa' => Satuan::toJuta($tabungan + $giro),
            ];
        })->sortBy(fn (array $row) => mb_strtolower($row['nama']))->values()->all();
    }

    /**
     * Turunkan 5 angka kartu dari saldo per produk.
     *
     * @param  array<string, float>  $perProduk
     * @return array<string, float>
     */
    private function agregatKartu(array $perProduk): array
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
}
