<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Cabang;
use App\Models\Pinjaman;
use App\Models\Recovery;
use App\Models\Simpanan;
use App\Services\Concerns\MenyaringOrganisasi;
use App\Support\Delta;
use App\Support\Satuan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            'trend' => $this->trendRegion($tanggal),
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
                $this->detailDanaPihakKetiga($tanggal),
                $this->detailPinjaman($tanggal),
                $this->detailSmlNominal($tanggal),
                $this->detailSmlRasio($tanggal),
                $this->detailNplNominal($tanggal),
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function detailDanaPihakKetiga(string $tanggal): array
    {
        $spesifikasi = [
            ['label' => 'Total Dana Pihak Ketiga', 'tables' => self::DPK, 'rka' => self::RKA_DPK],
            ['label' => 'Total Giro', 'tables' => self::DPK, 'rka' => self::RKA_DPK, 'filter' => ['produk' => [Simpanan::PRODUK_GIRO]]],
            ['label' => 'Total Tabungan', 'tables' => self::DPK, 'rka' => self::RKA_DPK, 'filter' => ['produk' => [Simpanan::PRODUK_TABUNGAN]]],
            ['label' => 'Total Deposito', 'tables' => self::DPK, 'rka' => self::RKA_DPK, 'filter' => ['produk' => [Simpanan::PRODUK_DEPOSITO]]],

            ['kind' => 'group', 'label' => 'Dana Pihak Ketiga Non Wholesale'],
            ['label' => 'Giro Non Wholesale', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_GIRO]]],
            ['label' => 'Tabungan Non Wholesale', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_TABUNGAN]]],
            ['label' => 'Deposito Non Wholesale', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_DEPOSITO]]],

            ['kind' => 'group', 'label' => 'Dana Pihak Ketiga Mikro'],
            ['label' => 'Giro Mikro', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_GIRO], 'segmentasi' => ['Mikro']]],
            ['label' => 'Tabungan Mikro', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_TABUNGAN], 'segmentasi' => ['Mikro']]],
            ['label' => 'Deposito Mikro', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_DEPOSITO], 'segmentasi' => ['Mikro']]],

            ['kind' => 'group', 'label' => 'Dana Pihak Ketiga Ritel'],
            ['label' => 'Giro Ritel', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_GIRO], 'segmentasi' => ['Ritel']]],
            ['label' => 'Tabungan Ritel', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_TABUNGAN], 'segmentasi' => ['Ritel']]],
            ['label' => 'Deposito Ritel', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_DEPOSITO], 'segmentasi' => ['Ritel']]],

            ['kind' => 'group', 'label' => 'Dana Pihak Ketiga Wholesale'],
            ['label' => 'Giro Wholesale', 'tables' => [['simpanan_wholesale', 'saldo']], 'rka' => [['rka_simpanan_wholesale', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_GIRO]]],
            ['label' => 'Tabungan Wholesale', 'tables' => [['simpanan_wholesale', 'saldo']], 'rka' => [['rka_simpanan_wholesale', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_TABUNGAN]]],
            ['label' => 'Deposito Wholesale', 'tables' => [['simpanan_wholesale', 'saldo']], 'rka' => [['rka_simpanan_wholesale', 'target']], 'filter' => ['produk' => [Simpanan::PRODUK_DEPOSITO]]],

            ['kind' => 'group', 'label' => 'CASA Total'],
            ['label' => 'CASA Non Wholesale', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => self::CASA]],
            ['label' => 'CASA Mikro', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => self::CASA, 'segmentasi' => ['Mikro']]],
            ['label' => 'CASA Ritel', 'tables' => [['simpanan', 'saldo']], 'rka' => [['rka_simpanan', 'target']], 'filter' => ['produk' => self::CASA, 'segmentasi' => ['Ritel']]],
            ['label' => 'CASA Wholesale', 'tables' => [['simpanan_wholesale', 'saldo']], 'rka' => [['rka_simpanan_wholesale', 'target']], 'filter' => ['produk' => self::CASA]],

            ['kind' => 'group', 'label' => '% CASA Total'],
            ['mode' => 'ratio', 'label' => '% CASA Non Wholesale', 'numTables' => [['simpanan', 'saldo']], 'numRka' => [['rka_simpanan', 'target']], 'numFilter' => ['produk' => self::CASA], 'denTables' => [['simpanan', 'saldo']], 'denRka' => [['rka_simpanan', 'target']]],
            ['mode' => 'ratio', 'label' => '% CASA Mikro', 'numTables' => [['simpanan', 'saldo']], 'numRka' => [['rka_simpanan', 'target']], 'numFilter' => ['produk' => self::CASA, 'segmentasi' => ['Mikro']], 'denTables' => [['simpanan', 'saldo']], 'denRka' => [['rka_simpanan', 'target']], 'denFilter' => ['segmentasi' => ['Mikro']]],
            ['mode' => 'ratio', 'label' => '% CASA Ritel', 'numTables' => [['simpanan', 'saldo']], 'numRka' => [['rka_simpanan', 'target']], 'numFilter' => ['produk' => self::CASA, 'segmentasi' => ['Ritel']], 'denTables' => [['simpanan', 'saldo']], 'denRka' => [['rka_simpanan', 'target']], 'denFilter' => ['segmentasi' => ['Ritel']]],
            ['mode' => 'ratio', 'label' => '% CASA Wholesale', 'numTables' => [['simpanan_wholesale', 'saldo']], 'numRka' => [['rka_simpanan_wholesale', 'target']], 'numFilter' => ['produk' => self::CASA], 'denTables' => [['simpanan_wholesale', 'saldo']], 'denRka' => [['rka_simpanan_wholesale', 'target']]],
        ];

        return $this->tabelRincianMetrik('dpk-detail', 'Detail Dana Pihak Ketiga', self::DPK, $tanggal, $spesifikasi);
    }

    /** @return array<string,mixed> */
    private function detailPinjaman(string $tanggal): array
    {
        $spesifikasi = [
            ['label' => 'Total Pinjaman', 'tables' => self::PINJAMAN, 'rka' => self::RKA_PINJAMAN],
            ['label' => 'Total Non Commercial', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']]],
            ['kind' => 'group', 'label' => 'Mikro'],
            ['label' => 'Mikro', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmen' => ['Mikro']]],
            ['label' => 'Kupedes Komersial', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['Kupedes Komersial']]],
            ['label' => 'Briguna Mikro', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['Briguna Mikro']]],
            ['label' => 'KUR Mikro', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['KUR Mikro']]],
            ['label' => 'KPP', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['KPP']]],
            ['label' => 'KUR Kecil', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['KUR Kecil']]],
            ['kind' => 'group', 'label' => 'Small & Medium'],
            ['label' => 'Small', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmen' => ['Kecil']]],
            ['label' => 'Non Cash Collateral', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['Non Cash Collateral']]],
            ['label' => 'Cash Collateral', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['Cash Collateral']]],
            ['label' => 'Medium', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmen' => ['Menengah']]],
            ['kind' => 'group', 'label' => 'Commercial'],
            ['label' => 'Commercial', 'tables' => [['pinjaman_commercial', 'baki_debet']], 'rka' => [['rka_pinjaman_commercial', 'target']]],
            ['kind' => 'group', 'label' => 'Consumer'],
            ['label' => 'Consumer', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmen' => ['Konsumer', 'Consumer']]],
            ['label' => 'Briguna', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['Briguna']]],
            ['label' => 'Non Briguna', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['Non Briguna']]],
            ['label' => 'KPR', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['KPR']]],
            ['label' => 'KKB', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['KKB']]],
        ];

        return $this->tabelRincianMetrik('pinjaman-detail', 'Detail Pinjaman', self::PINJAMAN, $tanggal, $spesifikasi);
    }

    /** @return array<string,mixed> */
    private function detailSmlNominal(string $tanggal): array
    {
        return $this->tabelRincianMetrik('sml-detail', 'Detail SML', self::PINJAMAN, $tanggal, $this->spesifikasiPinjamanBerkualitas(Pinjaman::KUALITAS_SML), inverse: true, mode: 'dtd');
    }

    /** @return array<string,mixed> */
    private function detailNplNominal(string $tanggal): array
    {
        return $this->tabelRincianMetrik('npl-detail', 'Detail NPL', self::PINJAMAN, $tanggal, $this->spesifikasiPinjamanBerkualitas(Pinjaman::KUALITAS_NPL), inverse: true, mode: 'dtd');
    }

    /** @return array<string,mixed> */
    private function detailSmlRasio(string $tanggal): array
    {
        $spesifikasi = [
            ['mode' => 'ratio', 'label' => 'Total Pinjaman', 'numTables' => self::PINJAMAN, 'numRka' => self::RKA_PINJAMAN, 'numFilter' => ['kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => self::PINJAMAN, 'denRka' => self::RKA_PINJAMAN],
            ['mode' => 'ratio', 'label' => 'Total Non Commercial', 'numTables' => [['pinjaman', 'baki_debet']], 'numRka' => [['rka_pinjaman', 'target']], 'numFilter' => ['kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman', 'baki_debet']], 'denRka' => [['rka_pinjaman', 'target']]],
            ['kind' => 'group', 'label' => 'Mikro'],
            ['mode' => 'ratio', 'label' => 'Mikro', 'numTables' => [['pinjaman', 'baki_debet']], 'numRka' => [['rka_pinjaman', 'target']], 'numFilter' => ['segmen' => ['Mikro'], 'kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman', 'baki_debet']], 'denRka' => [['rka_pinjaman', 'target']], 'denFilter' => ['segmen' => ['Mikro']]],
            ['mode' => 'ratio', 'label' => 'Small', 'numTables' => [['pinjaman', 'baki_debet']], 'numRka' => [['rka_pinjaman', 'target']], 'numFilter' => ['segmen' => ['Kecil'], 'kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman', 'baki_debet']], 'denRka' => [['rka_pinjaman', 'target']], 'denFilter' => ['segmen' => ['Kecil']]],
            ['mode' => 'ratio', 'label' => 'Medium', 'numTables' => [['pinjaman', 'baki_debet']], 'numRka' => [['rka_pinjaman', 'target']], 'numFilter' => ['segmen' => ['Menengah'], 'kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman', 'baki_debet']], 'denRka' => [['rka_pinjaman', 'target']], 'denFilter' => ['segmen' => ['Menengah']]],
            ['mode' => 'ratio', 'label' => 'Commercial', 'numTables' => [['pinjaman_commercial', 'baki_debet']], 'numRka' => [['rka_pinjaman_commercial', 'target']], 'numFilter' => ['kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman_commercial', 'baki_debet']], 'denRka' => [['rka_pinjaman_commercial', 'target']]],
            ['kind' => 'group', 'label' => 'Consumer'],
            ['mode' => 'ratio', 'label' => 'Consumer', 'numTables' => [['pinjaman', 'baki_debet']], 'numRka' => [['rka_pinjaman', 'target']], 'numFilter' => ['segmen' => ['Konsumer', 'Consumer'], 'kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman', 'baki_debet']], 'denRka' => [['rka_pinjaman', 'target']], 'denFilter' => ['segmen' => ['Konsumer', 'Consumer']]],
            ['mode' => 'ratio', 'label' => 'Briguna', 'numTables' => [['pinjaman', 'baki_debet']], 'numRka' => [['rka_pinjaman', 'target']], 'numFilter' => ['segmentasi' => ['Briguna'], 'kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman', 'baki_debet']], 'denRka' => [['rka_pinjaman', 'target']], 'denFilter' => ['segmentasi' => ['Briguna']]],
            ['mode' => 'ratio', 'label' => 'Non Briguna', 'numTables' => [['pinjaman', 'baki_debet']], 'numRka' => [['rka_pinjaman', 'target']], 'numFilter' => ['segmentasi' => ['Non Briguna'], 'kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman', 'baki_debet']], 'denRka' => [['rka_pinjaman', 'target']], 'denFilter' => ['segmentasi' => ['Non Briguna']]],
            ['mode' => 'ratio', 'label' => 'KPR', 'numTables' => [['pinjaman', 'baki_debet']], 'numRka' => [['rka_pinjaman', 'target']], 'numFilter' => ['segmentasi' => ['KPR'], 'kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman', 'baki_debet']], 'denRka' => [['rka_pinjaman', 'target']], 'denFilter' => ['segmentasi' => ['KPR']]],
            ['mode' => 'ratio', 'label' => 'KKB', 'numTables' => [['pinjaman', 'baki_debet']], 'numRka' => [['rka_pinjaman', 'target']], 'numFilter' => ['segmentasi' => ['KKB'], 'kualitas' => [Pinjaman::KUALITAS_SML]], 'denTables' => [['pinjaman', 'baki_debet']], 'denRka' => [['rka_pinjaman', 'target']], 'denFilter' => ['segmentasi' => ['KKB']]],
        ];

        return $this->tabelRincianMetrik('psml-detail', 'Detail % SML', self::PINJAMAN, $tanggal, $spesifikasi, inverse: true, mode: 'ratio');
    }

    /** @return list<array<string,mixed>> */
    private function spesifikasiPinjamanBerkualitas(string $kualitas): array
    {
        return [
            ['label' => 'Total Pinjaman', 'tables' => self::PINJAMAN, 'rka' => self::RKA_PINJAMAN, 'filter' => ['kualitas' => [$kualitas]]],
            ['label' => 'Total Non Commercial', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['kualitas' => [$kualitas]]],
            ['kind' => 'group', 'label' => 'Mikro'],
            ['label' => 'Mikro', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmen' => ['Mikro'], 'kualitas' => [$kualitas]]],
            ['label' => 'Small', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmen' => ['Kecil'], 'kualitas' => [$kualitas]]],
            ['label' => 'Medium', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmen' => ['Menengah'], 'kualitas' => [$kualitas]]],
            ['label' => 'Commercial', 'tables' => [['pinjaman_commercial', 'baki_debet']], 'rka' => [['rka_pinjaman_commercial', 'target']], 'filter' => ['kualitas' => [$kualitas]]],
            ['kind' => 'group', 'label' => 'Consumer'],
            ['label' => 'Consumer', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmen' => ['Konsumer', 'Consumer'], 'kualitas' => [$kualitas]]],
            ['label' => 'Briguna', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['Briguna'], 'kualitas' => [$kualitas]]],
            ['label' => 'Non Briguna', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['Non Briguna'], 'kualitas' => [$kualitas]]],
            ['label' => 'KPR', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['KPR'], 'kualitas' => [$kualitas]]],
            ['label' => 'KKB', 'tables' => [['pinjaman', 'baki_debet']], 'rka' => [['rka_pinjaman', 'target']], 'filter' => ['segmentasi' => ['KKB'], 'kualitas' => [$kualitas]]],
        ];
    }

    /** @return array<string,mixed> */
    private function tabelRincianMetrik(string $key, string $judul, array $basisTabel, string $tanggal, array $spesifikasi, bool $inverse = false, string $mode = 'amount'): array
    {
        $periode = $this->periodeRincian($basisTabel, $tanggal);
        $kolom = $this->kolomRincian($periode, $mode);
        $tahun = (int) Carbon::parse($periode['current'])->year;
        $bulan = (int) Carbon::parse($periode['current'])->month;

        $baris = collect($spesifikasi)->map(function (array $spec) use ($periode, $tahun, $bulan, $mode) {
            if (($spec['kind'] ?? 'item') === 'group') {
                return ['kind' => 'group', 'label' => $spec['label']];
            }

            $ratio = ($spec['mode'] ?? null) === 'ratio' || $mode === 'ratio';
            $current = $ratio ? $this->nilaiRasioSpec($spec, $periode['current']) : $this->nilaiSpec($spec, $periode['current']);
            $prevAug = $ratio ? $this->nilaiRasioSpec($spec, $periode['prev_aug']) : $this->nilaiSpec($spec, $periode['prev_aug']);
            $prevDec = $ratio ? $this->nilaiRasioSpec($spec, $periode['prev_dec']) : $this->nilaiSpec($spec, $periode['prev_dec']);
            $jul = $ratio ? $this->nilaiRasioSpec($spec, $periode['curr_jul']) : $this->nilaiSpec($spec, $periode['curr_jul']);
            $prevMonth = $ratio ? $this->nilaiRasioSpec($spec, $periode['prev_month']) : $this->nilaiSpec($spec, $periode['prev_month']);
            $sameDateLastYear = $ratio ? $this->nilaiRasioSpec($spec, $periode['same_date_last_year']) : $this->nilaiSpec($spec, $periode['same_date_last_year']);
            $rka = $ratio ? $this->targetRasioSpec($spec, $tahun, $bulan) : $this->targetSpec($spec, $tahun, $bulan);

            return [
                'kind' => 'item',
                'row_mode' => $ratio ? 'ratio' : $mode,
                'label' => $spec['label'],
                'prev_aug' => $prevAug,
                'prev_dec' => $prevDec,
                'curr_jul' => $jul,
                'current' => $current,
                'rka' => $rka,
                'penc' => ($current !== null && $rka !== null && (float) $rka !== 0.0) ? round($current / $rka * 100, 2) : null,
                'mtd' => ($current === null || $prevMonth === null) ? null : round($current - $prevMonth, 2),
                'ytd' => ($current === null || $prevDec === null) ? null : round($current - $prevDec, 2),
                'yoy' => ($current === null || $sameDateLastYear === null) ? null : round($current - $sameDateLastYear, 2),
                'dtd' => ($current === null || $jul === null) ? null : round($current - $jul, 2),
            ];
        })->all();

        return [
            'key' => $key,
            'judul' => $judul,
            'tanggal' => $periode['current'],
            'inverse' => $inverse,
            'mode' => $mode,
            'kolom' => $kolom,
            'baris' => $baris,
        ];
    }

    /** @return array<string,string> */
    private function periodeRincian(array $basisTabel, string $tanggal): array
    {
        $current = $this->tanggalTersedia($basisTabel, null, $tanggal) ?? Carbon::parse($tanggal)->toDateString();
        $cur = Carbon::parse($current);

        return [
            'current' => $current,
            'prev_aug' => $this->tanggalTersedia($basisTabel, null, $cur->copy()->subYear()->month(8)->endOfMonth()->toDateString()) ?? $cur->copy()->subYear()->month(8)->endOfMonth()->toDateString(),
            'prev_dec' => $this->tanggalTersedia($basisTabel, null, $cur->copy()->subYear()->endOfYear()->toDateString()) ?? $cur->copy()->subYear()->endOfYear()->toDateString(),
            'curr_jul' => $this->tanggalTersedia($basisTabel, null, $cur->copy()->month(7)->endOfMonth()->toDateString()) ?? $cur->copy()->month(7)->endOfMonth()->toDateString(),
            'prev_month' => $this->tanggalTersedia($basisTabel, null, $cur->copy()->subMonthNoOverflow()->endOfMonth()->toDateString()) ?? $cur->copy()->subMonthNoOverflow()->endOfMonth()->toDateString(),
            'same_date_last_year' => $this->tanggalTersedia($basisTabel, null, $cur->copy()->subYear()->toDateString()) ?? $cur->copy()->subYear()->toDateString(),
        ];
    }

    /** @return list<array<string,string>> */
    private function kolomRincian(array $periode, string $mode): array
    {
        $format = $mode === 'ratio' ? 'pct' : 'number';
        $deltaFormat = $mode === 'ratio' ? 'deltaPct' : 'delta';

        $kolom = [
            ['key' => 'prev_aug', 'label' => $this->labelTanggalKolom($periode['prev_aug']), 'group' => 'Actual 2025', 'format' => $format],
            ['key' => 'prev_dec', 'label' => $this->labelTanggalKolom($periode['prev_dec']), 'group' => 'Actual 2025', 'format' => $format],
            ['key' => 'curr_jul', 'label' => $this->labelTanggalKolom($periode['curr_jul']), 'group' => 'Actual 2026', 'format' => $format],
            ['key' => 'current', 'label' => $this->labelTanggalKolom($periode['current']), 'group' => 'Actual 2026', 'format' => $format],
            ['key' => 'rka', 'label' => 'RKA', 'group' => '', 'format' => $format],
            ['key' => 'penc', 'label' => 'Penc RKA', 'group' => '', 'format' => 'pct'],
        ];

        if ($mode === 'dtd' || $mode === 'ratio') {
            $kolom[] = ['key' => 'dtd', 'label' => 'Date to Date', 'group' => '', 'format' => $deltaFormat];
        }

        $kolom[] = ['key' => 'mtd', 'label' => 'MtD', 'group' => '', 'format' => $deltaFormat];
        $kolom[] = ['key' => 'ytd', 'label' => 'YtD', 'group' => '', 'format' => $deltaFormat];

        if ($mode === 'amount') {
            $kolom[] = ['key' => 'yoy', 'label' => 'YoY', 'group' => '', 'format' => 'delta'];
        }

        return $kolom;
    }

    private function labelTanggalKolom(?string $tanggal): string
    {
        if ($tanggal === null) {
            return '–';
        }

        return Carbon::parse($tanggal)->locale('id')->translatedFormat('d M y');
    }

    private function nilaiSpec(array $spec, ?string $tanggal): ?float
    {
        if ($tanggal === null) return null;
        return Satuan::toJuta($this->total($spec['tables'], null, $tanggal, $spec['filter'] ?? []));
    }

    private function targetSpec(array $spec, int $tahun, int $bulan): ?float
    {
        return Satuan::toJuta($this->targetBulan($spec['rka'], null, $tahun, $bulan, $spec['filter'] ?? []));
    }

    private function nilaiRasioSpec(array $spec, ?string $tanggal): ?float
    {
        if ($tanggal === null) return null;

        $num = $this->total($spec['numTables'], null, $tanggal, $spec['numFilter'] ?? []);
        $den = $this->total($spec['denTables'], null, $tanggal, $spec['denFilter'] ?? []);

        return (float) $den === 0.0 ? null : round($num / $den * 100, 2);
    }

    private function targetRasioSpec(array $spec, int $tahun, int $bulan): ?float
    {
        $num = $this->targetBulan($spec['numRka'], null, $tahun, $bulan, $spec['numFilter'] ?? []);
        $den = $this->targetBulan($spec['denRka'], null, $tahun, $bulan, $spec['denFilter'] ?? []);

        return (float) $den === 0.0 ? null : round($num / $den * 100, 2);
    }

    // ---------------------------------------------------------------------

    // Penyusun kartu overview (dipakai region & tiap area).
    // ---------------------------------------------------------------------

    /**
     * @return list<array<string, mixed>>
     */
    private function kartuUtama(?int $areaId, string $tanggal): array
    {
        $dpk = $this->kartuStok('dpk', 'Dana Pihak Ketiga', self::DPK, self::RKA_DPK, $areaId, $tanggal);
        $pinjaman = $this->kartuStok('pinjaman', 'Pinjaman', self::PINJAMAN, self::RKA_PINJAMAN, $areaId, $tanggal);
        $sml = $this->kartuStok(
            'sml',
            'SML',
            self::PINJAMAN,
            self::RKA_PINJAMAN,
            $areaId,
            $tanggal,
            ['kualitas' => [Pinjaman::KUALITAS_SML]],
        );
        $npl = $this->kartuStok(
            'npl',
            'NPL',
            self::PINJAMAN,
            self::RKA_PINJAMAN,
            $areaId,
            $tanggal,
            ['kualitas' => [Pinjaman::KUALITAS_NPL]],
        );
        $recovery = $this->kartuStok('recovery', 'Recovery EC', self::RECOVERY, self::RKA_RECOVERY, $areaId, $tanggal);

        $dpk['rincian'] = $this->rincianDpk($areaId, $tanggal);
        $pinjaman['rincian'] = $this->rincianPinjaman($areaId, $tanggal);
        $recovery['rincian'] = $this->rincianRecovery($areaId, $tanggal);

        $nilaiPinjaman = (float) ($pinjaman['nilai'] ?? 0);
        $sml['rasio'] = $nilaiPinjaman > 0 && $sml['nilai'] !== null
            ? round((float) $sml['nilai'] / $nilaiPinjaman * 100, 2)
            : null;
        $npl['rasio'] = $nilaiPinjaman > 0 && $npl['nilai'] !== null
            ? round((float) $npl['nilai'] / $nilaiPinjaman * 100, 2)
            : null;

        return [$dpk, $pinjaman, $sml, $npl, $recovery];
    }

    /**
     * Rincian produk DPK pada tanggal posisi aktif.
     *
     * @return list<array<string, mixed>>
     */
    private function rincianDpk(?int $areaId, string $tanggal): array
    {
        $posisiTgl = $this->tanggalTersedia(self::DPK, $areaId, $tanggal);

        if ($posisiTgl === null) {
            return [];
        }

        $posisi = Carbon::parse($posisiTgl);

        return collect(Simpanan::PRODUK)
            ->map(function (string $produk) use ($areaId, $posisiTgl, $posisi) {
                $nilai = $this->total(self::DPK, $areaId, $posisiTgl, ['produk' => [$produk]]);
                $target = $this->targetBulan(self::RKA_DPK, $areaId, $posisi->year, $posisi->month, ['produk' => [$produk]]);

                return $this->rincianItem($produk, $nilai, $target);
            })
            ->all();
    }

    /**
     * Rincian Pinjaman disederhanakan menjadi Micro, SME, dan Consumer.
     * SME mencakup Kecil, Menengah, serta Commercial.
     *
     * @return list<array<string, mixed>>
     */
    private function rincianPinjaman(?int $areaId, string $tanggal): array
    {
        $posisiTgl = $this->tanggalTersedia(self::PINJAMAN, $areaId, $tanggal);

        if ($posisiTgl === null) {
            return [];
        }

        $posisi = Carbon::parse($posisiTgl);
        $kelompok = [
            'Micro' => ['Mikro'],
            'SME' => ['Kecil', 'Menengah', 'Commercial', 'Komersial'],
            'Consumer' => ['Konsumer', 'Consumer'],
        ];

        return collect($kelompok)
            ->map(function (array $segmen, string $label) use ($areaId, $posisiTgl, $posisi) {
                $nilai = $this->total(self::PINJAMAN, $areaId, $posisiTgl, ['segmen' => $segmen]);
                $target = $this->targetBulan(self::RKA_PINJAMAN, $areaId, $posisi->year, $posisi->month, ['segmen' => $segmen]);

                return $this->rincianItem($label, $nilai, $target);
            })
            ->values()
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function rincianRecovery(?int $areaId, string $tanggal): array
    {
        $posisiTgl = $this->tanggalTersedia(self::RECOVERY, $areaId, $tanggal);

        if ($posisiTgl === null) {
            return [];
        }

        $posisi = Carbon::parse($posisiTgl);
        $kelompok = [
            'Micro' => array_values(array_unique([Recovery::SEGMEN_MICRO, ...(Recovery::SEGMEN_RAW[Recovery::SEGMEN_MICRO] ?? [])])),
            'SME' => array_values(array_unique([Recovery::SEGMEN_SME, ...(Recovery::SEGMEN_RAW[Recovery::SEGMEN_SME] ?? [])])),
            'Consumer' => array_values(array_unique([Recovery::SEGMEN_CONSUMER, ...(Recovery::SEGMEN_RAW[Recovery::SEGMEN_CONSUMER] ?? [])])),
        ];

        return collect($kelompok)
            ->map(function (array $segmen, string $label) use ($areaId, $posisiTgl, $posisi) {
                $nilai = $this->total(self::RECOVERY, $areaId, $posisiTgl, ['segmen' => $segmen]);
                $target = $this->targetBulan(self::RKA_RECOVERY, $areaId, $posisi->year, $posisi->month, ['segmen' => $segmen]);

                return $this->rincianItem($label, $nilai, $target);
            })
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    private function rincianItem(string $label, float $nilai, float $target): array
    {
        return [
            'label' => $label,
            'nilai' => Satuan::toJuta($nilai),
            'target' => Satuan::toJuta($target),
            'pencapaian' => $target > 0 ? round($nilai / $target * 100, 2) : null,
            'gap' => Satuan::toJuta($nilai - $target),
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
     * Tren harian multi-bulan untuk grafik halaman PRESENT.
     *
     * Format mengikuti dashboard domain: 6 seri tetap = Desember tahun
     * sebelumnya + 5 bulan berjalan sampai bulan posisi.
     *
     * @return array<string, mixed>
     */
    private function trendRegion(string $tanggal): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();

        return [
            'tahun' => (int) $posisi->year,
            'dpk' => [
                'seri' => $this->seriTrend(self::DPK, $tanggal),
                'seri_produk' => [
                    'tabungan' => $this->seriTrend(self::DPK, $tanggal, ['produk' => [Simpanan::PRODUK_TABUNGAN]]),
                    'giro' => $this->seriTrend(self::DPK, $tanggal, ['produk' => [Simpanan::PRODUK_GIRO]]),
                    'deposito' => $this->seriTrend(self::DPK, $tanggal, ['produk' => [Simpanan::PRODUK_DEPOSITO]]),
                ],
            ],
            'pinjaman' => ['seri' => $this->seriTrend(self::PINJAMAN, $tanggal)],
            'sml' => ['seri' => $this->seriTrend(self::PINJAMAN, $tanggal, ['kualitas' => [Pinjaman::KUALITAS_SML]])],
            'npl' => ['seri' => $this->seriTrend(self::PINJAMAN, $tanggal, ['kualitas' => [Pinjaman::KUALITAS_NPL]])],
            'recovery' => ['seri' => $this->seriTrend(self::RECOVERY, $tanggal)],
        ];
    }

    /**
     * Ambil tren harian dan kelompokkan menjadi seri per bulan.
     *
     * @param  list<array{0: string, 1: string}>  $tabel
     * @param  array<string, list<string>>  $filter
     * @return list<array<string, mixed>>
     */
    private function seriTrend(array $tabel, string $tanggal, array $filter = []): array
    {
        $posisi = Carbon::parse($tanggal)->startOfDay();
        $periode = collect(range(0, 4))
            ->map(fn (int $mundur) => $posisi->copy()->subMonthsNoOverflow($mundur)->format('Y-m'))
            ->push($posisi->copy()->subYear()->month(12)->format('Y-m'))
            ->unique()
            ->sort()
            ->values();

        $awal = Carbon::createFromFormat('Y-m-d', ((string) $periode->first()).'-01')->startOfDay();
        $perTanggal = [];

        foreach ($tabel as [$nama, $kolom]) {
            $rows = DB::table($nama)
                ->whereBetween('tanggal', [$awal->toDateString(), $posisi->toDateString()])
                ->when(isset($filter['produk']), fn ($q) => $q->whereIn('produk', $filter['produk']))
                ->when(isset($filter['kualitas']), fn ($q) => $q->whereIn('kualitas', $filter['kualitas']))
                ->when(isset($filter['segmen']), fn ($q) => $q->whereIn('segmen', $filter['segmen']))
                ->when(isset($filter['segmentasi']), fn ($q) => $q->whereIn('segmentasi', $filter['segmentasi']))
                ->groupBy('tanggal')
                ->orderBy('tanggal')
                ->selectRaw("tanggal, SUM({$kolom}) as total")
                ->pluck('total', 'tanggal');

            foreach ($rows as $tgl => $total) {
                $tanggalKey = Carbon::parse($tgl)->toDateString();
                $perTanggal[$tanggalKey] = ($perTanggal[$tanggalKey] ?? 0.0) + (float) $total;
            }
        }

        return $this->seriBulanan(
            collect($perTanggal)->filter(
                fn ($nilai, string $tgl) => $periode->contains(Carbon::parse($tgl)->format('Y-m')),
            ),
        );
    }

    /**
     * Ubah peta tanggal => total menjadi seri bulanan untuk line chart.
     *
     * @param  Collection<string, mixed>  $perTanggal
     * @return list<array<string, mixed>>
     */
    private function seriBulanan(Collection $perTanggal): array
    {
        return collect($perTanggal)
            ->mapWithKeys(fn ($total, $tgl) => [Carbon::parse($tgl)->toDateString() => $total])
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
                ->when(isset($filter['segmen']), fn ($q) => $q->whereIn('segmen', $filter['segmen']))
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
                ->when(isset($filter['segmen']), fn ($q) => $q->whereIn('segmen', $filter['segmen']))
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
                ->when(isset($filter['segmen']), fn ($q) => $q->whereIn('segmen', $filter['segmen']))
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
