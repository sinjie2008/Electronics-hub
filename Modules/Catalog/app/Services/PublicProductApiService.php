<?php

declare(strict_types=1);

namespace Modules\Catalog\Services;

use Modules\Catalog\Http\CatalogApiException;
use mysqli;

/** Read-only adapter over the existing Product Catalog Manager tables. */
final class PublicProductApiService
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $nodes = null;

    /** @var array<string, int> */
    private array $paths = [];

    /** @var array<int, list<int>> */
    private array $children = [];

    /** @var array<int, int> */
    private array $partCounts = [];

    public function __construct(private mysqli $db) {}

    public function root(): array
    {
        return [
            'name' => 'Public Product API',
            'sourceOfTruth' => 'existing Product Catalog Manager MySQL database',
            'readOnly' => true,
            'requiresMigration' => false,
            'requiresImport' => false,
            'requiresManualSync' => false,
            'endpointCount' => 10,
            'endpoints' => ['/api/', '/api/health', '/api/tree', '/api/resolve/{path}', '/api/categories/{path}', '/api/series/{path}', '/api/series/{path}/fields', '/api/series/{path}/parts', '/api/series/{path}/facets', '/api/search?q={keyword}'],
            'tree' => $this->tree(),
        ];
    }

    public function health(): array
    {
        $result = $this->db->query('SELECT 1');
        $result->close();

        return ['status' => 'ok', 'database' => 'connected', 'writeOperations' => false];
    }

    public function tree(): array
    {
        $this->load();
        $this->loadPartCounts(array_keys(array_filter($this->nodes, fn (array $node): bool => $node['type'] === 'series')));
        $tree = [];
        foreach ($this->children[0] ?? [] as $id) {
            $tree[] = $this->treeNode($id);
        }

        return $tree;
    }

    public function resolve(string $path): array
    {
        $node = $this->node($path);

        return ['resource' => $this->pub($node), 'breadcrumb' => $this->breadcrumb((int) $node['id'])];
    }

    public function category(string $path): array
    {
        $node = $this->node($path);
        if ($node['type'] !== 'category') {
            throw new CatalogApiException('CATEGORY_NOT_FOUND', 'Public category not found.', 404);
        }
        $childIds = $this->children[(int) $node['id']] ?? [];
        $this->loadPartCounts(array_values(array_filter($childIds, fn (int $id): bool => $this->nodes[$id]['type'] === 'series')));
        $items = [];
        foreach ($childIds as $id) {
            $child = $this->pub($this->nodes[$id]);
            if ($child['type'] === 'series') {
                $child['partCount'] = $this->countParts($id);
            }
            $items[] = $child;
        }

        return ['resource' => $this->pub($node), 'breadcrumb' => $this->breadcrumb((int) $node['id']), 'children' => $items];
    }

    public function series(string $path): array
    {
        $node = $this->seriesNode($path);
        $id = (int) $node['id'];

        return ['resource' => $this->pub($node), 'breadcrumb' => $this->breadcrumb($id), 'metadata' => $this->metadata($id), 'fields' => $this->fieldsById($id), 'partCount' => $this->countParts($id)];
    }

    public function fields(string $path): array
    {
        return $this->fieldsById((int) $this->seriesNode($path)['id']);
    }

    /**
     * Preserve PHP's natural ordering and JSON substring matching before pagination.
     * This requires materializing the selected series; database collation and LIKE
     * would change the public matching, null/default, and tie-breaking semantics.
     *
     * @param  array<string, mixed>  $q
     */
    public function parts(string $path, array $q): array
    {
        $node = $this->seriesNode($path);
        $seriesId = (int) $node['id'];
        $fields = $this->fieldsById($seriesId);
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[$field['key']] = $field;
        }
        $rows = $this->partRows($seriesId, $fieldMap);
        $search = trim((string) ($q['search'] ?? ''));
        $filters = $q['filter'] ?? [];
        if ($filters && ! is_array($filters)) {
            throw new CatalogApiException('INVALID_QUERY', 'filter must be an array.', 422);
        }
        foreach (array_keys((array) $filters) as $key) {
            if (! isset($fieldMap[$key])) {
                throw new CatalogApiException('INVALID_QUERY', 'Unknown filter field.', 422, ['field' => $key]);
            }
        }
        $rows = array_values(array_filter($rows, function (array $row) use ($search, $filters): bool {
            if ($search !== '' && stripos(json_encode($row, JSON_UNESCAPED_UNICODE) ?: '', $search) === false) {
                return false;
            }
            foreach ((array) $filters as $key => $wanted) {
                $actual = $row['values'][$key] ?? null;
                $wanted = is_array($wanted) ? $wanted : [$wanted];
                $matches = false;
                foreach ($wanted as $value) {
                    if (strcasecmp((string) $actual, (string) $value) === 0) {
                        $matches = true;
                        break;
                    }
                }
                if (! $matches) {
                    return false;
                }
            }

            return true;
        }));
        $sort = (string) ($q['sort'] ?? 'sku');
        $direction = strtolower((string) ($q['direction'] ?? 'asc'));
        if (! in_array($sort, ['id', 'sku', 'name'], true) && ! isset($fieldMap[$sort])) {
            throw new CatalogApiException('INVALID_QUERY', 'Unknown sort field.', 422);
        }
        if (! in_array($direction, ['asc', 'desc'], true)) {
            throw new CatalogApiException('INVALID_QUERY', 'direction must be asc or desc.', 422);
        }
        usort($rows, function (array $left, array $right) use ($sort, $direction): int {
            $leftValue = $left[$sort] ?? $left['values'][$sort] ?? '';
            $rightValue = $right[$sort] ?? $right['values'][$sort] ?? '';
            $comparison = strnatcasecmp((string) $leftValue, (string) $rightValue);
            if (! $comparison) {
                $comparison = $left['id'] <=> $right['id'];
            }

            return $direction === 'desc' ? -$comparison : $comparison;
        });
        $page = max(1, (int) ($q['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($q['per_page'] ?? 25)));
        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min($page, $lastPage);
        $offset = ($page - 1) * $perPage;

        return ['series' => $this->pub($node), 'fields' => $fields, 'parts' => array_slice($rows, $offset, $perPage), 'pagination' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => $lastPage]];
    }

    public function facets(string $path): array
    {
        $node = $this->seriesNode($path);
        $fields = $this->fieldsById((int) $node['id']);
        $fieldMap = [];
        foreach ($fields as $field) {
            $fieldMap[$field['key']] = $field;
        }
        $rows = $this->partRows((int) $node['id'], $fieldMap);
        $facets = [];
        foreach ($fields as $field) {
            $counts = [];
            foreach ($rows as $row) {
                $value = $row['values'][$field['key']] ?? null;
                if ($value === null || $value === '') {
                    continue;
                }
                $counts[(string) $value] = ($counts[(string) $value] ?? 0) + 1;
            }
            ksort($counts, SORT_NATURAL | SORT_FLAG_CASE);
            $values = [];
            foreach ($counts as $value => $count) {
                $values[] = ['value' => $value, 'count' => $count];
            }
            $facets[] = ['key' => $field['key'], 'label' => $field['label'], 'type' => $field['type'], 'values' => $values];
        }

        return ['series' => $this->pub($node), 'totalParts' => count($rows), 'facets' => $facets];
    }

    public function search(string $q): array
    {
        $q = trim($q);
        if ($q === '') {
            return ['query' => '', 'nodes' => [], 'parts' => []];
        }
        $this->load();
        $nodes = [];
        foreach ($this->nodes as $node) {
            if (stripos($node['name'], $q) !== false) {
                $nodes[] = $this->pub($node);
            }
        }
        $like = '%'.$q.'%';
        $statement = $this->db->prepare('SELECT id,series_id,sku,name,description FROM product WHERE sku LIKE ? OR name LIKE ? OR description LIKE ? ORDER BY name,id LIMIT 100');
        $statement->bind_param('sss', $like, $like, $like);
        $statement->execute();
        $result = $statement->get_result();
        $parts = [];
        while ($part = $result->fetch_assoc()) {
            if (! isset($this->nodes[(int) $part['series_id']])) {
                continue;
            }
            $parts[] = ['id' => (int) $part['id'], 'sku' => $part['sku'], 'name' => $part['name'], 'description' => $part['description'], 'series' => $this->pub($this->nodes[(int) $part['series_id']])];
        }
        $statement->close();

        return ['query' => $q, 'nodes' => $nodes, 'parts' => $parts];
    }

    private function load(): void
    {
        if ($this->nodes !== null) {
            return;
        }
        $result = $this->db->query('SELECT id,parent_id,name,type,display_order FROM category ORDER BY display_order,name,id');
        $rows = [];
        $children = [];
        while ($node = $result->fetch_assoc()) {
            $id = (int) $node['id'];
            $parentId = $node['parent_id'] === null ? 0 : (int) $node['parent_id'];
            $node['id'] = $id;
            $node['parent_id'] = $parentId ?: null;
            $node['display_order'] = (int) $node['display_order'];
            $node['slug'] = $this->slug($node['name']);
            $rows[$id] = $node;
            $children[$parentId][] = $id;
        }
        $result->close();
        foreach ($children as $ids) {
            do {
                $counts = [];
                foreach ($ids as $id) {
                    $counts[$rows[$id]['slug']] = ($counts[$rows[$id]['slug']] ?? 0) + 1;
                }
                $hasCollisions = false;
                foreach ($ids as $id) {
                    if ($counts[$rows[$id]['slug']] > 1) {
                        $rows[$id]['slug'] .= '-'.$id;
                        $hasCollisions = true;
                    }
                }
            } while ($hasCollisions);
        }
        $nodePaths = [];
        $index = [];
        foreach (array_keys($rows) as $id) {
            $chain = [];
            $seen = [];
            $currentId = $id;
            while (! isset($nodePaths[$currentId])) {
                if (isset($seen[$currentId])) {
                    throw new CatalogApiException('INVALID_HIERARCHY', 'Catalog hierarchy contains a cycle.', 500);
                }
                $seen[$currentId] = true;
                $chain[] = $currentId;
                $parentId = $rows[$currentId]['parent_id'];
                if (! $parentId || ! isset($rows[$parentId])) {
                    break;
                }
                $currentId = $parentId;
            }
            foreach (array_reverse($chain) as $chainId) {
                $parentId = $rows[$chainId]['parent_id'];
                $prefix = $parentId && isset($nodePaths[$parentId]) ? $nodePaths[$parentId].'/' : '';
                $nodePaths[$chainId] = $prefix.$rows[$chainId]['slug'];
            }
            $rows[$id]['path'] = $nodePaths[$id];
            if (isset($index[$rows[$id]['path']])) {
                throw new CatalogApiException('INVALID_HIERARCHY', 'Catalog hierarchy contains duplicate public paths.', 500);
            }
            $index[$rows[$id]['path']] = $id;
        }
        $this->nodes = $rows;
        $this->paths = $index;
        $this->children = $children;
    }

    private function treeNode(int $id): array
    {
        $node = $this->pub($this->nodes[$id]);
        $node['children'] = [];
        foreach ($this->children[$id] ?? [] as $childId) {
            $node['children'][] = $this->treeNode($childId);
        }
        if ($node['type'] === 'series') {
            $node['partCount'] = $this->countParts($id);
        }

        return $node;
    }

    private function node(string $path): array
    {
        $this->load();
        $path = trim(rawurldecode($path), '/');
        if ($path === '' || str_contains($path, '..')) {
            throw new CatalogApiException('INVALID_PATH', 'A valid catalog path is required.', 422);
        }
        $id = $this->paths[$path] ?? null;
        if (! $id) {
            throw new CatalogApiException('NOT_FOUND', 'Public catalog path not found.', 404, ['path' => $path]);
        }

        return $this->nodes[$id];
    }

    private function seriesNode(string $path): array
    {
        $node = $this->node($path);
        if ($node['type'] !== 'series') {
            throw new CatalogApiException('SERIES_NOT_FOUND', 'Public series not found.', 404);
        }

        return $node;
    }

    /** @param array<string, mixed> $node */
    private function pub(array $node): array
    {
        return ['id' => (int) $node['id'], 'parentId' => $node['parent_id'] === null ? null : (int) $node['parent_id'], 'name' => $node['name'], 'type' => $node['type'], 'slug' => $node['slug'], 'path' => $node['path'], 'displayOrder' => (int) $node['display_order']];
    }

    private function breadcrumb(int $id): array
    {
        $this->load();
        $breadcrumb = [];
        $seen = [];
        while (isset($this->nodes[$id]) && ! isset($seen[$id])) {
            $seen[$id] = true;
            array_unshift($breadcrumb, $this->pub($this->nodes[$id]));
            $parentId = $this->nodes[$id]['parent_id'];
            if ($parentId === null) {
                break;
            }
            $id = (int) $parentId;
        }

        return $breadcrumb;
    }

    private function countParts(int $sid): int
    {
        $this->loadPartCounts([$sid]);

        return $this->partCounts[$sid];
    }

    /** @param list<int> $seriesIds */
    private function loadPartCounts(array $seriesIds): void
    {
        $missingIds = [];
        foreach ($seriesIds as $id) {
            if (! array_key_exists($id, $this->partCounts)) {
                $missingIds[$id] = $id;
            }
        }
        if ($missingIds === []) {
            return;
        }
        $ids = implode(',', $missingIds);
        $result = $this->db->query("SELECT series_id,COUNT(1) AS part_count FROM product WHERE series_id IN ($ids) GROUP BY series_id");
        foreach ($missingIds as $id) {
            $this->partCounts[$id] = 0;
        }
        while ($row = $result->fetch_assoc()) {
            $this->partCounts[(int) $row['series_id']] = (int) $row['part_count'];
        }
        $result->close();
    }

    private function fieldsById(int $sid): array
    {
        $statement = $this->db->prepare("SELECT id,field_key,label,field_type,default_value,sort_order,is_required FROM series_custom_field WHERE series_id=? AND field_scope='product_attribute' AND COALESCE(is_public_portal_hidden,0)=0 ORDER BY sort_order,id");
        $statement->bind_param('i', $sid);
        $statement->execute();
        $result = $statement->get_result();
        $fields = [];
        while ($field = $result->fetch_assoc()) {
            $fields[] = ['id' => (int) $field['id'], 'key' => $field['field_key'], 'label' => $field['label'], 'type' => $field['field_type'], 'defaultValue' => $field['default_value'], 'sortOrder' => (int) $field['sort_order'], 'required' => (int) $field['is_required'] === 1];
        }
        $statement->close();

        return $fields;
    }

    private function metadata(int $sid): array
    {
        $statement = $this->db->prepare("SELECT f.field_key,f.label,f.field_type,f.default_value,f.sort_order,v.value FROM series_custom_field f LEFT JOIN series_custom_field_value v ON v.series_custom_field_id=f.id AND v.series_id=f.series_id WHERE f.series_id=? AND f.field_scope='series_metadata' AND COALESCE(f.is_public_portal_hidden,0)=0 ORDER BY f.sort_order,f.id");
        $statement->bind_param('i', $sid);
        $statement->execute();
        $result = $statement->get_result();
        $metadata = [];
        while ($field = $result->fetch_assoc()) {
            $metadata[] = ['key' => $field['field_key'], 'label' => $field['label'], 'type' => $field['field_type'], 'value' => $field['value'] ?? $field['default_value'], 'sortOrder' => (int) $field['sort_order']];
        }
        $statement->close();

        return $metadata;
    }

    /** @param array<string, array<string, mixed>> $fields */
    private function partRows(int $sid, array $fields): array
    {
        $statement = $this->db->prepare('SELECT id,sku,name,description FROM product WHERE series_id=? ORDER BY id');
        $statement->bind_param('i', $sid);
        $statement->execute();
        $result = $statement->get_result();
        $parts = [];
        $fieldIds = [];
        foreach ($fields as $key => $field) {
            $fieldIds[(int) $field['id']] = $key;
        }
        while ($part = $result->fetch_assoc()) {
            $id = (int) $part['id'];
            $values = [];
            foreach ($fields as $key => $field) {
                $values[$key] = $field['defaultValue'];
            }
            $parts[$id] = ['id' => $id, 'sku' => $part['sku'], 'name' => $part['name'], 'description' => $part['description'], 'values' => $values];
        }
        $statement->close();
        if ($parts === [] || $fieldIds === []) {
            return array_values($parts);
        }
        $ids = implode(',', array_map('intval', array_keys($fieldIds)));
        $statement = $this->db->prepare("SELECT v.product_id,v.series_custom_field_id,v.value FROM product_custom_field_value v INNER JOIN product p ON p.id=v.product_id WHERE p.series_id=? AND v.series_custom_field_id IN ($ids)");
        $statement->bind_param('i', $sid);
        $statement->execute();
        $result = $statement->get_result();
        while ($value = $result->fetch_assoc()) {
            $productId = (int) $value['product_id'];
            $key = $fieldIds[(int) $value['series_custom_field_id']] ?? null;
            if ($key !== null && isset($parts[$productId])) {
                $parts[$productId]['values'][$key] = $value['value'];
            }
        }
        $statement->close();

        return array_values($parts);
    }

    private function slug(string $v): string
    {
        $v = strtolower(trim($v));
        $v = preg_replace('/[^\pL\pN]+/u', '-', $v) ?? '';
        $v = trim($v, '-');

        return $v !== '' ? $v : 'item';
    }
}
