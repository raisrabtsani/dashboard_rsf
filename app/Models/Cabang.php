<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Cabang extends Model
{
    protected $table = 'cabang';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'region_id', 'area_id', 'nama'];

    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function uker(): HasMany
    {
        return $this->hasMany(Uker::class);
    }

    /**
     * Buang baris bayangan rollup Region Office (855) dari hasil.
     *
     * Dipakai untuk semua dropdown BO dan tabel "Kinerja Cabang": 855 bukan
     * cabang sungguhan, hanya penampung data kelolaan Region.
     */
    public function scopeTanpaRegionOffice(Builder $query): Builder
    {
        return $query->where($this->qualifyColumn('id'), '!=', Region::OFFICE_ID);
    }
}
