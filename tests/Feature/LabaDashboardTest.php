<?php

namespace Tests\Feature;

use App\Models\Laba;
use App\Models\Region;
use App\Models\RkaLaba;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization test domain Laba — domain BULANAN, nilai kumulatif YTD.
 *
 * Dimensi segmen = UKO (jenis kantor): Branch Office / Sub-Branch Office /
 * Micro / Region Office. Fokus: MTD = laba(N) - laba(N-1); Januari = nilai itu
 * sendiri; bulan N-1 tanpa data => MTD null (bukan 0); Region Office IKUT di
 * kartu Total.
 */
class LabaDashboardTest extends TestCase
{
    use RefreshDatabase;

    // Master Jakarta 2. Cabang 12 = KC Bogor.
    private const CABANG_A = 12;

    // uker 804 = UNIT PURBASARI BOGOR (dilaporkan uko "Micro").
    private const UKER_A = 804;

    // uker 595 = KCP IPB (dilaporkan uko "Sub-Branch Office").
    private const UKER_A_SBO = 595;

    // Cabang 319 = KC Cikarang; uker 842 = UNIT SETU CIKARANG.
    private const CABANG_B = 319;

    private const UKER_B = 842;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
        $this->buatData();
    }

    private function laba(int $cabang, int $uker, string $uko, int $tahun, int $bulan, float $laba): void
    {
        Laba::factory()->create([
            'cabang_id' => $cabang,
            'uker_id' => $uker,
            'segmen' => $uko,
            'tahun' => $tahun,
            'bulan' => $bulan,
            'laba' => $laba,
        ]);
    }

    /**
     * Cabang A, 2026 (nilai KUMULATIF YTD):
     *  - Micro (uker 804): Jan 100 jt, Feb 250 jt  -> MTD Feb = 150.
     *  - Sub-Branch Office (uker 595): hanya Feb 80 jt (Jan tak ada) -> MTD Feb = null.
     * YoY: Micro Feb 2025 = 200 jt. RKA Micro Feb 2026 = 500 jt.
     * Cabang B: Micro Feb 2026 = 900 jt (untuk scoping).
     */
    private function buatData(): void
    {
        $this->laba(self::CABANG_A, self::UKER_A, 'Micro', 2026, 1, 100 * 1_000_000);
        $this->laba(self::CABANG_A, self::UKER_A, 'Micro', 2026, 2, 250 * 1_000_000);
        $this->laba(self::CABANG_A, self::UKER_A_SBO, 'Sub-Branch Office', 2026, 2, 80 * 1_000_000);

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
        // Sub-Branch Office hanya ada di Feb (Jan tak ada) -> MTD null, bukan 0.
        $sbo = $this->kartu(
            $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A, 'tahun' => 2026, 'bulan' => 2]),
            'Sub-Branch Office',
        );

        $this->assertSame(80.0, $sbo['nilai']);
        $this->assertNull($sbo['delta']['mtd']['nilai']);
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

        // Micro 250 + Sub-Branch Office 80 = 330.
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
        $this->assertSame(330.0, $this->angka($titik[2]['nilai']));   // Feb kumulatif (Micro250+SBO80)
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
        $this->assertSame(330.0, $this->angka($a['nilai']));         // Micro250 + SBO80

        $uker = $this->actingAs($this->admin())
            ->getJson('/api/laba/branch-pencapaian?'.http_build_query([
                'tahun' => 2026, 'bulan' => 2, 'cabang_id' => self::CABANG_A,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame('uker', $uker['grouping']);
        // Dua uker di cabang A, terurut nilai desc: 804 (250) lalu 595 (80).
        $this->assertSame([self::UKER_A, self::UKER_A_SBO], collect($uker['baris'])->pluck('id')->all());
    }

    public function test_user_bo_terkunci_cabang_sendiri(): void
    {
        $bo = User::factory()->bo(self::CABANG_A)->create();

        // Admin tanpa filter Feb 2026: A(330) + B(900) = 1230.
        $this->assertSame(
            1230.0,
            $this->kartu($this->snapshot($this->admin(), ['tahun' => 2026, 'bulan' => 2]), 'total')['nilai'],
        );
        // BO cabang A tetap 330 walau minta cabang B (scope menimpa filter).
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

    public function test_region_office_ikut_di_total_dan_jadi_segmen_sendiri(): void
    {
        // Region Office (rollup) dilaporkan sebagai uko "Region Office".
        $region = Region::OFFICE_ID;
        $this->laba($region, $region, 'Region Office', 2026, 2, 500 * 1_000_000);

        $snapshot = $this->snapshot($this->admin(), ['tahun' => 2026, 'bulan' => 2]);

        // Total kini termasuk Region: A(330) + B(900) + Region(500) = 1730.
        $this->assertSame(1730.0, $this->kartu($snapshot, 'total')['nilai']);

        // Muncul kartu segmen "Region Office" sendiri.
        $this->assertSame(500.0, $this->kartu($snapshot, 'Region Office')['nilai']);
    }

    public function test_region_office_dikecualikan_dari_tabel_kinerja_cabang(): void
    {
        // Walau ikut di Total, rollup Region tidak masuk peringkat antar-cabang.
        $region = Region::OFFICE_ID;
        $this->laba($region, $region, 'Region Office', 2026, 2, 500 * 1_000_000);

        $cabang = $this->actingAs($this->admin())
            ->getJson('/api/laba/branch-pencapaian?'.http_build_query(['tahun' => 2026, 'bulan' => 2]))
            ->assertOk()
            ->json();

        $this->assertNotContains($region, collect($cabang['baris'])->pluck('id')->all());
    }

    public function test_semua_endpoint_laba_menolak_tamu(): void
    {
        foreach (['snapshot', 'chart', 'chart-mtd', 'branch-pencapaian', 'filter-options'] as $endpoint) {
            $this->getJson("/api/laba/{$endpoint}")->assertUnauthorized();
        }
    }
}
