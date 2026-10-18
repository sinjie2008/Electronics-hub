<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use phpseclib4\Crypt\RSA;
use Symfony\Component\Process\Process;
use Tests\Support\CatalogTestDatabase;
use Tests\Support\PublicProductApiFixture;

it('serves the ten public APIs and enforces real browser CSRF and Passport writes over HTTP', function () {
    $storage = CatalogTestDatabase::prepare();
    $directory = storage_path('framework/testing/catalog-http-'.bin2hex(random_bytes(8)));
    File::ensureDirectoryExists($directory);
    $statuses = $directory.'/modules.json';
    File::put($statuses, json_encode(['Core' => true, 'IAM' => true, 'System' => true, 'Catalog' => true]));
    PublicProductApiFixture::seed();
    $this->seed();
    $password = bin2hex(random_bytes(16)).'aA!7';
    $actor = User::factory()->create(['password' => $password]);
    $actor->givePermissionTo('catalog.view', 'catalog.create');
    $originalKeyPath = Passport::$keyPath;
    $key = RSA::createKey(2048);
    File::put($directory.'/oauth-private.key', (string) $key);
    File::put($directory.'/oauth-public.key', (string) $key->getPublicKey());
    chmod($directory.'/oauth-private.key', 0600);
    chmod($directory.'/oauth-public.key', 0600);
    Passport::loadKeysFrom($directory);
    app(ClientRepository::class)->createPersonalAccessGrantClient('HTTP verification');
    $bearer = $actor->createToken('HTTP verification')->accessToken;
    $listener = stream_socket_server('tcp://127.0.0.1:0');
    if ($listener === false) {
        throw new RuntimeException('Unable to reserve a local HTTP test port.');
    }
    $address = stream_socket_get_name($listener, false);
    fclose($listener);
    $router = $directory.'/router.php';
    File::put($router, '<?php require '.var_export(base_path('vendor/autoload.php'), true).'; '
        .'\\Laravel\\Passport\\Passport::loadKeysFrom('.var_export($directory, true).'); require '
        .var_export(base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php'), true).';');
    $server = new Process([PHP_BINARY, '-S', $address, $router], public_path(), [
        'APP_ENV' => 'testing', 'APP_DEBUG' => 'false', 'APP_URL' => 'http://'.$address,
        'APP_CONFIG_CACHE' => $directory.'/config.php', 'APP_ROUTES_CACHE' => $directory.'/routes.php',
        'MODULE_STATUSES_PATH' => $statuses, 'CATALOG_STORAGE_ROOT' => $storage,
        'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => config('database.connections.mysql.database'),
        'CATALOG_DB_DATABASE' => config('database.connections.catalog.database'),
        'SESSION_DRIVER' => 'redis', 'CACHE_STORE' => 'redis',
    ]);
    $server->setTimeout(60);
    $cookies = $directory.'/cookies';
    File::put($cookies, '');
    chmod($cookies, 0600);
    $request = static function (string $method, string $path, array|string|null $payload = null, array $headers = []) use ($address, $cookies): array {
        $curl = curl_init('http://'.$address.$path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_COOKIEFILE => $cookies, CURLOPT_COOKIEJAR => $cookies, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => $headers,
        ]);
        if ($payload !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, is_array($payload) ? json_encode($payload) : $payload);
        }
        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        curl_close($curl);

        return ['status' => $status, 'body' => $body === false ? '' : $body];
    };
    try {
        $server->start();
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if ($request('GET', '/api/v1/health')['status'] === 200) {
                break;
            }
            usleep(50000);
        }
        $series = '/api/series/'.PublicProductApiFixture::SERIES_PATH;
        foreach (['/api/', '/api/health', '/api/tree', '/api/resolve/'.PublicProductApiFixture::SERIES_PATH,
            '/api/categories/electronics', $series, $series.'/fields', $series.'/parts', $series.'/facets', '/api/search?q=Alpha'] as $uri) {
            $response = $request('GET', $uri);
            expect($response['status'])->toBe(200);
            expect(json_decode($response['body'], true)['success'])->toBeTrue();
        }
        expect($request('POST', '/catalog.php?action=v1.saveNode', ['name' => 'Denied'], ['Content-Type: application/json'])['status'])->toBe(401);
        $created = $request('POST', '/catalog.php?action=v1.saveNode', ['name' => 'Real HTTP write', 'type' => 'category'], ['Content-Type: application/json', 'Authorization: Bearer '.$bearer]);
        expect($created['status'])->toBe(200)
            ->and(DB::connection('catalog')->table('category')->where('name', 'Real HTTP write')->exists())->toBeTrue();

        $login = $request('GET', '/login');
        expect(preg_match('/name="_token" value="([^"]+)"/', $login['body'], $token))->toBe(1);
        $signedIn = $request('POST', '/login', http_build_query(['email' => $actor->email, 'password' => $password, '_token' => $token[1]]), ['Content-Type: application/x-www-form-urlencoded']);
        expect($signedIn['status'])->toBe(302);
        $page = $request('GET', '/catalog/spec-search.html');
        expect($page['status'])->toBe(200)
            ->and(preg_match('/name="csrf-token" content="([^"]+)"/', $page['body'], $csrf))->toBe(1);
        expect($request('POST', '/catalog/api/spec-search/facets.php', ['category_ids' => []], ['Content-Type: application/json'])['status'])->toBe(419);
        expect($request('POST', '/catalog/api/spec-search/facets.php', ['category_ids' => []], ['Content-Type: application/json', 'X-CSRF-TOKEN: '.$csrf[1]])['status'])->toBe(200);
    } finally {
        $server->stop(0);
        Passport::$keyPath = $originalKeyPath;
        CatalogTestDatabase::cleanup($storage);
        File::deleteDirectory($directory);
    }
});
