<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class Pinjaman extends Model
{
    /** @use HasFactory<\Database\Factories\PinjamanFactory> */
    use HasFactory;

    public const KUALITAS_LANCAR = 'Lancar';

    public const KUALITAS_SML = 'SML';

    public const KUALITAS_NPL = 'NPL';

    /** OS = jumlah ketiganya. */
    public const KUALITAS = [self::KUALITAS_LANCAR, self::KUALITAS_SML, self::KUALITAS_NPL];

    /**
     * Urutan tampil segmen (kartu & tabel), mengikuti data aktual Region 7:
     * Micro / Small / Consumer / Medium. HANYA untuk pengurutan — segmen dipakai
     * apa adanya dari data; yang di luar daftar ini tetap tampil di akhir.
     *
     * @var list<string>
     */
    public const SEGMEN = ['Micro', 'Small', 'Consumer', 'Medium'];

    protected $table = 'pinjaman';

    protected $fillable = ['cabang_id', 'uker_id', 'segmen', 'segmentasi', 'kualitas', 'tanggal', 'baki_debet'];

    protected function casts(): array
    {
        return [
            'baki_debet' => 'decimal:2',
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
