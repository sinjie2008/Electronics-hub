<?php

use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\IntegrationStatusController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Api\UserSearchController;
use Illuminate\Support\Facades\Route;
use Laravel\Passport\Http\Middleware\CheckToken;
use Laravel\Passport\Http\Middleware\EnsureClientIsResourceOwner;
use Spatie\Permission\Middleware\PermissionMiddleware;

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('/health', HealthController::class)->name('health');

    Route::middleware(['auth:api', 'active.user'])->group(function (): void {
        Route::get('/me', MeController::class)
            ->middleware(CheckToken::using('profile:read'))
            ->name('me');

        Route::get('/search/users', UserSearchController::class)
            ->middleware([
                CheckToken::using('users:search'),
                PermissionMiddleware::using('search.use', 'api'),
            ])
            ->name('search.users');
    });

    Route::get('/integration/status', IntegrationStatusController::class)
        ->middleware(EnsureClientIsResourceOwner::using('system:read'))
        ->name('integration.status');
});
