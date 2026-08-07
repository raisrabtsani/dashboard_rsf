<?php

namespace App\Http\Controllers;

use App\Services\PinjamanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller TIPIS — hanya parse request lalu delegasi ke PinjamanService.
 * Filter dibaca dari Request supaya otomatis terkunci middleware `scope`.
 */
class PinjamanDashboardController extends Controller
{
    public function __construct(private readonly PinjamanService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('PinjamanDashboard/Index', [
            'tanggalAwal' => $this->tanggal($request),
            'tabAwal' => PinjamanService::TAB_TOTAL,
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
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->snapshot(
            $this->tanggal($request),
            $this->tab($request),
            $areaId,
            $cabangId,
            $ukerId,
            $this->produkFilter($request),
            $this->segmentasiFilter($request),
        ));
    }

    public function chart(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->chart(
            $this->tanggal($request),
            $this->tab($request),
            $areaId,
            $cabangId,
            $ukerId,
            $this->produkFilter($request),
            $this->segmentasiFilter($request),
        ));
    }

    public function chartSegmen(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->chartSegmen(
            $this->tanggal($request),
            $this->tab($request),
            $areaId,
            $cabangId,
            $ukerId,
            $this->produkFilter($request),
            $this->segmentasiFilter($request),
        ));
    }

    public function produk(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->produk(
            $this->tanggal($request),
            $this->tab($request),
            $areaId,
            $cabangId,
            $ukerId,
            $this->produkFilter($request),
            $this->segmentasiFilter($request),
        ));
    }

    public function branchPencapaian(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->branchPencapaian(
            $this->tanggal($request),
            $this->tab($request),
            $areaId,
            $cabangId,
            $ukerId,
            $this->produkFilter($request),
            $this->segmentasiFilter($request),
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

    /**
     * @return array<string, int|string|null>
     */
    private function filter(Request $request): array
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return [
            'area_id' => $areaId,
            'cabang_id' => $cabangId,
            'uker_id' => $ukerId,
            'produk' => $this->produkFilter($request),
            'segmentasi' => $this->segmentasiFilter($request),
        ];
    }

    /** Produk pada UI disimpan di kolom database `segmentasi`. */
    private function produkFilter(Request $request): ?string
    {
        $nilai = trim((string) $request->input('produk', ''));

        return $nilai === '' ? null : $nilai;
    }

    /** Segmentasi pada UI disimpan di kolom database `segmen`. */
    private function segmentasiFilter(Request $request): ?string
    {
        $nilai = trim((string) $request->input('segmentasi', ''));

        return $nilai === '' ? null : $nilai;
    }

    /** Tab tak dikenal dinormalkan service ke `total`. */
    private function tab(Request $request): string
    {
        return $request->string('tab')->toString() ?: PinjamanService::TAB_TOTAL;
    }

    private function tanggal(Request $request): string
    {
        $tanggal = $request->date('tanggal')
            ?? ($this->service->tanggalTerakhir() === null ? null : Carbon::parse($this->service->tanggalTerakhir()));

        return ($tanggal ?? Carbon::today())->toDateString();
    }
}
