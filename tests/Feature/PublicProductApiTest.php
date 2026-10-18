<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Modules\Catalog\Http\Middleware\CatalogBoundary;
use Modules\Catalog\Http\Middleware\PublicApiHeaders;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Tests\Support\CatalogTestDatabase;
use Tests\Support\PublicProductApiFixture as Fixture;

beforeEach(function () {
    $this->publicCatalogStorage = CatalogTestDatabase::prepare();
    $this->publicCatalogOriginalStatuses = config('modules.activators.file.statuses-file');
    $this->publicCatalogStatuses = storage_path('framework/testing/public-catalog-'.bin2hex(random_bytes(8)).'.json');
    File::ensureDirectoryExists(dirname($this->publicCatalogStatuses));
    File::put($this->publicCatalogStatuses, json_encode([
        'Core' => true, 'IAM' => true, 'System' => true, 'Catalog' => true,
    ], JSON_THROW_ON_ERROR));
    config(['modules.activators.file.statuses-file' => $this->publicCatalogStatuses]);
    app()->forgetInstance(ActivatorInterface::class);
});

afterEach(function () {
    CatalogTestDatabase::cleanup($this->publicCatalogStorage ?? null);
    if (isset($this->publicCatalogStatuses)) {
        File::delete($this->publicCatalogStatuses, $this->publicCatalogStatuses.'.lock');
    }
    if (isset($this->publicCatalogOriginalStatuses)) {
        config(['modules.activators.file.statuses-file' => $this->publicCatalogOriginalStatuses]);
        app()->forgetInstance(ActivatorInterface::class);
    }
});

it('matches the executed source controller contract', function (array $case) {
    Fixture::seed();

    $response = $this->withHeader('X-Correlation-ID', 'public-oracle')
        ->json($case['method'], $case['uri']);

    $response->assertStatus($case['status'])->assertHeader('X-Correlation-ID', 'public-oracle');
    expect($response->json())->toBe([...$case['body'], 'correlationId' => 'public-oracle']);
})->with(array_map(
    static fn (array $case): array => [$case],
    json_decode(file_get_contents(dirname(__DIR__).'/Fixtures/public-product-api/source-oracle.json'), true, flags: JSON_THROW_ON_ERROR)['cases'],
));

it('serves the ten endpoint discovery contract anonymously with correlation and security headers', function () {
    $response = $this->withHeader('X-Correlation-ID', 'public-contract-root')
        ->getJson('/api/')
        ->assertOk()
        ->assertHeader('X-Correlation-ID', 'public-contract-root')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Cache-Control')
        ->assertExactJson([
            'success' => true,
            'data' => [
                'name' => 'Public Product API',
                'sourceOfTruth' => 'existing Product Catalog Manager MySQL database',
                'readOnly' => true,
                'requiresMigration' => false,
                'requiresImport' => false,
                'requiresManualSync' => false,
                'endpointCount' => 10,
                'endpoints' => ['/api/', '/api/health', '/api/tree', '/api/resolve/{path}', '/api/categories/{path}', '/api/series/{path}', '/api/series/{path}/fields', '/api/series/{path}/parts', '/api/series/{path}/facets', '/api/search?q={keyword}'],
                'tree' => [],
            ],
            'correlationId' => 'public-contract-root',
        ]);

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('reports database health without authentication or a synchronization requirement', function () {
    $this->withHeader('X-Correlation-ID', 'public-health')->getJson('/api/health')
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'data' => ['status' => 'ok', 'database' => 'connected', 'writeOperations' => false],
            'correlationId' => 'public-health',
        ]);
});

it('sanitizes framework debug errors while preserving public security and CORS headers', function () {
    config(['catalog.public_api.allowed_origins' => ['https://catalog.example.test']]);
    $request = Request::create('/api/tree');
    $request->headers->set('Origin', 'https://catalog.example.test');
    $request->headers->set('X-Correlation-ID', 'safe-public-error');

    $response = (new CatalogBoundary)->handle($request, fn ($request) => (new PublicApiHeaders)->handle(
        $request,
        fn () => response()->json(['message' => '/private/file.php', 'exception' => 'RuntimeException', 'trace' => ['secret']], 400),
    ));

    expect($response->getStatusCode())->toBe(400)
        ->and(json_decode($response->getContent(), true))->toBe([
            'success' => false, 'errorCode' => 'REQUEST_REJECTED',
            'message' => 'The Catalog request could not be completed.',
            'details' => [], 'correlationId' => 'safe-public-error',
        ])
        ->and($response->headers->get('Cache-Control'))->toBe('no-store')
        ->and($response->headers->get('Vary'))->toBe('Origin')
        ->and($response->headers->get('Access-Control-Allow-Origin'))->toBe('https://catalog.example.test')
        ->and($response->headers->get('Access-Control-Expose-Headers'))->toBe('X-Correlation-ID')
        ->and($response->headers->get('X-Correlation-ID'))->toBe('safe-public-error')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
});

it('orders the tree by display order and preserves node types duplicate slugs and Unicode', function () {
    Fixture::seed();

    $tree = $this->getJson('/api/tree')->assertOk()->json('data');

    expect(array_column($tree, 'id'))->toBe([100, 200]);
    expect(array_column($tree[0]['children'], 'id'))->toBe([120, 110, 130, 140, 150, 151]);
    expect($tree[0]['children'][1])->toBe([...Fixture::series(), 'children' => [], 'partCount' => 4]);
    expect($tree[0]['children'][2])->toBe([
        'id' => 130, 'parentId' => 100, 'name' => '測定 Étage', 'type' => 'category',
        'slug' => '測定-Étage', 'path' => 'electronics/測定-Étage', 'displayOrder' => 3, 'children' => [],
    ]);
    expect(array_column(array_slice($tree[0]['children'], 4), 'path'))
        ->toBe(['electronics/switch-150', 'electronics/switch-151']);
    expect($tree[1])->toBe([
        'id' => 200, 'parentId' => null, 'name' => 'Empty Category', 'type' => 'category',
        'slug' => 'empty-category', 'path' => 'empty-category', 'displayOrder' => 2, 'children' => [],
    ]);
});

it('resolves complete public resources and breadcrumbs through pretty and index routes', function (string $prefix) {
    Fixture::seed();

    $response = $this->getJson($prefix.'resolve/'.Fixture::SERIES_PATH)->assertOk();

    expect($response->json('data'))->toBe([
        'resource' => Fixture::series(), 'breadcrumb' => [Fixture::root(), Fixture::series()],
    ]);
})->with([
    'pretty' => '/api/', 'index' => '/api/index.php/',
    'root query alias' => '/api/?route=', 'index query alias' => '/api/index.php?route=',
]);

it('resolves percent encoded Unicode without lowercasing non ASCII letters', function () {
    Fixture::seed();

    $this->getJson('/api/resolve/electronics/'.rawurlencode('測定-Étage'))
        ->assertOk()
        ->assertJsonPath('data.resource.id', 130)
        ->assertJsonPath('data.resource.path', 'electronics/測定-Étage');
});

it('returns category children with series part counts and complete ancestor breadcrumbs', function () {
    Fixture::seed();

    $response = $this->getJson('/api/categories/electronics')->assertOk();

    expect($response->json('data.resource'))->toBe(Fixture::root());
    expect($response->json('data.breadcrumb'))->toBe([Fixture::root()]);
    expect(array_column($response->json('data.children'), 'id'))->toBe([120, 110, 130, 140, 150, 151]);
    expect($response->json('data.children.1'))->toBe([...Fixture::series(), 'partCount' => 4]);
    expect($response->json('data.children.0'))->not->toHaveKey('partCount');
});

it('returns visible series metadata and fields with exact defaults types and field order', function () {
    Fixture::seed();

    $response = $this->getJson('/api/series/'.Fixture::SERIES_PATH)->assertOk();

    expect($response->json('data'))->toBe([
        'resource' => Fixture::series(),
        'breadcrumb' => [Fixture::root(), Fixture::series()],
        'metadata' => Fixture::metadata(),
        'fields' => Fixture::fields(),
        'partCount' => 4,
    ]);
});

it('exposes only public product attributes through the standalone fields endpoint', function () {
    Fixture::seed();

    $response = $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/fields')->assertOk();

    expect($response->json('data'))->toBe(Fixture::fields());
});

it('returns natural SKU order with default values and no private fields in public parts', function () {
    Fixture::seed();

    $response = $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/parts')->assertOk();

    expect($response->json('data'))->toBe([
        'series' => Fixture::series(), 'fields' => Fixture::fields(), 'parts' => Fixture::parts(),
        'pagination' => ['page' => 1, 'per_page' => 25, 'total' => 4, 'last_page' => 1],
    ]);
});

it('reflects existing database updates on the next public read without import or synchronization', function () {
    Fixture::seed();
    $this->getJson('/api/series/'.Fixture::SERIES_PATH)->assertOk()->assertJsonPath('data.partCount', 4);
    DB::connection('catalog')->table('product')->insert([
        'id' => 2010, 'series_id' => 110, 'sku' => 'SW-0', 'name' => 'New live part', 'description' => null,
    ]);

    $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/parts')->assertOk()
        ->assertJsonPath('data.parts.0.id', 2010)
        ->assertJsonPath('data.pagination.total', 5);
});

it('preserves natural sorting tie breaks and direction for public attributes and identifiers', function (array $query, array $ids) {
    Fixture::seed();

    $response = $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/parts?'.http_build_query($query))->assertOk();

    expect(array_column($response->json('data.parts'), 'id'))->toBe($ids);
})->with([
    'numeric attribute naturally ascending' => [['sort' => 'voltage'], [2002, 2003, 2001, 2004]],
    'name tie uses descending ID too' => [['sort' => 'name', 'direction' => 'DESC'], [2004, 2003, 2002, 2001]],
    'case insensitive attribute tie uses ID' => [['sort' => 'finish'], [2001, 2002, 2004, 2003]],
    'numeric ID descending' => [['sort' => 'id', 'direction' => 'desc'], [2004, 2003, 2002, 2001]],
]);

it('combines case insensitive OR values within filters and AND between fields', function () {
    Fixture::seed();
    $query = http_build_query(['filter' => ['finish' => ['GOLD', 'unknown'], 'voltage' => ['2', '5']]]);

    $response = $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/parts?'.$query)->assertOk();

    expect(array_column($response->json('data.parts'), 'id'))->toBe([2002]);
    expect($response->json('data.pagination.total'))->toBe(1);
});

it('searches serialized public parts including defaults but excludes hidden attribute content', function (string $search, array $ids) {
    Fixture::seed();

    $response = $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/parts?'.http_build_query(['search' => $search]))->assertOk();

    expect(array_column($response->json('data.parts'), 'id'))->toBe($ids);
})->with([
    'description Unicode' => [' 測定 ', [2004]],
    'default field value' => ['silver', [2003]],
    'private value' => ['CLASSIFIED', []],
    'public attribute key' => ['voltage', [2003, 2002, 2001, 2004]],
]);

it('clamps numeric pagination while retaining the source page and per page contract', function (array $query, array $pagination, array $ids) {
    Fixture::seed();

    $response = $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/parts?'.http_build_query($query))->assertOk();

    expect($response->json('data.pagination'))->toBe($pagination);
    expect(array_column($response->json('data.parts'), 'id'))->toBe($ids);
})->with([
    'last page clamp' => [['page' => 999, 'per_page' => 2], ['page' => 2, 'per_page' => 2, 'total' => 4, 'last_page' => 2], [2001, 2004]],
    'negative values clamp to one' => [['page' => -1, 'per_page' => -5], ['page' => 1, 'per_page' => 1, 'total' => 4, 'last_page' => 4], [2003]],
    'maximum page size' => [['page' => 0, 'per_page' => 1000], ['page' => 1, 'per_page' => 100, 'total' => 4, 'last_page' => 1], [2003, 2002, 2001, 2004]],
    'fractional values cast as source integers' => [['page' => '2.9', 'per_page' => '2.9'], ['page' => 2, 'per_page' => 2, 'total' => 4, 'last_page' => 2], [2001, 2004]],
]);

it('counts facet defaults with case sensitive buckets numeric value types and natural order', function () {
    Fixture::seed();

    $response = $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/facets')->assertOk();

    expect($response->json('data'))->toBe([
        'series' => Fixture::series(), 'totalParts' => 4,
        'facets' => [
            ['key' => 'voltage', 'label' => 'Voltage', 'type' => 'number', 'values' => [['value' => 2, 'count' => 1], ['value' => 5, 'count' => 1], ['value' => 10, 'count' => 2]]],
            ['key' => 'finish', 'label' => 'Finish', 'type' => 'text', 'values' => [['value' => 'Gold', 'count' => 2], ['value' => 'gold', 'count' => 1], ['value' => 'Silver', 'count' => 1]]],
            ['key' => 'optional', 'label' => 'Optional', 'type' => 'text', 'values' => [['value' => 0, 'count' => 1]]],
        ],
    ]);
});

it('keeps empty series fields parts facets and pagination as empty lists', function () {
    Fixture::seed();

    $this->getJson('/api/series/electronics/blank-series')
        ->assertOk()->assertJsonPath('data.fields', [])->assertJsonPath('data.metadata', [])->assertJsonPath('data.partCount', 0);
    $this->getJson('/api/series/electronics/blank-series/parts?page=20')
        ->assertOk()->assertJsonPath('data.fields', [])->assertJsonPath('data.parts', [])
        ->assertJsonPath('data.pagination', ['page' => 1, 'per_page' => 25, 'total' => 0, 'last_page' => 1]);
    $this->getJson('/api/series/electronics/blank-series/facets')
        ->assertOk()->assertJsonPath('data.facets', [])->assertJsonPath('data.totalParts', 0);
});

it('prefers a real series whose path ends in a reserved subresource name', function (string $suffix) {
    Fixture::seed();
    DB::connection('catalog')->table('category')->insert([
        'id' => 160, 'parent_id' => 110, 'name' => $suffix, 'type' => 'series', 'display_order' => 1,
    ]);

    $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/'.$suffix)
        ->assertOk()
        ->assertJsonPath('data.resource.id', 160)
        ->assertJsonPath('data.resource.path', Fixture::SERIES_PATH.'/'.$suffix)
        ->assertJsonPath('data.fields', [])
        ->assertJsonPath('data.partCount', 0);
})->with(['fields', 'parts', 'facets']);

it('searches trimmed global node names and product descriptions with source name and ID order', function () {
    Fixture::seed();

    $response = $this->getJson('/api/search?q=%20alpha%20')->assertOk();

    expect($response->json('data'))->toBe([
        'query' => 'alpha', 'nodes' => [],
        'parts' => [
            ['id' => 2001, 'sku' => 'SW-10', 'name' => 'Alpha', 'description' => null, 'series' => Fixture::series()],
            ['id' => 2002, 'sku' => 'SW-2', 'name' => 'Alpha', 'description' => 'Second part', 'series' => Fixture::series()],
        ],
    ]);
    $this->getJson('/api/search?q=power')->assertOk()->assertJsonPath('data.nodes', [Fixture::series()])->assertJsonPath('data.parts', []);
    $this->getJson('/api/search?q='.rawurlencode('測定'))->assertOk()->assertJsonPath('data.parts.0.id', 2004);
});

it('retains SQL wildcard matching for global product search', function () {
    Fixture::seed();

    $response = $this->getJson('/api/search?q=%25')->assertOk();

    expect(array_column($response->json('data.parts'), 'id'))->toBe([2001, 2002, 2003, 2004]);
    expect($response->json('data.nodes'))->toBe([]);
});

it('limits global search to the first hundred matching products ordered by name and ID', function () {
    Fixture::seed();
    $products = [];
    for ($index = 1; $index <= 101; $index++) {
        $products[] = [
            'id' => 2100 + $index, 'series_id' => 110, 'sku' => 'LIMIT-'.$index,
            'name' => sprintf('Public Match %03d', $index), 'description' => null,
        ];
    }
    DB::connection('catalog')->table('product')->insert($products);

    $response = $this->getJson('/api/search?q=Public%20Match')->assertOk();

    expect(array_column($response->json('data.parts'), 'id'))->toBe(range(2101, 2200));
});

it('returns empty search lists for blank queries', function (string $query) {
    Fixture::seed();

    $this->getJson('/api/search'.$query)->assertOk()
        ->assertJsonPath('data', ['query' => '', 'nodes' => [], 'parts' => []]);
})->with(['missing' => '', 'whitespace' => '?q=%20%20']);

it('returns source error codes messages details and correlation for missing resources', function (string $uri, string $code, string $message, ?array $details) {
    Fixture::seed();
    $this->withHeader('X-Correlation-ID', 'public-missing')->getJson($uri)
        ->assertNotFound()->assertExactJson([
            'success' => false, 'errorCode' => $code, 'message' => $message,
            'details' => $details ?? [], 'correlationId' => 'public-missing',
        ]);
})->with([
    'unknown API route' => ['/api/unknown', 'NOT_FOUND', 'API route not found.', null],
    'missing path' => ['/api/resolve/electronics/missing', 'NOT_FOUND', 'Public catalog path not found.', ['path' => 'electronics/missing']],
    'series used as category' => ['/api/categories/electronics/power-switches', 'CATEGORY_NOT_FOUND', 'Public category not found.', null],
    'category used as series' => ['/api/series/electronics', 'SERIES_NOT_FOUND', 'Public series not found.', null],
    'missing series' => ['/api/series/electronics/missing', 'SERIES_NOT_FOUND', 'Public series not found.', null],
]);

it('returns 405 and the public error envelope for every non GET method without writing data', function (string $method, string $uri) {
    Fixture::seed();

    $response = $this->withHeader('X-Correlation-ID', 'public-method')->json($method, $uri, ['name' => 'Unauthorized write']);

    $response->assertMethodNotAllowed()->assertHeader('Allow', 'GET');
    if ($method !== 'HEAD') {
        $response->assertExactJson([
            'success' => false, 'errorCode' => 'METHOD_NOT_ALLOWED', 'message' => 'Only GET is supported.',
            'details' => [], 'correlationId' => 'public-method',
        ]);
    }
    expect(DB::connection('catalog')->table('product')->count())->toBe(4);
    expect(DB::connection('catalog')->table('category')->where('name', 'Unauthorized write')->exists())->toBeFalse();
})->with(['POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'HEAD'])->with([
    'root' => '/api/', 'parts' => '/api/series/electronics/power-switches/parts',
]);

it('returns 422 INVALID_PATH for traversal malformed encoding and paths over the byte limit', function (string $path) {
    $this->getJson('/api/resolve/'.$path)
        ->assertUnprocessable()->assertJsonPath('errorCode', 'INVALID_PATH');
})->with([
    'invalid percent sequence' => 'bad%GG',
    'truncated percent sequence' => 'bad%2',
    'dot traversal' => 'electronics/../secrets',
    'encoded traversal' => 'electronics/%2e%2e/secrets',
    'double encoded traversal' => 'electronics/%252e%252e/secrets',
    'single dot segment' => 'electronics/./secrets',
    'over 2048 bytes' => str_repeat('a', 2049),
]);

it('returns 422 INVALID_QUERY for non scalar unbounded and nested public query inputs', function (string $uri, array $query) {
    Fixture::seed();

    $this->getJson($uri.'?'.http_build_query($query))
        ->assertUnprocessable()->assertJsonPath('errorCode', 'INVALID_QUERY');
})->with([
    'global search array' => ['/api/search', ['q' => ['alpha']]],
    'global search oversized' => ['/api/search', ['q' => str_repeat('a', 2001)]],
    'parts search array' => ['/api/series/electronics/power-switches/parts', ['search' => ['alpha']]],
    'parts search oversized' => ['/api/series/electronics/power-switches/parts', ['search' => str_repeat('a', 2001)]],
    'filter is scalar' => ['/api/series/electronics/power-switches/parts', ['filter' => 'voltage']],
    'nested filter values' => ['/api/series/electronics/power-switches/parts', ['filter' => ['voltage' => [['10']]]]],
    'page array' => ['/api/series/electronics/power-switches/parts', ['page' => ['1']]],
    'per page array' => ['/api/series/electronics/power-switches/parts', ['per_page' => ['1']]],
    'sort array' => ['/api/series/electronics/power-switches/parts', ['sort' => ['sku']]],
    'direction array' => ['/api/series/electronics/power-switches/parts', ['direction' => ['asc']]],
]);

it('returns 422 INVALID_QUERY for unknown hidden and injected sort and filter fields', function (array $query, string $message) {
    Fixture::seed();

    $this->getJson('/api/series/'.Fixture::SERIES_PATH.'/parts?'.http_build_query($query))
        ->assertUnprocessable()->assertJsonPath('errorCode', 'INVALID_QUERY')->assertJsonPath('message', $message);
})->with([
    'hidden filter' => [['filter' => ['secret' => 'CLASSIFIED']], 'Unknown filter field.'],
    'unknown filter' => [['filter' => ['unknown' => 'x']], 'Unknown filter field.'],
    'sort injection' => [['sort' => 'sku DESC; DROP TABLE product'], 'Unknown sort field.'],
    'hidden sort' => [['sort' => 'secret'], 'Unknown sort field.'],
    'direction injection' => [['direction' => 'asc; SELECT 1'], 'direction must be asc or desc.'],
]);

it('accepts the exact search and path byte boundaries', function () {
    $this->getJson('/api/search?q='.str_repeat('a', 2000))->assertOk()->assertJsonPath('data.parts', []);
    $this->getJson('/api/resolve/'.str_repeat('a', 2048))->assertNotFound()->assertJsonPath('errorCode', 'NOT_FOUND');
});

it('returns 500 INVALID_HIERARCHY for cyclic source data without recursing indefinitely', function () {
    Fixture::seed();
    DB::connection('catalog')->table('category')->where('id', 100)->update(['parent_id' => 110]);

    $this->getJson('/api/tree')->assertInternalServerError()
        ->assertJsonPath('errorCode', 'INVALID_HIERARCHY')
        ->assertJsonPath('message', 'Catalog hierarchy contains a cycle.');
});

it('paginates and naturally sorts a large series without loading unrelated series products', function () {
    Fixture::seed();
    $rows = [];
    for ($index = 1; $index <= 2000; $index++) {
        $rows[] = ['id' => 10000 + $index, 'series_id' => 140, 'sku' => 'LARGE-'.$index, 'name' => 'Large fixture', 'description' => null];
    }
    foreach (array_chunk($rows, 500) as $chunk) {
        DB::connection('catalog')->table('product')->insert($chunk);
    }

    $response = $this->getJson('/api/series/electronics/blank-series/parts?page=20&per_page=100');
    $response->assertOk()->assertJsonPath('data.pagination', ['page' => 20, 'per_page' => 100, 'total' => 2000, 'last_page' => 20])
        ->assertJsonCount(100, 'data.parts')->assertJsonPath('data.parts.0.sku', 'LARGE-1901')->assertJsonPath('data.parts.99.sku', 'LARGE-2000');
    $this->getJson('/api/series/electronics/power-switches/parts')->assertOk()->assertJsonPath('data.pagination.total', 4);
});

it('keeps secondary slug collisions distinct instead of resolving a different product series', function () {
    Fixture::seed();
    DB::connection('catalog')->table('category')->insert([
        'id' => 152, 'parent_id' => 100, 'name' => 'Switch-150', 'type' => 'series', 'display_order' => 6,
    ]);

    $this->getJson('/api/resolve/electronics/switch-150-150')->assertOk()->assertJsonPath('data.resource.id', 150);
    $this->getJson('/api/resolve/electronics/switch-150-152')->assertOk()->assertJsonPath('data.resource.id', 152);
    $this->getJson('/api/resolve/electronics/switch-150')->assertNotFound();
    $this->getJson('/api/tree')->assertOk()->assertHeader('Cache-Control', 'no-store')->assertHeader('X-Content-Type-Options', 'nosniff');
});

it('rejects invalid UTF-8 paths and queries through safe public error envelopes', function () {
    $this->getJson('/api/resolve/%FF')->assertUnprocessable()->assertJsonPath('errorCode', 'INVALID_PATH')->assertJsonMissingPath('trace');
    $this->getJson('/api/search?q=%FF')->assertUnprocessable()->assertJsonPath('errorCode', 'INVALID_QUERY')->assertJsonMissingPath('trace');
});

it('returns the safe GET-only source contract for methods Laravel does not include in Route any', function () {
    $this->call('TRACE', '/api/tree')->assertMethodNotAllowed()->assertHeader('Allow', 'GET')
        ->assertJsonPath('errorCode', 'METHOD_NOT_ALLOWED')->assertJsonMissingPath('trace');
    $this->call('CONNECT', '/api/health')->assertMethodNotAllowed()->assertHeader('Allow', 'GET')
        ->assertJsonPath('errorCode', 'METHOD_NOT_ALLOWED')->assertJsonMissingPath('trace');
});
