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
        return [
            'area' => Area::query()->orderBy('nama')->get(['id', 'nama'])->toArray(),
            'cabang' => $this->cabangPerArea($areaId),
            'uker' => $cabangId === null ? [] : $this->ukerPerCabang($cabangId),
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
    public function snapshot(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();

        // Tanggal pembanding di-resolve ke tanggal TERSEDIA terakhir <= target,
        // supaya akhir pekan / hari libur tidak bikin delta kosong.
        $referensi = [
            'dtd' => $this->tanggalTersedia($posisi->copy()->subDay(), $areaId, $cabangId, $ukerId),
            'mtd' => $this->tanggalTersedia($posisi->copy()->subMonthNoOverflow()->endOfMonth(), $areaId, $cabangId, $ukerId),
            'ytd' => $this->tanggalTersedia($posisi->copy()->subYear()->endOfYear(), $areaId, $cabangId, $ukerId),
            'yoy' => $this->tanggalTersedia($posisi->copy()->subYear(), $areaId, $cabangId, $ukerId),
        ];

        $tanggalDibaca = collect($referensi)->push($posisi->toDateString())->filter()->unique()->values();
        $saldo = $this->saldoPerTanggalProduk($tanggalDibaca->all(), $areaId, $cabangId, $ukerId);
        $target = $this->targetPerProduk($posisi->year, $posisi->month, $areaId, $cabangId, $ukerId);

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
    public function chart(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();

        $harian = $this->dasar($areaId, $cabangId, $ukerId)
            ->whereBetween('tanggal', [$posisi->copy()->startOfYear()->toDateString(), $posisi->toDateString()])
            ->groupBy('tanggal')
            ->orderBy('tanggal')
            ->selectRaw('tanggal, SUM(saldo) as total')
            ->pluck('total', 'tanggal');

        $seri = collect($harian)
            ->mapWithKeys(fn ($total, $tgl) => [Carbon::parse($tgl)->toDateString() => $total])
            // preserveKeys wajib: key-nya tanggal, dan masih dipakai di map di bawah.
            ->groupBy(fn ($total, string $tgl) => (int) Carbon::parse($tgl)->month, preserveKeys: true)
            ->map(fn (Collection $bulanan, int $bulan) => [
                'bulan' => $bulan,
                'nama' => self::NAMA_BULAN[$bulan],
                'titik' => $bulanan->map(fn ($total, string $tgl) => [
                    'tanggal' => $tgl,
                    'hari' => (int) Carbon::parse($tgl)->day,
                    'nilai' => Satuan::toJuta($total),
                ])->values()->all(),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return [
            'tahun' => $posisi->year,
            'seri' => $seri,
        ];
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
    public function branchPencapaian(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';

        $aktual = $this->dasar($areaId, $cabangId, $ukerId)
            ->where('tanggal', $posisi->toDateString())
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(saldo) as total")
            ->pluck('total', 'entitas_id');

        $target = RkaSimpanan::query()
            ->when(! $perUker, fn (Builder $q) => $q->where('cabang_id', '!=', self::EXCLUDED_REGION_ID))
            ->when($perUker, fn (Builder $q) => $q->where('cabang_id', $cabangId))
            ->when($ukerId !== null, fn (Builder $q) => $q->where('uker_id', $ukerId))
            ->when($areaId !== null && ! $perUker, fn (Builder $q) => $q->whereIn('cabang_id', $this->cabangDiArea($areaId)))
            ->where('tahun', $posisi->year)
            ->where('bulan', $posisi->month)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(target) as total")
            ->pluck('total', 'entitas_id');

        $nama = $perUker
            ? Uker::query()->whereIn('id', $aktual->keys())->pluck('nama', 'id')
            : Cabang::query()->whereIn('id', $aktual->keys())->pluck('nama', 'id');

        $baris = $aktual->map(function ($total, $entitasId) use ($target, $nama) {
            $rka = (float) ($target[$entitasId] ?? 0);
            $nilai = (float) $total;

            return [
                'id' => (int) $entitasId,
                'nama' => $nama[$entitasId] ?? (string) $entitasId,
                'nilai' => Satuan::toJuta($nilai),
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($nilai / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($nilai - $rka),
            ];
        })->values()->sortByDesc('nilai')->values()->all();

        return [
            'tanggal' => $posisi->toDateString(),
            'grouping' => $perUker ? 'uker' : 'cabang',
            'baris' => $baris,
        ];
    }

    /**
     * Query dasar dengan filter organisasi + pengecualian rollup 855.
     */
    private function dasar(?int $areaId, ?int $cabangId, ?int $ukerId): Builder
    {
        return $this->filterOrganisasi(
            Simpanan::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        );
    }

    /**
     * Tanggal data terakhir yang tersedia pada atau sebelum $batas.
     */
    private function tanggalTersedia(Carbon $batas, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        $tanggal = $this->dasar($areaId, $cabangId, $ukerId)
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
    private function saldoPerTanggalProduk(array $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        if ($tanggal === []) {
            return [];
        }

        return $this->dasar($areaId, $cabangId, $ukerId)
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
    private function targetPerProduk(int $tahun, int $bulan, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        return RkaSimpanan::query()
            ->where('cabang_id', '!=', self::EXCLUDED_REGION_ID)
            ->when($areaId !== null, fn (Builder $q) => $q->whereIn('cabang_id', $this->cabangDiArea($areaId)))
            ->when($cabangId !== null, fn (Builder $q) => $q->where('cabang_id', $cabangId))
            ->when($ukerId !== null, fn (Builder $q) => $q->where('uker_id', $ukerId))
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->groupBy('produk')
            ->selectRaw('produk, SUM(target) as total')
            ->pluck('total', 'produk')
            ->map(fn ($v) => (float) $v)
            ->all();
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
