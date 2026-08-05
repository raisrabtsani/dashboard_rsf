<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Pinjaman;
use App\Models\Simpanan;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Delta;
use App\Support\Satuan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Data halaman PRESENT — rekap rapat pagi tingkat Region.
 *
 * BEDA UTAMA dari service dashboard harian: CAKUPAN LENGKAP. Selain tabel harian
 * (simpanan, pinjaman), PRESENT ikut menjumlahkan segmen yang tidak masuk
 * dashboard harian dari tabel terpisah:
 *   DPK       = simpanan + simpanan_wholesale
 *   Pinjaman  = pinjaman + pinjaman_commercial
 * Karena itu 855 (rollup Region) TIDAK dikecualikan di sini — rapat Region ingin
 * melihat angka utuh, dan tiap segmen sudah punya barisnya sendiri.
 *
 * Kontrak service: tidak menyentuh Request/auth() (gerbang akses = middleware
 * `present`), tidak mengembalikan response HTTP, nilai uang keluar dalam JUTA,
 * query portable MySQL/SQLite (tanpa MONTH()/YEAR() mentah).
 *
 * Kolom nilai kartu: posisi + dua delta MtD & YtD (bukan empat) — layar rapat
 * dipandang sekilas, jadi kartunya sengaja ringkas.
 */
class PresentService
{
    use MenyaringOrganisasi;

    /** Tabel + kolom nilai yang menyusun tiap metrik (dijumlahkan bersama). */
    private const DPK = [['simpanan', 'saldo'], ['simpanan_wholesale', 'saldo']];

    private const PINJAMAN = [['pinjaman', 'baki_debet'], ['pinjaman_commercial', 'baki_debet']];

    private const RECOVERY = [['recovery', 'actual']];

    private const RKA_DPK = [['rka_simpanan', 'target'], ['rka_simpanan_wholesale', 'target']];

    private const RKA_PINJAMAN = [['rka_pinjaman', 'target'], ['rka_pinjaman_commercial', 'target']];

    private const RKA_RECOVERY = [['rka_recovery', 'target']];

    /** @var list<string> */
    private const CASA = [Simpanan::PRODUK_TABUNGAN, Simpanan::PRODUK_GIRO];

    /** @var array<int, string> */
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * Tanggal posisi terbaru lintas metrik harian (default halaman).
     */
    public function tanggalTerakhir(): ?string
    {
        $maks = null;

        foreach ([...self::DPK, ...self::PINJAMAN, ...self::RECOVERY] as [$tabel]) {
            $t = DB::table($tabel)->max('tanggal');

            if ($t !== null) {
                $t = Carbon::parse($t)->toDateString();
                $maks = ($maks === null || $t > $maks) ? $t : $maks;
            }
        }

        return $maks;
    }

    /**
     * SLIDE 1 — Overview Region: DPK, Pinjaman, Recovery, Laba + %CASA + %LDR.
     *
     * @return array<string, mixed>
     */
    public function overviewRegion(string $tanggal): array
    {
        return [
            'tanggal' => Carbon::parse($tanggal)->toDateString(),
            'kartu' => $this->kartuUtama(null, $tanggal),
            'rasio' => $this->rasio(null, $tanggal),
        ];
    }

    /**
     * SLIDE 2 — Overview per Area: satu blok per area, kartu sama TANPA rasio.
     *
     * @return array<string, mixed>
     */
    public function overviewArea(string $tanggal): array
    {
        $blok = Area::query()
            ->orderBy('nama')
            ->get(['id', 'nama'])
            ->map(fn (Area $a) => [
                'area_id' => $a->id,
                'nama' => $a->nama,
                'kartu' => $this->kartuUtama($a->id, $tanggal),
            ])
            ->all();

        return ['tanggal' => Carbon::parse($tanggal)->toDateString(), 'area' => $blok];
    }

    /**
     * SLIDE 3 — Detail per cabang untuk DPK, Pinjaman (OS), SML, NPL.
     *
     * Keempatnya berbagi BENTUK yang sama supaya bisa dirender oleh satu komponen
     * tabel (PresentDetailTable). SML/NPL ditandai `inverse` (makin kecil makin
     * baik) untuk pewarnaan.
     *
     * @return array<string, mixed>
     */
    public function detail(string $tanggal): array
    {
        return [
            'tanggal' => Carbon::parse($tanggal)->toDateString(),
            'tabel' => [
                $this->detailTabel('dpk', 'DPK per Cabang', self::DPK, self::RKA_DPK, $tanggal),
                $this->detailTabel('pinjaman', 'Pinjaman (OS) per Cabang', self::PINJAMAN, self::RKA_PINJAMAN, $tanggal),
                $this->detailTabel('sml', 'SML per Cabang', self::PINJAMAN, self::RKA_PINJAMAN, $tanggal, ['kualitas' => [Pinjaman::KUALITAS_SML]], inverse: true),
                $this->detailTabel('npl', 'NPL per Cabang', self::PINJAMAN, self::RKA_PINJAMAN, $tanggal, ['kualitas' => [Pinjaman::KUALITAS_NPL]], inverse: true),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Penyusun kartu overview (dipakai region & tiap area).
    // ---------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function kartuUtama(?int $areaId, string $tanggal): array
    {
        return [
            $this->kartuStok('dpk', 'DPK', self::DPK, self::RKA_DPK, $areaId, $tanggal),
            $this->kartuStok('pinjaman', 'Pinjaman (OS)', self::PINJAMAN, self::RKA_PINJAMAN, $areaId, $tanggal),
            $this->kartuStok('recovery', 'Recovery', self::RECOVERY, self::RKA_RECOVERY, $areaId, $tanggal),
            $this->kartuLaba($areaId, $tanggal),
        ];
    }

    /**
     * Kartu metrik STOK (posisi pada tanggal): DPK / Pinjaman / Recovery.
     *
     * @param  list<array{0: string, 1: string}>  $tabel
     * @param  list<array{0: string, 1: string}>  $rkaTabel
     * @param  array<string, list<string>>  $filter
     * @return array<string, mixed>
     */
    private function kartuStok(string $key, string $judul, array $tabel, array $rkaTabel, ?int $areaId, string $tanggal, array $filter = []): array
    {
        $posisiTgl = $this->tanggalTersedia($tabel, $areaId, $tanggal);

        if ($posisiTgl === null) {
            return $this->kartuKosong($key, $judul);
        }

        $posisi = Carbon::parse($posisiTgl);
        $mtdTgl = $this->tanggalTersedia($tabel, $areaId, $posisi->copy()->subMonthNoOverflow()->endOfMonth()->toDateString());
        $ytdTgl = $this->tanggalTersedia($tabel, $areaId, $posisi->copy()->subYear()->endOfYear()->toDateString());

        $nilai = $this->total($tabel, $areaId, $posisiTgl, $filter);
        $mtd = $mtdTgl === null ? null : $this->total($tabel, $areaId, $mtdTgl, $filter);
        $ytd = $ytdTgl === null ? null : $this->total($tabel, $areaId, $ytdTgl, $filter);

        $target = $this->targetBulan($rkaTabel, $areaId, $posisi->year, $posisi->month, $filter);

        return $this->kartu($key, $judul, $nilai, [
            'mtd' => Delta::hitung($nilai, $mtd),
            'ytd' => Delta::hitung($nilai, $ytd),
        ], $target, $posisiTgl);
    }

    /**
     * Kartu Laba — BULANAN & KUMULATIF YTD. MtD = kenaikan bulan (kumulatif N −
     * N−1); YtD = nilai kumulatif itu sendiri (pertumbuhan sejak awal tahun).
     * Mundur otomatis ke bulan terakhir yang tersedia <= tanggal posisi.
     *
     * @return array<string, mixed>
     */
    private function kartuLaba(?int $areaId, string $tanggal): array
    {
        $posisi = Carbon::parse($tanggal);
        $periode = $this->labaPeriodeTersedia($areaId, (int) $posisi->year, (int) $posisi->month);

        if ($periode === null) {
            return $this->kartuKosong('laba', 'Laba');
        }

        [$tahun, $bulan] = $periode;

        $nilai = $this->labaKumulatif($areaId, $tahun, $bulan);
        $prev = $bulan > 1 ? $this->labaKumulatif($areaId, $tahun, $bulan - 1) : null;

        $mtd = match (true) {
            $nilai === null => Delta::kosong(),
            $bulan === 1 => Delta::hitung($nilai, 0.0),      // Januari: MtD = kumulatifnya sendiri
            $prev === null => Delta::kosong(),               // bulan lalu tak ada data
            default => Delta::hitung($nilai, $prev),
        };

        $target = $this->targetBulan([['rka_laba', 'target']], $areaId, $tahun, $bulan);

        $kartu = $this->kartu('laba', 'Laba', $nilai, [
            'mtd' => $mtd,
            // YtD laba = akumulasi YTD itu sendiri.
            'ytd' => ['nilai' => Satuan::toJuta($nilai), 'persen' => null],
        ], $target, $this->labelPeriode($tahun, $bulan));

        return $kartu;
    }

    /**
     * %CASA (CASA/DPK) & %LDR (OS/DPK) — hanya slide Region.
     *
     * @return list<array<string, mixed>>
     */
    private function rasio(?int $areaId, string $tanggal): array
    {
        $dpkTgl = $this->tanggalTersedia(self::DPK, $areaId, $tanggal);
        $pinjamanTgl = $this->tanggalTersedia(self::PINJAMAN, $areaId, $tanggal);

        $dpk = $dpkTgl === null ? null : $this->total(self::DPK, $areaId, $dpkTgl);
        $casa = $dpkTgl === null ? null : $this->total(self::DPK, $areaId, $dpkTgl, ['produk' => self::CASA]);
        $os = $pinjamanTgl === null ? null : $this->total(self::PINJAMAN, $areaId, $pinjamanTgl);

        return [
            $this->rasioItem('casa', '%CASA', 'CASA / DPK', $casa, $dpk),
            $this->rasioItem('ldr', '%LDR', 'OS Pinjaman / DPK', $os, $dpk),
        ];
    }

    /**
     * @param  list<array{0: string, 1: string}>  $tabel
     * @param  list<array{0: string, 1: string}>  $rkaTabel
     * @param  array<string, list<string>>  $filter
     * @return array<string, mixed>
     */
    private function detailTabel(string $key, string $judul, array $tabel, array $rkaTabel, string $tanggal, array $filter = [], bool $inverse = false): array
    {
        $posisiTgl = $this->tanggalTersedia($tabel, null, $tanggal);

        if ($posisiTgl === null) {
            return ['key' => $key, 'judul' => $judul, 'tanggal' => null, 'inverse' => $inverse, 'baris' => []];
        }

        $posisi = Carbon::parse($posisiTgl);
        $nilaiPerCabang = $this->perCabang($tabel, $posisiTgl, $filter);
        $targetPerCabang = $this->targetPerCabang($rkaTabel, $posisi->year, $posisi->month, $filter);

        $ids = array_unique([...array_keys($nilaiPerCabang), ...array_keys($targetPerCabang)]);
        $nama = Cabang::query()->whereIn('id', $ids)->pluck('nama', 'id');

        $baris = collect($ids)
            ->map(function (int $cid) use ($nilaiPerCabang, $targetPerCabang, $nama) {
                $nilai = (float) ($nilaiPerCabang[$cid] ?? 0);
                $rka = (float) ($targetPerCabang[$cid] ?? 0);

                return [
                    'id' => $cid,
                    'nama' => $nama[$cid] ?? (string) $cid,
                    'nilai' => Satuan::toJuta($nilai),
                    'target' => Satuan::toJuta($rka),
                    'pencapaian' => $rka > 0 ? round($nilai / $rka * 100, 2) : null,
                    'gap' => Satuan::toJuta($nilai - $rka),
                ];
            })
            ->sortByDesc('nilai')
            ->values()
            ->all();

        return ['key' => $key, 'judul' => $judul, 'tanggal' => $posisiTgl, 'inverse' => $inverse, 'baris' => $baris];
    }

    // ---------------------------------------------------------------------
    // Primitif query (portable, tanpa pengecualian 855).
    // ---------------------------------------------------------------------

    /**
     * Tanggal data terakhir yang tersedia <= $batas di antara tabel-tabel metrik.
     *
     * @param  list<array{0: string, 1: string}>  $tabel
     */
    private function tanggalTersedia(array $tabel, ?int $areaId, string $batas): ?string
    {
        $maks = null;

        foreach ($tabel as [$nama]) {
            $t = DB::table($nama)
                ->when($areaId !== null, fn ($q) => $q->whereIn('cabang_id', $this->cabangDiArea($areaId)))
                ->where('tanggal', '<=', $batas)
                ->max('tanggal');

            if ($t !== null) {
                $t = Carbon::parse($t)->toDateString();
                $maks = ($maks === null || $t > $maks) ? $t : $maks;
            }
        }

        return $maks;
    }

    /**
     * Jumlah nilai metrik pada satu tanggal, per cabang.
     *
     * @param  list<array{0: string, 1: string}>  $tabel
     * @param  array<string, list<string>>  $filter
     * @return array<int, float>
     */
    private function perCabang(array $tabel, string $tanggal, array $filter = [], ?int $areaId = null): array
    {
        $hasil = [];

        foreach ($tabel as [$nama, $kolom]) {
            $rows = DB::table($nama)
                ->when($areaId !== null, fn ($q) => $q->whereIn('cabang_id', $this->cabangDiArea($areaId)))
                ->when(isset($filter['produk']), fn ($q) => $q->whereIn('produk', $filter['produk']))
                ->when(isset($filter['kualitas']), fn ($q) => $q->whereIn('kualitas', $filter['kualitas']))
                ->where('tanggal', $tanggal)
                ->groupBy('cabang_id')
                ->selectRaw("cabang_id, SUM({$kolom}) as t")   // $kolom dari konstanta kelas, aman
                ->pluck('t', 'cabang_id');

            foreach ($rows as $cid => $t) {
                $cid = (int) $cid;
                $hasil[$cid] = ($hasil[$cid] ?? 0.0) + (float) $t;
            }
        }

        return $hasil;
    }

    /**
     * Total metrik pada satu tanggal (semua cabang dalam lingkup).
     *
     * @param  list<array{0: string, 1: string}>  $tabel
     * @param  array<string, list<string>>  $filter
     */
    private function total(array $tabel, ?int $areaId, string $tanggal, array $filter = []): float
    {
        return (float) array_sum($this->perCabang($tabel, $tanggal, $filter, $areaId));
    }

    /**
     * Total target RKA satu bulan (semua cabang dalam lingkup).
     *
     * @param  list<array{0: string, 1: string}>  $rkaTabel
     * @param  array<string, list<string>>  $filter
     */
    private function targetBulan(array $rkaTabel, ?int $areaId, int $tahun, int $bulan, array $filter = []): float
    {
        $total = 0.0;

        foreach ($rkaTabel as [$nama, $kolom]) {
            $total += (float) DB::table($nama)
                ->when($areaId !== null, fn ($q) => $q->whereIn('cabang_id', $this->cabangDiArea($areaId)))
                ->when(isset($filter['produk']), fn ($q) => $q->whereIn('produk', $filter['produk']))
                ->when(isset($filter['kualitas']), fn ($q) => $q->whereIn('kualitas', $filter['kualitas']))
                ->where('tahun', $tahun)
                ->where('bulan', $bulan)
                ->sum($kolom);
        }

        return $total;
    }

    /**
     * Target RKA per cabang untuk satu bulan.
     *
     * @param  list<array{0: string, 1: string}>  $rkaTabel
     * @param  array<string, list<string>>  $filter
     * @return array<int, float>
     */
    private function targetPerCabang(array $rkaTabel, int $tahun, int $bulan, array $filter = []): array
    {
        $hasil = [];

        foreach ($rkaTabel as [$nama, $kolom]) {
            $rows = DB::table($nama)
                ->when(isset($filter['produk']), fn ($q) => $q->whereIn('produk', $filter['produk']))
                ->when(isset($filter['kualitas']), fn ($q) => $q->whereIn('kualitas', $filter['kualitas']))
                ->where('tahun', $tahun)
                ->where('bulan', $bulan)
                ->groupBy('cabang_id')
                ->selectRaw("cabang_id, SUM({$kolom}) as t")
                ->pluck('t', 'cabang_id');

            foreach ($rows as $cid => $t) {
                $cid = (int) $cid;
                $hasil[$cid] = ($hasil[$cid] ?? 0.0) + (float) $t;
            }
        }

        return $hasil;
    }

    /**
     * Laba kumulatif YTD pada satu periode; null bila periode itu tak ada datanya.
     */
    private function labaKumulatif(?int $areaId, int $tahun, int $bulan): ?float
    {
        $q = fn () => DB::table('laba')
            ->when($areaId !== null, fn ($qq) => $qq->whereIn('cabang_id', $this->cabangDiArea($areaId)))
            ->where('tahun', $tahun)
            ->where('bulan', $bulan);

        if (! $q()->exists()) {
            return null;
        }

        return (float) $q()->sum('laba');
    }

    /**
     * Periode (tahun, bulan) laba terakhir <= (tahun, bulan) target, dalam lingkup.
     *
     * @return array{0: int, 1: int}|null
     */
    private function labaPeriodeTersedia(?int $areaId, int $tahun, int $bulan): ?array
    {
        $row = DB::table('laba')
            ->when($areaId !== null, fn ($q) => $q->whereIn('cabang_id', $this->cabangDiArea($areaId)))
            ->where(function ($q) use ($tahun, $bulan) {
                $q->where('tahun', '<', $tahun)
                    ->orWhere(fn ($qq) => $qq->where('tahun', $tahun)->where('bulan', '<=', $bulan));
            })
            ->orderByDesc('tahun')
            ->orderByDesc('bulan')
            ->first(['tahun', 'bulan']);

        return $row === null ? null : [(int) $row->tahun, (int) $row->bulan];
    }

    // ---------------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $delta
     * @return array<string, mixed>
     */
    private function kartu(string $key, string $judul, ?float $nilai, array $delta, ?float $target, ?string $per): array
    {
        return [
            'key' => $key,
            'judul' => $judul,
            'nilai' => Satuan::toJuta($nilai),
            'delta' => $delta,
            'target' => Satuan::toJuta($target),
            'pencapaian' => ($nilai !== null && $target !== null && $target > 0) ? round($nilai / $target * 100, 2) : null,
            'gap' => ($nilai === null || $target === null) ? null : Satuan::toJuta($nilai - $target),
            'per' => $per,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function kartuKosong(string $key, string $judul): array
    {
        return [
            'key' => $key,
            'judul' => $judul,
            'nilai' => null,
            'delta' => ['mtd' => Delta::kosong(), 'ytd' => Delta::kosong()],
            'target' => null,
            'pencapaian' => null,
            'gap' => null,
            'per' => null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function rasioItem(string $key, string $judul, string $deskripsi, ?float $pembilang, ?float $penyebut): array
    {
        $nilai = ($pembilang === null || $penyebut === null || $penyebut == 0.0)
            ? null
            : round($pembilang / $penyebut * 100, 2);

        return [
            'key' => $key,
            'judul' => $judul,
            'deskripsi' => $deskripsi,
            'nilai' => $nilai,
            'pembilang' => Satuan::toJuta($pembilang),
            'penyebut' => Satuan::toJuta($penyebut),
        ];
    }

    private function labelPeriode(int $tahun, int $bulan): string
    {
        return (self::NAMA_BULAN[$bulan] ?? (string) $bulan).' '.$tahun;
    }
}
