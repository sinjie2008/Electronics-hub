<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Catalog\Http\Controllers\PublicProductApiController;
use Modules\Catalog\Http\CorrelationId;
use Modules\Catalog\Http\HttpResponder;
use Modules\Catalog\Http\ModuleRoutes;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Runs before global CORS, including when Catalog's provider is absent. */
final class CatalogBoundary
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! ModuleRoutes::ownsPath($request->decodedPath())) {
            return $next($request);
        }
        try {
            $enabled = app('modules')->isEnabled('Catalog');
        } catch (Throwable) {
            $enabled = false;
        }
        if (! $enabled) {
            return new Response('', 404, ['Cache-Control' => 'no-store']);
        }
        if (ModuleRoutes::isPublicApiPath($request->decodedPath()) && $request->getRealMethod() !== 'GET') {
            // Includes methods such as TRACE that Laravel's Route::any omits.
            return (new PublicApiHeaders)->handle($request, fn () => app(PublicProductApiController::class)->missing($request));
        }
        if (ModuleRoutes::isPublicApiPath($request->decodedPath())
            && (! mb_check_encoding($request->decodedPath(), 'UTF-8') || preg_match('/[\x00-\x1f\x7f]/', $request->decodedPath()))) {
            $out = new HttpResponder;
            $out->setCorrelationId(CorrelationId::fromRequest($request));

            // Reject before Symfony's UTF-8 route matcher can render a debug error.
            return (new PublicApiHeaders)->handle($request, fn () => $out->sendError('INVALID_PATH', 'A valid catalog path is required.', 422));
        }
        $response = $next($request);
        if ($response->getStatusCode() >= 400) {
            $payload = json_decode((string) $response->getContent(), true);
            if (is_array($payload) && (isset($payload['exception']) || isset($payload['trace']))) {
                $out = new HttpResponder;
                $out->setCorrelationId(CorrelationId::fromRequest($request));
                $safe = $out->sendError('REQUEST_REJECTED', 'The Catalog request could not be completed.', $response->getStatusCode());
                // Keep headers already applied by CORS, throttling and the public contract.
                $response->setContent($safe->getContent());
                $response->headers->set('Content-Type', $safe->headers->get('Content-Type'));
                $response->headers->set('X-Correlation-ID', $safe->headers->get('X-Correlation-ID'));
            }
        }
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        if (! ModuleRoutes::isPublicApiPath($request->decodedPath()) && ! str_contains('/'.$request->decodedPath(), '/assets/')) {
            $response->headers->set('Cache-Control', 'no-store, private');
        }
        if ($response->getStatusCode() >= 500 && ! ModuleRoutes::isPublicApiPath($request->decodedPath())) {
            $payload = json_decode((string) $response->getContent(), true);
            if (is_array($payload)) {
                // Preserve each legacy envelope while removing SQL, paths and compiler output.
                if (isset($payload['error']) && is_array($payload['error'])) {
                    if (($payload['error']['message'] ?? '') !== 'Missing ID') {
                        $payload['error']['message'] = 'The Catalog operation could not be completed.';
                    }
                    unset($payload['error']['details']);
                } else {
                    $payload['message'] = 'The Catalog operation could not be completed.';
                    $payload['details'] = [];
                }
                $response->setContent(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            } else {
                $out = new HttpResponder;
                $out->setCorrelationId(CorrelationId::fromRequest($request));
                $response = $out->sendError('INTERNAL_ERROR', 'The Catalog operation could not be completed.', 500);
            }
        }

        return $response;
    }
}
