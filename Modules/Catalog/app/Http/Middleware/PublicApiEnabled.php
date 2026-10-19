<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Modules\Catalog\Http\CatalogApiException;
use Modules\Catalog\Http\CorrelationId;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/** Public API boundary; deliberately independent of session and Passport guards. */
final class PublicApiEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = CorrelationId::fromRequest($request);
        $request->attributes->set('catalog_public_correlation_id', $correlationId);

        try {
            if (! app('modules')->isEnabled('Catalog') || ! config('catalog.public_api.enabled', false)) {
                throw new CatalogApiException('API_UNAVAILABLE', 'The public product API is unavailable.', 503);
            }
            if ($request->getRealMethod() !== 'GET' || $request->method() !== 'GET') {
                throw new CatalogApiException('METHOD_NOT_ALLOWED', 'Only GET is supported.', 405);
            }
            $this->rateLimit($request);
            $this->validateUri($request);
            $response = $next($request);
        } catch (CatalogApiException $exception) {
            $response = new JsonResponse([
                'success' => false, 'errorCode' => $exception->getErrorCode(),
                'message' => $exception->getMessage(), 'details' => $exception->getDetails(),
                'correlationId' => $correlationId,
            ], $exception->getStatusCode());
            if ($exception->getStatusCode() === 405) {
                $response->headers->set('Allow', 'GET');
            }
            if ($exception->getStatusCode() === 429) {
                $response->headers->set('Retry-After', (string) ($exception->getDetails()['retryAfter'] ?? 60));
            }
        } catch (Throwable $exception) {
            Log::error('Public product API boundary failed.', [
                'correlationId' => $correlationId, 'exceptionClass' => $exception::class,
            ]);
            $response = new JsonResponse([
                'success' => false, 'errorCode' => 'INTERNAL_ERROR',
                'message' => 'The public product API is unavailable.', 'details' => [],
                'correlationId' => $correlationId,
            ], 500);
        }

        $response->headers->set('X-Correlation-ID', $correlationId);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Content-Type', 'application/json; charset=utf-8');

        return $response;
    }

    private function validateUri(Request $request): void
    {
        $uri = (string) $request->server->get('REQUEST_URI', '');
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $decoded = rawurldecode($path);
        if (strlen($path) > 8192 || preg_match('/%(?![0-9A-Fa-f]{2})/', $path)
            || ! mb_check_encoding($decoded, 'UTF-8') || str_contains($decoded, '%')
            || str_contains($decoded, '..') || str_contains($decoded, '\\')
            || str_contains($decoded, '//') || preg_match('/[\x00-\x1F\x7F]/', $decoded)) {
            throw new CatalogApiException('INVALID_PATH', 'A valid catalog path is required.', 422);
        }
        $query = (string) $request->server->get('QUERY_STRING', '');
        if (preg_match('/%(?![0-9A-Fa-f]{2})/', $query)) {
            throw new CatalogApiException('INVALID_QUERY', 'Invalid query encoding.', 422);
        }
        if (strlen($query) > (int) config('catalog.public_api.max_query_bytes', 8192)) {
            throw new CatalogApiException('INVALID_QUERY', 'The query string exceeds its allowed length.', 422);
        }
    }

    private function rateLimit(Request $request): void
    {
        $policies = ['standard'];
        $endpoint = (string) $request->route('catalog_public_endpoint');
        if ($endpoint === 'search') {
            $policies[] = 'search';
        } elseif (in_array($endpoint, ['parts', 'facets'], true)) {
            $policies[] = 'expensive';
        }
        foreach ($policies as $policy) {
            $key = 'catalog-public-api:'.$policy.':'.hash('sha256', (string) $request->ip());
            $limit = max(1, (int) config('catalog.public_api.rate_limits.'.$policy, 60));
            if (RateLimiter::tooManyAttempts($key, $limit)) {
                throw new CatalogApiException('RATE_LIMITED', 'Too many public API requests.', 429, [
                    'retryAfter' => max(1, RateLimiter::availableIn($key)),
                ]);
            }
            RateLimiter::hit($key, 60);
        }
    }
}
