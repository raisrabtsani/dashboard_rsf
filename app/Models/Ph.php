<?php

namespace App\Models;

use App\Support\Segmen;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * PH — Pinjaman Hapus buku (write-off), FLOW bulanan.
 *
 * Satu baris = jumlah yang dihapusbukukan selama satu bulan untuk satu
 * (uker, segmen). `periode` = tanggal akhir bulan, dipakai sebagai penanda bulan.
 */
class Ph extends Model
{
    /** @use HasFactory<\Database\Factories\PhFactory> */
    use HasFactory;

    /** @var list<string> */
    public const SEGMEN = Segmen::SEMUA;

    protected $table = 'ph';

    protected $fillable = ['cabang_id', 'uker_id', 'segmen', 'periode', 'saldo'];

    protected function casts(): array
    {
        return ['saldo' => 'decimal:2'];
    }

    /**
     * `periode` disimpan & dibaca sebagai string 'Y-m-d'.
     *
     * Bukan cast `date`: cast itu menulis 'Y-m-d H:i:s', yang dipotong MySQL
     * tapi disimpan utuh oleh SQLite — bikin perbandingan tanggal cocok di
     * produksi dan gagal di test. Lihat CLAUDE.md §8.
     */
    protected function periode(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $v): ?string => $v === null ? null : Carbon::parse($v)->toDateString(),
            // Selalu dinormalkan ke AKHIR BULAN: berkas sumber kadang memakai
            // tanggal 1 atau tanggal kejadian, padahal maknanya "bulan ini".
            set: fn (mixed $v): ?string => $v === null ? null : Carbon::parse($v)->endOfMonth()->toDateString(),
        );
    }

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function uker(): BelongsTo
    {
        return $this->belongsTo(Uker::class);
    }
}
