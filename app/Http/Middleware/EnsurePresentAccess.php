<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang halaman & endpoint PRESENT (alias `present`).
 *
 * Yang boleh: admin dan RO — yaitu pemegang access_level LEVEL_ALL. PRESENT
 * adalah layar rapat pagi tingkat Region; BO (level cabang) dan user uker tidak
 * berkepentingan melihat rekap seluruh Region dan tidak boleh mengaksesnya.
 *
 * WAJIB dipasang di HALAMAN dan di SETIAP endpoint api/present/*. Menyembunyikan
 * menunya di frontend saja bukan pengamanan.
 */
class EnsurePresentAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->access_level === User::LEVEL_ALL,
            403,
            'Halaman PRESENT hanya untuk RO dan admin.',
        );

        return $next($request);
    }
}
