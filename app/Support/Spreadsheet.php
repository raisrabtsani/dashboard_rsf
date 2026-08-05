<?php

namespace App\Support;

use App\Exceptions\ImportException;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Pembaca tabel sumber untuk importer admin: CSV maupun Excel.
 *
 * Satu pintu masuk untuk semua importer domain — jangan memanggil PhpSpreadsheet
 * langsung di service importer. Berkas .csv didelegasikan ke App\Support\Csv
 * (penanganan BOM sudah teruji di sana); .xlsx/.xls dibaca PhpSpreadsheet.
 */
class Spreadsheet
{
    /** @var list<string> */
    public const EKSTENSI = ['csv', 'txt', 'xlsx', 'xls'];

    /**
     * @param  list<string>  $kolomWajib
     * @param  string|null  $namaAsli  nama berkas dari klien; WAJIB diisi untuk unggahan HTTP
     * @return Collection<int, array<string, string>>
     */
    public static function baca(string $path, array $kolomWajib = [], ?string $namaAsli = null): Collection
    {
        // Format ditentukan dari NAMA ASLI, bukan dari $path.
        // Unggahan HTTP disimpan PHP sebagai berkas sementara "phpXXXX.tmp" —
        // menebak dari path akan selalu membaca ekstensi ".tmp" dan menolak
        // berkas yang sebenarnya sah.
        $ekstensi = strtolower(pathinfo($namaAsli ?? $path, PATHINFO_EXTENSION));

        if (! in_array($ekstensi, self::EKSTENSI, true)) {
            throw ImportException::berkas(
                "Format berkas .{$ekstensi} tidak didukung. Gunakan: ".implode(', ', self::EKSTENSI).'.',
            );
        }

        return in_array($ekstensi, ['csv', 'txt'], true)
            ? Csv::baca($path, $kolomWajib, $namaAsli)
            : self::bacaExcel($path, $kolomWajib, $namaAsli);
    }

    /**
     * @param  list<string>  $kolomWajib
     * @return Collection<int, array<string, string>>
     */
    private static function bacaExcel(string $path, array $kolomWajib, ?string $namaAsli): Collection
    {
        $pembaca = IOFactory::createReaderForFile($path);
        // Hanya butuh nilai, bukan gaya/format — jauh lebih hemat memori.
        $pembaca->setReadDataOnly(true);

        $lembar = $pembaca->load($path)->getActiveSheet();
        $nama = $namaAsli ?? basename($path);

        $header = null;
        $baris = collect();

        foreach ($lembar->getRowIterator() as $row) {
            $sel = $row->getCellIterator();
            $sel->setIterateOnlyExistingCells(false);

            $kolom = [];

            foreach ($sel as $cell) {
                $kolom[] = self::nilaiSel($cell);
            }

            if (self::kosong($kolom)) {
                continue;
            }

            if ($header === null) {
                $header = array_map(trim(...), $kolom);
                $hilang = array_diff($kolomWajib, $header);

                if ($hilang !== []) {
                    throw ImportException::berkas(sprintf(
                        'Kolom %s tidak ada di %s (kolom terbaca: %s).',
                        implode(', ', $hilang),
                        $nama,
                        implode(', ', array_filter($header)),
                    ));
                }

                continue;
            }

            // Baris Excel bisa lebih panjang/pendek dari header; ratakan.
            $kolom = array_pad(array_slice($kolom, 0, count($header)), count($header), '');

            $baris->push(array_combine($header, $kolom));
        }

        if ($header === null) {
            throw ImportException::berkas("Berkas kosong: {$nama}");
        }

        return $baris;
    }

    /**
     * @param  list<string>  $kolom
     */
    private static function kosong(array $kolom): bool
    {
        return $kolom === [] || collect($kolom)->every(fn (string $v) => $v === '');
    }

    /**
     * Normalkan satu sel jadi string.
     *
     * Tanggal di Excel tersimpan sebagai serial number, bukan teks — tanpa
     * konversi ini "2026-08-05" terbaca sebagai "46234".
     */
    private static function nilaiSel(Cell $cell): string
    {
        $nilai = $cell->getValue();

        if ($nilai === null) {
            return '';
        }

        if (ExcelDate::isDateTime($cell) && is_numeric($nilai)) {
            return ExcelDate::excelToDateTimeObject($nilai)->format('Y-m-d');
        }

        return trim((string) $nilai);
    }
}
