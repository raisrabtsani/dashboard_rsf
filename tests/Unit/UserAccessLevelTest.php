<?php

namespace Tests\Unit;

use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class UserAccessLevelTest extends TestCase
{
    /**
     * @return list<array{string, string|null, string}>
     */
    public static function peta(): array
    {
        return [
            'admin selalu LEVEL_ALL' => [User::ROLE_ADMIN, null, User::LEVEL_ALL],
            'admin walau tipe BO' => [User::ROLE_ADMIN, User::TIPE_BO, User::LEVEL_ALL],
            'admin walau tipe UNIT' => [User::ROLE_ADMIN, User::TIPE_UNIT, User::LEVEL_ALL],
            'RO' => [User::ROLE_USER, User::TIPE_RO, User::LEVEL_ALL],
            'BO' => [User::ROLE_USER, User::TIPE_BO, User::LEVEL_CABANG],
            'SBO' => [User::ROLE_USER, User::TIPE_SBO, User::LEVEL_UKER],
            'UNIT' => [User::ROLE_USER, User::TIPE_UNIT, User::LEVEL_UKER],
            'KK' => [User::ROLE_USER, User::TIPE_KK, User::LEVEL_UKER],
        ];
    }

    #[DataProvider('peta')]
    public function test_memetakan_role_dan_tipe_ke_access_level(string $role, ?string $tipe, string $harapan): void
    {
        $user = new User(['role' => $role, 'tipe' => $tipe]);

        $this->assertSame($harapan, $user->access_level);
    }

    public function test_tipe_kosong_atau_asing_jatuh_ke_level_paling_sempit(): void
    {
        // Default-nya WAJIB level tersempit: tipe yang belum diisi atau tidak
        // dikenali tidak boleh diam-diam berujung bisa melihat semua data.
        $this->assertSame(User::LEVEL_UKER, (new User(['role' => User::ROLE_USER]))->access_level);
        $this->assertSame(User::LEVEL_UKER, (new User(['role' => User::ROLE_USER, 'tipe' => 'ENTAH']))->access_level);
    }

    public function test_access_level_ikut_terserialisasi(): void
    {
        $user = new User(['role' => User::ROLE_USER, 'tipe' => User::TIPE_BO]);

        $this->assertArrayHasKey('access_level', $user->toArray());
        $this->assertSame(User::LEVEL_CABANG, $user->toArray()['access_level']);
    }

    public function test_is_admin_hanya_untuk_role_admin(): void
    {
        $this->assertTrue((new User(['role' => User::ROLE_ADMIN]))->isAdmin());
        // RO boleh lihat semua data, tapi BUKAN admin.
        $this->assertFalse((new User(['role' => User::ROLE_USER, 'tipe' => User::TIPE_RO]))->isAdmin());
    }
}
