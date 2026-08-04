<?php

namespace Tests\Feature;

use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // users.cabang_id / uker_id punya foreign key ke master organisasi.
        $this->seed(MasterSeeder::class);
    }

    public function test_admin_boleh_masuk_area_admin(): void
    {
        $this->actingAs(User::factory()->admin()->create())
            ->get('/admin')
            ->assertOk();
    }

    public function test_user_ro_ditolak_walau_boleh_lihat_semua_data(): void
    {
        $ro = User::factory()->ro()->create();

        // RO berlevel LEVEL_ALL, tapi area Admin dijaga role — bukan access_level.
        $this->assertSame(User::LEVEL_ALL, $ro->access_level);
        $this->actingAs($ro)->get('/admin')->assertForbidden();
    }

    public function test_user_bo_dan_uker_ditolak(): void
    {
        $this->actingAs(User::factory()->bo(159)->create())->get('/admin')->assertForbidden();
        $this->actingAs(User::factory()->uker(159, 5438)->create())->get('/admin')->assertForbidden();
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get('/admin')->assertRedirect('/login');
    }
}
