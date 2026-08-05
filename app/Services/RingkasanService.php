<?php

namespace App\Services;

use App\Models\Area;
use App\Services\Concerns\MenyaringOrganisasi;
use Illuminate\Support\Carbon;

/**
 * Halaman landing /dashboard: satu kartu ringkas per domain + rasio lintas domain.
 *
 * PRINSIP: service ini TIDAK menulis query domain apa pun. Ia hanya MENDELEGASI
 * ke service tiap domain (SimpananService, PinjamanService, …), mengambil kartu
 * "Total"-nya, lalu menyusunnya jadi ringkasan. Satu-satunya kalkulasi milik
 * service ini adalah dua rasio turunan (%CASA, %LDR) — aritmetika atas angka
 * yang sudah dihitung service domain, bukan query baru.
 *
 * Kontrak service (sama seperti domain lain): tidak menyentuh Request/auth(),
 * tidak mengembalikan response HTTP. Filter (area/cabang/uker) diterima sebagai
 * parameter biasa dan diteruskan apa adanya ke tiap service — lingkupnya sudah
 * dikunci middleware `scope` sebelum controller memanggil.
 *
 * TANGGAL POSISI. Cadence tiap domain berbeda (harian, bulanan, telat rilis),
 * jadi setiap kartu MERESOLUSI sendiri posisi terakhir yang tersedia <= tanggal
 * yang diminta lewat helper `tanggalTersediaHingga` / `periodeTersedia` milik
 * service domain. Tanpa itu, memilih satu tanggal untuk delapan domain akan
 * membuat sebagian kartu tampak 0 padahal hanya belum ada datanya pada hari itu
 * (§7 PRD: tidak ada data ≠ nol). `per` di tiap kartu melaporkan tanggal/bulan
 * efektif yang dipakainya.
 */
class RingkasanService
{
    use MenyaringOrganisasi;

    public function __construct(
        private readonly SimpananService $simpanan,
        private readonly PinjamanService $pinjaman,
        private readonly RecoveryService $recovery,
        private readonly LabaService $laba,
        private readonly PhNetDgService $phNetDg,
        private readonly EdcService $edc,
        private readonly QrisService $qris,
    ) {}

    /**
     * Opsi filter + tanggal maksimum (posisi terbaru lintas domain harian).
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
        ];
    }

    /**
     * Tanggal posisi terbaru di antara seluruh domain harian.
     *
     * Perbandingan string 'Y-m-d' setara dengan perbandingan kronologis, jadi
     * max() lexicographic sudah benar.
     */
    public function tanggalTerakhir(): ?string
    {
        $kandidat = array_filter([
            $this->simpanan->tanggalTerakhir(),
            $this->pinjaman->tanggalTerakhir(),
            $this->recovery->tanggalTerakhir(),
            $this->edc->tanggalTerakhir(),
            $this->qris->tanggalTerakhir(),
        ]);

        return $kandidat === [] ? null : collect($kandidat)->max();
    }

    /**
     * Ringkasan semua domain + rasio, untuk satu tanggal posisi & lingkup filter.
     *
     * @return array<string, mixed>
     */
    public function ringkasan(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        [$kartuSimpanan, $dpk, $casa] = $this->simpananKartu($tanggal, $areaId, $cabangId, $ukerId);
        [$kartuPinjaman, $os] = $this->pinjamanKartu($tanggal, $areaId, $cabangId, $ukerId);

        $kartu = [
            $kartuSimpanan,
            $kartuPinjaman,
            $this->recoveryKartu($tanggal, $areaId, $cabangId, $ukerId),
            $this->labaKartu($tanggal, $areaId, $cabangId, $ukerId),
            $this->phNetDgKartu(PhNetDgService::MODE_PH, 'ph', 'PH', $tanggal, $areaId, $cabangId, $ukerId),
            $this->phNetDgKartu(PhNetDgService::MODE_NETDG, 'netdg', 'Net DG', $tanggal, $areaId, $cabangId, $ukerId),
            $this->merchantKartu($this->edc, 'edc', 'EDC · Sales Volume', $tanggal, $areaId, $cabangId, $ukerId),
            $this->merchantKartu($this->qris, 'qris', 'QRIS · Sales Volume', $tanggal, $areaId, $cabangId, $ukerId),
        ];

        return [
            'tanggal' => $tanggal,
            'kartu' => $kartu,
            'rasio' => [
                // %CASA = CASA / DPK — keduanya dari snapshot Simpanan yang sama.
                $this->rasio('casa', '%CASA', 'CASA / DPK', $casa, $dpk),
                // %LDR = OS Pinjaman / DPK — silang dua domain.
                $this->rasio('ldr', '%LDR', 'OS Pinjaman / DPK', $os, $dpk),
            ],
        ];
    }

    // ---------------------------------------------------------------------
    // Kartu per domain — masing-masing mengambil kartu "Total" dari snapshot
    // domainnya, lalu memetakannya ke bentuk kartu ringkasan yang seragam.
    // ---------------------------------------------------------------------

    /**
     * @return array{0: array<string, mixed>, 1: float|null, 2: float|null}
     *                                                                      [kartu DPK, nilai DPK (juta), nilai CASA (juta)] — DPK & CASA
     *                                                                      dipakai lagi untuk rasio.
     */
    private function simpananKartu(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tgl = $this->simpanan->tanggalTersediaHingga($tanggal, $areaId, $cabangId, $ukerId);

        if ($tgl === null) {
            return [$this->kartu('simpanan', 'DPK', 'simpanan'), null, null];
        }

        $snapshot = $this->simpanan->snapshot($tgl, $areaId, $cabangId, $ukerId);
        $total = $this->cari($snapshot['kartu'], 'key', 'total');
        $casa = $this->cari($snapshot['kartu'], 'key', 'casa');

        return [
            $this->kartu('simpanan', 'DPK', 'simpanan', [
                'nilai' => $total['nilai'],
                'delta' => $total['delta'],
                'target' => $total['target'],
                'pencapaian' => $total['pencapaian'],
                'gap' => $total['gap'],
                'per' => $snapshot['tanggal'],
            ]),
            $total['nilai'],
            $casa['nilai'] ?? null,
        ];
    }

    /**
     * @return array{0: array<string, mixed>, 1: float|null} [kartu OS, nilai OS (juta)]
     */
    private function pinjamanKartu(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tgl = $this->pinjaman->tanggalTersediaHingga($tanggal, $areaId, $cabangId, $ukerId);

        if ($tgl === null) {
            return [$this->kartu('pinjaman', 'Pinjaman (OS)', 'pinjaman'), null];
        }

        $snapshot = $this->pinjaman->snapshot($tgl, PinjamanService::TAB_TOTAL, $areaId, $cabangId, $ukerId);
        $total = $this->cari($snapshot['kartu'], 'key', 'total');

        return [
            $this->kartu('pinjaman', 'Pinjaman (OS)', 'pinjaman', [
                'nilai' => $total['nilai'],
                'delta' => $total['delta'],
                'label_delta' => $snapshot['label_delta'],
                'inverse' => $snapshot['inverse'],
                'target' => $total['target'],
                'pencapaian' => $total['pencapaian'],
                'gap' => $total['gap'],
                'per' => $snapshot['tanggal'],
            ]),
            $total['nilai'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function recoveryKartu(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tgl = $this->recovery->tanggalTersediaHingga($tanggal, $areaId, $cabangId, $ukerId);

        if ($tgl === null) {
            return $this->kartu('recovery', 'Recovery', 'recovery');
        }

        $snapshot = $this->recovery->snapshot($tgl, $areaId, $cabangId, $ukerId);
        $total = $this->cari($snapshot['kartu'], 'key', 'total');

        return $this->kartu('recovery', 'Recovery', 'recovery', [
            'nilai' => $total['nilai'],
            'delta' => $total['delta'],
            'target' => $total['target'],
            'pencapaian' => $total['pencapaian'],
            'gap' => $total['gap'],
            'per' => $snapshot['tanggal'],
        ]);
    }

    /**
     * Laba — bulanan, mundur otomatis ke bulan terakhir yang tersedia <= posisi.
     *
     * @return array<string, mixed>
     */
    private function labaKartu(string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $posisi = Carbon::parse($tanggal);
        $periode = $this->laba->periodeTersedia((int) $posisi->year, (int) $posisi->month, $areaId, $cabangId, $ukerId);

        if ($periode === null) {
            return $this->kartu('laba', 'Laba', 'laba');
        }

        $snapshot = $this->laba->snapshot($periode['tahun'], $periode['bulan'], $areaId, $cabangId, $ukerId);
        $total = $this->cari($snapshot['kartu'], 'key', 'total');

        return $this->kartu('laba', 'Laba', 'laba', [
            'nilai' => $total['nilai'],
            'delta' => $total['delta'],
            'label_delta' => $snapshot['label_delta'],
            'target' => $total['target'],
            'pencapaian' => $total['pencapaian'],
            'gap' => $total['gap'],
            'per' => $this->labelPeriode($periode['tahun'], $periode['bulan']),
        ]);
    }

    /**
     * PH / Net DG — bulanan, tanpa RKA. Headline-nya AKUMULASI YTD; deltanya
     * YoY YTD (akumulasi tahun ini vs tahun lalu pada bulan yang sama).
     *
     * @return array<string, mixed>
     */
    private function phNetDgKartu(string $mode, string $key, string $judul, string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $periode = $this->phNetDg->periodeTersedia($mode, $tanggal, $areaId, $cabangId, $ukerId);

        $tanpaTarget = ['tampilkan_target' => false, 'label_delta' => [['key' => 'yoy', 'label' => 'YoY YTD']]];

        if ($periode === null) {
            return $this->kartu($key, $judul, 'recovery-ph', $tanpaTarget);
        }

        $snapshot = $this->phNetDg->snapshot($mode, $periode, $areaId, $cabangId, $ukerId);
        $total = $this->cari($snapshot['kartu'], 'key', 'total');

        // Nilai PH/Net DG dari snapshot sudah dalam JUTA — selisih langsung, tanpa Satuan.
        return $this->kartu($key, $judul, 'recovery-ph', $tanpaTarget + [
            'nilai' => $total['akumulasi'],
            'delta' => ['yoy' => $this->selisih($total['akumulasi'], $total['akumulasi_tahun_lalu'])],
            'per' => $this->labelPeriode($snapshot['tahun'], $snapshot['bulan']),
        ]);
    }

    /**
     * Merchant EDC/QRIS — headline KPI Sales Volume (flow rupiah, ber-target).
     *
     * @return array<string, mixed>
     */
    private function merchantKartu(MerchantService $service, string $key, string $judul, string $tanggal, ?int $areaId, ?int $cabangId, ?int $ukerId): array
    {
        $tgl = $service->tanggalTersediaHingga($tanggal, $areaId, $cabangId, $ukerId);

        if ($tgl === null) {
            return $this->kartu($key, $judul, 'merchant');
        }

        $snapshot = $service->snapshot($tgl, $areaId, $cabangId, $ukerId);
        $sv = $this->cari($snapshot['kartu'], 'kode', 'SALES_VOLUME');

        if ($sv === null) {
            return $this->kartu($key, $judul, 'merchant', ['per' => $snapshot['tanggal']]);
        }

        return $this->kartu($key, $judul, 'merchant', [
            'nilai' => $sv['nilai'],
            'delta' => $sv['delta'],
            'label_delta' => $sv['label_delta'],
            'inverse' => $sv['inverse'],
            'rupiah' => $sv['rupiah'],
            'tampilkan_target' => $sv['punya_target'],
            'target' => $sv['target'],
            'pencapaian' => $sv['pencapaian'],
            'gap' => $sv['gap'],
            'per' => $snapshot['tanggal'],
        ]);
    }

    // ---------------------------------------------------------------------

    /**
     * Kartu ringkasan seragam. Default aman (nilai null, ber-target, rupiah),
     * di-override lewat $isi. `route` = nama route Ziggy halaman domainnya,
     * dipakai frontend untuk menautkan kartu.
     *
     * @param  array<string, mixed>  $isi
     * @return array<string, mixed>
     */
    private function kartu(string $key, string $judul, string $route, array $isi = []): array
    {
        return array_merge([
            'key' => $key,
            'judul' => $judul,
            'route' => $route,
            'nilai' => null,
            'delta' => [],
            'label_delta' => null,
            'target' => null,
            'pencapaian' => null,
            'gap' => null,
            'inverse' => false,
            'rupiah' => true,
            'tampilkan_target' => true,
            'per' => null,
        ], $isi);
    }

    /**
     * Rasio persen dari dua nilai (juta). Penyebut null/0 → null (tak terdefinisi),
     * bukan 0 — sesuai §7 PRD.
     *
     * @return array<string, mixed>
     */
    private function rasio(string $key, string $judul, string $deskripsi, ?float $pembilang, ?float $penyebut): array
    {
        $nilai = ($pembilang === null || $penyebut === null || $penyebut == 0.0)
            ? null
            : round($pembilang / $penyebut * 100, 2);

        return [
            'key' => $key,
            'judul' => $judul,
            'deskripsi' => $deskripsi,
            'nilai' => $nilai,
            'pembilang' => $pembilang,
            'penyebut' => $penyebut,
        ];
    }

    /**
     * Selisih dua nilai yang SUDAH dalam juta (mis. akumulasi PH/Net DG).
     *
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

    /** @var array<int, string> */
    private const NAMA_BULAN = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    private function labelPeriode(int $tahun, int $bulan): string
    {
        return (self::NAMA_BULAN[$bulan] ?? (string) $bulan).' '.$tahun;
    }

    /**
     * Cari satu kartu di list berdasarkan field (key/kode).
     *
     * @param  list<array<string, mixed>>  $kartu
     * @return array<string, mixed>|null
     */
    private function cari(array $kartu, string $field, string $nilai): ?array
    {
        foreach ($kartu as $k) {
            if (($k[$field] ?? null) === $nilai) {
                return $k;
            }
        }

        return null;
    }
}
