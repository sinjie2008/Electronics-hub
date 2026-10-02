<?php

declare(strict_types=1);

namespace Modules\Catalog\Providers;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Http\ModuleRoutes;

final class RouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        TrimStrings::skipWhen(fn (Request $request): bool => ModuleRoutes::isApiPath($request->path()));
        ConvertEmptyStringsToNull::skipWhen(fn (Request $request): bool => ModuleRoutes::isApiPath($request->path()));
        $this->loadRoutesFrom(dirname(__DIR__, 2).'/routes/web.php');
    }
}
