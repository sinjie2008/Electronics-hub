<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Laravel\Passport\Passport;
use Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Facades\Module;
use phpseclib4\Crypt\RSA;

beforeEach(function () {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Catalog parity requires MySQL.');
    }
    $this->seed([RolesAndPermissionsSeeder::class, CatalogDatabaseSeeder::class]);
    $this->catalogStorage = storage_path('framework/testing/catalog-parity-'.uniqid());
    config(['catalog.storage_root' => $this->catalogStorage, 'catalog.settings.logging.enabled' => false]);
    $this->actor = User::factory()->create();
    $this->actor->assignRole('Super Admin');
});

afterEach(function () {
    if (isset($this->catalogStorage)) {
        File::deleteDirectory($this->catalogStorage);
    }
    if (isset($this->catalogStatuses)) {
        File::delete($this->catalogStatuses, $this->catalogStatuses.'.lock');
    }
});

it('renders all six Catalog features as native Filament pages on MySQL', function (string $path, string $page, string $controlId) {
    expect(Module::findOrFail('Catalog')->isEnabled())->toBeTrue();
    $response = $this->actingAs($this->actor)->get($path);
    $response->assertOk()
        ->assertSee('data-catalog-page="'.$page.'"', false)
        ->assertSee('id="'.$controlId.'"', false)
        ->assertDontSee('<iframe', false)
        ->assertDontSee('<object', false)
        ->assertDontSee('<embed', false);
    expect($response->getContent())->not->toContain('sidebar-panel', 'Open Navigation');
})->with([
    'product catalog' => ['/admin/catalog', 'product-catalog', 'hierarchy-container'],
    'CSV import and export' => ['/admin/catalog/csv', 'csv', 'csv-import-form'],
    'specification search' => ['/admin/catalog/spec-search', 'spec-search', 'root-category-options'],
    'LaTeX templating' => ['/admin/catalog/latex-templating', 'latex-templating', 'templateForm'],
    'global Typst template' => ['/admin/catalog/global-typst-template', 'global-typst-template', 'compileBtn'],
    'series Typst template' => ['/admin/catalog/series-typst-template', 'series-typst-template', 'seriesDetailsContainer'],
]);

it('uses original compiled styles and browser scripts with versioned cache headers', function () {
    $path = module_path('Catalog', 'public/assets/css/catalog_ui.css');
    $hash = substr(hash_file('sha256', $path), 0, 16);
    $response = $this->actingAs($this->actor)->get('/catalog/assets/css/catalog_ui.css?v='.$hash);
    $response->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
    expect($response->headers->get('Cache-Control'))->toContain('immutable', 'max-age=31536000');
    $script = $this->get('/catalog/assets/js/catalog-request.js')->assertOk();
    expect(file_get_contents($script->baseResponse->getFile()->getPathname()))->toContain('X-CSRF-TOKEN');
    $this->get('/catalog/storage/csv/truncate_audit.jsonl')->assertNotFound();
});

it('requires Filament authentication for search and protects administrative operations', function () {
    $this->get('/catalog/spec-search.html')->assertRedirect('/admin/login');
    $this->getJson('/catalog/api/spec-search/root-categories.php')->assertUnauthorized()->assertJsonPath('error.code', 'UNAUTHENTICATED');
    $this->get('/catalog/catalog_ui.html')->assertRedirect('/admin/login');
    $this->postJson('/catalog/catalog.php?action=v1.saveNode', ['name' => 'blocked'])
        ->assertUnauthorized()->assertJsonPath('errorCode', 'UNAUTHENTICATED');
    $ordinary = User::factory()->create();
    $this->actingAs($ordinary)->postJson('/catalog/catalog.php?action=v1.saveNode', ['name' => 'blocked'])
        ->assertForbidden()->assertJsonPath('errorCode', 'FORBIDDEN');
    expect(DB::table('category')->where('name', 'blocked')->exists())->toBeFalse();
});

it('rejects inactive administrators and real method spoofing through a view-only session', function () {
    $inactive = User::factory()->create(['is_active' => false]);
    $inactive->assignRole('Super Admin');
    $this->actingAs($inactive)->postJson('/catalog/api/typst/templates.php', ['title' => 'inactive'])
        ->assertForbidden();
    $viewer = User::factory()->create();
    $viewer->givePermissionTo(['access.admin', 'catalog.view']);
    $this->actingAs($viewer)->post('/catalog/api/typst/variables.php', [
        '_method' => 'GET', 'key' => 'spoofed', 'value' => 'blocked',
    ])->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    expect(DB::table('typst_variables')->where('field_key', 'spoofed')->exists())->toBeFalse();
});

it('requires Passport for protected root API aliases and accepts the API actor', function () {
    $key = RSA::createKey(2048);
    config(['passport.private_key' => (string) $key, 'passport.public_key' => (string) $key->getPublicKey()]);
    $this->actingAs($this->actor)->postJson('/api/typst/templates.php', ['title' => 'session'])
        ->assertUnauthorized();
    Passport::actingAs($this->actor, [], 'api');
    $this->postJson('/api/typst/templates.php', ['title' => 'oauth', 'typst' => '= API'])
        ->assertSuccessful()->assertJsonPath('success', true);
    expect(DB::table('typst_templates')->where('title', 'oauth')->exists())->toBeTrue();
});

it('preserves JSON errors, method errors and supplied correlation ids across requests', function () {
    $this->actingAs($this->actor)->withHeader('X-Correlation-ID', 'catalog-parity-correlation')
        ->getJson('/catalog/catalog.php?action=v1.saveNode')
        ->assertStatus(405)->assertHeader('Allow', 'POST')
        ->assertJsonPath('message', 'Expected HTTP POST but received GET.')
        ->assertJsonPath('correlationId', 'catalog-parity-correlation');
    $this->call('POST', '/catalog/catalog.php?action=v1.saveNode', [], [], [],
        ['CONTENT_TYPE' => 'application/json'], '{broken')
        ->assertStatus(400)->assertJsonPath('errorCode', 'INVALID_JSON');
    $this->getJson('/catalog/catalog.php?action=unknown')
        ->assertStatus(404)->assertJsonPath('errorCode', 'ACTION_NOT_FOUND');
    $this->getJson('/catalog/catalog.php?action=v1.ping')
        ->assertOk()->assertJsonPath('data.message', 'Catalog backend ready.');
});

it('seeds the original catalog only when its migration marker is absent', function () {
    $this->actingAs($this->actor)->getJson('/catalog/catalog.php?action=v1.ping')->assertOk();
    expect(DB::table('category')->count())->toBe(5)
        ->and(DB::table('product')->count())->toBe(4)
        ->and(DB::table('product')->where('sku', 'C0-100')->value('name'))->toBe('Capacitor 100uF');
    $this->getJson('/catalog/catalog.php?action=v1.ping')->assertOk();
    expect(DB::table('category')->count())->toBe(5)
        ->and(DB::table('product')->count())->toBe(4)
        ->and(DB::table('seed_migration')->where('name', 'initial_catalog_v1')->count())->toBe(1);
});

it('rejects previously registered module routes after disabling Catalog', function () {
    $this->catalogStatuses = storage_path('framework/testing/catalog-statuses-'.uniqid().'.json');
    File::put($this->catalogStatuses, json_encode(['Core' => true, 'IAM' => true, 'System' => true, 'Catalog' => false]));
    config(['modules.activators.file.statuses-file' => $this->catalogStatuses]);
    app()->forgetInstance(ActivatorInterface::class);
    $this->actingAs($this->actor)->get('/catalog/catalog_ui.html')->assertNotFound();
    $this->postJson('/catalog/catalog.php?action=v1.saveNode', ['name' => 'disabled'])->assertNotFound();
    $this->getJson('/api/typst/templates.php')->assertNotFound();
    expect(DB::table('category')->count())->toBe(0);
});
