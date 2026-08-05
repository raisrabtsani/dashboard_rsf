<?php

namespace Tests\Feature;

use App\Models\Laba;
use App\Models\RkaLaba;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization test domain Laba — domain BULANAN, nilai kumulatif YTD.
 *
 * Fokus utama: MTD = laba(N) - laba(N-1); Januari = nilai itu sendiri; bulan
 * N-1 tanpa data => MTD null (bukan 0).
 */
class LabaDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const CABANG_A = 159;

    private const UKER_A = 5438;

    private const CABANG_B = 621;

    private const UKER_B = 5516;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
        $this->buatData();
    }

    private function laba(int $cabang, int $uker, string $segmen, int $tahun, int $bulan, float $laba): void
    {
        Laba::factory()->create([
            'cabang_id' => $cabang,
            'uker_id' => $uker,
            'segmen' => $segmen,
            'tahun' => $tahun,
            'bulan' => $bulan,
            'laba' => $laba,
        ]);
    }

    /**
     * Cabang A, 2026 (nilai KUMULATIF YTD):
     *  - Micro: Jan 100 jt, Feb 250 jt  -> MTD Feb = 150.
     *  - SME:   hanya Feb 80 jt (Jan tak ada) -> MTD Feb = null.
     * YoY: Micro Feb 2025 = 200 jt. RKA Micro Feb 2026 = 500 jt.
     * Cabang B: Micro Feb 2026 = 900 jt (untuk scoping).
     */
    private function buatData(): void
    {
        $this->laba(self::CABANG_A, self::UKER_A, 'Micro', 2026, 1, 100 * 1_000_000);
        $this->laba(self::CABANG_A, self::UKER_A, 'Micro', 2026, 2, 250 * 1_000_000);
        $this->laba(self::CABANG_A, self::UKER_A, 'SME', 2026, 2, 80 * 1_000_000);

        $this->laba(self::CABANG_A, self::UKER_A, 'Micro', 2025, 2, 200 * 1_000_000);

        $this->laba(self::CABANG_B, self::UKER_B, 'Micro', 2026, 2, 900 * 1_000_000);

        RkaLaba::factory()->create([
            'cabang_id' => self::CABANG_A,
            'uker_id' => self::UKER_A,
            'segmen' => 'Micro',
            'tahun' => 2026,
            'bulan' => 2,
            'target' => 500 * 1_000_000,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    private function snapshot(User $user, array $filter): array
    {
        return $this->actingAs($user)
            ->getJson('/api/laba/snapshot?'.http_build_query($filter))
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function kartu(array $snapshot, string $key): array
    {
        $kartu = collect($snapshot['kartu'])->firstWhere('key', $key);

        foreach (['nilai', 'target', 'pencapaian', 'gap'] as $field) {
            $kartu[$field] = $this->angka($kartu[$field]);
        }

        foreach ($kartu['delta'] as $jenis => $delta) {
            $kartu['delta'][$jenis] = [
                'nilai' => $this->angka($delta['nilai']),
                'persen' => $this->angka($delta['persen']),
            ];
        }

        return $kartu;
    }

    private function angka(mixed $nilai): ?float
    {
        return $nilai === null ? null : (float) $nilai;
    }

    public function test_halaman_bisa_dibuka(): void
    {
        $this->actingAs($this->admin())->get('/dashboard/laba')->assertOk();
    }

    // --- Inti: MTD dari selisih kumulatif --------------------------------

    public function test_mtd_bulan_ini_adalah_selisih_kumulatif(): void
    {
        // Jan 100, Feb 250 -> MTD Feb = 150.
        $micro = $this->kartu(
            $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A, 'tahun' => 2026, 'bulan' => 2]),
            'Micro',
        );

        $this->assertSame(250.0, $micro['nilai']);           // nilai = kumulatif YTD
        $this->assertSame(150.0, $micro['delta']['mtd']['nilai']);
    }

    public function test_mtd_null_saat_bulan_sebelumnya_tidak_ada_data(): void
    {
        // SME hanya ada di Feb (Jan tak ada) -> MTD tak diketahui = null, bukan 0.
        $sme = $this->kartu(
            $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A, 'tahun' => 2026, 'bulan' => 2]),
            'SME',
        );

        $this->assertSame(80.0, $sme['nilai']);
        $this->assertNull($sme['delta']['mtd']['nilai']);
    }

    public function test_januari_mtd_sama_dengan_nilai_kumulatif(): void
    {
        // Januari tidak punya bulan sebelumnya: MTD = nilai Januari itu sendiri.
        $micro = $this->kartu(
            $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A, 'tahun' => 2026, 'bulan' => 1]),
            'Micro',
        );

        $this->assertSame(100.0, $micro['nilai']);
        $this->assertSame(100.0, $micro['delta']['mtd']['nilai']);
    }

    public function test_ytd_adalah_nilai_kartu_itu_sendiri(): void
    {
        $micro = $this->kartu(
            $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A, 'tahun' => 2026, 'bulan' => 2]),
            'Micro',
        );

        $this->assertSame(250.0, $micro['delta']['ytd']['nilai']);
    }

    public function test_yoy_bulan_sama_tahun_lalu(): void
    {
        // Feb 2026 (250) vs Feb 2025 (200) = 50.
        $micro = $this->kartu(
            $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A, 'tahun' => 2026, 'bulan' => 2]),
            'Micro',
        );

        $this->assertSame(50.0, $micro['delta']['yoy']['nilai']);
    }

    public function test_tidak_ada_kolom_d1(): void
    {
        $snapshot = $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A, 'tahun' => 2026, 'bulan' => 2]);

        $keys = collect($snapshot['label_delta'])->pluck('key')->all();
        $this->assertSame(['mtd', 'ytd', 'yoy'], $keys);
        $this->assertNotContains('dtd', $keys);
    }

    public function test_pencapaian_dan_gap_terhadap_rka_kumulatif(): void
    {
        $micro = $this->kartu(
            $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A, 'tahun' => 2026, 'bulan' => 2]),
            'Micro',
        );

        $this->assertSame(500.0, $micro['target']);
        $this->assertSame(50.0, $micro['pencapaian']);   // 250 / 500
        $this->assertSame(-250.0, $micro['gap']);
    }

    public function test_total_menjumlahkan_semua_segmen(): void
    {
        $total = $this->kartu(
            $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A, 'tahun' => 2026, 'bulan' => 2]),
            'total',
        );

        // Micro 250 + SME 80 = 330.
        $this->assertSame(330.0, $total['nilai']);
    }

    // --- Chart -----------------------------------------------------------

    public function test_chart_menggambar_kumulatif_ytd(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/laba/chart?'.http_build_query(['tahun' => 2026, 'cabang_id' => self::CABANG_A]))
            ->assertOk()
            ->json();

        $titik = collect($data['titik'])->keyBy('bulan');
        $this->assertSame(100.0, $this->angka($titik[1]['nilai']));   // Jan kumulatif
        $this->assertSame(330.0, $this->angka($titik[2]['nilai']));   // Feb kumulatif (Micro250+SME80)
    }

    public function test_chart_mtd_adalah_selisih_antar_bulan(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/laba/chart-mtd?'.http_build_query(['tahun' => 2026, 'cabang_id' => self::CABANG_A]))
            ->assertOk()
            ->json();

        $titik = collect($data['titik'])->keyBy('bulan');
        $this->assertSame(100.0, $this->angka($titik[1]['nilai']));   // Jan = kumulatif Jan
        $this->assertSame(230.0, $this->angka($titik[2]['nilai']));   // Feb = 330 - 100
    }

    public function test_chart_mtd_bulan_kosong_bernilai_null(): void
    {
        // Tahun terpisah: Jan 50, Mar 200 (Feb bolong).
        $this->laba(self::CABANG_A, self::UKER_A, 'Micro', 2027, 1, 50 * 1_000_000);
        $this->laba(self::CABANG_A, self::UKER_A, 'Micro', 2027, 3, 200 * 1_000_000);

        $data = $this->actingAs($this->admin())
            ->getJson('/api/laba/chart-mtd?'.http_build_query(['tahun' => 2027, 'cabang_id' => self::CABANG_A]))
            ->assertOk()
            ->json();

        $titik = collect($data['titik'])->keyBy('bulan');
        $this->assertSame(50.0, $this->angka($titik[1]['nilai']));    // Jan
        $this->assertNull($titik[2]['nilai']);                        // Feb tak ada data
        $this->assertNull($titik[3]['nilai']);                        // Mar: Feb bolong -> null
    }

    // --- Tabel & scoping -------------------------------------------------

    public function test_branch_pencapaian_per_cabang_lalu_drilldown_uker(): void
    {
        $cabang = $this->actingAs($this->admin())
            ->getJson('/api/laba/branch-pencapaian?'.http_build_query(['tahun' => 2026, 'bulan' => 2]))
            ->assertOk()
            ->json();

        $this->assertSame('cabang', $cabang['grouping']);
        $a = collect($cabang['baris'])->firstWhere('id', self::CABANG_A);
        $this->assertSame(330.0, $this->angka($a['nilai']));         // Micro250 + SME80

        $uker = $this->actingAs($this->admin())
            ->getJson('/api/laba/branch-pencapaian?'.http_build_query([
                'tahun' => 2026, 'bulan' => 2, 'cabang_id' => self::CABANG_A,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame('uker', $uker['grouping']);
        $this->assertSame([self::UKER_A], collect($uker['baris'])->pluck('id')->all());
    }

    public function test_user_bo_tidak_bisa_melihat_total_region(): void
    {
        $bo = User::factory()->bo(self::CABANG_A)->create();

        // Admin tanpa filter Feb 2026: A(330) + B(900) = 1230. BO A tetap 330.
        $this->assertSame(
            1230.0,
            $this->kartu($this->snapshot($this->admin(), ['tahun' => 2026, 'bulan' => 2]), 'total')['nilai'],
        );
        $this->assertSame(
            330.0,
            $this->kartu($this->snapshot($bo, ['tahun' => 2026, 'bulan' => 2, 'cabang_id' => self::CABANG_B]), 'total')['nilai'],
        );
    }

    public function test_endpoint_ranking_kosong_untuk_user_level_uker(): void
    {
        $user = User::factory()->uker(self::CABANG_A, self::UKER_A)->create();

        $this->actingAs($user)
            ->getJson('/api/laba/branch-pencapaian?'.http_build_query(['tahun' => 2026, 'bulan' => 2]))
            ->assertOk()
            ->assertJson(['baris' => []]);
    }

    public function test_rollup_855_tidak_ikut_terhitung(): void
    {
        $this->laba(855, 855, 'Micro', 2026, 2, 9_000 * 1_000_000);

        $this->assertSame(
            1230.0,
            $this->kartu($this->snapshot($this->admin(), ['tahun' => 2026, 'bulan' => 2]), 'total')['nilai'],
        );
    }

    public function test_semua_endpoint_laba_menolak_tamu(): void
    {
        foreach (['snapshot', 'chart', 'chart-mtd', 'branch-pencapaian', 'filter-options'] as $endpoint) {
            $this->getJson("/api/laba/{$endpoint}")->assertUnauthorized();
        }
    }
}
