<?php

namespace App\Support;

use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Pembaca CSV bersama untuk seeder & importer.
 *
 * Semua file master/import diekspor dari Excel dan ber-BOM UTF-8, jadi BOM di
 * kolom pertama dibuang di sini — kalau tidak, nama kolom pertama terbaca
 * sebagai "\u{FEFF}id_region" dan semua lookup meleset.
 *
 * Filosofinya gagal keras: file hilang, kosong, kolom wajib tidak ada, atau
 * baris yang jumlah kolomnya tidak cocok akan melempar exception dengan pesan
 * jelas — jangan sampai struktur file yang berubah menghasilkan tabel kosong
 * secara diam-diam.
 */
class Csv
{
    /**
     * Baca CSV jadi koleksi array asosiatif berdasarkan baris header.
     *
     * @param  list<string>  $kolomWajib
     * @return Collection<int, array<string, string>>
     */
    public static function baca(string $path, array $kolomWajib = []): Collection
    {
        if (! is_file($path)) {
            throw new RuntimeException("File CSV tidak ditemukan: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw new RuntimeException("File CSV tidak bisa dibuka: {$path}");
        }

        $nama = basename($path);
        $header = null;
        $baris = collect();
        $nomor = 0;

        try {
            while (($kolom = fgetcsv($handle, escape: '')) !== false) {
                $nomor++;

                if (self::barisKosong($kolom)) {
                    continue;
                }

                if ($header === null) {
                    $header = self::header($kolom, $kolomWajib, $nama);

                    continue;
                }

                if (count($kolom) !== count($header)) {
                    throw new RuntimeException(sprintf(
                        'Baris %d di %s punya %d kolom, harusnya %d: %s',
                        $nomor,
                        $nama,
                        count($kolom),
                        count($header),
                        implode(',', $kolom),
                    ));
                }

                $baris->push(array_combine($header, $kolom));
            }
        } finally {
            fclose($handle);
        }

        if ($header === null) {
            throw new RuntimeException("File CSV kosong: {$path}");
        }

        return $baris;
    }

    /**
     * @param  list<string|null>  $kolom
     */
    private static function barisKosong(array $kolom): bool
    {
        return $kolom === [] || $kolom === [null] || (count($kolom) === 1 && trim((string) $kolom[0]) === '');
    }

    /**
     * @param  list<string|null>  $kolom
     * @param  list<string>  $kolomWajib
     * @return list<string>
     */
    private static function header(array $kolom, array $kolomWajib, string $nama): array
    {
        $kolom[0] = preg_replace('/^\x{FEFF}/u', '', (string) $kolom[0]);
        $header = array_map(trim(...), $kolom);

        $hilang = array_diff($kolomWajib, $header);

        if ($hilang !== []) {
            throw new RuntimeException(sprintf(
                'Kolom %s tidak ada di %s (kolom terbaca: %s).',
                implode(', ', $hilang),
                $nama,
                implode(', ', $header),
            ));
        }

        return $header;
    }
}
