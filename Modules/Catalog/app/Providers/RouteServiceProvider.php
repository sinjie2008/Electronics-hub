<?php

declare(strict_types=1);

namespace Modules\Catalog\Providers;

use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Modules\Catalog\Http\ModuleRoutes;
use Modules\Catalog\Support\Config;

final class RouteServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if (! $this->app->configurationIsCached()) {
            $defaults = require dirname(__DIR__, 2).'/config/config.php';
            $settings = Config::combine($defaults, (array) config('catalog', []));
            $this->app['config']->set('catalog', $settings);
            if (config('catalog.connection') === 'default') {
                $this->app['config']->set('catalog.connection', config('database.default'));
            }
            if (config('catalog.connection') === 'catalog') {
                $this->app['config']->set('database.connections.catalog', config('catalog.database'));
            }
        }
    }

    public function boot(): void
    {
        $this->loadViewsFrom(dirname(__DIR__, 2).'/resources/views', 'catalog');
        TrimStrings::skipWhen(fn (Request $request): bool => ModuleRoutes::isApiPath($request->path()));
        ConvertEmptyStringsToNull::skipWhen(fn (Request $request): bool => ModuleRoutes::isApiPath($request->path()));
        $this->loadRoutesFrom(dirname(__DIR__, 2).'/routes/web.php');
    }
}
