<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Laba;
use App\Models\Region;
use App\Models\RkaLaba;
use App\Models\Uker;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Delta;
use App\Support\Satuan;
use Illuminate\Database\Eloquent\Builder;

/**
 * Seluruh query & kalkulasi dashboard Laba (profitabilitas).
 *
 * Mengikuti pola SimpananService (lihat CLAUDE.md §4), tetapi ini domain
 * BULANAN, bukan harian, dengan dua konsekuensi penting:
 *
 *  1. Periode = (tahun, bulan). Tidak ada kolom tanggal, tidak ada resolusi
 *     "tanggal tersedia terakhir".
 *  2. Nilai `laba` yang tersimpan KUMULATIF YTD. Laba bulan berjalan (MTD)
 *     diturunkan saat baca: laba(N) - laba(N-1). Untuk Januari, MTD = nilai
 *     Januari itu sendiri. Bila bulan N-1 tidak ada datanya, MTD = null (bukan 0).
 *
 * Kolom delta kartu: MTD, YTD (= nilai kartu itu sendiri), YoY (bulan sama tahun
 * lalu). TIDAK ada D-1.
 *
 * Rollup Region Office IKUT dihitung di kartu Total & chart (tampil sebagai
 * segmen "Region Office" tersendiri) — berbeda dari Simpanan yang mengecualikannya.
 * Hanya tabel "Kinerja Branch Office" yang tetap mengecualikan entitas rollup
 * Region, karena itu peringkat antar-cabang, bukan rollup regional.
 */
class LabaService
{
    use MenyaringOrganisasi;

    public const EXCLUDED_REGION_ID = Region::OFFICE_ID;

    /** @var array<int, string> */
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * @return array<string, mixed>
     */
    public function filterOptions(?int $areaId, ?int $cabangId): array
    {
        return [
            'area' => Area::query()->orderBy('nama')->get(['id', 'nama'])->toArray(),
            'cabang' => $this->cabangPerArea($areaId),
            'uker' => $cabangId === null ? [] : $this->ukerPerCabang($cabangId),
            'tahun' => Laba::query()->distinct()->orderByDesc('tahun')->pluck('tahun')->map(fn ($t) => (int) $t)->all(),
            'periode_terakhir' => $this->periodeTerakhir(),
        ];
    }

    /**
     * Periode (tahun, bulan) terakhir yang datanya tersedia.
     *
     * @return array{tahun: int, bulan: int}|null
     */
    public function periodeTerakhir(): ?array
    {
        $row = Laba::query()->orderByDesc('tahun')->orderByDesc('bulan')->first(['tahun', 'bulan']);

        return $row === null ? null : ['tahun' => (int) $row->tahun, 'bulan' => (int) $row->bulan];
    }

    /**
     * Periode (tahun, bulan) terakhir yang datanya tersedia pada atau sebelum
     * (tahun, bulan) target, DALAM lingkup filter.
     *
     * Laba sering telat rilis, jadi halaman Ringkasan mundur otomatis ke bulan
     * terakhir yang benar-benar ada agar kartunya tidak kosong hanya karena bulan
     * posisi belum diunggah. Null bila tak ada data <= periode target di lingkup.
     *
     * @return array{tahun: int, bulan: int}|null
     */
    public function periodeTersedia(int $tahun, int $bulan, ?int $areaId, ?int $cabangId, ?int $ukerId): ?array
    {
        $row = $this->dasar($areaId, $cabangId, $ukerId)
            ->where(function (Builder $q) use ($tahun, $bulan) {
                $q->where('tahun', '<', $tahun)
                    ->orWhere(fn (Builder $qq) => $qq->where('tahun', $tahun)->where('bulan', '<=', $bulan));
            })
            ->orderByDesc('tahun')
            ->orderByDesc('bulan')
            ->first(['tahun', 'bulan']);

        return $row === null ? null : ['tahun' => (int) $row->tahun, 'bulan' => (int) $row->bulan];
    }

    /**
     * Kartu KPI: Total + satu kartu per segmen. nilai = KUMULATIF YTD di periode.
     *
     * @return array<string, mixed>
     */
    public function snapshot(int $tahun, int $bulan, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisiKunci = $this->periodeKunci($tahun, $bulan);
        $prev = $bulan > 1 ? [$tahun, $bulan - 1] : null;
        $prevKunci = $prev === null ? null : $this->periodeKunci($prev[0], $prev[1]);
        $yoyKunci = $this->periodeKunci($tahun - 1, $bulan);

        // Ambil ketiga periode sekaligus (posisi, bulan sebelumnya, YoY).
        $nilai = $this->nilaiPeriodeSegmen([[$tahun, $bulan], $prev, [$tahun - 1, $bulan]], $areaId, $cabangId, $ukerId);
        $target = $this->targetPerSegmen($tahun, $bulan, $areaId, $cabangId, $ukerId);

        [$tahunBerikutnya, $bulanBerikutnya] = $bulan === 12
            ? [$tahun + 1, 1]
            : [$tahun, $bulan + 1];
        $targetBerikutnya = $this->targetPerSegmen(
            $tahunBerikutnya,
            $bulanBerikutnya,
            $areaId,
            $cabangId,
            $ukerId,
        );

        $adaPosisi = isset($nilai[$posisiKunci]);
        $posisiNilai = $nilai[$posisiKunci] ?? [];

        $segmen = $this->segmenTersedia($posisiNilai, $target);

        $kartu = [];

        foreach (['total', ...$segmen] as $key) {
            // Periode posisi tanpa data sama sekali = nilai TIDAK DIKETAHUI (null),
            // bukan 0 — laba 0 dan "tidak ada laporan" adalah hal berbeda.
            $aktual = ! $adaPosisi
                ? null
                : ($key === 'total' ? array_sum($posisiNilai) : (float) ($posisiNilai[$key] ?? 0));

            $rka = $key === 'total' ? array_sum($target) : (float) ($target[$key] ?? 0);
            $rkaBerikutnya = $targetBerikutnya === []
                ? null
                : ($key === 'total'
                    ? array_sum($targetBerikutnya)
                    : (float) ($targetBerikutnya[$key] ?? 0));

            $delta = [
                'mtd' => $this->deltaMtd($aktual, $bulan, $key, $bulan > 1 ? ($nilai[$prevKunci] ?? null) : null),
                // YTD = nilai kartu itu sendiri (kumulatif dari awal tahun).
                'ytd' => ['nilai' => Satuan::toJuta($aktual), 'persen' => null],
                'yoy' => $this->deltaPembanding($aktual, $key, $nilai[$yoyKunci] ?? null),
            ];

            $kartu[] = [
                'key' => $key,
                'judul' => $key === 'total' ? 'Total Laba' : $key,
                'nilai' => Satuan::toJuta($aktual),
                'delta' => $delta,
                'target' => Satuan::toJuta($rka),
                'target_berikutnya' => Satuan::toJuta($rkaBerikutnya),
                'target_berikutnya_tahun' => $tahunBerikutnya,
                'target_berikutnya_bulan' => $bulanBerikutnya,
                'pencapaian' => ($aktual !== null && $rka > 0) ? round($aktual / $rka * 100, 2) : null,
                'gap' => $aktual === null ? null : Satuan::toJuta($aktual - $rka),
            ];
        }

        return [
            'tahun' => $tahun,
            'bulan' => $bulan,
            // Kolom delta domain ini: MTD, YTD, YoY (tanpa D-1). Frontend memakainya apa adanya.
            'label_delta' => [
                ['key' => 'mtd', 'label' => 'MTD'],
                ['key' => 'ytd', 'label' => 'YTD'],
                ['key' => 'yoy', 'label' => 'YoY'],
            ],
            'kartu' => $kartu,
        ];
    }

    /**
     * CHART 1: garis KUMULATIF YTD sepanjang bulan pada satu tahun.
     *
     * Nilai tersimpan sudah kumulatif, jadi cukup di-plot apa adanya per bulan.
     *
     * @return array<string, mixed>
     */
    public function chart(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $segmen = null): array
    {
        $kumulatif = $this->kumulatifPerBulan($tahun, $areaId, $cabangId, $ukerId, $segmen);
        $target = $this->targetKumulatifPerBulan($tahun, $areaId, $cabangId, $ukerId, $segmen);
        $tahunLalu = $this->kumulatifPerBulan($tahun - 1, $areaId, $cabangId, $ukerId, $segmen);

        $mtd = $this->mtdPerBulan($kumulatif);
        $mtdTahunLalu = $this->mtdPerBulan($tahunLalu);

        $bulanTersedia = collect(array_keys($kumulatif))
            ->merge(array_keys($target))
            ->merge(array_keys($tahunLalu))
            ->unique()
            ->sort()
            ->values();

        $titik = $bulanTersedia
            ->map(fn (int $bulan) => [
                'bulan' => $bulan,
                'nama' => self::NAMA_BULAN[$bulan],
                'nilai' => array_key_exists($bulan, $kumulatif) ? Satuan::toJuta($kumulatif[$bulan]) : null,
                'target' => array_key_exists($bulan, $target) ? Satuan::toJuta($target[$bulan]) : null,
                'nilai_tahun_lalu' => array_key_exists($bulan, $tahunLalu) ? Satuan::toJuta($tahunLalu[$bulan]) : null,
                'mtd' => array_key_exists($bulan, $mtd) ? Satuan::toJuta($mtd[$bulan]) : null,
                'mtd_tahun_lalu' => array_key_exists($bulan, $mtdTahunLalu) ? Satuan::toJuta($mtdTahunLalu[$bulan]) : null,
            ])
            ->all();

        return [
            'tahun' => $tahun,
            'segmen' => $segmen,
            'titik' => $titik,
        ];
    }

    /**
     * CHART 2: batang laba PER BULAN (MTD), hasil selisih antar bulan kumulatif.
     *
     * Bulan tanpa data, atau yang bulan sebelumnya tak ada datanya, bernilai
     * null (batang kosong) — bukan 0.
     *
     * @return array<string, mixed>
     */
    public function chartMtd(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $segmen = null): array
    {
        $kumulatif = $this->kumulatifPerBulan($tahun, $areaId, $cabangId, $ukerId, $segmen);

        if ($kumulatif === []) {
            return ['tahun' => $tahun, 'segmen' => $segmen, 'titik' => []];
        }

        $mtd = $this->mtdPerBulan($kumulatif);
        $titik = [];

        for ($bulan = 1; $bulan <= max(array_keys($kumulatif)); $bulan++) {
            $titik[] = [
                'bulan' => $bulan,
                'nama' => self::NAMA_BULAN[$bulan],
                'nilai' => array_key_exists($bulan, $mtd) ? Satuan::toJuta($mtd[$bulan]) : null,
            ];
        }

        return ['tahun' => $tahun, 'segmen' => $segmen, 'titik' => $titik];
    }

    /**
     * Tabel kinerja per cabang; bila cabang_id dikirim, grouping pindah ke uker.
     * Membandingkan laba KUMULATIF YTD di periode terhadap target YTD.
     *
     * @return array<string, mixed>
     */
    public function branchPencapaian(int $tahun, int $bulan, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';

        [$prevTahun, $prevBulan] = $bulan === 1 ? [$tahun - 1, 12] : [$tahun, $bulan - 1];

        // Peringkat antar-cabang: rollup Region Office DIKECUALIKAN di sini
        // (walau ikut di kartu Total). dasar() sudah termasuk Region, jadi
        // disaring eksplisit per query.
        $tanpaRegion = fn (Builder $q) => $q->where('cabang_id', '!=', self::EXCLUDED_REGION_ID);

        $aktual = $tanpaRegion($this->dasar($areaId, $cabangId, $ukerId))
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(laba) as total")
            ->pluck('total', 'entitas_id');

        $bulanLalu = $tanpaRegion($this->dasar($areaId, $cabangId, $ukerId))
            ->where('tahun', $prevTahun)
            ->where('bulan', $prevBulan)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(laba) as total")
            ->pluck('total', 'entitas_id');

        $tahunLalu = $tanpaRegion($this->dasar($areaId, $cabangId, $ukerId))
            ->where('tahun', $tahun - 1)
            ->where('bulan', $bulan)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(laba) as total")
            ->pluck('total', 'entitas_id');

        $desemberTahunLalu = $tanpaRegion($this->dasar($areaId, $cabangId, $ukerId))
            ->where('tahun', $tahun - 1)
            ->where('bulan', 12)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(laba) as total")
            ->pluck('total', 'entitas_id');

        $target = $this->filterOrganisasi(
            RkaLaba::query()->where('cabang_id', '!=', self::EXCLUDED_REGION_ID),
            $perUker ? null : $areaId,
            $cabangId,
            $ukerId,
        )
            ->where('tahun', $tahun)
            ->where('bulan', $bulan)
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(target) as total")
            ->pluck('total', 'entitas_id');

        $ids = collect($aktual->keys())
            ->merge($target->keys())
            ->merge($tahunLalu->keys())
            ->merge($desemberTahunLalu->keys())
            ->unique()
            ->values();

        $entitas = $perUker
            ? Uker::query()->with('cabang.area')->whereIn('id', $ids)->get()->keyBy('id')
            : Cabang::query()->with('area')->whereIn('id', $ids)->get()->keyBy('id');

        $baris = $ids->map(function ($entitasId) use ($aktual, $bulanLalu, $tahunLalu, $desemberTahunLalu, $target, $entitas, $bulan) {
            $nilai = (float) ($aktual[$entitasId] ?? 0);
            $rka = (float) ($target[$entitasId] ?? 0);
            $prev = $bulan === 1 ? 0.0 : (isset($bulanLalu[$entitasId]) ? (float) $bulanLalu[$entitasId] : null);
            $yoyBase = isset($tahunLalu[$entitasId]) ? (float) $tahunLalu[$entitasId] : null;
            $ytdBase = isset($desemberTahunLalu[$entitasId]) ? (float) $desemberTahunLalu[$entitasId] : null;
            $model = $entitas[$entitasId] ?? null;

            return [
                'id' => (int) $entitasId,
                'nama' => $model?->nama ?? (string) $entitasId,
                'area_nama' => $model?->area?->nama ?? $model?->cabang?->area?->nama,
                'posisi_bulan_lalu' => $yoyBase === null ? null : Satuan::toJuta($yoyBase),
                'posisi_desember_lalu' => $ytdBase === null ? null : Satuan::toJuta($ytdBase),
                'nilai' => Satuan::toJuta($nilai),
                'target' => Satuan::toJuta($rka),
                'pencapaian' => $rka > 0 ? round($nilai / $rka * 100, 2) : null,
                'gap' => Satuan::toJuta($nilai - $rka),
                'mtd' => $prev === null ? null : Satuan::toJuta($nilai - $prev),
                'ytd' => Satuan::toJuta($nilai),
                'yoy' => $yoyBase === null ? null : Satuan::toJuta($nilai - $yoyBase),
            ];
        })->sortByDesc('nilai')->values()->all();

        return [
            'tahun' => $tahun,
            'bulan' => $bulan,
            'grouping' => $perUker ? 'uker' : 'cabang',
            'baris' => $baris,
        ];
    }

    // ---------------------------------------------------------------------

    /**
     * Laba kumulatif total (semua segmen) per bulan pada satu tahun.
     *
     * @return array<int, float> [bulan => total]
     */
    private function kumulatifPerBulan(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $segmen = null): array
    {
        return $this->dasar($areaId, $cabangId, $ukerId)
            ->when($segmen !== null && $segmen !== '', fn (Builder $q) => $q->where('segmen', $segmen))
            ->where('tahun', $tahun)
            ->groupBy('bulan')
            ->orderBy('bulan')
            ->selectRaw('bulan, SUM(laba) as total')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->bulan => (float) $r->total])
            ->all();
    }

    /**
     * Target RKA kumulatif per bulan untuk total atau satu segmen.
     *
     * @return array<int, float>
     */
    private function targetKumulatifPerBulan(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $segmen = null): array
    {
        return $this->filterOrganisasi(
            RkaLaba::query(),
            $areaId,
            $cabangId,
            $ukerId,
        )
            ->when($segmen !== null && $segmen !== '', fn (Builder $q) => $q->where('segmen', $segmen))
            ->where('tahun', $tahun)
            ->groupBy('bulan')
            ->orderBy('bulan')
            ->selectRaw('bulan, SUM(target) as total')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->bulan => (float) $r->total])
            ->all();
    }

    /**
     * Turunkan laba MTD per bulan dari nilai kumulatif YTD.
     *
     * @param  array<int, float>  $kumulatif
     * @return array<int, float|null>
     */
    private function mtdPerBulan(array $kumulatif): array
    {
        if ($kumulatif === []) {
            return [];
        }

        $hasil = [];
        $bulanMaks = max(array_keys($kumulatif));

        for ($bulan = 1; $bulan <= $bulanMaks; $bulan++) {
            $hasil[$bulan] = $this->mtdDariKumulatif($kumulatif, $bulan);
        }

        return $hasil;
    }

    /**
     * MTD satu bulan dari deret kumulatif: laba(N) - laba(N-1).
     *
     * @param  array<int, float>  $kumulatif
     */
    private function mtdDariKumulatif(array $kumulatif, int $bulan): ?float
    {
        if (! array_key_exists($bulan, $kumulatif)) {
            return null;                        // bulan ini tidak ada datanya
        }

        if ($bulan === 1) {
            return $kumulatif[1];               // Januari: MTD = nilai kumulatif itu sendiri
        }

        if (! array_key_exists($bulan - 1, $kumulatif)) {
            return null;                        // bulan sebelumnya tak ada data → MTD tak diketahui
        }

        return $kumulatif[$bulan] - $kumulatif[$bulan - 1];
    }

    /**
     * Delta MTD kartu: laba(N) - laba(N-1) untuk key tertentu.
     *
     * @param  array<string, float>|null  $prevRow  baris segmen bulan N-1, null bila periode itu tak ada
     * @return array{nilai: float|null, persen: float|null}
     */
    private function deltaMtd(?float $aktual, int $bulan, string $key, ?array $prevRow): array
    {
        if ($aktual === null) {
            return Delta::kosong();
        }

        if ($bulan === 1) {
            // Januari: pembanding 0 -> MTD = nilai kumulatif Januari.
            return Delta::hitung($aktual, 0.0);
        }

        if ($prevRow === null) {
            // Bulan N-1 tidak ada datanya sama sekali = MTD TIDAK DIKETAHUI.
            return Delta::kosong();
        }

        if ($key !== 'total' && ! array_key_exists($key, $prevRow)) {
            // Segmen ini tidak ada di bulan N-1 -> MTD null (bukan 0). "Tidak ada
            // laporan bulan lalu" berbeda dari "laba bulan lalu 0".
            return Delta::kosong();
        }

        $prevVal = $key === 'total' ? array_sum($prevRow) : (float) $prevRow[$key];

        return Delta::hitung($aktual, $prevVal);
    }

    /**
     * Delta terhadap satu periode pembanding (dipakai YoY).
     *
     * @param  array<string, float>|null  $row
     * @return array{nilai: float|null, persen: float|null}
     */
    private function deltaPembanding(?float $aktual, string $key, ?array $row): array
    {
        if ($aktual === null || $row === null) {
            return Delta::kosong();
        }

        $val = $key === 'total' ? array_sum($row) : (float) ($row[$key] ?? 0);

        return Delta::hitung($aktual, $val);
    }

    /**
     * Nilai per (periode, segmen) untuk beberapa periode (tahun, bulan) sekaligus.
     *
     * @param  list<array{0: int, 1: int}|null>  $pairs
     * @return array<string, array<string, float>> ["tahun-bulan" => [segmen => laba]]
     */
    private function nilaiPeriodeSegmen(array $pairs, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $pairs = array_values(array_filter($pairs));

        if ($pairs === []) {
            return [];
        }

        $rows = $this->dasar($areaId, $cabangId, $ukerId)
            ->where(function (Builder $q) use ($pairs) {
                foreach ($pairs as [$tahun, $bulan]) {
                    $q->orWhere(fn (Builder $qq) => $qq->where('tahun', $tahun)->where('bulan', $bulan));
                }
            })
            ->groupBy('tahun', 'bulan', 'segmen')
            ->selectRaw('tahun, bulan, segmen, SUM(laba) as total')
            ->get();

        $hasil = [];

        foreach ($rows as $r) {
            $hasil[$this->periodeKunci((int) $r->tahun, (int) $r->bulan)][$r->segmen] = (float) $r->total;
        }

        return $hasil;
    }

    /**
     * @return array<string, float> [segmen => target]
     */
    private function targetPerSegmen(int $tahun, int $bulan, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        return $this->filterOrganisasi(
            RkaLaba::query(),
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
     * Segmen yang muncul di data posisi ATAU target, urut mengikuti Laba::SEGMEN.
     *
     * @param  array<string, float>  $posisiNilai
     * @param  array<string, float>  $target
     * @return list<string>
     */
    private function segmenTersedia(array $posisiNilai, array $target): array
    {
        return collect(array_keys($posisiNilai))
            ->merge(array_keys($target))
            ->unique()
            ->sortBy(fn (string $s) => array_search($s, Laba::SEGMEN, true) === false
                ? PHP_INT_MAX
                : array_search($s, Laba::SEGMEN, true))
            ->values()
            ->all();
    }

    /**
     * Dataset dasar Laba — TERMASUK rollup Region Office (dihitung di Total &
     * segmen). Tabel peringkat cabang mengecualikannya sendiri lewat
     * {@see branchPencapaian()}.
     */
    private function dasar(?int $areaId, ?int $cabangId, ?int $ukerId): Builder
    {
        return $this->filterOrganisasi(
            Laba::query(),
            $areaId,
            $cabangId,
            $ukerId,
        );
    }

    private function periodeKunci(int $tahun, int $bulan): string
    {
        return $tahun.'-'.$bulan;
    }
}
