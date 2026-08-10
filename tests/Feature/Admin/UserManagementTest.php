<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Database\Seeders\MasterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private const CABANG = 159;

    private const UKER = 5438;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(MasterSeeder::class);
    }

    private function admin(): User
    {
        return User::factory()->admin()->create();
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(array $ganti = []): array
    {
        return array_merge([
            'username' => 'PKU9999',
            'name' => 'Unit Uji Coba',
            'role' => User::ROLE_USER,
            'tipe' => User::TIPE_UNIT,
            'cabang_id' => self::CABANG,
            'uker_id' => self::UKER,
            'password' => 'rahasia123',
        ], $ganti);
    }

    // --- CRUD ------------------------------------------------------------

    public function test_admin_bisa_membuat_user(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/admin/users', $this->payload())
            ->assertCreated();

        $user = User::query()->where('username', 'PKU9999')->sole();

        $this->assertSame('Unit Uji Coba', $user->name);
        $this->assertSame(User::TIPE_UNIT, $user->tipe);
        $this->assertSame(self::UKER, $user->uker_id);
        $this->assertSame(User::LEVEL_UKER, $user->access_level);
        $this->assertFalse($user->is_locked);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
    }

    public function test_username_wajib_unik(): void
    {
        User::factory()->create(['username' => 'PKU9999']);

        $this->actingAs($this->admin())
            ->postJson('/admin/users', $this->payload())
            ->assertJsonValidationErrors('username');
    }

    public function test_admin_bisa_mengubah_identitas_dan_kantor_user(): void
    {
        $user = User::factory()->create(['username' => 'PKU1111']);

        $this->actingAs($this->admin())
            ->putJson("/admin/users/{$user->id}", $this->payload([
                'username' => 'PKU1111',
                'name' => 'Nama Baru',
                'tipe' => User::TIPE_BO,
                'uker_id' => self::CABANG,
                'password' => null,
            ]))
            ->assertOk();

        $user->refresh();

        $this->assertSame('Nama Baru', $user->name);
        $this->assertSame(User::TIPE_BO, $user->tipe);
        $this->assertSame(User::LEVEL_CABANG, $user->access_level);
    }

    public function test_password_kosong_saat_edit_berarti_tidak_diubah(): void
    {
        $user = User::factory()->create(['username' => 'PKU1111']);
        $passwordLama = $user->password;

        $this->actingAs($this->admin())
            ->putJson("/admin/users/{$user->id}", $this->payload([
                'username' => 'PKU1111',
                'password' => '',
            ]))
            ->assertOk();

        $this->assertSame($passwordLama, $user->fresh()->password);
    }

    public function test_password_diisi_saat_edit_berarti_diganti(): void
    {
        $user = User::factory()->create(['username' => 'PKU1111']);

        $this->actingAs($this->admin())
            ->putJson("/admin/users/{$user->id}", $this->payload([
                'username' => 'PKU1111',
                'password' => 'password-baru-123',
            ]))
            ->assertOk();

        $this->assertTrue(Hash::check('password-baru-123', $user->fresh()->password));
    }

    public function test_admin_bisa_menghapus_user_tapi_tidak_dirinya_sendiri(): void
    {
        $admin = $this->admin();
        $lain = User::factory()->create();

        $this->actingAs($admin)->deleteJson("/admin/users/{$lain->id}")->assertOk();
        $this->assertModelMissing($lain);

        $this->actingAs($admin)->deleteJson("/admin/users/{$admin->id}")->assertStatus(422);
        $this->assertModelExists($admin);
    }

    // --- Kunci / buka akun ------------------------------------------------

    public function test_toggle_lock_mengunci_dan_membuka_akun(): void
    {
        $user = User::factory()->create();

        $this->actingAs($this->admin())
            ->patchJson("/admin/users/{$user->id}/toggle-lock")
            ->assertOk()
            ->assertJson(['is_locked' => true]);

        $this->assertTrue($user->fresh()->is_locked);

        $this->actingAs($this->admin())
            ->patchJson("/admin/users/{$user->id}/toggle-lock")
            ->assertOk()
            ->assertJson(['is_locked' => false]);

        $this->assertFalse($user->fresh()->is_locked);
    }

    public function test_admin_tidak_bisa_mengunci_dirinya_sendiri(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->patchJson("/admin/users/{$admin->id}/toggle-lock")
            ->assertStatus(422);

        $this->assertFalse($admin->fresh()->is_locked);
    }

    public function test_user_terkunci_ditolak_login_walau_kredensial_benar(): void
    {
        User::factory()->create([
            'username' => 'PKU1111',
            'is_locked' => true,
        ]);

        // 'password' adalah password bawaan factory — kredensialnya BENAR.
        $this->post('/login', ['username' => 'PKU1111', 'password' => 'password'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_user_yang_dibuka_kembali_bisa_login_lagi(): void
    {
        $user = User::factory()->create(['username' => 'PKU1111', 'is_locked' => true]);

        $this->post('/login', ['username' => 'PKU1111', 'password' => 'password'])->assertSessionHasErrors();
        $this->assertGuest();

        $user->update(['is_locked' => false]);

        $this->post('/login', ['username' => 'PKU1111', 'password' => 'password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_akun_terkunci_dengan_password_salah_tetap_ditolak(): void
    {
        User::factory()->create(['username' => 'PKU1111', 'is_locked' => true]);

        $this->post('/login', ['username' => 'PKU1111', 'password' => 'salah'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    // --- Data & opsi ------------------------------------------------------

    public function test_daftar_user_menyertakan_nama_kantor_dan_access_level(): void
    {
        User::factory()->bo(self::CABANG)->create(['username' => 'PKU0159', 'name' => 'BO Dumai']);

        $data = $this->actingAs($this->admin())
            ->getJson('/admin/users/data?cari=PKU0159')
            ->assertOk()
            ->json();

        $baris = collect($data['users'])->firstWhere('username', 'PKU0159');

        $this->assertSame('BO Dumai', $baris['cabang']);
        $this->assertSame(User::LEVEL_CABANG, $baris['access_level']);
        $this->assertFalse($baris['is_locked']);
    }

    public function test_statistik_menghitung_admin_dan_akun_terkunci(): void
    {
        $this->admin();
        User::factory()->count(3)->create();
        User::factory()->create(['is_locked' => true]);

        $statistik = $this->actingAs($this->admin())
            ->getJson('/admin/users/data')
            ->assertOk()
            ->json('statistik');

        $this->assertSame(6, $statistik['total']);   // 2 admin + 3 + 1
        $this->assertSame(2, $statistik['admin']);
        $this->assertSame(1, $statistik['terkunci']);
    }

    public function test_dropdown_uker_mengikuti_cabang(): void
    {
        $uker = $this->actingAs($this->admin())
            ->getJson('/admin/users/uker/'.self::CABANG)
            ->assertOk()
            ->json();

        $this->assertNotEmpty($uker);
        $this->assertContains(self::UKER, collect($uker)->pluck('id')->all());
    }

    public function test_non_admin_ditolak_di_manajemen_user(): void
    {
        $ro = User::factory()->ro()->create();

        $this->actingAs($ro)->get('/admin/users')->assertForbidden();
        $this->actingAs($ro)->getJson('/admin/users/data')->assertForbidden();
        $this->actingAs($ro)->postJson('/admin/users', $this->payload())->assertForbidden();

        $korban = User::factory()->create();
        $this->actingAs($ro)->patchJson("/admin/users/{$korban->id}/toggle-lock")->assertForbidden();
        $this->assertFalse($korban->fresh()->is_locked);
    }

    // --- Import massal ---------------------------------------------------

    public function test_admin_bisa_import_user_dari_format_yang_diumumkan(): void
    {
        $csv = implode("\n", [
            'id_region,id_cabang,id_uker,User,Nama,Type Uker,Role,Password',
            '317,319,842,USRIMPORT,User Hasil Import,UNIT,User,rahasia123',
        ]);

        $this->actingAs($this->admin())
            ->post('/admin/users/upload', [
                'berkas' => UploadedFile::fake()->createWithContent('user.csv', $csv),
            ])
            ->assertOk()
            ->assertJsonPath('hasil.baru', 1);

        $user = User::query()->where('username', 'USRIMPORT')->sole();
        $this->assertSame(319, $user->cabang_id);
        $this->assertSame(842, $user->uker_id);
        $this->assertTrue(Hash::check('rahasia123', $user->password));
    }

    public function test_import_memvalidasi_relasi_kantor_dan_tidak_menimpa_password_lama(): void
    {
        $lama = User::factory()->create([
            'username' => 'USRLAMA',
            'password' => Hash::make('password-pilihan-user'),
        ]);

        $csvValid = implode("\n", [
            'id_region,id_cabang,id_uker,User,Nama,Type Uker,Role,Password',
            '317,319,842,USRLAMA,Nama Diperbarui,UNIT,User,password-dari-file',
        ]);

        $this->actingAs($this->admin())
            ->post('/admin/users/upload', [
                'berkas' => UploadedFile::fake()->createWithContent('user.csv', $csvValid),
            ])
            ->assertOk()
            ->assertJsonPath('hasil.diperbarui', 1);

        $this->assertSame('Nama Diperbarui', $lama->fresh()->name);
        $this->assertTrue(Hash::check('password-pilihan-user', $lama->fresh()->password));

        $csvSalah = implode("\n", [
            'id_region,id_cabang,id_uker,User,Nama,Type Uker,Role,Password',
            '317,319,999999,USRSALAH,Relasi Salah,UNIT,User,rahasia123',
        ]);

        $this->actingAs($this->admin())
            ->post('/admin/users/upload', [
                'berkas' => UploadedFile::fake()->createWithContent('user.csv', $csvSalah),
            ])
            ->assertStatus(422);

        $this->assertDatabaseMissing('users', ['username' => 'USRSALAH']);
    }
}
