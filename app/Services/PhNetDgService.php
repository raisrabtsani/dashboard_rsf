<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Ph;
use App\Models\Pinjaman;
use App\Models\Region;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Bulan;
use App\Support\Satuan;
use App\Support\Segmen;
use Illuminate\Support\Carbon;

/**
 * Dua "domain" dalam satu halaman: PH (punya tabel) dan Net DG (TIDAK punya tabel).
 *
 * ── PH ────────────────────────────────────────────────────────────────────
 * Flow bulanan hapus buku, dibaca langsung dari tabel `ph`.
 *
 * ── NET DG ────────────────────────────────────────────────────────────────
 * Dihitung on-the-fly dari posisi SML/NPL akhir bulan di tabel `pinjaman`
 * ditambah PH bulan itu. Rumusnya:
 *
 *     NetDG_NPL(N) = NPL(N) − NPL(N−1) + PH(N)
 *     NetDG_SML(N) = SML(N) − SML(N−1) + NetDG_NPL(N)
 *
 * Yang DITAMPILKAN adalah NetDG_SML. Substitusi memberi bentuk teleskopik:
 *
 *     NetDG_SML(N) = Δ(SML+NPL)(N) + PH(N)
 *
 * sehingga akumulasi Januari..M runtuh jadi:
 *
 *     Akum(M) = (SML+NPL)(M) − (SML+NPL)(Des tahun lalu) + Σ PH
 *
 * KONSEKUENSI DATA: deret tahun X membutuhkan posisi pinjaman akhir Desember
 * X−1. Selama posisi itu belum diunggah, Januari X (dan karena itu seluruh
 * akumulasinya) bernilai NULL — bukan 0.
 *
 * PH & Net DG TIDAK punya RKA: tidak ada target, pencapaian, maupun gap.
 */
class PhNetDgService
{
    use MenyaringOrganisasi;

    public const MODE_PH = 'ph';

    public const MODE_NETDG = 'netdg';

    /** @var list<string> */
    public const MODE = [self::MODE_PH, self::MODE_NETDG];

    /**
     * Rollup Region Office DIIKUTKAN dalam kalkulasi.
     *
     * Segmen Menengah dikelola di level Region (cabang_id 855) dan merupakan
     * bagian sah dari Net DG. Baris 855 tetap disembunyikan dari dropdown BO,
     * sehingga jumlah baris tabel cabang < kartu Total sebesar porsi Menengah —
     * itu DISENGAJA, bukan bug.
     */
    public const IKUTKAN_ROLLUP = true;

    /**
     * Scope kartu & chart: Total plus tiga segmen kanonik.
     *
     * @return list<array{key: string, judul: string, segmen: string|null}>
     */
    public static function scope(): array
    {
        return [
            ['key' => 'total', 'judul' => 'Total', 'segmen' => null],
            ...array_map(
                fn (string $s) => ['key' => mb_strtolower($s), 'judul' => $s, 'segmen' => $s],
                Segmen::SEMUA,
            ),
        ];
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
            'periode_maks' => $this->periodeTerakhir(),
            'tahun' => $this->tahunTersedia(),
        ];
    }

    public function periodeTerakhir(): ?string
    {
        $periode = Ph::query()->max('periode') ?? Pinjaman::query()->max('tanggal');

        return $periode === null ? null : Carbon::parse($periode)->toDateString();
    }

    /**
     * Akhir bulan terakhir yang datanya tersedia pada atau sebelum $tanggalBatas,
     * DALAM lingkup filter — mode-aware:
     *   PH    → dari tabel `ph` (flow yang sering telat rilis; mundur otomatis).
     *   NetDG → dari posisi `pinjaman` (basis perhitungan Net DG).
     *
     * Dipakai halaman Ringkasan agar kartu domain ini mundur ke bulan terakhir
     * yang benar-benar ada, bukan bulan posisi yang mungkin belum lengkap.
     * Null bila tak ada data <= batas di lingkup itu.
     */
    public function periodeTersedia(string $mode, string $tanggalBatas, ?int $areaId, ?int $cabangId, ?int $ukerId): ?string
    {
        $batas = Carbon::parse($tanggalBatas)->endOfMonth()->toDateString();

        $tanggal = $mode === self::MODE_PH
            ? $this->filterOrganisasi(Ph::query(), $areaId, $cabangId, $ukerId)
                ->where('periode', '<=', $batas)->max('periode')
            : $this->filterOrganisasi(Pinjaman::query(), $areaId, $cabangId, $ukerId)
                ->where('tanggal', '<=', $batas)->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->endOfMonth()->toDateString();
    }

    /**
     * @return list<int>
     */
    public function tahunTersedia(): array
    {
        // Tahun diturunkan di PHP dari tanggal — TIDAK memakai YEAR() mentah,
        // supaya query jalan sama di MySQL dan SQLite.
        $dariPh = Ph::query()->distinct()->pluck('periode');
        $dariPinjaman = Pinjaman::query()->distinct()->pluck('tanggal');

        return $dariPh->merge($dariPinjaman)
            ->map(fn ($t) => (int) Carbon::parse($t)->year)
            ->unique()
            ->sortDesc()
            ->values()
            ->all();
    }

    /**
     * Kartu per scope untuk bulan posisi + akumulasi YTD.
     *
     * @return array<string, mixed>
     */
    public function snapshot(string $mode, string $periode, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($periode)->endOfMonth();
        $bulan = (int) $posisi->month;

        $tahunIni = $this->deret($mode, (int) $posisi->year, $areaId, $cabangId, $ukerId);
        $tahunLalu = $this->deret($mode, (int) $posisi->year - 1, $areaId, $cabangId, $ukerId);

        $kartu = [];

        foreach (self::scope() as $s) {
            $ini = $tahunIni[$s['key']];
            $lalu = $tahunLalu[$s['key']];

            $nilai = $ini['bulanan'][$bulan - 1];
            $nilaiLalu = $lalu['bulanan'][$bulan - 1];
            $sebelumnya = $bulan > 1 ? $ini['bulanan'][$bulan - 2] : null;

            $kartu[] = [
                'key' => $s['key'],
                'judul' => $s['judul'],
                'nilai' => $nilai,
                'akumulasi' => $ini['akumulasi'][$bulan - 1],
                'akumulasi_tahun_lalu' => $lalu['akumulasi'][$bulan - 1],
                'delta' => [
                    'mom' => $this->selisih($nilai, $sebelumnya),
                    'yoy' => $this->selisih($nilai, $nilaiLalu),
                ],
            ];
        }

        return [
            'mode' => $mode,
            'periode' => $posisi->toDateString(),
            'bulan' => $bulan,
            'tahun' => (int) $posisi->year,
            // PH & Net DG tidak punya RKA — frontend tidak boleh merender
            // pencapaian/gap untuk halaman ini.
            'punya_rka' => false,
            'ph_kosong' => $mode === self::MODE_NETDG && ! $this->adaPh((int) $posisi->year, $areaId, $cabangId, $ukerId),
            'kartu' => $kartu,
        ];
    }

    /**
     * Chart combo dua tahun: batang = nilai bulanan, garis = akumulasi.
     *
     * Dipotong sampai bulan posisi untuk TAHUN BERJALAN; tahun lalu ditampilkan
     * penuh sampai bulan yang sama agar perbandingannya setara.
     *
     * @return array<string, mixed>
     */
    public function chart(string $mode, string $periode, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($periode)->endOfMonth();
        $bulan = (int) $posisi->month;
        $tahun = (int) $posisi->year;

        $ini = $this->deret($mode, $tahun, $areaId, $cabangId, $ukerId);
        $lalu = $this->deret($mode, $tahun - 1, $areaId, $cabangId, $ukerId);

        $potong = fn (array $n) => array_slice($n, 0, $bulan);

        $seri = [];

        foreach (self::scope() as $s) {
            $seri[$s['key']] = [
                'judul' => $s['judul'],
                'label' => array_map(fn (int $b) => Bulan::PENDEK[$b], range(1, $bulan)),
                'tahun_lalu' => [
                    'tahun' => $tahun - 1,
                    'bulanan' => $potong($lalu[$s['key']]['bulanan']),
                    'akumulasi' => $potong($lalu[$s['key']]['akumulasi']),
                ],
                'tahun_ini' => [
                    'tahun' => $tahun,
                    'bulanan' => $potong($ini[$s['key']]['bulanan']),
                    'akumulasi' => $potong($ini[$s['key']]['akumulasi']),
                ],
            ];
        }

        return ['mode' => $mode, 'tahun' => $tahun, 'bulan' => $bulan, 'seri' => $seri];
    }

    /**
     * Deret 12 bulan per scope untuk satu tahun.
     *
     * @return array<string, array{bulanan: list<float|null>, akumulasi: list<float|null>}>
     */
    public function deret(string $mode, int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        return $mode === self::MODE_PH
            ? $this->deretPh($tahun, $areaId, $cabangId, $ukerId)
            : $this->deretNetDg($tahun, $areaId, $cabangId, $ukerId);
    }

    /**
     * PH: flow bulanan langsung dari tabel.
     *
     * Bulan tanpa baris = NULL (belum diunggah), bukan 0.
     *
     * @return array<string, array{bulanan: list<float|null>, akumulasi: list<float|null>}>
     */
    private function deretPh(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $perBulan = $this->phPerBulanSegmen($tahun, $areaId, $cabangId, $ukerId);

        $hasil = [];

        foreach (self::scope() as $s) {
            $bulanan = [];

            for ($b = 1; $b <= 12; $b++) {
                $isi = $perBulan[$b] ?? null;

                $bulanan[] = $isi === null
                    ? null
                    : Satuan::toJuta($s['segmen'] === null ? array_sum($isi) : ($isi[$s['segmen']] ?? 0));
            }

            $hasil[$s['key']] = [
                'bulanan' => $bulanan,
                'akumulasi' => $this->akumulasi($bulanan),
            ];
        }

        return $hasil;
    }

    /**
     * NET DG: dihitung dari posisi SML/NPL akhir bulan + PH bulan itu.
     *
     * @return array<string, array{bulanan: list<float|null>, akumulasi: list<float|null>}>
     */
    private function deretNetDg(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        // Butuh 13 posisi: Des (tahun-1) sampai Des (tahun).
        $posisi = $this->posisiSmlNplAkhirBulan($tahun, $areaId, $cabangId, $ukerId);
        $ph = $this->phPerBulanSegmen($tahun, $areaId, $cabangId, $ukerId);

        $hasil = [];

        foreach (self::scope() as $s) {
            $bulanan = [];

            for ($b = 1; $b <= 12; $b++) {
                $sekarang = $this->smlNpl($posisi, $b, $s['segmen']);
                $sebelum = $this->smlNpl($posisi, $b - 1, $s['segmen']);

                // Tanpa posisi bulan ini ATAU bulan sebelumnya, Net DG tidak
                // terdefinisi. Untuk Januari, "bulan sebelumnya" = Des tahun lalu.
                if ($sekarang === null || $sebelum === null) {
                    $bulanan[] = null;

                    continue;
                }

                $phBulan = $ph[$b] ?? null;
                $phNilai = $phBulan === null
                    ? 0.0
                    : ($s['segmen'] === null ? array_sum($phBulan) : ($phBulan[$s['segmen']] ?? 0.0));

                // NetDG_SML = Δ(SML+NPL) + PH — bentuk teleskopik dari rumus
                // dua langkah di docblock kelas.
                $bulanan[] = Satuan::toJuta($sekarang - $sebelum + $phNilai);
            }

            $hasil[$s['key']] = [
                'bulanan' => $bulanan,
                'akumulasi' => $this->akumulasi($bulanan),
            ];
        }

        return $hasil;
    }

    /**
     * Akumulasi berjalan; NULL menular ke depan karena akumulasi yang melompati
     * bulan kosong akan menyesatkan.
     *
     * @param  list<float|null>  $bulanan
     * @return list<float|null>
     */
    private function akumulasi(array $bulanan): array
    {
        $hasil = [];
        $jumlah = 0.0;
        $terputus = false;

        foreach ($bulanan as $n) {
            // Sekali ada bulan yang tidak diketahui, seluruh akumulasi
            // SESUDAHNYA ikut tidak diketahui. Melanjutkan penjumlahan seolah
            // bulan itu bernilai 0 menghasilkan angka YTD yang understated tanpa
            // ada tandanya di layar.
            $terputus = $terputus || $n === null;

            if ($terputus) {
                $hasil[] = null;

                continue;
            }

            $jumlah += $n;
            $hasil[] = round($jumlah, 6);
        }

        return $hasil;
    }

    /**
     * PH per bulan per segmen kanonik untuk satu tahun (rupiah penuh).
     *
     * Query hanya group by periode+segmen; BULAN DITURUNKAN DI PHP agar portable
     * MySQL/SQLite.
     *
     * @return array<int, array<string, float>>
     */
    private function phPerBulanSegmen(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $baris = $this->filterOrganisasi(Ph::query(), $areaId, $cabangId, $ukerId)
            ->whereBetween('periode', ["{$tahun}-01-01", "{$tahun}-12-31"])
            ->groupBy('periode', 'segmen')
            ->selectRaw('periode, segmen, SUM(saldo) as total')
            ->get();

        $hasil = [];

        foreach ($baris as $r) {
            $bulan = (int) Carbon::parse($r->periode)->month;
            $segmen = Segmen::kanonik((string) $r->segmen) ?? (string) $r->segmen;

            $hasil[$bulan][$segmen] = ($hasil[$bulan][$segmen] ?? 0.0) + (float) $r->total;
        }

        return $hasil;
    }

    /**
     * Posisi (SML+NPL) akhir bulan, per segmen kanonik.
     *
     * Indeks 0 = Desember tahun sebelumnya, 1..12 = Jan..Des tahun berjalan.
     * Nilai null berarti posisinya belum ada di tabel `pinjaman`.
     *
     * @return array<int, array<string, float>|null>
     */
    private function posisiSmlNplAkhirBulan(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $awal = Carbon::create($tahun - 1, 12, 1)->startOfMonth();
        $akhir = Carbon::create($tahun, 12, 31)->endOfMonth();

        // Tanggal tersedia dalam rentang, dikelompokkan per bulan di PHP.
        $tanggal = $this->filterOrganisasi(Pinjaman::query(), $areaId, $cabangId, $ukerId)
            ->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()])
            ->distinct()
            ->orderBy('tanggal')
            ->pluck('tanggal');

        /** @var array<string, string> $akhirBulan kunci "Y-m" => tanggal terakhir tersedia */
        $akhirBulan = [];

        foreach ($tanggal as $t) {
            $tgl = Carbon::parse($t);
            $kunci = $tgl->format('Y-m');

            if (! isset($akhirBulan[$kunci]) || $tgl->gt(Carbon::parse($akhirBulan[$kunci]))) {
                $akhirBulan[$kunci] = $tgl->toDateString();
            }
        }

        if ($akhirBulan === []) {
            return array_fill(0, 13, null);
        }

        $saldo = $this->filterOrganisasi(Pinjaman::query(), $areaId, $cabangId, $ukerId)
            ->whereIn('tanggal', array_values($akhirBulan))
            ->whereIn('kualitas', [Pinjaman::KUALITAS_SML, Pinjaman::KUALITAS_NPL])
            ->groupBy('tanggal', 'segmen')
            ->selectRaw('tanggal, segmen, SUM(baki_debet) as total')
            ->get()
            ->groupBy(fn ($r) => Carbon::parse($r->tanggal)->toDateString());

        $hasil = [];

        for ($i = 0; $i <= 12; $i++) {
            $bulan = $i === 0
                ? Carbon::create($tahun - 1, 12, 1)
                : Carbon::create($tahun, $i, 1);

            $kunci = $bulan->format('Y-m');
            $tgl = $akhirBulan[$kunci] ?? null;

            if ($tgl === null || ! isset($saldo[$tgl])) {
                $hasil[$i] = null;

                continue;
            }

            $perSegmen = [];

            foreach ($saldo[$tgl] as $r) {
                $segmen = Segmen::kanonik((string) $r->segmen) ?? (string) $r->segmen;
                $perSegmen[$segmen] = ($perSegmen[$segmen] ?? 0.0) + (float) $r->total;
            }

            $hasil[$i] = $perSegmen;
        }

        return $hasil;
    }

    /**
     * (SML+NPL) pada indeks bulan tertentu untuk satu scope.
     *
     * @param  array<int, array<string, float>|null>  $posisi
     */
    private function smlNpl(array $posisi, int $indeks, ?string $segmen): ?float
    {
        $isi = $posisi[$indeks] ?? null;

        if ($isi === null) {
            return null;
        }

        return $segmen === null ? array_sum($isi) : ($isi[$segmen] ?? 0.0);
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
            'nilai' => round($aktual - $pembanding, 6),
            'persen' => $pembanding == 0.0 ? null : round(($aktual - $pembanding) / abs($pembanding) * 100, 2),
        ];
    }

    private function adaPh(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId): bool
    {
        return $this->filterOrganisasi(Ph::query(), $areaId, $cabangId, $ukerId)
            ->whereBetween('periode', ["{$tahun}-01-01", "{$tahun}-12-31"])
            ->exists();
    }

    /**
     * Rollup Region Office — dipakai test & dokumentasi.
     *
     * Berbeda dari Simpanan/Recovery, domain ini TIDAK mengecualikannya dari
     * kalkulasi (lihat IKUTKAN_ROLLUP).
     */
    public static function rollupId(): int
    {
        return Region::OFFICE_ID;
    }
}
