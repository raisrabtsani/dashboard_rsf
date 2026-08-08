<?php

namespace Tests\Feature;

use App\Models\Ph;
use App\Models\Pinjaman;
use App\Services\PhNetDgService;
use App\Support\Segmen;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Mengunci rumus Net DG angka per angka.
 *
 *     NetDG_SML(N) = Posisi SML akhir bulan N + PH(N)
 *     NetDG_NPL(N) = Posisi NPL akhir bulan N + PH(N)
 *
 * Filter SML/NPL bersifat eksklusif. SML tidak mencampurkan NPL dan NPL tidak
 * mencampurkan SML. Pilihan default pada service adalah SML.
 */
class NetDgFormulaTest extends TestCase
{
    use RefreshDatabase;

    private const CABANG = 159;

    private const UKER = 5438;

    private const JT = 1_000_000;

    private PhNetDgService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
        $this->service = app(PhNetDgService::class);
    }

    private function posisi(string $tanggal, float $sml, float $npl, string $segmen = 'Mikro'): void
    {
        foreach ([Pinjaman::KUALITAS_SML => $sml, Pinjaman::KUALITAS_NPL => $npl] as $kualitas => $nilai) {
            Pinjaman::factory()->create([
                'cabang_id' => self::CABANG,
                'uker_id' => self::UKER,
                'segmen' => $segmen,
                'segmentasi' => 'Ritel',
                'kualitas' => $kualitas,
                'tanggal' => $tanggal,
                'baki_debet' => $nilai * self::JT,
            ]);
        }
    }

    private function ph(string $periode, float $nilai, string $segmen = Segmen::MICRO): void
    {
        Ph::factory()->create([
            'cabang_id' => self::CABANG,
            'uker_id' => self::UKER,
            'segmen' => $segmen,
            'periode' => $periode,
            'saldo' => $nilai * self::JT,
        ]);
    }

    /**
     * Skenario acuan (satuan juta):
     *
     *   bulan      SML    NPL   SML+NPL   PH
     *   Des 2025   100     50      150     —
     *   Jan 2026   110     60      170     10
     *   Feb 2026   105     70      175      5
     *   Mar 2026   120     65      185      8
     */
    private function skenarioAcuan(): void
    {
        $this->posisi('2025-12-31', 100, 50);
        $this->posisi('2026-01-31', 110, 60);
        $this->posisi('2026-02-28', 105, 70);
        $this->posisi('2026-03-31', 120, 65);

        $this->ph('2026-01-31', 10);
        $this->ph('2026-02-28', 5);
        $this->ph('2026-03-31', 8);
    }

    /**
     * @return array{bulanan: list<float|null>, akumulasi: list<float|null>}
     */
    private function deretTotal(int $tahun = 2026, string $kualitas = PhNetDgService::NETDG_SML): array
    {
        return $this->service->deret(PhNetDgService::MODE_NETDG, $tahun, null, null, null, $kualitas)['total'];
    }

    public function test_net_dg_sml_memakai_posisi_sml_akhir_bulan_ditambah_ph(): void
    {
        $this->skenarioAcuan();

        $bulanan = $this->deretTotal()['bulanan'];

        // Januari: posisi SML 110 + PH 10 = 120.
        $this->assertSame(120.0, $bulanan[0]);
        // Februari: posisi SML 105 + PH 5 = 110.
        $this->assertSame(110.0, $bulanan[1]);
        // Maret: posisi SML 120 + PH 8 = 128.
        $this->assertSame(128.0, $bulanan[2]);
    }

    public function test_filter_npl_memakai_posisi_npl_akhir_bulan_ditambah_ph(): void
    {
        $this->skenarioAcuan();

        $bulanan = $this->deretTotal(2026, PhNetDgService::NETDG_NPL)['bulanan'];

        // Jan: posisi NPL 60 + PH 10 = 70.
        $this->assertSame(70.0, $bulanan[0]);
        // Feb: posisi NPL 70 + PH 5 = 75.
        $this->assertSame(75.0, $bulanan[1]);
        // Mar: posisi NPL 65 + PH 8 = 73.
        $this->assertSame(73.0, $bulanan[2]);
    }

    public function test_filter_sml_dan_npl_benar_benar_terpisah(): void
    {
        $this->skenarioAcuan();

        $sml = $this->deretTotal(2026, PhNetDgService::NETDG_SML)['bulanan'];
        $npl = $this->deretTotal(2026, PhNetDgService::NETDG_NPL)['bulanan'];

        $this->assertSame(120.0, $sml[0]);
        $this->assertSame(70.0, $npl[0]);
        $this->assertNotSame($sml[0], $npl[0]);
    }

    public function test_net_dg_adalah_posisi_dan_tidak_diakumulasi_antarbulan(): void
    {
        $this->skenarioAcuan();

        $posisi = $this->deretTotal()['akumulasi'];

        // Key `akumulasi` dipertahankan untuk kompatibilitas chart, tetapi pada
        // NET DG isinya adalah posisi akhir bulan, bukan jumlah Jan..bulan ini.
        $this->assertSame(120.0, $posisi[0]);
        $this->assertSame(110.0, $posisi[1]);
        $this->assertSame(128.0, $posisi[2]);
    }

    public function test_januari_tidak_membutuhkan_posisi_desember_tahun_lalu(): void
    {
        // Sengaja TIDAK memasukkan posisi 31 Des 2025.
        $this->posisi('2026-01-31', 110, 60);
        $this->posisi('2026-02-28', 105, 70);
        $this->ph('2026-01-31', 10);
        $this->ph('2026-02-28', 5);

        $deret = $this->deretTotal();

        $this->assertSame(120.0, $deret['bulanan'][0]);
        $this->assertSame(110.0, $deret['bulanan'][1]);
        $this->assertSame(110.0, $deret['akumulasi'][1]);
    }

    public function test_bulan_tanpa_posisi_bernilai_null_bukan_nol(): void
    {
        $this->skenarioAcuan();

        $bulanan = $this->deretTotal()['bulanan'];

        // April..Desember tidak punya posisi sama sekali.
        for ($i = 3; $i < 12; $i++) {
            $this->assertNull($bulanan[$i], 'Bulan ke-'.($i + 1).' seharusnya null');
        }
    }

    public function test_bulan_tanpa_ph_tetap_dihitung_dengan_ph_nol(): void
    {
        $this->posisi('2025-12-31', 100, 50);
        $this->posisi('2026-01-31', 110, 60);
        // Tidak ada baris PH Januari.

        // NetDG SML = posisi SML 110 + PH 0.
        $this->assertSame(110.0, $this->deretTotal()['bulanan'][0]);
    }

    public function test_segmen_pinjaman_dilipat_ke_taksonomi_kanonik(): void
    {
        // Kecil & Menengah dua-duanya masuk SME.
        $this->posisi('2025-12-31', 100, 50, 'Kecil');
        $this->posisi('2025-12-31', 20, 10, 'Menengah');
        $this->posisi('2026-01-31', 110, 60, 'Kecil');
        $this->posisi('2026-01-31', 25, 15, 'Menengah');
        $this->ph('2026-01-31', 10, Segmen::SME);

        $deret = $this->service->deret(PhNetDgService::MODE_NETDG, 2026, null, null, null);

        // SME SML: posisi (110 + 25) + PH 10 = 145.
        $this->assertSame(145.0, $deret['sme']['bulanan'][0]);
        // Segmen lain tidak ikut terisi.
        $this->assertSame(0.0, $deret['micro']['bulanan'][0]);
        $this->assertSame(145.0, $deret['total']['bulanan'][0]);
    }

    public function test_ph_adalah_flow_bulanan_bukan_posisi(): void
    {
        $this->ph('2026-01-31', 10);
        $this->ph('2026-02-28', 5);

        $deret = $this->service->deret(PhNetDgService::MODE_PH, 2026, null, null, null)['total'];

        // Nilai bulanan apa adanya, akumulasi menjumlah.
        $this->assertSame(10.0, $deret['bulanan'][0]);
        $this->assertSame(5.0, $deret['bulanan'][1]);
        $this->assertSame(15.0, $deret['akumulasi'][1]);

        // Bulan tanpa baris PH = null, BUKAN 0.
        $this->assertNull($deret['bulanan'][2]);
    }

    public function test_rollup_855_ikut_dihitung_di_domain_ini(): void
    {
        $this->skenarioAcuan();

        $sebelum = $this->deretTotal()['bulanan'][0];

        // Segmen Menengah dikelola level Region (cabang 855) — HARUS ikut.
        Pinjaman::factory()->create([
            'cabang_id' => 855, 'uker_id' => 855, 'segmen' => 'Menengah', 'segmentasi' => 'Ritel',
            'kualitas' => Pinjaman::KUALITAS_SML, 'tanggal' => '2026-01-31', 'baki_debet' => 55 * self::JT,
        ]);

        // Tambahan posisi SML = 55.
        $this->assertSame($sebelum + 55.0, $this->deretTotal()['bulanan'][0]);
    }


    public function test_snapshot_sml_dan_npl_menghasilkan_total_yang_berbeda(): void
    {
        $this->skenarioAcuan();

        $sml = $this->service->snapshot(
            PhNetDgService::MODE_NETDG,
            '2026-03-31',
            null,
            null,
            null,
            PhNetDgService::NETDG_SML,
        );
        $npl = $this->service->snapshot(
            PhNetDgService::MODE_NETDG,
            '2026-03-31',
            null,
            null,
            null,
            PhNetDgService::NETDG_NPL,
        );

        $totalSml = collect($sml['kartu'])->firstWhere('key', 'total');
        $totalNpl = collect($npl['kartu'])->firstWhere('key', 'total');

        $this->assertSame('sml', $sml['netdg_kualitas']);
        $this->assertSame('npl', $npl['netdg_kualitas']);
        $this->assertSame(128.0, $totalSml['akumulasi']);
        $this->assertSame(73.0, $totalNpl['akumulasi']);
        $this->assertNotSame($totalSml['akumulasi'], $totalNpl['akumulasi']);
    }

    public function test_snapshot_tidak_memuat_rka_sama_sekali(): void
    {
        $this->skenarioAcuan();

        $snapshot = $this->service->snapshot(
            PhNetDgService::MODE_NETDG,
            '2026-03-31',
            null,
            null,
            null,
        );

        $this->assertFalse($snapshot['punya_rka']);

        foreach ($snapshot['kartu'] as $kartu) {
            $this->assertArrayNotHasKey('target', $kartu);
            $this->assertArrayNotHasKey('pencapaian', $kartu);
            $this->assertArrayNotHasKey('gap', $kartu);
        }
    }

    public function test_snapshot_memakai_bulan_posisi(): void
    {
        $this->skenarioAcuan();

        $snapshot = $this->service->snapshot(
            PhNetDgService::MODE_NETDG,
            '2026-03-31',
            null,
            null,
            null,
        );

        $total = collect($snapshot['kartu'])->firstWhere('key', 'total');

        $this->assertSame(128.0, $total['nilai']);          // Maret: SML 120 + PH 8
        $this->assertSame(128.0, $total['akumulasi']);      // posisi Maret, bukan 120+110+128
        $this->assertSame(18.0, $total['delta']['mom']['nilai']); // 128 − 110 (Feb)
    }
}
