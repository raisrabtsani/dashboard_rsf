<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'auth' => [
                // Sengaja dikurasi, bukan seluruh model: hanya yang dibutuhkan UI.
                // access_level dipakai frontend untuk menyembunyikan filter yang
                // tak relevan — kosmetik saja, penegakannya di EnforceUserScope.
                'user' => $user === null ? null : [
                    'id' => $user->id,
                    'name' => $user->name,
                    'username' => $user->username,
                    'role' => $user->role,
                    'tipe' => $user->tipe,
                    'cabang_id' => $user->cabang_id,
                    'uker_id' => $user->uker_id,
                    'access_level' => $user->access_level,
                ],
            ],
        ];
    }
}
