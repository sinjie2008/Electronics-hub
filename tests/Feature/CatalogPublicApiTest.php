<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Catalog\Providers\PublicApiServiceProvider;
use Modules\Catalog\Repositories\PublicApi\ProductApiRepository;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Tests\TestCase;

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('The public Catalog API feature tests require MySQL.');
    }

    config([
        'catalog.public_api.enabled' => true,
        'catalog.public_api.cache_ttl' => 0,
        'catalog.public_api.rate_limits.standard' => 60,
        'catalog.public_api.rate_limits.search' => 20,
        'catalog.public_api.rate_limits.expensive' => 30,
        'catalog.public_api.media_hosts' => ['cdn.example.com'],
    ]);

    $this->app->bind(ProductApiRepository::class, fn (): ProductApiRepository => new ProductApiRepository(
        DB::connection((string) config('database.default'))
    ));
});

afterEach(function (): void {
    if (isset($this->moduleStatusesPath)) {
        File::delete($this->moduleStatusesPath, $this->moduleStatusesPath.'.lock');
    }
});

it('returns the public API root and a database-free health contract', function (): void {
    $tree = catalogPublicApiTree();

    $root = $this->getJson('/api/v1/catalog')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Public Product API')
        ->assertJsonPath('data.readOnly', true)
        ->assertJsonPath('data.requiresMigration', false)
        ->assertJsonPath('data.requiresImport', false)
        ->assertJsonPath('data.requiresManualSync', false)
        ->assertJsonPath('data.endpointCount', 10)
        ->assertJsonPath('data.tree.0.path', $tree['rootPath'])
        ->assertJsonPath('data.tree.0.children.0.children.0.path', $tree['seriesPath']);
    expect(array_keys($root->json()))->toBe(['success', 'data', 'correlationId']);

    $health = $this->getJson('/api/v1/catalog/health')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.writeOperations', false)
        ->assertJsonMissingPath('data.database');
    expect(array_keys($health->json('data')))->toBe(['status', 'writeOperations']);
});

it('returns an empty tree and nested public category and series paths', function (): void {
    $this->getJson('/api/v1/catalog/tree')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data', []);

    $tree = catalogPublicApiTree();

    $this->getJson('/api/v1/catalog/tree')
        ->assertOk()
        ->assertJsonPath('data.0.path', $tree['rootPath'])
        ->assertJsonPath('data.0.children.0.path', $tree['categoryPath'])
        ->assertJsonPath('data.0.children.0.children.0.path', $tree['seriesPath'])
        ->assertJsonPath('data.0.children.0.children.0.partCount', 0);

    $this->getJson('/api/v1/catalog/categories/'.$tree['categoryPath'])
        ->assertOk()
        ->assertJsonPath('data.resource.type', 'category')
        ->assertJsonPath('data.resource.path', $tree['categoryPath'])
        ->assertJsonPath('data.breadcrumb.0.name', 'Electronics')
        ->assertJsonPath('data.children.0.type', 'series')
        ->assertJsonPath('data.children.0.partCount', 0);

    $this->getJson('/api/v1/catalog/resolve/'.$tree['seriesPath'])
        ->assertOk()
        ->assertJsonPath('data.resource.type', 'series')
        ->assertJsonPath('data.resource.path', $tree['seriesPath'])
        ->assertJsonPath('data.breadcrumb.2.name', 'Resistors');
});

it('resolves Unicode nested paths after URL encoding each path segment', function (): void {
    $rootId = (int) DB::table('category')->insertGetId([
        'name' => '测试', 'type' => 'category', 'display_order' => 1,
    ]);
    $categoryId = (int) DB::table('category')->insertGetId([
        'parent_id' => $rootId, 'name' => '电容器', 'type' => 'category', 'display_order' => 1,
    ]);
    DB::table('category')->insert([
        'parent_id' => $categoryId, 'name' => '陶瓷', 'type' => 'series', 'display_order' => 1,
    ]);
    $encodedPath = implode('/', array_map('rawurlencode', ['测试', '电容器', '陶瓷']));

    $this->getJson('/api/v1/catalog/resolve/'.$encodedPath)
        ->assertOk()
        ->assertJsonPath('data.resource.name', '陶瓷')
        ->assertJsonPath('data.resource.path', '测试/电容器/陶瓷');
});

it('keeps generated sibling slugs unique when a natural slug collides with a duplicate suffix', function (): void {
    $tree = catalogPublicApiTree();
    $firstId = (int) DB::table('category')->insertGetId([
        'parent_id' => $tree['rootId'], 'name' => 'Twin', 'type' => 'category', 'display_order' => 1,
    ]);
    $secondId = (int) DB::table('category')->insertGetId([
        'parent_id' => $tree['rootId'], 'name' => 'Twin', 'type' => 'category', 'display_order' => 2,
    ]);
    $naturalId = (int) DB::table('category')->insertGetId([
        'parent_id' => $tree['rootId'], 'name' => 'Twin-'.$firstId, 'type' => 'category', 'display_order' => 3,
    ]);

    $nodes = collect($this->getJson('/api/v1/catalog/tree')->assertOk()->json('data.0.children'))
        ->whereIn('id', [$firstId, $secondId, $naturalId]);
    $paths = $nodes->pluck('path')->all();

    expect($paths)->toHaveCount(3)->and(array_unique($paths))->toHaveCount(3)
        ->and($nodes->firstWhere('id', $firstId)['path'])->toBe('electronics/twin-'.$firstId.'-'.$firstId)
        ->and($nodes->firstWhere('id', $naturalId)['path'])->toBe('electronics/twin-'.$firstId.'-'.$naturalId);
});

it('resolves a real series before interpreting its name as a reserved endpoint suffix', function (): void {
    $tree = catalogPublicApiTree();
    DB::table('category')->insert([
        'parent_id' => $tree['categoryId'],
        'name' => 'Fields',
        'type' => 'series',
        'display_order' => 2,
    ]);

    $this->getJson('/api/v1/catalog/series/electronics/passives/fields')
        ->assertOk()
        ->assertJsonPath('data.resource.type', 'series')
        ->assertJsonPath('data.resource.path', 'electronics/passives/fields')
        ->assertJsonPath('data.metadata', [])
        ->assertJsonPath('data.fields', []);
});

it('returns series metadata and only public field definitions and values', function (): void {
    $tree = catalogPublicApiTree();
    $voltage = catalogPublicApiField($tree['seriesId'], 'voltage', 'number', '12', 1);
    catalogPublicApiField($tree['seriesId'], 'manufacturer', 'text', null, 2, backendHidden: true);
    catalogPublicApiField($tree['seriesId'], 'portal_hidden', 'text', 'secret', 3, publicPortalHidden: true);
    catalogPublicApiField($tree['seriesId'], 'public_hidden', 'text', 'secret', 4, publicHidden: true);
    $seriesNote = catalogPublicApiField($tree['seriesId'], 'series_note', 'text', 'General purpose', 1, scope: 'series_metadata');
    $nullNote = catalogPublicApiField($tree['seriesId'], 'null_note', 'text', null, 2, scope: 'series_metadata');
    DB::table('series_custom_field_value')->insert([
        ['series_id' => $tree['seriesId'], 'series_custom_field_id' => $seriesNote, 'value' => null],
        ['series_id' => $tree['seriesId'], 'series_custom_field_id' => $nullNote, 'value' => null],
    ]);

    $series = $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.resource.id', $tree['seriesId'])
        ->assertJsonPath('data.partCount', 0)
        ->assertJsonPath('data.fields.0.key', 'voltage')
        ->assertJsonPath('data.fields.0.defaultValue', '12')
        ->assertJsonPath('data.fields.0.sortOrder', 1)
        ->assertJsonPath('data.fields.0.required', false)
        ->assertJsonPath('data.fields.1.key', 'manufacturer')
        ->assertJsonMissing(['key' => 'portal_hidden'])
        ->assertJsonMissing(['key' => 'public_hidden'])
        ->assertJsonPath('data.metadata.0.key', 'series_note')
        ->assertJsonPath('data.metadata.0.value', 'General purpose')
        ->assertJsonPath('data.metadata.1.key', 'null_note')
        ->assertJsonPath('data.metadata.1.value', null);

    expect($series->json('data.fields.0'))->toHaveKeys([
        'id', 'key', 'label', 'type', 'defaultValue', 'sortOrder', 'required',
    ])->not->toHaveKeys([
        'fieldKey', 'fieldType', 'fieldScope', 'isRequired', 'publicPortalHidden', 'backendPortalHidden',
    ]);

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/fields')
        ->assertOk()
        ->assertJsonPath('data.0.id', $voltage)
        ->assertJsonPath('data.0.defaultValue', '12')
        ->assertJsonMissing(['key' => 'portal_hidden'])
        ->assertJsonMissing(['key' => 'public_hidden']);
});

it('returns sanitized part values, preserves public nulls, and rejects file-field queries', function (): void {
    $tree = catalogPublicApiTree();
    catalogPublicApiField($tree['seriesId'], 'voltage', 'number', null, 1);
    catalogPublicApiField($tree['seriesId'], 'datasheet', 'file', 'storage/media/private-default.pdf', 2);
    catalogPublicApiField($tree['seriesId'], 'package', 'text', '0805', 3);
    $hiddenId = catalogPublicApiField($tree['seriesId'], 'hidden', 'text', 'secret', 4, publicHidden: true);
    $safeMediaId = catalogPublicApiField($tree['seriesId'], 'public_datasheet', 'file', null, 5);
    $partId = catalogPublicApiPart($tree['seriesId'], 'AX-10', 'Resistor ten');
    catalogPublicApiValue($partId, $hiddenId, 'private value');
    catalogPublicApiValue($partId, catalogPublicApiFieldId($tree['seriesId'], 'datasheet'), 'https://unapproved.example/private.pdf');
    catalogPublicApiValue($partId, $safeMediaId, 'https://cdn.example.com/public.pdf');

    $parts = $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.parts.0.sku', 'AX-10')
        ->assertJsonPath('data.parts.0.values.voltage', null)
        ->assertJsonPath('data.parts.0.values.package', '0805')
        ->assertJsonPath('data.parts.0.values.datasheet', null)
        ->assertJsonPath('data.parts.0.values.public_datasheet', 'https://cdn.example.com/public.pdf')
        ->assertJsonMissingPath('data.parts.0.values.hidden')
        ->assertJsonPath('data.pagination.page', 1)
        ->assertJsonPath('data.pagination.per_page', 25)
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.pagination.last_page', 1);

    expect($parts->json('data.parts.0'))->toHaveKeys(['id', 'sku', 'name', 'description', 'values'])
        ->not->toHaveKeys(['series_id', 'created_at', 'updated_at']);

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?sort=datasheet')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errorCode', 'INVALID_QUERY');

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?filter%5Bdatasheet%5D=private')
        ->assertUnprocessable()
        ->assertJsonPath('errorCode', 'INVALID_QUERY');
});

it('filters parts with OR values and AND fields and applies SQL numeric and lexical ordering', function (): void {
    $tree = catalogPublicApiTree();
    catalogPublicApiField($tree['seriesId'], 'voltage', 'number', null, 1);
    catalogPublicApiField($tree['seriesId'], 'family', 'text', null, 2);
    $first = catalogPublicApiPart($tree['seriesId'], 'AX-2', 'Second resistor');
    $second = catalogPublicApiPart($tree['seriesId'], 'AX-10', 'Tenth resistor');
    catalogPublicApiValue($first, catalogPublicApiFieldId($tree['seriesId'], 'voltage'), '2');
    catalogPublicApiValue($first, catalogPublicApiFieldId($tree['seriesId'], 'family'), 'R2');
    catalogPublicApiValue($second, catalogPublicApiFieldId($tree['seriesId'], 'voltage'), '10');
    catalogPublicApiValue($second, catalogPublicApiFieldId($tree['seriesId'], 'family'), 'R10');

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?sort=sku&direction=asc')
        ->assertOk()
        ->assertJsonPath('data.parts.0.sku', 'AX-10')
        ->assertJsonPath('data.parts.1.sku', 'AX-2');

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?sort=voltage&direction=asc')
        ->assertOk()
        ->assertJsonPath('data.parts.0.values.voltage', '2')
        ->assertJsonPath('data.parts.1.values.voltage', '10');

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?sort=family&direction=asc')
        ->assertOk()
        ->assertJsonPath('data.parts.0.values.family', 'R10')
        ->assertJsonPath('data.parts.1.values.family', 'R2');

    $filters = http_build_query([
        'filter' => ['voltage' => ['2', '10'], 'family' => 'R10'],
    ]);
    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?'.$filters)
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 1)
        ->assertJsonPath('data.parts.0.sku', 'AX-10');
});

it('returns natural-sorted facet counts and hides revoked field values immediately', function (): void {
    $tree = catalogPublicApiTree();
    $familyId = catalogPublicApiField($tree['seriesId'], 'family', 'text', null, 1);
    $hiddenId = catalogPublicApiField($tree['seriesId'], 'restricted', 'text', null, 2);
    catalogPublicApiField($tree['seriesId'], 'attachment', 'file', null, 3);
    $part2 = catalogPublicApiPart($tree['seriesId'], 'AX-2', 'Second resistor');
    $part10 = catalogPublicApiPart($tree['seriesId'], 'AX-10', 'Tenth resistor');
    catalogPublicApiValue($part2, $familyId, 'R2');
    catalogPublicApiValue($part10, $familyId, 'R10');
    catalogPublicApiValue($part2, $hiddenId, 'public for now');

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/facets')
        ->assertOk()
        ->assertJsonPath('data.totalParts', 2)
        ->assertJsonPath('data.facets.0.key', 'family')
        ->assertJsonPath('data.facets.0.values.0.value', 'R2')
        ->assertJsonPath('data.facets.0.values.0.count', 1)
        ->assertJsonPath('data.facets.0.values.1.value', 'R10')
        ->assertJsonPath('data.facets.1.values.0.value', 'public for now')
        ->assertJsonPath('data.facets.2.key', 'attachment')
        ->assertJsonPath('data.facets.2.values', []);

    DB::table('series_custom_field')->where('id', $hiddenId)->update(['is_public_hidden' => 1]);

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/facets')
        ->assertOk()
        ->assertJsonMissing(['key' => 'restricted']);
    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts')
        ->assertOk()
        ->assertJsonMissingPath('data.parts.0.values.restricted');
});

it('keeps case-distinct facet buckets in source-compatible order when natural keys tie', function (): void {
    $tree = catalogPublicApiTree();
    $field = catalogPublicApiField($tree['seriesId'], 'family');
    foreach (['b', 'B', 'a', 'A'] as $index => $value) {
        $part = catalogPublicApiPart($tree['seriesId'], 'F-'.$index, 'Facet '.$index);
        catalogPublicApiValue($part, $field, $value);
    }

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/facets')->assertOk()
        ->assertJsonPath('data.facets.0.values', [
            ['value' => 'a', 'count' => 1], ['value' => 'A', 'count' => 1],
            ['value' => 'b', 'count' => 1], ['value' => 'B', 'count' => 1],
        ]);
});

it('searches public nodes and products and rejects unrelated query parameters', function (): void {
    $tree = catalogPublicApiTree();
    catalogPublicApiPart($tree['seriesId'], 'CAP-SEARCH-1', 'Searchable resistor');

    $this->getJson('/api/v1/catalog/search?q=resistor')
        ->assertOk()
        ->assertJsonPath('data.query', 'resistor')
        ->assertJsonPath('data.nodes.0.path', $tree['seriesPath'])
        ->assertJsonPath('data.parts.0.sku', 'CAP-SEARCH-1')
        ->assertJsonPath('data.parts.0.series.path', $tree['seriesPath']);

    $this->getJson('/api/v1/catalog/search?q=')
        ->assertOk()
        ->assertJsonPath('data.query', '')
        ->assertJsonPath('data.nodes', [])
        ->assertJsonPath('data.parts', []);

    $this->getJson('/api/v1/catalog?unexpected=1')
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errorCode', 'INVALID_QUERY')
        ->assertJsonPath('details', []);

    $this->getJson('/api/v1/catalog/search?q=searchable&page=2')
        ->assertUnprocessable()
        ->assertJsonPath('errorCode', 'INVALID_QUERY');
});

it('treats LIKE metacharacters and SQL-shaped input as literal search and filter values', function (): void {
    $tree = catalogPublicApiTree();
    catalogPublicApiField($tree['seriesId'], 'family', 'text');
    $percent = catalogPublicApiPart($tree['seriesId'], 'PCT-1', '100% rated resistor');
    $underscore = catalogPublicApiPart($tree['seriesId'], 'UND-1', 'A_B component');
    catalogPublicApiPart($tree['seriesId'], 'SAFE-1', 'Ordinary component');
    catalogPublicApiValue($percent, catalogPublicApiFieldId($tree['seriesId'], 'family'), 'R1');
    catalogPublicApiValue($underscore, catalogPublicApiFieldId($tree['seriesId'], 'family'), 'R2');

    $this->getJson('/api/v1/catalog/search?q=%25')
        ->assertOk()
        ->assertJsonPath('data.parts.0.sku', 'PCT-1')
        ->assertJsonCount(1, 'data.parts');
    $this->getJson('/api/v1/catalog/search?q=_')
        ->assertOk()
        ->assertJsonPath('data.parts.0.sku', 'UND-1')
        ->assertJsonCount(1, 'data.parts');
    $this->getJson('/api/v1/catalog/search?q=resistor%27%20OR%201%3D1%20--')
        ->assertOk()
        ->assertJsonPath('data.parts', [])
        ->assertJsonPath('data.nodes', []);

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?filter%5Bfamily%5D=R1%27%20OR%201%3D1%20--')
        ->assertOk()
        ->assertJsonPath('data.pagination.total', 0)
        ->assertJsonPath('data.parts', []);
});

it('bounds filter shapes, value counts, and raw query bytes', function (): void {
    $tree = catalogPublicApiTree();
    catalogPublicApiField($tree['seriesId'], 'family', 'text');
    catalogPublicApiField($tree['seriesId'], 'voltage', 'number');
    config(['catalog.public_api.max_filters' => 1, 'catalog.public_api.max_filter_values' => 1]);

    foreach ([
        'filter%5Bfamily%5D%5Bnested%5D=x',
        'filter%5Bfamily%5D%5B%5D=R1&filter%5Bfamily%5D%5B%5D=R2',
        'filter%5Bfamily%5D=R1&filter%5Bvoltage%5D=10',
    ] as $query) {
        $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?'.$query)
            ->assertUnprocessable()
            ->assertJsonPath('errorCode', 'INVALID_QUERY');
    }

    config(['catalog.public_api.max_query_bytes' => 24]);
    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?search='.str_repeat('x', 30))
        ->assertUnprocessable()
        ->assertJsonPath('errorCode', 'INVALID_QUERY');
});

it('clamps valid pagination past the last page and rejects malformed or excessive query values', function (): void {
    $tree = catalogPublicApiTree();
    catalogPublicApiPart($tree['seriesId'], 'AX-1', 'First resistor');
    catalogPublicApiPart($tree['seriesId'], 'AX-2', 'Second resistor');

    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?page=9&per_page=1')
        ->assertOk()
        ->assertJsonPath('data.pagination.page', 2)
        ->assertJsonPath('data.pagination.per_page', 1)
        ->assertJsonPath('data.pagination.total', 2)
        ->assertJsonPath('data.parts.0.sku', 'AX-2');

    foreach ([
        'sort[]=sku',
        'sort=unknown_column',
        'direction=sideways',
        'page=0',
        'per_page=101',
        'filter[missing]=value',
        'unexpected=value',
    ] as $query) {
        $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?'.$query)
            ->assertUnprocessable()
            ->assertJsonPath('success', false)
            ->assertJsonPath('errorCode', 'INVALID_QUERY');
    }

    $this->getJson('/api/v1/catalog/search?q='.str_repeat('x', 101))
        ->assertUnprocessable()
        ->assertJsonPath('errorCode', 'INVALID_QUERY');

    $this->withHeader('X-Correlation-ID', 'public-api-correlation-42')
        ->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/parts?sort=unknown_column')
        ->assertUnprocessable()
        ->assertJsonPath('correlationId', 'public-api-correlation-42');
});

it('rejects invalid encoded catalog paths and returns not found for unknown resources', function (string $path): void {
    $this->getJson('/api/v1/catalog/resolve/'.$path)
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errorCode', 'INVALID_PATH');
})->with([
    'dot traversal' => ['%2E%2E/private'],
    'encoded slash traversal' => ['root%2F..%2Fprivate'],
    'double encoded traversal' => ['%252e%252e/private'],
    'backslash traversal' => ['root%5C..%5Cprivate'],
    'malformed percent escape' => ['bad%ZZ'],
    'invalid UTF-8 byte' => ['bad%FF'],
    'encoded control byte' => ['bad%00path'],
    'encoded backslash' => ['root%5Cchild'],
]);

it('returns 404 for unknown paths and wrong resource kinds', function (): void {
    catalogPublicApiTree();

    $missing = $this->getJson('/api/v1/catalog/resolve/missing/path')
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errorCode', 'NOT_FOUND')
        ->assertJsonPath('message', 'Public catalog path not found.')
        ->assertJsonPath('details.path', 'missing/path');
    expect(array_keys($missing->json()))->toBe(['success', 'errorCode', 'message', 'details', 'correlationId']);
    $this->getJson('/api/v1/catalog/series/electronics/passives')
        ->assertNotFound()
        ->assertJsonPath('errorCode', 'SERIES_NOT_FOUND');
    $this->getJson('/api/v1/catalog/categories/electronics/passives/resistors')
        ->assertNotFound()
        ->assertJsonPath('errorCode', 'CATEGORY_NOT_FOUND');
});

it('allows GET only and rejects write, preflight, head, and spoofed methods', function (): void {
    catalogPublicApiTree();

    $writes = [];
    DB::connection((string) config('database.default'))->listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(?:insert|update|delete|replace|truncate|alter|create|drop)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });
    foreach (['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'] as $method) {
        $this->call($method, '/api/v1/catalog/tree')
            ->assertStatus(405)
            ->assertHeader('Allow', 'GET')
            ->assertJsonPath('errorCode', 'METHOD_NOT_ALLOWED');
    }
    $this->call('HEAD', '/api/v1/catalog/tree')->assertStatus(405)->assertHeader('Allow', 'GET');
    $this->post('/api/v1/catalog/tree', ['_method' => 'GET'])
        ->assertStatus(405)
        ->assertJsonPath('errorCode', 'METHOD_NOT_ALLOWED');
    expect($writes)->toBe([]);
});

it('keeps module availability and the independent public API toggle fail-closed', function (): void {
    catalogPublicApiTree();
    catalogPublicApiSetModuleEnabled($this, true);
    config(['catalog.public_api.cache_ttl' => 60]);

    $this->getJson('/api/v1/catalog/tree')->assertOk();

    config(['catalog.public_api.enabled' => false]);
    $this->getJson('/api/v1/catalog/tree')
        ->assertStatus(503)
        ->assertJsonPath('errorCode', 'API_UNAVAILABLE');

    config(['catalog.public_api.enabled' => true]);
    catalogPublicApiSetModuleEnabled($this, false);
    $this->getJson('/api/v1/catalog/tree')
        ->assertStatus(503)
        ->assertJsonPath('errorCode', 'API_UNAVAILABLE');

    config(['catalog.public_api.enabled' => false]);
    $this->getJson('/api/v1/catalog/tree')
        ->assertStatus(503)
        ->assertJsonPath('errorCode', 'API_UNAVAILABLE');
});

it('keeps legacy catalog authentication independent from the new API toggle', function (): void {
    config(['catalog.public_api.enabled' => false]);

    $this->get('/catalog/catalog.php?action=v1.publicCatalogSnapshot')->assertUnauthorized();
    $this->postJson('/catalog/catalog.php?action=v1.saveNode', [])->assertUnauthorized();

    config(['catalog.public_api.enabled' => true]);
    $this->get('/catalog/catalog.php?action=v1.publicCatalogSnapshot')->assertUnauthorized();
    $this->postJson('/catalog/catalog.php?action=v1.saveNode', [])->assertUnauthorized();
});

it('fails closed on excessive hierarchy depth and cyclic category data', function (): void {
    $tree = catalogPublicApiTree();
    $depthFourId = (int) DB::table('category')->insertGetId([
        'parent_id' => $tree['seriesId'], 'name' => 'Depth four', 'type' => 'category', 'display_order' => 1,
    ]);
    DB::table('category')->insert([
        'parent_id' => $depthFourId, 'name' => 'Depth five', 'type' => 'category', 'display_order' => 1,
    ]);
    config(['catalog.public_api.max_depth' => 4]);

    $this->getJson('/api/v1/catalog/tree')
        ->assertStatus(500)
        ->assertJsonPath('errorCode', 'INVALID_HIERARCHY');

    config(['catalog.public_api.max_depth' => 64]);
    DB::table('category')->where('id', $tree['rootId'])->update(['parent_id' => $tree['seriesId']]);
    $this->getJson('/api/v1/catalog/tree')
        ->assertStatus(500)
        ->assertJsonPath('errorCode', 'INVALID_HIERARCHY');
});

it('returns a bounded service error when node, field, or facet limits are exceeded', function (): void {
    $tree = catalogPublicApiTree();
    config(['catalog.public_api.max_nodes' => 2]);
    $this->getJson('/api/v1/catalog/tree')
        ->assertStatus(503)
        ->assertJsonPath('errorCode', 'RESOURCE_LIMIT');

    config(['catalog.public_api.max_nodes' => 10000, 'catalog.public_api.max_fields' => 1]);
    catalogPublicApiField($tree['seriesId'], 'family', 'text');
    catalogPublicApiField($tree['seriesId'], 'voltage', 'number');
    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/fields')
        ->assertStatus(503)
        ->assertJsonPath('errorCode', 'RESOURCE_LIMIT');

    config(['catalog.public_api.max_fields' => 10, 'catalog.public_api.max_facet_values' => 1]);
    $familyId = catalogPublicApiFieldId($tree['seriesId'], 'family');
    $firstId = catalogPublicApiPart($tree['seriesId'], 'R-1', 'First resistor');
    $secondId = catalogPublicApiPart($tree['seriesId'], 'R-2', 'Second resistor');
    catalogPublicApiValue($firstId, $familyId, 'A');
    catalogPublicApiValue($secondId, $familyId, 'B');
    $this->getJson('/api/v1/catalog/series/'.$tree['seriesPath'].'/facets')
        ->assertStatus(503)
        ->assertJsonPath('errorCode', 'RESOURCE_LIMIT');
});

it('caches only hierarchy responses and bypasses cached hierarchy when the TTL is zero', function (): void {
    $tree = catalogPublicApiTree();
    config(['catalog.public_api.cache_ttl' => 60]);

    $this->getJson('/api/v1/catalog/tree')
        ->assertOk()
        ->assertJsonMissing(['name' => 'New category']);
    DB::table('category')->insert([
        'parent_id' => $tree['rootId'],
        'name' => 'New category',
        'type' => 'category',
        'display_order' => 99,
    ]);

    $this->getJson('/api/v1/catalog/tree')
        ->assertOk()
        ->assertJsonMissing(['name' => 'New category']);

    config(['catalog.public_api.cache_ttl' => 0]);
    $this->getJson('/api/v1/catalog/tree')
        ->assertOk()
        ->assertJsonFragment(['name' => 'New category']);
});

it('uses a bounded standard rate limit and ignores a spoofed forwarded address', function (): void {
    config(['catalog.public_api.rate_limits.standard' => 2]);

    $this->withHeader('X-Forwarded-For', '198.51.100.1')->getJson('/api/v1/catalog/health')->assertOk();
    $this->withHeader('X-Forwarded-For', '203.0.113.77')->getJson('/api/v1/catalog/health')->assertOk();
    $this->withHeader('X-Forwarded-For', '192.0.2.19')->getJson('/api/v1/catalog/health')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('errorCode', 'RATE_LIMITED');
});

it('applies independent search and expensive endpoint rate limits', function (): void {
    $tree = catalogPublicApiTree();
    config([
        'catalog.public_api.rate_limits.standard' => 60,
        'catalog.public_api.rate_limits.search' => 2,
        'catalog.public_api.rate_limits.expensive' => 2,
    ]);

    $this->getJson('/api/v1/catalog/search?q=resistor')->assertOk();
    $this->getJson('/api/v1/catalog/search?q=capacitor')->assertOk();
    $this->getJson('/api/v1/catalog/search?q=inductor')
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('errorCode', 'RATE_LIMITED');

    $parts = '/api/v1/catalog/series/'.$tree['seriesPath'].'/parts';
    $this->getJson($parts)->assertOk();
    $this->getJson($parts)->assertOk();
    $this->getJson($parts)
        ->assertStatus(429)
        ->assertHeader('Retry-After')
        ->assertJsonPath('errorCode', 'RATE_LIMITED');
});

it('inherits parsed source connection and TLS options for the dedicated read-only connection', function (): void {
    config([
        'catalog.connection' => 'public_api_test_source',
        'catalog.public_api.database.username' => 'api_readonly',
        'catalog.public_api.database.password' => 'test-only-password',
        'database.connections.public_api_test_source' => [
            'driver' => 'mysql',
            'url' => 'mysql://catalog_owner:owner-password@db.example.test:3307/catalog_fixture?charset=utf8mb4',
            'host' => 'fallback.example.test',
            'database' => 'fallback_database',
            'username' => 'fallback_user',
            'password' => 'fallback_password',
            'options' => [
                PDO::MYSQL_ATTR_SSL_CA => '/fixture/mysql-ca.pem',
                PDO::ATTR_TIMEOUT => 9,
            ],
            'read' => ['host' => ['reader.example.test']],
            'write' => ['host' => ['writer.example.test']],
            'sticky' => true,
        ],
    ]);

    (new PublicApiServiceProvider($this->app))->register();
    $connection = config('database.connections.catalog_public_api');

    expect($connection['host'])->toBe('db.example.test')
        ->and($connection['port'])->toBe(3307)
        ->and($connection['database'])->toBe('catalog_fixture')
        ->and($connection['username'])->toBe('api_readonly')
        ->and($connection['options'][PDO::MYSQL_ATTR_SSL_CA])->toBe('/fixture/mysql-ca.pem')
        ->and($connection['options'][PDO::ATTR_TIMEOUT])->toBe(9)
        ->and($connection['options'][PDO::ATTR_EMULATE_PREPARES])->toBeFalse()
        ->and($connection['options'][PDO::MYSQL_ATTR_USE_BUFFERED_QUERY])->toBeFalse()
        ->and($connection)->not->toHaveKeys(['url', 'read', 'write', 'sticky']);
});

it('returns generic internal errors without leaking exception details', function (): void {
    $this->app->bind(ProductApiRepository::class, function (): never {
        throw new RuntimeException('SELECT secret_column FROM private_table');
    });

    $response = $this->getJson('/api/v1/catalog/tree')
        ->assertStatus(500)
        ->assertJsonPath('success', false)
        ->assertJsonPath('errorCode', 'INTERNAL_ERROR')
        ->assertJsonPath('message', 'The public product API is unavailable.')
        ->assertJsonMissing(['details' => 'SELECT secret_column FROM private_table'])
        ->assertDontSee('secret_column')
        ->assertDontSee('private_table');
    expect(array_keys($response->json()))->toBe(['success', 'errorCode', 'message', 'details', 'correlationId'])
        ->and($response->json('details'))->toBe([]);
});

it('performs only SELECT queries across the public endpoint set', function (): void {
    $tree = catalogPublicApiTree();
    catalogPublicApiField($tree['seriesId'], 'family', 'text', 'R1', 1);
    $partId = catalogPublicApiPart($tree['seriesId'], 'R-001', 'Read-only resistor');
    catalogPublicApiValue($partId, catalogPublicApiFieldId($tree['seriesId'], 'family'), 'R1');
    $writes = [];
    DB::connection((string) config('database.default'))->listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(?:insert|update|delete|replace|truncate|alter|create|drop)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    foreach ([
        '/api/v1/catalog',
        '/api/v1/catalog/health',
        '/api/v1/catalog/tree',
        '/api/v1/catalog/resolve/'.$tree['seriesPath'],
        '/api/v1/catalog/categories/'.$tree['categoryPath'],
        '/api/v1/catalog/series/'.$tree['seriesPath'],
        '/api/v1/catalog/series/'.$tree['seriesPath'].'/fields',
        '/api/v1/catalog/series/'.$tree['seriesPath'].'/parts',
        '/api/v1/catalog/series/'.$tree['seriesPath'].'/facets',
        '/api/v1/catalog/search?q=resistor',
    ] as $path) {
        $this->getJson($path)->assertSuccessful();
    }

    expect($writes)->toBe([]);
});

/** @return array{rootId: int, categoryId: int, seriesId: int, rootPath: string, categoryPath: string, seriesPath: string} */
function catalogPublicApiTree(): array
{
    $connection = DB::connection((string) config('database.default'));
    $rootId = (int) $connection->table('category')->insertGetId([
        'name' => 'Electronics', 'type' => 'category', 'display_order' => 1,
    ]);
    $categoryId = (int) $connection->table('category')->insertGetId([
        'parent_id' => $rootId, 'name' => 'Passives', 'type' => 'category', 'display_order' => 1,
    ]);
    $seriesId = (int) $connection->table('category')->insertGetId([
        'parent_id' => $categoryId, 'name' => 'Resistors', 'type' => 'series', 'display_order' => 1,
    ]);

    return [
        'rootId' => $rootId,
        'categoryId' => $categoryId,
        'seriesId' => $seriesId,
        'rootPath' => 'electronics',
        'categoryPath' => 'electronics/passives',
        'seriesPath' => 'electronics/passives/resistors',
    ];
}

function catalogPublicApiField(
    int $seriesId,
    string $key,
    string $type = 'text',
    ?string $defaultValue = null,
    int $sortOrder = 0,
    bool $publicPortalHidden = false,
    bool $publicHidden = false,
    bool $backendHidden = false,
    string $scope = 'product_attribute'
): int {
    return (int) DB::table('series_custom_field')->insertGetId([
        'series_id' => $seriesId,
        'field_key' => $key,
        'label' => Str::headline($key),
        'field_type' => $type,
        'field_scope' => $scope,
        'default_value' => $defaultValue,
        'sort_order' => $sortOrder,
        'is_required' => 0,
        'is_public_portal_hidden' => $publicPortalHidden ? 1 : 0,
        'is_public_hidden' => $publicHidden ? 1 : 0,
        'is_backend_portal_hidden' => $backendHidden ? 1 : 0,
        'is_backend_hidden' => $backendHidden ? 1 : 0,
    ]);
}

function catalogPublicApiFieldId(int $seriesId, string $key): int
{
    return (int) DB::table('series_custom_field')
        ->where('series_id', $seriesId)
        ->where('field_key', $key)
        ->value('id');
}

function catalogPublicApiPart(int $seriesId, string $sku, string $name, ?string $description = null): int
{
    return (int) DB::table('product')->insertGetId([
        'series_id' => $seriesId,
        'sku' => $sku,
        'name' => $name,
        'description' => $description,
    ]);
}

function catalogPublicApiValue(int $partId, int $fieldId, ?string $value): void
{
    DB::table('product_custom_field_value')->insert([
        'product_id' => $partId,
        'series_custom_field_id' => $fieldId,
        'value' => $value,
    ]);
}

function catalogPublicApiSetModuleEnabled(TestCase $test, bool $enabled): void
{
    if (! isset($test->moduleStatusesPath)) {
        $test->moduleStatusesPath = storage_path('framework/testing/public-api-module-'.Str::uuid().'.json');
    }
    File::ensureDirectoryExists(dirname($test->moduleStatusesPath));
    File::put($test->moduleStatusesPath, json_encode([
        'Core' => true,
        'IAM' => true,
        'System' => true,
        'Catalog' => $enabled,
    ], JSON_THROW_ON_ERROR));
    config(['modules.activators.file.statuses-file' => $test->moduleStatusesPath]);
    app()->forgetInstance(ActivatorInterface::class);
}
