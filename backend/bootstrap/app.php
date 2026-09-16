<?php

use App\Http\Middleware\RequireBusinessPermission;
use App\Http\Middleware\ResolveBusinessContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant' => ResolveBusinessContext::class,
            'permission' => RequireBusinessPermission::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Central JSON exception rendering will be expanded with the API error contract.
    })
    ->create();
