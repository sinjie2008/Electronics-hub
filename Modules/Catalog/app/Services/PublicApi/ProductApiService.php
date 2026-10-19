<?php

declare(strict_types=1);

namespace Modules\Catalog\Services\PublicApi;

use Modules\Catalog\Http\CatalogApiException;
use Modules\Catalog\Repositories\PublicApi\ProductApiRepository;

/** Source-compatible public payloads, independent of all Catalog write services. */
final class ProductApiService
{
    /** @var array<int, array<string, mixed>>|null */
    private ?array $nodes = null;

    /** @var array<string, int> */
    private array $paths = [];

    /** @var array<int, list<int>> */
    private array $children = [];

    /** @var array<int, int>|null */
    private ?array $partCounts = null;

    private int $pathBytes = 0;

    public function __construct(private ProductApiRepository $repository) {}

    /** @return array<string, mixed> */
    public function root(): array
    {
        $base = '/api/v1/catalog';

        return [
            'name' => 'Public Product API',
            'sourceOfTruth' => 'existing Product Catalog Manager MySQL database',
            'readOnly' => true,
            'requiresMigration' => false,
            'requiresImport' => false,
            'requiresManualSync' => false,
            'endpointCount' => 10,
            'endpoints' => [
                $base, $base.'/health', $base.'/tree', $base.'/resolve/{path}', $base.'/categories/{path}',
                $base.'/series/{path}', $base.'/series/{path}/fields', $base.'/series/{path}/parts',
                $base.'/series/{path}/facets', $base.'/search?q={keyword}',
            ],
            'tree' => $this->tree(),
        ];
    }

    /** @return array{status: string, writeOperations: bool} */
    public function health(): array
    {
        $this->repository->health();

        return ['status' => 'ok', 'writeOperations' => false];
    }

    /** @return list<array<string, mixed>> */
    public function tree(): array
    {
        $this->load();
        $ids = array_keys(array_filter($this->nodes, static fn (array $node): bool => $node['type'] === 'series'));
        $this->partCounts = $this->repository->counts($ids);

        return array_map(fn (int $id): array => $this->treeNode($id), $this->children[0] ?? []);
    }

    /** @return array<string, mixed> */
    public function resolve(string $path): array
    {
        $node = $this->node($path);

        return ['resource' => $this->publicNode($node), 'breadcrumb' => $this->breadcrumb($node['id'])];
    }

    /** @return array<string, mixed> */
    public function category(string $path): array
    {
        $node = $this->node($path);
        if ($node['type'] !== 'category') {
            throw new CatalogApiException('CATEGORY_NOT_FOUND', 'Public category not found.', 404);
        }
        $children = [];
        $seriesIds = array_values(array_filter($this->children[$node['id']] ?? [], fn (int $id): bool => $this->nodes[$id]['type'] === 'series'));
        $this->partCounts = $this->repository->counts($seriesIds);
        foreach ($this->children[$node['id']] ?? [] as $id) {
            $child = $this->publicNode($this->nodes[$id]);
            if ($child['type'] === 'series') {
                $child['partCount'] = $this->countParts($id);
            }
            $children[] = $child;
        }

        return ['resource' => $this->publicNode($node), 'breadcrumb' => $this->breadcrumb($node['id']), 'children' => $children];
    }

    /** @return array<string, mixed> */
    public function series(string $path): array
    {
        $node = $this->seriesNode($path);
        $metadata = [];
        foreach ($this->repository->metadata($node['id']) as $field) {
            $metadata[] = [
                'key' => $field['field_key'],
                'label' => $field['label'],
                'type' => $field['field_type'],
                'value' => $this->publicValue($field['value'], $field['field_type']),
                'sortOrder' => (int) $field['sort_order'],
            ];
        }
        $this->checkFieldCount(count($metadata));

        return [
            'resource' => $this->publicNode($node),
            'breadcrumb' => $this->breadcrumb($node['id']),
            'metadata' => $metadata,
            'fields' => $this->fields($path),
            'partCount' => $this->countParts($node['id']),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function fields(string $path): array
    {
        $node = $this->seriesNode($path);
        $fields = [];
        foreach ($this->repository->fields($node['id']) as $field) {
            $fields[] = [
                'id' => (int) $field['id'],
                'key' => (string) $field['field_key'],
                'label' => (string) $field['label'],
                'type' => (string) $field['field_type'],
                'defaultValue' => $this->publicValue($field['default_value'], $field['field_type']),
                'sortOrder' => (int) $field['sort_order'],
                'required' => (int) $field['is_required'] === 1,
            ];
        }
        $this->checkFieldCount(count($fields));

        return $fields;
    }

    /** @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function parts(string $path, array $query): array
    {
        $node = $this->seriesNode($path);
        $fields = $this->fields($path);
        $page = $this->repository->partPage($node['id'], $fields, $query);
        foreach ($page['parts'] as &$part) {
            foreach ($fields as $field) {
                $part['values'][$field['key']] = $this->publicValue($part['values'][$field['key']] ?? null, $field['type']);
            }
        }
        unset($part);

        return ['series' => $this->publicNode($node), 'fields' => $fields, ...$page];
    }

    /** @return array<string, mixed> */
    public function facets(string $path): array
    {
        $node = $this->seriesNode($path);
        $fields = $this->fields($path);

        return [
            'series' => $this->publicNode($node),
            'totalParts' => $this->countParts($node['id']),
            'facets' => $this->repository->facets($node['id'], $fields),
        ];
    }

    /** @return array<string, mixed> */
    public function search(string $term): array
    {
        $term = trim($term);
        if ($term === '') {
            return ['query' => '', 'nodes' => [], 'parts' => []];
        }
        $this->load();
        $nodes = [];
        foreach ($this->nodes as $node) {
            if (mb_stripos($node['name'], $term) !== false) {
                $nodes[] = $this->publicNode($node);
            }
        }
        $parts = [];
        foreach ($this->repository->search($term) as $row) {
            $series = $this->nodes[(int) $row['series_id']] ?? null;
            if ($series === null || $series['type'] !== 'series') {
                continue;
            }
            $parts[] = [
                'id' => (int) $row['id'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'description' => $row['description'],
                'series' => $this->publicNode($series),
            ];
        }

        return ['query' => $term, 'nodes' => $nodes, 'parts' => $parts];
    }

    /** A real series whose slug is a reserved suffix wins, as in the source. */
    public function hasSeries(string $path): bool
    {
        $this->load();
        $id = $this->paths[trim($path, '/')] ?? null;

        return $id !== null && $this->nodes[$id]['type'] === 'series';
    }

    private function load(): void
    {
        if ($this->nodes !== null) {
            return;
        }
        $this->nodes = [];
        foreach ($this->repository->hierarchy() as $row) {
            $row['slug'] = $this->slug($row['name']);
            $this->nodes[$row['id']] = $row;
            $this->children[$row['parent_id'] ?? 0][] = $row['id'];
        }

        foreach ($this->children as $ids) {
            $counts = [];
            foreach ($ids as $id) {
                $slug = $this->nodes[$id]['slug'];
                $counts[$slug] = ($counts[$slug] ?? 0) + 1;
            }
            foreach ($ids as $id) {
                if ($counts[$this->nodes[$id]['slug']] > 1) {
                    $this->nodes[$id]['slug'] .= '-'.$id;
                }
            }
            // A generated suffix must not overwrite a naturally occurring sibling slug.
            for ($attempt = 0; $attempt < count($ids); $attempt++) {
                $groups = [];
                foreach ($ids as $id) {
                    $groups[$this->nodes[$id]['slug']][] = $id;
                }
                $duplicates = array_filter($groups, static fn (array $group): bool => count($group) > 1);
                if ($duplicates === []) {
                    break;
                }
                foreach ($duplicates as $group) {
                    foreach ($group as $id) {
                        $this->nodes[$id]['slug'] .= '-'.$id;
                    }
                }
            }
        }
        foreach (array_keys($this->nodes) as $id) {
            $path = $this->buildPath($id, []);
            $this->paths[$path] = $id;
        }
    }

    /** @param array<int, true> $seen */
    private function buildPath(int $id, array $seen): string
    {
        if (isset($seen[$id]) || count($seen) >= (int) config('catalog.public_api.max_depth', 64)) {
            throw new CatalogApiException('INVALID_HIERARCHY', 'The public catalog hierarchy is unavailable.', 500);
        }
        if (isset($this->nodes[$id]['path'])) {
            return $this->nodes[$id]['path'];
        }
        $seen[$id] = true;
        $parent = $this->nodes[$id]['parent_id'];
        if ($parent !== null && ! isset($this->nodes[$parent])) {
            throw new CatalogApiException('INVALID_HIERARCHY', 'The public catalog hierarchy is unavailable.', 500);
        }
        $prefix = $parent !== null ? $this->buildPath($parent, $seen).'/' : '';
        $depth = $parent !== null ? $this->nodes[$parent]['depth'] + 1 : 1;
        if ($depth > (int) config('catalog.public_api.max_depth', 64)) {
            throw new CatalogApiException('INVALID_HIERARCHY', 'The public catalog hierarchy is unavailable.', 500);
        }
        $this->nodes[$id]['depth'] = $depth;

        $path = $prefix.$this->nodes[$id]['slug'];
        $this->pathBytes += strlen($path);
        if ($this->pathBytes > (int) config('catalog.public_api.max_response_bytes', 8388608)) {
            throw new CatalogApiException('RESOURCE_LIMIT', 'Public hierarchy paths exceed their configured limit.', 503);
        }

        return $this->nodes[$id]['path'] = $path;
    }

    /** @return array<string, mixed> */
    private function node(string $path): array
    {
        if (! mb_check_encoding($path, 'UTF-8') || $path === '' || str_contains($path, '..')
            || str_contains($path, '\\') || str_contains($path, '%') || str_contains($path, '//')) {
            throw new CatalogApiException('INVALID_PATH', 'A valid catalog path is required.', 422);
        }
        $this->load();
        $path = trim($path, '/');
        $id = $this->paths[$path] ?? null;
        if ($id === null) {
            throw new CatalogApiException('NOT_FOUND', 'Public catalog path not found.', 404, ['path' => $path]);
        }

        return $this->nodes[$id];
    }

    /** @return array<string, mixed> */
    private function seriesNode(string $path): array
    {
        $node = $this->node($path);
        if ($node['type'] !== 'series') {
            throw new CatalogApiException('SERIES_NOT_FOUND', 'Public series not found.', 404);
        }

        return $node;
    }

    /** @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function publicNode(array $node): array
    {
        return [
            'id' => $node['id'], 'parentId' => $node['parent_id'], 'name' => $node['name'],
            'type' => $node['type'], 'slug' => $node['slug'], 'path' => $node['path'],
            'displayOrder' => $node['display_order'],
        ];
    }

    /** @return list<array<string, mixed>> */
    private function breadcrumb(int $id): array
    {
        $result = [];
        do {
            array_unshift($result, $this->publicNode($this->nodes[$id]));
            $id = $this->nodes[$id]['parent_id'];
        } while ($id !== null);

        return $result;
    }

    /** @return array<string, mixed> */
    private function treeNode(int $id): array
    {
        $node = $this->publicNode($this->nodes[$id]);
        $node['children'] = array_map(fn (int $child): array => $this->treeNode($child), $this->children[$id] ?? []);
        if ($node['type'] === 'series') {
            $node['partCount'] = $this->countParts($id);
        }

        return $node;
    }

    private function countParts(int $id): int
    {
        $this->partCounts ??= $this->repository->counts([$id]);

        return $this->partCounts[$id] ?? 0;
    }

    private function slug(string $value): string
    {
        $slug = trim((string) preg_replace('/[^\pL\pN]+/u', '-', strtolower(trim($value))), '-');

        return $slug !== '' ? $slug : 'item';
    }

    private function checkFieldCount(int $count): void
    {
        if ($count > (int) config('catalog.public_api.max_fields', 200)) {
            throw new CatalogApiException('RESOURCE_LIMIT', 'Public field definitions exceed their configured limit.', 503);
        }
    }

    private function publicValue(?string $value, string $type): ?string
    {
        if ($type !== 'file' || $value === null) {
            return $value;
        }
        $url = parse_url($value);
        $hosts = array_map('strtolower', (array) config('catalog.public_api.media_hosts', []));
        if ($url === false || ($url['scheme'] ?? '') !== 'https' || empty($url['host'])
            || ! in_array(strtolower($url['host']), $hosts, true)
            || isset($url['user']) || isset($url['pass']) || isset($url['port']) || isset($url['fragment'])
            || ! filter_var($value, FILTER_VALIDATE_URL)
            || preg_match('/[\x00-\x20\\\\]/', $value)
            || str_contains(rawurldecode((string) ($url['path'] ?? '')), '..')) {
            return null;
        }

        return $value;
    }
}
