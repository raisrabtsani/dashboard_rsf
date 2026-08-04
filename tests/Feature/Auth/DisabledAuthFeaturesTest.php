<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Mengunci alur Breeze yang sengaja dimatikan.
 *
 * Registrasi publik, reset password via email, verifikasi email, konfirmasi
 * password, update profil, dan hapus akun sendiri TIDAK boleh hidup kembali —
 * akun dikelola admin. Test ini gagal kalau ada yang menghidupkannya lagi.
 */
class DisabledAuthFeaturesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return list<array{string, string}>
     */
    public static function routeMati(): array
    {
        return [
            'registrasi (form)' => ['get', '/register'],
            'registrasi (submit)' => ['post', '/register'],
            'lupa password (form)' => ['get', '/forgot-password'],
            'lupa password (kirim email)' => ['post', '/forgot-password'],
            'reset password (form)' => ['get', '/reset-password/token-palsu'],
            'reset password (submit)' => ['post', '/reset-password'],
            'verifikasi email (notice)' => ['get', '/verify-email'],
            'verifikasi email (verify)' => ['get', '/verify-email/1/hash-palsu'],
            'verifikasi email (kirim ulang)' => ['post', '/email/verification-notification'],
            'konfirmasi password (form)' => ['get', '/confirm-password'],
            'konfirmasi password (submit)' => ['post', '/confirm-password'],
        ];
    }

    /**
     * @return list<array{string}>
     */
    public static function namaRouteMati(): array
    {
        return [
            ['register'],
            ['password.request'],
            ['password.email'],
            ['password.reset'],
            ['password.store'],
            ['verification.notice'],
            ['verification.verify'],
            ['verification.send'],
            ['password.confirm'],
            ['profile.update'],
            ['profile.destroy'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('routeMati')]
    public function test_route_yang_dimatikan_membalas_404(string $method, string $uri): void
    {
        // Diuji sebagai user yang SUDAH login: kalau route-nya masih ada, ia akan
        // membalas 200/302, bukan 404. Jadi 404 di sini benar-benar berarti
        // route-nya tidak terdaftar, bukan sekadar tertahan middleware guest.
        $this->actingAs(User::factory()->create())
            ->{$method}($uri)
            ->assertNotFound();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('namaRouteMati')]
    public function test_nama_route_yang_dimatikan_tidak_terdaftar(string $nama): void
    {
        $this->assertFalse(
            Route::has($nama),
            "Route '{$nama}' seharusnya sudah dihapus, tapi masih terdaftar.",
        );
    }

    public function test_profil_tidak_bisa_diupdate_atau_dihapus(): void
    {
        $user = User::factory()->create();

        // GET /profile ada (form ganti password), tapi verb lain tidak terdaftar.
        $this->actingAs($user)->patch('/profile')->assertMethodNotAllowed();
        $this->actingAs($user)->delete('/profile')->assertMethodNotAllowed();

        $this->assertModelExists($user);
    }

    public function test_alur_yang_disisakan_tetap_hidup(): void
    {
        $this->assertTrue(Route::has('login'));
        $this->assertTrue(Route::has('logout'));
        $this->assertTrue(Route::has('password.update'));
        $this->assertTrue(Route::has('profile.edit'));
    }
}
