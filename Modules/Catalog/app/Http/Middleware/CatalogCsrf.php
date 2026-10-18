<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Middleware;

use Closure;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/** Passport-authenticated requests do not use ambient browser credentials. */
final class CatalogCsrf extends PreventRequestForgery
{
    public function handle($request, Closure $next)
    {
        if ($request->attributes->get('catalog.bearer') === true) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }

    protected function isReading($request): bool
    {
        return in_array($request->getRealMethod(), ['GET', 'HEAD', 'OPTIONS'], true);
    }
}
