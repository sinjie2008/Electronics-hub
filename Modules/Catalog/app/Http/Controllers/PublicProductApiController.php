<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Validator;
use Modules\Catalog\Http\CatalogApiException;
use Modules\Catalog\Http\CorrelationId;
use Modules\Catalog\Http\HttpResponder;
use Modules\Catalog\Http\LegacyFileHeaders;
use Modules\Catalog\Services\PublicProductApiService;
use Throwable;

/** Contract-preserving transport for the source's ten read-only operations. */
final class PublicProductApiController
{
    public function missing(Request $request): Response
    {
        $out = new HttpResponder;
        $out->setCorrelationId(CorrelationId::fromRequest($request));
        if ($request->getRealMethod() !== 'GET') {
            $response = $out->sendError('METHOD_NOT_ALLOWED', 'Only GET is supported.', 405);
            $response->headers->set('Allow', 'GET');
        } else {
            $response = $out->sendError('NOT_FOUND', 'API route not found.', 404);
        }
        $response->headers = new LegacyFileHeaders($response->headers->all());
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

    public function run(Request $request): Response
    {
        $out = new HttpResponder;
        $out->setCorrelationId(CorrelationId::fromRequest($request));
        try {
            if ($request->getRealMethod() !== 'GET') {
                $response = $out->sendError('METHOD_NOT_ALLOWED', 'Only GET is supported.', 405);
                $response->headers->set('Allow', 'GET');

                return $response;
            }
            $route = $this->route($request);
            $query = $this->query($request);
            // Resolve lazily: method/input failures never open a database connection.
            $api = app(PublicProductApiService::class);
            $data = match (true) {
                $route === '' => $api->root(),
                $route === 'health' => $api->health(),
                $route === 'tree' => $api->tree(),
                $route === 'search' => $api->search($query['q'] ?? ''),
                str_starts_with($route, 'resolve/') => $api->resolve(substr($route, 8)),
                str_starts_with($route, 'categories/') => $api->category(substr($route, 11)),
                str_starts_with($route, 'series/') => $this->series($api, substr($route, 7), $query),
                default => throw new CatalogApiException('NOT_FOUND', 'API route not found.', 404),
            };

            return $out->sendJson(['success' => true, 'data' => $data]);
        } catch (CatalogApiException $exception) {
            return $out->sendError($exception->getErrorCode(), $exception->getMessage(), $exception->getStatusCode(), $exception->getDetails());
        } catch (Throwable $exception) {
            report($exception);

            return $out->sendError('INTERNAL_ERROR', 'The public product API is unavailable. Check the existing database connection and tables.', 500);
        }
    }

    private function series(PublicProductApiService $api, string $path, array $query): array
    {
        $path = trim($path, '/');
        try {
            return $api->series($path);
        } catch (CatalogApiException $exception) {
            if ($exception->getStatusCode() !== 404) {
                throw $exception;
            }
        }
        foreach (['fields', 'parts', 'facets'] as $subresource) {
            $suffix = '/'.$subresource;
            if (str_ends_with($path, $suffix)) {
                $series = substr($path, 0, -strlen($suffix));

                return match ($subresource) {
                    'fields' => $api->fields($series),
                    'parts' => $api->parts($series, $query),
                    'facets' => $api->facets($series),
                };
            }
        }
        throw new CatalogApiException('SERIES_NOT_FOUND', 'Public series not found.', 404);
    }

    private function route(Request $request): string
    {
        $raw = (string) parse_url($request->getRequestUri(), PHP_URL_PATH);
        if (preg_match('/%(?![0-9a-f]{2})/i', $raw) === 1) {
            throw new CatalogApiException('INVALID_PATH', 'Invalid path encoding.', 422);
        }
        $route = ltrim(substr($request->decodedPath(), 3), '/');
        if (str_starts_with($route, 'index.php/')) {
            $route = substr($route, 10);
        }
        if (isset($request->query->all()['route'])) {
            $route = $request->query->all()['route'];
        } elseif (in_array(trim($route, '/'), ['', 'index.php'], true)) {
            $route = '';
        }
        if (! is_string($route) || ! mb_check_encoding($route, 'UTF-8') || strlen($route) > 2060 || preg_match('/[\x00-\x1f\x7f\\\\]/', $route)) {
            throw new CatalogApiException('INVALID_PATH', 'A valid catalog path is required.', 422);
        }
        $route = trim($route, '/');
        $path = explode('/', $route, 2)[1] ?? '';
        if (strlen($path) > 2048 || str_contains(rawurldecode($route), '..') || in_array('.', explode('/', rawurldecode($route)), true)) {
            throw new CatalogApiException('INVALID_PATH', 'A valid catalog path is required.', 422);
        }

        return $route;
    }

    private function query(Request $request): array
    {
        $query = $request->query->all();
        if (! mb_check_encoding($query, 'UTF-8')) {
            throw new CatalogApiException('INVALID_QUERY', 'Invalid query parameters.', 422);
        }
        if (isset($query['filter']) && ! is_array($query['filter'])) {
            throw new CatalogApiException('INVALID_QUERY', 'filter must be an array.', 422);
        }
        $validator = Validator::make($query, [
            'q' => ['sometimes', 'string', 'max:2000'],
            'search' => ['sometimes', 'string', 'max:2000'],
            'sort' => ['sometimes', 'string', 'max:128'],
            'direction' => ['sometimes', 'string', 'max:128'],
            'page' => ['sometimes', 'numeric', 'between:-2147483648,2147483647'],
            'per_page' => ['sometimes', 'numeric', 'between:-2147483648,2147483647'],
            'filter' => ['sometimes', 'array', 'max:50'],
        ]);
        if ($validator->fails()) {
            throw new CatalogApiException('INVALID_QUERY', 'Invalid query parameters.', 422);
        }
        foreach ($query['filter'] ?? [] as $key => $values) {
            if (strlen((string) $key) > 128 || (is_array($values) && count($values) > 50)) {
                throw new CatalogApiException('INVALID_QUERY', 'Invalid filter values.', 422);
            }
            foreach (is_array($values) ? $values : [$values] as $value) {
                if (! is_scalar($value) || strlen((string) $value) > 2000) {
                    throw new CatalogApiException('INVALID_QUERY', 'Invalid filter values.', 422);
                }
            }
        }

        return $query;
    }
}
