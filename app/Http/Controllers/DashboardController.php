<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Dashboard');
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
}
