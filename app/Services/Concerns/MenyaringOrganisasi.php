<?php

namespace App\Services\Concerns;

use App\Models\Cabang;
use App\Models\Uker;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Builder;

/**
 * Penyaringan dimensi organisasi yang sama persis di semua domain dashboard.
 *
 * Dipakai bersama supaya opsi dropdown & aturan filter Area→Cabang→Uker tidak
 * bercabang beda-beda per domain. Yang BOLEH berbeda per domain hanyalah
 * perlakuan rollup 855 — itu ditentukan di query dasar masing-masing service.
 */
trait MenyaringOrganisasi
{
    /**
     * @return list<array{id: int, nama: string}>
     */
    public function cabangPerArea(?int $areaId): array
    {
        return Cabang::query()
            ->tanpaRegionOffice()
            ->when($areaId !== null, fn (Builder $q) => $q->where('area_id', $areaId))
            ->orderBy('nama')
            ->get(['id', 'nama'])
            ->toArray();
    }

    /**
     * @return list<array{id: int, nama: string, tipe: string}>
     */
    public function ukerPerCabang(int $cabangId): array
    {
        return Uker::query()
            ->tanpaRegionOffice()
            ->where('cabang_id', $cabangId)
            ->orderBy('nama')
            ->get(['id', 'nama', 'tipe'])
            ->toArray();
    }

    /**
     * Subquery id cabang dalam satu area.
     */
    protected function cabangDiArea(int $areaId): BuilderContract
    {
        return Cabang::query()->where('area_id', $areaId)->select('id')->getQuery();
    }

    /**
     * Terapkan filter Area/Cabang/Uker pada query mana pun yang punya kolom
     * `cabang_id` dan `uker_id`.
     */
    protected function filterOrganisasi(Builder $query, ?int $areaId, ?int $cabangId, ?int $ukerId): Builder
    {
        return $query
            ->when($areaId !== null, fn (Builder $q) => $q->whereIn('cabang_id', $this->cabangDiArea($areaId)))
            ->when($cabangId !== null, fn (Builder $q) => $q->where('cabang_id', $cabangId))
            ->when($ukerId !== null, fn (Builder $q) => $q->where('uker_id', $ukerId));
    }
}
