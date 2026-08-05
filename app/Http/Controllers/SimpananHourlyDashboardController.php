<?php

namespace App\Http\Controllers;

use App\Services\SimpananHourlyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Controller TIPIS DPK Hourly. Seluruh kalkulasi ada di SimpananHourlyService.
 *
 * Halaman DAN semua endpoint di bawah api/simpanan-hourly/* dijaga middleware
 * `hourly` di routes/web.php — jangan mengandalkan penyembunyian menu.
 */
class SimpananHourlyDashboardController extends Controller
{
    public function __construct(private readonly SimpananHourlyService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('SimpananHourly/Index', [
            'tanggalAwal' => $this->tanggal($request),
            // Interval auto-refresh layar pemantauan EOM (detik).
            'intervalRefresh' => 120,
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
            $this->jam($request),
            $areaId,
            $cabangId,
            $ukerId,
        ));
    }

    public function chart(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->chart(
            $this->tanggal($request),
            $areaId,
            $cabangId,
            $ukerId,
        ));
    }

    public function branchPencapaian(Request $request): JsonResponse
    {
        [$areaId, $cabangId, $ukerId] = $this->filterId($request);

        return response()->json($this->service->branchPencapaian(
            $this->tanggal($request),
            $this->jam($request),
            $areaId,
            $cabangId,
            $ukerId,
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

    private function jam(Request $request): ?int
    {
        if (! $request->filled('jam')) {
            return null;
        }

        $jam = $request->integer('jam');

        return $jam >= 0 && $jam <= 23 ? $jam : null;
    }

    private function tanggal(Request $request): string
    {
        $tanggal = $request->date('tanggal')
            ?? ($this->service->tanggalTerakhir() === null
                ? null
                : Carbon::parse($this->service->tanggalTerakhir()));

        return ($tanggal ?? Carbon::today())->toDateString();
    }
}
