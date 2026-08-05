<?php

namespace App\Http\Controllers;

use App\Services\MerchantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Basis controller API merchant — TIPIS. EdcController & QrisController hanya
 * menyuntikkan service konkretnya; seluruh logika ada di MerchantService.
 *
 * Filter dibaca dari Request supaya otomatis terkunci middleware `scope`.
 */
abstract class MerchantApiController extends Controller
{
    public function __construct(protected readonly MerchantService $service) {}

    public function filterOptions(Request $request): JsonResponse
    {
        return response()->json($this->service->filterOptions(
            $request->integer('area_id') ?: null,
            $request->integer('cabang_id') ?: null,
        ));
    }

    public function snapshot(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json(
            $this->service->snapshot($this->tanggal($request), $areaId, $cabangId, $ukerId),
        );
    }

    public function chart(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json(
            $this->service->chart($this->tanggal($request), $this->kpi($request), $areaId, $cabangId, $ukerId),
        );
    }

    public function branchPencapaian(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json(
            $this->service->branchPencapaian($this->tanggal($request), $this->kpi($request), $areaId, $cabangId, $ukerId),
        );
    }

    public function cabang(int $areaId): JsonResponse
    {
        return response()->json($this->service->cabangPerArea($areaId));
    }

    public function uker(int $cabangId): JsonResponse
    {
        return response()->json($this->service->ukerPerCabang($cabangId));
    }

    /**
     * @return array{0: int|null, 1: int|null, 2: int|null}
     */
    private function filterId(Request $request): array
    {
        return [
            $request->integer('area_id') ?: null,
            $request->integer('cabang_id') ?: null,
            $request->integer('uker_id') ?: null,
        ];
    }

    /** KPI kosong dibiarkan; service jatuh ke KPI pertama katalog. */
    private function kpi(Request $request): string
    {
        return $request->string('kpi')->toString();
    }

    private function tanggal(Request $request): string
    {
        $tanggal = $request->date('tanggal')
            ?? ($this->service->tanggalTerakhir() === null ? null : Carbon::parse($this->service->tanggalTerakhir()));

        return ($tanggal ?? Carbon::today())->toDateString();
    }
}
