<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RkaPinjaman extends Model
{
    /** Nilai target untuk tab Total OS pada dashboard Pinjaman. */
    public const KUALITAS_OS = 'OS';

    /** @use HasFactory<\Database\Factories\RkaPinjamanFactory> */
    use HasFactory;

    protected $table = 'rka_pinjaman';

    protected $fillable = ['cabang_id', 'uker_id', 'segmen', 'segmentasi', 'kualitas', 'tahun', 'bulan', 'target'];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'target' => 'decimal:2',
        ];
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
