<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\RkaEdcController;
use App\Http\Controllers\Admin\RkaLabaController;
use App\Http\Controllers\Admin\RkaPinjamanController;
use App\Http\Controllers\Admin\RkaQrisController;
use App\Http\Controllers\Admin\RkaRecoveryController;
use App\Http\Controllers\Admin\RkaSimpananController;
use App\Http\Controllers\Admin\UploadEdcController;
use App\Http\Controllers\Admin\UploadLabaController;
use App\Http\Controllers\Admin\UploadPhController;
use App\Http\Controllers\Admin\UploadPinjamanController;
use App\Http\Controllers\Admin\UploadQrisController;
use App\Http\Controllers\Admin\UploadRecoveryController;
use App\Http\Controllers\Admin\UploadSimpananController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EdcController;
use App\Http\Controllers\LabaDashboardController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\PhNetDgDashboardController;
use App\Http\Controllers\PinjamanDashboardController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QrisController;
use App\Http\Controllers\RecoveryDashboardController;
use App\Http\Controllers\SimpananDashboardController;
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

    Route::get('/dashboard/simpanan', [SimpananDashboardController::class, 'index'])->name('simpanan');
    Route::get('/dashboard/pinjaman', [PinjamanDashboardController::class, 'index'])->name('pinjaman');
    Route::get('/dashboard/recovery', [RecoveryDashboardController::class, 'index'])->name('recovery');
    Route::get('/dashboard/recovery-ph', [PhNetDgDashboardController::class, 'index'])->name('recovery-ph');
    Route::get('/dashboard/laba', [LabaDashboardController::class, 'index'])->name('laba');
    Route::get('/dashboard/merchant', [MerchantController::class, 'index'])->name('merchant');

    Route::prefix('api')->name('api.')->group(function () {
        Route::get('scope', [DashboardController::class, 'scope'])->name('scope');

        // Pola endpoint baku tiap domain — salin bentuk ini untuk domain berikutnya.
        Route::prefix('simpanan')->name('simpanan.')->group(function () {
            Route::get('filter-options', [SimpananDashboardController::class, 'filterOptions'])->name('filter-options');
            Route::get('snapshot', [SimpananDashboardController::class, 'snapshot'])->name('snapshot');
            Route::get('chart', [SimpananDashboardController::class, 'chart'])->name('chart');
            Route::get('branch-pencapaian', [SimpananDashboardController::class, 'branchPencapaian'])->name('branch-pencapaian');
            Route::get('cabang/{areaId}', [SimpananDashboardController::class, 'cabang'])->name('cabang');
            Route::get('uker/{cabangId}', [SimpananDashboardController::class, 'uker'])->name('uker');
        });

        // Pinjaman mengikuti pola yang sama, plus dua endpoint khusus domain:
        // chart-segmen (tren per segmen) dan produk (rincian per segmen).
        Route::prefix('pinjaman')->name('pinjaman.')->group(function () {
            Route::get('filter-options', [PinjamanDashboardController::class, 'filterOptions'])->name('filter-options');
            Route::get('snapshot', [PinjamanDashboardController::class, 'snapshot'])->name('snapshot');
            Route::get('chart', [PinjamanDashboardController::class, 'chart'])->name('chart');
            Route::get('chart-segmen', [PinjamanDashboardController::class, 'chartSegmen'])->name('chart-segmen');
            Route::get('produk', [PinjamanDashboardController::class, 'produk'])->name('produk');
            Route::get('branch-pencapaian', [PinjamanDashboardController::class, 'branchPencapaian'])->name('branch-pencapaian');
            Route::get('cabang/{areaId}', [PinjamanDashboardController::class, 'cabang'])->name('cabang');
            Route::get('uker/{cabangId}', [PinjamanDashboardController::class, 'uker'])->name('uker');
        });

        /*
         * PH & Net DG: dua "domain" dalam satu halaman, dipilih lewat parameter
         * `mode` (ph|netdg). Net DG TIDAK punya tabel — dihitung on-the-fly.
         * Tidak ada endpoint branch-pencapaian & tidak ada RKA di domain ini.
         */
        Route::prefix('recovery-ph')->name('recovery-ph.')->group(function () {
            Route::get('filter-options', [PhNetDgDashboardController::class, 'filterOptions'])->name('filter-options');
            Route::get('snapshot', [PhNetDgDashboardController::class, 'snapshot'])->name('snapshot');
            Route::get('chart', [PhNetDgDashboardController::class, 'chart'])->name('chart');
            Route::get('cabang/{areaId}', [PhNetDgDashboardController::class, 'cabang'])->name('cabang');
            Route::get('uker/{cabangId}', [PhNetDgDashboardController::class, 'uker'])->name('uker');
        });

        // Recovery mengikuti pola baku Simpanan (berdimensi segmen, tanpa produk).
        Route::prefix('recovery')->name('recovery.')->group(function () {
            Route::get('filter-options', [RecoveryDashboardController::class, 'filterOptions'])->name('filter-options');
            Route::get('snapshot', [RecoveryDashboardController::class, 'snapshot'])->name('snapshot');
            Route::get('chart', [RecoveryDashboardController::class, 'chart'])->name('chart');
            Route::get('branch-pencapaian', [RecoveryDashboardController::class, 'branchPencapaian'])->name('branch-pencapaian');
            Route::get('cabang/{areaId}', [RecoveryDashboardController::class, 'cabang'])->name('cabang');
            Route::get('uker/{cabangId}', [RecoveryDashboardController::class, 'uker'])->name('uker');
        });

        // Laba domain BULANAN, plus endpoint khusus chart-mtd (batang per bulan).
        Route::prefix('laba')->name('laba.')->group(function () {
            Route::get('filter-options', [LabaDashboardController::class, 'filterOptions'])->name('filter-options');
            Route::get('snapshot', [LabaDashboardController::class, 'snapshot'])->name('snapshot');
            Route::get('chart', [LabaDashboardController::class, 'chart'])->name('chart');
            Route::get('chart-mtd', [LabaDashboardController::class, 'chartMtd'])->name('chart-mtd');
            Route::get('branch-pencapaian', [LabaDashboardController::class, 'branchPencapaian'])->name('branch-pencapaian');
            Route::get('cabang/{areaId}', [LabaDashboardController::class, 'cabang'])->name('cabang');
            Route::get('uker/{cabangId}', [LabaDashboardController::class, 'uker'])->name('uker');
        });

        /*
         * Merchant = SATU halaman, DUA prefix endpoint (EDC & QRIS). Endpoint
         * chart & branch-pencapaian menerima parameter `kpi` (kode katalog).
         */
        Route::prefix('merchant')->name('merchant.')->group(function () {
            Route::prefix('edc')->name('edc.')->group(function () {
                Route::get('filter-options', [EdcController::class, 'filterOptions'])->name('filter-options');
                Route::get('snapshot', [EdcController::class, 'snapshot'])->name('snapshot');
                Route::get('chart', [EdcController::class, 'chart'])->name('chart');
                Route::get('branch-pencapaian', [EdcController::class, 'branchPencapaian'])->name('branch-pencapaian');
                Route::get('cabang/{areaId}', [EdcController::class, 'cabang'])->name('cabang');
                Route::get('uker/{cabangId}', [EdcController::class, 'uker'])->name('uker');
            });

            Route::prefix('qris')->name('qris.')->group(function () {
                Route::get('filter-options', [QrisController::class, 'filterOptions'])->name('filter-options');
                Route::get('snapshot', [QrisController::class, 'snapshot'])->name('snapshot');
                Route::get('chart', [QrisController::class, 'chart'])->name('chart');
                Route::get('branch-pencapaian', [QrisController::class, 'branchPencapaian'])->name('branch-pencapaian');
                Route::get('cabang/{areaId}', [QrisController::class, 'cabang'])->name('cabang');
                Route::get('uker/{cabangId}', [QrisController::class, 'uker'])->name('uker');
            });
        });
    });
});

Route::middleware('auth')->group(function () {
    // Halaman Profil hanya berisi form ganti password — update profil & hapus
    // akun sendiri sengaja tidak ada.
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
});

/*
 * Area Admin — seluruh grup dijaga middleware 'admin' (role admin saja).
 * Halaman memakai layout yang sama dengan dashboard (AuthenticatedLayout).
 */
Route::middleware(['auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', [AdminController::class, 'index'])->name('index');

    // Upload data aktual Simpanan.
    Route::prefix('upload/simpanan')->name('upload.simpanan.')->group(function () {
        Route::get('/', [UploadSimpananController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadSimpananController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadSimpananController::class, 'upload'])->name('store');
        Route::get('unduh/{tanggal}', [UploadSimpananController::class, 'unduh'])->name('unduh');
        Route::delete('bulk-month', [UploadSimpananController::class, 'hapusBulan'])->name('bulk-month');
        Route::delete('{tanggal}', [UploadSimpananController::class, 'hapusTanggal'])->name('hapus');
    });

    // Upload data aktual Pinjaman.
    Route::prefix('upload/pinjaman')->name('upload.pinjaman.')->group(function () {
        Route::get('/', [UploadPinjamanController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadPinjamanController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadPinjamanController::class, 'upload'])->name('store');
        Route::get('unduh/{tanggal}', [UploadPinjamanController::class, 'unduh'])->name('unduh');
        Route::delete('bulk-month', [UploadPinjamanController::class, 'hapusBulan'])->name('bulk-month');
        Route::delete('{tanggal}', [UploadPinjamanController::class, 'hapusTanggal'])->name('hapus');
    });

    // Kelola RKA Pinjaman.
    Route::prefix('rka/pinjaman')->name('rka.pinjaman.')->group(function () {
        Route::get('/', [RkaPinjamanController::class, 'index'])->name('index');
        Route::get('data', [RkaPinjamanController::class, 'data'])->name('data');
        Route::post('/', [RkaPinjamanController::class, 'upload'])->name('store');
        Route::delete('year/{tahun}', [RkaPinjamanController::class, 'hapusTahun'])->name('hapus-tahun');
    });

    // Upload data aktual Recovery.
    Route::prefix('upload/recovery')->name('upload.recovery.')->group(function () {
        Route::get('/', [UploadRecoveryController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadRecoveryController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadRecoveryController::class, 'upload'])->name('store');
        Route::get('unduh/{tanggal}', [UploadRecoveryController::class, 'unduh'])->name('unduh');
        Route::delete('bulk-month', [UploadRecoveryController::class, 'hapusBulan'])->name('bulk-month');
        Route::delete('{tanggal}', [UploadRecoveryController::class, 'hapusTanggal'])->name('hapus');
    });

    // Upload PH. Tidak ada RKA untuk domain ini.
    Route::prefix('upload/ph')->name('upload.ph.')->group(function () {
        Route::get('/', [UploadPhController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadPhController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadPhController::class, 'upload'])->name('store');
        Route::get('unduh/{periode}', [UploadPhController::class, 'unduh'])->name('unduh');
        Route::delete('year/{tahun}', [UploadPhController::class, 'hapusTahun'])->name('hapus-tahun');
        Route::delete('{periode}', [UploadPhController::class, 'hapusPeriode'])->name('hapus');
    });

    // Kelola RKA Recovery.
    Route::prefix('rka/recovery')->name('rka.recovery.')->group(function () {
        Route::get('/', [RkaRecoveryController::class, 'index'])->name('index');
        Route::get('data', [RkaRecoveryController::class, 'data'])->name('data');
        Route::post('/', [RkaRecoveryController::class, 'upload'])->name('store');
        Route::delete('year/{tahun}', [RkaRecoveryController::class, 'hapusTahun'])->name('hapus-tahun');
    });

    // Upload data aktual Laba — BULANAN (periode tahun+bulan, bukan tanggal).
    Route::prefix('upload/laba')->name('upload.laba.')->group(function () {
        Route::get('/', [UploadLabaController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadLabaController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadLabaController::class, 'upload'])->name('store');
        Route::get('unduh/{tahun}/{bulan}', [UploadLabaController::class, 'unduh'])->name('unduh');
        Route::delete('year/{tahun}', [UploadLabaController::class, 'hapusTahun'])->name('hapus-tahun');
        Route::delete('{tahun}/{bulan}', [UploadLabaController::class, 'hapusPeriode'])->name('hapus');
    });

    // Kelola RKA Laba.
    Route::prefix('rka/laba')->name('rka.laba.')->group(function () {
        Route::get('/', [RkaLabaController::class, 'index'])->name('index');
        Route::get('data', [RkaLabaController::class, 'data'])->name('data');
        Route::post('/', [RkaLabaController::class, 'upload'])->name('store');
        Route::delete('year/{tahun}', [RkaLabaController::class, 'hapusTahun'])->name('hapus-tahun');
    });

    // Merchant EDC — data aktual harian per KPI.
    Route::prefix('upload/edc')->name('upload.edc.')->group(function () {
        Route::get('/', [UploadEdcController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadEdcController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadEdcController::class, 'upload'])->name('store');
        Route::get('unduh/{tanggal}', [UploadEdcController::class, 'unduh'])->name('unduh');
        Route::delete('bulk-month', [UploadEdcController::class, 'hapusBulan'])->name('bulk-month');
        Route::delete('{tanggal}', [UploadEdcController::class, 'hapusTanggal'])->name('hapus');
    });

    Route::prefix('rka/edc')->name('rka.edc.')->group(function () {
        Route::get('/', [RkaEdcController::class, 'index'])->name('index');
        Route::get('data', [RkaEdcController::class, 'data'])->name('data');
        Route::post('/', [RkaEdcController::class, 'upload'])->name('store');
        Route::delete('year/{tahun}', [RkaEdcController::class, 'hapusTahun'])->name('hapus-tahun');
    });

    // Merchant QRIS — data aktual harian per KPI.
    Route::prefix('upload/qris')->name('upload.qris.')->group(function () {
        Route::get('/', [UploadQrisController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadQrisController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadQrisController::class, 'upload'])->name('store');
        Route::get('unduh/{tanggal}', [UploadQrisController::class, 'unduh'])->name('unduh');
        Route::delete('bulk-month', [UploadQrisController::class, 'hapusBulan'])->name('bulk-month');
        Route::delete('{tanggal}', [UploadQrisController::class, 'hapusTanggal'])->name('hapus');
    });

    Route::prefix('rka/qris')->name('rka.qris.')->group(function () {
        Route::get('/', [RkaQrisController::class, 'index'])->name('index');
        Route::get('data', [RkaQrisController::class, 'data'])->name('data');
        Route::post('/', [RkaQrisController::class, 'upload'])->name('store');
        Route::delete('year/{tahun}', [RkaQrisController::class, 'hapusTahun'])->name('hapus-tahun');
    });

    // Kelola RKA Simpanan.
    Route::prefix('rka/simpanan')->name('rka.simpanan.')->group(function () {
        Route::get('/', [RkaSimpananController::class, 'index'])->name('index');
        Route::get('data', [RkaSimpananController::class, 'data'])->name('data');
        Route::post('/', [RkaSimpananController::class, 'upload'])->name('store');
        Route::delete('year/{tahun}', [RkaSimpananController::class, 'hapusTahun'])->name('hapus-tahun');
    });

    // Manajemen user.
    Route::prefix('users')->name('users.')->group(function () {
        Route::get('/', [UserController::class, 'index'])->name('index');
        Route::get('data', [UserController::class, 'data'])->name('data');
        Route::get('uker/{cabangId}', [UserController::class, 'uker'])->name('uker');
        Route::post('/', [UserController::class, 'store'])->name('store');
        Route::put('{user}', [UserController::class, 'update'])->name('update');
        Route::delete('{user}', [UserController::class, 'destroy'])->name('destroy');
        Route::patch('{user}/toggle-lock', [UserController::class, 'toggleLock'])->name('toggle-lock');
    });
});

require __DIR__.'/auth.php';
