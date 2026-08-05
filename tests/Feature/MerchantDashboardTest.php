<?php

namespace Tests\Feature;

use App\Models\Edc;
use App\Models\RkaEdc;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization test domain Merchant (memakai sub-domain EDC).
 *
 * INTI: dua semantik nilai —
 *   STOK (TID)          : nilai = SUM(actual) pada tanggal itu.
 *   FLOW (Sales Volume) : nilai = SUM(actual) 1 Jan s/d tanggal (akumulasi YTD).
 */
class MerchantDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const CABANG_A = 159;

    private const UKER_A = 5438;

    private const CABANG_B = 621;

    private const UKER_B = 5516;

    private const POSISI = '2026-03-10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
        $this->buatData();
    }

    private function edc(int $cabang, int $uker, string $kpi, string $tanggal, float $actual): void
    {
        Edc::factory()->create([
            'cabang_id' => $cabang,
            'uker_id' => $uker,
            'kpi' => $kpi,
            'tanggal' => $tanggal,
            'actual' => $actual,
        ]);
    }

    /**
     * Cabang A / Uker A, tahun 2026:
     *  - TID (stok): 5 Jan = 40, 10 Mar = 100. Stok di 10 Mar = 100 (BUKAN 140).
     *  - Sales Volume (flow, rupiah): 5 Jan = 100 jt, 10 Mar = 80 jt.
     *    Akumulasi YTD di 10 Mar = 180 jt.
     *  - Jumlah Transaksi (flow, tanpa target): 10 Mar = 25.
     *  - EDC SV Rp.0 (stok, inverse, tanpa target): 10 Mar = 5.
     * RKA Maret 2026: TID = 125, Sales Volume = 200 jt.
     * Cabang B: TID 10 Mar = 900 (untuk scoping).
     */
    private function buatData(): void
    {
        $this->edc(self::CABANG_A, self::UKER_A, 'TID', '2026-01-05', 40);
        $this->edc(self::CABANG_A, self::UKER_A, 'TID', self::POSISI, 100);

        $this->edc(self::CABANG_A, self::UKER_A, 'SALES_VOLUME', '2026-01-05', 100 * 1_000_000);
        $this->edc(self::CABANG_A, self::UKER_A, 'SALES_VOLUME', self::POSISI, 80 * 1_000_000);

        $this->edc(self::CABANG_A, self::UKER_A, 'JUMLAH_TRX', self::POSISI, 25);
        $this->edc(self::CABANG_A, self::UKER_A, 'EDC_SV_0', self::POSISI, 5);

        $this->edc(self::CABANG_B, self::UKER_B, 'TID', self::POSISI, 900);

        RkaEdc::factory()->create(['cabang_id' => self::CABANG_A, 'uker_id' => self::UKER_A, 'kpi' => 'TID', 'tahun' => 2026, 'bulan' => 3, 'target' => 125]);
        RkaEdc::factory()->create(['cabang_id' => self::CABANG_A, 'uker_id' => self::UKER_A, 'kpi' => 'SALES_VOLUME', 'tahun' => 2026, 'bulan' => 3, 'target' => 200 * 1_000_000]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    private function snapshot(User $user, array $filter = []): array
    {
        return $this->actingAs($user)
            ->getJson('/api/merchant/edc/snapshot?'.http_build_query($filter + ['tanggal' => self::POSISI]))
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array<string, mixed>
     */
    private function kartu(array $snapshot, string $kode): array
    {
        return collect($snapshot['kartu'])->firstWhere('kode', $kode);
    }

    private function angka(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    public function test_halaman_bisa_dibuka(): void
    {
        $this->actingAs($this->admin())->get('/dashboard/merchant')->assertOk();
    }

    // --- Inti: stok vs flow ----------------------------------------------

    public function test_kpi_stok_bernilai_sum_pada_tanggal_itu_bukan_akumulasi(): void
    {
        $tid = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'TID');

        // 100 di 10 Mar — baris 5 Jan (40) TIDAK ikut diakumulasi.
        $this->assertSame(100.0, $this->angka($tid['nilai']));
        // KPI hitungan: nilai apa adanya, tidak dikonversi ke juta.
        $this->assertFalse($tid['rupiah']);
    }

    public function test_kpi_flow_bernilai_akumulasi_ytd(): void
    {
        $sv = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'SALES_VOLUME');

        // 100 jt (5 Jan) + 80 jt (10 Mar) = 180 jt, ditampilkan dalam juta.
        $this->assertSame(180.0, $this->angka($sv['nilai']));
        $this->assertTrue($sv['rupiah']);
    }

    public function test_kpi_flow_tidak_menampilkan_kolom_ytd_tapi_stok_iya(): void
    {
        $snapshot = $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]);

        $tidKeys = collect($this->kartu($snapshot, 'TID')['label_delta'])->pluck('key')->all();
        $svKeys = collect($this->kartu($snapshot, 'SALES_VOLUME')['label_delta'])->pluck('key')->all();

        // Stok: D-1/MTD/YTD/YoY. Flow: D-1/MTD/YoY (YTD dilewati).
        $this->assertSame(['dtd', 'mtd', 'ytd', 'yoy'], $tidKeys);
        $this->assertSame(['dtd', 'mtd', 'yoy'], $svKeys);
    }

    public function test_pencapaian_hanya_untuk_kpi_bertarget(): void
    {
        $snapshot = $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]);

        $tid = $this->kartu($snapshot, 'TID');
        $this->assertTrue($tid['punya_target']);
        $this->assertSame(80.0, $this->angka($tid['pencapaian']));   // 100 / 125

        $trx = $this->kartu($snapshot, 'JUMLAH_TRX');
        $this->assertFalse($trx['punya_target']);
        $this->assertNull($trx['pencapaian']);
        $this->assertNull($trx['target']);
    }

    public function test_flag_inverse_diteruskan(): void
    {
        $svNol = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'EDC_SV_0');

        $this->assertTrue($svNol['inverse']);
        $this->assertFalse($svNol['punya_target']);
    }

    // --- Chart -----------------------------------------------------------

    public function test_chart_flow_kumulatif_stok_harian(): void
    {
        $flow = $this->actingAs($this->admin())
            ->getJson('/api/merchant/edc/chart?'.http_build_query(['tanggal' => self::POSISI, 'cabang_id' => self::CABANG_A, 'kpi' => 'SALES_VOLUME']))
            ->assertOk()
            ->json();

        $this->assertTrue($flow['flow']);
        $titikFlow = collect($flow['seri'])->flatMap(fn ($s) => $s['titik'])->keyBy('tanggal');
        $this->assertSame(100.0, $this->angka($titikFlow['2026-01-05']['nilai']));   // kumulatif Jan
        $this->assertSame(180.0, $this->angka($titikFlow[self::POSISI]['nilai']));    // kumulatif s/d 10 Mar

        $stok = $this->actingAs($this->admin())
            ->getJson('/api/merchant/edc/chart?'.http_build_query(['tanggal' => self::POSISI, 'cabang_id' => self::CABANG_A, 'kpi' => 'TID']))
            ->assertOk()
            ->json();

        $this->assertFalse($stok['flow']);
        $titikStok = collect($stok['seri'])->flatMap(fn ($s) => $s['titik'])->keyBy('tanggal');
        $this->assertSame(40.0, $this->angka($titikStok['2026-01-05']['nilai']));     // harian, bukan akumulasi
        $this->assertSame(100.0, $this->angka($titikStok[self::POSISI]['nilai']));
    }

    // --- Tabel & scoping -------------------------------------------------

    public function test_branch_pencapaian_hormati_flag_target(): void
    {
        $tid = $this->actingAs($this->admin())
            ->getJson('/api/merchant/edc/branch-pencapaian?'.http_build_query(['tanggal' => self::POSISI, 'kpi' => 'TID']))
            ->assertOk()
            ->json();

        $this->assertTrue($tid['punya_target']);
        $this->assertSame('cabang', $tid['grouping']);
        $a = collect($tid['baris'])->firstWhere('id', self::CABANG_A);
        $this->assertSame(100.0, $this->angka($a['nilai']));

        $trx = $this->actingAs($this->admin())
            ->getJson('/api/merchant/edc/branch-pencapaian?'.http_build_query(['tanggal' => self::POSISI, 'kpi' => 'JUMLAH_TRX']))
            ->assertOk()
            ->json();

        $this->assertFalse($trx['punya_target']);
    }

    public function test_user_bo_tidak_bisa_melihat_kpi_cabang_lain(): void
    {
        $bo = User::factory()->bo(self::CABANG_A)->create();

        // Admin tanpa filter TID di 10 Mar = A(100) + B(900) = 1000. BO A = 100.
        $this->assertSame(1000.0, $this->angka($this->kartu($this->snapshot($this->admin()), 'TID')['nilai']));
        $this->assertSame(100.0, $this->angka($this->kartu($this->snapshot($bo, ['cabang_id' => self::CABANG_B]), 'TID')['nilai']));
    }

    public function test_rollup_855_tidak_ikut_terhitung(): void
    {
        $this->edc(855, 855, 'TID', self::POSISI, 9_000);

        $this->assertSame(1000.0, $this->angka($this->kartu($this->snapshot($this->admin()), 'TID')['nilai']));
    }

    public function test_endpoint_ranking_kosong_untuk_user_level_uker(): void
    {
        $user = User::factory()->uker(self::CABANG_A, self::UKER_A)->create();

        $this->actingAs($user)
            ->getJson('/api/merchant/edc/branch-pencapaian?'.http_build_query(['tanggal' => self::POSISI, 'kpi' => 'TID']))
            ->assertOk()
            ->assertJson(['baris' => []]);
    }

    public function test_semua_endpoint_merchant_menolak_tamu(): void
    {
        foreach (['edc', 'qris'] as $sub) {
            foreach (['snapshot', 'chart', 'branch-pencapaian', 'filter-options'] as $endpoint) {
                $this->getJson("/api/merchant/{$sub}/{$endpoint}")->assertUnauthorized();
            }
        }
    }
}
