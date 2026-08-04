<?php

namespace App\Support;

/**
 * Sumber tunggal konversi satuan nilai uang.
 *
 * Semua nilai uang disimpan di database dalam RUPIAH PENUH.
 * Tampilan (kartu KPI, chart, tabel) memakai satuan JUTA.
 * Jangan menulis pembagian 1.000.000 di tempat lain — panggil helper ini.
 */
class Satuan
{
    /**
     * Pembagi rupiah penuh menjadi juta.
     */
    public const JUTA = 1_000_000;

    /**
     * Konversi rupiah penuh menjadi juta.
     *
     * `null` masuk berarti "tidak ada data" dan tetap keluar sebagai `null`
     * (bukan 0) — bedanya bermakna untuk chart dan perhitungan delta.
     */
    public static function toJuta(int|float|string|null $rupiah, ?int $presisi = null): ?float
    {
        if ($rupiah === null || $rupiah === '') {
            return null;
        }

        $juta = ((float) $rupiah) / self::JUTA;

        return $presisi === null ? $juta : round($juta, $presisi);
    }
}
