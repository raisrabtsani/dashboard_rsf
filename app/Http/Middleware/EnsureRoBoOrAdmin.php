<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang halaman & endpoint DPK Hourly (alias `hourly`).
 *
 * Yang boleh: admin, RO, dan BO — yaitu semua kecuali level uker.
 * DPK Hourly adalah layar pemantauan tingkat Region/Cabang di hari EOM;
 * user yang terkunci di satu uker tidak punya kepentingan di sana dan tidak
 * boleh melihat pergerakan kantor lain.
 *
 * WAJIB dipasang di halaman DAN di seluruh endpoint api/simpanan-hourly/*.
 * Menyembunyikan menunya di frontend saja bukan pengamanan.
 */
class EnsureRoBoOrAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->access_level !== User::LEVEL_UKER,
            403,
            'Halaman DPK Hourly hanya untuk RO, BO, dan admin.',
        );

        return $next($request);
    }
}
