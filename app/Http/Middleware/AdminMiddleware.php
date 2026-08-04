<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gerbang area Admin (/admin/*): hanya user dengan role admin.
 *
 * Sengaja memeriksa role, bukan access_level: user RO juga berlevel LEVEL_ALL
 * (boleh melihat semua data) tapi TIDAK boleh mengelola upload, RKA, dan user.
 */
class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        abort_unless($user instanceof User && $user->isAdmin(), 403);

        return $next($request);
    }
}
