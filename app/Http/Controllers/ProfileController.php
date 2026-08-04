<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Halaman Profil — hanya form ganti password.
     *
     * Update profil dan hapus akun sendiri sengaja dihapus: identitas user
     * (username, tipe, kantor) dikelola admin lewat /admin/users.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('Profile/Edit', [
            'status' => session('status'),
        ]);
    }
}
