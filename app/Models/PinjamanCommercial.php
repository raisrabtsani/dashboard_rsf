<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Pinjaman segmen Commercial — struktur mencerminkan App\Models\Pinjaman
 * (berdimensi kualitas Lancar/SML/NPL, lihat Pinjaman::KUALITAS). Dipisah
 * karena upload & RKA-nya terpisah dan hanya dipakai di halaman PRESENT.
 */
class PinjamanCommercial extends Model
{
    /** @use HasFactory<\Database\Factories\PinjamanCommercialFactory> */
    use HasFactory;

    protected $table = 'pinjaman_commercial';

    protected $fillable = ['cabang_id', 'uker_id', 'segmen', 'segmentasi', 'kualitas', 'tanggal', 'baki_debet'];

    protected function casts(): array
    {
        return [
            'baki_debet' => 'decimal:2',
        ];
    }

    /**
     * `tanggal` sengaja BUKAN cast `date` — lihat App\Models\Simpanan.
     */
    protected function tanggal(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString(),
            set: fn (mixed $value): ?string => $value === null ? null : Carbon::parse($value)->toDateString(),
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
