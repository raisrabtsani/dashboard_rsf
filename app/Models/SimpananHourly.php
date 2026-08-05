<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Posisi DPK per JAM pada tanggal akhir bulan.
 *
 * Dimensi & produknya sama persis dengan model Simpanan; yang membedakan hanya
 * kolom `jam`. Konstanta produk sengaja DELEGASI ke Simpanan supaya kedua tabel
 * tidak pernah punya daftar produk yang berbeda.
 */
class SimpananHourly extends Model
{
    /** @use HasFactory<\Database\Factories\SimpananHourlyFactory> */
    use HasFactory;

    /** @var list<string> */
    public const PRODUK = Simpanan::PRODUK;

    protected $table = 'simpanan_hourly';

    protected $fillable = ['cabang_id', 'uker_id', 'produk', 'segmentasi', 'tanggal', 'jam', 'saldo'];

    protected function casts(): array
    {
        return [
            'jam' => 'integer',
            'saldo' => 'decimal:2',
        ];
    }

    /**
     * `tanggal` disimpan & dibaca sebagai string 'Y-m-d' — alasan yang sama
     * dengan model Simpanan (cast `date` menulis 'Y-m-d H:i:s' yang dipotong
     * MySQL tapi disimpan utuh SQLite). Lihat CLAUDE.md §8.
     */
    protected function tanggal(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $v): ?string => $v === null ? null : Carbon::parse($v)->toDateString(),
            set: fn (mixed $v): ?string => $v === null ? null : Carbon::parse($v)->toDateString(),
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
