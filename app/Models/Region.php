<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Region extends Model
{
    /**
     * ID entitas rollup Region Office — SUMBER TUNGGAL angka 855.
     *
     * Selain jadi baris di tabel `region`, id ini juga dibuatkan baris bayangan
     * di tabel `cabang` dan `uker` supaya data yang dikelola di level Region
     * (mis. Pinjaman segmen Medium) lolos validasi foreign key. Baris bayangan
     * itu disembunyikan dari dropdown & tabel tampilan — lihat scope
     * `tanpaRegionOffice()` di model Cabang dan Uker.
     *
     * Jangan menulis 855 sebagai angka literal di tempat lain.
     */
    public const OFFICE_ID = 855;

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
