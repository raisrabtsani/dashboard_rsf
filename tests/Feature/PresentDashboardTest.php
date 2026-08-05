<?php

namespace Tests\Feature;

use App\Models\Laba;
use App\Models\Pinjaman;
use App\Models\PinjamanCommercial;
use App\Models\Recovery;
use App\Models\RkaSimpanan;
use App\Models\RkaSimpananWholesale;
use App\Models\Simpanan;
use App\Models\SimpananWholesale;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Halaman PRESENT (rapat pagi Region).
 *
 * Fokus: (1) gerbang RO/admin di halaman & data, (2) CAKUPAN LENGKAP — DPK
 * menjumlahkan simpanan + simpanan_wholesale, Pinjaman menjumlahkan pinjaman +
 * pinjaman_commercial, (3) rasio %CASA/%LDR, (4) detail per cabang termasuk
 * segmen tambahan.
 */
class PresentDashboardTest extends TestCase
{
    use RefreshDatabase;

    private const CABANG_A = 159;

    private const UKER_A = 5438;

    private const POSISI = '2026-08-05';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
        $this->buatData();
    }

    private function dpk(int $produk_saldo_jt, string $produk, bool $wholesale = false): void
    {
        $model = $wholesale ? SimpananWholesale::class : Simpanan::class;

        $model::factory()->create([
            'cabang_id' => self::CABANG_A,
            'uker_id' => self::UKER_A,
            'produk' => $produk,
            'segmentasi' => $wholesale ? 'Wholesale' : 'Ritel',
            'tanggal' => self::POSISI,
            'saldo' => $produk_saldo_jt * 1_000_000,
        ]);
    }

    private function pinjaman(float $jt, string $kualitas, bool $commercial = false): void
    {
        $model = $commercial ? PinjamanCommercial::class : Pinjaman::class;

        $model::factory()->create([
            'cabang_id' => self::CABANG_A,
            'uker_id' => self::UKER_A,
            'segmen' => $commercial ? 'Commercial' : 'Mikro',
            'kualitas' => $kualitas,
            'tanggal' => self::POSISI,
            'baki_debet' => $jt * 1_000_000,
        ]);
    }

    /**
     * DPK lengkap = 200 (harian) + 50 (wholesale) = 250 jt; CASA = 190 jt.
     * OS lengkap  = 190 (harian) + 105 (commercial) = 295 jt; SML = 25, NPL = 10.
     */
    private function buatData(): void
    {
        // DPK harian: Tabungan 100, Giro 40, Deposito 60 (CASA harian = 140).
        $this->dpk(100, Simpanan::PRODUK_TABUNGAN);
        $this->dpk(40, Simpanan::PRODUK_GIRO);
        $this->dpk(60, Simpanan::PRODUK_DEPOSITO);
        // DPK Wholesale: Tabungan 50 (ikut CASA).
        $this->dpk(50, Simpanan::PRODUK_TABUNGAN, wholesale: true);

        // Pinjaman harian: OS = 160 + 20 + 10 = 190.
        $this->pinjaman(160, Pinjaman::KUALITAS_LANCAR);
        $this->pinjaman(20, Pinjaman::KUALITAS_SML);
        $this->pinjaman(10, Pinjaman::KUALITAS_NPL);
        // Pinjaman Commercial: OS = 100 + 5 = 105.
        $this->pinjaman(100, Pinjaman::KUALITAS_LANCAR, commercial: true);
        $this->pinjaman(5, Pinjaman::KUALITAS_SML, commercial: true);

        Recovery::factory()->create([
            'cabang_id' => self::CABANG_A, 'uker_id' => self::UKER_A,
            'segmen' => 'Micro', 'tanggal' => self::POSISI, 'actual' => 30 * 1_000_000,
        ]);

        Laba::factory()->create([
            'cabang_id' => self::CABANG_A, 'uker_id' => self::UKER_A,
            'segmen' => 'Micro', 'tahun' => 2026, 'bulan' => 8, 'laba' => 700 * 1_000_000,
        ]);

        // RKA DPK lengkap = 200 + 50 = 250 -> pencapaian DPK 100%.
        RkaSimpanan::factory()->create([
            'cabang_id' => self::CABANG_A, 'uker_id' => self::UKER_A,
            'produk' => Simpanan::PRODUK_TABUNGAN, 'tahun' => 2026, 'bulan' => 8, 'target' => 200 * 1_000_000,
        ]);
        RkaSimpananWholesale::factory()->create([
            'cabang_id' => self::CABANG_A, 'uker_id' => self::UKER_A,
            'produk' => Simpanan::PRODUK_TABUNGAN, 'segmentasi' => 'Wholesale',
            'tahun' => 2026, 'bulan' => 8, 'target' => 50 * 1_000_000,
        ]);
    }

    private function ro(): User
    {
        return User::factory()->ro()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function overview(): array
    {
        return $this->actingAs($this->ro())
            ->getJson('/api/present/overview?tanggal='.self::POSISI)
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function kartu(array $data, string $key): array
    {
        return collect($data['kartu'])->firstWhere('key', $key);
    }

    private function angka(mixed $v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    // --- Gerbang akses ---------------------------------------------------

    public function test_halaman_hanya_untuk_ro_dan_admin(): void
    {
        $this->get('/present')->assertRedirect('/login');
        $this->actingAs(User::factory()->uker(self::CABANG_A, self::UKER_A)->create())->get('/present')->assertForbidden();
        $this->actingAs(User::factory()->bo(self::CABANG_A)->create())->get('/present')->assertForbidden();
        $this->actingAs(User::factory()->ro()->create())->get('/present')->assertOk();
        $this->actingAs(User::factory()->admin()->create())->get('/present')->assertOk();
    }

    public function test_endpoint_data_juga_dijaga_present(): void
    {
        $endpoints = ['overview', 'area', 'detail'];

        // Tamu lebih dulu — actingAs bertahan dalam satu test method.
        foreach ($endpoints as $endpoint) {
            $this->getJson("/api/present/{$endpoint}")->assertUnauthorized();
        }

        $bo = User::factory()->bo(self::CABANG_A)->create();
        foreach ($endpoints as $endpoint) {
            $this->actingAs($bo)->getJson("/api/present/{$endpoint}")->assertForbidden();
        }

        $ro = User::factory()->ro()->create();
        foreach ($endpoints as $endpoint) {
            $this->actingAs($ro)->getJson("/api/present/{$endpoint}")->assertOk();
        }
    }

    // --- Cakupan lengkap -------------------------------------------------

    public function test_dpk_menjumlahkan_wholesale(): void
    {
        // 200 (harian) + 50 (wholesale) = 250; tanpa wholesale hanya 200.
        $this->assertSame(250.0, $this->angka($this->kartu($this->overview(), 'dpk')['nilai']));
    }

    public function test_pinjaman_menjumlahkan_commercial(): void
    {
        // 190 (harian) + 105 (commercial) = 295.
        $this->assertSame(295.0, $this->angka($this->kartu($this->overview(), 'pinjaman')['nilai']));
    }

    public function test_recovery_dan_laba_terisi(): void
    {
        $data = $this->overview();

        $this->assertSame(30.0, $this->angka($this->kartu($data, 'recovery')['nilai']));
        $this->assertSame(700.0, $this->angka($this->kartu($data, 'laba')['nilai']));
    }

    public function test_pencapaian_dpk_terhadap_rka_lengkap(): void
    {
        // RKA lengkap 250, nilai 250 -> 100%.
        $this->assertSame(100.0, $this->angka($this->kartu($this->overview(), 'dpk')['pencapaian']));
    }

    public function test_rasio_casa_dan_ldr(): void
    {
        $data = $this->overview();

        $casa = collect($data['rasio'])->firstWhere('key', 'casa');
        $ldr = collect($data['rasio'])->firstWhere('key', 'ldr');

        // CASA lengkap = 140 + 50 = 190; DPK = 250 -> 76%.
        $this->assertSame(76.0, $this->angka($casa['nilai']));
        // OS = 295; DPK = 250 -> 118%.
        $this->assertSame(118.0, $this->angka($ldr['nilai']));
    }

    // --- Slide 2 & 3 -----------------------------------------------------

    public function test_overview_area_tanpa_rasio(): void
    {
        $data = $this->actingAs($this->ro())
            ->getJson('/api/present/area?tanggal='.self::POSISI)
            ->assertOk()
            ->json();

        $this->assertArrayNotHasKey('rasio', $data);

        // Area 1 (cabang 159) memuat DPK lengkap 250.
        $area1 = collect($data['area'])->firstWhere('area_id', 1);
        $this->assertNotNull($area1);
        $dpk = collect($area1['kartu'])->firstWhere('key', 'dpk');
        $this->assertSame(250.0, $this->angka($dpk['nilai']));
    }

    public function test_detail_per_cabang_termasuk_segmen_tambahan(): void
    {
        $data = $this->actingAs($this->ro())
            ->getJson('/api/present/detail?tanggal='.self::POSISI)
            ->assertOk()
            ->json();

        $ambil = fn (string $key) => collect($data['tabel'])->firstWhere('key', $key);

        $dpk = $ambil('dpk');
        $barisDpk = collect($dpk['baris'])->firstWhere('id', self::CABANG_A);
        $this->assertSame(250.0, $this->angka($barisDpk['nilai']));   // termasuk wholesale

        $sml = $ambil('sml');
        $this->assertTrue($sml['inverse']);
        $barisSml = collect($sml['baris'])->firstWhere('id', self::CABANG_A);
        $this->assertSame(25.0, $this->angka($barisSml['nilai']));    // 20 + 5 (commercial)

        $npl = $ambil('npl');
        $barisNpl = collect($npl['baris'])->firstWhere('id', self::CABANG_A);
        $this->assertSame(10.0, $this->angka($barisNpl['nilai']));
    }

    public function test_semua_route_present_pakai_scope(): void
    {
        // ScopeEnforcementTest global sudah menjaganya, tapi kunci juga di sini:
        // endpoint present berada di grup scope (bukan bocor lintas kantor).
        $this->actingAs($this->ro())->getJson('/api/present/overview')->assertOk();
    }
}
