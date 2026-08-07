<?php

namespace App\Support;

use App\Exceptions\ImportException;
use Generator;
use Illuminate\Support\Collection;

/**
 * Pembaca CSV bersama untuk seeder & importer.
 *
 * File ekspor Excel/Tableau tidak selalu UTF-8. Beberapa berkas berukuran besar
 * disimpan sebagai UTF-16 LE/BE dengan BOM. Pembacaan dilakukan melalui stream
 * filter agar berkas tidak perlu dimuat seluruhnya ke memori hanya untuk
 * dikonversi ke UTF-8.
 */
class Csv
{
    /**
     * Baca CSV jadi koleksi array asosiatif berdasarkan baris header.
     *
     * Importer lama tetap dapat memakai API koleksi ini. Importer berkas besar
     * sebaiknya memakai {@see baris()} agar pemrosesan berlangsung bertahap.
     *
     * @param  list<string>  $kolomWajib
     * @return Collection<int, array<string, string>>
     */
    public static function baca(string $path, array $kolomWajib = [], ?string $namaAsli = null): Collection
    {
        return collect(iterator_to_array(
            self::baris($path, $kolomWajib, $namaAsli),
            preserve_keys: false,
        ));
    }

    /**
     * Baca CSV baris demi baris tanpa menampung seluruh isi berkas di memori.
     *
     * Encoding yang diterima:
     * - UTF-8, dengan atau tanpa BOM;
     * - UTF-16 LE dengan BOM;
     * - UTF-16 BE dengan BOM.
     *
     * @param  list<string>  $kolomWajib
     * @return Generator<int, array<string, string>>
     */
    public static function baris(string $path, array $kolomWajib = [], ?string $namaAsli = null): Generator
    {
        if (! is_file($path)) {
            throw ImportException::berkas("File CSV tidak ditemukan: {$path}");
        }

        $handle = self::buka($path);

        // Pesan error menyebut nama yang dikenal user, bukan nama berkas
        // sementara PHP ("phpA1B2.tmp") yang tidak berarti apa-apa baginya.
        $nama = $namaAsli ?? basename($path);
        $header = null;
        $nomor = 0;
        $indeks = 0;

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

                $gabungan = array_combine($header, $kolom);

                if ($gabungan === false) {
                    throw ImportException::berkas(
                        "Baris {$nomor} di {$nama} tidak dapat dipetakan ke header CSV.",
                    );
                }

                yield $indeks++ => $gabungan;
            }
        } finally {
            fclose($handle);
        }

        if ($header === null) {
            throw ImportException::berkas("File CSV kosong: {$path}");
        }
    }

    /**
     * Buka stream CSV dan ubah UTF-16 menjadi UTF-8 secara streaming.
     *
     * @return resource
     */
    private static function buka(string $path)
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw ImportException::berkas("File CSV tidak bisa dibuka: {$path}");
        }

        $awal = fread($handle, 4);

        if ($awal === false) {
            fclose($handle);

            throw ImportException::berkas("File CSV tidak bisa dibaca: {$path}");
        }

        rewind($handle);

        $encoding = null;
        $panjangBom = 0;

        if (str_starts_with($awal, "\xFF\xFE")) {
            $encoding = 'UTF-16LE';
            $panjangBom = 2;
        } elseif (str_starts_with($awal, "\xFE\xFF")) {
            $encoding = 'UTF-16BE';
            $panjangBom = 2;
        }

        if ($encoding === null) {
            return $handle;
        }

        // Lewati BOM sumber. Header UTF-8 hasil konversi jadi bersih dan fungsi
        // fgetcsv dapat mengenali koma serta CRLF secara normal.
        if (fseek($handle, $panjangBom) !== 0) {
            fclose($handle);

            throw ImportException::berkas("Posisi awal file CSV tidak dapat dibaca: {$path}");
        }

        $filter = @stream_filter_append(
            $handle,
            "convert.iconv.{$encoding}/UTF-8",
            STREAM_FILTER_READ,
        );

        if ($filter === false) {
            fclose($handle);

            throw ImportException::berkas(
                "CSV ber-encoding {$encoding} tidak dapat dikonversi. Pastikan ekstensi PHP iconv aktif.",
            );
        }

        return $handle;
    }

    /**
     * @param  list<string|null>  $kolom
     */
    private static function barisKosong(array $kolom): bool
    {
        return $kolom === []
            || $kolom === [null]
            || collect($kolom)->every(fn (mixed $nilai) => trim((string) $nilai) === '');
    }

    /**
     * @param  list<string|null>  $kolom
     * @param  list<string>  $kolomWajib
     * @return list<string>
     */
    private static function header(array $kolom, array $kolomWajib, string $nama): array
    {
        $pertama = (string) ($kolom[0] ?? '');
        $kolom[0] = preg_replace('/^\x{FEFF}/u', '', $pertama) ?? $pertama;
        $header = array_map(fn (mixed $nilai) => trim((string) $nilai), $kolom);
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
