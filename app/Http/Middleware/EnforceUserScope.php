<?php

namespace App\Http\Middleware;

use App\Models\Cabang;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Satu-satunya titik penegakan lingkup data.
 *
 * Middleware ini MENULIS ULANG parameter area_id / cabang_id / uker_id pada
 * Request SEBELUM controller & service membacanya. Artinya user tidak bisa
 * memperluas aksesnya dengan mengarang query string — nilai yang dikirim klien
 * dibuang dan diganti nilai miliknya sendiri.
 *
 * Penyembunyian filter di frontend (composable useScope) hanya kosmetik/UX.
 * Penegakan yang sesungguhnya ada di sini. Endpoint dashboard baru WAJIB membaca
 * filter dari Request supaya otomatis ikut terkunci.
 */
class EnforceUserScope
{
    /**
     * Parameter filter yang dikendalikan middleware ini.
     */
    private const PARAM = ['area_id', 'cabang_id', 'uker_id'];

    /**
     * Endpoint "ranking antar entitas" — dibalas KOSONG untuk user level uker.
     *
     * Tabel kinerja cabang membandingkan entitas satu sama lain; user yang
     * terkunci di satu uker tidak berhak melihat posisi kantor lain. Daftar ini
     * adalah satu-satunya tempat aturan itu ditulis: setiap domain baru yang
     * punya endpoint ranking WAJIB mendaftarkan nama route-nya di sini.
     *
     * @var list<string>
     */
    public const BRANCH_ROUTES = [
        'api.simpanan.branch-pencapaian',
        'api.pinjaman.branch-pencapaian',
    ];

    /**
     * Memo area per cabang, sengaja properti instance (bukan static) supaya
     * tidak bocor antar-request di worker yang hidup lama.
     *
     * @var array<int, int|null>
     */
    private array $memoArea = [];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        match ($user->access_level) {
            User::LEVEL_CABANG => $this->kunciKeCabang($request, $user),
            User::LEVEL_UKER => $this->kunciKeUker($request, $user),
            // LEVEL_ALL: filter dibiarkan apa adanya.
            default => null,
        };

        if ($user->access_level === User::LEVEL_UKER && $this->routeRanking($request)) {
            return response()->json(['baris' => []]);
        }

        return $next($request);
    }

    /**
     * User level cabang: dipaksa ke cabang miliknya.
     *
     * uker_id dibiarkan lolos supaya drill-down uker di dalam cabangnya tetap
     * jalan — uker yang diminta tetap dibatasi oleh cabang_id yang sudah dikunci.
     */
    private function kunciKeCabang(Request $request, User $user): void
    {
        $cabangId = $user->cabang_id;

        // Data user cacat (level cabang tapi tak punya cabang): tolak, jangan
        // biarkan lolos tanpa filter — itu justru membuka semua data.
        abort_if($cabangId === null, 403, 'Akun ini belum ditetapkan ke cabang mana pun.');

        $this->paksa($request, [
            'area_id' => $this->areaDariCabang($cabangId),
            'cabang_id' => $cabangId,
        ]);
    }

    /**
     * User level uker: dipaksa ke uker miliknya, sekaligus cabang & area induknya.
     */
    private function kunciKeUker(Request $request, User $user): void
    {
        $ukerId = $user->uker_id;

        abort_if($ukerId === null, 403, 'Akun ini belum ditetapkan ke unit kerja mana pun.');

        $cabangId = $user->cabang_id ?? $user->uker?->cabang_id;

        $this->paksa($request, [
            'area_id' => $cabangId === null ? null : $this->areaDariCabang($cabangId),
            'cabang_id' => $cabangId,
            'uker_id' => $ukerId,
        ]);
    }

    /**
     * Timpa parameter di SEMUA sumber input.
     *
     * merge() saja tidak cukup: untuk request GET ia hanya menyentuh query bag,
     * sedangkan untuk POST hanya request bag — sisanya masih memuat nilai kiriman
     * klien dan bisa terbaca lewat $request->query() / $request->post().
     *
     * @param  array<string, int|null>  $nilai
     */
    private function paksa(Request $request, array $nilai): void
    {
        foreach ($nilai as $param => $isi) {
            if ($isi === null) {
                $request->query->remove($param);
                $request->request->remove($param);

                continue;
            }

            $request->query->set($param, $isi);
            $request->request->set($param, $isi);
        }

        $request->merge(array_filter($nilai, fn ($isi) => $isi !== null));
    }

    private function routeRanking(Request $request): bool
    {
        return in_array($request->route()?->getName(), self::BRANCH_ROUTES, true);
    }

    /**
     * Area induk sebuah cabang, di-memo per request supaya tidak query berulang.
     */
    private function areaDariCabang(int $cabangId): ?int
    {
        return $this->memoArea[$cabangId] ??= Cabang::query()->whereKey($cabangId)->value('area_id');
    }

    /**
     * Daftar parameter yang dikelola middleware ini — dipakai test & dokumentasi.
     *
     * @return list<string>
     */
    public static function parameter(): array
    {
        return self::PARAM;
    }
}
