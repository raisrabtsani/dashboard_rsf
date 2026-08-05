<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RkaQris extends Model
{
    /** @use HasFactory<\Database\Factories\RkaQrisFactory> */
    use HasFactory;

    protected $table = 'rka_qris';

    protected $fillable = ['cabang_id', 'uker_id', 'kpi', 'tahun', 'bulan', 'target'];

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
