<?php

namespace App\Http\Controllers;

use App\Services\PresentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman PRESENT — slide rapat pagi Region. Controller TIPIS: seluruh kalkulasi
 * di PresentService. Gerbang akses (RO/admin) ada di middleware `present`, dipasang
 * pada halaman DAN setiap endpoint. Tidak ada filter organisasi — halaman ini
 * selalu menampilkan seluruh Region; satu-satunya parameter adalah tanggal posisi.
 */
class PresentController extends Controller
{
    public function __construct(private readonly PresentService $service) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Present/Index', [
            'tanggalAwal' => $this->tanggal($request),
        ]);
    }

    public function overview(Request $request): JsonResponse
    {
        return response()->json($this->service->overviewRegion($this->tanggal($request)));
    }

    public function area(Request $request): JsonResponse
    {
        return response()->json($this->service->overviewArea($this->tanggal($request)));
    }

    public function detail(Request $request): JsonResponse
    {
        return response()->json($this->service->detail($this->tanggal($request)));
    }

    private function tanggal(Request $request): string
    {
        $tanggal = $request->date('tanggal')
            ?? ($this->service->tanggalTerakhir() === null ? null : Carbon::parse($this->service->tanggalTerakhir()));

        return ($tanggal ?? Carbon::today())->toDateString();
    }
}
