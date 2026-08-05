<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Kegagalan import yang membawa status HTTP-nya sendiri.
 *
 * Membedakan dua hal yang tampak mirip di UI tapi butuh tindakan berbeda:
 *  - 422 berkas()  : isi/format berkasnya salah  -> perbaiki berkasnya
 *  - 409 bentrok() : berkasnya benar tapi datanya sudah ada -> hapus dulu
 *
 * Sebelumnya semuanya dibalas 409 sehingga admin tidak bisa membedakan
 * "file saya salah" dari "tanggalnya sudah ada".
 */
class ImportException extends RuntimeException
{
    private function __construct(string $message, public readonly int $status)
    {
        parent::__construct($message);
    }

    /** Berkas cacat: kolom kurang, nilai tidak valid, format tak didukung. */
    public static function berkas(string $pesan): self
    {
        return new self($pesan, 422);
    }

    /** Berkas valid, tapi bentrok dengan data yang sudah ada. */
    public static function bentrok(string $pesan): self
    {
        return new self($pesan, 409);
    }
}
