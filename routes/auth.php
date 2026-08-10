<?php

use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\SessionExpiredController;
use Illuminate\Support\Facades\Route;

/*
 * Hanya tiga alur auth yang hidup: login, logout, ganti password.
 *
 * Registrasi publik, reset password via email, verifikasi email, dan konfirmasi
 * password SENGAJA TIDAK didaftarkan — akun dibuat admin lewat /admin/users.
 * Jangan menghidupkan kembali route-route itu; Auth\DisabledAuthFeaturesTest
 * mengunci supaya semuanya tetap 404 dan nama route-nya tidak ada.
 */

// Endpoint GET khusus halaman 419. Endpoint ini sengaja berada di luar
// middleware guest/auth agar tetap dapat digunakan saat status session tidak jelas.
Route::get('session-expired/login', SessionExpiredController::class)
    ->name('session.expired.login');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthenticatedSessionController::class, 'create'])
        ->name('login');

    Route::post('login', [AuthenticatedSessionController::class, 'store']);
});

Route::middleware('auth')->group(function () {
    Route::put('password', [PasswordController::class, 'update'])->name('password.update');

    Route::post('logout', [AuthenticatedSessionController::class, 'destroy'])
        ->name('logout');
});
