<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            App\Http\Middleware\HandleInertiaRequests::class,
            Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets::class,
            // Catat last_seen_at user (throttle 60s) untuk Admin > Aktivitas Pengguna.
            App\Http\Middleware\TrackUserActivity::class,
        ]);

        $middleware->alias([
            // Gerbang area Admin — role admin saja.
            'admin' => App\Http\Middleware\AdminMiddleware::class,
            // Penegakan lingkup data; wajib dipasang di SEMUA route dashboard.
            'scope' => App\Http\Middleware\EnforceUserScope::class,
            // Gerbang DPK Hourly — RO/BO/admin saja (semua kecuali level uker).
            'hourly' => App\Http\Middleware\EnsureRoBoOrAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
