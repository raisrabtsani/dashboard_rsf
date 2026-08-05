<?php

namespace App\Http\Controllers;

use App\Services\SimpananService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller TIPIS — hanya parse request lalu delegasi ke SimpananService.
 *
 * Tidak boleh ada query, kalkulasi, atau pemanggilan Model di sini.
 * Filter dibaca dari Request supaya otomatis terkunci middleware `scope`.
 */
class SimpananDashboardController extends Controller
{
    public function __construct(private readonly SimpananService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('SimpananDashboard/Index', [
            'tanggalAwal' => $this->tanggal($request),
            'filterAwal' => $this->filter($request),
        ]);
    }

    public function filterOptions(Request $request): JsonResponse
    {
        [$areaId, $cabangId] = [$request->integer('area_id') ?: null, $request->integer('cabang_id') ?: null];

        return response()->json($this->service->filterOptions($areaId, $cabangId));
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
            $this->service->chart($this->tanggal($request), $areaId, $cabangId, $ukerId),
        );
    }

    public function branchPencapaian(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json(
            $this->service->branchPencapaian($this->tanggal($request), $areaId, $cabangId, $ukerId),
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

    /**
     * @return array<string, int|null>
     */
    private function filter(Request $request): array
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return ['area_id' => $areaId, 'cabang_id' => $cabangId, 'uker_id' => $ukerId];
    }

    private function tanggal(Request $request): string
    {
        $tanggal = $request->date('tanggal')
            ?? ($this->service->tanggalTerakhir() === null ? null : Carbon::parse($this->service->tanggalTerakhir()));

        return ($tanggal ?? Carbon::today())->toDateString();
    }
}
