<?php

namespace App\Services;

use App\Models\Qris;
use App\Models\RkaQris;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard merchant QRIS. Seluruh logika ada di MerchantService; kelas ini
 * hanya menetapkan KATALOG KPI dan tabel sumbernya.
 *
 * ⚠️ SESUAIKAN daftar KPI di bawah dengan yang dipakai region.
 */
class QrisService extends MerchantService
{
    /**
     * @var list<array<string, mixed>>
     */
    public const KPI = [
        ['kode' => 'USER_QRIS', 'label' => 'User QRIS', 'flow' => false, 'target' => true, 'rupiah' => false, 'inverse' => false, 'alias' => ['pengguna qris', 'jumlah user qris', 'jml qris', 'jumlah qris', 'user']],
        ['kode' => 'QRIS_PRODUKTIF', 'label' => 'QRIS Produktif', 'flow' => false, 'target' => true, 'rupiah' => false, 'inverse' => false, 'alias' => ['produktif', 'qris aktif', 'qris produktif']],
        ['kode' => 'SALES_VOLUME', 'label' => 'Sales Volume', 'flow' => true, 'target' => true, 'rupiah' => true, 'inverse' => false, 'alias' => ['sv', 'volume penjualan', 'sales vol', 'sales volume qris', 'sales_volume_qris', 'sales volume marginal', 'sales_volume_marginal']],
        ['kode' => 'JUMLAH_TRX', 'label' => 'Jumlah Transaksi', 'flow' => true, 'target' => false, 'rupiah' => false, 'inverse' => false, 'alias' => ['trx', 'transaksi', 'jml trx', 'jumlah trx', 'jml trx marginal', 'jml_trx_marginal']],
    ];

    protected function baseQuery(): Builder
    {
        return Qris::query();
    }

    protected function rkaQuery(): Builder
    {
        return RkaQris::query();
    }
}
