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
 *     NetDG_NPL(N) = NPL(N) − NPL(N−1) + PH(N)
 *     NetDG_SML(N) = SML(N) − SML(N−1) + NetDG_NPL(N)   <-- yang ditampilkan
 *
 * Angka harapan di test ini DIHITUNG TANGAN dari rumus dua langkah di atas,
 * lalu dicek silang terhadap bentuk teleskopiknya Δ(SML+NPL) + PH. Keduanya
 * harus setuju; kalau tidak, salah satu sisi yang keliru.
 *
 * CATATAN: perbandingan terhadap berkas perhitungan bisnis ("DG 26.xlsx") BELUM
 * dilakukan — berkasnya belum tersedia. Test ini membuktikan implementasi setia
 * pada rumus yang dispesifikasikan, bukan bahwa rumusnya sama dengan Excel bisnis.
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
    private function deretTotal(int $tahun = 2026): array
    {
        return $this->service->deret(PhNetDgService::MODE_NETDG, $tahun, null, null, null)['total'];
    }

    public function test_net_dg_bulanan_sesuai_hitungan_tangan(): void
    {
        $this->skenarioAcuan();

        $bulanan = $this->deretTotal()['bulanan'];

        // Januari: NetDG_NPL = 60 − 50 + 10 = 20 ; NetDG_SML = 110 − 100 + 20 = 30
        $this->assertSame(30.0, $bulanan[0]);

        // Februari: NetDG_NPL = 70 − 60 + 5 = 15 ; NetDG_SML = 105 − 110 + 15 = 10
        $this->assertSame(10.0, $bulanan[1]);

        // Maret: NetDG_NPL = 65 − 70 + 8 = 3 ; NetDG_SML = 120 − 105 + 3 = 18
        $this->assertSame(18.0, $bulanan[2]);
    }

    public function test_hasilnya_sama_dengan_bentuk_teleskopik(): void
    {
        $this->skenarioAcuan();

        $bulanan = $this->deretTotal()['bulanan'];

        // Δ(SML+NPL) + PH — dihitung terpisah dari rumus dua langkah.
        $smlNpl = [150.0, 170.0, 175.0, 185.0];   // Des, Jan, Feb, Mar
        $ph = [10.0, 5.0, 8.0];                   // Jan, Feb, Mar

        foreach ($ph as $i => $phBulan) {
            $teleskopik = ($smlNpl[$i + 1] - $smlNpl[$i]) + $phBulan;

            $this->assertSame($teleskopik, $bulanan[$i], 'Bulan ke-'.($i + 1));
        }
    }

    public function test_akumulasi_ytd_runtuh_jadi_selisih_posisi_plus_akum_ph(): void
    {
        $this->skenarioAcuan();

        $akumulasi = $this->deretTotal()['akumulasi'];

        $this->assertSame(30.0, $akumulasi[0]);
        $this->assertSame(40.0, $akumulasi[1]);
        $this->assertSame(58.0, $akumulasi[2]);

        // Identitas: Akum(M) = (SML+NPL)(M) − (SML+NPL)(Des lalu) + Σ PH
        $this->assertSame(185.0 - 150.0 + (10.0 + 5.0 + 8.0), $akumulasi[2]);
    }

    public function test_tanpa_posisi_desember_tahun_lalu_januari_null_bukan_nol(): void
    {
        // Sengaja TIDAK memasukkan posisi 31 Des 2025.
        $this->posisi('2026-01-31', 110, 60);
        $this->posisi('2026-02-28', 105, 70);
        $this->ph('2026-01-31', 10);
        $this->ph('2026-02-28', 5);

        $deret = $this->deretTotal();

        // Januari tidak terdefinisi tanpa posisi pembanding.
        $this->assertNull($deret['bulanan'][0]);
        // Februari tetap bisa dihitung (Jan & Feb dua-duanya ada).
        $this->assertSame(10.0, $deret['bulanan'][1]);

        // Akumulasi ikut null — akumulasi yang melompati bulan kosong menyesatkan.
        $this->assertNull($deret['akumulasi'][0]);
        $this->assertNull($deret['akumulasi'][1]);
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

        // NetDG = Δ(SML+NPL) + 0 = (170 − 150) = 20
        $this->assertSame(20.0, $this->deretTotal()['bulanan'][0]);
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

        // SME: Δ(SML+NPL) = (110+60+25+15) − (100+50+20+10) = 210 − 180 = 30 ; +PH 10 = 40
        $this->assertSame(40.0, $deret['sme']['bulanan'][0]);
        // Segmen lain tidak ikut terisi.
        $this->assertSame(0.0, $deret['micro']['bulanan'][0]);
        $this->assertSame(40.0, $deret['total']['bulanan'][0]);
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
            'kualitas' => Pinjaman::KUALITAS_NPL, 'tanggal' => '2025-12-31', 'baki_debet' => 40 * self::JT,
        ]);
        Pinjaman::factory()->create([
            'cabang_id' => 855, 'uker_id' => 855, 'segmen' => 'Menengah', 'segmentasi' => 'Ritel',
            'kualitas' => Pinjaman::KUALITAS_NPL, 'tanggal' => '2026-01-31', 'baki_debet' => 55 * self::JT,
        ]);

        // Δ tambahan = 55 − 40 = 15
        $this->assertSame($sebelum + 15.0, $this->deretTotal()['bulanan'][0]);
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

        $this->assertSame(18.0, $total['nilai']);          // Maret
        $this->assertSame(58.0, $total['akumulasi']);      // akum s/d Maret
        $this->assertSame(8.0, $total['delta']['mom']['nilai']);  // 18 − 10 (Feb)
    }
}
