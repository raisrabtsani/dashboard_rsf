<?php

namespace App\Support;

use App\Exceptions\ImportException;
use Illuminate\Support\Collection;

/**
 * Pemetaan nama kolom berkas sumber ke nama kanonik importer.
 *
 * Berkas dari unit bisnis disusun di Excel oleh manusia, jadi nama kolomnya
 * tidak pernah persis: ada yang kapital ("Produk"), berspasi (" RKA "), atau
 * memakai istilah lain ("target" vs "RKA"). Menuntut nama kolom yang persis
 * membuat importer menolak berkas yang sebenarnya benar isinya.
 *
 * Perbandingan dilakukan setelah normalisasi: trim, huruf kecil, spasi/underscore
 * dirapatkan. Jadi " RKA ", "rka", dan "R K A" sama-sama dikenali.
 */
class PetaKolom
{
    /**
     * Ubah key tiap baris jadi nama kanonik.
     *
     * @param  Collection<int, array<string, string>>  $baris
     * @param  array<string, list<string>>  $alias  nama kanonik => daftar alias yang diterima
     * @param  list<string>  $wajib  nama kanonik yang harus ada
     * @return Collection<int, array<string, string>>
     */
    public static function petakan(Collection $baris, array $alias, array $wajib, string $namaBerkas): Collection
    {
        if ($baris->isEmpty()) {
            throw ImportException::berkas("Berkas {$namaBerkas} tidak berisi baris data.");
        }

        $header = array_keys($baris->first());
        $peta = self::bangunPeta($header, $alias);

        $hilang = array_diff($wajib, array_values($peta));

        if ($hilang !== []) {
            throw ImportException::berkas(sprintf(
                'Kolom %s tidak ditemukan di %s. Kolom yang terbaca: %s.',
                implode(', ', $hilang),
                $namaBerkas,
                implode(', ', array_map(fn ($h) => "'{$h}'", $header)),
            ));
        }

        return $baris->map(function (array $r) use ($peta) {
            $hasil = [];

            foreach ($r as $kolom => $nilai) {
                $kanonik = $peta[$kolom] ?? null;

                if ($kanonik !== null) {
                    $hasil[$kanonik] = $nilai;
                }
            }

            return $hasil;
        });
    }

    /**
     * @param  list<string>  $header
     * @param  array<string, list<string>>  $alias
     * @return array<string, string> nama kolom asli => nama kanonik
     */
    private static function bangunPeta(array $header, array $alias): array
    {
        $cari = [];

        foreach ($alias as $kanonik => $daftar) {
            foreach ([$kanonik, ...$daftar] as $a) {
                $cari[self::normal($a)] = $kanonik;
            }
        }

        $peta = [];

        foreach ($header as $kolom) {
            $kunci = self::normal($kolom);

            if (isset($cari[$kunci])) {
                $peta[$kolom] = $cari[$kunci];
            }
        }

        return $peta;
    }

    private static function normal(string $teks): string
    {
        return preg_replace('/[\s_]+/', '', mb_strtolower(trim($teks)));
    }
}
