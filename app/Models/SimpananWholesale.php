<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * DPK segmen Wholesale — struktur mencerminkan App\Models\Simpanan. Produknya
 * sama (Tabungan/Giro/Deposito, lihat Simpanan::PRODUK); dipisah karena
 * upload & RKA-nya terpisah dan hanya dipakai di halaman PRESENT.
 */
class SimpananWholesale extends Model
{
    /** @use HasFactory<\Database\Factories\SimpananWholesaleFactory> */
    use HasFactory;

    protected $table = 'simpanan_wholesale';

    protected $fillable = ['cabang_id', 'uker_id', 'produk', 'segmentasi', 'tanggal', 'saldo'];

    protected function casts(): array
    {
        return [
            'saldo' => 'decimal:2',
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
