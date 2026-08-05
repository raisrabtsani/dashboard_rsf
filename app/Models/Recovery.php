<?php

namespace App\Models;

use App\Support\Segmen;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Recovery — penagihan atas kredit yang sudah dihapusbukukan.
 *
 * Nilainya `actual` (rupiah penuh), berdimensi SEGMEN. Tidak ada dimensi produk.
 */
class Recovery extends Model
{
    /** @use HasFactory<\Database\Factories\RecoveryFactory> */
    use HasFactory;

    /*
     * Taksonomi segmen tinggal DELEGASI ke App\Support\Segmen — sumbernya satu,
     * dipakai bersama Recovery, PH, dan Net DG. Konstanta di bawah dipertahankan
     * karena namanya sudah bermakna di konteks Recovery.
     */

    public const SEGMEN_MICRO = Segmen::MICRO;

    public const SEGMEN_SME = Segmen::SME;

    public const SEGMEN_CONSUMER = Segmen::CONSUMER;

    /** Segmen KANONIK — urutan ini juga dipakai untuk mengurutkan kartu. */
    public const SEGMEN = Segmen::SEMUA;

    /** @var array<string, list<string>> */
    public const SEGMEN_RAW = Segmen::RAW;

    protected $table = 'recovery';

    protected $fillable = ['cabang_id', 'uker_id', 'segmen', 'tanggal', 'actual'];

    /**
     * Lipat satu nilai segmen mentah ke segmen kanonik.
     *
     * Nilai yang tidak dikenal dikembalikan APA ADANYA (bukan dibuang) supaya
     * taksonomi baru yang belum terpetakan tetap muncul di dashboard dan mudah
     * ketahuan, bukan hilang diam-diam.
     */
    public static function kanonik(string $segmen): string
    {
        return Segmen::kanonik($segmen) ?? trim($segmen);
    }

    protected function casts(): array
    {
        return [
            'actual' => 'decimal:2',
        ];
    }

    /**
     * `tanggal` sengaja BUKAN cast `date` — lihat App\Models\Simpanan untuk
     * alasannya (cast date menulis 'Y-m-d H:i:s'; MySQL memotong jamnya, SQLite
     * tidak, sehingga query tanggal berperilaku beda di produksi vs test).
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
