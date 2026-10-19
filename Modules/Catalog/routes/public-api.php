<?php

declare(strict_types=1);

use App\Http\Middleware\ApplySystemSettings;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Http\Controllers\PublicApi\ProductApiController;
use Modules\Catalog\Http\Middleware\PublicApiEnabled;

Route::prefix('v1/catalog')->name('api.v1.catalog.public.')
    ->withoutMiddleware([ApplySystemSettings::class, 'throttle:api'])
    ->middleware(PublicApiEnabled::class)->group(function (): void {
        foreach (['' => 'root', 'health' => 'health', 'tree' => 'tree', 'search' => 'search'] as $path => $endpoint) {
            Route::get($path, ProductApiController::class)
                ->defaults('catalog_public_endpoint', $endpoint)->name($endpoint);
        }
        foreach (['resolve', 'categories'] as $endpoint) {
            Route::get($endpoint.'/{path}', ProductApiController::class)->where('path', '.+')
                ->defaults('catalog_public_endpoint', $endpoint)->name($endpoint);
        }
        foreach (['fields', 'parts', 'facets'] as $endpoint) {
            Route::get('series/{path}/'.$endpoint, ProductApiController::class)->where('path', '.+')
                ->defaults('catalog_public_endpoint', $endpoint)->name($endpoint);
        }
        Route::get('series/{path}', ProductApiController::class)->where('path', '.+')
            ->defaults('catalog_public_endpoint', 'series')->name('series');

        // Rejection-only fallback gives unknown paths and unsupported methods the API envelope.
        Route::any('{unmatched?}', ProductApiController::class)->where('unmatched', '.*')
            ->defaults('catalog_public_endpoint', 'unknown')->name('unavailable');
    });
