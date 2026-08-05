<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Simpanan extends Model
{
    /** @use HasFactory<\Database\Factories\SimpananFactory> */
    use HasFactory;

    public const PRODUK_TABUNGAN = 'Tabungan';

    public const PRODUK_GIRO = 'Giro';

    public const PRODUK_DEPOSITO = 'Deposito';

    /** @var list<string> */
    public const PRODUK = [self::PRODUK_TABUNGAN, self::PRODUK_GIRO, self::PRODUK_DEPOSITO];

    protected $table = 'simpanan';

    protected $fillable = ['cabang_id', 'uker_id', 'produk', 'segmentasi', 'tanggal', 'saldo'];

    protected function casts(): array
    {
        return [
            'saldo' => 'decimal:2',
        ];
    }

    /**
     * `tanggal` sengaja BUKAN cast `date`.
     *
     * Cast `date` Laravel menulis nilai memakai format 'Y-m-d H:i:s'. Kolom DATE
     * MySQL memotong bagian jamnya, tapi SQLite (typeless, dipakai test) menyimpan
     * apa adanya jadi '2026-03-10 00:00:00' — sehingga
     * `whereIn('tanggal', ['2026-03-10'])` cocok di produksi tapi TIDAK cocok di
     * test. Menormalkan ke string 'Y-m-d' di sini membuat perilakunya identik di
     * kedua driver. Tanggal posisi juga memang tidak punya komponen jam.
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
