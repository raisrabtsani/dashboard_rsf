<?php

use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\Admin\RkaEdcController;
use App\Http\Controllers\Admin\RkaLabaController;
use App\Http\Controllers\Admin\RkaPinjamanCommercialController;
use App\Http\Controllers\Admin\RkaPinjamanController;
use App\Http\Controllers\Admin\RkaQrisController;
use App\Http\Controllers\Admin\RkaRecoveryController;
use App\Http\Controllers\Admin\RkaSimpananController;
use App\Http\Controllers\Admin\RkaSimpananWholesaleController;
use App\Http\Controllers\Admin\UploadEdcController;
use App\Http\Controllers\Admin\UploadLabaController;
use App\Http\Controllers\Admin\UploadPhController;
use App\Http\Controllers\Admin\UploadPinjamanCommercialController;
use App\Http\Controllers\Admin\UploadPinjamanController;
use App\Http\Controllers\Admin\UploadQrisController;
use App\Http\Controllers\Admin\UploadRecoveryController;
use App\Http\Controllers\Admin\UploadSimpananController;
use App\Http\Controllers\Admin\UploadSimpananHourlyController;
use App\Http\Controllers\Admin\UploadSimpananWholesaleController;
use App\Http\Controllers\Admin\UserActivityController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\EdcController;
use App\Http\Controllers\LabaDashboardController;
use App\Http\Controllers\MerchantController;
use App\Http\Controllers\PhNetDgDashboardController;
use App\Http\Controllers\PinjamanDashboardController;
use App\Http\Controllers\PresentController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\QrisController;
use App\Http\Controllers\RecoveryDashboardController;
use App\Http\Controllers\SimpananDashboardController;
use App\Http\Controllers\SimpananHourlyDashboardController;
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

    /*
     * DPK Hourly — layar pemantauan hari EOM. Middleware `hourly` (RO/BO/admin)
     * dipasang di HALAMAN dan di SELURUH endpoint api/simpanan-hourly/* di bawah;
     * menyembunyikan menunya di frontend BUKAN pengamanan.
     */
    Route::get('/dashboard/simpanan-hourly', [SimpananHourlyDashboardController::class, 'index'])
        ->middleware('hourly')
        ->name('simpanan-hourly');
    Route::get('/dashboard/laba', [LabaDashboardController::class, 'index'])->name('laba');
    Route::get('/dashboard/merchant', [MerchantController::class, 'index'])->name('merchant');

    /*
     * PRESENT — slide rapat pagi Region. Gerbang `present` (RO/admin) dipasang di
     * HALAMAN dan di SETIAP endpoint api/present/* di bawah. Menyembunyikan menunya
     * di frontend BUKAN pengamanan.
     */
    Route::get('/present', [PresentController::class, 'index'])->middleware('present')->name('present');

    Route::prefix('api')->name('api.')->group(function () {
        Route::get('scope', [DashboardController::class, 'scope'])->name('scope');

        // Landing: ringkasan lintas domain. Tidak punya endpoint ranking antar
        // entitas, jadi aman untuk semua level (tiap user melihat total lingkupnya
        // sendiri) — tidak perlu didaftarkan di EnforceUserScope::BRANCH_ROUTES.
        Route::prefix('dashboard')->name('dashboard.')->group(function () {
            Route::get('filter-options', [DashboardController::class, 'filterOptions'])->name('filter-options');
            Route::get('ringkasan', [DashboardController::class, 'ringkasan'])->name('ringkasan');
            Route::get('cabang/{areaId}', [DashboardController::class, 'cabang'])->name('cabang');
            Route::get('uker/{cabangId}', [DashboardController::class, 'uker'])->name('uker');
        });

        // PRESENT — seluruh grup dijaga `present` (RO/admin), bukan hanya halamannya.
        Route::prefix('present')->name('present.')->middleware('present')->group(function () {
            Route::get('overview', [PresentController::class, 'overview'])->name('overview');
            Route::get('area', [PresentController::class, 'area'])->name('area');
            Route::get('detail', [PresentController::class, 'detail'])->name('detail');
        });

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

        // Seluruh grup dijaga `hourly`, bukan hanya halamannya.
        Route::prefix('simpanan-hourly')->name('simpanan-hourly.')->middleware('hourly')->group(function () {
            Route::get('filter-options', [SimpananHourlyDashboardController::class, 'filterOptions'])->name('filter-options');
            Route::get('snapshot', [SimpananHourlyDashboardController::class, 'snapshot'])->name('snapshot');
            Route::get('chart', [SimpananHourlyDashboardController::class, 'chart'])->name('chart');
            Route::get('branch-pencapaian', [SimpananHourlyDashboardController::class, 'branchPencapaian'])->name('branch-pencapaian');
            Route::get('cabang/{areaId}', [SimpananHourlyDashboardController::class, 'cabang'])->name('cabang');
            Route::get('uker/{cabangId}', [SimpananHourlyDashboardController::class, 'uker'])->name('uker');
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
            Route::get('branch-pencapaian', [PhNetDgDashboardController::class, 'branchPencapaian'])->name('branch-pencapaian');
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

    // Master kantor cabang & unit kerja: upload, edit, dan hapus aman.
    Route::prefix('cabang')->name('cabang.')->group(function () {
        Route::get('/', [AdminController::class, 'kantorCabang'])->name('index');
        Route::get('template', [AdminController::class, 'templateKantor'])->name('template');
        Route::post('upload', [AdminController::class, 'uploadKantor'])->name('upload');
        Route::delete('selected', [AdminController::class, 'destroyKantorPilihan'])->name('selected');
        Route::put('{cabang}', [AdminController::class, 'updateCabang'])->name('update');
        Route::delete('{cabang}', [AdminController::class, 'destroyCabang'])->name('destroy');
    });

    Route::prefix('uker')->name('uker.')->group(function () {
        Route::put('{uker}', [AdminController::class, 'updateUker'])->name('update');
        Route::delete('{uker}', [AdminController::class, 'destroyUker'])->name('destroy');
    });

    // Ringkasan dan penghapusan pilihan RKA lintas domain.
    Route::prefix('rka-manage/{domain}')->name('rka-manage.')->group(function () {
        Route::get('data', [AdminController::class, 'rkaData'])
            ->where('domain', 'simpanan|pinjaman|recovery|laba|edc|qris|simpanan-wholesale|pinjaman-commercial')
            ->name('data');
        Route::delete('selected', [AdminController::class, 'hapusRkaPilihan'])
            ->where('domain', 'simpanan|pinjaman|recovery|laba|edc|qris|simpanan-wholesale|pinjaman-commercial')
            ->name('selected');
    });

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
        Route::post('preview', [UploadPinjamanController::class, 'preview'])->name('preview');
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

    // Upload DPK Hourly. Jam ditetapkan admin di form, bukan dari berkas.
    Route::prefix('upload/simpanan-hourly')->name('upload.simpanan-hourly.')->group(function () {
        Route::get('/', [UploadSimpananHourlyController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadSimpananHourlyController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadSimpananHourlyController::class, 'upload'])->name('store');
        Route::delete('{tanggal}/jam', [UploadSimpananHourlyController::class, 'hapusJam'])->name('hapus-jam');
        Route::delete('{tanggal}', [UploadSimpananHourlyController::class, 'hapusTanggal'])->name('hapus');
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

    /*
     * Segmen tambahan khusus PRESENT (tidak masuk dashboard harian):
     * DPK Wholesale & Pinjaman Commercial — masing-masing upload aktual + RKA,
     * mengikuti pola domain harian.
     */
    Route::prefix('upload/simpanan-wholesale')->name('upload.simpanan-wholesale.')->group(function () {
        Route::get('/', [UploadSimpananWholesaleController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadSimpananWholesaleController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadSimpananWholesaleController::class, 'upload'])->name('store');
        Route::get('unduh/{tanggal}', [UploadSimpananWholesaleController::class, 'unduh'])->name('unduh');
        Route::delete('bulk-month', [UploadSimpananWholesaleController::class, 'hapusBulan'])->name('bulk-month');
        Route::delete('{tanggal}', [UploadSimpananWholesaleController::class, 'hapusTanggal'])->name('hapus');
    });

    Route::prefix('rka/simpanan-wholesale')->name('rka.simpanan-wholesale.')->group(function () {
        Route::get('/', [RkaSimpananWholesaleController::class, 'index'])->name('index');
        Route::get('data', [RkaSimpananWholesaleController::class, 'data'])->name('data');
        Route::post('/', [RkaSimpananWholesaleController::class, 'upload'])->name('store');
        Route::delete('year/{tahun}', [RkaSimpananWholesaleController::class, 'hapusTahun'])->name('hapus-tahun');
    });

    Route::prefix('upload/pinjaman-commercial')->name('upload.pinjaman-commercial.')->group(function () {
        Route::get('/', [UploadPinjamanCommercialController::class, 'index'])->name('index');
        Route::get('riwayat', [UploadPinjamanCommercialController::class, 'riwayat'])->name('riwayat');
        Route::post('/', [UploadPinjamanCommercialController::class, 'upload'])->name('store');
        Route::get('unduh/{tanggal}', [UploadPinjamanCommercialController::class, 'unduh'])->name('unduh');
        Route::delete('bulk-month', [UploadPinjamanCommercialController::class, 'hapusBulan'])->name('bulk-month');
        Route::delete('{tanggal}', [UploadPinjamanCommercialController::class, 'hapusTanggal'])->name('hapus');
    });

    Route::prefix('rka/pinjaman-commercial')->name('rka.pinjaman-commercial.')->group(function () {
        Route::get('/', [RkaPinjamanCommercialController::class, 'index'])->name('index');
        Route::get('data', [RkaPinjamanCommercialController::class, 'data'])->name('data');
        Route::post('/', [RkaPinjamanCommercialController::class, 'upload'])->name('store');
        Route::delete('year/{tahun}', [RkaPinjamanCommercialController::class, 'hapusTahun'])->name('hapus-tahun');
    });

    // Aktivitas pengguna — online sekarang (dari sessions) + terakhir aktif per user.
    Route::prefix('activity')->name('activity.')->group(function () {
        Route::get('/', [UserActivityController::class, 'index'])->name('index');
        Route::get('data', [UserActivityController::class, 'data'])->name('data');
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
