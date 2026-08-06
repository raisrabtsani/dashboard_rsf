<?php

namespace App\Support;

use App\Exceptions\ImportException;
use Illuminate\Support\Collection;

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
    public static function baca(string $path, array $kolomWajib = [], ?string $namaAsli = null): Collection
    {
        if (! is_file($path)) {
            throw ImportException::berkas("File CSV tidak ditemukan: {$path}");
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            throw ImportException::berkas("File CSV tidak bisa dibuka: {$path}");
        }

        // Pesan error menyebut nama yang dikenal user, bukan nama berkas
        // sementara PHP ("phpA1B2.tmp") yang tidak berarti apa-apa baginya.
        $nama = $namaAsli ?? basename($path);
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

                // Jangan hentikan seluruh berkas hanya karena satu baris
                // memiliki kolom kosong/kurang. Ratakan seperti pembaca Excel:
                // kolom kurang diisi string kosong, kolom berlebih dipotong.
                // Validasi isi tetap dilakukan per baris oleh masing-masing
                // importer sehingga baris valid tetap dapat diproses dan baris
                // bermasalah masuk ke laporan error yang dapat diunduh.
                $kolom = array_pad(
                    array_slice($kolom, 0, count($header)),
                    count($header),
                    '',
                );

                $baris->push(array_combine($header, $kolom));
            }
        } finally {
            fclose($handle);
        }

        if ($header === null) {
            throw ImportException::berkas("File CSV kosong: {$path}");
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
        $header = self::gabungkanHeaderTanggalTableau($header);

        $hilang = array_diff($kolomWajib, $header);

        if ($hilang !== []) {
            throw ImportException::berkas(sprintf(
                'Kolom %s tidak ada di %s (kolom terbaca: %s).',
                implode(', ', $hilang),
                $nama,
                implode(', ', $header),
            ));
        }

        return $header;
    }

    /**
     * Tableau kadang menulis header tanggal sebagai:
     * Month, Day, Year of Posisi
     *
     * Jika header CSV tidak diberi tanda kutip, fgetcsv membacanya sebagai tiga
     * kolom terpisah. Gabungkan kembali supaya jumlah kolom header cocok dengan
     * baris data dan importer tetap menerima format sumber apa adanya.
     *
     * @param  list<string>  $header
     * @return list<string>
     */
    private static function gabungkanHeaderTanggalTableau(array $header): array
    {
        for ($i = 0; $i <= count($header) - 3; $i++) {
            if (
                strcasecmp($header[$i], 'Month') === 0
                && strcasecmp($header[$i + 1], 'Day') === 0
                && preg_match('/^Year\s+of\s+.+$/iu', $header[$i + 2]) === 1
            ) {
                array_splice(
                    $header,
                    $i,
                    3,
                    [implode(', ', [$header[$i], $header[$i + 1], $header[$i + 2]])],
                );

                break;
            }
        }

        return array_values($header);
    }
}
