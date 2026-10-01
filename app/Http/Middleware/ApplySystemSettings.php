<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\System\Settings\SystemSettings;
use Symfony\Component\HttpFoundation\Response;

class ApplySystemSettings
{
    public function __construct(private SystemSettings $settings) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->is('oauth/authorize') && $request->user()) {
            abort_unless($request->user()->is_active, 403);
        }

        $original = [
            'app.name' => config('app.name'),
            'app.timezone' => config('app.timezone'),
            'app.locale' => app()->getLocale(),
        ];
        $timezone = date_default_timezone_get();
        config(['app.name' => $this->settings->application_name, 'app.timezone' => $this->settings->timezone]);
        app()->setLocale($this->settings->default_locale);
        date_default_timezone_set($this->settings->timezone);

        try {
            return $next($request);
        } finally {
            config($original);
            app()->setLocale($original['app.locale']);
            date_default_timezone_set($timezone);
        }
    }
}
