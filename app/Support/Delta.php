<?php

namespace App\Support;

/**
 * Perhitungan delta (selisih vs tanggal pembanding) untuk kartu KPI.
 *
 * Dipakai semua domain — bentuk payload-nya harus seragam supaya komponen
 * KpiCard tidak perlu tahu domain mana yang mengirimnya.
 */
class Delta
{
    /**
     * @return array{nilai: float|null, persen: float|null}
     */
    public static function hitung(float $aktual, ?float $pembanding): array
    {
        if ($pembanding === null) {
            // Tidak ada data pembanding = TIDAK DIKETAHUI, bukan nol.
            return ['nilai' => null, 'persen' => null];
        }

        return [
            'nilai' => Satuan::toJuta($aktual - $pembanding),
            'persen' => $pembanding == 0.0
                ? null
                : round(($aktual - $pembanding) / abs($pembanding) * 100, 2),
        ];
    }

    /**
     * Kosong dipakai saat tanggal pembandingnya sendiri tidak ada.
     *
     * @return array{nilai: null, persen: null}
     */
    public static function kosong(): array
    {
        return ['nilai' => null, 'persen' => null];
    }
}
