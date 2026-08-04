<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\UserCsvImportService;
use App\Support\Csv;
use Database\Seeders\MasterSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UserSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // users.cabang_id & uker_id punya FK ke master organisasi.
        $this->seed(MasterSeeder::class);
        $this->seed(UserSeeder::class);
    }

    /**
     * Jumlah baris data di user.csv, dibaca dari file — bukan angka hardcode,
     * supaya test ikut benar saat file diperbarui.
     */
    private function jumlahBarisCsv(): int
    {
        return Csv::baca(database_path('seeders/data/user.csv'))->count();
    }

    public function test_jumlah_user_sama_dengan_jumlah_baris_csv(): void
    {
        $this->assertSame($this->jumlahBarisCsv(), User::query()->count());
    }

    public function test_username_dan_nama_diambil_dari_kolom_user_dan_nama(): void
    {
        $this->assertDatabaseHas('users', [
            'username' => 'RSF',
            'name' => 'REGIONAL STRATEGY AND FINANCE',
        ]);
    }

    public function test_role_admin_hanya_untuk_kolom_role_admin(): void
    {
        $admin = User::query()->where('username', 'RSF')->sole();

        $this->assertSame(User::ROLE_ADMIN, $admin->role);
        $this->assertSame(User::LEVEL_ALL, $admin->access_level);

        // Sisanya user biasa.
        $this->assertSame(1, User::query()->where('role', User::ROLE_ADMIN)->count());
        $this->assertSame(
            $this->jumlahBarisCsv() - 1,
            User::query()->where('role', User::ROLE_USER)->count(),
        );
    }

    public function test_type_uker_dinormalkan(): void
    {
        // "Unit" -> UNIT, "Kantor Kas" -> KK, sisanya apa adanya.
        $this->assertSame([
            User::TIPE_BO => 22,
            User::TIPE_KK => 20,
            User::TIPE_RO => 26,
            User::TIPE_SBO => 20,
            User::TIPE_UNIT => 171,
        ], User::query()
            ->selectRaw('tipe, count(*) as jumlah')
            ->groupBy('tipe')
            ->orderBy('tipe')
            ->pluck('jumlah', 'tipe')
            ->all());

        // Tidak ada tipe mentah yang lolos tanpa normalisasi.
        $this->assertSame(0, User::query()->whereIn('tipe', ['Unit', 'Kantor Kas'])->count());
    }

    public function test_cabang_dan_uker_diambil_langsung_dari_csv(): void
    {
        $ro = User::query()->where('username', 'RSF')->sole();
        $this->assertSame(855, $ro->cabang_id);
        $this->assertSame(855, $ro->uker_id);

        $bo = User::query()->where('username', 'PKU1190')->sole();
        $this->assertSame(User::TIPE_BO, $bo->tipe);
        $this->assertSame(1190, $bo->cabang_id);
        $this->assertSame(1190, $bo->uker_id);

        $unit = User::query()->where('username', 'PKU7494')->sole();
        $this->assertSame(User::TIPE_UNIT, $unit->tipe);
        $this->assertSame(1190, $unit->cabang_id);
        $this->assertSame(7494, $unit->uker_id);
    }

    public function test_semua_user_menempel_ke_master_yang_ada(): void
    {
        $this->assertSame(0, User::query()->whereNull('cabang_id')->count());
        $this->assertSame(0, User::query()->whereNull('uker_id')->count());
    }

    public function test_password_dari_csv_bisa_dipakai_login(): void
    {
        $user = User::query()->where('username', 'PKU1190')->sole();

        $this->assertTrue(Hash::check('RSF54321', $user->password));
    }

    public function test_hash_dihitung_sekali_per_password_unik(): void
    {
        // Semua baris memakai password yang sama. Kalau hash dihitung per baris,
        // tiap user akan punya hash bcrypt berbeda (salt acak). Hash yang identik
        // membuktikan bcrypt hanya dipanggil sekali.
        $hashUnik = User::query()->distinct()->pluck('password');

        $this->assertCount(1, $hashUnik);
    }

    public function test_user_bo_ter_scope_ke_cabangnya_sendiri(): void
    {
        // BO Siak (cabang 1190) meminta data BO Dumai (cabang 159).
        $bo = User::query()->where('username', 'PKU1190')->sole();

        $this->actingAs($bo)
            ->getJson('/api/scope?'.http_build_query([
                'area_id' => 1,
                'cabang_id' => 159,
                'uker_id' => 5438,
            ]))
            ->assertOk()
            ->assertJson([
                'access_level' => User::LEVEL_CABANG,
                'cabang_id' => 1190,
            ])
            ->assertJsonPath('cabang_id', fn ($id) => $id !== 159);
    }

    public function test_user_unit_ter_scope_ke_ukernya_sendiri(): void
    {
        $unit = User::query()->where('username', 'PKU7494')->sole();

        $this->actingAs($unit)
            ->getJson('/api/scope?cabang_id=159&uker_id=5438')
            ->assertOk()
            ->assertJson([
                'access_level' => User::LEVEL_UKER,
                'cabang_id' => 1190,
                'uker_id' => 7494,
            ]);
    }

    public function test_seeder_menimpa_bukan_menggandakan(): void
    {
        $sebelum = User::query()->count();

        $this->seed(UserSeeder::class);

        $this->assertSame($sebelum, User::query()->count());
    }

    public function test_sync_tidak_mengubah_password_user_lama(): void
    {
        $service = app(UserCsvImportService::class);

        $bo = User::query()->where('username', 'PKU1190')->sole();
        $bo->forceFill(['password' => Hash::make('password-baru-pilihan-user')])->save();
        $passwordSendiri = $bo->fresh()->password;

        $hasil = $service->sync();

        $this->assertSame(0, $hasil['baru']);
        $this->assertSame($this->jumlahBarisCsv(), $hasil['diperbarui']);

        // Password yang sudah diganti user TIDAK boleh dikembalikan ke default.
        $this->assertSame($passwordSendiri, $bo->fresh()->password);
        $this->assertTrue(Hash::check('password-baru-pilihan-user', $bo->fresh()->password));
    }

    public function test_sync_menambah_user_baru_tanpa_menghapus_yang_lain(): void
    {
        $service = app(UserCsvImportService::class);

        $manual = User::factory()->create(['username' => 'MANUAL01']);

        $hasil = $service->sync();

        // User di luar CSV tetap hidup — sync tidak truncate.
        $this->assertModelExists($manual);
        $this->assertSame($this->jumlahBarisCsv() + 1, User::query()->count());
        $this->assertSame(0, $hasil['baru']);
    }

    public function test_sync_memperbarui_identitas_kantor_dari_csv(): void
    {
        $service = app(UserCsvImportService::class);

        User::query()->where('username', 'PKU7494')->update([
            'name' => 'Nama Basi',
            'cabang_id' => 159,
            'uker_id' => 5438,
        ]);

        $service->sync();

        $this->assertDatabaseHas('users', [
            'username' => 'PKU7494',
            'name' => 'Unit Bunga Raya Siak',
            'cabang_id' => 1190,
            'uker_id' => 7494,
        ]);
    }
}
