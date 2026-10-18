<?php

declare(strict_types=1);

use CatalogSuite\Controllers\PublicProductApiController;
use CatalogSuite\Http\Transport;
use CatalogSuite\Support\Config;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Symfony\Component\Process\Process;
use Tests\Support\CatalogTestDatabase;
use Tests\Support\PublicProductApiFixture;

$root = dirname(__DIR__, 2);
$reference = $argv[1] ?? '';
if (! is_file($reference.'/app/Controllers/PublicProductApiController.php')) {
    fwrite(STDERR, "Usage: php tests/Support/ComparePublicProductApi.php /path/to/source-checkout\n");
    exit(2);
}
require $root.'/vendor/autoload.php';
$head = new Process(['git', '-C', $reference, 'rev-parse', 'HEAD']);
$head->mustRun();
if (trim($head->getOutput()) !== 'c10faf4b5314668f7695a9c138a8e9333bee469d') {
    throw new RuntimeException('The reference checkout must be the exact recorded source commit.');
}
$app = require $root.'/bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();
spl_autoload_register(static function (string $class) use ($reference): void {
    $prefix = 'CatalogSuite\\';
    if (str_starts_with($class, $prefix)) {
        $file = $reference.'/app/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
$db = config('database.connections.catalog');
Config::set('db', $db);
$storage = CatalogTestDatabase::prepare();
$series = '/api/series/electronics/power-switches';
$cases = [
    'discovery' => ['GET', '/api/'],
    'health' => ['GET', '/api/health'],
    'tree' => ['GET', '/api/tree'],
    'resolve' => ['GET', '/api/resolve/electronics/power-switches'],
    'category' => ['GET', '/api/categories/electronics'],
    'series' => ['GET', $series],
    'fields' => ['GET', $series.'/fields'],
    'parts' => ['GET', $series.'/parts'],
    'facets' => ['GET', $series.'/facets'],
    'search' => ['GET', '/api/search?q=alpha'],
    'search-trimmed' => ['GET', '/api/search?q=%20alpha%20'],
    'search-wildcard' => ['GET', '/api/search?q=%25'],
    'search-description' => ['GET', '/api/search?q='.rawurlencode('測定')],
    'search-blank' => ['GET', '/api/search?q=%20'],
    'search-missing' => ['GET', '/api/search'],
    'filter-or-and' => ['GET', $series.'/parts?'.http_build_query(['filter' => ['finish' => ['GOLD', 'unknown'], 'voltage' => ['2', '5']]])],
    'filter-whitespace' => ['GET', $series.'/parts?filter[finish]=%20Gold%20'],
    'filter-empty' => ['GET', $series.'/parts?filter[optional]='],
    'sort-natural' => ['GET', $series.'/parts?sort=voltage'],
    'sort-descending-tie' => ['GET', $series.'/parts?sort=name&direction=DESC'],
    'parts-public-search' => ['GET', $series.'/parts?search=silver'],
    'parts-private-search' => ['GET', $series.'/parts?search=CLASSIFIED'],
    'pagination-last' => ['GET', $series.'/parts?page=999&per_page=2'],
    'pagination-negative' => ['GET', $series.'/parts?page=-1&per_page=-5'],
    'pagination-fractional' => ['GET', $series.'/parts?page=2.9&per_page=2.9'],
    'empty-series' => ['GET', '/api/series/electronics/blank-series'],
    'empty-parts' => ['GET', '/api/series/electronics/blank-series/parts?page=20'],
    'empty-facets' => ['GET', '/api/series/electronics/blank-series/facets'],
    'duplicate-slug' => ['GET', '/api/resolve/electronics/switch-150'],
    'unicode-slug' => ['GET', '/api/resolve/electronics/'.rawurlencode('測定-Étage')],
    'index-path' => ['GET', '/api/index.php/resolve/electronics/power-switches'],
    'index-query' => ['GET', '/api/index.php?route=resolve/electronics/power-switches'],
    'query-precedence' => ['GET', '/api/health?route=resolve/electronics/power-switches'],
    'missing-path' => ['GET', '/api/resolve/electronics/missing'],
    'missing-series' => ['GET', '/api/series/electronics/missing'],
    'wrong-category' => ['GET', '/api/categories/electronics/power-switches'],
    'wrong-series' => ['GET', '/api/series/electronics'],
    'unknown-route' => ['GET', '/api/unknown'],
    'hidden-filter' => ['GET', $series.'/parts?filter[secret]=CLASSIFIED'],
    'hidden-sort' => ['GET', $series.'/parts?sort=secret'],
    'invalid-sort' => ['GET', $series.'/parts?sort=unknown'],
    'invalid-direction' => ['GET', $series.'/parts?direction=sideways'],
    'invalid-filter' => ['GET', $series.'/parts?filter=bad'],
    'invalid-percent' => ['GET', '/api/resolve/bad%GG'],
    'invalid-traversal' => ['GET', '/api/resolve/electronics/%2e%2e/secret'],
    'post' => ['POST', $series.'/parts'],
    'put' => ['PUT', $series.'/parts'],
    'patch' => ['PATCH', $series.'/parts'],
    'delete' => ['DELETE', $series.'/parts'],
    'options' => ['OPTIONS', $series.'/parts'],
];
$output = ['sourceCommit' => 'c10faf4b5314668f7695a9c138a8e9333bee469d', 'sourceSha256' => [
    'app/Controllers/PublicProductApiController.php' => hash_file('sha256', $reference.'/app/Controllers/PublicProductApiController.php'),
    'app/Services/PublicProductApiService.php' => hash_file('sha256', $reference.'/app/Services/PublicProductApiService.php'),
], 'normalization' => 'Only top-level correlationId is removed; payload types, keys, lists and status codes are preserved.', 'cases' => []];
$failures = [];
try {
    PublicProductApiFixture::seed();
    $kernel = $app->make(HttpKernel::class);
    foreach ($cases as $name => [$method, $uri]) {
        parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
        $source = Transport::capture('', $query, [], [], [
            'REQUEST_METHOD' => $method, 'REQUEST_URI' => $uri,
            'SCRIPT_NAME' => '/api/index.php', 'HTTP_X_CORRELATION_ID' => 'oracle',
        ], static fn () => (new PublicProductApiController)->run());
        $body = json_decode($source['body'], true, flags: JSON_THROW_ON_ERROR);
        unset($body['correlationId']);
        $output['cases'][$name] = ['method' => $method, 'uri' => $uri, 'status' => $source['status'], 'body' => $body];
        $request = Request::create($uri, $method, server: ['HTTP_ACCEPT' => 'application/json', 'HTTP_X_CORRELATION_ID' => 'oracle']);
        $target = $kernel->handle($request);
        $targetBody = json_decode($target->getContent(), true, flags: JSON_THROW_ON_ERROR);
        unset($targetBody['correlationId']);
        if ($source['status'] !== $target->getStatusCode() || $body !== $targetBody) {
            $failures[$name] = ['sourceStatus' => $source['status'], 'targetStatus' => $target->getStatusCode(), 'sourceBody' => $body, 'targetBody' => $targetBody];
        }
        $kernel->terminate($request, $target);
    }
    $path = $root.'/tests/Fixtures/public-product-api/source-oracle.json';
    if ($failures === []) {
        file_put_contents($path, json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
    if ($failures !== []) {
        file_put_contents(sys_get_temp_dir().'/public-api-oracle-differences.json', json_encode($failures, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
    echo json_encode(['cases' => count($cases), 'passed' => count($cases) - count($failures), 'failed' => array_keys($failures), 'fixture' => $path], JSON_THROW_ON_ERROR)."\n";
} finally {
    CatalogTestDatabase::cleanup($storage);
}
exit($failures === [] ? 0 : 1);
