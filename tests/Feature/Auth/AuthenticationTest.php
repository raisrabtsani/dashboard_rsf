<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_screen_can_be_rendered(): void
    {
        $this->get('/login')->assertOk();
    }

    public function test_users_can_authenticate_using_their_username(): void
    {
        $user = User::factory()->create(['username' => 'PKU1234']);

        $response = $this->post('/login', [
            'username' => 'PKU1234',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
        $response->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        User::factory()->create(['username' => 'PKU1234']);

        $this->post('/login', [
            'username' => 'PKU1234',
            'password' => 'password-salah',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_email_tidak_bisa_dipakai_untuk_login(): void
    {
        User::factory()->create([
            'username' => 'PKU1234',
            'email' => 'orang@contoh.test',
        ]);

        $this->post('/login', [
            'email' => 'orang@contoh.test',
            'password' => 'password',
        ])->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_users_can_logout(): void
    {
        $response = $this->actingAs(User::factory()->create())->post('/logout');

        $this->assertGuest();
        $response->assertRedirect('/');
    }

    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/dashboard')->assertRedirect('/login');
    }
}
