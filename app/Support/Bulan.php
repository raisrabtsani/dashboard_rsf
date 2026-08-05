<?php

namespace App\Support;

use App\Exceptions\ImportException;

/**
 * Konstanta & penguraian bulan — sumber tunggal untuk seluruh domain.
 *
 * Berkas RKA dari unit bisnis menulis bulan sebagai NAMA ("Januari"), bukan
 * angka. Penguraiannya dipusatkan di sini supaya tiap importer domain tidak
 * membuat tabel bulannya sendiri.
 */
class Bulan
{
    /** @var array<int, string> */
    public const NAMA = [
        1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
        5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
        9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
    ];

    /** @var array<int, string> */
    public const PENDEK = [
        1 => 'Jan', 2 => 'Feb', 3 => 'Mar', 4 => 'Apr', 5 => 'Mei', 6 => 'Jun',
        7 => 'Jul', 8 => 'Ags', 9 => 'Sep', 10 => 'Okt', 11 => 'Nov', 12 => 'Des',
    ];

    /**
     * Terima angka 1-12, nama Indonesia, singkatan, atau nama Inggris.
     */
    public static function urai(string $mentah): ?int
    {
        $teks = trim($mentah);

        if ($teks === '') {
            return null;
        }

        if (is_numeric($teks)) {
            $angka = (int) $teks;

            return $angka >= 1 && $angka <= 12 ? $angka : null;
        }

        $kunci = mb_strtolower($teks);

        foreach (self::peta() as $nama => $angka) {
            if ($kunci === $nama) {
                return $angka;
            }
        }

        return null;
    }

    /**
     * Sama seperti urai(), tapi melempar ImportException dengan pesan jelas.
     */
    public static function uraiAtauGagal(string $mentah, int $nomorBaris): int
    {
        return self::urai($mentah) ?? throw ImportException::berkas(
            "Baris {$nomorBaris}: bulan '{$mentah}' tidak dikenal. ".
            'Gunakan angka 1-12 atau nama bulan (Januari, Februari, ...).',
        );
    }

    /**
     * @return array<string, int>
     */
    private static function peta(): array
    {
        static $peta = null;

        if ($peta !== null) {
            return $peta;
        }

        $peta = [];

        foreach (self::NAMA as $angka => $nama) {
            $peta[mb_strtolower($nama)] = $angka;
        }

        foreach (self::PENDEK as $angka => $nama) {
            $peta[mb_strtolower($nama)] = $angka;
        }

        // Berkas ekspor sistem kadang berbahasa Inggris.
        $inggris = [
            'january' => 1, 'february' => 2, 'march' => 3, 'april' => 4,
            'may' => 5, 'june' => 6, 'july' => 7, 'august' => 8,
            'september' => 9, 'october' => 10, 'november' => 11, 'december' => 12,
            'jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'jun' => 6,
            'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12,
        ];

        return $peta = [...$peta, ...$inggris];
    }
}
