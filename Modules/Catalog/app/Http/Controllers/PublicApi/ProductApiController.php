<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Controllers\PublicApi;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Modules\Catalog\Http\CatalogApiException;
use Modules\Catalog\Http\Requests\PublicApi\ProductApiInput;
use Modules\Catalog\Services\PublicApi\ProductApiService;
use Throwable;

final class ProductApiController
{
    /** @param Closure(): ProductApiService $serviceFactory */
    public function __construct(private Closure $serviceFactory, private ProductApiInput $input) {}

    public function __invoke(Request $request): JsonResponse
    {
        $correlationId = (string) $request->attributes->get('catalog_public_correlation_id');
        try {
            $endpoint = (string) $request->route('catalog_public_endpoint', 'unknown');
            $path = (string) $request->route('path', '');
            $query = $this->input->query($request, $endpoint);
            if ($endpoint === 'unknown') {
                throw new CatalogApiException('NOT_FOUND', 'API route not found.', 404);
            }
            // Resolve the connection inside the error boundary, including connection failures.
            $load = function () use ($endpoint, $path, $query): array {
                $api = ($this->serviceFactory)();

                return match ($endpoint) {
                    'root' => $api->root(),
                    'health' => $api->health(),
                    'tree' => $api->tree(),
                    'resolve' => $api->resolve($path),
                    'categories' => $api->category($path),
                    'series', 'fields', 'parts', 'facets' => $this->series($api, $endpoint, $path, $query),
                    'search' => $api->search($query['q'] ?? ''),
                    default => throw new CatalogApiException('NOT_FOUND', 'API route not found.', 404),
                };
            };
            $data = $this->cachedHierarchy($endpoint, $path, $load);
            $response = new JsonResponse(['success' => true, 'data' => $data, 'correlationId' => $correlationId]);
            if (strlen($response->getContent()) > (int) config('catalog.public_api.max_response_bytes', 8388608)) {
                throw new CatalogApiException('RESOURCE_LIMIT', 'The public response exceeds its configured limit.', 503);
            }

            return $response;
        } catch (CatalogApiException $exception) {
            if ($exception->getStatusCode() >= 500) {
                Log::warning('Public product API is unavailable.', [
                    'correlationId' => $correlationId, 'errorCode' => $exception->getErrorCode(),
                ]);
            }

            return $this->error($exception->getErrorCode(), $exception->getMessage(), $exception->getStatusCode(), $exception->getDetails(), $correlationId);
        } catch (Throwable $exception) {
            // Exception messages / SQL bindings may contain private catalog values or credentials.
            Log::error('Public product API request failed.', [
                'correlationId' => $correlationId, 'exceptionClass' => $exception::class,
                'file' => basename($exception->getFile()), 'line' => $exception->getLine(),
            ]);

            return $this->error('INTERNAL_ERROR', 'The public product API is unavailable.', 500, [], $correlationId);
        }
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>|list<mixed>
     */
    private function series(ProductApiService $api, string $endpoint, string $path, array $query): array
    {
        if ($endpoint !== 'series' && $api->hasSeries($path.'/'.$endpoint)) {
            return $api->series($path.'/'.$endpoint);
        }

        try {
            return match ($endpoint) {
                'series' => $api->series($path),
                'fields' => $api->fields($path),
                'parts' => $api->parts($path, $query),
                'facets' => $api->facets($path),
                default => throw new CatalogApiException('NOT_FOUND', 'API route not found.', 404),
            };
        } catch (CatalogApiException $exception) {
            if ($exception->getErrorCode() === 'NOT_FOUND') {
                throw new CatalogApiException('SERIES_NOT_FOUND', 'Public series not found.', 404);
            }

            throw $exception;
        }
    }

    /** @param Closure(): array<mixed> $load
     * @return array<mixed>
     */
    private function cachedHierarchy(string $endpoint, string $path, Closure $load): array
    {
        $ttl = max(0, (int) config('catalog.public_api.cache_ttl', 300));
        if ($ttl === 0 || ! in_array($endpoint, ['root', 'tree', 'resolve', 'categories'], true)) {
            return $load();
        }
        $database = (array) config('database.connections.catalog_public_api', []);
        unset($database['password']);
        $fingerprint = hash('sha256', json_encode([$database, $endpoint, $path], JSON_THROW_ON_ERROR));
        $slots = max(1, min(4096, (int) config('catalog.public_api.cache_slots', 512)));
        $slot = hexdec(substr($fingerprint, 0, 7)) % $slots;
        $cache = Cache::store(config('catalog.public_api.cache_store'));
        $key = 'catalog-public-api:v1:'.hash('sha256', base_path()).':'.$slot;
        $entry = $cache->get($key);
        if (is_array($entry) && ($entry['fingerprint'] ?? '') === $fingerprint && is_array($entry['data'] ?? null)) {
            return $entry['data'];
        }
        $data = $load();
        if (strlen(json_encode($data, JSON_THROW_ON_ERROR)) <= (int) config('catalog.public_api.max_cache_bytes', 1048576)) {
            $cache->put($key, ['fingerprint' => $fingerprint, 'data' => $data], $ttl);
        }

        return $data;
    }

    /** @param array<string, mixed> $details */
    private function error(string $code, string $message, int $status, array $details, string $correlationId): JsonResponse
    {
        return new JsonResponse([
            'success' => false, 'errorCode' => $code, 'message' => $message,
            'details' => $details, 'correlationId' => $correlationId,
        ], $status);
    }
}
