<?php

namespace App\Services;

use App\Exceptions\ImportException;
use App\Models\Cabang;
use App\Models\Region;
use App\Models\Uker;
use App\Models\User;
use App\Support\Csv;
use App\Support\PetaKolom;
use App\Support\Spreadsheet;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Sumber tunggal pembacaan & normalisasi user.csv.
 *
 * Dipakai dua pemanggil dengan perilaku berbeda:
 *  - UserSeeder      : truncate + bulk insert (inisialisasi awal saja)
 *  - command users:sync : upsert tanpa truncate, password lama tidak diubah
 *
 * Keduanya WAJIB lewat service ini supaya aturan normalisasi (tipe, role,
 * password) tidak bercabang dua.
 */
class UserCsvImportService
{
    /**
     * Dipakai hanya bila kolom Password di CSV kosong.
     *
     * Password seragam adalah risiko yang diketahui — user wajib menggantinya
     * setelah login pertama.
     */
    public const PASSWORD_DEFAULT = 'RSF12345';

    public const FILE = 'user.csv';

    private const KOLOM = ['id_cabang', 'id_uker', 'User', 'Nama', 'Type Uker', 'Role', 'Password'];

    public const KOLOM_UPLOAD = ['id_region', 'id_cabang', 'id_uker', 'User', 'Nama', 'Type Uker', 'Role', 'Password'];

    private const ALIAS_UPLOAD = [
        'id_region' => ['region', 'region_id'],
        'id_cabang' => ['cabang', 'cabang_id'],
        'id_uker' => ['uker', 'uker_id'],
        'User' => ['username', 'user id'],
        'Nama' => ['name', 'nama user'],
        'Type Uker' => ['tipe', 'tipe uker', 'type_uker'],
        'Role' => ['peran'],
        'Password' => ['kata sandi'],
    ];

    /**
     * Normalisasi "Type Uker" di CSV ke nilai kolom users.tipe.
     *
     * Kuncinya sudah di-uppercase; pencocokan case-insensitive.
     */
    private const PETA_TIPE = [
        'RO' => User::TIPE_RO,
        'BO' => User::TIPE_BO,
        'SBO' => User::TIPE_SBO,
        'UNIT' => User::TIPE_UNIT,
        'KANTOR KAS' => User::TIPE_KK,
        'KK' => User::TIPE_KK,
    ];

    /**
     * Baca CSV dan ubah jadi baris siap simpan (password masih plain).
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function baris(?string $path = null): Collection
    {
        return Csv::baca($path ?? $this->path(), self::KOLOM)
            ->map(fn (array $r) => $this->normalkan($r));
    }

    /**
     * Inisialisasi awal: kosongkan tabel users lalu bulk insert dari CSV.
     *
     * BERBAHAYA di produksi — menghapus semua akun beserta password yang sudah
     * diganti user. Untuk pemutakhiran produksi pakai sync().
     *
     * @return int jumlah user yang dibuat
     */
    public function seedMassal(?string $path = null): int
    {
        $baris = $this->baris($path);

        User::query()->truncate();

        $this->simpan($baris, fn (Collection $potongan) => User::query()->insert($potongan->all()));

        return $baris->count();
    }

    /**
     * Pemutakhiran produksi: upsert per username, TANPA truncate.
     *
     * Password hanya dipakai untuk baris yang benar-benar baru — kolom password
     * sengaja tidak masuk daftar kolom yang di-update, sehingga password user
     * lama (yang mungkin sudah diganti sendiri) tidak tersentuh.
     *
     * @return array{baru: int, diperbarui: int}
     */
    public function sync(?string $path = null): array
    {
        $baris = $this->baris($path);

        return $this->syncBaris($baris);
    }

    /**
     * Impor dari panel Admin. Mendukung CSV/Excel dan memvalidasi hubungan
     * region -> cabang -> unit kerja sebelum satu pun user disimpan.
     *
     * Password hanya diterapkan pada username baru. Password akun yang sudah
     * ada tidak ditimpa oleh berkas impor.
     *
     * @return array{baru: int, diperbarui: int}
     */
    public function syncUpload(UploadedFile $file): array
    {
        $nama = $file->getClientOriginalName();
        $sumber = Spreadsheet::baca($file->getRealPath(), [], $nama);
        $sumber = PetaKolom::petakan($sumber, self::ALIAS_UPLOAD, self::KOLOM_UPLOAD, $nama);

        $region = Region::query()->pluck('id')->map(fn ($id) => (int) $id)->flip();
        $cabang = Cabang::query()->get(['id', 'region_id'])->keyBy(fn (Cabang $item) => (int) $item->id);
        $uker = Uker::query()->get(['id', 'cabang_id'])->keyBy(fn (Uker $item) => (int) $item->id);
        $usernameTerlihat = [];

        $baris = $sumber->map(function (array $r, int $index) use ($region, $cabang, $uker, &$usernameTerlihat) {
            $nomor = $index + 2;
            $hasil = $this->normalkanUpload($r, $nomor);
            $idRegion = (int) $r['id_region'];
            $idCabang = (int) $hasil['cabang_id'];
            $idUker = (int) $hasil['uker_id'];
            $kunciUsername = mb_strtolower($hasil['username']);

            if (isset($usernameTerlihat[$kunciUsername])) {
                throw ImportException::berkas("Baris {$nomor}: username {$hasil['username']} duplikat dengan baris {$usernameTerlihat[$kunciUsername]}.");
            }
            $usernameTerlihat[$kunciUsername] = $nomor;

            if (! $region->has($idRegion)) {
                throw ImportException::berkas("Baris {$nomor}: id_region {$idRegion} tidak ditemukan di master region.");
            }

            /** @var Cabang|null $masterCabang */
            $masterCabang = $cabang->get($idCabang);
            if ($masterCabang === null) {
                throw ImportException::berkas("Baris {$nomor}: id_cabang {$idCabang} tidak ditemukan di master kantor.");
            }
            if ((int) $masterCabang->region_id !== $idRegion) {
                throw ImportException::berkas("Baris {$nomor}: id_cabang {$idCabang} bukan bagian dari id_region {$idRegion}.");
            }

            /** @var Uker|null $masterUker */
            $masterUker = $uker->get($idUker);
            if ($masterUker === null) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$idUker} tidak ditemukan di master kantor.");
            }
            if ((int) $masterUker->cabang_id !== $idCabang) {
                throw ImportException::berkas("Baris {$nomor}: id_uker {$idUker} bukan bagian dari id_cabang {$idCabang}.");
            }

            return $hasil;
        });

        return DB::transaction(fn () => $this->syncBaris($baris));
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $baris
     * @return array{baru: int, diperbarui: int}
     */
    private function syncBaris(Collection $baris): array
    {
        if ($baris->isEmpty()) {
            throw ImportException::berkas('Berkas user tidak memiliki baris data.');
        }

        $sudahAda = User::query()
            ->whereIn('username', $baris->pluck('username'))
            ->pluck('username')
            ->all();

        $this->simpan($baris, fn (Collection $potongan) => User::query()->upsert(
            $potongan->all(),
            ['username'],
            // 'password' SENGAJA tidak ada di sini.
            ['name', 'role', 'tipe', 'cabang_id', 'uker_id', 'updated_at'],
        ));

        return [
            'baru' => $baris->count() - count($sudahAda),
            'diperbarui' => count($sudahAda),
        ];
    }

    public function path(): string
    {
        return database_path('seeders/data/'.self::FILE);
    }

    /**
     * @param  array<string, string>  $r
     * @return array<string, mixed>
     */
    private function normalkan(array $r): array
    {
        $username = trim($r['User']);

        if ($username === '') {
            throw new RuntimeException('Ada baris di '.self::FILE.' tanpa kolom User (username).');
        }

        return [
            'username' => $username,
            'name' => trim($r['Nama']),
            'email' => null,
            'role' => $this->role($r['Role']),
            'tipe' => $this->tipe($r['Type Uker'], $username),
            // Diambil apa adanya dari file — sudah benar di sumbernya:
            // RO = kantor Region, BO id_cabang == id_uker, unit memakai id_uker.
            'cabang_id' => (int) trim($r['id_cabang']),
            'uker_id' => (int) trim($r['id_uker']),
            'password_plain' => trim($r['Password']) ?: self::PASSWORD_DEFAULT,
        ];
    }

    /**
     * @param  array<string, string>  $r
     * @return array<string, mixed>
     */
    private function normalkanUpload(array $r, int $nomor): array
    {
        foreach (['id_region', 'id_cabang', 'id_uker'] as $kolom) {
            $nilai = trim((string) ($r[$kolom] ?? ''));
            if (! ctype_digit($nilai) || (int) $nilai < 1) {
                throw ImportException::berkas("Baris {$nomor}: {$kolom} wajib berupa angka positif.");
            }
        }

        $username = trim((string) ($r['User'] ?? ''));
        $nama = trim((string) ($r['Nama'] ?? ''));
        $password = trim((string) ($r['Password'] ?? ''));
        $roleMentah = trim((string) ($r['Role'] ?? ''));

        if ($username === '' || $nama === '') {
            throw ImportException::berkas("Baris {$nomor}: User dan Nama wajib diisi.");
        }
        if (mb_strlen($username) > 255 || mb_strlen($nama) > 255) {
            throw ImportException::berkas("Baris {$nomor}: User dan Nama maksimal 255 karakter.");
        }
        if (! in_array(mb_strtolower($roleMentah), ['admin', 'user'], true)) {
            throw ImportException::berkas("Baris {$nomor}: Role '{$roleMentah}' tidak valid. Gunakan Admin atau User.");
        }
        if ($password !== '' && mb_strlen($password) < 8) {
            throw ImportException::berkas("Baris {$nomor}: Password minimal 8 karakter.");
        }

        try {
            $tipe = $this->tipe((string) ($r['Type Uker'] ?? ''), $username);
        } catch (RuntimeException $e) {
            throw ImportException::berkas("Baris {$nomor}: {$e->getMessage()}");
        }

        return [
            'username' => $username,
            'name' => $nama,
            'email' => null,
            'role' => mb_strtolower($roleMentah) === 'admin' ? User::ROLE_ADMIN : User::ROLE_USER,
            'tipe' => $tipe,
            'cabang_id' => (int) $r['id_cabang'],
            'uker_id' => (int) $r['id_uker'],
            'password_plain' => $password ?: self::PASSWORD_DEFAULT,
        ];
    }

    private function role(string $mentah): string
    {
        return strcasecmp(trim($mentah), 'Admin') === 0 ? User::ROLE_ADMIN : User::ROLE_USER;
    }

    private function tipe(string $mentah, string $username): string
    {
        $kunci = strtoupper(trim($mentah));

        // Gagal keras: tipe menentukan access_level. Menebak di sini sama saja
        // menebak seberapa banyak data yang boleh dilihat orang.
        return self::PETA_TIPE[$kunci]
            ?? throw new RuntimeException("Type Uker '{$mentah}' (user {$username}) tidak dikenali di ".self::FILE.'.');
    }

    /**
     * Hitung hash SEKALI per password unik, lalu simpan per potongan.
     *
     * Semua baris memakai password yang sama, jadi ini memangkas ratusan
     * pemanggilan bcrypt (masing-masing ratusan milidetik) jadi satu.
     *
     * @param  Collection<int, array<string, mixed>>  $baris
     * @param  callable(Collection<int, array<string, mixed>>): mixed  $tulis
     */
    private function simpan(Collection $baris, callable $tulis): void
    {
        $now = Carbon::now();

        $hash = $baris
            ->pluck('password_plain')
            ->unique()
            ->mapWithKeys(fn (string $plain) => [$plain => Hash::make($plain)]);

        $baris
            ->map(function (array $b) use ($hash, $now) {
                $b['password'] = $hash[$b['password_plain']];
                unset($b['password_plain']);

                return $b + ['created_at' => $now, 'updated_at' => $now];
            })
            ->chunk(500)
            ->each($tulis);
    }
}
