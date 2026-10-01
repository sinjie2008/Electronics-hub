<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    public function register(): void
    {
        Telescope::hideRequestParameters([
            '_token', 'password', 'password_confirmation', 'current_password',
            'client_secret', 'secret', 'access_token', 'refresh_token', 'code', 'code_verifier',
        ]);
        Telescope::hideRequestHeaders(['authorization', 'cookie', 'x-csrf-token', 'x-xsrf-token']);
        Telescope::filter(fn (IncomingEntry $entry): bool => $this->app->environment('local'));
    }

    protected function authorization(): void
    {
        $this->gate();
        Telescope::auth(fn (Request $request): bool => $this->app->environment('local')
            && $request->user() instanceof User && $request->user()->is_active
            && Gate::forUser($request->user())->allows('viewTelescope'));
    }

    protected function gate(): void
    {
        Gate::define('viewTelescope', fn (User $user): bool => $user->can('system-info.view'));
    }
}
