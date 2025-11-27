<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //$middleware->statefulApi();
        $middleware->alias([
            'token.expiry' => \App\Http\Middleware\CheckTokenExpiry::class,
            'check.superadmin' => \App\Http\Middleware\CheckSuperAdmin::class,
            'check.admin' => \App\Http\Middleware\IsAdmin::class,
            'api.auth.response' => \App\Http\Middleware\ApiAuthResponse::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
