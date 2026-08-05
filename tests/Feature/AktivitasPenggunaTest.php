<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserActivityService;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Admin > Aktivitas Pengguna: online sekarang (dari tabel sessions) + kolom
 * terakhir aktif per user (users.last_seen_at via middleware TrackUserActivity).
 */
class AktivitasPenggunaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
    }

    private function sesi(User $user, int $detikLalu): void
    {
        DB::table('sessions')->insert([
            'id' => 'sess-'.$user->id.'-'.$detikLalu,
            'user_id' => $user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'phpunit',
            'payload' => 'x',
            'last_activity' => Carbon::now()->subSeconds($detikLalu)->getTimestamp(),
        ]);
    }

    public function test_halaman_hanya_untuk_admin(): void
    {
        // Tamu lebih dulu — actingAs berikutnya bertahan dalam satu test method.
        $this->get('/admin/activity')->assertRedirect('/login');

        // RO berlevel LEVEL_ALL untuk data, tapi BUKAN admin — tetap ditolak.
        $this->actingAs(User::factory()->ro()->create())->get('/admin/activity')->assertForbidden();

        $this->actingAs(User::factory()->admin()->create())->get('/admin/activity')->assertOk();
    }

    public function test_online_sekarang_dari_sessions_dalam_ambang_5_menit(): void
    {
        $aktif = User::factory()->create(['username' => 'aktif']);
        $lama = User::factory()->create(['username' => 'lama']);

        $this->sesi($aktif, 60);              // 1 menit lalu -> online
        $this->sesi($lama, 10 * 60);          // 10 menit lalu -> tidak online

        $data = $this->actingAs(User::factory()->admin()->create())
            ->getJson('/admin/activity/data')
            ->assertOk()
            ->json();

        $onlineIds = collect($data['online'])->pluck('id')->all();

        $this->assertContains($aktif->id, $onlineIds);
        $this->assertNotContains($lama->id, $onlineIds);
    }

    public function test_beberapa_sesi_satu_user_dihitung_satu_dengan_jumlah_sesi(): void
    {
        $user = User::factory()->create(['username' => 'dua-perangkat']);
        $this->sesi($user, 30);
        $this->sesi($user, 90);

        $data = $this->actingAs(User::factory()->admin()->create())
            ->getJson('/admin/activity/data')
            ->assertOk()
            ->json();

        $baris = collect($data['online'])->firstWhere('id', $user->id);

        $this->assertNotNull($baris);
        $this->assertSame(2, $baris['sesi']);
    }

    public function test_middleware_mencatat_last_seen_at_user(): void
    {
        $user = User::factory()->bo(159)->create(['last_seen_at' => null]);

        $this->assertNull($user->fresh()->last_seen_at);

        // Satu request terautentikasi memicu pencatatan (throttle 60s: null -> tulis).
        $this->actingAs($user)->getJson('/api/scope')->assertOk();

        $this->assertNotNull($user->fresh()->last_seen_at);
    }

    public function test_last_seen_at_tidak_mengubah_updated_at(): void
    {
        // Aktivitas bukan perubahan data akun — updated_at tidak boleh ikut bergerak.
        $user = User::factory()->admin()->create([
            'last_seen_at' => null,
            'updated_at' => Carbon::now()->subDay(),
        ]);
        $updatedSebelum = $user->fresh()->updated_at;

        $this->actingAs($user)->getJson('/api/scope')->assertOk();

        $segar = $user->fresh();
        $this->assertNotNull($segar->last_seen_at);
        $this->assertEquals($updatedSebelum, $segar->updated_at);
    }

    public function test_daftar_menandai_user_online(): void
    {
        $user = User::factory()->create(['username' => 'sedang-online']);
        $this->sesi($user, 45);

        $data = $this->actingAs(User::factory()->admin()->create())
            ->getJson('/admin/activity/data')
            ->assertOk()
            ->json();

        $baris = collect($data['pengguna'])->firstWhere('id', $user->id);

        $this->assertNotNull($baris);
        $this->assertTrue($baris['online']);
    }

    public function test_ambang_online_lima_menit(): void
    {
        $this->assertSame(5, UserActivityService::AMBANG_ONLINE_MENIT);
    }
}
