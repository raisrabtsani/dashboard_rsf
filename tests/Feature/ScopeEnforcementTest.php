<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Penegakan lingkup data oleh middleware EnforceUserScope.
 *
 * Semua test di sini menyerang dari sisi klien: mengirim area_id/cabang_id/
 * uker_id milik kantor lain lewat query string, lalu memastikan backend tetap
 * membalas dengan lingkup milik user itu sendiri.
 */
class ScopeEnforcementTest extends TestCase
{
    use RefreshDatabase;

    /** BO Dumai, area 1 (Dumai). */
    private const CABANG_SENDIRI = 159;

    /** BO Batam Center, area 3 (Batam Center) — milik orang lain. */
    private const CABANG_ORANG_LAIN = 621;

    /** Unit Bukit Kapur Dumai, di bawah cabang 159. */
    private const UKER_SENDIRI = 5438;

    /** Unit Sagulung Batam Center, di bawah cabang 621. */
    private const UKER_ORANG_LAIN = 5516;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
    }

    public function test_user_bo_yang_meminta_cabang_lain_tetap_menerima_cabangnya_sendiri(): void
    {
        $bo = User::factory()->bo(self::CABANG_SENDIRI)->create();

        $response = $this->actingAs($bo)->getJson('/api/scope?'.http_build_query([
            'area_id' => 3,
            'cabang_id' => self::CABANG_ORANG_LAIN,
        ]));

        $response->assertOk()->assertJson([
            'access_level' => User::LEVEL_CABANG,
            'cabang_id' => self::CABANG_SENDIRI,
            // area ikut dipaksa ke area induk cabangnya, bukan area yang dikirim.
            'area_id' => 1,
        ]);

        $this->assertNotSame(self::CABANG_ORANG_LAIN, $response->json('cabang_id'));
    }

    public function test_user_bo_masih_boleh_drill_down_uker_di_cabangnya(): void
    {
        $bo = User::factory()->bo(self::CABANG_SENDIRI)->create();

        $this->actingAs($bo)
            ->getJson('/api/scope?uker_id='.self::UKER_SENDIRI)
            ->assertOk()
            ->assertJson([
                'cabang_id' => self::CABANG_SENDIRI,
                'uker_id' => self::UKER_SENDIRI,
            ]);
    }

    public function test_user_uker_dipaksa_ke_ukernya_sendiri(): void
    {
        $uker = User::factory()->uker(self::CABANG_SENDIRI, self::UKER_SENDIRI)->create();

        $this->actingAs($uker)->getJson('/api/scope?'.http_build_query([
            'area_id' => 3,
            'cabang_id' => self::CABANG_ORANG_LAIN,
            'uker_id' => self::UKER_ORANG_LAIN,
        ]))->assertOk()->assertJson([
            'access_level' => User::LEVEL_UKER,
            'area_id' => 1,
            'cabang_id' => self::CABANG_SENDIRI,
            'uker_id' => self::UKER_SENDIRI,
        ]);
    }

    public function test_user_ro_dan_admin_filternya_tidak_diubah(): void
    {
        foreach ([User::factory()->ro(), User::factory()->admin()] as $factory) {
            $this->actingAs($factory->create())
                ->getJson('/api/scope?'.http_build_query([
                    'area_id' => 3,
                    'cabang_id' => self::CABANG_ORANG_LAIN,
                    'uker_id' => self::UKER_ORANG_LAIN,
                ]))
                ->assertOk()
                ->assertJson([
                    'access_level' => User::LEVEL_ALL,
                    'area_id' => 3,
                    'cabang_id' => self::CABANG_ORANG_LAIN,
                    'uker_id' => self::UKER_ORANG_LAIN,
                ]);
        }
    }

    public function test_scope_juga_dipaksa_pada_parameter_body_bukan_hanya_query(): void
    {
        $bo = User::factory()->bo(self::CABANG_SENDIRI)->create();

        // Dikirim lewat body: kalau middleware hanya menyentuh query bag,
        // controller masih akan membaca nilai kiriman klien.
        $this->actingAs($bo)
            ->call('GET', '/api/scope', ['cabang_id' => self::CABANG_ORANG_LAIN])
            ->assertOk()
            ->assertJsonPath('cabang_id', self::CABANG_SENDIRI);
    }

    public function test_akun_level_cabang_tanpa_cabang_ditolak_bukan_dibiarkan_lolos(): void
    {
        // Data user cacat tidak boleh berujung "tanpa filter" — itu justru
        // membuka seluruh data.
        $rusak = User::factory()->create([
            'tipe' => User::TIPE_BO,
            'cabang_id' => null,
        ]);

        $this->actingAs($rusak)->getJson('/api/scope')->assertForbidden();
    }

    public function test_akun_level_uker_tanpa_uker_ditolak(): void
    {
        $rusak = User::factory()->create([
            'tipe' => User::TIPE_UNIT,
            'uker_id' => null,
        ]);

        $this->actingAs($rusak)->getJson('/api/scope')->assertForbidden();
    }

    public function test_semua_route_dashboard_memakai_middleware_scope(): void
    {
        $tanpaScope = collect(Route::getRoutes()->getRoutes())
            ->filter(function ($route) {
                $uri = $route->uri();

                return str_starts_with($uri, 'dashboard') || str_starts_with($uri, 'api/');
            })
            ->reject(fn ($route) => in_array('scope', $route->gatherMiddleware(), true))
            ->map(fn ($route) => $route->uri())
            ->values();

        $this->assertSame(
            [],
            $tanpaScope->all(),
            'Route dashboard/api berikut tidak memakai middleware scope: '.$tanpaScope->implode(', '),
        );
    }
}
