<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Models\Pinjaman;
use App\Models\Uker;
use App\Services\Concerns\MelaporkanImport;
use App\Support\Csv;
use App\Support\PetaKolom;
use App\Support\Satuan;
use App\Support\Spreadsheet;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Import data AKTUAL Pinjaman dari CSV/Excel.
 *
 * Format sumber, nilai RUPIAH PENUH:
 *   id_cabang | id_uker | SEGMEN_2025 | Segmentasi | Kualitas Kredit |
 *   Baki Debet | Month, Day, Year of Posisi
 *
 * CSV ekspor Excel/Tableau dapat berukuran ratusan ribu baris dan ber-encoding
 * UTF-16. Karena itu CSV diproses bertahap, bukan dimuat seluruhnya ke memori.
 * Baris sumber yang memiliki kunci sama dijumlahkan sebelum disimpan agar nilai
 * tidak hilang akibat unique key tabel pinjaman.
 */
class PinjamanCsvImportService
{
    use MelaporkanImport;

    private const UKURAN_POTONGAN = 1000;

    /** Nilai pengganti ketika kolom Segmentasi sumber memang kosong. */
    private const SEGMENTASI_KOSONG = 'Tanpa Segmentasi';

    /**
     * @var array<string, list<string>>
     */
    public const ALIAS = [
        'id_cabang' => ['cabang_id', 'kode_cabang', 'cabang'],
        'id_uker' => ['uker_id', 'kode_uker', 'uker'],
        'segmen' => ['segment', 'SEGMEN_2025', 'segmen2025'],
        'segmentasi' => ['Segmentasi'],
        'kualitas' => ['kolektibilitas', 'kol', 'Kualitas Kredit', 'kualitas_kredit'],
        'tanggal' => [
            'tgl',
            'date',
            'periode',
            'posisi',
            'tanggal_posisi',
            'Month, Day, Year of Posisi',
        ],
        'baki_debet' => ['bakidebet', 'Baki Debet', 'baki', 'os', 'outstanding', 'nilai', 'nominal'],
    ];

    /**
     * Header yang ditampilkan pada halaman upload dan dipakai saat ekspor ulang.
     * Urutan mengikuti format sumber terbaru.
     *
     * @var list<string>
     */
    public const KOLOM = [
        'id_cabang',
        'id_uker',
        'SEGMEN_2025',
        'Segmentasi',
        'Kualitas Kredit',
        'Baki Debet',
        'Month, Day, Year of Posisi',
    ];

    /** @var list<string> */
    private const KOLOM_WAJIB = [
        'id_cabang',
        'id_uker',
        'segmen',
        'segmentasi',
        'kualitas',
        'baki_debet',
        'tanggal',
    ];

    /**
     * Validasi berkas tanpa menyimpan ke database.
     *
     * @return array{tanggal:list<string>,baris:int,segmen:list<string>,total:float,laporan:array<string,mixed>}
     */
    public function validasi(string $path, ?string $namaAsli = null): array
    {
        return $this->prosesBerkas(
            $path,
            $namaAsli ?? basename($path),
        );
    }

    /**
     * Impor memakai temporary table tanpa unique key. Setelah seluruh baris
     * valid masuk staging, data digrup dan dijumlahkan lalu di-upsert ke tabel
     * utama. Ini mencegah baris duplikat dalam sumber saling menimpa.
     *
     * @return array{tanggal:list<string>,baris:int,baris_sumber:int,dilewati:int,segmen:list<string>,total:float,laporan:array<string,mixed>}
     */
    public function impor(string $path, ?string $namaAsli = null): array
    {
        $namaBerkas = $namaAsli ?? basename($path);
        $tabelSementara = 'tmp_pinjaman_import_'.bin2hex(random_bytes(6));

        $this->buatTabelSementara($tabelSementara);

        try {
            return DB::transaction(function () use ($path, $namaBerkas, $tabelSementara) {
                $hasil = $this->prosesBerkas(
                    $path,
                    $namaBerkas,
                    function (array $potongan) use ($tabelSementara): void {
                        if ($potongan !== []) {
                            DB::table($tabelSementara)->insert($potongan);
                        }
                    },
                );

                $jumlahTersimpan = 0;

                DB::table($tabelSementara)
                    ->select([
                        'uker_id',
                        'segmen',
                        'segmentasi',
                        'kualitas',
                        'tanggal',
                    ])
                    ->selectRaw('MAX(cabang_id) as cabang_id')
                    ->selectRaw('SUM(baki_debet) as baki_debet')
                    ->selectRaw('MIN(created_at) as created_at')
                    ->selectRaw('MAX(updated_at) as updated_at')
                    ->groupBy('uker_id', 'segmen', 'segmentasi', 'kualitas', 'tanggal')
                    ->orderBy('uker_id')
                    ->orderBy('segmen')
                    ->orderBy('segmentasi')
                    ->orderBy('kualitas')
                    ->orderBy('tanggal')
                    ->chunk(self::UKURAN_POTONGAN, function (Collection $baris) use (&$jumlahTersimpan): void {
                        $data = $baris->map(fn (object $r) => [
                            'cabang_id' => (int) $r->cabang_id,
                            'uker_id' => (int) $r->uker_id,
                            'segmen' => (string) $r->segmen,
                            'segmentasi' => (string) $r->segmentasi,
                            'kualitas' => (string) $r->kualitas,
                            'tanggal' => Carbon::parse($r->tanggal)->toDateString(),
                            'baki_debet' => (float) $r->baki_debet,
                            'created_at' => $r->created_at,
                            'updated_at' => $r->updated_at,
                        ])->all();

                        Pinjaman::query()->upsert(
                            $data,
                            ['uker_id', 'segmen', 'segmentasi', 'kualitas', 'tanggal'],
                            ['cabang_id', 'baki_debet', 'updated_at'],
                        );

                        $jumlahTersimpan += count($data);
                    });

                $hasil['baris_sumber'] = $hasil['baris'];
                $hasil['baris'] = $jumlahTersimpan;
                $hasil['dilewati'] = 0;

                return $hasil;
            });
        } finally {
            Schema::dropIfExists($tabelSementara);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function riwayat(int $batas = 1000): array
    {
        return Pinjaman::query()
            ->groupBy('tanggal')
            ->orderByDesc('tanggal')
            ->limit($batas)
            ->selectRaw('tanggal, COUNT(*) as jumlah_baris, SUM(baki_debet) as total, MAX(updated_at) as diunggah')
            ->get()
            ->map(fn ($r) => [
                'tanggal' => Carbon::parse($r->tanggal)->toDateString(),
                'jumlah_baris' => (int) $r->jumlah_baris,
                'total' => Satuan::toJuta($r->total),
                'diunggah' => $r->diunggah === null ? null : Carbon::parse($r->diunggah)->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function barisUntukUnduh(string $tanggal): array
    {
        return Pinjaman::query()
            ->where('tanggal', Carbon::parse($tanggal)->toDateString())
            ->orderBy('cabang_id')
            ->orderBy('uker_id')
            ->orderBy('segmen')
            ->orderBy('kualitas')
            ->get()
            ->map(fn (Pinjaman $p) => [
                'id_cabang' => $p->cabang_id,
                'id_uker' => $p->uker_id,
                'SEGMEN_2025' => $p->segmen,
                'Segmentasi' => $p->segmentasi === self::SEGMENTASI_KOSONG ? '' : $p->segmentasi,
                'Kualitas Kredit' => $p->kualitas,
                'Baki Debet' => $p->baki_debet,
                'Month, Day, Year of Posisi' => $p->tanggal,
            ])
            ->all();
    }

    public function hapusTanggal(string $tanggal): int
    {
        return Pinjaman::query()->where('tanggal', Carbon::parse($tanggal)->toDateString())->delete();
    }

    public function hapusBulan(int $tahun, int $bulan): int
    {
        $awal = Carbon::create($tahun, $bulan, 1)->startOfMonth();

        return Pinjaman::query()
            ->whereBetween('tanggal', [$awal->toDateString(), $awal->copy()->endOfMonth()->toDateString()])
            ->delete();
    }

    /**
     * Proses sumber secara bertahap. Callback menerima baris valid per potongan
     * dan digunakan oleh proses upload untuk memasukkan data ke staging.
     *
     * @param  null|callable(list<array<string,mixed>>):void  $terimaPotongan
     * @return array{tanggal:list<string>,baris:int,segmen:list<string>,total:float,laporan:array<string,mixed>}
     */
    private function prosesBerkas(string $path, string $namaBerkas, ?callable $terimaPotongan = null): array
    {
        $ukerValid = Uker::query()->pluck('cabang_id', 'id');
        $now = Carbon::now();
        $potongan = collect();
        $adaBaris = false;
        $jumlahValid = 0;
        $total = 0.0;
        $tanggal = [];
        $segmen = [];

        $this->mulaiLaporanImport();

        $prosesPotongan = function () use (
            &$potongan,
            &$jumlahValid,
            &$total,
            &$tanggal,
            &$segmen,
            $ukerValid,
            $now,
            $namaBerkas,
            $terimaPotongan,
        ): void {
            if ($potongan->isEmpty()) {
                return;
            }

            $dipetakan = PetaKolom::petakan(
                $potongan,
                self::ALIAS,
                self::KOLOM_WAJIB,
                $namaBerkas,
            );

            $valid = [];

            foreach ($dipetakan as $i => $r) {
                $nomor = ((int) $i) + 2;
                $this->tambahTotalBarisSumber();

                try {
                    $baris = $this->normalisasiBaris($r, $nomor, $ukerValid, $now);

                    $valid[] = $baris;
                    $jumlahValid++;
                    $total += (float) $baris['baki_debet'];
                    $tanggal[$baris['tanggal']] = true;
                    $segmen[$baris['segmen']] = true;
                } catch (ImportException $e) {
                    $this->catatBarisTidakValid($r, $nomor, $e->getMessage());
                } catch (Throwable $e) {
                    $this->catatBarisTidakValid($r, $nomor, $e->getMessage());
                }
            }

            if ($terimaPotongan !== null && $valid !== []) {
                $terimaPotongan($valid);
            }

            $potongan = collect();
        };

        foreach ($this->barisSumber($path, $namaBerkas) as $i => $baris) {
            $adaBaris = true;
            $potongan->put((int) $i, $baris);

            if ($potongan->count() >= self::UKURAN_POTONGAN) {
                $prosesPotongan();
            }
        }

        $prosesPotongan();

        if (! $adaBaris) {
            throw ImportException::berkas("Berkas {$namaBerkas} tidak berisi baris data.");
        }

        $daftarTanggal = array_keys($tanggal);
        $daftarSegmen = array_keys($segmen);
        sort($daftarTanggal);
        sort($daftarSegmen);

        return [
            'tanggal' => $daftarTanggal,
            'baris' => $jumlahValid,
            'segmen' => $daftarSegmen,
            'total' => $total,
            'laporan' => $this->laporanImport(),
        ];
    }

    /**
     * @return iterable<int, array<string, string>>
     */
    private function barisSumber(string $path, string $namaBerkas): iterable
    {
        $ekstensi = strtolower(pathinfo($namaBerkas, PATHINFO_EXTENSION));

        if (in_array($ekstensi, ['csv', 'txt'], true)) {
            yield from Csv::baris($path, namaAsli: $namaBerkas);

            return;
        }

        foreach (Spreadsheet::baca($path, namaAsli: $namaBerkas) as $i => $baris) {
            yield $i => $baris;
        }
    }

    /**
     * @param  Collection<int, int>  $ukerValid
     * @return array<string, mixed>
     */
    private function normalisasiBaris(array $r, int $nomor, Collection $ukerValid, Carbon $now): array
    {
        $idCabangSumber = trim((string) ($r['id_cabang'] ?? ''));
        $idUkerSumber = trim((string) ($r['id_uker'] ?? ''));
        $segmen = trim((string) ($r['segmen'] ?? ''));
        $segmentasi = trim((string) ($r['segmentasi'] ?? ''));
        $kualitas = trim((string) ($r['kualitas'] ?? ''));

        if ($idCabangSumber === '' || ! ctype_digit($idCabangSumber)) {
            throw ImportException::berkas("Baris {$nomor}: id_cabang '{$idCabangSumber}' tidak valid.");
        }

        if ($idUkerSumber === '' || ! ctype_digit($idUkerSumber)) {
            throw ImportException::berkas("Baris {$nomor}: id_uker '{$idUkerSumber}' tidak valid.");
        }

        $ukerId = (int) $idUkerSumber;

        if (! $ukerValid->has($ukerId)) {
            throw ImportException::berkas("Baris {$nomor}: id_uker '{$ukerId}' tidak ditemukan di master unit kerja.");
        }

        if ($segmen === '') {
            throw ImportException::berkas("Baris {$nomor}: kolom SEGMEN_2025 kosong.");
        }

        // Pada ekspor sumber terdapat baris finansial yang valid tetapi dimensi
        // Segmentasi kosong. Nilainya tetap harus masuk ke total dashboard.
        if ($segmentasi === '') {
            $segmentasi = self::SEGMENTASI_KOSONG;
        }

        if ($kualitas === '') {
            throw ImportException::berkas("Baris {$nomor}: kolom Kualitas Kredit kosong.");
        }

        if (! in_array($kualitas, Pinjaman::KUALITAS, true)) {
            throw ImportException::berkas(
                "Baris {$nomor}: kualitas '{$kualitas}' tidak dikenal. Gunakan: ".implode(', ', Pinjaman::KUALITAS).'.',
            );
        }

        return [
            // Master adalah sumber kebenaran hubungan uker->cabang.
            'cabang_id' => (int) $ukerValid[$ukerId],
            'uker_id' => $ukerId,
            'segmen' => $segmen,
            'segmentasi' => $segmentasi,
            'kualitas' => $kualitas,
            'tanggal' => $this->tanggal($r['tanggal'] ?? '', $nomor),
            'baki_debet' => $this->angka($r['baki_debet'] ?? '', $nomor),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function buatTabelSementara(string $nama): void
    {
        Schema::create($nama, function (Blueprint $table): void {
            $table->temporary();
            $table->unsignedInteger('cabang_id');
            $table->unsignedInteger('uker_id');
            $table->string('segmen', 30);
            $table->string('segmentasi', 50);
            $table->string('kualitas', 10);
            $table->date('tanggal');
            $table->decimal('baki_debet', 20, 2)->default(0);
            $table->timestamps();
            $table->index(
                ['uker_id', 'segmen', 'segmentasi', 'kualitas', 'tanggal'],
                'tmp_pinjaman_group_idx',
            );
        });
    }

    private function tanggal(mixed $nilai, int $nomor): string
    {
        $mentah = trim((string) $nilai);

        if ($mentah === '') {
            throw ImportException::berkas("Baris {$nomor}: kolom tanggal kosong.");
        }

        foreach (['m/d/Y', 'n/j/Y', 'Y-m-d', 'd/m/Y'] as $format) {
            try {
                $tanggal = Carbon::createFromFormat($format, $mentah);

                if ($tanggal !== false && $tanggal->format($format) === $mentah) {
                    return $tanggal->toDateString();
                }
            } catch (Throwable) {
                // Coba format berikutnya.
            }
        }

        try {
            return Carbon::parse($mentah)->toDateString();
        } catch (Throwable) {
            throw ImportException::berkas("Baris {$nomor}: tanggal '{$mentah}' tidak bisa dibaca. Gunakan format MM/DD/YYYY, contoh 01/31/2026.");
        }
    }

    private function angka(mixed $nilai, int $nomor): float
    {
        $bersih = str_replace([' ', ',', "\u{00A0}"], '', trim((string) $nilai));

        if ($bersih === '' || ! is_numeric($bersih)) {
            throw ImportException::berkas("Baris {$nomor}: baki_debet '{$nilai}' bukan angka.");
        }

        return (float) $bersih;
    }
}
