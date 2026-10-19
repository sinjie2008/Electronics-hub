<?php

declare(strict_types=1);

namespace Modules\Catalog\Repositories\PublicApi;

use Illuminate\Database\Connection;
use Modules\Catalog\Http\CatalogApiException;

/**
 * Reads public catalog data exclusively through the dedicated read connection.
 *
 * @phpstan-type PublicField array{id: int, key: string, label: string, type: string, defaultValue: ?string, sortOrder: int, required: bool}
 */
final class ProductApiRepository
{
    private int $requestReadBytes = 2;

    public function __construct(private Connection $connection) {}

    /** @return list<array{id: int, parent_id: ?int, name: string, type: string, display_order: int}> */
    public function hierarchy(): array
    {
        $limit = max(1, (int) config('catalog.public_api.max_nodes', 10000));
        $rows = $this->rows(
            'SELECT id, parent_id, name, type, display_order FROM category ORDER BY display_order, name, id LIMIT ?',
            [$limit + 1]
        );

        if (count($rows) > $limit) {
            throw new CatalogApiException('RESOURCE_LIMIT', 'The public catalog hierarchy exceeds its configured limit.', 503);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
            'name' => (string) $row['name'],
            'type' => (string) $row['type'],
            'display_order' => (int) $row['display_order'],
        ], $rows);
    }

    /** @param list<int>|null $seriesIds
     * @return array<int, int>
     */
    public function counts(?array $seriesIds = null): array
    {
        if ($seriesIds === []) {
            return [];
        }
        $where = $seriesIds !== null ? ' WHERE series_id IN ('.implode(',', array_fill(0, count($seriesIds), '?')).')' : '';
        $counts = [];
        foreach ($this->rows('SELECT series_id, COUNT(*) AS part_count FROM product'.$where.' GROUP BY series_id', $seriesIds ?? []) as $row) {
            $counts[(int) $row['series_id']] = (int) $row['part_count'];
        }

        return $counts;
    }

    /** @return list<array<string, mixed>> */
    public function fields(int $seriesId, string $scope = 'product_attribute'): array
    {
        $limit = max(1, (int) config('catalog.public_api.max_fields', 200));
        $rows = $this->rows(
            'SELECT id, field_key, label, field_type, default_value, sort_order, is_required
             FROM series_custom_field
             WHERE series_id = ? AND field_scope = ?
               AND COALESCE(is_public_portal_hidden, 0) = 0 AND COALESCE(is_public_hidden, 0) = 0
             ORDER BY sort_order, id LIMIT ?',
            [$seriesId, $scope, $limit + 1]
        );

        if (count($rows) > $limit) {
            throw new CatalogApiException('RESOURCE_LIMIT', 'Public fields exceed their configured limit.', 503);
        }

        return $rows;
    }

    /** @return list<array<string, mixed>> */
    public function metadata(int $seriesId): array
    {
        $limit = max(1, (int) config('catalog.public_api.max_fields', 200));
        $rows = $this->rows(
            "SELECT f.id, f.field_key, f.label, f.field_type, f.default_value, f.sort_order, f.is_required,
                    COALESCE(v.value, f.default_value) AS value
             FROM series_custom_field f
             LEFT JOIN series_custom_field_value v ON v.series_custom_field_id = f.id AND v.series_id = f.series_id
             WHERE f.series_id = ? AND f.field_scope = 'series_metadata'
               AND COALESCE(f.is_public_portal_hidden, 0) = 0 AND COALESCE(f.is_public_hidden, 0) = 0
             ORDER BY f.sort_order, f.id LIMIT ?",
            [$seriesId, $limit + 1]
        );

        if (count($rows) > $limit) {
            throw new CatalogApiException('RESOURCE_LIMIT', 'Public metadata fields exceed their configured limit.', 503);
        }

        return $rows;
    }

    /**
     * @param  list<PublicField>  $fields
     * @param  array<string, mixed>  $query
     * @return array{parts: list<array{id: int, sku: string, name: string, description: ?string, values: array<string, ?string>}>, pagination: array{page: int, per_page: int, total: int, last_page: int}}
     */
    public function partPage(int $seriesId, array $fields, array $query): array
    {
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[$field['key']] = $field;
        }

        $sort = (string) ($query['sort'] ?? 'sku');
        $direction = strtolower((string) ($query['direction'] ?? 'asc'));
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new CatalogApiException('INVALID_QUERY', 'direction must be asc or desc.', 422);
        }

        if (! in_array($sort, ['id', 'sku', 'name'], true)) {
            $this->queryField($fieldMap, $sort);
        }

        $filters = $query['filter'] ?? [];
        if (! is_array($filters)) {
            throw new CatalogApiException('INVALID_QUERY', 'filter must be an array.', 422);
        }

        $joinFields = [];
        foreach ($filters as $key => $values) {
            $field = $this->queryField($fieldMap, (string) $key);
            $joinFields[$field['key']] = $field;
        }
        if (! in_array($sort, ['id', 'sku', 'name'], true)) {
            $joinFields[$sort] = $fieldMap[$sort];
        }

        $from = ' FROM product p';
        $bindings = [];
        $expressions = [];
        foreach (array_values($joinFields) as $index => $field) {
            $from .= " LEFT JOIN series_custom_field f{$index}
                ON f{$index}.id = ? AND f{$index}.series_id = p.series_id
                AND f{$index}.field_scope = 'product_attribute' AND f{$index}.field_type <> 'file'
                AND COALESCE(f{$index}.is_public_portal_hidden, 0) = 0 AND COALESCE(f{$index}.is_public_hidden, 0) = 0
                LEFT JOIN product_custom_field_value v{$index}
                ON v{$index}.product_id = p.id AND v{$index}.series_custom_field_id = f{$index}.id";
            $bindings[] = $field['id'];
            $expressions[$field['key']] = "CASE WHEN v{$index}.id IS NULL THEN f{$index}.default_value ELSE v{$index}.value END";
        }

        $where = ' WHERE p.series_id = ?';
        $bindings[] = $seriesId;
        $search = trim((string) ($query['search'] ?? ''));
        if ($search !== '') {
            $like = $this->literalLike($search);
            $where .= " AND (LOWER(CAST(p.id AS CHAR)) LIKE LOWER(?) ESCAPE '!'
                OR LOWER(p.sku) LIKE LOWER(?) ESCAPE '!'
                OR LOWER(p.name) LIKE LOWER(?) ESCAPE '!'
                OR LOWER(p.description) LIKE LOWER(?) ESCAPE '!'
                OR EXISTS (
                    SELECT 1 FROM series_custom_field sf
                    LEFT JOIN product_custom_field_value sv ON sv.series_custom_field_id = sf.id AND sv.product_id = p.id
                    WHERE sf.series_id = p.series_id AND sf.field_scope = 'product_attribute' AND sf.field_type <> 'file'
                      AND COALESCE(sf.is_public_portal_hidden, 0) = 0 AND COALESCE(sf.is_public_hidden, 0) = 0
                      AND LOWER(CASE WHEN sv.id IS NULL THEN sf.default_value ELSE sv.value END) LIKE LOWER(?) ESCAPE '!'
                ))";
            array_push($bindings, $like, $like, $like, $like, $like);
        }

        foreach ($filters as $key => $wanted) {
            $values = is_array($wanted) ? $wanted : [$wanted];
            if ($values === []) {
                $where .= ' AND 1 = 0';

                continue;
            }

            $alternatives = [];
            foreach ($values as $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw new CatalogApiException('INVALID_QUERY', 'Filter values must be scalar.', 422);
                }
                $alternatives[] = 'BINARY LOWER(COALESCE('.$expressions[$key].", '')) = BINARY LOWER(?)";
                $bindings[] = (string) $value;
            }
            $where .= ' AND ('.implode(' OR ', $alternatives).')';
        }

        $total = (int) $this->rows('SELECT COUNT(*) AS aggregate'.$from.$where, $bindings)[0]['aggregate'];
        $perPage = max(1, min(100, (int) ($query['per_page'] ?? 25)));
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) ($query['page'] ?? 1)), $lastPage);

        $sortExpression = match ($sort) {
            'id' => 'p.id',
            'sku' => 'BINARY LOWER(p.sku)',
            'name' => 'BINARY LOWER(p.name)',
            default => $fieldMap[$sort]['type'] === 'number'
                ? 'CAST(('.$expressions[$sort].') AS DECIMAL(65, 20))'
                : 'BINARY LOWER(COALESCE(('.$expressions[$sort]."), ''))",
        };

        $parts = $this->rows(
            'SELECT p.id, p.sku, p.name, p.description'.$from.$where
                ." ORDER BY {$sortExpression} {$direction}, p.id {$direction} LIMIT ? OFFSET ?",
            [...$bindings, $perPage, ($page - 1) * $perPage]
        );

        return [
            'parts' => $this->partValues($parts, $fields),
            'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage],
        ];
    }

    /**
     * @param  list<PublicField>  $fields
     * @return list<array{key: string, label: string, type: string, values: list<array{value: string|int, count: int}>}>
     */
    public function facets(int $seriesId, array $fields): array
    {
        $facets = [];
        $limit = max(1, (int) config('catalog.public_api.max_facet_values', 1000));

        foreach ($fields as $field) {
            $values = [];
            if ($field['type'] !== 'file') {
                $rows = $this->rows(
                    "SELECT effective_value AS value, COUNT(*) AS part_count, MIN(product_id) AS first_product_id FROM (
                        SELECT p.id AS product_id, CASE WHEN v.id IS NULL THEN f.default_value ELSE v.value END AS effective_value
                        FROM product p
                        JOIN series_custom_field f ON f.id = ? AND f.series_id = p.series_id
                        LEFT JOIN product_custom_field_value v ON v.product_id = p.id AND v.series_custom_field_id = f.id
                        WHERE p.series_id = ? AND f.field_scope = 'product_attribute' AND f.field_type <> 'file'
                          AND COALESCE(f.is_public_portal_hidden, 0) = 0 AND COALESCE(f.is_public_hidden, 0) = 0
                    ) public_values
                    WHERE effective_value IS NOT NULL AND OCTET_LENGTH(effective_value) > 0
                    GROUP BY BINARY effective_value, effective_value ORDER BY first_product_id LIMIT ?",
                    [$field['id'], $seriesId, $limit + 1]
                );

                if (count($rows) > $limit) {
                    throw new CatalogApiException('RESOURCE_LIMIT', 'Public facet values exceed their configured limit.', 503);
                }

                usort($rows, static fn (array $left, array $right): int => strnatcasecmp((string) $left['value'], (string) $right['value']));
                foreach ($rows as $row) {
                    $value = (string) $row['value'];
                    $bucket = [$value => true];
                    $values[] = ['value' => array_key_first($bucket), 'count' => (int) $row['part_count']];
                }
            }

            $facets[] = ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'], 'values' => $values];
        }

        return $facets;
    }

    /** @return list<array<string, mixed>> */
    public function search(string $term): array
    {
        if (trim($term) === '') {
            return [];
        }

        $like = $this->literalLike(trim($term));

        return $this->rows(
            "SELECT id, series_id, sku, name, description FROM product
             WHERE LOWER(sku) LIKE LOWER(?) ESCAPE '!' OR LOWER(name) LIKE LOWER(?) ESCAPE '!'
                OR LOWER(description) LIKE LOWER(?) ESCAPE '!'
             ORDER BY name, id LIMIT 100",
            [$like, $like, $like]
        );
    }

    public function health(): void
    {
        $this->rows('SELECT 1');
    }

    /**
     * @param  array<string, PublicField>  $fields
     * @return PublicField
     */
    private function queryField(array $fields, string $key): array
    {
        if (! isset($fields[$key]) || $fields[$key]['type'] === 'file') {
            throw new CatalogApiException('INVALID_QUERY', 'Unknown or unavailable query field.', 422, ['field' => $key]);
        }

        return $fields[$key];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<PublicField>  $fields
     * @return list<array{id: int, sku: string, name: string, description: ?string, values: array<string, ?string>}>
     */
    private function partValues(array $rows, array $fields): array
    {
        $parts = [];
        $fieldKeys = [];
        $defaults = [];
        foreach ($fields as $field) {
            $fieldKeys[$field['id']] = $field['key'];
            $defaults[$field['key']] = $field['defaultValue'];
        }
        foreach ($rows as $row) {
            $parts[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'sku' => (string) $row['sku'],
                'name' => (string) $row['name'],
                'description' => $row['description'] === null ? null : (string) $row['description'],
                'values' => $defaults,
            ];
        }

        if ($parts !== [] && $fieldKeys !== []) {
            $productSlots = implode(',', array_fill(0, count($parts), '?'));
            $fieldSlots = implode(',', array_fill(0, count($fieldKeys), '?'));
            $values = $this->rows(
                "SELECT v.product_id, v.series_custom_field_id, v.value FROM product_custom_field_value v
                 JOIN series_custom_field f ON f.id = v.series_custom_field_id
                 WHERE v.product_id IN ({$productSlots}) AND v.series_custom_field_id IN ({$fieldSlots})
                   AND f.field_scope = 'product_attribute'
                   AND COALESCE(f.is_public_portal_hidden, 0) = 0 AND COALESCE(f.is_public_hidden, 0) = 0",
                [...array_keys($parts), ...array_keys($fieldKeys)]
            );
            foreach ($values as $value) {
                $key = $fieldKeys[(int) $value['series_custom_field_id']];
                $parts[(int) $value['product_id']]['values'][$key] = $value['value'] === null ? null : (string) $value['value'];
            }
        }

        foreach ($parts as $part) {
            $this->chargeRow($part);
        }

        return array_values($parts);
    }

    private function literalLike(string $value): string
    {
        return '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value).'%';
    }

    /**
     * @param  list<mixed>  $bindings
     * @return list<array<string, mixed>>
     */
    private function rows(string $sql, array $bindings = []): array
    {
        $rows = [];
        $cursor = $this->connection->cursor($sql, $bindings);

        try {
            foreach ($cursor as $record) {
                $row = (array) $record;
                $this->chargeRow($row);
                $rows[] = $row;
            }
        } finally {
            unset($cursor);
        }

        return $rows;
    }

    /**
     * Charges both database rows and expanded parts so repeated defaults cannot
     * multiply a small database result into an unbounded serialized response.
     *
     * @param  array<string, mixed>  $row
     */
    private function chargeRow(array $row): void
    {
        $limit = max(1, (int) config('catalog.public_api.max_response_bytes', 8388608));
        $rowBytes = strlen(json_encode($row, JSON_THROW_ON_ERROR)) + 1;

        if ($rowBytes > $limit - $this->requestReadBytes) {
            throw new CatalogApiException('RESOURCE_LIMIT', 'Public catalog data exceeds its configured byte limit.', 503);
        }

        $this->requestReadBytes += $rowBytes;
    }
}
