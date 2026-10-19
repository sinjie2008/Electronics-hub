<?php

declare(strict_types=1);

namespace Modules\Catalog\Http\Requests\PublicApi;

use Illuminate\Http\Request;
use Modules\Catalog\Http\CatalogApiException;

/** Validates query shapes before any dynamic query is constructed. */
final class ProductApiInput
{
    /** @return array<string, mixed> */
    public function query(Request $request, string $endpoint): array
    {
        $query = $request->query->all();
        $allowed = match ($endpoint) {
            'parts' => ['search', 'filter', 'sort', 'direction', 'page', 'per_page'],
            'search' => ['q'],
            default => [],
        };
        if (array_diff(array_keys($query), $allowed) !== []) {
            $this->invalid('Unknown query parameter.');
        }

        foreach (['q', 'search', 'sort', 'direction'] as $key) {
            if (array_key_exists($key, $query)) {
                if ($query[$key] === null) {
                    $query[$key] = '';
                }
                if (! is_string($query[$key]) || ! mb_check_encoding($query[$key], 'UTF-8')) {
                    $this->invalid($key.' must be a string.');
                }
                $limit = in_array($key, ['q', 'search'], true)
                    ? (int) config('catalog.public_api.max_search_length', 100) : 64;
                if (mb_strlen($query[$key]) > $limit || preg_match('/[\x00-\x1F\x7F]/', $query[$key])) {
                    $this->invalid($key.' exceeds its allowed length or contains invalid characters.');
                }
                $query[$key] = trim($query[$key]);
            }
        }

        if (isset($query['direction'])) {
            $query['direction'] = strtolower($query['direction']);
            if (! in_array($query['direction'], ['asc', 'desc'], true)) {
                $this->invalid('direction must be asc or desc.');
            }
        }

        foreach (['page', 'per_page'] as $key) {
            if (array_key_exists($key, $query)) {
                $maximum = $key === 'per_page' ? min(100, (int) config('catalog.public_api.max_per_page', 100)) : 2147483647;
                if ((! is_string($query[$key]) && ! is_int($query[$key]))
                    || ! preg_match('/^[1-9][0-9]*$/D', (string) $query[$key])
                    || strlen((string) $query[$key]) > 10 || (int) $query[$key] > $maximum) {
                    $this->invalid($key.' must be a positive integer within its allowed limit.');
                }
                $query[$key] = (int) $query[$key];
            }
        }

        if (array_key_exists('filter', $query)) {
            if (! is_array($query['filter']) || count($query['filter']) > (int) config('catalog.public_api.max_filters', 10)) {
                $this->invalid('filter must be a bounded field map.');
            }
            foreach ($query['filter'] as $key => $wanted) {
                if (strlen((string) $key) === 0
                    || mb_strlen((string) $key) > 64 || ! mb_check_encoding((string) $key, 'UTF-8')
                    || preg_match('/[\x00-\x1F\x7F]/', (string) $key)) {
                    $this->invalid('Invalid filter field.');
                }
                $values = is_array($wanted) ? $wanted : [$wanted];
                if (! array_is_list($values) || count($values) > (int) config('catalog.public_api.max_filter_values', 20)) {
                    $this->invalid('Filter values must be a bounded list.');
                }
                foreach ($values as $value) {
                    if ((! is_string($value) && ! is_int($value) && $value !== null)
                        || ! mb_check_encoding((string) $value, 'UTF-8')
                        || mb_strlen((string) $value) > (int) config('catalog.public_api.max_value_length', 255)) {
                        $this->invalid('Invalid filter value.');
                    }
                }
            }
        }

        if ($endpoint === 'parts') {
            $query += ['page' => 1, 'per_page' => min(100, max(1, (int) config('catalog.public_api.default_per_page', 25)))];
        }
        ksort($query);

        return $query;
    }

    private function invalid(string $message): never
    {
        throw new CatalogApiException('INVALID_QUERY', $message, 422);
    }
}
