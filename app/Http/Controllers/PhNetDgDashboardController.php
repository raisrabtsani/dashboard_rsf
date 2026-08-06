<?php

namespace App\Http\Controllers;

use App\Services\PhNetDgService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller TIPIS untuk halaman gabungan PH & Net DG.
 *
 * Toggle mode (ph|netdg) dibaca dari request seperti filter lain; seluruh
 * kalkulasi ada di PhNetDgService.
 */
class PhNetDgDashboardController extends Controller
{
    public function __construct(private readonly PhNetDgService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('RecoveryPh/Index', [
            'periodeAwal' => $this->periode($request),
            'modeAwal' => $this->mode($request),
            'scope' => PhNetDgService::scope(),
        ]);
    }

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

        return response()->json($this->service->snapshot(
            $this->mode($request),
            $this->periode($request),
            $areaId,
            $cabangId,
            $ukerId,
        ));
    }

    public function chart(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->chart(
            $this->mode($request),
            $this->periode($request),
            $areaId,
            $cabangId,
            $ukerId,
        ));
    }


    public function branchPencapaian(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->branchPencapaian(
            $this->mode($request),
            $this->periode($request),
            $areaId,
            $cabangId,
            $ukerId,
            $request->string('scope')->toString(),
        ));
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

    private function mode(Request $request): string
    {
        $mode = $request->string('mode')->toString();

        return in_array($mode, PhNetDgService::MODE, true) ? $mode : PhNetDgService::MODE_PH;
    }

    private function periode(Request $request): string
    {
        $periode = $request->date('periode')
            ?? ($this->service->periodeTerakhir() === null
                ? null
                : Carbon::parse($this->service->periodeTerakhir()));

        return ($periode ?? Carbon::today())->endOfMonth()->toDateString();
    }
}
