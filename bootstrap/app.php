<?php

use App\Http\Middleware\ApplySystemSettings;
use App\Http\Middleware\EnsureActiveUser;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Request;
use Modules\Catalog\Console\Commands\CatalogMigrateCommand;
use Modules\Catalog\Http\Controllers\PublicProductApiController;
use Modules\Catalog\Http\Middleware\CatalogBoundary;
use Modules\Catalog\Http\ModuleRoutes;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withCommands([CatalogMigrateCommand::class])
    ->withMiddleware(function (Middleware $middleware): void {
        // Catalog owns its public CORS policy, including OPTIONS when disabled.
        $middleware->prepend(CatalogBoundary::class);
        HandleCors::skipWhen(fn (Request $request): bool => ModuleRoutes::ownsPath($request->decodedPath()));
        $middleware->alias(['active.user' => EnsureActiveUser::class]);
        $middleware->web(append: [ApplySystemSettings::class]);
        $middleware->api(append: [ApplySystemSettings::class]);
        $middleware->redirectGuestsTo(fn (Request $request) => $request->is('oauth/*') ? route('login') : route('filament.admin.auth.login'));
        $middleware->throttleApi('api');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Preserve unknown public API errors without a catch-all route over /api/v1.
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->is('api/*') && ! $request->is('api/v1', 'api/v1/*') && $request->route() === null) {
                return app(PublicProductApiController::class)->missing($request);
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
