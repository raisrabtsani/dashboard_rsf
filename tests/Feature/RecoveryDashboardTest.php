<?php

namespace Tests\Feature;

use App\Models\Recovery;
use App\Models\RkaRecovery;
use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Characterization test domain Recovery — jaring pengaman perilaku.
 *
 * Fokus khusus domain ini: SEGMEN dinormalkan ke kanonik SAAT BACA, sehingga
 * perbandingan YoY tetap apple-to-apple walau taksonomi berkas berubah antar
 * tahun ("SME" tahun lalu vs "Small" + "Medium" tahun ini).
 */
class RecoveryDashboardTest extends TestCase
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

    private function recovery(int $cabang, int $uker, string $segmen, string $tanggal, float $actual): void
    {
        Recovery::factory()->create([
            'cabang_id' => $cabang,
            'uker_id' => $uker,
            'segmen' => $segmen,
            'tanggal' => $tanggal,
            'actual' => $actual,
        ]);
    }

    /**
     * Cabang A:
     *  - Posisi (2026): Micro 100 jt, Small 30 jt, Medium 40 jt, Consumer 10 jt.
     *    Kanonik -> Micro 100, SME 70 (Small+Medium), Consumer 10. Total 180.
     *  - YoY (2025):    Micro 40 jt, SME 50 jt (mentah "SME"), Consumer 5 jt.
     *
     * RKA Maret 2026: Micro 125 jt; SME dipecah mentah jadi Small 40 + Medium 30
     * (kanonik -> 70), untuk membuktikan target pun dinormalkan saat baca.
     */
    private function buatData(): void
    {
        // Posisi 2026 — taksonomi baru (Small/Medium terpisah).
        $this->recovery(self::CABANG_A, self::UKER_A, 'Micro', self::POSISI, 100 * 1_000_000);
        $this->recovery(self::CABANG_A, self::UKER_A, 'Small', self::POSISI, 30 * 1_000_000);
        $this->recovery(self::CABANG_A, self::UKER_A, 'Medium', self::POSISI, 40 * 1_000_000);
        $this->recovery(self::CABANG_A, self::UKER_A, 'Consumer', self::POSISI, 10 * 1_000_000);

        // YoY 2025 — taksonomi lama ("SME" utuh).
        $this->recovery(self::CABANG_A, self::UKER_A, 'Micro', '2025-03-10', 40 * 1_000_000);
        $this->recovery(self::CABANG_A, self::UKER_A, 'SME', '2025-03-10', 50 * 1_000_000);
        $this->recovery(self::CABANG_A, self::UKER_A, 'Consumer', '2025-03-10', 5 * 1_000_000);

        // Cabang lain, hanya pada tanggal posisi — untuk menguji scoping.
        $this->recovery(self::CABANG_B, self::UKER_B, 'Micro', self::POSISI, 500 * 1_000_000);

        // RKA Maret 2026 untuk cabang A.
        $this->rka('Micro', 125 * 1_000_000);
        $this->rka('Small', 40 * 1_000_000);
        $this->rka('Medium', 30 * 1_000_000);
    }

    private function rka(string $segmen, float $target): void
    {
        RkaRecovery::factory()->create([
            'cabang_id' => self::CABANG_A,
            'uker_id' => self::UKER_A,
            'segmen' => $segmen,
            'tahun' => 2026,
            'bulan' => 3,
            'target' => $target,
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
    private function snapshot(User $user, array $filter = []): array
    {
        return $this->actingAs($user)
            ->getJson('/api/recovery/snapshot?'.http_build_query($filter + ['tanggal' => self::POSISI]))
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
        $this->actingAs($this->admin())->get('/dashboard/recovery')->assertOk();
    }

    // --- Normalisasi segmen (inti domain) --------------------------------

    public function test_kartu_sme_menjumlahkan_small_dan_medium_saat_baca(): void
    {
        $snapshot = $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]);

        // Small 30 + Medium 40 dilipat jadi kanonik SME = 70 jt.
        $this->assertSame(70.0, $this->kartu($snapshot, 'SME')['nilai']);
        $this->assertSame(100.0, $this->kartu($snapshot, 'Micro')['nilai']);
        $this->assertSame(10.0, $this->kartu($snapshot, 'Consumer')['nilai']);
    }

    public function test_total_menjumlahkan_semua_segmen_kanonik(): void
    {
        $snapshot = $this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]);

        // Micro 100 + SME 70 + Consumer 10 = 180.
        $this->assertSame(180.0, $this->kartu($snapshot, 'total')['nilai']);
    }

    public function test_yoy_apple_to_apple_meski_taksonomi_berubah(): void
    {
        $sme = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'SME');

        // SME 2026 (Small+Medium = 70) dibanding SME 2025 (mentah "SME" = 50).
        // Tanpa normalisasi saat baca, pembanding 2025 tidak akan cocok.
        $this->assertSame(20.0, $sme['delta']['yoy']['nilai']);
    }

    public function test_pencapaian_sme_menormalkan_target_juga(): void
    {
        $sme = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'SME');

        // Target SME = Small 40 + Medium 30 = 70 -> pencapaian 70/70 = 100%.
        $this->assertSame(70.0, $sme['target']);
        $this->assertSame(100.0, $sme['pencapaian']);
        $this->assertSame(0.0, $sme['gap']);
    }

    public function test_pencapaian_dan_gap_micro_terhadap_rka(): void
    {
        $micro = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'Micro');

        $this->assertSame(125.0, $micro['target']);
        $this->assertSame(80.0, $micro['pencapaian']);   // 100 / 125
        $this->assertSame(-25.0, $micro['gap']);         // 100 - 125
    }

    public function test_tanpa_target_pencapaian_null_bukan_nol(): void
    {
        // Consumer tidak punya baris RKA.
        $consumer = $this->kartu($this->snapshot($this->admin(), ['cabang_id' => self::CABANG_A]), 'Consumer');

        $this->assertNull($consumer['pencapaian']);
    }

    public function test_segmen_disimpan_mentah_di_database(): void
    {
        // Normalisasi hanya saat baca; DB tetap menyimpan taksonomi mentah.
        $this->assertEqualsCanonicalizing(
            ['Micro', 'Small', 'Medium', 'Consumer', 'SME'],
            Recovery::query()->distinct()->pluck('segmen')->all(),
        );
    }

    // --- Chart & tabel ---------------------------------------------------

    public function test_chart_mengelompokkan_tren_harian_per_bulan(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/recovery/chart?'.http_build_query(['tanggal' => self::POSISI, 'cabang_id' => self::CABANG_A]))
            ->assertOk()
            ->json();

        $this->assertSame(2026, $data['tahun']);

        // Dalam 2026 s/d 10 Mar cabang A hanya punya data di 10 Mar.
        $maret = collect($data['seri'])->firstWhere('bulan', 3);
        $this->assertSame(180.0, $this->angka(collect($maret['titik'])->firstWhere('hari', 10)['nilai']));
    }

    public function test_branch_pencapaian_mengelompokkan_per_cabang(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/recovery/branch-pencapaian?tanggal='.self::POSISI)
            ->assertOk()
            ->json();

        $this->assertSame('cabang', $data['grouping']);
        $this->assertEqualsCanonicalizing(
            [self::CABANG_A, self::CABANG_B],
            collect($data['baris'])->pluck('id')->all(),
        );

        // Cabang A total = 180 (semua segmen dijumlahkan).
        $a = collect($data['baris'])->firstWhere('id', self::CABANG_A);
        $this->assertSame(180.0, $this->angka($a['nilai']));
    }

    public function test_branch_pencapaian_berpindah_ke_per_uker_saat_drill_down(): void
    {
        $data = $this->actingAs($this->admin())
            ->getJson('/api/recovery/branch-pencapaian?'.http_build_query([
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

        $snapshot = $this->snapshot($bo, ['cabang_id' => self::CABANG_B]);

        $this->assertSame(180.0, $this->kartu($snapshot, 'total')['nilai']);
        $this->assertNotSame(500.0, $this->kartu($snapshot, 'total')['nilai']);
    }

    public function test_user_bo_tidak_bisa_melihat_total_seluruh_region(): void
    {
        $bo = User::factory()->bo(self::CABANG_A)->create();

        // Admin tanpa filter: 180 (A) + 500 (B) = 680. BO tetap 180.
        $this->assertSame(680.0, $this->kartu($this->snapshot($this->admin()), 'total')['nilai']);
        $this->assertSame(180.0, $this->kartu($this->snapshot($bo), 'total')['nilai']);
    }

    public function test_endpoint_ranking_kosong_untuk_user_level_uker(): void
    {
        $user = User::factory()->uker(self::CABANG_A, self::UKER_A)->create();

        $this->actingAs($user)
            ->getJson('/api/recovery/branch-pencapaian?tanggal='.self::POSISI)
            ->assertOk()
            ->assertJson(['baris' => []]);
    }

    public function test_rollup_855_tidak_ikut_terhitung(): void
    {
        $this->recovery(855, 855, 'Micro', self::POSISI, 9_000 * 1_000_000);

        // Total tetap 680 seperti sebelum baris 855 ditambahkan.
        $this->assertSame(680.0, $this->kartu($this->snapshot($this->admin()), 'total')['nilai']);
    }

    public function test_semua_endpoint_recovery_menolak_tamu(): void
    {
        foreach (['snapshot', 'chart', 'branch-pencapaian', 'filter-options'] as $endpoint) {
            $this->getJson("/api/recovery/{$endpoint}")->assertUnauthorized();
        }
    }
}
