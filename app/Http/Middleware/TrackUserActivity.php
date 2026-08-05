<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Catat aktivitas terakhir user login ke kolom `users.last_seen_at`.
 *
 * THROTTLE 60 detik: kolom hanya ditulis ulang bila cap terakhir sudah lebih tua
 * dari satu menit, jadi lalu-lintas normal (termasuk auto-refresh videotron tiap
 * 2 menit) tidak membebani DB dengan UPDATE tiap request.
 *
 * Ditulis lewat query builder (bukan model save) agar TIDAK menyentuh `updated_at`
 * — aktivitas bukan perubahan data akun, dan halaman Manajemen User menampilkan
 * `updated_at` sebagai "terakhir diubah".
 *
 * Catatan: daftar "online sekarang" dibaca dari tabel `sessions` (butuh
 * SESSION_DRIVER=database), bukan dari kolom ini; keduanya saling melengkapi.
 */
class TrackUserActivity
{
    private const THROTTLE_DETIK = 60;

    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    /**
     * Dijalankan setelah respons terkirim supaya tidak menambah latensi request.
     */
    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return;
        }

        $now = Carbon::now();
        $terakhir = $user->last_seen_at;

        if ($terakhir !== null && $terakhir->diffInSeconds($now) < self::THROTTLE_DETIK) {
            return;
        }

        DB::table('users')->where('id', $user->id)->update(['last_seen_at' => $now]);
    }
}
