<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Catalog\Http\LegacyFileHeaders;
use Symfony\Component\HttpFoundation\Response;

final class PublicApiHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers = new LegacyFileHeaders($response->headers->all());
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Vary', 'Origin', false);
        $origin = $request->header('Origin');
        if (is_string($origin) && in_array($origin, (array) config('catalog.public_api.allowed_origins', []), true)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Access-Control-Expose-Headers', 'X-Correlation-ID');
        }

        return $response;
    }
}
