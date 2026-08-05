<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laba — domain BULANAN. Nilai `laba` tersimpan KUMULATIF YTD (rupiah penuh,
 * bisa negatif). Tidak ada kolom tanggal; periode = (tahun, bulan).
 */
class Laba extends Model
{
    /** @use HasFactory<\Database\Factories\LabaFactory> */
    use HasFactory;

    /**
     * Urutan tampil kartu segmen. HANYA untuk pengurutan — segmen dipakai apa
     * adanya dari data (tidak ada normalisasi taksonomi seperti Recovery).
     * Segmen di luar daftar ini tetap tampil, ditaruh di akhir.
     *
     * @var list<string>
     */
    public const SEGMEN = ['Micro', 'SME', 'Consumer'];

    protected $table = 'laba';

    protected $fillable = ['cabang_id', 'uker_id', 'segmen', 'tahun', 'bulan', 'laba'];

    protected function casts(): array
    {
        return [
            'tahun' => 'integer',
            'bulan' => 'integer',
            'laba' => 'decimal:2',
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
