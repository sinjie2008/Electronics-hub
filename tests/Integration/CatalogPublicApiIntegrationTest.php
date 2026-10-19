<?php

declare(strict_types=1);

use CatalogSuite\Services\PublicProductApiService;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Modules\Catalog\Support\PublicApiConnection;
use Symfony\Component\Process\Process;

beforeEach(function (): void {
    if (config('database.default') !== 'mysql' || ! config('catalog.public_api.database.username')) {
        $this->markTestSkipped('Requires disposable MySQL with a separate SELECT-only public API account.');
    }

    config([
        'catalog.public_api.enabled' => true,
        'catalog.public_api.cache_ttl' => 0,
        'catalog.public_api.rate_limits.standard' => 1000,
        'catalog.public_api.rate_limits.expensive' => 1000,
    ]);
});

afterEach(function (): void {
    DB::purge('catalog_public_api');
});

it('serves anonymous reads on a genuinely separate SELECT-only MySQL connection', function (): void {
    $series = publicApiIntegrationSeries();
    DB::table('product')->insert(['series_id' => $series, 'sku' => 'PUBLIC-1', 'name' => 'Public component']);
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if ($query->connectionName === 'catalog_public_api') {
            $queries[] = $query->sql;
        }
    });

    $this->getJson('/api/v1/catalog/series/electronics/passives/r-series/parts')
        ->assertOk()->assertJsonPath('data.parts.0.sku', 'PUBLIC-1')
        ->assertJsonPath('data.pagination.total', 1);

    expect($queries)->not->toBeEmpty();
    foreach ($queries as $query) {
        expect(preg_match('/^\\s*(?:SELECT|SHOW GRANTS)\\b/i', $query))->toBe(1);
    }
    $read = app(PublicApiConnection::class)->connection();
    expect($read->getName())->toBe('catalog_public_api');
    expect($read->getConfig('username'))->not->toBe(DB::connection()->getConfig('username'));
    expect((int) $read->scalar('SELECT @@session.transaction_read_only'))->toBe(1);

    try {
        $read->table('product')->insert(['series_id' => $series, 'sku' => 'BLOCKED-SESSION', 'name' => 'Blocked']);
        $this->fail('The read-only session accepted a write.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBeIn([1142, 1792]);
    }

    $read->getPdo()->exec('SET SESSION TRANSACTION READ WRITE');
    try {
        $read->table('product')->insert(['series_id' => $series, 'sku' => 'BLOCKED-GRANT', 'name' => 'Blocked']);
        $this->fail('The SELECT-only account accepted a write.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[1])->toBe(1142);
    } finally {
        $read->getPdo()->exec('SET SESSION TRANSACTION READ ONLY');
    }
    $this->assertDatabaseCount('product', 1);
});

it('fails safely when dedicated read credentials are missing or match the backend account', function (): void {
    config(['database.connections.catalog_public_api.username' => null]);

    $this->getJson('/api/v1/catalog/health')->assertServiceUnavailable()
        ->assertJsonPath('errorCode', 'API_UNAVAILABLE')->assertDontSee('password');

    config(['database.connections.catalog_public_api.username' => config('database.connections.mysql.username')]);

    $this->getJson('/api/v1/catalog/health')->assertServiceUnavailable()
        ->assertJsonPath('errorCode', 'API_UNAVAILABLE');
});

it('keeps MySQL pagination memory and query counts bounded on a representative catalog', function (): void {
    $series = publicApiIntegrationSeries();
    $otherSeries = [];
    for ($index = 0; $index < 24; $index++) {
        $otherSeries[] = (int) DB::table('category')->insertGetId(['parent_id' => $series - 1, 'name' => 'Series '.$index, 'type' => 'series']);
    }
    $field = (int) DB::table('series_custom_field')->insertGetId([
        'series_id' => $series, 'field_key' => 'voltage', 'label' => 'Voltage',
        'field_scope' => 'product_attribute', 'field_type' => 'number', 'default_value' => '12',
    ]);
    for ($chunk = 0; $chunk < 12; $chunk++) {
        $parts = [];
        for ($index = 0; $index < 500; $index++) {
            $number = $chunk * 500 + $index;
            $parts[] = ['series_id' => $series, 'sku' => sprintf('R-%05d', $number), 'name' => 'Resistor '.$number];
        }
        DB::table('product')->insert($parts);
    }
    foreach ($otherSeries as $otherId) {
        $parts = [];
        for ($index = 0; $index < 100; $index++) {
            $parts[] = ['series_id' => $otherId, 'sku' => 'OTHER-'.$index, 'name' => 'Other component '.$index];
        }
        DB::table('product')->insert($parts);
    }
    DB::statement(
        'INSERT INTO product_custom_field_value (product_id, series_custom_field_id, value)
         SELECT id, ?, CAST(MOD(id, 20) AS CHAR) FROM product WHERE series_id = ?',
        [$field, $series]
    );
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if ($query->connectionName === 'catalog_public_api') {
            $queries[] = $query->sql;
        }
    });
    memory_reset_peak_usage();
    $memoryBefore = memory_get_usage();
    $started = hrtime(true);

    $this->getJson('/api/v1/catalog/series/electronics/passives/r-series/parts?per_page=25&page=100&sort=voltage')
        ->assertOk()->assertJsonCount(25, 'data.parts')->assertJsonPath('data.pagination.total', 6000)
        ->assertJsonPath('data.pagination.page', 100);

    $elapsed = (hrtime(true) - $started) / 1000000;
    $peakGrowth = memory_get_peak_usage() - $memoryBefore;
    expect(count($queries))->toBeLessThanOrEqual(8);
    expect($peakGrowth)->toBeLessThan(33554432);
    expect(implode(' ', $queries))->toContain('LIMIT');
    fwrite(STDERR, sprintf("\nPublic API disposable benchmark: 8400 parts / 25 series, 6000 selected, %d queries, %.1f ms, %.1f MiB peak growth.\n", count($queries), $elapsed, $peakGrowth / 1048576));

    $queries = [];
    $this->getJson('/api/v1/catalog/tree')->assertOk()
        ->assertJsonCount(25, 'data.0.children.0.children');
    expect(count($queries))->toBeLessThanOrEqual(3);

    $this->getJson('/api/v1/catalog/series/electronics/passives/r-series/facets')
        ->assertOk()->assertJsonPath('data.totalParts', 6000)
        ->assertJsonCount(20, 'data.facets.0.values')->assertJsonPath('data.facets.0.values.0.count', 300);
});

it('rejects oversized facets before serializing an unbounded public response', function (): void {
    $series = publicApiIntegrationSeries();
    $field = (int) DB::table('series_custom_field')->insertGetId([
        'series_id' => $series, 'field_key' => 'large', 'label' => 'Large public value',
        'field_scope' => 'product_attribute', 'field_type' => 'text',
    ]);
    for ($index = 0; $index < 8; $index++) {
        $part = (int) DB::table('product')->insertGetId(['series_id' => $series, 'sku' => 'L-'.$index, 'name' => 'Large '.$index]);
        DB::table('product_custom_field_value')->insert([
            'product_id' => $part, 'series_custom_field_id' => $field, 'value' => str_repeat((string) $index, 6000),
        ]);
    }
    config(['catalog.public_api.max_response_bytes' => 16000]);

    $this->getJson('/api/v1/catalog/series/electronics/passives/r-series/facets')
        ->assertServiceUnavailable()->assertJsonPath('errorCode', 'RESOURCE_LIMIT');

    config(['catalog.public_api.max_response_bytes' => 8388608]);
    $this->getJson('/api/v1/catalog/health')->assertOk();
});

it('matches the pinned source payloads on the same disposable MySQL fixture', function (): void {
    $referenceDirectory = getenv('CATALOG_PUBLIC_API_REFERENCE_DIR');
    if (! is_string($referenceDirectory) || ! is_file($referenceDirectory.'/PublicProductApiService.php')
        || ! extension_loaded('mysqli')) {
        $this->markTestSkipped('Requires the pinned read-only source files and mysqli for live compatibility comparison.');
    }
    require_once $referenceDirectory.'/CatalogApiException.php';
    require_once $referenceDirectory.'/PublicProductApiService.php';
    $series = publicApiIntegrationSeries();
    DB::table('series_custom_field')->insert([
        ['series_id' => $series, 'field_key' => 'voltage', 'label' => 'Voltage', 'field_scope' => 'product_attribute', 'field_type' => 'number', 'default_value' => '12'],
        ['series_id' => $series, 'field_key' => 'summary', 'label' => 'Summary', 'field_scope' => 'series_metadata', 'field_type' => 'text', 'default_value' => 'General purpose'],
    ]);
    DB::table('product')->insert([
        ['series_id' => $series, 'sku' => 'A-001', 'name' => 'Component One', 'description' => 'Public description'],
        ['series_id' => $series, 'sku' => 'A-002', 'name' => 'Component Two', 'description' => null],
    ]);
    $settings = config('database.connections.catalog_public_api');
    $database = new mysqli($settings['host'], $settings['username'], $settings['password'], $settings['database'], (int) $settings['port']);
    $database->set_charset('utf8mb4');
    $database->query('SET SESSION TRANSACTION READ ONLY');
    try {
        $reference = new PublicProductApiService($database);
        $path = 'electronics/passives/r-series';
        $root = $reference->root();
        $root['endpoints'] = array_map(
            static fn (string $endpoint): string => str_replace('/api', '/api/v1/catalog', rtrim($endpoint, '/')),
            $root['endpoints']
        );
        $health = $reference->health();
        unset($health['database']);
        foreach ([
            '' => $root,
            '/health' => $health,
            '/tree' => $reference->tree(),
            '/resolve/'.$path => $reference->resolve($path),
            '/categories/electronics/passives' => $reference->category('electronics/passives'),
            '/series/'.$path => $reference->series($path),
            '/series/'.$path.'/fields' => $reference->fields($path),
            '/series/'.$path.'/parts' => $reference->parts($path, []),
            '/series/'.$path.'/facets' => $reference->facets($path),
            '/search?q=component' => $reference->search('component'),
            '/series/'.$path.'/parts?filter[voltage]=12&per_page=1&page=2&direction=desc' => $reference->parts($path, ['filter' => ['voltage' => '12'], 'per_page' => 1, 'page' => 2, 'direction' => 'desc']),
        ] as $endpoint => $expected) {
            $this->getJson('/api/v1/catalog'.$endpoint)->assertOk()->assertJsonPath('data', $expected);
        }
    } finally {
        $database->close();
    }
});

it('enforces public API limits with a real disposable Redis store', function (): void {
    if (getenv('RUN_REDIS_INTEGRATION') !== 'true') {
        $this->markTestSkipped('Requires the explicitly enabled disposable Redis integration environment.');
    }
    RateLimiter::swap(new CacheRateLimiter(Cache::store('redis')));
    config(['catalog.public_api.rate_limits.standard' => 2]);
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.77']);
    $key = 'catalog-public-api:standard:'.hash('sha256', '198.51.100.77');
    RateLimiter::clear($key);
    try {
        $this->getJson('/api/v1/catalog/health')->assertOk();
        $this->getJson('/api/v1/catalog/health')->assertOk();
        $this->getJson('/api/v1/catalog/health')->assertTooManyRequests()->assertHeader('Retry-After')
            ->assertJsonPath('errorCode', 'RATE_LIMITED');
    } finally {
        RateLimiter::clear($key);
    }
});

it('refuses an independently provisioned account with excessive MySQL grants', function (): void {
    $username = getenv('CATALOG_PUBLIC_API_TEST_EXCESS_USERNAME');
    $password = getenv('CATALOG_PUBLIC_API_TEST_EXCESS_PASSWORD');
    if (! is_string($username) || $username === '' || ! is_string($password) || $password === '') {
        $this->markTestSkipped('Requires a separate disposable account with SELECT and INSERT grants.');
    }
    config([
        'database.connections.catalog_public_api.username' => $username,
        'database.connections.catalog_public_api.password' => $password,
    ]);

    $this->getJson('/api/v1/catalog/tree')->assertServiceUnavailable()
        ->assertJsonPath('errorCode', 'API_UNAVAILABLE')->assertDontSee($username);
    $this->assertDatabaseCount('product', 0);
});

it('honors module and API switches in fresh processes with uncached and cached routes', function (): void {
    $directory = storage_path('framework/testing/public-api-process-'.Str::uuid());
    File::ensureDirectoryExists($directory);
    $statuses = ['Core' => true, 'IAM' => true, 'System' => true, 'Catalog' => false];
    File::put($directory.'/modules.json', json_encode($statuses, JSON_THROW_ON_ERROR));
    $environment = [
        'MODULE_STATUSES_PATH' => $directory.'/modules.json',
        'APP_ROUTES_CACHE' => $directory.'/routes.php',
        'APP_CONFIG_CACHE' => $directory.'/config.php',
        'APP_DEBUG' => 'false',
        'CATALOG_PUBLIC_API_ENABLED' => 'true',
    ];
    $probe = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$request = Illuminate\Http\Request::create('/api/v1/catalog/tree', 'GET');
$response = $kernel->handle($request);
echo json_encode(['status' => $response->getStatusCode(), 'body' => json_decode($response->getContent(), true)], JSON_THROW_ON_ERROR);
$kernel->terminate($request, $response);
PHP;
    $request = function (array $environment) use ($probe): array {
        $process = new Process([PHP_BINARY, '-r', $probe], base_path(), $environment);
        $process->setTimeout(30)->mustRun();

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    };
    try {
        $disabled = $request($environment);
        expect($disabled['status'])->toBe(503)
            ->and($disabled['body']['errorCode'])->toBe('API_UNAVAILABLE');

        $statuses['Catalog'] = true;
        File::put($directory.'/modules.json', json_encode($statuses, JSON_THROW_ON_ERROR));
        $cache = new Process([PHP_BINARY, 'artisan', 'route:cache', '--no-interaction'], base_path(), $environment);
        $cache->setTimeout(30)->mustRun();
        expect(is_file($directory.'/routes.php'))->toBeTrue();
        expect($request($environment)['status'])->toBe(200);

        $environment['CATALOG_PUBLIC_API_ENABLED'] = 'false';
        expect($request($environment)['body']['errorCode'])->toBe('API_UNAVAILABLE');
        $statuses['Catalog'] = false;
        File::put($directory.'/modules.json', json_encode($statuses, JSON_THROW_ON_ERROR));
        expect($request($environment)['status'])->toBe(503);
        $environment['CATALOG_PUBLIC_API_ENABLED'] = 'true';
        expect($request($environment)['body']['errorCode'])->toBe('API_UNAVAILABLE');
    } finally {
        File::deleteDirectory($directory);
    }
});

function publicApiIntegrationSeries(): int
{
    $root = (int) DB::table('category')->insertGetId(['name' => 'Electronics', 'type' => 'category']);
    $group = (int) DB::table('category')->insertGetId(['parent_id' => $root, 'name' => 'Passives', 'type' => 'category']);

    return (int) DB::table('category')->insertGetId(['parent_id' => $group, 'name' => 'R Series', 'type' => 'series']);
}
