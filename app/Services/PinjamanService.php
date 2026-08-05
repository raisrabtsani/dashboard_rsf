<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Pinjaman;
use App\Models\Region;
use App\Models\RkaPinjaman;
use App\Models\Uker;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Delta;
use App\Support\Satuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Seluruh query & kalkulasi dashboard Pinjaman (Kredit).
 *
 * Mengikuti pola SimpananService (lihat CLAUDE.md §4), dengan tiga perbedaan:
 *
 *  1. Nilainya `baki_debet`, berdimensi KUALITAS (Lancar/SML/NPL).
 *     Tab yang aktif menentukan angka mana yang ditampilkan:
 *     Total = Lancar+SML+NPL (OS), SML = SML saja, NPL = NPL saja.
 *  2. Tab SML & NPL mengganti kolom YoY dengan MoM ("Date to Date").
 *  3. Rollup Region 855 IKUT dihitung (segmen Menengah dikelola level Region),
 *     berbeda dari Simpanan yang mengecualikannya.
 */
class PinjamanService
{
    use MenyaringOrganisasi;

    public const TAB_TOTAL = 'total';

    public const TAB_SML = 'sml';

    public const TAB_NPL = 'npl';

    /** @var list<string> */
    public const TAB = [self::TAB_TOTAL, self::TAB_SML, self::TAB_NPL];

    /**
     * Tab yang arah pencapaiannya TERBALIK: makin kecil makin baik.
     *
     * @var list<string>
     */
    public const TAB_INVERSE = [self::TAB_SML, self::TAB_NPL];

    /**
     * Rollup Region Office DIIKUTKAN di angka Pinjaman.
     *
     * Segmen Menengah dikelola di level Region dengan cabang_id 855, jadi
     * mengecualikannya akan membuat total OS kurang sebesar porsi Menengah.
     * Konsekuensi yang disengaja: jumlah baris tabel Kinerja Cabang lebih kecil
     * dari kartu Total, karena 855 tetap disembunyikan dari daftar cabang.
     */
    public const ROLLUP_REGION_ID = Region::OFFICE_ID;

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(?int $areaId, ?int $cabangId): array
    {
        return [
            'area' => Area::query()->orderBy('nama')->get(['id', 'nama'])->toArray(),
            'cabang' => $this->cabangPerArea($areaId),
            'uker' => $cabangId === null ? [] : $this->ukerPerCabang($cabangId),
            'segmen' => Pinjaman::query()->distinct()->orderBy('segmen')->pluck('segmen')->all(),
            'tanggal_maks' => $this->tanggalTerakhir(),
            'tanggal_min' => Pinjaman::query()->min('tanggal'),
        ];
    }

    public function tanggalTerakhir(): ?string
    {
        $tanggal = Pinjaman::query()->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * Tanggal data terakhir yang tersedia pada atau sebelum $tanggal, dalam
     * lingkup filter. Dipakai halaman Ringkasan; memakai TAB_TOTAL supaya
     * mencakup semua kualitas (OS penuh). Lihat SimpananService.
     */
    public function tanggalTersediaHingga(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        return $this->tanggalTersedia(Carbon::parse($tanggal), self::TAB_TOTAL, $areaId, $cabangId, $ukerId);
    }

    /**
     * Kartu KPI per segmen + Total, sesuai tab yang aktif.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tab = $this->tab($tab);
        $posisi = Carbon::parse($tanggal)->startOfDay();

        $referensi = $this->tanggalReferensi($posisi, $tab, $areaId, $cabangId, $ukerId);

        $dibaca = collect($referensi)->push($posisi->toDateString())->filter()->unique()->values();
        $nilai = $this->nilaiPerTanggalSegmen($dibaca->all(), $tab, $areaId, $cabangId, $ukerId);
        $target = $this->targetPerSegmen($posisi->year, $posisi->month, $tab, $areaId, $cabangId, $ukerId);

        $segmen = $this->segmenTersedia($nilai, $target);
        $posisiNilai = $nilai[$posisi->toDateString()] ?? [];

        $kartu = [];

        foreach ([...$segmen, 'total'] as $key) {
            $aktual = $key === 'total' ? array_sum($posisiNilai) : (float) ($posisiNilai[$key] ?? 0);
            $rka = $key === 'total' ? array_sum($target) : (float) ($target[$key] ?? 0);

            $delta = [];

            foreach ($referensi as $jenis => $tanggalRef) {
                $baris = $tanggalRef === null ? null : ($nilai[$tanggalRef] ?? []);
                $pembanding = $baris === null
                    ? null
                    : ($key === 'total' ? array_sum($baris) : (float) ($baris[$key] ?? 0));

                $delta[$jenis] = Delta::hitung($aktual, $pembanding);
            }

            $kartu[] = [
                'key' => $key,
                'judul' => $key === 'total' ? $this->judulTotal($tab) : $key,
                'nilai' => Satuan::toJuta($aktual),
                'delta' => $delta,
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($aktual / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($aktual - $rka),
            ];
        }

        return [
            'tanggal' => $posisi->toDateString(),
            'tab' => $tab,
            'inverse' => in_array($tab, self::TAB_INVERSE, true),
            // Label kolom delta terakhir berubah per tab; frontend memakainya apa adanya.
            'label_delta' => $this->labelDelta($tab),
            'tanggal_referensi' => $referensi,
            'kartu' => $kartu,
        ];
    }

    /**
     * Tren harian nilai tab aktif, dipecah per bulan (pola sama dengan Simpanan).
     *
     * @return array<string, mixed>
     */
    public function chart(string $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tab = $this->tab($tab);
        $posisi = Carbon::parse($tanggal)->startOfDay();

        $harian = $this->dasar($tab, $areaId, $cabangId, $ukerId)
            ->whereBetween('tanggal', [$posisi->copy()->startOfYear()->toDateString(), $posisi->toDateString()])
            ->groupBy('tanggal')
            ->orderBy('tanggal')
            ->selectRaw('tanggal, SUM(baki_debet) as total')
            ->pluck('total', 'tanggal');

        return [
            'tahun' => $posisi->year,
            'tab' => $tab,
            'seri' => $this->perBulan($harian),
        ];
    }

    /**
     * ENDPOINT KHUSUS PINJAMAN: tren harian dipecah PER SEGMEN (bukan per bulan).
     *
     * @return array<string, mixed>
     */
    public function chartSegmen(string $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tab = $this->tab($tab);
        $posisi = Carbon::parse($tanggal)->startOfDay();

        $baris = $this->dasar($tab, $areaId, $cabangId, $ukerId)
            ->whereBetween('tanggal', [$posisi->copy()->startOfMonth()->toDateString(), $posisi->toDateString()])
            ->groupBy('tanggal', 'segmen')
            ->orderBy('tanggal')
            ->selectRaw('tanggal, segmen, SUM(baki_debet) as total')
            ->get();

        $tanggalUnik = $baris->map(fn ($r) => Carbon::parse($r->tanggal)->toDateString())->unique()->sort()->values();

        $seri = $baris
            ->groupBy('segmen')
            ->map(function (Collection $rows, string $segmen) use ($tanggalUnik) {
                $perTanggal = $rows->mapWithKeys(
                    fn ($r) => [Carbon::parse($r->tanggal)->toDateString() => (float) $r->total],
                );

                return [
                    'segmen' => $segmen,
                    // Tanggal tanpa data tetap null, bukan 0 — garis terputus,
                    // tidak jatuh ke sumbu.
                    'titik' => $tanggalUnik->map(fn (string $t) => [
                        'tanggal' => $t,
                        'nilai' => isset($perTanggal[$t]) ? Satuan::toJuta($perTanggal[$t]) : null,
                    ])->all(),
                ];
            })
            ->sortKeys()
            ->values()
            ->all();

        return [
            'tab' => $tab,
            'tanggal' => $tanggalUnik->all(),
            'seri' => $seri,
        ];
    }

    /**
     * ENDPOINT KHUSUS PINJAMAN: rincian per segmen dengan pecahan kualitas.
     *
     * Selalu menampilkan ketiga kualitas + OS + %NPL, apa pun tab aktifnya —
     * gunanya justru untuk melihat komposisinya sekaligus.
     *
     * @return array<string, mixed>
     */
    public function produk(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();

        $baris = $this->dasarSemua($areaId, $cabangId, $ukerId)
            ->where('tanggal', $posisi->toDateString())
            ->groupBy('segmen', 'kualitas')
            ->selectRaw('segmen, kualitas, SUM(baki_debet) as total')
            ->get()
            ->groupBy('segmen');

        $hasil = $baris->map(function (Collection $rows, string $segmen) {
            $per = $rows->pluck('total', 'kualitas')->map(fn ($v) => (float) $v);

            $lancar = (float) ($per[Pinjaman::KUALITAS_LANCAR] ?? 0);
            $sml = (float) ($per[Pinjaman::KUALITAS_SML] ?? 0);
            $npl = (float) ($per[Pinjaman::KUALITAS_NPL] ?? 0);
            $os = $lancar + $sml + $npl;

            return [
                'segmen' => $segmen,
                'lancar' => Satuan::toJuta($lancar),
                'sml' => Satuan::toJuta($sml),
                'npl' => Satuan::toJuta($npl),
                'os' => Satuan::toJuta($os),
                // Rasio, bukan nilai uang — jangan dikonversi ke juta.
                'pct_npl' => $os > 0 ? round($npl / $os * 100, 2) : null,
                'pct_sml' => $os > 0 ? round($sml / $os * 100, 2) : null,
            ];
        })->values()->sortByDesc('os')->values()->all();

        return ['tanggal' => $posisi->toDateString(), 'baris' => $hasil];
    }

    /**
     * Tabel kinerja per cabang; dengan cabang_id, grouping pindah ke per-uker.
     *
     * @return array<string, mixed>
     */
    public function branchPencapaian(string $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tab = $this->tab($tab);
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';

        $aktual = $this->dasar($tab, $areaId, $cabangId, $ukerId)
            // 855 disembunyikan dari BARIS tabel walau ikut di kartu Total.
            ->when(! $perUker, fn (Builder $q) => $q->where('cabang_id', '!=', self::ROLLUP_REGION_ID))
            ->where('tanggal', $posisi->toDateString())
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(baki_debet) as total")
            ->pluck('total', 'entitas_id');

        $target = $this->filterOrganisasi(
            RkaPinjaman::query()->whereIn('kualitas', $this->kualitasTab($tab)),
            $perUker ? null : $areaId,
            $cabangId,
            $ukerId,
        )
            ->when(! $perUker, fn (Builder $q) => $q->where('cabang_id', '!=', self::ROLLUP_REGION_ID))
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
            'tab' => $tab,
            'inverse' => in_array($tab, self::TAB_INVERSE, true),
            'grouping' => $perUker ? 'uker' : 'cabang',
            'baris' => $baris,
        ];
    }

    // ---------------------------------------------------------------------

    /**
     * Tanggal pembanding tiap jenis delta.
     *
     * Tab Total memakai D-1/MTD/YTD/YoY. Tab SML & NPL mengganti YoY dengan MoM
     * ("Date to Date"): tanggal posisi dibanding tanggal yang SAMA bulan lalu.
     *
     * @return array<string, string|null>
     */
    private function tanggalReferensi(Carbon $posisi, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $referensi = [
            'dtd' => $this->tanggalTersedia($posisi->copy()->subDay(), $tab, $areaId, $cabangId, $ukerId),
            'mtd' => $this->tanggalTersedia($posisi->copy()->subMonthNoOverflow()->endOfMonth(), $tab, $areaId, $cabangId, $ukerId),
            'ytd' => $this->tanggalTersedia($posisi->copy()->subYear()->endOfYear(), $tab, $areaId, $cabangId, $ukerId),
        ];

        $referensi[$this->kunciDeltaTerakhir($tab)] = in_array($tab, self::TAB_INVERSE, true)
            ? $this->tanggalMom($posisi, $tab, $areaId, $cabangId, $ukerId)
            : $this->tanggalTersedia($posisi->copy()->subYear(), $tab, $areaId, $cabangId, $ukerId);

        return $referensi;
    }

    /**
     * MoM "Date to Date": tanggal yang sama bulan lalu (18 Jun -> 18 Mei).
     *
     * subMonthNoOverflow supaya 31 Mar tidak melompat ke 2/3 Mar. Kalau tanggal
     * itu tidak ada datanya (akhir pekan/libur), fallback ke tanggal TERAKHIR
     * yang tersedia di bulan tersebut — bukan ke tanggal mana pun sebelumnya,
     * supaya perbandingannya tetap antar-bulan yang benar.
     */
    private function tanggalMom(Carbon $posisi, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        $target = $posisi->copy()->subMonthNoOverflow();

        $persis = $this->dasar($tab, $areaId, $cabangId, $ukerId)
            ->where('tanggal', $target->toDateString())
            ->exists();

        if ($persis) {
            return $target->toDateString();
        }

        $fallback = $this->dasar($tab, $areaId, $cabangId, $ukerId)
            ->whereBetween('tanggal', [
                $target->copy()->startOfMonth()->toDateString(),
                $target->copy()->endOfMonth()->toDateString(),
            ])
            ->max('tanggal');

        return $fallback === null ? null : Carbon::parse($fallback)->toDateString();
    }

    private function tanggalTersedia(Carbon $batas, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        $tanggal = $this->dasar($tab, $areaId, $cabangId, $ukerId)
            ->where('tanggal', '<=', $batas->toDateString())
            ->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * Query dasar TERBATAS pada kualitas yang relevan dengan tab.
     */
    private function dasar(string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): Builder
    {
        return $this->dasarSemua($areaId, $cabangId, $ukerId)
            ->whereIn('kualitas', $this->kualitasTab($tab));
    }

    /**
     * Query dasar tanpa penyaringan kualitas (dipakai tabel rincian per produk).
     */
    private function dasarSemua(?int $areaId, ?int $cabangId, ?int $ukerId): Builder
    {
        // Tidak ada pengecualian 855: segmen Menengah dikelola level Region.
        return $this->filterOrganisasi(Pinjaman::query(), $areaId, $cabangId, $ukerId);
    }

    /**
     * @return list<string>
     */
    private function kualitasTab(string $tab): array
    {
        return match ($tab) {
            self::TAB_SML => [Pinjaman::KUALITAS_SML],
            self::TAB_NPL => [Pinjaman::KUALITAS_NPL],
            default => Pinjaman::KUALITAS,
        };
    }

    private function tab(string $tab): string
    {
        $tab = strtolower(trim($tab));

        return in_array($tab, self::TAB, true) ? $tab : self::TAB_TOTAL;
    }

    private function judulTotal(string $tab): string
    {
        return match ($tab) {
            self::TAB_SML => 'Total SML',
            self::TAB_NPL => 'Total NPL',
            default => 'Total OS',
        };
    }

    private function kunciDeltaTerakhir(string $tab): string
    {
        return in_array($tab, self::TAB_INVERSE, true) ? 'mom' : 'yoy';
    }

    /**
     * Label kolom delta untuk KpiCard; urutannya = urutan tampil.
     *
     * @return list<array{key: string, label: string}>
     */
    private function labelDelta(string $tab): array
    {
        return [
            ['key' => 'dtd', 'label' => 'D-1'],
            ['key' => 'mtd', 'label' => 'MTD'],
            ['key' => 'ytd', 'label' => 'YTD'],
            in_array($tab, self::TAB_INVERSE, true)
                ? ['key' => 'mom', 'label' => 'Date to Date']
                : ['key' => 'yoy', 'label' => 'YoY'],
        ];
    }

    /**
     * @param  list<string>  $tanggal
     * @return array<string, array<string, float>>
     */
    private function nilaiPerTanggalSegmen(array $tanggal, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        if ($tanggal === []) {
            return [];
        }

        return $this->dasar($tab, $areaId, $cabangId, $ukerId)
            ->whereIn('tanggal', $tanggal)
            ->groupBy('tanggal', 'segmen')
            ->selectRaw('tanggal, segmen, SUM(baki_debet) as total')
            ->get()
            ->groupBy(fn ($row) => Carbon::parse($row->tanggal)->toDateString())
            ->map(fn (Collection $rows) => $rows->pluck('total', 'segmen')->map(fn ($v) => (float) $v)->all())
            ->all();
    }

    /**
     * @return array<string, float>
     */
    private function targetPerSegmen(int $tahun, int $bulan, string $tab, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        return $this->filterOrganisasi(
            RkaPinjaman::query()->whereIn('kualitas', $this->kualitasTab($tab)),
            $areaId,
            $cabangId,
            $ukerId,
        )
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->groupBy('segmen')
            ->selectRaw('segmen, SUM(target) as total')
            ->pluck('total', 'segmen')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Segmen yang muncul di data ATAU di target, supaya segmen bertarget tapi
     * belum ada realisasinya tetap tampil (pencapaian 0%, bukan hilang).
     *
     * @param  array<string, array<string, float>>  $nilai
     * @param  array<string, float>  $target
     * @return list<string>
     */
    private function segmenTersedia(array $nilai, array $target): array
    {
        $dariNilai = collect($nilai)->flatMap(fn (array $per) => array_keys($per));

        return $dariNilai
            ->merge(array_keys($target))
            ->unique()
            ->sortBy(fn (string $s) => array_search($s, Pinjaman::SEGMEN, true) === false
                ? PHP_INT_MAX
                : array_search($s, Pinjaman::SEGMEN, true))
            ->values()
            ->all();
    }

    /** @var array<int, string> */
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * Kelompokkan deret harian jadi satu seri per bulan.
     *
     * Bulan diturunkan DI PHP — query hanya group by tanggal (portable).
     *
     * @param  Collection<string, mixed>  $harian
     * @return list<array<string, mixed>>
     */
    private function perBulan(Collection $harian): array
    {
        return collect($harian)
            ->mapWithKeys(fn ($total, $tgl) => [Carbon::parse($tgl)->toDateString() => $total])
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
    }
}
