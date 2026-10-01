<?php

declare(strict_types=1);

namespace Modules\Catalog\Providers;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Modules\Catalog\Http\ModuleRoutes;

final class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // Global input normalization runs before route middleware. Restrict this
        // exemption to the module's exact API addresses, also with cached routes.
        $skip = static fn ($request): bool => ModuleRoutes::isApiPath($request->path());
        TrimStrings::skipWhen($skip);
        ConvertEmptyStringsToNull::skipWhen($skip);
        $this->routes(function (): void {
            require dirname(__DIR__, 2).'/routes/web.php';
        });
        parent::boot();
    }
}
