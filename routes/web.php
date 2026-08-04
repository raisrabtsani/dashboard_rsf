<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/login')->name('home');

/*
 * Semua halaman & endpoint dashboard WAJIB lewat middleware 'scope'.
 *
 * 'scope' (EnforceUserScope) menulis ulang area_id/cabang_id/uker_id di Request
 * sebelum controller membacanya. Menambahkan route dashboard di luar grup ini
 * = membocorkan data lintas kantor. ScopeEnforcementTest mengunci hal ini.
 */
Route::middleware(['auth', 'scope'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    Route::prefix('api')->name('api.')->group(function () {
        Route::get('scope', [DashboardController::class, 'scope'])->name('scope');
    });
});

Route::middleware('auth')->group(function () {
    // Halaman Profil hanya berisi form ganti password — update profil & hapus
    // akun sendiri sengaja tidak ada.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
});

Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');
});

require __DIR__.'/auth.php';
