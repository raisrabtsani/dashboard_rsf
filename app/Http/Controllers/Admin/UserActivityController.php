<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\UserActivityService;
use Illuminate\Http\JsonResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin > Aktivitas Pengguna — controller tipis, seluruh query di service.
 */
class UserActivityController extends Controller
{
    public function __construct(private readonly UserActivityService $service) {}

    public function index(): Response
    {
        return Inertia::render('Admin/Users/Activity');
    }

    public function data(): JsonResponse
    {
        return response()->json([
            'online' => $this->service->online(),
            'pengguna' => $this->service->daftar(),
            'statistik' => $this->service->statistik(),
        ]);
    }
}
