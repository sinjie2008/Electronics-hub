<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Closure;
use Modules\Catalog\Http\RequestInput;
use Modules\Catalog\Http\Response;
use Modules\Catalog\Services\CatalogService;
use Modules\Catalog\Support\Logger;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Handles catalog read API endpoints while preserving their existing envelopes.
 */
final class CatalogReadController
{
    /** @var (Closure(): CatalogService)|null */
    private ?Closure $catalogServiceFactory;

    /**
     * Accept an existing service or a lazy factory for host application bindings.
     *
     * The default keeps constructing the service inside each endpoint's existing
     * error boundary, preserving standalone database-connection timing.
     *
     * @param  (callable(): CatalogService)|null  $catalogServiceFactory
     */
    public function __construct(
        private RequestInput $input,
        private ?CatalogService $catalogService = null,
        ?callable $catalogServiceFactory = null
    ) {
        $this->catalogServiceFactory = $catalogServiceFactory === null
            ? null
            : Closure::fromCallable($catalogServiceFactory);
    }

    /**
     * Return the catalog hierarchy.
     */
    public function hierarchy(): HttpResponse
    {
        $correlationId = $this->input->correlationId();
        $route = $this->input->route();
        $method = $this->input->method();
        $startedAt = microtime(true);
        Logger::info('request_start', ['route' => $route, 'method' => $method, 'action' => 'catalog.hierarchy'], $correlationId);

        try {
            $tree = $this->newService()->getHierarchy();
            $response = Response::success($tree, 200, $correlationId);
        } catch (Throwable $e) {
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => 'catalog.hierarchy',
                'status' => 500,
                'errorCode' => 'internal_error',
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);

            return Response::error('internal_error', 'Unexpected error', 500, $correlationId);
        }

        Logger::info('request_success', [
            'route' => $route,
            'method' => $method,
            'action' => 'catalog.hierarchy',
            'status' => 200,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ], $correlationId);

        return $response;
    }

    /**
     * Search categories, series, and products by query string.
     */
    public function search(): HttpResponse
    {
        $correlationId = $this->input->correlationId();
        $route = $this->input->route();
        $method = $this->input->method();
        $startedAt = microtime(true);
        Logger::info('request_start', ['route' => $route, 'method' => $method, 'action' => 'catalog.search'], $correlationId);

        try {
            $query = ($this->input->query('q') !== null) ? (string) $this->input->query('q') : '';
            $matches = $this->newService()->search($query);
            $response = Response::success($matches, 200, $correlationId);
        } catch (Throwable $e) {
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => 'catalog.search',
                'status' => 500,
                'errorCode' => 'internal_error',
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);

            return Response::error('internal_error', 'Unexpected error', 500, $correlationId);
        }

        Logger::info('request_success', [
            'route' => $route,
            'method' => $method,
            'action' => 'catalog.search',
            'status' => 200,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ], $correlationId);

        return $response;
    }

    /**
     * Return details for one series.
     */
    public function seriesDetails(): HttpResponse
    {
        $correlationId = $this->input->correlationId();
        $route = $this->input->route();
        $method = $this->input->method();

        try {
            if ($method === 'GET') {
                $seriesId = (int) ($this->input->query('id') ?? 0);
                if (! $seriesId) {
                    return Response::error('validation_error', 'Series ID is required', 400, $correlationId);
                }

                $details = $this->newService()->getSeriesDetails($seriesId);
                if (! $details) {
                    return Response::error('not_found', 'Series not found', 404, $correlationId);
                }

                return Response::success($details, 200, $correlationId);
            } else {
                return Response::error('method_not_allowed', 'Method not allowed', 405, $correlationId);
            }
        } catch (Throwable $e) {
            Logger::error('request_failed', ['route' => $route, 'method' => $method, 'exception' => $e], $correlationId);

            return Response::error('internal_error', 'Unexpected error: '.$e->getMessage(), 500, $correlationId);
        }
    }

    /** Create or resolve the service only when an endpoint needs it. */
    private function newService(): CatalogService
    {
        if ($this->catalogService !== null) {
            return $this->catalogService;
        }

        if ($this->catalogServiceFactory !== null) {
            return ($this->catalogServiceFactory)();
        }

        return new CatalogService;
    }
}
