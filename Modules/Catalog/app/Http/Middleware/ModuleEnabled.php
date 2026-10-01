<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Prevent previously cached module routes from responding after disable. */
final class ModuleEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! app('modules')->isEnabled('Catalog')) {
            return new Response('', 404);
        }

        return $next($request);
    }
}
