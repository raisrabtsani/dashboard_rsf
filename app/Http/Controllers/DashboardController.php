<?php

namespace App\Http\Controllers;

use App\Services\RingkasanService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Landing /dashboard — ringkasan semua domain dalam satu halaman.
 *
 * Controller TIPIS: hanya parse request lalu delegasi ke RingkasanService, yang
 * pada gilirannya mendelegasi ke service tiap domain. Tidak ada query di sini.
 * Filter dibaca dari Request supaya otomatis terkunci middleware `scope`.
 */
class DashboardController extends Controller
{
    public function __construct(private readonly RingkasanService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Dashboard', [
            'tanggalAwal' => $this->tanggal($request),
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

    public function ringkasan(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json(
            $this->service->ringkasan($this->tanggal($request), $areaId, $cabangId, $ukerId),
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
     * Filter efektif setelah EnforceUserScope menulis ulang Request.
     *
     * Dipakai frontend untuk tahu lingkup yang sebenarnya diterapkan backend —
     * bukan untuk menentukannya. Controller sengaja hanya membaca Request:
     * nilai yang dibaca di sini sudah dikunci middleware.
     */
    public function scope(Request $request): JsonResponse
    {
        return response()->json([
            'access_level' => $request->user()->access_level,
            'area_id' => $request->integer('area_id') ?: null,
            'cabang_id' => $request->integer('cabang_id') ?: null,
            'uker_id' => $request->integer('uker_id') ?: null,
        ]);
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
