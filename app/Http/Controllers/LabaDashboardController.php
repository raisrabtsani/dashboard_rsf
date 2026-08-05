<?php

namespace App\Http\Controllers;

use App\Services\LabaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller TIPIS — hanya parse request lalu delegasi ke LabaService.
 *
 * Domain BULANAN: periode dibaca sebagai (tahun, bulan), bukan tanggal.
 * Filter dibaca dari Request supaya otomatis terkunci middleware `scope`.
 */
class LabaDashboardController extends Controller
{
    public function __construct(private readonly LabaService $service) {}

    public function index(Request $request): Response
    {
        [$tahun, $bulan] = $this->periode($request);

        return Inertia::render('LabaDashboard/Index', [
            'tahunAwal' => $tahun,
            'bulanAwal' => $bulan,
            'filterAwal' => $this->filter($request),
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
        [$tahun, $bulan] = $this->periode($request);
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->snapshot($tahun, $bulan, $areaId, $cabangId, $ukerId));
    }

    public function chart(Request $request): JsonResponse
    {
        [$tahun] = $this->periode($request);
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->chart($tahun, $areaId, $cabangId, $ukerId));
    }

    public function chartMtd(Request $request): JsonResponse
    {
        [$tahun] = $this->periode($request);
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->chartMtd($tahun, $areaId, $cabangId, $ukerId));
    }

    public function branchPencapaian(Request $request): JsonResponse
    {
        [$tahun, $bulan] = $this->periode($request);
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->branchPencapaian($tahun, $bulan, $areaId, $cabangId, $ukerId));
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

    /**
     * Periode efektif: pakai kiriman bila ada, jika tidak jatuh ke periode
     * terakhir yang punya data, terakhir baru ke bulan berjalan.
     *
     * @return array{0: int, 1: int}
     */
    private function periode(Request $request): array
    {
        $terakhir = $this->service->periodeTerakhir();

        $tahun = $request->integer('tahun') ?: ($terakhir['tahun'] ?? (int) Carbon::now()->year);
        $bulan = $request->integer('bulan') ?: ($terakhir['bulan'] ?? (int) Carbon::now()->month);

        return [$tahun, max(1, min(12, $bulan))];
    }
}
