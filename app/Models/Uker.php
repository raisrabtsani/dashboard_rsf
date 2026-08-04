<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Support\Str;

class Uker extends Model
{
    public const TIPE_BO = 'BO';

    public const TIPE_SBO = 'SBO';

    public const TIPE_UNIT = 'UNIT';

    public const TIPE_KK = 'KK';

    /** Hanya dipakai baris bayangan rollup Region Office (855). */
    public const TIPE_REGION = 'REGION';

    protected $table = 'uker';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'cabang_id', 'nama', 'tipe'];

    public function cabang(): BelongsTo
    {
        return $this->belongsTo(Cabang::class);
    }

    public function area(): HasOneThrough
    {
        return $this->hasOneThrough(Area::class, Cabang::class, 'id', 'id', 'cabang_id', 'area_id');
    }

    public function region(): HasOneThrough
    {
        return $this->hasOneThrough(Region::class, Cabang::class, 'id', 'id', 'cabang_id', 'region_id');
    }

    /**
     * Turunkan tipe uker dari id dan namanya.
     *
     * Sumber data tidak punya kolom tipe, jadi tipe diturunkan dari konvensi
     * penamaan BRI di code_uker.csv. Urutan pemeriksaan penting: baris BO
     * dikenali dari id_uker == id_cabang, bukan dari namanya, karena nama BO
     * ("BO Dumai") juga mengandung awalan yang bisa tertukar.
     */
    public static function tipeDari(int $idUker, int $idCabang, string $namaUker): ?string
    {
        if ($idUker === $idCabang) {
            return self::TIPE_BO;
        }

        $nama = trim($namaUker);

        return match (true) {
            Str::startsWith($nama, 'SBO ') => self::TIPE_SBO,
            Str::startsWith($nama, 'Unit ') => self::TIPE_UNIT,
            Str::startsWith($nama, 'KK ') => self::TIPE_KK,
            Str::contains($nama, 'SBO', ignoreCase: true) => self::TIPE_SBO,
            Str::contains($nama, 'Unit', ignoreCase: true) => self::TIPE_UNIT,
            Str::contains($nama, 'KK', ignoreCase: true) => self::TIPE_KK,
            default => null,
        };
    }

    /**
     * Buang baris bayangan rollup Region Office (855) dari hasil.
     */
    public function scopeTanpaRegionOffice(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('id'), '!=', Region::OFFICE_ID);
    }
}
