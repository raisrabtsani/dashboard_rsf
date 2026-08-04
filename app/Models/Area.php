<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class Area extends Model
{
    protected $table = 'areas';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = ['id', 'nama'];

    public function cabang(): HasMany
    {
        return $this->hasMany(Cabang::class);
    }

    public function uker(): HasManyThrough
    {
        return $this->hasManyThrough(Uker::class, Cabang::class, 'area_id', 'cabang_id');
    }
}
