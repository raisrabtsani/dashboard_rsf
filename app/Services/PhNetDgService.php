<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Ph;
use App\Models\Pinjaman;
use App\Models\Region;
use App\Models\Uker;
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
 * Dihitung on-the-fly dari posisi akhir bulan di tabel `pinjaman` ditambah
 * PH pada bulan yang sama. Filter SML/NPL berfungsi sebagai pemisah kualitas:
 *
 *     NetDG_SML(N) = Posisi SML akhir bulan N + PH(N)
 *     NetDG_NPL(N) = Posisi NPL akhir bulan N + PH(N)
 *
 * SML tidak mencampurkan NPL, dan NPL tidak mencampurkan SML. Mode default
 * adalah SML. Bulan tanpa posisi pinjaman tetap bernilai NULL — bukan 0.
 *
 * PH & Net DG TIDAK punya RKA: tidak ada target, pencapaian, maupun gap.
 */
class PhNetDgService
{
    use MenyaringOrganisasi;

    public const MODE_PH = 'ph';

    public const MODE_NETDG = 'netdg';

    public const NETDG_SML = 'sml';

    public const NETDG_NPL = 'npl';

    /** @var list<string> */
    public const MODE = [self::MODE_PH, self::MODE_NETDG];

    /** @var list<string> */
    public const NETDG_KUALITAS = [self::NETDG_SML, self::NETDG_NPL];

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
    public function snapshot(string $mode, string $periode, ?int $areaId, ?int $cabangId, ?int $ukerId, string $netDgKualitas = self::NETDG_SML): array
    {
        $posisi = Carbon::parse($periode)->endOfMonth();
        $bulan = (int) $posisi->month;

        $netDgKualitas = $this->normalisasiKualitasNetDg($netDgKualitas);
        $tahunIni = $this->deret($mode, (int) $posisi->year, $areaId, $cabangId, $ukerId, $netDgKualitas);
        $tahunLalu = $this->deret($mode, (int) $posisi->year - 1, $areaId, $cabangId, $ukerId, $netDgKualitas);

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
                // PH adalah flow sehingga kartu memakai akumulasi YTD.
                // NET DG adalah posisi akhir bulan, sehingga TIDAK boleh dijumlahkan
                // antarbulan. Menjumlah posisi Jan..Jul menyebabkan angka membengkak
                // (misalnya 23 T) walaupun posisi Juli hanya beberapa triliun.
                'akumulasi' => $mode === self::MODE_NETDG ? $nilai : $ini['akumulasi'][$bulan - 1],
                'akumulasi_tahun_lalu' => $mode === self::MODE_NETDG ? $nilaiLalu : $lalu['akumulasi'][$bulan - 1],
                'delta' => [
                    'mom' => $this->selisih($nilai, $sebelumnya),
                    'yoy' => $this->selisih($nilai, $nilaiLalu),
                ],
            ];
        }

        return [
            'mode' => $mode,
            'netdg_kualitas' => $mode === self::MODE_NETDG ? $netDgKualitas : null,
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
    public function chart(string $mode, string $periode, ?int $areaId, ?int $cabangId, ?int $ukerId, string $netDgKualitas = self::NETDG_SML): array
    {
        $posisi = Carbon::parse($periode)->endOfMonth();
        $bulan = (int) $posisi->month;
        $tahun = (int) $posisi->year;

        $netDgKualitas = $this->normalisasiKualitasNetDg($netDgKualitas);
        $ini = $this->deret($mode, $tahun, $areaId, $cabangId, $ukerId, $netDgKualitas);
        $lalu = $this->deret($mode, $tahun - 1, $areaId, $cabangId, $ukerId, $netDgKualitas);

        $potong = fn (array $n) => array_slice($n, 0, $bulan);

        $seri = [];

        foreach (self::scope() as $s) {
            $bulananLalu = $potong($lalu[$s['key']]['bulanan']);
            $bulananIni = $potong($ini[$s['key']]['bulanan']);

            $seri[$s['key']] = [
                'judul' => $s['judul'],
                'label' => array_map(fn (int $b) => Bulan::PENDEK[$b], range(1, $bulan)),
                'tahun_lalu' => [
                    'tahun' => $tahun - 1,
                    // PH: batang = flow bulanan, garis = akumulasi.
                    // NET DG: batang = perubahan bulanan, garis = posisi akhir bulan.
                    'bulanan' => $mode === self::MODE_NETDG ? $this->perubahanBulanan($bulananLalu) : $bulananLalu,
                    'akumulasi' => $mode === self::MODE_NETDG ? $bulananLalu : $potong($lalu[$s['key']]['akumulasi']),
                ],
                'tahun_ini' => [
                    'tahun' => $tahun,
                    'bulanan' => $mode === self::MODE_NETDG ? $this->perubahanBulanan($bulananIni) : $bulananIni,
                    'akumulasi' => $mode === self::MODE_NETDG ? $bulananIni : $potong($ini[$s['key']]['akumulasi']),
                ],
            ];
        }

        return [
            'mode' => $mode,
            'netdg_kualitas' => $mode === self::MODE_NETDG ? $netDgKualitas : null,
            'tahun' => $tahun,
            'bulan' => $bulan,
            'seri' => $seri,
        ];
    }


    /**
     * Tabel kinerja cabang / uker untuk PH maupun Net DG.
     *
     * @return array<string, mixed>
     */
    public function branchPencapaian(string $mode, string $periode, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $scope = null, string $netDgKualitas = self::NETDG_SML): array
    {
        $posisi = Carbon::parse($periode)->endOfMonth();
        $perUker = $cabangId !== null;
        $kolom = $perUker ? 'uker_id' : 'cabang_id';
        $segmen = $this->scopeToSegmen($scope);
        $netDgKualitas = $this->normalisasiKualitasNetDg($netDgKualitas);

        $bulanLalu = $posisi->copy()->subMonthNoOverflow()->endOfMonth();
        $tahunLalu = $posisi->copy()->subYear()->endOfMonth();
        $desemberLalu = $posisi->copy()->subYear()->endOfYear();

        $ambil = fn (Carbon $p) => $mode === self::MODE_PH
            ? $this->nilaiPhPerEntitas($p, $kolom, $areaId, $cabangId, $ukerId, $segmen)
            : $this->nilaiNetDgPerEntitas($p, $kolom, $areaId, $cabangId, $ukerId, $segmen, $netDgKualitas);

        $aktual = $ambil($posisi);
        $periodeLalu = $ambil($tahunLalu);
        $desember = $ambil($desemberLalu);
        $mtdBasis = $ambil($bulanLalu);

        $ids = $aktual->keys();
        $entitas = $perUker
            ? Uker::query()->with('cabang.area')->whereIn('id', $ids)->get()->keyBy('id')
            : Cabang::query()->with('area')->whereIn('id', $ids)->get()->keyBy('id');

        $baris = $aktual->map(function ($nilai, $entitasId) use ($periodeLalu, $desember, $mtdBasis, $entitas, $perUker) {
            $kantor = $entitas->get($entitasId);
            $cabang = $perUker ? $kantor?->cabang : $kantor;
            $area = $cabang?->area;
            $aktualNilai = $nilai === null ? null : (float) $nilai;
            $laluNilai = $periodeLalu->has($entitasId) ? $periodeLalu[$entitasId] : null;
            $desNilai = $desember->has($entitasId) ? $desember[$entitasId] : null;
            $mtdNilai = $mtdBasis->has($entitasId) ? $mtdBasis[$entitasId] : null;

            return [
                'id' => (int) $entitasId,
                'nama' => $kantor?->nama ?? (string) $entitasId,
                'cabang_nama' => $perUker ? $cabang?->nama : null,
                'area_nama' => $area?->nama,
                'periode_lalu' => $laluNilai,
                'desember_lalu' => $desNilai,
                'nilai' => $aktualNilai,
                'mtd' => $this->selisih($aktualNilai, $mtdNilai),
                'yoy' => $this->selisih($aktualNilai, $laluNilai),
            ];
        })->values()->sortByDesc('nilai')->values()->all();

        return [
            'mode' => $mode,
            'netdg_kualitas' => $mode === self::MODE_NETDG ? $netDgKualitas : null,
            'periode' => $posisi->toDateString(),
            'periode_lalu' => $tahunLalu->toDateString(),
            'desember_lalu' => $desemberLalu->toDateString(),
            'grouping' => $perUker ? 'uker' : 'cabang',
            'scope' => $scope ?: 'total',
            'baris' => $baris,
        ];
    }

    /**
     * Deret 12 bulan per scope untuk satu tahun.
     *
     * @return array<string, array{bulanan: list<float|null>, akumulasi: list<float|null>}>
     */
    public function deret(string $mode, int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId, string $netDgKualitas = self::NETDG_SML): array
    {
        return $mode === self::MODE_PH
            ? $this->deretPh($tahun, $areaId, $cabangId, $ukerId)
            : $this->deretNetDg($tahun, $areaId, $cabangId, $ukerId, $this->normalisasiKualitasNetDg($netDgKualitas));
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
     * NET DG: posisi kualitas terpilih pada akhir bulan + PH bulan itu.
     *
     * @return array<string, array{bulanan: list<float|null>, akumulasi: list<float|null>}>
     */
    private function deretNetDg(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId, string $netDgKualitas): array
    {
        // Butuh 12 posisi akhir bulan: Januari sampai Desember tahun berjalan.
        $posisi = $this->posisiSmlNplAkhirBulan($tahun, $areaId, $cabangId, $ukerId, $netDgKualitas);
        $ph = $this->phPerBulanSegmen($tahun, $areaId, $cabangId, $ukerId);

        $hasil = [];

        foreach (self::scope() as $s) {
            $bulanan = [];

            for ($b = 1; $b <= 12; $b++) {
                $sekarang = $this->smlNpl($posisi, $b, $s['segmen']);

                // Posisi kualitas terpilih wajib tersedia pada bulan itu.
                if ($sekarang === null) {
                    $bulanan[] = null;

                    continue;
                }

                $phBulan = $ph[$b] ?? null;
                $phNilai = $phBulan === null
                    ? 0.0
                    : ($s['segmen'] === null ? array_sum($phBulan) : ($phBulan[$s['segmen']] ?? 0.0));

                // Filter adalah pemisah kualitas, bukan penggabung:
                // SML = posisi SML akhir bulan + PH bulan yang sama.
                // NPL = posisi NPL akhir bulan + PH bulan yang sama.
                $bulanan[] = Satuan::toJuta($sekarang + $phNilai);
            }

            $hasil[$s['key']] = [
                'bulanan' => $bulanan,
                // NET DG merupakan posisi/saldo akhir bulan, bukan flow.
                // Karena itu seri posisi tidak boleh diakumulasi antarbulan.
                'akumulasi' => $bulanan,
            ];
        }

        return $hasil;
    }

    /**
     * Perubahan posisi bulan ke bulan untuk batang pada chart NET DG.
     * Bulan pertama bernilai null karena basis Desember tahun sebelumnya tidak
     * berada di dalam deret tahun yang sama.
     *
     * @param  list<float|null>  $posisi
     * @return list<float|null>
     */
    private function perubahanBulanan(array $posisi): array
    {
        $hasil = [];
        $sebelumnya = null;

        foreach ($posisi as $indeks => $nilai) {
            $hasil[] = $indeks === 0 || $nilai === null || $sebelumnya === null
                ? null
                : round($nilai - $sebelumnya, 6);
            $sebelumnya = $nilai;
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
     * Posisi basis Net DG akhir bulan, per segmen kanonik.
     *
     * Indeks 1..12 = Januari..Desember tahun berjalan.
     * Nilai null berarti posisinya belum ada di tabel `pinjaman`.
     *
     * @return array<int, array<string, float>|null>
     */
    private function posisiSmlNplAkhirBulan(int $tahun, ?int $areaId, ?int $cabangId, ?int $ukerId, string $netDgKualitas): array
    {
        $awal = Carbon::create($tahun, 1, 1)->startOfMonth();
        $akhir = Carbon::create($tahun, 12, 31)->endOfMonth();

        // Tanggal tersedia dalam rentang, dikelompokkan per bulan di PHP.
        $tanggalQuery = $this->filterOrganisasi(Pinjaman::query(), $areaId, $cabangId, $ukerId)
            ->whereBetween('tanggal', [$awal->toDateString(), $akhir->toDateString()]);
        $this->terapkanKualitasNetDg($tanggalQuery, $netDgKualitas);

        $tanggal = $tanggalQuery
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
            return array_fill(1, 12, null);
        }

        $saldoQuery = $this->filterOrganisasi(Pinjaman::query(), $areaId, $cabangId, $ukerId)
            ->whereIn('tanggal', array_values($akhirBulan));
        $this->terapkanKualitasNetDg($saldoQuery, $netDgKualitas);

        $saldo = $saldoQuery
            ->groupBy('tanggal', 'segmen')
            ->selectRaw('tanggal, segmen, SUM(baki_debet) as total')
            ->get()
            ->groupBy(fn ($r) => Carbon::parse($r->tanggal)->toDateString());

        $hasil = [];

        for ($i = 1; $i <= 12; $i++) {
            $bulan = Carbon::create($tahun, $i, 1);
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
     * Posisi basis Net DG pada indeks bulan tertentu untuk satu scope.
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
     * @return \Illuminate\Support\Collection<int|string, float>
     */
    private function nilaiPhPerEntitas(Carbon $periode, string $kolom, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $segmen): \Illuminate\Support\Collection
    {
        $query = $this->filterOrganisasi(Ph::query(), $areaId, $cabangId, $ukerId)
            ->where('periode', $periode->copy()->endOfMonth()->toDateString());

        if ($segmen !== null) {
            $query->whereIn('segmen', Segmen::RAW[$segmen] ?? [$segmen]);
        }

        return $query
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(saldo) as total")
            ->pluck('total', 'entitas_id')
            ->map(fn ($n) => Satuan::toJuta((float) $n));
    }

    /**
     * @return \Illuminate\Support\Collection<int|string, float>
     */
    private function nilaiNetDgPerEntitas(Carbon $periode, string $kolom, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $segmen, string $netDgKualitas): \Illuminate\Support\Collection
    {
        $akhirKini = $this->tanggalPosisiPinjamanAkhirBulan($periode, $areaId, $cabangId, $ukerId, $netDgKualitas);

        if ($akhirKini === null) {
            return collect();
        }

        $posisiKini = $this->posisiSmlNplPerEntitas($akhirKini, $kolom, $areaId, $cabangId, $ukerId, $segmen, $netDgKualitas);
        $ph = $this->nilaiPhPerEntitas($periode, $kolom, $areaId, $cabangId, $ukerId, $segmen);

        $ids = $posisiKini->keys()->merge($ph->keys())->unique()->values();

        return $ids->mapWithKeys(function ($id) use ($posisiKini, $ph) {
            $kini = $posisiKini->has($id) ? (float) $posisiKini[$id] : null;

            if ($kini === null) {
                return [];
            }

            $phRupiah = ($ph->has($id) ? (float) $ph[$id] : 0.0) * 1_000_000;

            return [$id => Satuan::toJuta($kini + $phRupiah)];
        });
    }

    /**
     * @return \Illuminate\Support\Collection<int|string, float>
     */
    private function posisiSmlNplPerEntitas(string $tanggal, string $kolom, ?int $areaId, ?int $cabangId, ?int $ukerId, ?string $segmen, string $netDgKualitas): \Illuminate\Support\Collection
    {
        $query = $this->filterOrganisasi(Pinjaman::query(), $areaId, $cabangId, $ukerId)
            ->where('tanggal', $tanggal);
        $this->terapkanKualitasNetDg($query, $netDgKualitas);

        if ($segmen !== null) {
            $query->whereIn('segmen', Segmen::RAW[$segmen] ?? [$segmen]);
        }

        return $query
            ->groupBy($kolom)
            ->selectRaw("{$kolom} as entitas_id, SUM(baki_debet) as total")
            ->pluck('total', 'entitas_id');
    }

    private function tanggalPosisiPinjamanAkhirBulan(Carbon $periode, ?int $areaId, ?int $cabangId, ?int $ukerId, string $netDgKualitas): ?string
    {
        $query = $this->filterOrganisasi(Pinjaman::query(), $areaId, $cabangId, $ukerId)
            ->whereBetween('tanggal', [
                $periode->copy()->startOfMonth()->toDateString(),
                $periode->copy()->endOfMonth()->toDateString(),
            ]);
        $this->terapkanKualitasNetDg($query, $netDgKualitas);

        $tanggal = $query->max('tanggal');

        return $tanggal === null ? null : Carbon::parse($tanggal)->toDateString();
    }

    private function normalisasiKualitasNetDg(string $kualitas): string
    {
        $kualitas = strtolower(trim($kualitas));

        return in_array($kualitas, self::NETDG_KUALITAS, true)
            ? $kualitas
            : self::NETDG_SML;
    }

    /**
     * Filter kualitas bersifat eksklusif: SML hanya mengambil SML dan NPL hanya
     * mengambil NPL. PH bulan yang sama ditambahkan setelah posisi dipisahkan.
     *
     * @return list<string>
     */
    private function kualitasPinjamanNetDg(string $kualitas): array
    {
        return $this->normalisasiKualitasNetDg($kualitas) === self::NETDG_NPL
            ? [Pinjaman::KUALITAS_NPL]
            : [Pinjaman::KUALITAS_SML];
    }

    /**
     * Terapkan kualitas secara case-insensitive dan tahan spasi sisa pada data
     * lama. SML dan NPL tetap eksklusif; query tidak pernah menggabungkan keduanya.
     */
    private function terapkanKualitasNetDg($query, string $kualitas): void
    {
        $kode = $this->kualitasPinjamanNetDg($kualitas)[0];
        $query->whereRaw('UPPER(TRIM(kualitas)) = ?', [mb_strtoupper($kode)]);
    }

    private function scopeToSegmen(?string $scope): ?string
    {
        $scope = strtolower(trim((string) $scope));

        return match ($scope) {
            'micro' => Segmen::MICRO,
            'sme' => Segmen::SME,
            'consumer' => Segmen::CONSUMER,
            default => null,
        };
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
