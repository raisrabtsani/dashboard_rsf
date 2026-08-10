<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

final class SessionExpiredController extends Controller
{
    /**
     * Akhiri sesi lama secara eksplisit lalu arahkan ke halaman login.
     * Route ini dipakai oleh halaman 419 karena tombol logout normal
     * membutuhkan POST + token CSRF yang sudah kedaluwarsa.
     */
    public function __invoke(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()
            ->route('login')
            ->with('status', 'Sesi telah diakhiri. Silakan login kembali.');
    }
}
