<?php

use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Modules\Catalog\Database\Seeders\CatalogPermissionsSeeder;
use Modules\Catalog\Models\CatalogNode;
use Modules\Catalog\Policies\CatalogPolicy;
use Modules\Catalog\Services\CatalogAdminService;
use Modules\Catalog\Services\CatalogCsvService;
use Modules\IAM\Models\Permission;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Symfony\Component\Process\Process;
use Tests\Support\CatalogTestDatabase;

beforeEach(function () {
    $this->catalogStorageRoot = CatalogTestDatabase::prepare();
    $this->catalogOriginalStatusesPath = config('modules.activators.file.statuses-file');
    $this->catalogStatusesPath = storage_path('framework/testing/catalog-status-'.bin2hex(random_bytes(8)).'.json');
    File::ensureDirectoryExists(dirname($this->catalogStatusesPath));
    File::put($this->catalogStatusesPath, json_encode([
        'Core' => true,
        'IAM' => true,
        'System' => true,
        'Catalog' => true,
    ], JSON_THROW_ON_ERROR));
    config(['modules.activators.file.statuses-file' => $this->catalogStatusesPath]);
    app()->forgetInstance(ActivatorInterface::class);
});

afterEach(function () {
    CatalogTestDatabase::cleanup($this->catalogStorageRoot ?? null);

    if (isset($this->catalogStatusesPath)) {
        File::delete($this->catalogStatusesPath, $this->catalogStatusesPath.'.lock');
    }
    if (isset($this->catalogOriginalStatusesPath)) {
        config(['modules.activators.file.statuses-file' => $this->catalogOriginalStatusesPath]);
        app()->forgetInstance(ActivatorInterface::class);
    }
});

it('discovers Catalog as an enabled module with its own named database and routes', function () {
    expect(app('modules')->isEnabled('Catalog'))->toBeTrue()
        ->and(config('catalog.connection'))->toBe('catalog')
        ->and(config('database.connections.catalog.driver'))->toBeIn(['mysql', 'mariadb'])
        ->and(config('database.connections.catalog.prefix'))->toBe('')
        ->and(app()->bound('catalog.connection'))->toBeTrue()
        ->and(Route::has('catalog.catalog-php'))->toBeTrue()
        ->and(Route::has('catalog.catalog_ui'))->toBeTrue()
        ->and(Route::has('catalog.compat.catalog-php'))->toBeTrue()
        ->and(Route::has('catalog.compat.catalog_ui'))->toBeTrue();
});

it('registers Catalog permissions through the host seeder and enforces them before admin writes', function () {
    config(['enterprise.initial_admin' => ['name' => null, 'email' => null, 'password' => null]]);
    app(DatabaseSeeder::class)->run();

    $actor = User::factory()->create();
    $admin = app(CatalogAdminService::class);
    expect(Gate::getPolicyFor(CatalogNode::class))->toBeInstanceOf(CatalogPolicy::class)
        ->and(Permission::query()->whereIn('name', CatalogPermissionsSeeder::PERMISSIONS)
            ->where('is_system', true)->count())->toBe(count(CatalogPermissionsSeeder::PERMISSIONS))
        ->and(Gate::forUser($actor)->allows('create', CatalogNode::class))->toBeFalse();

    try {
        $admin->saveNode($actor, [
            'name' => 'Denied Catalog Node',
            'type' => 'category',
            'display_order' => 1,
        ]);
        test()->fail('Catalog writes without the view and create permissions must be denied.');
    } catch (AuthorizationException) {
        expect(DB::connection('catalog')->table('category')->where('name', 'Denied Catalog Node')->exists())
            ->toBeFalse();
    }

    $actor->givePermissionTo('catalog.view', 'catalog.create');
    $node = $admin->saveNode($actor, [
        'name' => 'Authorized Catalog Node',
        'type' => 'category',
        'display_order' => 1,
    ]);

    expect($node->exists)->toBeTrue()
        ->and(DB::connection('catalog')->table('category')->where('id', $node->getKey())->exists())
        ->toBeTrue();
});

it('rejects unsafe Catalog migration connections and disabled module migration in a fresh process', function () {
    expect(fn () => Artisan::call('module:migrate', [
        'module' => ['Catalog'], '--database' => config('database.default'), '--force' => true,
    ]))->toThrow(RuntimeException::class, 'must match catalog.connection');
    expect(fn () => Artisan::call('module:migrate', [
        'module' => ['Core'], '--database' => 'catalog', '--force' => true,
    ]))->toThrow(RuntimeException::class, 'reserved for Catalog module migrations');

    File::put($this->catalogStatusesPath, json_encode([
        'Core' => true, 'IAM' => true, 'System' => true, 'Catalog' => false,
    ], JSON_THROW_ON_ERROR));
    $process = new Process([
        PHP_BINARY, 'artisan', 'module:migrate', 'Catalog', '--database=catalog', '--force', '--no-ansi',
    ], base_path(), ['MODULE_STATUSES_PATH' => $this->catalogStatusesPath]);
    $process->setTimeout(45);
    $process->run();

    expect($process->isSuccessful())->toBeFalse()
        ->and($process->getOutput().$process->getErrorOutput())->toContain('Enable Catalog before running its migrations.');
});

it('saves hierarchy, scoped fields and products that appear in list and specification search APIs', function () {
    $saveNode = fn (array $payload) => $this->postJson(
        '/catalog/catalog.php?action=v1.saveNode',
        $payload
    )->assertOk()->assertJsonPath('success', true)->json('data');

    $rootPayload = ['name' => 'Integration Catalog', 'type' => 'category', 'displayOrder' => 1];
    $rootResponse = $this->withHeader('X-Correlation-ID', 'catalog-integration-123')
        ->postJson('/catalog.php?action=v1.saveNode', $rootPayload)
        ->assertOk()
        ->assertJsonPath('correlationId', 'catalog-integration-123')
        ->assertHeader('X-Correlation-ID', 'catalog-integration-123');
    $root = $rootResponse->json('data');
    $group = $saveNode([
        'name' => 'Power Components',
        'type' => 'category',
        'parentId' => $root['id'],
        'displayOrder' => 1,
    ]);
    $leaf = $saveNode([
        'name' => 'Inductors',
        'type' => 'category',
        'parentId' => $group['id'],
        'displayOrder' => 1,
    ]);
    $series = $saveNode([
        'name' => 'Shielded Series',
        'type' => 'series',
        'parentId' => $leaf['id'],
        'displayOrder' => 1,
    ]);

    $field = $this->postJson('/catalog/catalog.php?action=v1.saveSeriesField', [
        'seriesId' => $series['id'],
        'fieldKey' => 'inductance',
        'label' => 'Inductance',
        'fieldType' => 'text',
        'fieldScope' => 'product_attribute',
        'sortOrder' => 1,
    ])->assertOk()->assertJsonPath('data.fieldScope', 'product_attribute')->json('data');

    $product = $this->postJson('/catalog/catalog.php?action=v1.saveProduct', [
        'seriesId' => $series['id'],
        'sku' => 'EH-IND-001',
        'name' => 'Shielded Power Inductor',
        'description' => 'Integration fixture',
        'custom_field_values' => ['inductance' => '10 µH'],
    ])->assertOk()->assertJsonPath('data.sku', 'EH-IND-001')->json('data');

    expect(DB::connection('catalog')->table('product')->where('id', $product['id'])->value('name'))
        ->toBe('Shielded Power Inductor')
        ->and(DB::connection('catalog')->table('product_custom_field_value')
            ->where('product_id', $product['id'])->where('series_custom_field_id', $field['id'])->value('value'))
        ->toBe('10 µH');

    $this->getJson('/catalog/catalog.php?action=v1.listProducts&seriesId='.$series['id'])
        ->assertOk()
        ->assertJsonPath('data.0.customValues.inductance', '10 µH');

    $this->getJson('/catalog/api/spec-search/root-categories.php')
        ->assertOk()
        ->assertJsonPath('data.categories.0.name', 'Integration Catalog');
    $this->getJson('/catalog/api/spec-search/product-categories.php?root_id='.$root['id'])
        ->assertOk()
        ->assertJsonPath('data.groups.0.categories.0.id', $leaf['id']);
    $this->postJson('/catalog/api/spec-search/facets.php', ['category_ids' => [$leaf['id']]])
        ->assertOk()
        ->assertJsonPath('data.facets.0.key', 'series')
        ->assertJsonPath('data.facets.1.key', 'inductance');
    $this->postJson('/catalog/api/spec-search/products.php', [
        'category_ids' => [$leaf['id']],
        'filters' => ['inductance' => ['10 µH']],
    ])->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.sku', 'EH-IND-001');
});

it('renders all six legacy pages and serves their versioned module assets', function () {
    $pages = [
        ['catalog_ui.html', 'catalog_ui.js'],
        ['catalog-csv.html', 'catalog_csv.js'],
        ['spec-search.html', 'spec_search.js'],
        ['latex-templating.html', 'latex-templating.js'],
        ['global_typst_template.html', 'global-typst-templating.js'],
        ['series_typst_template.html?series_id=17', 'series-typst-templating.js'],
    ];

    foreach ($pages as [$page, $script]) {
        $response = $this->get('/catalog/'.$page)->assertOk();
        $response->assertSee('assets/js/'.$script.'?v=');
        $this->get('/catalog/assets/js/'.$script)->assertOk();

        $this->get('/'.$page)->assertOk();
        $this->get('/assets/js/'.$script)->assertOk();
    }

    $assetPath = base_path('Modules/Catalog/public/assets/css/catalog_ui.css');
    $version = substr(hash_file('sha256', $assetPath), 0, 16);
    $this->get('/catalog/assets/css/catalog_ui.css?v='.$version)
        ->assertOk()
        ->assertHeader('Cache-Control', 'immutable, max-age=31536000, public');
    $this->get('/catalog/assets/css/catalog_ui.css')
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-cache, private');
});

it('imports, exports, restores and lists CSV files through the Catalog workflows', function () {
    $fixtureDirectory = $this->catalogStorageRoot.'/fixtures';
    File::ensureDirectoryExists($fixtureDirectory);
    $fixturePath = $fixtureDirectory.'/catalog-import.csv';
    File::put($fixturePath, "category_path,product_name,inductance\nPower Components > Inductors > Shielded Series,EH-CSV-001,22 µH\n");

    $import = app(CatalogCsvService::class)
        ->importFromPath($fixturePath, 'catalog-import.csv');

    expect($import['importedProducts'])->toBe(1)
        ->and(DB::connection('catalog')->table('product')->where('sku', 'EH-CSV-001')->exists())->toBeTrue();

    $export = $this->postJson('/catalog/api/catalog/csv-export.php')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->json('data');

    $this->postJson('/catalog/api/catalog/csv-restore.php', ['id' => $import['fileId']])
        ->assertOk()
        ->assertJsonPath('data.importedProducts', 1);

    $history = $this->getJson('/catalog/api/catalog/csv-history.php')->assertOk()->json('data.files');
    expect(collect($history)->pluck('id')->all())
        ->toContain($import['fileId'], $export['id']);

    $download = $this->get('/catalog/api/catalog/csv-download.php?id='.rawurlencode($export['id']))
        ->assertOk();
    expect($download->streamedContent())
        ->toContain('category_path', 'EH-CSV-001', '22 µH');
});

it('round trips Typst file API templates and reports its JSON envelope', function () {
    $created = $this->postJson('/catalog/api/typst/templates.php', [
        'title' => 'Global Datasheet',
        'description' => 'Integration template',
        'typst' => '#set page(width: 100mm)',
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertHeader('Content-Type', 'application/json; charset=utf-8')
        ->json('data');

    $this->putJson('/catalog/api/typst/templates.php', [
        'id' => $created['id'],
        'title' => 'Updated Datasheet',
        'description' => 'Updated integration template',
        'typst' => '#set page(width: 120mm)',
    ])->assertOk()->assertJsonPath('data.typst', '#set page(width: 120mm)');

    $this->getJson('/catalog/api/typst/templates.php?id='.$created['id'])
        ->assertOk()
        ->assertJsonPath('data.title', 'Updated Datasheet');
    $this->deleteJson('/catalog/api/typst/templates.php?id='.$created['id'])
        ->assertOk()
        ->assertJsonPath('success', true);
    $this->getJson('/catalog/api/typst/templates.php?id='.$created['id'])
        ->assertOk()
        ->assertJsonPath('data', null);
});

it('round trips LaTeX file API templates and variables', function () {
    $template = $this->postJson('/catalog/api/latex/templates.php', [
        'title' => 'LaTeX Datasheet',
        'description' => 'Integration template',
        'latex' => '\\documentclass{article}',
    ])->assertCreated()
        ->assertJsonPath('success', true)
        ->json('data');

    $this->putJson('/catalog/api/latex/templates.php', [
        'id' => $template['id'],
        'title' => 'Updated LaTeX Datasheet',
        'description' => 'Updated description',
        'latex' => '\\documentclass{report}',
    ])->assertOk()->assertJsonPath('data.latex', '\\documentclass{report}');
    $this->getJson('/catalog/api/latex/templates.php')
        ->assertOk()
        ->assertJsonPath('data.0.title', 'Updated LaTeX Datasheet');

    $variable = $this->postJson('/catalog/api/latex/variables.php', [
        'key' => 'product_name',
        'type' => 'text',
        'value' => 'Widget',
    ])->assertOk()->assertJsonPath('data.key', 'product_name')->json('data');
    $this->getJson('/catalog/api/latex/variables.php')
        ->assertOk()
        ->assertJsonPath('data.0.value', 'Widget');
    $this->deleteJson('/catalog/api/latex/variables.php?id='.$variable['id'])
        ->assertOk()
        ->assertJsonPath('data.deleted', true);
    $this->deleteJson('/catalog/api/latex/templates.php?id='.$template['id'])
        ->assertOk()
        ->assertJsonPath('data.deleted', true);
});

it('stores uploaded product files under Catalog storage and rejects traversal', function () {
    $root = $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'name' => 'Storage Category',
        'type' => 'category',
    ])->assertOk()->json('data');
    $series = $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'name' => 'Storage Series',
        'type' => 'series',
        'parentId' => $root['id'],
    ])->assertOk()->json('data');
    $field = $this->postJson('/catalog/catalog.php?action=v1.saveSeriesField', [
        'seriesId' => $series['id'],
        'fieldKey' => 'datasheet',
        'label' => 'Datasheet',
        'fieldType' => 'file',
        'fieldScope' => 'product_attribute',
    ])->assertOk()->json('data');

    $upload = UploadedFile::fake()->createWithContent('datasheet.pdf', "%PDF-1.4\nIntegration test\n%%EOF");
    $response = $this->call(
        'POST',
        '/catalog/catalog.php?action=v1.saveProduct',
        ['metadata' => json_encode([
            'seriesId' => $series['id'],
            'sku' => 'EH-FILE-001',
            'name' => 'Uploaded Datasheet Product',
        ], JSON_THROW_ON_ERROR)],
        [],
        ['files' => ['datasheet' => $upload]],
        ['CONTENT_TYPE' => 'multipart/form-data']
    )->assertOk()->assertJsonPath('success', true);

    $productId = (int) $response->json('data.id');
    $storedPath = (string) DB::connection('catalog')->table('product_custom_field_value')
        ->where('product_id', $productId)
        ->where('series_custom_field_id', $field['id'])
        ->value('value');
    expect($storedPath)->toContain('datasheet.pdf');

    $this->get('/catalog/storage/media/'.$storedPath)->assertOk();
    $this->get('/catalog/storage/media/%2e%2e%2f%2e%2e%2f%2e%2e%2f.env')->assertNotFound();
});

it('preserves the legacy Typst template DELETE Missing ID failure for a valid template id', function () {
    $template = $this->postJson('/catalog/api/typst/templates.php', [
        'title' => 'Legacy delete regression',
        'description' => 'A valid row is deliberately used',
        'typst' => '#set page(width: 100mm)',
    ])->assertOk()->json('data');

    $this->deleteJson('/legacy/api/typst/templates.php?id='.$template['id'])
        ->assertInternalServerError()
        ->assertJsonPath('error.message', 'Missing ID');
    expect(DB::connection('catalog')->table('typst_templates')->where('id', $template['id'])->exists())
        ->toBeTrue();
});

it('preserves Typst textarea variables as text', function () {
    $this->postJson('/catalog/api/typst/variables.php', [
        'key' => 'description',
        'type' => 'textarea',
        'value' => 'Long description',
    ])->assertOk()
        ->assertJsonPath('data.type', 'text')
        ->assertJsonPath('data.value', 'Long description');
});

it('preserves HTTP 405 for file LaTeX variable PUT requests', function () {
    $this->putJson('/catalog/api/latex/variables.php', [
        'id' => 1,
        'key' => 'product_name',
        'type' => 'text',
        'value' => 'Widget',
    ])->assertMethodNotAllowed()
        ->assertJsonPath('error.code', 'method_not_allowed');
});

it('preserves the legacy LaTeX empty-description TypeError without inserting a template', function () {
    $this->postJson('/catalog/catalog.php?action=v1.createLatexTemplate', [
        'title' => 'Empty description regression',
        'description' => '',
        'latex' => '\\documentclass{article}',
    ])->assertInternalServerError()
        ->assertJsonPath('success', false)
        ->assertJsonPath('errorCode', 'SERVER_ERROR');

    expect(DB::connection('catalog')->table('latex_template')
        ->where('title', 'Empty description regression')->exists())->toBeFalse();
});

it('disables previously registered routes and reflects module state after a fresh bootstrap', function () {
    $this->get('/catalog/catalog_ui.html')->assertOk();

    File::put($this->catalogStatusesPath, json_encode([
        'Core' => true,
        'IAM' => true,
        'System' => true,
        'Catalog' => false,
    ], JSON_THROW_ON_ERROR));
    app()->forgetInstance(ActivatorInterface::class);

    expect(app('modules')->isEnabled('Catalog'))->toBeFalse();
    $this->get('/catalog/catalog_ui.html')->assertNotFound();

    $disabledRoutes = CatalogTestDatabase::freshBootRoutes(false);
    expect(collect($disabledRoutes)->pluck('uri')->all())->not->toContain('catalog/catalog_ui.html');

    File::put($this->catalogStatusesPath, json_encode([
        'Core' => true,
        'IAM' => true,
        'System' => true,
        'Catalog' => true,
    ], JSON_THROW_ON_ERROR));
    app()->forgetInstance(ActivatorInterface::class);

    expect(app('modules')->isEnabled('Catalog'))->toBeTrue();
    $this->get('/catalog/catalog_ui.html')->assertOk();

    $enabledRoutes = CatalogTestDatabase::freshBootRoutes(true);
    expect(collect($enabledRoutes)->pluck('uri')->all())->toContain('catalog/catalog_ui.html');
});

it('runs the explicit Catalog seeder twice without duplicating its initial catalog', function () {
    $options = [
        '--class' => 'Modules\\Catalog\\Database\\Seeders\\CatalogDatabaseSeeder',
        '--database' => 'catalog',
        '--force' => true,
    ];

    expect(Artisan::call('db:seed', $options))->toBe(0);
    $initialCounts = [
        DB::connection('catalog')->table('category')->count(),
        DB::connection('catalog')->table('product')->count(),
        DB::connection('catalog')->table('series_custom_field')->count(),
    ];
    expect($initialCounts[0])->toBeGreaterThan(0)
        ->and($initialCounts[1])->toBeGreaterThan(0);

    expect(Artisan::call('db:seed', $options))->toBe(0)
        ->and([
            DB::connection('catalog')->table('category')->count(),
            DB::connection('catalog')->table('product')->count(),
            DB::connection('catalog')->table('series_custom_field')->count(),
        ])->toBe($initialCounts);
});
