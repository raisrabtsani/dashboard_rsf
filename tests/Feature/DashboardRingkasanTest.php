<?php

namespace Tests\Feature;

use App\Models\Laba;
use App\Models\Pinjaman;
use App\Models\Simpanan;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization test halaman landing /dashboard (RingkasanService).
 *
 * Data dibangun kecil & eksplisit supaya angka harapannya bisa dihitung tangan.
 * Fokus: (1) kartu tiap domain didelegasikan dengan benar, (2) rasio %CASA/%LDR,
 * (3) Laba mundur otomatis ke bulan terakhir yang tersedia, (4) scoping.
 */
class DashboardRingkasanTest extends TestCase
{
    use RefreshDatabase;

    /** BO Dumai, area 1. */
    private const CABANG_A = 159;

    private const UKER_A = 5438;

    /** BO Batam Center — milik orang lain. */
    private const CABANG_B = 621;

    private const UKER_B = 5516;

    private const POSISI = '2026-08-05';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
        $this->buatData();
    }

    private function simpanan(int $cabang, int $uker, string $produk, string $tanggal, float $jt): void
    {
        Simpanan::factory()->create([
            'cabang_id' => $cabang,
            'uker_id' => $uker,
            'produk' => $produk,
            'segmentasi' => 'Ritel',
            'tanggal' => $tanggal,
            'saldo' => $jt * 1_000_000,
        ]);
    }

    /**
     * Cabang A pada tanggal posisi:
     *   DPK  = Tabungan 100 + Giro 40 + Deposito 60 = 200 jt (CASA = 140 jt).
     *   OS   = Pinjaman Lancar 160 jt.
     *   Laba = kumulatif YTD, HANYA sampai Juni 2026 (Jul/Ags belum rilis).
     */
    private function buatData(): void
    {
        $this->simpanan(self::CABANG_A, self::UKER_A, Simpanan::PRODUK_TABUNGAN, self::POSISI, 100);
        $this->simpanan(self::CABANG_A, self::UKER_A, Simpanan::PRODUK_GIRO, self::POSISI, 40);
        $this->simpanan(self::CABANG_A, self::UKER_A, Simpanan::PRODUK_DEPOSITO, self::POSISI, 60);

        // Cabang lain — untuk menguji scoping.
        $this->simpanan(self::CABANG_B, self::UKER_B, Simpanan::PRODUK_TABUNGAN, self::POSISI, 999);

        Pinjaman::factory()->create([
            'cabang_id' => self::CABANG_A,
            'uker_id' => self::UKER_A,
            'segmen' => 'Mikro',
            'kualitas' => Pinjaman::KUALITAS_LANCAR,
            'tanggal' => self::POSISI,
            'baki_debet' => 160 * 1_000_000,
        ]);

        // Laba bulanan kumulatif, berhenti di Juni — posisi Agustus harus mundur.
        foreach ([5 => 500, 6 => 600] as $bulan => $jt) {
            Laba::factory()->create([
                'cabang_id' => self::CABANG_A,
                'uker_id' => self::UKER_A,
                'segmen' => 'Micro',
                'tahun' => 2026,
                'bulan' => $bulan,
                'laba' => $jt * 1_000_000,
            ]);
        }
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @param  array<string, mixed>  $filter
     * @return array<string, mixed>
     */
    private function ringkasan(User $user, array $filter = []): array
    {
        return $this->actingAs($user)
            ->getJson('/api/dashboard/ringkasan?'.http_build_query($filter + ['tanggal' => self::POSISI]))
            ->assertOk()
            ->json();
    }

    /**
     * @param  array<string, mixed>  $ringkasan
     * @return array<string, mixed>
     */
    private function kartu(array $ringkasan, string $key): array
    {
        return collect($ringkasan['kartu'])->firstWhere('key', $key);
    }

    /**
     * @param  array<string, mixed>  $ringkasan
     * @return array<string, mixed>
     */
    private function rasio(array $ringkasan, string $key): array
    {
        return collect($ringkasan['rasio'])->firstWhere('key', $key);
    }

    private function angka(mixed $nilai): ?float
    {
        return $nilai === null ? null : (float) $nilai;
    }

    public function test_halaman_bisa_dibuka(): void
    {
        $this->actingAs($this->admin())->get('/dashboard')->assertOk();
    }

    public function test_kartu_dpk_didelegasikan_dari_service_simpanan(): void
    {
        $kartu = $this->kartu($this->ringkasan($this->admin(), ['cabang_id' => self::CABANG_A]), 'simpanan');

        $this->assertSame('DPK', $kartu['judul']);
        $this->assertSame('simpanan', $kartu['route']);
        $this->assertSame(200.0, $this->angka($kartu['nilai']));
        $this->assertSame(self::POSISI, $kartu['per']);
    }

    public function test_kartu_pinjaman_os_didelegasikan(): void
    {
        $kartu = $this->kartu($this->ringkasan($this->admin(), ['cabang_id' => self::CABANG_A]), 'pinjaman');

        $this->assertSame(160.0, $this->angka($kartu['nilai']));
    }

    public function test_rasio_casa_dan_ldr_dihitung_dari_kartu_domain(): void
    {
        $ringkasan = $this->ringkasan($this->admin(), ['cabang_id' => self::CABANG_A]);

        // %CASA = CASA 140 / DPK 200 = 70%.
        $this->assertSame(70.0, $this->angka($this->rasio($ringkasan, 'casa')['nilai']));
        // %LDR = OS 160 / DPK 200 = 80%.
        $this->assertSame(80.0, $this->angka($this->rasio($ringkasan, 'ldr')['nilai']));
    }

    public function test_kartu_laba_mundur_ke_bulan_terakhir_yang_tersedia(): void
    {
        // Posisi Agustus, tapi Laba hanya sampai Juni -> kartu memakai Juni.
        $kartu = $this->kartu($this->ringkasan($this->admin(), ['cabang_id' => self::CABANG_A]), 'laba');

        $this->assertSame(600.0, $this->angka($kartu['nilai']));   // kumulatif YTD Juni
        $this->assertSame('Jun 2026', $kartu['per']);
    }

    public function test_domain_tanpa_data_bernilai_null_bukan_nol(): void
    {
        // Recovery tidak punya data sama sekali -> "tidak ada data" bukan 0.
        $kartu = $this->kartu($this->ringkasan($this->admin(), ['cabang_id' => self::CABANG_A]), 'recovery');

        $this->assertNull($kartu['nilai']);
        $this->assertNull($kartu['per']);
    }

    public function test_ringkasan_memuat_delapan_kartu_domain_dan_dua_rasio(): void
    {
        $ringkasan = $this->ringkasan($this->admin());

        $this->assertEqualsCanonicalizing(
            ['simpanan', 'pinjaman', 'recovery', 'laba', 'ph', 'netdg', 'edc', 'qris'],
            collect($ringkasan['kartu'])->pluck('key')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['casa', 'ldr'],
            collect($ringkasan['rasio'])->pluck('key')->all(),
        );
    }

    // --- Scoping ---------------------------------------------------------

    public function test_user_bo_yang_meminta_cabang_lain_tetap_menerima_angka_cabangnya(): void
    {
        $bo = User::factory()->bo(self::CABANG_A)->create();

        // Minta cabang B (999 jt), harusnya tetap dapat angka cabang A (200 jt).
        $kartu = $this->kartu($this->ringkasan($bo, ['cabang_id' => self::CABANG_B]), 'simpanan');

        $this->assertSame(200.0, $this->angka($kartu['nilai']));
    }

    public function test_user_uker_tetap_melihat_ringkasan_lingkupnya_sendiri(): void
    {
        // Endpoint ringkasan BUKAN ranking antar entitas: user uker melihat total
        // ukernya sendiri, bukan dibalas kosong.
        $uker = User::factory()->uker(self::CABANG_A, self::UKER_A)->create();

        $kartu = $this->kartu($this->ringkasan($uker), 'simpanan');

        $this->assertSame(200.0, $this->angka($kartu['nilai']));
    }

    public function test_semua_endpoint_dashboard_menolak_tamu(): void
    {
        foreach (['ringkasan', 'filter-options'] as $endpoint) {
            $this->getJson("/api/dashboard/{$endpoint}")->assertUnauthorized();
        }
    }
}
