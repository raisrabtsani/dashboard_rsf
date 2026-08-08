<?php

namespace App\Services;

use App\Models\Edc;
use App\Models\RkaEdc;
use Illuminate\Database\Eloquent\Builder;

/**
 * Dashboard merchant EDC. Seluruh logika ada di MerchantService; kelas ini hanya
 * menetapkan KATALOG KPI dan tabel sumbernya.
 *
 * ⚠️ SESUAIKAN daftar KPI di bawah dengan yang dipakai region. Ini satu-satunya
 * sumber urutan tampil, label, satuan, dan perilaku tiap KPI.
 */
class EdcService extends MerchantService
{
    /**
     * @var list<array<string, mixed>>
     */
    public const KPI = [
        ['kode' => 'TID', 'label' => 'TID', 'flow' => false, 'target' => true, 'rupiah' => false, 'inverse' => false, 'alias' => ['terminal id', 'jumlah tid']],
        ['kode' => 'MID', 'label' => 'MID', 'flow' => false, 'target' => false, 'rupiah' => false, 'inverse' => false, 'alias' => ['merchant id', 'jumlah mid']],
        ['kode' => 'EDC_PRODUKTIF', 'label' => 'EDC Produktif', 'flow' => false, 'target' => true, 'rupiah' => false, 'inverse' => false, 'alias' => ['edc aktif', 'produktif', 'edc produktif']],
        ['kode' => 'EDC_SV_0', 'label' => 'EDC SV Rp.0', 'flow' => false, 'target' => false, 'rupiah' => false, 'inverse' => true, 'alias' => ['edc sv rp 0', 'edc sv 0', 'edc sales volume 0', 'sv rp0', 'sv rp nol', 'sv_rp_nol']],
        ['kode' => 'SALES_VOLUME', 'label' => 'Sales Volume', 'flow' => true, 'target' => true, 'rupiah' => true, 'inverse' => false, 'alias' => ['sv', 'volume penjualan', 'sales vol', 'sales volume marginal', 'sales_volume_marginal']],
        ['kode' => 'JUMLAH_TRX', 'label' => 'Jumlah Transaksi', 'flow' => true, 'target' => false, 'rupiah' => false, 'inverse' => false, 'alias' => ['trx', 'transaksi', 'jml trx', 'jumlah trx', 'jml trx marginal', 'jml_trx_marginal']],
    ];

    protected function baseQuery(): Builder
    {
        return Edc::query();
    }

    protected function rkaQuery(): Builder
    {
        return RkaEdc::query();
    }
}
