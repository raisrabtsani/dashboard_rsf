<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Region extends Model
{
    /**
     * ID entitas rollup Region Office — SUMBER TUNGGAL id KANWIL.
     *
     * Data Region 7 Jakarta 2 memakai id 317 (KANWIL JAKARTA 2). Nilai ini juga
     * dipakai untuk baris bayangan di tabel `cabang` & `uker` agar data level
     * Region (mis. Pinjaman segmen Medium) lolos validasi foreign key, lalu
     * disembunyikan dari dropdown & tabel lewat scope `tanpaRegionOffice()`.
     *
     * Jangan menulis id ini sebagai angka literal di tempat lain — selalu rujuk
     * konstanta ini. (Kalau data region berganti, ubah cukup di sini.)
     */
    public const OFFICE_ID = 317;

    protected $table = 'region';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'nama'];

    public function cabang(): HasMany
    {
        return $this->hasMany(Cabang::class);
    }

    public function uker(): HasManyThrough
    {
        return $this->hasManyThrough(Uker::class, Cabang::class, 'region_id', 'cabang_id');
    }
}
