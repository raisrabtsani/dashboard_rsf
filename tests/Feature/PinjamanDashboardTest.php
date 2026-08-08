<?php

namespace Tests\Feature;

use App\Models\Pinjaman;
use App\Models\Region;
use App\Models\RkaPinjaman;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization test domain Pinjaman.
 *
 * Fokus pada yang BERBEDA dari Simpanan: dimensi kualitas, tab Total/SML/NPL,
 * MoM "Date to Date", endpoint chart-segmen & produk, dan perlakuan rollup 855
 * yang justru diikutkan.
 */
class PinjamanDashboardTest extends TestCase
{
    use RefreshDatabase;

    // Master Jakarta 2: cabang 12 = KC Bogor (uker 804), cabang 319 = KC Cikarang (uker 842).
    private const CABANG_A = 12;

    private const UKER_A = 804;

    private const CABANG_B = 319;

    private const UKER_B = 842;

    private const POSISI = '2026-06-18';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
        $this->buatData();
    }

    private function baris(int $cabang, int $uker, string $segmen, string $kualitas, string $tanggal, float $juta): void
    {
        Pinjaman::factory()->create([
            'cabang_id' => $cabang,
            'uker_id' => $uker,
            'segmen' => $segmen,
            'segmentasi' => 'Ritel',
            'kualitas' => $kualitas,
            'tanggal' => $tanggal,
            'baki_debet' => $juta * 1_000_000,
        ]);
    }

    /**
     * Cabang A, segmen Mikro. Posisi 18 Jun: Lancar 800, SML 120, NPL 80 (juta).
     */
    private function buatData(): void
    {
        $tanggal = [
            self::POSISI => [800, 120, 80],
            '2026-06-17' => [790, 110, 75],   // D-1
            '2026-05-31' => [700, 100, 70],   // akhir bulan lalu (MTD)
            '2025-12-31' => [600, 90, 60],    // akhir Des (YTD)
            '2025-06-18' => [500, 60, 40],    // tanggal sama tahun lalu (YoY)
            '2026-05-18' => [720, 105, 65],   // tanggal sama bulan lalu (MoM)
        ];

        foreach ($tanggal as $tgl => [$lancar, $sml, $npl]) {
            $this->baris(self::CABANG_A, self::UKER_A, 'Micro', Pinjaman::KUALITAS_LANCAR, $tgl, $lancar);
            $this->baris(self::CABANG_A, self::UKER_A, 'Micro', Pinjaman::KUALITAS_SML, $tgl, $sml);
            $this->baris(self::CABANG_A, self::UKER_A, 'Micro', Pinjaman::KUALITAS_NPL, $tgl, $npl);
        }

        // Cabang lain, hanya tanggal posisi — untuk uji scoping.
        $this->baris(self::CABANG_B, self::UKER_B, 'Small', Pinjaman::KUALITAS_LANCAR, self::POSISI, 1_000);

        // RKA Juni 2026 cabang A segmen Mikro.
        foreach ([[Pinjaman::KUALITAS_LANCAR, 1_000], [Pinjaman::KUALITAS_SML, 100], [Pinjaman::KUALITAS_NPL, 100]] as [$k, $t]) {
            RkaPinjaman::factory()->create([
                'cabang_id' => self::CABANG_A,
                'uker_id' => self::UKER_A,
                'segmen' => 'Micro',
                'segmentasi' => 'Ritel',
                'kualitas' => $k,
                'tahun' => 2026,
                'bulan' => 6,
                'target' => $t * 1_000_000,
            ]);
        }
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    private function snapshot(User $user, string $tab = 'total', array $filter = []): array
    {
        return $this->actingAs($user)
            ->getJson('/api/pinjaman/snapshot?'.http_build_query($filter + [
                'tanggal' => self::POSISI,
                'tab' => $tab,
            ]))
            ->assertOk()
            ->json();
    }

    private function kartu(array $snapshot, string $key): array
    {
        $kartu = collect($snapshot['kartu'])->firstWhere('key', $key);

        foreach (['nilai', 'target', 'pencapaian', 'gap'] as $f) {
            $kartu[$f] = $kartu[$f] === null ? null : (float) $kartu[$f];
        }

        foreach ($kartu['delta'] as $jenis => $d) {
            $kartu['delta'][$jenis] = [
                'nilai' => $d['nilai'] === null ? null : (float) $d['nilai'],
                'persen' => $d['persen'] === null ? null : (float) $d['persen'],
            ];
        }

        return $kartu;
    }

    public function test_halaman_bisa_dibuka(): void
    {
        $this->actingAs($this->admin())->get('/dashboard/pinjaman')->assertOk();
    }

    // --- Tab & OS --------------------------------------------------------

    public function test_tab_total_menjumlahkan_ketiga_kualitas_sebagai_os(): void
    {
        $snapshot = $this->snapshot($this->admin(), 'total', ['cabang_id' => self::CABANG_A]);

        // OS = 800 + 120 + 80
        $this->assertSame(1_000.0, $this->kartu($snapshot, 'total')['nilai']);
        $this->assertSame('Total OS', $this->kartu($snapshot, 'total')['judul']);
    }

    public function test_tab_sml_dan_npl_hanya_menghitung_kualitasnya_sendiri(): void
    {
        $sml = $this->snapshot($this->admin(), 'sml', ['cabang_id' => self::CABANG_A]);
        $npl = $this->snapshot($this->admin(), 'npl', ['cabang_id' => self::CABANG_A]);

        $this->assertSame(120.0, $this->kartu($sml, 'total')['nilai']);
        $this->assertSame(80.0, $this->kartu($npl, 'total')['nilai']);
    }

    public function test_kartu_dipecah_per_segmen(): void
    {
        $snapshot = $this->snapshot($this->admin(), 'total', ['cabang_id' => self::CABANG_A]);

        $this->assertSame(1_000.0, $this->kartu($snapshot, 'Micro')['nilai']);
    }

    public function test_tab_tak_dikenal_jatuh_ke_total(): void
    {
        $snapshot = $this->snapshot($this->admin(), 'entah', ['cabang_id' => self::CABANG_A]);

        $this->assertSame('total', $snapshot['tab']);
        $this->assertSame(1_000.0, $this->kartu($snapshot, 'total')['nilai']);
    }

    // --- Delta & MoM ------------------------------------------------------

    public function test_tab_total_memakai_dtd_mtd_ytd_yoy(): void
    {
        $total = $this->kartu($this->snapshot($this->admin(), 'total', ['cabang_id' => self::CABANG_A]), 'total');

        $this->assertArrayHasKey('yoy', $total['delta']);
        $this->assertArrayNotHasKey('mom', $total['delta']);

        // posisi 1000
        $this->assertSame(25.0, $total['delta']['dtd']['nilai']);    // vs 975 (17 Jun)
        $this->assertSame(130.0, $total['delta']['mtd']['nilai']);   // vs 870 (31 Mei)
        $this->assertSame(250.0, $total['delta']['ytd']['nilai']);   // vs 750 (31 Des)
        $this->assertSame(400.0, $total['delta']['yoy']['nilai']);   // vs 600 (18 Jun 2025)
    }

    public function test_tab_sml_mengganti_yoy_dengan_mom_tanggal_yang_sama_bulan_lalu(): void
    {
        $snapshot = $this->snapshot($this->admin(), 'sml', ['cabang_id' => self::CABANG_A]);
        $total = $this->kartu($snapshot, 'total');

        $this->assertArrayHasKey('mom', $total['delta']);
        $this->assertArrayNotHasKey('yoy', $total['delta']);

        // 18 Jun (120) dibanding 18 Mei (105).
        $this->assertSame('2026-05-18', $snapshot['tanggal_referensi']['mom']);
        $this->assertSame(15.0, $total['delta']['mom']['nilai']);
    }

    public function test_label_delta_terakhir_berubah_jadi_date_to_date(): void
    {
        $total = $this->snapshot($this->admin(), 'total', ['cabang_id' => self::CABANG_A]);
        $npl = $this->snapshot($this->admin(), 'npl', ['cabang_id' => self::CABANG_A]);

        $this->assertSame(['dtd', 'mtd', 'ytd', 'yoy'], collect($total['label_delta'])->pluck('key')->all());
        $this->assertSame('YoY', collect($total['label_delta'])->last()['label']);

        $this->assertSame(['dtd', 'mtd', 'ytd', 'mom'], collect($npl['label_delta'])->pluck('key')->all());
        $this->assertSame('Date to Date', collect($npl['label_delta'])->last()['label']);
    }

    public function test_mom_fallback_ke_tanggal_terakhir_yang_tersedia_di_bulan_itu(): void
    {
        // Hapus 18 Mei; tersisa 31 Mei sebagai tanggal terakhir bulan itu.
        Pinjaman::query()->where('tanggal', '2026-05-18')->delete();

        $snapshot = $this->snapshot($this->admin(), 'sml', ['cabang_id' => self::CABANG_A]);

        $this->assertSame('2026-05-31', $snapshot['tanggal_referensi']['mom']);
        // 120 vs 100 (SML 31 Mei)
        $this->assertSame(20.0, $this->kartu($snapshot, 'total')['delta']['mom']['nilai']);
    }

    public function test_mom_memakai_sub_month_no_overflow(): void
    {
        // 31 Mar -> 28 Feb (bukan lompat ke Maret lagi).
        $this->baris(self::CABANG_A, self::UKER_A, 'Micro', Pinjaman::KUALITAS_NPL, '2026-03-31', 50);
        $this->baris(self::CABANG_A, self::UKER_A, 'Micro', Pinjaman::KUALITAS_NPL, '2026-02-28', 40);

        $snapshot = $this->actingAs($this->admin())
            ->getJson('/api/pinjaman/snapshot?'.http_build_query([
                'tanggal' => '2026-03-31',
                'tab' => 'npl',
                'cabang_id' => self::CABANG_A,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame('2026-02-28', $snapshot['tanggal_referensi']['mom']);
    }

    public function test_mom_null_bila_bulan_lalu_tidak_ada_data_sama_sekali(): void
    {
        Pinjaman::query()->whereBetween('tanggal', ['2026-05-01', '2026-05-31'])->delete();

        $snapshot = $this->snapshot($this->admin(), 'npl', ['cabang_id' => self::CABANG_A]);

        $this->assertNull($snapshot['tanggal_referensi']['mom']);
        $this->assertNull($this->kartu($snapshot, 'total')['delta']['mom']['nilai']);
    }

    // --- Pencapaian & arah -------------------------------------------------

    public function test_pencapaian_dihitung_terhadap_rka_kualitas_tab(): void
    {
        // Tab Total: target = 1000+100+100 = 1200; aktual 1000 -> 83,33%
        $total = $this->kartu($this->snapshot($this->admin(), 'total', ['cabang_id' => self::CABANG_A]), 'total');
        $this->assertSame(1_200.0, $total['target']);
        $this->assertSame(83.33, $total['pencapaian']);

        // Tab NPL: target 100, aktual 80 -> 80% (dan 80% itu BAGUS untuk NPL)
        $npl = $this->kartu($this->snapshot($this->admin(), 'npl', ['cabang_id' => self::CABANG_A]), 'total');
        $this->assertSame(100.0, $npl['target']);
        $this->assertSame(80.0, $npl['pencapaian']);
    }

    public function test_target_os_tidak_dijumlahkan_lagi_dengan_target_sml_dan_npl(): void
    {
        RkaPinjaman::factory()->create([
            'cabang_id' => self::CABANG_A,
            'uker_id' => self::UKER_A,
            'segmen' => 'Micro',
            'segmentasi' => 'Ritel',
            'kualitas' => RkaPinjaman::KUALITAS_OS,
            'tahun' => 2026,
            'bulan' => 6,
            'target' => 900 * 1_000_000,
        ]);

        // Baris legacy Lancar/SML/NPL masih ada dari setUp, tetapi untuk tab
        // Total harus digantikan oleh target OS, bukan ikut dijumlahkan.
        $total = $this->kartu($this->snapshot($this->admin(), 'total', ['cabang_id' => self::CABANG_A]), 'total');
        $sml = $this->kartu($this->snapshot($this->admin(), 'sml', ['cabang_id' => self::CABANG_A]), 'total');
        $npl = $this->kartu($this->snapshot($this->admin(), 'npl', ['cabang_id' => self::CABANG_A]), 'total');

        $this->assertSame(900.0, $total['target']);
        $this->assertSame(100.0, $sml['target']);
        $this->assertSame(100.0, $npl['target']);
    }

    public function test_flag_inverse_dikirim_ke_frontend(): void
    {
        $this->assertFalse($this->snapshot($this->admin(), 'total')['inverse']);
        $this->assertTrue($this->snapshot($this->admin(), 'sml')['inverse']);
        $this->assertTrue($this->snapshot($this->admin(), 'npl')['inverse']);
    }

    // --- Endpoint khusus ---------------------------------------------------

    public function test_endpoint_produk_merinci_per_produk_dengan_target_dan_gap(): void
    {
        // Produk kedua di segmen Mikro (hanya tanggal posisi) untuk menguji
        // pengelompokan per produk (segmentasi) dan pengurutan nilai.
        Pinjaman::factory()->create([
            'cabang_id' => self::CABANG_A,
            'uker_id' => self::UKER_A,
            'segmen' => 'Micro',
            'segmentasi' => 'Kupedes',
            'kualitas' => Pinjaman::KUALITAS_LANCAR,
            'tanggal' => self::POSISI,
            'baki_debet' => 200 * 1_000_000,
        ]);

        $data = $this->actingAs($this->admin())
            ->getJson('/api/pinjaman/produk?'.http_build_query([
                'tanggal' => self::POSISI,
                'tab' => 'total',
                'cabang_id' => self::CABANG_A,
            ]))
            ->assertOk()
            ->json();

        $mikro = collect($data['kelompok'])->firstWhere('segmen', 'Micro');

        // Total segmen = Ritel (OS 1000) + Kupedes (200).
        $this->assertSame(1_200.0, (float) $mikro['total']['nilai']);
        // Terurut nilai desc: Ritel dulu, lalu Kupedes.
        $this->assertSame(['Ritel', 'Kupedes'], collect($mikro['produk'])->pluck('segmentasi')->all());

        $produk = collect($mikro['produk'])->keyBy('segmentasi');

        // Ritel: OS 1000 vs target RKA 1200 (Lancar1000+SML100+NPL100).
        $this->assertSame(1_000.0, (float) $produk['Ritel']['nilai']);
        $this->assertSame(1_200.0, (float) $produk['Ritel']['target']);
        $this->assertSame(-200.0, (float) $produk['Ritel']['gap']);
        $this->assertSame(25.0, (float) $produk['Ritel']['delta']['dtd']['nilai']);   // vs 975 (17 Jun)
        $this->assertSame(400.0, (float) $produk['Ritel']['delta']['yoy']['nilai']);  // vs 600 (18 Jun 2025)

        // Kupedes tanpa RKA -> pencapaian null (bukan 0), gap = nilainya sendiri.
        $this->assertSame(200.0, (float) $produk['Kupedes']['nilai']);
        $this->assertNull($produk['Kupedes']['pencapaian']);
    }

    public function test_endpoint_produk_menghormati_tab_kualitas(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/pinjaman/produk?'.http_build_query([
                'tanggal' => self::POSISI,
                'tab' => 'npl',
                'cabang_id' => self::CABANG_A,
            ]))
            ->assertOk()
            ->json();

        $this->assertSame('npl', $data['tab']);
        $this->assertTrue($data['inverse']);

        // Tab NPL: total segmen Mikro = NPL Ritel saja = 80.
        $mikro = collect($data['kelompok'])->firstWhere('segmen', 'Micro');
        $this->assertSame(80.0, (float) $mikro['total']['nilai']);
        $this->assertSame(80.0, (float) collect($mikro['produk'])->firstWhere('segmentasi', 'Ritel')['nilai']);
    }

    public function test_endpoint_chart_segmen_memecah_deret_per_segmen(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/pinjaman/chart-segmen?'.http_build_query([
                'tanggal' => self::POSISI,
                'tab' => 'total',
            ]))
            ->assertOk()
            ->json();

        $segmen = collect($data['seri'])->pluck('segmen')->all();

        $this->assertContains('Micro', $segmen);
        $this->assertContains('Small', $segmen);
    }

    // --- Rollup 855 --------------------------------------------------------

    public function test_rollup_855_ikut_dihitung_berbeda_dari_simpanan(): void
    {
        $sebelum = $this->kartu($this->snapshot($this->admin(), 'total'), 'total')['nilai'];

        $this->baris(Region::OFFICE_ID, Region::OFFICE_ID, 'Medium', Pinjaman::KUALITAS_LANCAR, self::POSISI, 5_000);

        $sesudah = $this->kartu($this->snapshot($this->admin(), 'total'), 'total')['nilai'];

        // Segmen Menengah dikelola Region — HARUS masuk total.
        $this->assertSame($sebelum + 5_000.0, $sesudah);
    }

    public function test_rollup_855_tetap_disembunyikan_dari_baris_tabel_cabang(): void
    {
        $this->baris(Region::OFFICE_ID, Region::OFFICE_ID, 'Medium', Pinjaman::KUALITAS_LANCAR, self::POSISI, 5_000);

        $data = $this->actingAs($this->admin())
            ->getJson('/api/pinjaman/branch-pencapaian?tanggal='.self::POSISI.'&tab=total')
            ->assertOk()
            ->json();

        $this->assertNotContains(Region::OFFICE_ID, collect($data['baris'])->pluck('id')->all());
    }

    public function test_ranking_cabang_menyertakan_deskripsi_area_head(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/pinjaman/branch-pencapaian?tanggal='.self::POSISI.'&tab=total')
            ->assertOk()
            ->json();

        $bogor = collect($data['baris'])->firstWhere('id', self::CABANG_A);

        $this->assertNotNull($bogor);
        $this->assertArrayHasKey('area_head_id', $bogor);
        $this->assertArrayHasKey('area_head', $bogor);
        $this->assertNotEmpty($bogor['area_head']);
    }

    public function test_ranking_cabang_mengirim_d1_mtd_ytd_dan_yoy_beserta_persentasenya(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/pinjaman/branch-pencapaian?tanggal='.self::POSISI.'&tab=total')
            ->assertOk()
            ->json();

        $bogor = collect($data['baris'])->firstWhere('id', self::CABANG_A);

        $this->assertSame([
            'dtd' => '2026-06-17',
            'mtd' => '2026-05-31',
            'ytd' => '2025-12-31',
            'yoy' => '2025-06-18',
        ], $data['tanggal_referensi']);
        $this->assertSame(['dtd', 'mtd', 'ytd', 'yoy'], collect($data['label_delta'])->pluck('key')->all());
        $this->assertSame('D-1', $data['label_delta'][0]['label']);

        $this->assertSame(25.0, (float) $bogor['dtd']['nilai']);
        $this->assertSame(2.56, (float) $bogor['dtd']['persen']);
        $this->assertSame(130.0, (float) $bogor['mtd']['nilai']);
        $this->assertSame(14.94, (float) $bogor['mtd']['persen']);
        $this->assertSame(250.0, (float) $bogor['ytd']['nilai']);
        $this->assertSame(33.33, (float) $bogor['ytd']['persen']);
        $this->assertSame(400.0, (float) $bogor['yoy']['nilai']);
        $this->assertSame(66.67, (float) $bogor['yoy']['persen']);
    }

    public function test_ranking_sml_tetap_memakai_yoy_bukan_mom(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/pinjaman/branch-pencapaian?tanggal='.self::POSISI.'&tab=sml')
            ->assertOk()
            ->json();

        $bogor = collect($data['baris'])->firstWhere('id', self::CABANG_A);

        $this->assertArrayHasKey('yoy', $bogor);
        $this->assertSame(60.0, (float) $bogor['yoy']['nilai']);
        $this->assertSame(100.0, (float) $bogor['yoy']['persen']);
    }

    public function test_filter_segmentasi_ranking_hanya_menampilkan_segmen_terpilih(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/pinjaman/branch-pencapaian?'.http_build_query([
                'tanggal' => self::POSISI,
                'tab' => 'total',
                'segmentasi' => 'Micro',
            ]))
            ->assertOk()
            ->json();

        $ids = collect($data['baris'])->pluck('id')->all();

        $this->assertContains(self::CABANG_A, $ids);
        $this->assertNotContains(self::CABANG_B, $ids);
    }

    // --- Scoping ------------------------------------------------------------

    public function test_user_bo_yang_meminta_cabang_lain_tetap_menerima_angka_cabangnya(): void
    {
        $bo = User::factory()->bo(self::CABANG_A)->create();

        $snapshot = $this->snapshot($bo, 'total', ['cabang_id' => self::CABANG_B]);

        $this->assertSame(1_000.0, $this->kartu($snapshot, 'total')['nilai']);
    }

    public function test_endpoint_ranking_kosong_untuk_user_level_uker(): void
    {
        $user = User::factory()->uker(self::CABANG_A, self::UKER_A)->create();

        $this->actingAs($user)
            ->getJson('/api/pinjaman/branch-pencapaian?tanggal='.self::POSISI)
            ->assertOk()
            ->assertJson(['baris' => []]);
    }

    public function test_semua_endpoint_pinjaman_menolak_tamu(): void
    {
        foreach (['snapshot', 'chart', 'chart-segmen', 'produk', 'branch-pencapaian', 'filter-options'] as $endpoint) {
            $this->getJson("/api/pinjaman/{$endpoint}")->assertUnauthorized();
        }
    }
}
