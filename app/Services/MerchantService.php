<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Region;
use App\Models\Uker;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Satuan;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Basis bersama dashboard Merchant (EDC & QRIS).
 *
 * EDC dan QRIS berbagi SELURUH logika — bedanya hanya tabel sumbernya dan
 * KATALOG KPI-nya. Katalog itu satu-satunya sumber urutan tampil, label, dan
 * perilaku tiap KPI; subклас (EdcService / QrisService) hanya mendefinisikan
 * `const KPI` plus query modelnya.
 *
 * Tiap entri KPI: kode kanonik, label tampilan, dan empat flag —
 *   flow    : nilai = akumulasi 1 Jan s/d tanggal (YTD). false = STOK (posisi).
 *   target  : punya RKA -> pencapaian & gap dirender.
 *   rupiah  : nilai uang -> dikonversi ke juta. false = hitungan apa adanya.
 *   inverse : makin kecil makin baik (mis. EDC SV Rp.0).
 *
 * INTI DOMAIN — dua semantik nilai:
 *   STOK : nilai pada tanggal = SUM(actual) pada tanggal itu.
 *   FLOW : data sumber sudah marginal -> nilai = SUM(actual) 1 Jan s/d tanggal.
 */
abstract class MerchantService
{
    use MenyaringOrganisasi;

    public const EXCLUDED_REGION_ID = Region::OFFICE_ID;

    /** @var array<int, string> */
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /** Query tabel aktual (edc / qris). */
    abstract protected function baseQuery(): Builder;

    /** Query tabel target (rka_edc / rka_qris). */
    abstract protected function rkaQuery(): Builder;

    /**
     * Katalog KPI domain ini (dari const KPI subклас).
     *
     * @return list<array<string, mixed>>
     */
    public function kpiList(): array
    {
        return static::KPI;
    }

    /**
     * Normalkan nama KPI mentah dari berkas ke KODE KANONIK, atau null bila tak
     * dikenal. Dipakai importer: nama di berkas sumber sering beda spasi/kapital
     * ("EDC SV Rp.0" vs "edc sv rp 0" vs "EDC SV RP0").
     */
    public static function kanonikKpi(string $raw): ?string
    {
        $norm = self::normalKpi($raw);

        foreach (static::KPI as $entri) {
            foreach ([$entri['kode'], $entri['label'], ...($entri['alias'] ?? [])] as $kandidat) {
                if (self::normalKpi($kandidat) === $norm) {
                    return $entri['kode'];
                }
            }
        }

        return null;
    }

    private static function normalKpi(string $teks): string
    {
        return preg_replace('/[^a-z0-9]/', '', mb_strtolower(trim($teks)));
    }

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(?int $areaId, ?int $cabangId): array
    {
        return [
            'area' => Area::query()->orderBy('nama')->get(['id', 'nama'])->toArray(),
            'cabang' => $this->cabangPerArea($areaId),
            'uker' => $cabangId === null ? [] : $this->ukerPerCabang($cabangId),
            'kpi' => array_map(fn (array $e) => [
                'kode' => $e['kode'],
                'label' => $e['label'],
                'flow' => $e['flow'],
                'target' => $e['target'],
                'rupiah' => $e['rupiah'],
                'inverse' => $e['inverse'],
            ], $this->kpiList()),
            'tanggal_maks' => $this->tanggalTerakhir(),
            'tanggal_min' => $this->baseQuery()->min('tanggal'),
        ];
    }

    public function tanggalTerakhir(): ?string
    {
        $tanggal = $this->baseQuery()->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * Kartu KPI: satu kartu per entri katalog, dengan nilai stok/flow, delta,
     * dan (khusus KPI ber-target) pencapaian & gap.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $p = $posisi->toDateString();

        $referensi = [
            'dtd' => $this->tanggalTersedia($posisi->copy()->subDay(), $areaId, $cabangId, $ukerId),
            'mtd' => $this->tanggalTersedia($posisi->copy()->subMonthNoOverflow()->endOfMonth(), $areaId, $cabangId, $ukerId),
            'ytd' => $this->tanggalTersedia($posisi->copy()->subYear()->endOfYear(), $areaId, $cabangId, $ukerId),
            'yoy' => $this->tanggalTersedia($posisi->copy()->subYear(), $areaId, $cabangId, $ukerId),
        ];

        $tanggalDibaca = collect($referensi)->push($p)->filter()->unique()->values()->all();

        $stok = $this->stokPerTanggal($tanggalDibaca, $this->kodeFlow(false), $areaId, $cabangId, $ukerId);
        $flow = $this->flowPerTanggal($tanggalDibaca, $this->kodeFlow(true), $areaId, $cabangId, $ukerId);
        $target = $this->targetPerKpi($posisi->year, $posisi->month, $areaId, $cabangId, $ukerId);

        $kartu = [];

        foreach ($this->kpiList() as $entri) {
            $isFlow = $entri['flow'];
            $rupiah = $entri['rupiah'];
            $punyaTarget = $entri['target'];

            $aktual = $this->nilaiKpi($isFlow, $p, $entri['kode'], $stok, $flow);

            $labelDelta = $this->labelDelta($isFlow);
            $delta = [];

            foreach ($labelDelta as $ld) {
                $ref = $referensi[$ld['key']];
                $pembanding = $ref === null ? null : $this->nilaiKpi($isFlow, $ref, $entri['kode'], $stok, $flow);
                $delta[$ld['key']] = $this->delta($aktual, $pembanding, $rupiah);
            }

            $rka = (float) ($target[$entri['kode']] ?? 0);

            $kartu[] = [
                'kode' => $entri['kode'],
                'judul' => $entri['label'],
                'nilai' => $this->tampil($aktual, $rupiah),
                'rupiah' => $rupiah,
                'inverse' => $entri['inverse'],
                'flow' => $isFlow,
                'delta' => $delta,
                'label_delta' => $labelDelta,
                'punya_target' => $punyaTarget,
                'target' => $punyaTarget ? $this->tampil($rka, $rupiah) : null,
                'pencapaian' => ($punyaTarget && $rka > 0) ? round($aktual / $rka * 100, 2) : null,
                'gap' => $punyaTarget ? $this->tampil($aktual - $rka, $rupiah) : null,
            ];
        }

        return [
            'tanggal' => $p,
            'tanggal_referensi' => $referensi,
            'kartu' => $kartu,
        ];
    }

    /**
     * Tren harian satu KPI. KPI flow digambar sebagai garis KUMULATIF YTD
     * (running sum dari data marginal); KPI stok digambar apa adanya per hari.
     *
     * @return array<string, mixed>
     */
    public function chart(string $tanggal, string $kpi, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $entri = $this->kpiByKode($kpi);
        $posisi = Carbon::parse($tanggal)->startOfDay();

        $harian = $this->dasar($areaId, $cabangId, $ukerId)
            ->where('kpi', $entri['kode'])
            ->whereBetween('tanggal', [$posisi->copy()->startOfYear()->toDateString(), $posisi->toDateString()])
            ->groupBy('tanggal')
            ->orderBy('tanggal')
            ->selectRaw('tanggal, SUM(actual) as total')
            ->pluck('total', 'tanggal');

        if ($entri['flow']) {
            // Data marginal -> akumulasi berjalan supaya garisnya kumulatif YTD.
            $jalan = 0.0;
            $harian = collect($harian)->map(function ($v) use (&$jalan) {
                $jalan += (float) $v;

                return $jalan;
            });
        }

        return [
            'tahun' => $posisi->year,
            'kpi' => $entri['kode'],
            'label' => $entri['label'],
            'rupiah' => $entri['rupiah'],
            'flow' => $entri['flow'],
            'seri' => $this->perBulan(collect($harian), $entri['rupiah']),
        ];
    }

    /**
     * Tabel kinerja cabang/uker untuk satu KPI (stok = posisi, flow = YTD).
     *
     * @return array<string, mixed>
     */
    public function branchPencapaian(string $tanggal, string $kpi, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $entri = $this->kpiByKode($kpi);
        $rupiah = $entri['rupiah'];
        $punyaTarget = $entri['target'];
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';

        $query = $this->dasar($areaId, $cabangId, $ukerId)->where('kpi', $entri['kode']);

        if ($entri['flow']) {
            $query->whereBetween('tanggal', [$posisi->copy()->startOfYear()->toDateString(), $posisi->toDateString()]);
        } else {
            $query->where('tanggal', $posisi->toDateString());
        }

        $aktual = $query
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(actual) as total")
            ->pluck('total', 'entitas_id');

        $target = collect();

        if ($punyaTarget) {
            $target = $this->filterOrganisasi(
                $this->rkaQuery()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID)->where('kpi', $entri['kode']),
                $perUker ? null : $areaId,
                $cabangId,
                $ukerId,
            )
                ->where('tahun', $posisi->year)
                ->where('bulan', $posisi->month)
                ->groupBy($kolom)
                ->selectRaw("{$kolom} as entitas_id, SUM(target) as total")
                ->pluck('total', 'entitas_id');
        }

        $nama = $perUker
            ? Uker::query()->whereIn('id', $aktual->keys())->pluck('nama', 'id')
            : Cabang::query()->whereIn('id', $aktual->keys())->pluck('nama', 'id');

        $baris = $aktual->map(function ($total, $entitasId) use ($target, $nama, $rupiah, $punyaTarget) {
            $nilai = (float) $total;
            $rka = (float) ($target[$entitasId] ?? 0);

            return [
                'id' => (int) $entitasId,
                'nama' => $nama[$entitasId] ?? (string) $entitasId,
                'nilai' => $this->tampil($nilai, $rupiah),
                'target' => $punyaTarget ? $this->tampil($rka, $rupiah) : null,
                'pencapaian' => ($punyaTarget && $rka > 0) ? round($nilai / $rka * 100, 2) : null,
                'gap' => $punyaTarget ? $this->tampil($nilai - $rka, $rupiah) : null,
            ];
        })->values()->sortByDesc('nilai')->values()->all();

        return [
            'tanggal' => $posisi->toDateString(),
            'kpi' => $entri['kode'],
            'rupiah' => $rupiah,
            'inverse' => $entri['inverse'],
            'punya_target' => $punyaTarget,
            'grouping' => $perUker ? 'uker' : 'cabang',
            'baris' => $baris,
        ];
    }

    // ---------------------------------------------------------------------

    private function dasar(?int $areaId, ?int $cabangId, ?int $ukerId): Builder
    {
        return $this->filterOrganisasi(
            $this->baseQuery()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        );
    }

    private function tanggalTersedia(Carbon $batas, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        $tanggal = $this->dasar($areaId, $cabangId, $ukerId)
            ->where('tanggal', '<=', $batas->toDateString())
            ->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    /**
     * STOK per (tanggal, kpi) untuk sekumpulan tanggal — SUM pada tanggal itu.
     *
     * @param  list<string>  $tanggal
     * @param  list<string>  $kodes
     * @return array<string, array<string, float>>
     */
    private function stokPerTanggal(array $tanggal, array $kodes, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        if ($tanggal === [] || $kodes === []) {
            return [];
        }

        return $this->dasar($areaId, $cabangId, $ukerId)
            ->whereIn('tanggal', $tanggal)
            ->whereIn('kpi', $kodes)
            ->groupBy('tanggal', 'kpi')
            ->selectRaw('tanggal, kpi, SUM(actual) as total')
            ->get()
            ->groupBy(fn ($r) => Carbon::parse($r->tanggal)->toDateString())
            ->map(fn (Collection $rows) => $rows->pluck('total', 'kpi')->map(fn ($v) => (float) $v)->all())
            ->all();
    }

    /**
     * FLOW per (tanggal, kpi): akumulasi 1 Jan (tahun tanggal itu) s/d tanggal.
     *
     * @param  list<string>  $tanggal
     * @param  list<string>  $kodes
     * @return array<string, array<string, float>>
     */
    private function flowPerTanggal(array $tanggal, array $kodes, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        if ($kodes === []) {
            return [];
        }

        $hasil = [];

        foreach ($tanggal as $t) {
            $awalTahun = Carbon::parse($t)->startOfYear()->toDateString();

            $hasil[$t] = $this->dasar($areaId, $cabangId, $ukerId)
                ->whereIn('kpi', $kodes)
                ->whereBetween('tanggal', [$awalTahun, $t])
                ->groupBy('kpi')
                ->selectRaw('kpi, SUM(actual) as total')
                ->pluck('total', 'kpi')
                ->map(fn ($v) => (float) $v)
                ->all();
        }

        return $hasil;
    }

    /**
     * @return array<string, float>
     */
    private function targetPerKpi(int $tahun, int $bulan, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        return $this->filterOrganisasi(
            $this->rkaQuery()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $areaId,
            $cabangId,
            $ukerId,
        )
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->groupBy('kpi')
            ->selectRaw('kpi, SUM(target) as total')
            ->pluck('total', 'kpi')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * @param  array<string, array<string, float>>  $stok
     * @param  array<string, array<string, float>>  $flow
     */
    private function nilaiKpi(bool $isFlow, string $tanggal, string $kode, array $stok, array $flow): float
    {
        $ember = $isFlow ? ($flow[$tanggal] ?? []) : ($stok[$tanggal] ?? []);

        return (float) ($ember[$kode] ?? 0);
    }

    /**
     * Delta yang menghormati satuan: KPI rupiah dikonversi ke juta, KPI hitungan
     * dibiarkan apa adanya.
     *
     * @return array{nilai: float|null, persen: float|null}
     */
    private function delta(float $aktual, ?float $pembanding, bool $rupiah): array
    {
        if ($pembanding === null) {
            return ['nilai' => null, 'persen' => null];
        }

        $selisih = $aktual - $pembanding;

        return [
            'nilai' => $rupiah ? Satuan::toJuta($selisih) : $selisih,
            'persen' => $pembanding == 0.0 ? null : round($selisih / abs($pembanding) * 100, 2),
        ];
    }

    private function tampil(float $nilai, bool $rupiah): float
    {
        return $rupiah ? (float) Satuan::toJuta($nilai) : $nilai;
    }

    /**
     * Susunan kolom delta: STOK punya YTD, FLOW tidak (YTD flow = angka kartu
     * itu sendiri, jadi mubazir).
     *
     * @return list<array{key: string, label: string}>
     */
    private function labelDelta(bool $isFlow): array
    {
        $baris = [
            ['key' => 'dtd', 'label' => 'D-1'],
            ['key' => 'mtd', 'label' => 'MTD'],
        ];

        if (! $isFlow) {
            $baris[] = ['key' => 'ytd', 'label' => 'YTD'];
        }

        $baris[] = ['key' => 'yoy', 'label' => 'YoY'];

        return $baris;
    }

    /**
     * @return list<string>
     */
    private function kodeFlow(bool $flow): array
    {
        return array_values(array_map(
            fn (array $e) => $e['kode'],
            array_filter($this->kpiList(), fn (array $e) => $e['flow'] === $flow),
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function kpiByKode(string $kode): array
    {
        foreach ($this->kpiList() as $entri) {
            if ($entri['kode'] === $kode) {
                return $entri;
            }
        }

        // KPI tak dikenal -> pakai yang pertama supaya endpoint tetap membalas.
        return $this->kpiList()[0];
    }

    /**
     * Deret harian -> satu seri per bulan (bulan diturunkan di PHP, portable).
     *
     * @param  Collection<string, mixed>  $harian
     * @return list<array<string, mixed>>
     */
    private function perBulan(Collection $harian, bool $rupiah): array
    {
        return $harian
            ->mapWithKeys(fn ($total, $tgl) => [Carbon::parse($tgl)->toDateString() => $total])
            ->groupBy(fn ($total, string $tgl) => (int) Carbon::parse($tgl)->month, preserveKeys: true)
            ->map(fn (Collection $bulanan, int $bulan) => [
                'bulan' => $bulan,
                'nama' => self::NAMA_BULAN[$bulan],
                'titik' => $bulanan->map(fn ($total, string $tgl) => [
                    'tanggal' => $tgl,
                    'hari' => (int) Carbon::parse($tgl)->day,
                    'nilai' => $rupiah ? Satuan::toJuta($total) : (float) $total,
                ])->values()->all(),
            ])
            ->sortKeys()
            ->values()
            ->all();
    }
}
