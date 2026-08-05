<?php

namespace App\Http\Controllers;

use App\Services\EdcService;
use App\Services\QrisService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Halaman tunggal /dashboard/merchant. Toggle EDC|QRIS ditangani di frontend;
 * backend tetap dua service & dua prefix endpoint (api/merchant/edc|qris/*).
 */
class MerchantController extends Controller
{
    public function __construct(
        private readonly EdcService $edc,
        private readonly QrisService $qris,
    ) {}

    public function index(Request $request): Response
    {
        // Default tanggal = data terbaru di antara kedua sub-domain.
        $terakhir = collect([$this->edc->tanggalTerakhir(), $this->qris->tanggalTerakhir()])
            ->filter()
            ->max();

        $tanggal = $terakhir === null ? Carbon::today() : Carbon::parse($terakhir);

        return Inertia::render('MerchantDashboard/Index', [
            'tanggalAwal' => $tanggal->toDateString(),
            'filterAwal' => [
                'area_id' => $request->integer('area_id') ?: null,
                'cabang_id' => $request->integer('cabang_id') ?: null,
                'uker_id' => $request->integer('uker_id') ?: null,
            ],
        ]);
    }
}
