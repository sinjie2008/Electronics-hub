<?php

use App\Models\User;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;
use Modules\Catalog\Http\CatalogApiException;
use Modules\Catalog\Http\ModuleRoutes;
use Modules\Catalog\Services\MediaStorageService;
use Modules\IAM\Models\Role;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Tests\Support\CatalogTestDatabase;
use Tests\Support\PublicProductApiFixture;

beforeEach(function () {
    $this->catalogStorageRoot = CatalogTestDatabase::prepare();
    File::ensureDirectoryExists($this->catalogStorageRoot);
    $this->seed();
});

afterEach(function () {
    CatalogTestDatabase::cleanup($this->catalogStorageRoot ?? null);
});

it('denies anonymous access through every legacy alias, page and private storage kind without mutations', function () {
    $connection = DB::connection('catalog');
    $before = $connection->table('category')->count();
    foreach (['', '/catalog'] as $mount) {
        foreach (ModuleRoutes::ENDPOINTS as $endpoint => $handler) {
            $this->postJson($mount.'/'.$endpoint, ['name' => 'Denied write'])
                ->assertUnauthorized()->assertJsonPath('errorCode', 'UNAUTHENTICATED');
        }
        foreach (['root-categories', 'product-categories', 'facets', 'products'] as $endpoint) {
            foreach (['', '/index.php'] as $suffix) {
                $this->getJson($mount.'/api/spec-search/'.$endpoint.$suffix)->assertUnauthorized();
            }
        }
        foreach (ModuleRoutes::PAGES as $page) {
            $this->get($mount.'/'.$page)->assertUnauthorized();
        }
        foreach (['media', 'latex-pdfs', 'typst-pdfs', 'typst-assets'] as $kind) {
            $this->get($mount.'/storage/'.$kind.'/private.pdf')->assertUnauthorized();
        }
    }
    expect($connection->table('category')->count())->toBe($before)
        ->and(File::allFiles($this->catalogStorageRoot))->toBeEmpty();
});

it('denies each privileged legacy action for a view-only actor before side effects', function (string $action, string $method) {
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view');

    $this->actingAs($actor, 'web')->json($method, '/catalog.php?action='.$action, ['name' => 'Denied'])
        ->assertForbidden()->assertJsonPath('errorCode', 'FORBIDDEN');

    expect(DB::connection('catalog')->table('category')->count())->toBe(0)
        ->and(File::allFiles($this->catalogStorageRoot))->toBeEmpty();
})->with([
    ['v1.saveNode', 'POST'], ['v1.deleteNode', 'DELETE'], ['v1.saveProduct', 'POST'], ['v1.deleteProduct', 'DELETE'],
    ['v1.saveSeriesField', 'POST'], ['v1.deleteSeriesField', 'DELETE'], ['v1.saveSeriesAttributes', 'POST'],
    ['v1.setSeriesTypstTemplating', 'POST'], ['v1.createLatexTemplate', 'POST'], ['v1.updateLatexTemplate', 'PUT'],
    ['v1.deleteLatexTemplate', 'DELETE'], ['v1.buildLatexTemplate', 'POST'], ['v1.importCsv', 'POST'],
    ['v1.restoreCsv', 'POST'], ['v1.truncateCatalog', 'POST'], ['v1.deleteCsv', 'POST'], ['v1.exportCsv', 'POST'],
]);

it('denies template, CSV and compiler operations for a view-only actor', function (string $uri, string $method) {
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view');

    $this->actingAs($actor, 'web')->json($method, $uri, ['typst' => '= denied', 'latex' => 'denied'])
        ->assertForbidden();

    expect(File::allFiles($this->catalogStorageRoot))->toBeEmpty();
})->with([
    ['/api/typst/compile.php', 'POST'], ['/api/latex/compile.php', 'POST'],
    ['/catalog/api/catalog/csv-import.php', 'POST'], ['/api/catalog/csv-restore.php', 'POST'],
    ['/api/catalog/csv-export.php', 'POST'], ['/api/catalog/truncate.php', 'POST'], ['/api/catalog/pdf.php', 'POST'],
    ['/legacy/api/typst/templates.php', 'POST'], ['/catalog/api/typst/templates.php', 'PUT'],
    ['/api/typst/variables.php', 'DELETE'], ['/api/typst/series-preferences.php', 'PUT'],
    ['/api/latex/templates.php', 'POST'], ['/api/latex/variables.php', 'DELETE'],
]);

it('requires update permission when positive IDs are supplied through JSON or multipart metadata', function (bool $multipart) {
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view', 'catalog.create');
    $this->actingAs($actor, 'web');
    $payload = ['id' => 123, 'name' => 'No update', 'type' => 'category'];

    if ($multipart) {
        $this->post('/catalog.php?action=v1.saveProduct', ['metadata' => json_encode($payload)], ['Content-Type' => 'multipart/form-data'])
            ->assertForbidden();
    } else {
        $this->postJson('/catalog.php?action=v1.saveNode', $payload)->assertForbidden();
    }
})->with([false, true]);

it('authenticates Passport user tokens and requires application permissions independently of OAuth scopes', function () {
    $actor = User::factory()->create();
    Passport::actingAs($actor, ['*'], 'api');
    $this->withToken('passport-test-actor');
    $this->postJson('/catalog.php?action=v1.saveNode', ['name' => 'Token node', 'type' => 'category'])->assertForbidden();

    $actor->givePermissionTo('catalog.view', 'catalog.create');
    $this->postJson('/catalog.php?action=v1.saveNode', ['name' => 'Token node', 'type' => 'category'])
        ->assertOk()->assertJsonPath('data.name', 'Token node');
    expect(DB::connection('catalog')->table('category')->where('name', 'Token node')->exists())->toBeTrue();
});

it('rejects invalid credentials even when a valid session exists and denies inactive administrators', function () {
    $actor = User::factory()->create();
    $actor->assignRole(Role::SUPER_ADMIN);
    $this->actingAs($actor, 'web')->withToken('invalid-catalog-token')->getJson('/catalog.php?action=v1.listHierarchy')
        ->assertUnauthorized();
    $this->withoutToken();
    $actor->forceFill(['is_active' => false])->save();
    $this->getJson('/catalog.php?action=v1.listHierarchy')->assertForbidden();
});

it('rejects expired real Passport tokens without falling back to browser authentication', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view');
    app(ClientRepository::class)->createPersonalAccessGrantClient('Catalog integration');
    Passport::personalAccessTokensExpireIn(now()->subMinute());
    try {
        $token = $actor->createToken('Expired catalog token')->accessToken;
    } finally {
        Passport::personalAccessTokensExpireIn(now()->addDays(30));
    }
    Auth::forgetGuards();

    $this->withToken($token)->getJson('/catalog.php?action=v1.listHierarchy')->assertUnauthorized();
});

it('enforces real CSRF validation for session writes and accepts matching tokens', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view', 'catalog.create');
    $this->actingAs($actor, 'web');
    $this->app->instance('env', 'production');
    try {
        $this->postJson('/catalog.php?action=v1.saveNode', ['name' => 'CSRF denied', 'type' => 'category'])->assertStatus(419);
        expect(DB::connection('catalog')->table('category')->count())->toBe(0);
        $this->withSession(['_token' => 'catalog-csrf-token'])->withHeader('X-CSRF-TOKEN', 'catalog-csrf-token')
            ->postJson('/catalog.php?action=v1.saveNode', ['name' => 'CSRF accepted', 'type' => 'category'])
            ->assertOk()->assertJsonPath('data.name', 'CSRF accepted');
    } finally {
        $this->app->instance('env', 'testing');
    }
});

it('rejects state-changing file endpoints reached through GET despite administrator privileges', function (string $endpoint) {
    $actor = User::factory()->create();
    $actor->assignRole(Role::SUPER_ADMIN);

    $this->actingAs($actor, 'web')->getJson('/api/catalog/'.$endpoint.'.php')->assertMethodNotAllowed()->assertHeader('Allow', 'POST');
})->with(['csv-export', 'csv-import', 'csv-restore', 'truncate', 'pdf']);

it('applies a bounded public throttle and ignores spoofed forwarding headers', function () {
    config(['catalog.public_api.requests_per_minute' => 2]);
    RateLimiter::clear('catalog-public:198.51.100.99');
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99']);
    $this->getJson('/api/health')->assertOk();
    $this->withHeader('X-Forwarded-For', '198.51.100.1')->getJson('/api/health')->assertOk();
    $this->withHeader('X-Forwarded-For', '198.51.100.2')->getJson('/api/health')
        ->assertTooManyRequests()->assertJsonPath('errorCode', 'TOO_MANY_REQUESTS')->assertHeader('Retry-After');
});

it('uses an exact public CORS origin allowlist without credentialed or wildcard responses', function () {
    config(['catalog.public_api.allowed_origins' => ['https://catalog-consumer.example']]);
    $this->withHeader('Origin', 'https://untrusted.example')->getJson('/api/health')->assertOk()->assertHeaderMissing('Access-Control-Allow-Origin');
    $this->withHeader('Origin', 'https://catalog-consumer.example')->getJson('/api/health')
        ->assertOk()->assertHeader('Access-Control-Allow-Origin', 'https://catalog-consumer.example')->assertHeaderMissing('Access-Control-Allow-Credentials');
});

it('keeps private media behind permissions and prevents path-prefix traversal and active uploads', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view');
    File::ensureDirectoryExists($this->catalogStorageRoot.'/media');
    File::put($this->catalogStorageRoot.'/media/private.pdf', "%PDF-1.4\nprivate fixture");
    $this->get('/storage/media/private.pdf')->assertUnauthorized();
    $this->actingAs($actor, 'web')->get('/storage/media/private.pdf')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->get('/storage/media/%2e%2e/%2e%2e/.env')->assertNotFound();

    $service = app(MediaStorageService::class);
    foreach (['../media-backup/secret.pdf', '/etc/passwd', "bad\0file", 'a/../../.env'] as $path) {
        expect(fn () => $service->deleteFile($path))->toThrow(CatalogApiException::class, 'Invalid media path.');
    }
    $file = $this->catalogStorageRoot.'/active.glb';
    File::put($file, '<svg onload="alert(1)"></svg>');
    expect(fn () => $service->saveSeriesFile(1, 1, 'file', ['tmp_name' => $file, 'name' => 'active.glb', 'size' => filesize($file), 'error' => UPLOAD_ERR_OK]))
        ->toThrow(CatalogApiException::class, 'Unsupported file type');
});

it('does not reveal SQL, filesystem paths or stack traces in legacy failures', function () {
    $actor = User::factory()->create();
    $actor->assignRole(Role::SUPER_ADMIN);
    $this->actingAs($actor, 'web');
    $response = $this->postJson('/api/typst/variables.php', ['key' => str_repeat('x', 1000), 'type' => 'text', 'value' => 'safe']);
    $response->assertStatus(500)->assertJsonPath('error.message', 'The Catalog operation could not be completed.');
    expect($response->getContent())->not->toContain(base_path(), 'trace', 'SQLSTATE');
});

it('matches raw JSON save readers regardless of the declared Content-Type', function (string $action, array $payload, string $table, int $id, string $column, string $original) {
    PublicProductApiFixture::seed();
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view', 'catalog.create');
    $this->actingAs($actor, 'web');

    $this->call('POST', '/catalog.php?action='.$action, [], [], [], ['CONTENT_TYPE' => 'text/plain'], json_encode($payload))
        ->assertForbidden();

    expect(DB::connection('catalog')->table($table)->where('id', $id)->value($column))->toBe($original);
})->with([
    ['v1.saveNode', ['id' => 100, 'name' => 'Unauthorized update', 'type' => 'category'], 'category', 100, 'name', 'Electronics'],
    ['v1.saveProduct', ['id' => 2001, 'seriesId' => 110, 'sku' => 'SW-10', 'name' => 'Unauthorized update'], 'product', 2001, 'name', 'Alpha'],
    ['v1.saveSeriesField', ['id' => 1001, 'seriesId' => 110, 'fieldKey' => 'voltage', 'fieldType' => 'number', 'label' => 'Unauthorized update'], 'series_custom_field', 1001, 'label', 'Voltage'],
]);

it('denies multipart variable updates when only create permission is present', function () {
    $id = DB::connection('catalog')->table('typst_variables')->insertGetId([
        'field_key' => 'existing', 'field_type' => 'text', 'field_value' => 'Original', 'is_global' => 1,
    ]);
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view', 'catalog.create');

    $this->actingAs($actor, 'web')->post('/api/typst/variables.php', [
        'id' => $id, 'key' => 'existing', 'type' => 'text', 'value' => 'Unauthorized update',
    ], ['Content-Type' => 'multipart/form-data'])->assertForbidden();

    expect(DB::connection('catalog')->table('typst_variables')->where('id', $id)->value('field_value'))->toBe('Original');
});

it('requires create permission for template POST even when an ignored ID is included', function (string $kind) {
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view', 'catalog.update');

    $this->actingAs($actor, 'web')->postJson('/api/'.$kind.'/templates.php', [
        'id' => 999, 'title' => 'Unauthorized create', 'description' => 'Denied', 'typst' => '= Denied', 'latex' => 'Denied',
    ])->assertForbidden();

    expect(DB::connection('catalog')->table($kind.'_templates')->count())->toBe(0);
})->with(['typst', 'latex']);

it('renders CSRF-enabled Catalog pages and permits the authenticated spec-search POST workflow', function () {
    $actor = User::factory()->create();
    $actor->givePermissionTo('catalog.view');
    $this->actingAs($actor, 'web')->withSession(['_token' => 'catalog-page-token']);
    foreach (ModuleRoutes::PAGES as $page) {
        $this->get('/catalog/'.$page)->assertSee('<meta name="csrf-token" content="catalog-page-token">', false)
            ->assertSee('assets/js/catalog-auth.js', false);
    }
    $this->app->instance('env', 'production');
    try {
        $this->withHeader('X-CSRF-TOKEN', 'catalog-page-token')
            ->postJson('/catalog/api/spec-search/facets.php', ['category_ids' => []])->assertOk();
    } finally {
        $this->app->instance('env', 'testing');
    }
});

it('does not claim host assets or pages at compatibility mounts that are turned off', function () {
    $statuses = storage_path('framework/testing/host-isolation-status.json');
    File::put($statuses, json_encode(['Core' => true, 'IAM' => true, 'System' => true, 'Catalog' => false]));
    config(['catalog.legacy_urls' => false, 'catalog.project_root_urls' => false, 'modules.activators.file.statuses-file' => $statuses]);
    app()->forgetInstance(ActivatorInterface::class);
    $router = app('router');
    $routes = $router->getRoutes();
    $router->setRoutes(new RouteCollection);
    foreach (['/assets/host.txt', '/public/assets/host.txt', '/catalog_ui.html'] as $path) {
        $router->get($path, fn () => response('host remains available'));
    }
    try {
        foreach (['/assets/host.txt', '/public/assets/host.txt', '/catalog_ui.html'] as $path) {
            $this->get($path)->assertOk()->assertSee('host remains available');
        }
        $this->get('/catalog/catalog_ui.html')->assertNotFound();
    } finally {
        $router->setRoutes($routes);
        File::delete($statuses, $statuses.'.lock');
    }
});
