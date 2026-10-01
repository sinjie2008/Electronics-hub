<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers;

use Closure;
use Modules\Catalog\Http\RequestInput;
use Modules\Catalog\Http\Response;
use Modules\Catalog\Services\SpecSearchService;
use Modules\Catalog\Support\Logger;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
use Throwable;

/**
 * Handles specification-search API endpoints.
 */
final class SpecSearchController
{
    /** @var (Closure(): SpecSearchService)|null */
    private ?Closure $specSearchServiceFactory;

    /**
     * Accept an existing service or a lazy factory for host application bindings.
     *
     * The default leaves service creation in the existing request error boundary.
     *
     * @param  (callable(): SpecSearchService)|null  $specSearchServiceFactory
     */
    public function __construct(
        private RequestInput $input,
        private ?SpecSearchService $specSearchService = null,
        ?callable $specSearchServiceFactory = null
    ) {
        $this->specSearchServiceFactory = $specSearchServiceFactory === null
            ? null
            : Closure::fromCallable($specSearchServiceFactory);
    }

    /**
     * Return root categories.
     */
    public function rootCategories(): HttpResponse
    {
        return $this->run('spec-search.roots', function (string $correlationId): HttpResponse {
            $categories = $this->newService()->getRootCategories();

            return Response::success(['categories' => $categories], 200, $correlationId);
        });
    }

    /**
     * Return categories under a root category.
     */
    public function productCategories(): HttpResponse
    {
        return $this->run('spec-search.product-categories', function (string $correlationId): HttpResponse {
            $rootId = ($this->input->query('root_id') !== null) ? (int) $this->input->query('root_id') : 0;
            $data = $this->newService()->getProductCategories($rootId);

            return Response::success(['groups' => $data], 200, $correlationId);
        });
    }

    /**
     * Return available filter facets.
     */
    public function facets(): HttpResponse
    {
        return $this->run('spec-search.facets', function (string $correlationId): HttpResponse {
            $payload = $this->input->json();
            $categoryIds = isset($payload['category_ids']) && is_array($payload['category_ids'])
                ? $payload['category_ids']
                : [];
            $facets = $this->newService()->getFacets($categoryIds);

            return Response::success(['facets' => $facets], 200, $correlationId);
        });
    }

    /**
     * Search products using selected categories and facet filters.
     */
    public function products(): HttpResponse
    {
        return $this->run('spec-search.products', function (string $correlationId): HttpResponse {
            $payload = $this->input->json();
            $categoryIds = isset($payload['category_ids']) && is_array($payload['category_ids'])
                ? $payload['category_ids']
                : [];
            $filters = isset($payload['filters']) && is_array($payload['filters'])
                ? $payload['filters']
                : [];

            $products = $this->newService()->getProducts($categoryIds, $filters);

            return Response::success(['items' => $products, 'total' => count($products)], 200, $correlationId);
        });
    }

    /**
     * Apply the existing request logging and error envelope around one search action.
     *
     * @param  callable(string): mixed  $action
     */
    private function run(string $name, callable $action): HttpResponse
    {
        $correlationId = $this->input->correlationId();
        $route = $this->input->route();
        $method = $this->input->method();
        $startedAt = microtime(true);
        Logger::info('request_start', ['route' => $route, 'method' => $method, 'action' => $name], $correlationId);

        try {
            $response = $action($correlationId);
        } catch (Throwable $e) {
            Logger::error('request_failed', [
                'route' => $route,
                'method' => $method,
                'action' => $name,
                'status' => 500,
                'errorCode' => 'internal_error',
                'exception' => $e,
                'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
            ], $correlationId);

            return Response::error('internal_error', 'Unexpected error', 500, $correlationId);
        }

        $context = [
            'route' => $route,
            'method' => $method,
            'action' => $name,
            'status' => 200,
            'durationMs' => (int) round((microtime(true) - $startedAt) * 1000),
        ];
        Logger::info('request_success', $context, $correlationId);

        return $response;
    }

    /** Create or resolve the service only when an endpoint needs it. */
    private function newService(): SpecSearchService
    {
        if ($this->specSearchService !== null) {
            return $this->specSearchService;
        }

        if ($this->specSearchServiceFactory !== null) {
            return ($this->specSearchServiceFactory)();
        }

        return new SpecSearchService;
    }
}
