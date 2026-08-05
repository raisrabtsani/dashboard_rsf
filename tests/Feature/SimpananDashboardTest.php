<?php

namespace Tests\Feature;

use App\Models\RkaSimpanan;
use App\Models\Simpanan;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Characterization test domain Simpanan — jaring pengaman perilaku.
 *
 * Data dibangun kecil & eksplisit di sini (bukan lewat SimpananDummySeeder)
 * supaya angka harapannya bisa dihitung tangan.
 */
class SimpananDashboardTest extends TestCase
{
    use RefreshDatabase;

    /** BO Dumai. */
    private const CABANG_A = 159;

    private const UKER_A = 5438;

    /** BO Batam Center — milik orang lain. */
    private const CABANG_B = 621;

    private const UKER_B = 5516;

    private const POSISI = '2026-03-10';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
        $this->buatData();
    }

    private function simpanan(int $cabang, int $uker, string $produk, string $tanggal, float $saldo): void
    {
        Simpanan::factory()->create([
            'cabang_id' => $cabang,
            'uker_id' => $uker,
            'produk' => $produk,
            'segmentasi' => 'Ritel',
            'tanggal' => $tanggal,
            'saldo' => $saldo,
        ]);
    }

    /**
     * Cabang A: Tabungan 100 jt, Giro 40 jt, Deposito 60 jt (posisi).
     * Pembanding dipasang di semua tanggal referensi.
     */
    private function buatData(): void
    {
        $tanggal = [
            self::POSISI => [100, 40, 60],
            '2026-03-09' => [90, 40, 60],   // D-1
            '2026-02-28' => [80, 30, 50],   // akhir bulan lalu (MTD)
            '2025-12-31' => [70, 20, 40],   // akhir Des tahun lalu (YTD)
            '2025-03-10' => [50, 10, 30],   // tanggal sama tahun lalu (YoY)
        ];

        foreach ($tanggal as $tgl => [$tabungan, $giro, $deposito]) {
            $this->simpanan(self::CABANG_A, self::UKER_A, Simpanan::PRODUK_TABUNGAN, $tgl, $tabungan * 1_000_000);
            $this->simpanan(self::CABANG_A, self::UKER_A, Simpanan::PRODUK_GIRO, $tgl, $giro * 1_000_000);
            $this->simpanan(self::CABANG_A, self::UKER_A, Simpanan::PRODUK_DEPOSITO, $tgl, $deposito * 1_000_000);
        }

        // Cabang lain, hanya pada tanggal posisi — untuk menguji scoping.
        $this->simpanan(self::CABANG_B, self::UKER_B, Simpanan::PRODUK_TABUNGAN, self::POSISI, 500 * 1_000_000);

        // RKA Maret 2026 untuk cabang A: Tabungan 125 jt (pencapaian 80%).
        RkaSimpanan::factory()->create([
            'cabang_id' => self::CABANG_A,
            'uker_id' => self::UKER_A,
            'produk' => Simpanan::PRODUK_TABUNGAN,
            'tahun' => 2026,
            'bulan' => 3,
            'target' => 125 * 1_000_000,
        ]);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function snapshot(User $user, array $filter = []): array
    {
        return $this->actingAs($user)
            ->getJson('/api/simpanan/snapshot?'.http_build_query($filter + ['tanggal' => self::POSISI]))
            ->assertOk()
            ->json();
    }

    /**
     * Ambil satu kartu snapshot dengan angka dinormalkan ke float.
     *
     * json_encode membuang ".0" sehingga 100.0 kembali sebagai int 100;
     * normalisasi di sini supaya assertion bisa tetap assertSame yang ketat.
     *
     * @param  array<string, mixed>  $snapshot
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
        $this->actingAs($this->admin())->get('/dashboard/simpanan')->assertOk();
    }

    public function test_snapshot_menghitung_nilai_per_produk_dalam_juta(): void
    {
        $snapshot = $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]);

        $this->assertSame(100.0, $this->kartu($snapshot, 'tabungan')['nilai']);
        $this->assertSame(40.0, $this->kartu($snapshot, 'giro')['nilai']);
        $this->assertSame(60.0, $this->kartu($snapshot, 'deposito')['nilai']);
    }

    public function test_total_dpk_dan_casa_diturunkan_dengan_benar(): void
    {
        $snapshot = $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]);

        // Total = Tabungan + Giro + Deposito
        $this->assertSame(200.0, $this->kartu($snapshot, 'total')['nilai']);
        // CASA = Tabungan + Giro (tanpa Deposito)
        $this->assertSame(140.0, $this->kartu($snapshot, 'casa')['nilai']);
    }

    public function test_empat_delta_dihitung_dari_tanggal_pembanding_yang_benar(): void
    {
        $total = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'total');

        // posisi 200 jt
        $this->assertSame(10.0, $total['delta']['dtd']['nilai']);   // vs 190 (09 Mar)
        $this->assertSame(40.0, $total['delta']['mtd']['nilai']);   // vs 160 (28 Feb)
        $this->assertSame(70.0, $total['delta']['ytd']['nilai']);   // vs 130 (31 Des 2025)
        $this->assertSame(110.0, $total['delta']['yoy']['nilai']);  // vs 90  (10 Mar 2025)
    }

    public function test_pencapaian_dan_gap_dihitung_terhadap_rka_bulan_posisi(): void
    {
        $tabungan = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'tabungan');

        $this->assertSame(125.0, $tabungan['target']);
        $this->assertSame(80.0, $tabungan['pencapaian']);   // 100 / 125
        $this->assertSame(-25.0, $tabungan['gap']);         // 100 - 125
    }

    public function test_tanpa_target_pencapaian_null_bukan_nol(): void
    {
        // Giro tidak punya baris RKA — "tidak ada target" bukan "gagal capai target".
        $giro = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'giro');

        $this->assertNull($giro['pencapaian']);
    }

    public function test_delta_tanpa_data_pembanding_bernilai_null(): void
    {
        // Cabang B hanya punya data di tanggal posisi.
        $total = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_B]), 'total');

        $this->assertSame(500.0, $total['nilai']);
        $this->assertNull($total['delta']['dtd']['nilai']);
        $this->assertNull($total['delta']['yoy']['nilai']);
    }

    public function test_chart_mengelompokkan_tren_harian_per_bulan(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/simpanan/chart?'.http_build_query(['tanggal' => self::POSISI, 'cabang_id' => self::CABANG_A]))
            ->assertOk()
            ->json();

        $this->assertSame(2026, $data['tahun']);

        // Dalam tahun 2026 s/d 10 Mar: ada data di 28 Feb, 9 Mar, 10 Mar.
        $bulan = collect($data['seri'])->pluck('bulan')->all();
        $this->assertSame([2, 3], $bulan);

        $maret = collect($data['seri'])->firstWhere('bulan', 3);
        $this->assertSame([9, 10], collect($maret['titik'])->pluck('hari')->all());
        $this->assertSame(200.0, $this->angka(collect($maret['titik'])->firstWhere('hari', 10)['nilai']));
    }

    public function test_branch_pencapaian_mengelompokkan_per_cabang(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/simpanan/branch-pencapaian?tanggal='.self::POSISI)
            ->assertOk()
            ->json();

        $this->assertSame('cabang', $data['grouping']);
        $this->assertEqualsCanonicalizing(
            [self::CABANG_A, self::CABANG_B],
            collect($data['baris'])->pluck('id')->all(),
        );
    }

    public function test_branch_pencapaian_berpindah_ke_per_uker_saat_drill_down(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/simpanan/branch-pencapaian?'.http_build_query([
                'tanggal' => self::POSISI,
                'cabang_id' => self::CABANG_A,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame('uker', $data['grouping']);
        $this->assertSame([self::UKER_A], collect($data['baris'])->pluck('id')->all());
    }

    // --- Scoping ---------------------------------------------------------

    public function test_user_bo_yang_meminta_cabang_lain_tetap_menerima_angka_cabangnya(): void
    {
        $bo = User::factory()->bo(self::CABANG_A)->create();

        // Minta cabang B (500 jt), harusnya tetap dapat angka cabang A (200 jt).
        $snapshot = $this->snapshot($bo, ['cabang_id' => self::CABANG_B]);

        $this->assertSame(200.0, $this->kartu($snapshot, 'total')['nilai']);
        $this->assertNotSame(500.0, $this->kartu($snapshot, 'total')['nilai']);
    }

    public function test_user_bo_tidak_bisa_melihat_total_seluruh_region(): void
    {
        $bo = User::factory()->bo(self::CABANG_A)->create();

        // Tanpa filter apa pun: admin lihat 700 jt, BO tetap 200 jt.
        $this->assertSame(700.0, $this->kartu($this->snapshot($this->admin()), 'total')['nilai']);
        $this->assertSame(200.0, $this->kartu($this->snapshot($bo), 'total')['nilai']);
    }

    public function test_user_uker_terkunci_di_ukernya(): void
    {
        $uker = User::factory()->uker(self::CABANG_A, self::UKER_A)->create();

        $snapshot = $this->snapshot($uker, ['cabang_id' => self::CABANG_B, 'uker_id' => self::UKER_B]);

        $this->assertSame(200.0, $this->kartu($snapshot, 'total')['nilai']);
    }

    public function test_endpoint_ranking_kosong_untuk_user_level_uker(): void
    {
        $user = User::factory()->uker(self::CABANG_A, self::UKER_A)->create();

        $this->actingAs($user)
            ->getJson('/api/simpanan/branch-pencapaian?tanggal='.self::POSISI)
            ->assertOk()
            ->assertJson(['baris' => []]);
    }

    public function test_endpoint_ranking_tetap_terisi_untuk_bo(): void
    {
        $bo = User::factory()->bo(self::CABANG_A)->create();

        $data = $this->actingAs($bo)
            ->getJson('/api/simpanan/branch-pencapaian?tanggal='.self::POSISI)
            ->assertOk()
            ->json();

        // BO dipaksa ke cabangnya -> grouping otomatis jadi per uker.
        $this->assertSame('uker', $data['grouping']);
        $this->assertSame([self::UKER_A], collect($data['baris'])->pluck('id')->all());
    }

    public function test_rollup_855_tidak_ikut_terhitung(): void
    {
        $this->simpanan(855, 855, Simpanan::PRODUK_TABUNGAN, self::POSISI, 9_000 * 1_000_000);

        // Total tetap 700 jt seperti sebelum baris 855 ditambahkan.
        $this->assertSame(700.0, $this->kartu($this->snapshot($this->admin()), 'total')['nilai']);
    }

    public function test_tanggal_tersimpan_sebagai_y_m_d_tanpa_komponen_jam(): void
    {
        // Regresi: cast `date` Laravel menulis 'Y-m-d H:i:s'. MySQL memotong
        // jamnya, SQLite tidak — bikin query tanggal cocok di produksi tapi
        // gagal di test. Jangan kembalikan cast `date` ke kolom ini.
        $mentah = DB::table('simpanan')->value('tanggal');

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) $mentah);
    }

    public function test_semua_endpoint_simpanan_menolak_tamu(): void
    {
        foreach (['snapshot', 'chart', 'branch-pencapaian', 'filter-options'] as $endpoint) {
            $this->getJson("/api/simpanan/{$endpoint}")->assertUnauthorized();
        }
    }
}
