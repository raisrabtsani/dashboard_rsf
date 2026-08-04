<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_profil_bisa_dibuka(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/profile')
            ->assertOk();
    }

    public function test_tamu_tidak_bisa_membuka_profil(): void
    {
        $this->get('/profile')->assertRedirect('/login');
    }

    // Update profil & hapus akun sendiri sudah dimatikan —
    // dikunci di Auth\DisabledAuthFeaturesTest.
}
