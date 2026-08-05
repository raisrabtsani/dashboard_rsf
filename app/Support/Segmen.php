<?php

namespace App\Support;

/**
 * Taksonomi segmen KANONIK — sumber tunggal untuk Recovery, PH, dan Net DG.
 *
 * Sistem sumber memakai istilah berbeda-beda antar tahun dan antar domain
 * ("Small"/"Kecil"/"Medium"/"Menengah" semuanya bermuara ke SME). Normalisasi
 * dilakukan SAAT BACA sehingga perbandingan antar tahun apple-to-apple,
 * sementara data mentah di database tetap utuh.
 *
 * Segmen pinjaman (Mikro/Kecil/Menengah/Konsumer) juga dipetakan ke sini supaya
 * Net DG — yang menggabungkan posisi pinjaman dengan flow PH — memakai sumbu
 * segmen yang sama persis di kedua sisinya.
 */
class Segmen
{
    public const MICRO = 'Micro';

    public const SME = 'SME';

    public const CONSUMER = 'Consumer';

    /** Urutan ini juga dipakai untuk mengurutkan kartu & seri chart. */
    public const SEMUA = [self::MICRO, self::SME, self::CONSUMER];

    /**
     * Bentuk mentah (huruf kecil) => kanonik.
     *
     * @var array<string, list<string>>
     */
    public const RAW = [
        self::MICRO => ['micro', 'mikro'],
        self::SME => ['sme', 'small', 'medium', 'kecil', 'menengah'],
        self::CONSUMER => ['consumer', 'konsumer', 'konsumtif'],
    ];

    /**
     * Normalkan satu label segmen. Mengembalikan null bila tidak dikenali —
     * pemanggil yang memutuskan apakah itu error atau baris yang dilewati.
     */
    public static function kanonik(string $segmen): ?string
    {
        $kunci = mb_strtolower(trim($segmen));

        foreach (self::RAW as $kanonik => $mentah) {
            if (in_array($kunci, $mentah, true)) {
                return $kanonik;
            }
        }

        return null;
    }
}
