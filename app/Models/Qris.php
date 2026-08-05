<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** Data merchant QRIS per KPI — struktur identik dengan App\Models\Edc. */
class Qris extends Model
{
    /** @use HasFactory<\Database\Factories\QrisFactory> */
    use HasFactory;

    protected $table = 'qris';

    protected $fillable = ['cabang_id', 'uker_id', 'kpi', 'tanggal', 'actual'];

    protected function casts(): array
    {
        return [
            'actual' => 'decimal:2',
        ];
    }

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
