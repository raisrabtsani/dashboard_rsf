<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Laba — domain BULANAN. Nilai `laba` tersimpan KUMULATIF YTD (rupiah penuh,
 * bisa negatif). Tidak ada kolom tanggal; periode = (tahun, bulan).
 *
 * Dimensi segmen domain ini adalah UKO (jenis kantor operasional): Branch
 * Office / Sub-Branch Office / Micro / Region Office. Label uko diambil APA
 * ADANYA dari kolom `uko` berkas unggahan (bukan diturunkan dari tipe uker di
 * master) — pelabelan bisnis tidak selalu sama dengan tipe master, mis. KCP IPB
 * (tipe UNIT di master) dilaporkan sebagai "Sub-Branch Office". Kolom DB tetap
 * bernama `segmen` (dimensi segmen generik yang dipakai service & frontend).
 */
class Laba extends Model
{
    /** @use HasFactory<\Database\Factories\LabaFactory> */
    use HasFactory;

    /**
     * Urutan tampil kartu segmen (uko). HANYA untuk pengurutan — nilai segmen
     * dipakai apa adanya dari data. Segmen di luar daftar ini tetap tampil,
     * ditaruh di akhir.
     *
     * @var list<string>
     */
    public const SEGMEN = ['Branch Office', 'Sub-Branch Office', 'Micro', 'Region Office'];

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
