<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Data merchant EDC per KPI. Semantik nilai (stok vs flow) & satuan ditentukan
 * katalog KPI di App\Services\EdcService, bukan di model ini.
 */
class Edc extends Model
{
    /** @use HasFactory<\Database\Factories\EdcFactory> */
    use HasFactory;

    protected $table = 'edc';

    protected $fillable = ['cabang_id', 'uker_id', 'kpi', 'tanggal', 'actual'];

    protected function casts(): array
    {
        return [
            'actual' => 'decimal:2',
        ];
    }

    /**
     * `tanggal` sengaja BUKAN cast `date` — lihat App\Models\Simpanan untuk
     * alasannya (perbedaan penulisan jam MySQL vs SQLite di test).
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
