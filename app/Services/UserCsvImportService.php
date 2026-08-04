<?php

namespace App\Services;

use App\Models\User;
use App\Support\Csv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
            // RO = 855/855, BO id_cabang == id_uker, uker pakai id_uker-nya.
            'cabang_id' => (int) trim($r['id_cabang']),
            'uker_id' => (int) trim($r['id_uker']),
            'password_plain' => trim($r['Password']) ?: self::PASSWORD_DEFAULT,
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
