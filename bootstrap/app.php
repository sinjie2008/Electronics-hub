<?php

use App\Http\Middleware\ApplySystemSettings;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['active.user' => EnsureActiveUser::class]);
        $middleware->web(append: [ApplySystemSettings::class]);
        $middleware->api(append: [ApplySystemSettings::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('oauth/*') ? route('login') : route('filament.admin.auth.login'));
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
