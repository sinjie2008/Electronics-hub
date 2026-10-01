<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;
use Modules\IAM\Models\Role;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local')
            && config('telescope.enabled', false)
            && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {
            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);

            if (class_exists(TelescopeServiceProvider::class)) {
                $this->app->register(TelescopeServiceProvider::class);
            }
        }
    }

    public function boot(): void
    {
        Gate::before(function (User $user, string $ability): ?bool {
            if (! $user->is_active) {
                return false;
            }

            if ($user->hasRole(Role::SUPER_ADMIN, 'web')) {
                return true;
            }

            if ($ability === 'access.super-admin') {
                return false;
            }

            // Permission names are namespaced; policy verbs always reach policies.
            return str_contains($ability, '.') && $user->checkPermissionTo($ability, 'web') ? true : null;
        });
        Gate::define('access.super-admin', fn (User $user): bool => false);

        Password::defaults(fn (): Password => Password::min(12)->mixedCase()->numbers()->symbols());

        Passport::tokensCan([
            'profile:read' => 'Read your own profile',
            'users:search' => 'Search users subject to administrative permissions',
            'system:read' => 'Read safe integration status',
        ]);
        Passport::tokensExpireIn(now()->addHour());
        Passport::refreshTokensExpireIn(now()->addDays(14));
        Passport::personalAccessTokensExpireIn(now()->addDays(30));
        Passport::authorizationView('oauth.authorize');

        RateLimiter::for('api', fn (Request $request): Limit => Limit::perMinute(60)
            ->by((string) ($request->user()?->getAuthIdentifier() ?? $request->ip())));
    }
}
