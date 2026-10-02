<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Filament\Panel;
use Illuminate\Support\Facades\File;
use Laravel\Passport\Passport;
use Livewire\Livewire;
use Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder;
use Modules\Catalog\Filament\CatalogPage;
use Modules\Catalog\Filament\CatalogPlugin;
use Modules\Catalog\Filament\Pages\CatalogCsv;
use Modules\Catalog\Filament\Pages\GlobalTypstTemplate;
use Modules\Catalog\Filament\Pages\LatexTemplating;
use Modules\Catalog\Filament\Pages\ProductCatalog;
use Modules\Catalog\Filament\Pages\SeriesTypstTemplate;
use Modules\Catalog\Filament\Pages\SpecSearch;
use Modules\System\Filament\Pages\ModuleManagement;
use Modules\System\Services\ModuleManager;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Facades\Module;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

beforeEach(function (): void {
    $this->withoutVite();
    $this->moduleStatuses = storage_path('framework/testing/catalog-filament-'.uniqid().'.json');
    File::ensureDirectoryExists(dirname($this->moduleStatuses));
    File::put($this->moduleStatuses, json_encode([
        'Core' => true,
        'IAM' => true,
        'System' => true,
        'Catalog' => true,
    ], JSON_THROW_ON_ERROR));
    config(['modules.activators.file.statuses-file' => $this->moduleStatuses]);
    app()->forgetInstance(ActivatorInterface::class);
});

afterEach(function (): void {
    if (isset($this->moduleStatuses)) {
        File::delete($this->moduleStatuses, $this->moduleStatuses.'.lock');
    }
});

it('shows all Catalog pages in the shared Filament navigation', function (): void {
    $actor = catalogFilamentUser($this);

    $response = $this->actingAs($actor, 'web')->get('/admin')->assertOk();

    foreach ([
        'Catalog',
        'Catalog UI',
        'CSV Import/Export',
        'Spec Search',
        'LaTeX Templating',
        'Global Typst Template',
        'Series Typst Template',
    ] as $label) {
        $response->assertSee($label);
    }

    expect($response->getContent())
        ->not->toContain('sidebar-panel', 'data-sidebar-toggle', 'sidebar-backdrop', 'sidebar-nav.js');
});

it('keeps Catalog page registration stable while disabled so cached routes can be enabled later', function (): void {
    $superAdmin = catalogFilamentUser($this, role: 'Super Admin');
    $this->actingAs($superAdmin, 'web');
    app(ModuleManager::class)->disable($superAdmin, 'Catalog');

    $panel = Panel::make()->id('disabled-catalog-cache-test');
    CatalogPlugin::make()->register($panel);

    expect($panel->getPages())->toContain(...array_values(CatalogPage::DOCUMENT_PAGES))
        ->and(ProductCatalog::canAccess())->toBeFalse();

    app(ModuleManager::class)->enable($superAdmin, 'Catalog');

    expect(ProductCatalog::canAccess())->toBeTrue();
});

it('renders each Catalog feature as a native Filament page', function (string $panelPath, string $page, string $controlId): void {
    $actor = catalogFilamentUser($this);

    $response = $this->actingAs($actor, 'web')->get($panelPath);

    $response->assertOk()
        ->assertSee('catalog-workspace', false)
        ->assertSee('data-catalog-page="'.$page.'"', false)
        ->assertSee('id="'.$controlId.'"', false)
        ->assertDontSee('<iframe', false)
        ->assertDontSee('<object', false)
        ->assertDontSee('<embed', false);

    expect(substr_count($response->getContent(), 'id="fi-main-sidebar"'))->toBe(1);
})->with([
    'product catalog' => ['/admin/catalog', 'product-catalog', 'hierarchy-container'],
    'CSV import and export' => ['/admin/catalog/csv', 'csv', 'csv-import-form'],
    'specification search' => ['/admin/catalog/spec-search', 'spec-search', 'root-category-options'],
    'LaTeX templating' => ['/admin/catalog/latex-templating', 'latex-templating', 'templateForm'],
    'global Typst template' => ['/admin/catalog/global-typst-template', 'global-typst-template', 'compileBtn'],
    'series Typst template' => ['/admin/catalog/series-typst-template', 'series-typst-template', 'seriesDetailsContainer'],
]);

it('redirects guests from native Catalog pages to the Filament login', function (string $path): void {
    $this->get($path)->assertRedirect('/admin/login');
})->with([
    'product catalog' => ['/admin/catalog'],
    'CSV import and export' => ['/admin/catalog/csv'],
    'specification search' => ['/admin/catalog/spec-search'],
    'LaTeX templating' => ['/admin/catalog/latex-templating'],
    'global Typst template' => ['/admin/catalog/global-typst-template'],
    'series Typst template' => ['/admin/catalog/series-typst-template'],
]);

it('redirects spoofed iframe requests for legacy documents to native Filament pages', function (string $document, string $panelPath): void {
    $actor = catalogFilamentUser($this);

    $this->actingAs($actor, 'web')
        ->withHeader('Sec-Fetch-Dest', 'iframe')
        ->get('/catalog/'.$document)
        ->assertRedirect($panelPath);
})->with([
    'product catalog' => ['catalog_ui.html', '/admin/catalog'],
    'CSV import and export' => ['catalog-csv.html', '/admin/catalog/csv'],
    'specification search' => ['spec-search.html', '/admin/catalog/spec-search'],
    'LaTeX templating' => ['latex-templating.html', '/admin/catalog/latex-templating'],
    'global Typst template' => ['global_typst_template.html', '/admin/catalog/global-typst-template'],
    'series Typst template' => ['series_typst_template.html', '/admin/catalog/series-typst-template'],
]);

it('redirects direct legacy document visits to the corresponding Filament page', function (string $document, string $panelPath): void {
    $actor = catalogFilamentUser($this);

    $this->actingAs($actor, 'web')
        ->withHeader('Sec-Fetch-Dest', 'document')
        ->get('/catalog/'.$document)
        ->assertRedirect($panelPath);
})->with([
    'product catalog' => ['catalog_ui.html', '/admin/catalog'],
    'CSV import and export' => ['catalog-csv.html', '/admin/catalog/csv'],
    'specification search' => ['spec-search.html', '/admin/catalog/spec-search'],
    'LaTeX templating' => ['latex-templating.html', '/admin/catalog/latex-templating'],
    'global Typst template' => ['global_typst_template.html', '/admin/catalog/global-typst-template'],
    'series Typst template' => ['series_typst_template.html', '/admin/catalog/series-typst-template'],
]);

it('preserves approved Catalog deep-link parameters in native pages and drops unrelated values', function (): void {
    $actor = catalogFilamentUser($this);
    $query = [
        'category' => 'native-category-91',
        'series' => 'native-series-92',
        'product' => 'native-product-93',
        'series_id' => '9821',
        'seriesId' => '9822',
        'unexpected' => 'discard-me',
    ];

    $response = $this->actingAs($actor, 'web')
        ->withHeader('Sec-Fetch-Dest', 'document')
        ->get('/catalog/catalog_ui.html?'.http_build_query($query))
        ->assertRedirect();

    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $forwardedQuery);

    expect(parse_url($location, PHP_URL_PATH))->toBe('/admin/catalog')
        ->and($forwardedQuery)->toBe([
            'category' => 'native-category-91',
            'series' => 'native-series-92',
            'product' => 'native-product-93',
            'series_id' => '9821',
            'seriesId' => '9822',
        ]);

    $nativeResponse = $this->get('/admin/catalog?'.http_build_query($query))->assertOk();
    foreach (['native-category-91', 'native-series-92', 'native-product-93', '9821', '9822'] as $value) {
        $nativeResponse->assertSee($value, false);
    }
    $nativeResponse->assertDontSee('discard-me');

    $seriesResponse = $this->withHeader('Sec-Fetch-Dest', 'document')
        ->get('/catalog/series_typst_template.html?series_id=42&seriesId=43&unexpected=discard-me')
        ->assertRedirect();
    $seriesLocation = (string) $seriesResponse->headers->get('Location');
    parse_str((string) parse_url($seriesLocation, PHP_URL_QUERY), $seriesQuery);

    expect(parse_url($seriesLocation, PHP_URL_PATH))->toBe('/admin/catalog/series-typst-template')
        ->and($seriesQuery)->toBe([
            'series_id' => '42',
            'seriesId' => '43',
        ]);
});

it('forbids native pages, iframe documents, and web APIs when either required permission is missing', function (array $permissions): void {
    $actor = catalogFilamentUser($this, $permissions);

    $this->actingAs($actor, 'web')->get('/admin/catalog')->assertForbidden();
    $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/catalog/catalog_ui.html')->assertForbidden();
    $this->getJson('/catalog/api/catalog/hierarchy.php')->assertForbidden();
})->with([
    'without admin access' => [['catalog.view']],
    'without catalog viewing' => [['access.admin']],
]);

it('requires an active verified administrator for Catalog pages and iframe or API access', function (): void {
    $inactive = catalogFilamentUser($this, ['access.admin', 'catalog.view'], ['is_active' => false]);

    $this->actingAs($inactive, 'web')->get('/admin/catalog')->assertForbidden();
    $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/catalog/catalog_ui.html')->assertForbidden();
    $this->getJson('/catalog/api/catalog/hierarchy.php')->assertForbidden();

    $unverified = catalogFilamentUser($this, ['access.admin', 'catalog.view'], unverified: true);

    $this->actingAs($unverified, 'web')
        ->get('/admin/catalog')
        ->assertRedirect('/admin/email-verification/prompt');
    $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/catalog/catalog_ui.html')->assertForbidden();
    $this->getJson('/catalog/api/catalog/hierarchy.php')->assertForbidden();
});

it('protects legacy public reads, search, media, assets, and storage behind Catalog viewing permission', function (string $path): void {
    $actor = catalogFilamentUser($this, ['access.admin']);

    $this->get($path)->assertUnauthorized();
    $this->actingAs($actor, 'web')->get($path)->assertForbidden();
})->with([
    'ping' => ['/catalog/catalog.php?action=v1.ping'],
    'public snapshot' => ['/catalog/catalog.php?action=v1.publicCatalogSnapshot'],
    'search API' => ['/catalog/api/spec-search/root-categories.php'],
    'hierarchy API' => ['/catalog/api/catalog/hierarchy.php'],
    'media download' => ['/catalog/catalog.php?action=v1.downloadMedia&id=private-file'],
    'public JavaScript asset' => ['/catalog/assets/js/catalog_ui.js'],
    'media storage' => ['/catalog/storage/media/private-file.png'],
]);

it('requires admin and Catalog viewing permissions on Passport root API routes', function (array $permissions): void {
    $apiUser = catalogFilamentUser($this, $permissions);
    Passport::actingAs($apiUser, [], 'api');

    $this->getJson('/api/catalog/hierarchy.php')->assertForbidden();
})->with([
    'without admin access' => [['catalog.view']],
    'without catalog viewing' => [['access.admin']],
]);

it('blocks and restores Catalog navigation, pages, iframe documents, and APIs through Module Management state', function (): void {
    $superAdmin = catalogFilamentUser($this, role: 'Super Admin');

    $this->actingAs($superAdmin, 'web')->get('/admin')->assertSee('Catalog UI');
    $this->get('/admin/catalog')->assertOk();
    $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/catalog/catalog_ui.html')->assertRedirect('/admin/catalog');
    $this->getJson('/catalog/catalog.php?action=v1.ping')->assertOk();
    $livewirePages = [];
    foreach ([
        ProductCatalog::class,
        CatalogCsv::class,
        SpecSearch::class,
        LatexTemplating::class,
        GlobalTypstTemplate::class,
        SeriesTypstTemplate::class,
    ] as $pageClass) {
        $livewirePages[] = Livewire::actingAs($superAdmin, 'web')->test($pageClass);
    }

    app(ModuleManager::class)->disable($superAdmin, 'Catalog');

    $this->get('/admin')->assertDontSee('Catalog UI');
    $this->get('/admin/catalog')->assertNotFound();
    foreach ($livewirePages as $livewirePage) {
        $livewirePage->call('$refresh')->assertForbidden();
    }
    $this->withHeader('Sec-Fetch-Dest', 'document')->get('/catalog/catalog_ui.html')->assertNotFound();
    $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/catalog/catalog_ui.html')->assertNotFound();
    $this->getJson('/catalog/catalog.php?action=v1.ping')->assertNotFound();

    app(ModuleManager::class)->enable($superAdmin, 'Catalog');

    $this->actingAs($superAdmin, 'web')->get('/admin')->assertSee('Catalog UI');
    $this->get('/admin/catalog')->assertOk();
    $this->withHeader('Sec-Fetch-Dest', 'iframe')->get('/catalog/catalog_ui.html')->assertRedirect('/admin/catalog');
    $this->getJson('/catalog/catalog.php?action=v1.ping')->assertOk();
    expect(Module::findOrFail('Catalog')->isEnabled())->toBeTrue();
});

it('redirects after a successful Module Management toggle so the panel refreshes its navigation', function (string $action, bool $initiallyEnabled, bool $expectedEnabled, string $auditEvent): void {
    $superAdmin = catalogFilamentUser($this, role: 'Super Admin');

    if (! $initiallyEnabled) {
        app(ModuleManager::class)->disable($superAdmin, 'Catalog');
    }

    Livewire::actingAs($superAdmin, 'web')
        ->test(ModuleManagement::class)
        ->call($action, 'Catalog')
        ->assertRedirect(ModuleManagement::getUrl());

    expect(Module::findOrFail('Catalog')->isEnabled())->toBe($expectedEnabled)
        ->and(Activity::query()->where('event', $auditEvent)->where('causer_id', $superAdmin->id)->count())->toBe(1);
})->with([
    'disable' => ['disableModule', true, false, 'module.disabled'],
    'enable' => ['enableModule', false, true, 'module.enabled'],
]);

function catalogFilamentUser(TestCase $test, array $permissions = ['access.admin', 'catalog.view'], array $attributes = [], bool $unverified = false, ?string $role = null): User
{
    $test->seed([RolesAndPermissionsSeeder::class, CatalogDatabaseSeeder::class]);

    $factory = User::factory();
    if ($unverified) {
        $factory = $factory->unverified();
    }
    $user = $factory->create($attributes);

    foreach ($permissions as $permission) {
        $user->givePermissionTo($permission);
    }
    if ($role !== null) {
        $user->assignRole($role);
    }

    return $user;
}
