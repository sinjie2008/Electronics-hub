<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Connection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder;
use Modules\Catalog\Services\SeriesFieldService;
use Tests\TestCase;

beforeEach(function (): void {
    if (config('database.default') !== 'mysql') {
        $this->markTestSkipped('Catalog parity requires MySQL.');
    }

    $storageRoot = storage_path('framework/testing/catalog-behavior-'.Str::uuid());
    config([
        'catalog.storage_root' => $storageRoot,
        'catalog.settings.logging.path' => $storageRoot.DIRECTORY_SEPARATOR.'catalog.log',
    ]);
});

afterEach(function (): void {
    $storageRoot = (string) config('catalog.storage_root', '');
    $testingStorageRoot = storage_path('framework/testing/catalog-behavior-');
    if (str_starts_with($storageRoot, $testingStorageRoot)) {
        File::deleteDirectory($storageRoot);
    }

    if (config('catalog.connection') === 'catalog_behavior_truncate') {
        $connection = DB::connection('catalog_behavior_truncate');
        try {
            $connection->statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach ([
                'product_custom_field_value',
                'series_custom_field_value',
                'product',
                'series_custom_field',
                'category',
                'seed_migration',
            ] as $table) {
                $connection->statement('TRUNCATE TABLE '.$table);
            }
        } finally {
            $connection->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    if (config('catalog.connection') !== config('database.default')) {
        config(['catalog.connection' => config('database.default')]);
    }

    foreach (['catalog_behavior_truncate', 'catalog_behavior_lock'] as $connectionName) {
        DB::purge($connectionName);
    }
});

function catalogBehaviorConnection(): Connection
{
    return DB::connection((string) config('catalog.connection'));
}

function catalogBehaviorPrepare(TestCase $test): Connection
{
    $test->seed(RolesAndPermissionsSeeder::class);
    $test->seed(CatalogDatabaseSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('Super Admin');
    $test->actingAs($user);

    $connection = catalogBehaviorConnection();
    catalogBehaviorMarkInitialSeeded($connection);

    return $connection;
}

function catalogBehaviorMarkInitialSeeded(Connection $connection): void
{
    $seedName = (string) config('catalog.settings.seed_name', 'initial_catalog_v1');
    if (! $connection->table('seed_migration')->where('name', $seedName)->exists()) {
        $connection->table('seed_migration')->insert(['name' => $seedName]);
    }
}

function catalogBehaviorConfigureParallelConnection(string $connectionName): void
{
    $defaultConnectionName = (string) config('database.default');
    config([
        'database.connections.'.$connectionName => config('database.connections.'.$defaultConnectionName),
    ]);
    DB::purge($connectionName);
}

/** @return array{rootId:int, groupId:int, seriesId:int} */
function catalogBehaviorCreateTree(
    Connection $connection,
    string $rootName = 'Electronics',
    string $groupName = 'Capacitors',
    string $seriesName = 'C-Series'
): array {
    $rootId = catalogBehaviorInsertNode($connection, $rootName, 'category', null, 1);
    $groupId = catalogBehaviorInsertNode($connection, $groupName, 'category', $rootId, 1);
    $seriesId = catalogBehaviorInsertNode($connection, $seriesName, 'series', $groupId, 1);

    return ['rootId' => $rootId, 'groupId' => $groupId, 'seriesId' => $seriesId];
}

function catalogBehaviorInsertNode(
    Connection $connection,
    string $name,
    string $type,
    ?int $parentId,
    int $displayOrder = 0
): int {
    return (int) $connection->table('category')->insertGetId([
        'parent_id' => $parentId,
        'name' => $name,
        'type' => $type,
        'display_order' => $displayOrder,
    ]);
}

/** @param array<string, mixed> $overrides */
function catalogBehaviorInsertField(
    Connection $connection,
    int $seriesId,
    string $fieldKey,
    string $scope = SeriesFieldService::SCOPE_PRODUCT,
    array $overrides = []
): int {
    return (int) $connection->table('series_custom_field')->insertGetId(array_merge([
        'series_id' => $seriesId,
        'field_key' => $fieldKey,
        'label' => Str::headline($fieldKey),
        'field_type' => 'text',
        'field_scope' => $scope,
        'default_value' => null,
        'sort_order' => 1,
        'is_required' => 0,
        'is_public_portal_hidden' => 0,
        'is_backend_portal_hidden' => 0,
    ], $overrides));
}

function catalogBehaviorInsertProduct(
    Connection $connection,
    int $seriesId,
    string $sku,
    string $name,
    ?string $description = null
): int {
    return (int) $connection->table('product')->insertGetId([
        'series_id' => $seriesId,
        'sku' => $sku,
        'name' => $name,
        'description' => $description,
    ]);
}

it('persists hierarchy nodes and returns the nested tree while validating parent and templating rules', function (): void {
    $connection = catalogBehaviorPrepare($this);

    $rootResponse = $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'name' => 'Electronics',
        'type' => 'category',
        'displayOrder' => 4,
    ]);
    $rootResponse->assertOk()->assertJsonPath('success', true)->assertJsonPath('data.displayOrder', 4);
    $rootId = (int) $rootResponse->json('data.id');

    $seriesResponse = $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'name' => 'C-Series',
        'type' => 'series',
        'parentId' => $rootId,
        'displayOrder' => 2,
    ]);
    $seriesResponse->assertOk()->assertJsonPath('data.type', 'series');
    $seriesId = (int) $seriesResponse->json('data.id');

    $this->putJson('/catalog/catalog.php?action=v1.setSeriesTypstTemplating', [
        'seriesId' => $seriesId,
        'enabled' => true,
    ])->assertOk()->assertJsonPath('data.typstTemplatingEnabled', true);

    $this->get('/catalog/catalog.php?action=v1.listHierarchy')
        ->assertOk()
        ->assertJsonPath('data.hierarchy.0.id', $rootId)
        ->assertJsonPath('data.hierarchy.0.children.0.name', 'C-Series')
        ->assertJsonPath('data.hierarchy.0.children.0.typstTemplatingEnabled', true)
        ->assertJsonPath('data.seriesOptions.0.id', $seriesId);

    $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'id' => $rootId,
        'name' => 'Electronics',
        'type' => 'category',
        'parentId' => $rootId,
    ])->assertBadRequest()
        ->assertJsonPath('errorCode', 'VALIDATION_ERROR')
        ->assertJsonPath('details.parentId', 'Parent cannot be the node itself.');

    $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'name' => 'Invalid root series',
        'type' => 'series',
    ])->assertBadRequest()->assertJsonPath('details.parentId', 'Series must have a parent category.');

    $leafCategoryId = catalogBehaviorInsertNode($connection, 'Leaf category', 'category', $rootId);
    $emptySeriesId = catalogBehaviorInsertNode($connection, 'Empty series', 'series', $leafCategoryId);
    $this->postJson('/catalog/catalog.php?action=v1.deleteNode', ['id' => $emptySeriesId])
        ->assertOk()->assertJsonPath('success', true);
    $this->postJson('/catalog/catalog.php?action=v1.deleteNode', ['id' => $leafCategoryId])
        ->assertOk()->assertJsonPath('success', true);

    $this->assertDatabaseHas('category', [
        'id' => $seriesId,
        'parent_id' => $rootId,
        'name' => 'C-Series',
        'typst_templating_enabled' => 1,
    ], (string) config('catalog.connection'));
});

it('rejects deleting non-leaf nodes and removing a series that still has products', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    $productId = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'CAP-01', 'Capacitor');

    $this->postJson('/catalog/catalog.php?action=v1.deleteNode', ['id' => $tree['rootId']])
        ->assertConflict()
        ->assertJsonPath('errorCode', 'CONFLICT')
        ->assertJsonPath('details.id', 'Node still has children.');

    $this->postJson('/catalog/catalog.php?action=v1.deleteNode', ['id' => $tree['seriesId']])
        ->assertConflict()
        ->assertJsonPath('details.id', 'Series contains products.');

    $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'id' => $tree['seriesId'],
        'name' => 'C-Series',
        'type' => 'category',
        'parentId' => $tree['groupId'],
    ])->assertStatus(409)
        ->assertJsonPath('message', 'Cannot convert series with products into category.');

    $this->assertDatabaseHas('product', ['id' => $productId, 'series_id' => $tree['seriesId']], (string) config('catalog.connection'));
});

it('preserves a disabled Typst template on subsequent catalog requests', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    $connection->table('category')->where('id', $tree['seriesId'])->update([
        'latex_templating_enabled' => 1,
        'typst_templating_enabled' => 0,
    ]);

    $this->get('/catalog/catalog.php?action=v1.listHierarchy')->assertOk()
        ->assertJsonPath('data.hierarchy.0.children.0.children.0.typstTemplatingEnabled', false);

    $this->assertDatabaseHas('category', [
        'id' => $tree['seriesId'],
        'typst_templating_enabled' => 0,
    ], (string) config('catalog.connection'));
});

it('keeps product and metadata field scopes distinct and preserves field defaults and visibility flags', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);

    $productField = $this->postJson('/catalog/catalog.php?action=v1.saveSeriesField', [
        'seriesId' => $tree['seriesId'],
        'fieldKey' => 'shared_key',
        'label' => 'Voltage',
        'fieldType' => 'number',
        'fieldScope' => SeriesFieldService::SCOPE_PRODUCT,
        'defaultValue' => '12',
        'sortOrder' => 3,
        'isRequired' => true,
        'publicPortalHidden' => true,
        'backendPortalHidden' => true,
    ]);
    $productField->assertOk()
        ->assertJsonPath('data.fieldScope', SeriesFieldService::SCOPE_PRODUCT)
        ->assertJsonPath('data.defaultValue', '12')
        ->assertJsonPath('data.isRequired', true)
        ->assertJsonPath('data.publicPortalHidden', true)
        ->assertJsonPath('data.backendPortalHidden', true);
    $productFieldId = (int) $productField->json('data.id');

    $metadataField = $this->postJson('/catalog/catalog.php?action=v1.saveSeriesField', [
        'seriesId' => $tree['seriesId'],
        'fieldKey' => 'shared_key',
        'label' => 'Series voltage',
        'fieldScope' => SeriesFieldService::SCOPE_SERIES,
    ]);
    $metadataField->assertOk()->assertJsonPath('data.fieldScope', SeriesFieldService::SCOPE_SERIES);

    $defaultField = $this->postJson('/catalog/catalog.php?action=v1.saveSeriesField', [
        'seriesId' => $tree['seriesId'],
        'fieldKey' => 'tolerance',
        'label' => 'Tolerance',
    ]);
    $defaultField->assertOk()
        ->assertJsonPath('data.fieldScope', SeriesFieldService::SCOPE_PRODUCT)
        ->assertJsonPath('data.fieldType', 'text')
        ->assertJsonPath('data.isRequired', false)
        ->assertJsonPath('data.publicPortalHidden', false)
        ->assertJsonPath('data.backendPortalHidden', false);
    $defaultFieldId = (int) $defaultField->json('data.id');

    $this->postJson('/catalog/catalog.php?action=v1.saveSeriesField', [
        'id' => $defaultFieldId,
        'seriesId' => $tree['seriesId'],
        'fieldKey' => 'tolerance',
        'label' => 'Updated tolerance',
        'sortOrder' => 8,
    ])->assertOk()->assertJsonPath('data.label', 'Updated tolerance');

    $this->get('/catalog/catalog.php?action=v1.listSeriesFields&seriesId='.$tree['seriesId'].'&scope=product_attribute')
        ->assertOk()
        ->assertJsonPath('data.0.fieldKey', 'shared_key')
        ->assertJsonPath('data.0.fieldScope', SeriesFieldService::SCOPE_PRODUCT);
    $this->get('/catalog/catalog.php?action=v1.listSeriesFields&seriesId='.$tree['seriesId'].'&scope=series_metadata')
        ->assertOk()
        ->assertJsonPath('data.0.fieldKey', 'shared_key')
        ->assertJsonPath('data.0.fieldScope', SeriesFieldService::SCOPE_SERIES);

    $this->postJson('/catalog/catalog.php?action=v1.saveSeriesField', [
        'seriesId' => $tree['seriesId'],
        'fieldKey' => 'shared_key',
        'label' => 'Duplicate product key',
        'fieldScope' => SeriesFieldService::SCOPE_PRODUCT,
    ])->assertBadRequest()
        ->assertJsonPath('errorCode', 'VALIDATION_ERROR')
        ->assertJsonPath('message', 'Field key must be unique within the series and scope.');

    $this->postJson('/catalog/catalog.php?action=v1.saveSeriesField', [
        'id' => $productFieldId,
        'seriesId' => $tree['seriesId'],
        'fieldKey' => 'shared_key',
        'label' => 'Voltage',
        'fieldScope' => SeriesFieldService::SCOPE_SERIES,
    ])->assertBadRequest()->assertJsonPath('errorCode', 'FIELD_SCOPE_IMMUTABLE');

    $this->assertDatabaseHas('series_custom_field', [
        'id' => $productFieldId,
        'field_scope' => SeriesFieldService::SCOPE_PRODUCT,
        'is_public_portal_hidden' => 1,
        'is_backend_portal_hidden' => 1,
    ], (string) config('catalog.connection'));
    $this->assertSame(2, $connection->table('series_custom_field')->where('series_id', $tree['seriesId'])->where('field_key', 'shared_key')->count());

    $this->postJson('/catalog/catalog.php?action=v1.deleteSeriesField', ['id' => $defaultFieldId])
        ->assertOk()->assertJsonPath('success', true);
    expect($connection->table('series_custom_field')->where('id', $defaultFieldId)->exists())->toBeFalse();
});

it('adds missing default series metadata without replacing existing definitions or values', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    $voltageFieldId = catalogBehaviorInsertField(
        $connection,
        $tree['seriesId'],
        'series_voltage',
        SeriesFieldService::SCOPE_SERIES,
        ['label' => 'Locally defined voltage', 'field_type' => 'number', 'default_value' => '9', 'sort_order' => 7]
    );
    $connection->table('series_custom_field_value')->insert([
        'series_id' => $tree['seriesId'],
        'series_custom_field_id' => $voltageFieldId,
        'value' => 'custom voltage',
    ]);

    $this->get('/catalog/catalog.php?action=v1.getSeriesAttributes&seriesId='.$tree['seriesId'])
        ->assertOk()
        ->assertJsonPath('data.values.series_voltage', 'custom voltage');

    $notesField = $connection->table('series_custom_field')
        ->where('series_id', $tree['seriesId'])
        ->where('field_key', 'series_notes')
        ->first();
    expect($notesField)->not->toBeNull()
        ->and($notesField->field_scope)->toBe(SeriesFieldService::SCOPE_SERIES)
        ->and($notesField->label)->toBe('Series Notes');

    $preservedField = $connection->table('series_custom_field')->where('id', $voltageFieldId)->first();
    expect($preservedField->label)->toBe('Locally defined voltage')
        ->and($preservedField->field_type)->toBe('number')
        ->and($preservedField->default_value)->toBe('9')
        ->and($connection->table('series_custom_field_value')
            ->where('series_id', $tree['seriesId'])
            ->where('series_custom_field_id', $voltageFieldId)
            ->value('value'))->toBe('custom voltage');

    $this->get('/catalog/catalog.php?action=v1.getSeriesAttributes&seriesId='.$tree['seriesId'])->assertOk();
    expect($connection->table('series_custom_field')
        ->where('series_id', $tree['seriesId'])
        ->whereIn('field_key', ['series_voltage', 'series_notes'])
        ->count())->toBe(2);
});

it('keeps concurrent default metadata inserts idempotent and preserves existing metadata values', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    $existingFieldId = catalogBehaviorInsertField(
        $connection,
        $tree['seriesId'],
        'local_metadata',
        SeriesFieldService::SCOPE_SERIES,
        ['label' => 'Local Metadata', 'sort_order' => 1]
    );
    $connection->table('series_custom_field_value')->insert([
        'series_id' => $tree['seriesId'],
        'series_custom_field_id' => $existingFieldId,
        'value' => 'preserved value',
    ]);

    $injectingDuplicate = false;
    $fieldRaceInjected = false;
    $valueRaceInjected = false;
    $racedFieldId = null;

    $connection->beforeExecuting(function (string $query, array $bindings, Connection $queryConnection) use (
        $tree,
        &$injectingDuplicate,
        &$fieldRaceInjected,
        &$valueRaceInjected,
        &$racedFieldId
    ): void {
        if ($injectingDuplicate) {
            return;
        }

        $fieldInsert = preg_match('/^\s*insert\s+(?:ignore\s+)?into\s+`?series_custom_field`?(?:\s|\()/i', $query) === 1;
        $valueInsert = preg_match('/^\s*insert\s+(?:ignore\s+)?into\s+`?series_custom_field_value`?(?:\s|\()/i', $query) === 1;
        $stringBindings = array_map(static fn (mixed $binding): string => (string) $binding, $bindings);
        $integerBindings = array_map(static fn (mixed $binding): int => (int) $binding, $bindings);

        if (
            ! $fieldRaceInjected
            && $fieldInsert
            && in_array($tree['seriesId'], $integerBindings, true)
            && in_array('series_voltage', $stringBindings, true)
            && in_array(SeriesFieldService::SCOPE_SERIES, $stringBindings, true)
        ) {
            $fieldRaceInjected = true;
            $injectingDuplicate = true;
            try {
                $queryConnection->insert($query, $bindings);
                $racedFieldId = (int) $queryConnection->table('series_custom_field')
                    ->where('series_id', $tree['seriesId'])
                    ->where('field_scope', SeriesFieldService::SCOPE_SERIES)
                    ->where('field_key', 'series_voltage')
                    ->value('id');
            } finally {
                $injectingDuplicate = false;
            }

            return;
        }

        if (
            ! $valueRaceInjected
            && $valueInsert
            && $racedFieldId !== null
            && in_array($tree['seriesId'], $integerBindings, true)
            && in_array($racedFieldId, $integerBindings, true)
        ) {
            $valueRaceInjected = true;
            $injectingDuplicate = true;
            try {
                $queryConnection->insert($query, $bindings);
            } finally {
                $injectingDuplicate = false;
            }
        }
    });

    $response = $this->get('/catalog/catalog.php?action=v1.getSeriesAttributes&seriesId='.$tree['seriesId']);

    $response->assertOk()
        ->assertJsonPath('data.values.local_metadata', 'preserved value')
        ->assertJsonCount(3, 'data.definitions');
    expect($fieldRaceInjected)->toBeTrue()
        ->and($valueRaceInjected)->toBeTrue()
        ->and($racedFieldId)->toBeGreaterThan(0);

    $defaultFieldIds = $connection->table('series_custom_field')
        ->where('series_id', $tree['seriesId'])
        ->where('field_scope', SeriesFieldService::SCOPE_SERIES)
        ->whereIn('field_key', ['series_voltage', 'series_notes'])
        ->pluck('id');
    expect($defaultFieldIds)->toHaveCount(2)
        ->and($connection->table('series_custom_field_value')
            ->where('series_id', $tree['seriesId'])
            ->whereIn('series_custom_field_id', $defaultFieldIds)
            ->count())->toBe(2)
        ->and($connection->table('series_custom_field')
            ->where('series_id', $tree['seriesId'])
            ->where('field_scope', SeriesFieldService::SCOPE_SERIES)
            ->count())->toBe(3)
        ->and($connection->table('series_custom_field_value')
            ->where('series_id', $tree['seriesId'])->count())->toBe(3)
        ->and($connection->table('series_custom_field_value')
            ->where('series_id', $tree['seriesId'])
            ->where('series_custom_field_id', $existingFieldId)
            ->value('value'))->toBe('preserved value');
});

it('validates and saves series metadata with numeric and required-field rules', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    $fieldId = catalogBehaviorInsertField(
        $connection,
        $tree['seriesId'],
        'rated_frequency',
        SeriesFieldService::SCOPE_SERIES,
        ['field_type' => 'number', 'is_required' => 1]
    );

    $this->postJson('/catalog/catalog.php?action=v1.saveSeriesAttributes', [
        'seriesId' => $tree['seriesId'],
        'values' => ['rated_frequency' => '60 Hz'],
    ])->assertBadRequest()
        ->assertJsonPath('errorCode', 'VALIDATION_ERROR')
        ->assertJsonPath('message', 'Series metadata validation failed.')
        ->assertJsonPath('details.rated_frequency', 'Must be numeric.');

    $this->postJson('/catalog/catalog.php?action=v1.saveSeriesAttributes', [
        'seriesId' => $tree['seriesId'],
        'values' => [],
    ])->assertBadRequest()->assertJsonPath('details.rated_frequency', 'Field is required.');

    $this->postJson('/catalog/catalog.php?action=v1.saveSeriesAttributes', [
        'seriesId' => $tree['seriesId'],
        'values' => ['rated_frequency' => '60'],
    ])->assertOk()->assertJsonPath('data.values.rated_frequency', '60');

    $this->postJson('/catalog/catalog.php?action=v1.saveSeriesAttributes', [
        'seriesId' => $tree['seriesId'],
        'values' => ['rated_frequency' => '61'],
    ])->assertOk()->assertJsonPath('data.values.rated_frequency', '61');

    $this->assertDatabaseHas('series_custom_field_value', [
        'series_id' => $tree['seriesId'],
        'series_custom_field_id' => $fieldId,
        'value' => '61',
    ], (string) config('catalog.connection'));
});

it('rolls back earlier metadata writes when a later uploaded field fails validation', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    $firstFieldId = catalogBehaviorInsertField($connection, $tree['seriesId'], 'commit_first', SeriesFieldService::SCOPE_SERIES, ['sort_order' => 1]);
    catalogBehaviorInsertField($connection, $tree['seriesId'], 'manual', SeriesFieldService::SCOPE_SERIES, [
        'field_type' => 'file',
        'sort_order' => 2,
    ]);

    $this->withHeaders(['Content-Type' => 'multipart/form-data'])->post('/catalog/catalog.php?action=v1.saveSeriesAttributes', [
        'metadata' => json_encode([
            'seriesId' => $tree['seriesId'],
            'values' => ['commit_first' => 'must roll back'],
        ], JSON_THROW_ON_ERROR),
        'files' => ['manual' => UploadedFile::fake()->create('manual.txt', 1, 'text/plain')],
    ])->assertBadRequest()
        ->assertJsonPath('errorCode', 'MEDIA_TYPE_INVALID');

    expect($connection->table('series_custom_field_value')
        ->where('series_id', $tree['seriesId'])
        ->where('series_custom_field_id', $firstFieldId)->exists())->toBeFalse();
});

it('validates product fields and supports product create, update, list, and delete', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    $fieldId = catalogBehaviorInsertField($connection, $tree['seriesId'], 'voltage_rating', overrides: [
        'field_type' => 'number',
        'is_required' => 1,
    ]);

    $this->postJson('/catalog/catalog.php?action=v1.saveProduct', [
        'seriesId' => $tree['seriesId'],
        'sku' => 'CAP-01',
        'name' => 'Capacitor 100uF',
        'custom_field_values' => ['voltage_rating' => '16V'],
    ])->assertBadRequest()
        ->assertJsonPath('errorCode', 'VALIDATION_ERROR')
        ->assertJsonPath('message', 'Field voltage_rating must be numeric.');
    expect($connection->table('product')->where('sku', 'CAP-01')->exists())->toBeFalse();

    $saved = $this->postJson('/catalog/catalog.php?action=v1.saveProduct', [
        'series_id' => $tree['seriesId'],
        'sku' => 'CAP-01',
        'name' => 'Capacitor 100uF',
        'description' => 'Initial description',
        'custom_field_values' => ['voltage_rating' => '16'],
    ]);
    $saved->assertOk()->assertJsonPath('data.customValues.voltage_rating', '16');
    $productId = (int) $saved->json('data.id');

    $this->postJson('/catalog/catalog.php?action=v1.saveProduct', [
        'id' => $productId,
        'seriesId' => $tree['seriesId'],
        'sku' => 'CAP-01A',
        'name' => 'Updated capacitor',
        'description' => 'Updated description',
        'customValues' => ['voltage_rating' => '25'],
    ])->assertOk()->assertJsonPath('data.customValues.voltage_rating', '25');

    $this->get('/catalog/catalog.php?action=v1.listProducts&seriesId='.$tree['seriesId'])
        ->assertOk()
        ->assertJsonPath('data.0.sku', 'CAP-01A')
        ->assertJsonPath('data.0.customValues.voltage_rating', '25');

    $this->postJson('/catalog/catalog.php?action=v1.deleteProduct', ['id' => $productId])
        ->assertOk()->assertJsonPath('success', true);
    expect($connection->table('product')->where('id', $productId)->exists())->toBeFalse()
        ->and($connection->table('product_custom_field_value')
            ->where('product_id', $productId)->where('series_custom_field_id', $fieldId)->exists())->toBeFalse();
});

it('rolls back a product insert when an uploaded custom field is rejected', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    catalogBehaviorInsertField($connection, $tree['seriesId'], 'datasheet', overrides: ['field_type' => 'file']);

    $this->withHeaders(['Content-Type' => 'multipart/form-data'])->post('/catalog/catalog.php?action=v1.saveProduct', [
        'metadata' => json_encode([
            'seriesId' => $tree['seriesId'],
            'sku' => 'ROLLBACK-1',
            'name' => 'Must not persist',
        ], JSON_THROW_ON_ERROR),
        'files' => ['datasheet' => UploadedFile::fake()->create('notes.txt', 1, 'text/plain')],
    ])->assertBadRequest()->assertJsonPath('errorCode', 'MEDIA_TYPE_INVALID');

    expect($connection->table('product')->where('sku', 'ROLLBACK-1')->exists())->toBeFalse();
});

it('searches SQL-backed product facets and applies bound category and custom-field filters', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection, 'Electrical', 'Capacitors', 'C-Series');
    $fieldId = catalogBehaviorInsertField($connection, $tree['seriesId'], 'voltage_rating');
    $firstProductId = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'CAP-16', '16V Capacitor');
    $secondProductId = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'CAP-25', '25V Capacitor');
    foreach ([[$firstProductId, '16V'], [$secondProductId, '25V']] as [$productId, $value]) {
        $connection->table('product_custom_field_value')->insert([
            'product_id' => $productId,
            'series_custom_field_id' => $fieldId,
            'value' => $value,
        ]);
    }

    $this->get('/catalog/api/spec-search/root-categories.php')
        ->assertOk()->assertJsonPath('data.categories.0.name', 'Electrical');
    $this->get('/catalog/api/spec-search/product-categories.php?root_id='.$tree['rootId'])
        ->assertOk()->assertJsonPath('data.groups.0.categories.0.id', $tree['groupId']);
    $this->postJson('/catalog/api/spec-search/facets.php', ['category_ids' => [$tree['groupId']]])
        ->assertOk()
        ->assertJsonPath('data.facets.0.key', 'series')
        ->assertJsonPath('data.facets.0.values.0', 'C-Series');

    $this->postJson('/catalog/api/spec-search/products.php', [
        'category_ids' => [$tree['groupId']],
        'filters' => ['voltage_rating' => ['25V']],
    ])->assertOk()
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.items.0.sku', 'CAP-25')
        ->assertJsonPath('data.items.0.voltage_rating', '25V');

    $this->postJson('/catalog/api/spec-search/products.php', [
        'category_ids' => [$tree['groupId']],
        'filters' => ["voltage_rating' OR 1=1 --" => ['16V']],
    ])->assertOk()->assertJsonPath('data.total', 0);
});

it('imports CSV as a full snapshot and prunes records absent from the new snapshot', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $oldTree = catalogBehaviorCreateTree($connection, 'Old root', 'Old group', 'Old series');
    $oldFieldId = catalogBehaviorInsertField($connection, $oldTree['seriesId'], 'voltage_rating');
    $oldProductId = catalogBehaviorInsertProduct($connection, $oldTree['seriesId'], 'OLD-1', 'Old product');
    $connection->table('product_custom_field_value')->insert([
        'product_id' => $oldProductId,
        'series_custom_field_id' => $oldFieldId,
        'value' => '5V',
    ]);
    $this->get('/catalog/catalog.php?action=v1.listHierarchy')->assertOk();

    $csv = "category_path,product_name,voltage_rating\n"
        .'Power > Capacitors > C-Series,CAP-16,16V'."\n"
        .'Power > Capacitors > C-Series,CAP-25,25V'."\n";
    $response = $this->post('/catalog/api/catalog/csv-import.php', [
        'file' => UploadedFile::fake()->createWithContent('snapshot.csv', $csv),
    ]);

    $response->assertStatus(202)
        ->assertJsonPath('data.importedProducts', 2)
        ->assertJsonPath('data.createdCategories', 2)
        ->assertJsonPath('data.createdSeries', 1);
    $newSeriesId = (int) $connection->table('category')->where('type', 'series')->where('name', 'C-Series')->value('id');
    expect($newSeriesId)->toBeGreaterThan(0)
        ->and($connection->table('product')->where('sku', 'OLD-1')->exists())->toBeFalse()
        ->and($connection->table('product')->where('series_id', $newSeriesId)->count())->toBe(2);

    $importedFieldId = (int) $connection->table('series_custom_field')
        ->where('series_id', $newSeriesId)->where('field_key', 'voltage_rating')->value('id');
    expect($connection->table('product_custom_field_value')
        ->where('series_custom_field_id', $importedFieldId)
        ->where('value', '25V')->exists())->toBeTrue();
});

it('rolls back every catalog row from a malformed CSV snapshot', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection, 'Keep root', 'Keep group', 'Keep series');
    $productId = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'KEEP-1', 'Keep product');
    $this->get('/catalog/catalog.php?action=v1.listHierarchy')->assertOk();
    $categoryCount = $connection->table('category')->count();
    $fieldCount = $connection->table('series_custom_field')->count();

    $csv = "category_path,product_name,voltage_rating\n"
        .'New root > New group > New series,NEW-1,12V'."\n"
        .',BROKEN-1,5V'."\n";
    $this->post('/catalog/api/catalog/csv-import.php', [
        'file' => UploadedFile::fake()->createWithContent('broken.csv', $csv),
    ])->assertBadRequest()
        ->assertJsonPath('error.code', 'VALIDATION_ERROR')
        ->assertJsonPath('error.message', 'Row 3: category_path is required.');

    expect($connection->table('category')->count())->toBe($categoryCount)
        ->and($connection->table('series_custom_field')->count())->toBe($fieldCount)
        ->and($connection->table('product')->where('id', $productId)->exists())->toBeTrue()
        ->and($connection->table('category')->where('name', 'New root')->exists())->toBeFalse();
});

it('imports a dense CSV through the page action with a bounded query budget and restores its values', function (int $productCount, int $attributeCount, int $seriesCount, int $populatedAttributeCount, int $queryBudget): void {
    $connection = catalogBehaviorPrepare($this);
    $columns = array_map(static fn (int $index): string => 'attribute_'.$index, range(1, $attributeCount));
    $stream = fopen('php://temp', 'w+');
    fputcsv($stream, ['category_path', 'product_name', ...$columns]);
    foreach (range(1, $productCount) as $index) {
        fputcsv($stream, [
            'Power > Components > Dense series '.((($index - 1) % $seriesCount) + 1),
            'DENSE-'.$index,
            ...array_fill(0, $populatedAttributeCount - 1, '16V'),
            ...array_fill(0, $attributeCount - $populatedAttributeCount, ''),
            'Quoted "value", with comma',
        ]);
    }
    rewind($stream);
    $csv = stream_get_contents($stream);
    fclose($stream);
    $queryCount = 0;
    $connection->listen(static function (QueryExecuted $query) use ($connection, &$queryCount): void {
        if ($query->connection === $connection) {
            $queryCount++;
        }
    });

    $response = $this->post('/catalog/catalog.php?action=v1.importCsv', [
        'file' => UploadedFile::fake()->createWithContent('dense.csv', $csv),
    ]);

    $response->assertOk()->assertJsonPath('data.importedProducts', $productCount);
    expect($queryCount)->toBeLessThan($queryBudget);
    $this->assertDatabaseCount('product', $productCount, (string) config('catalog.connection'));
    $this->assertDatabaseCount('product_custom_field_value', $productCount * $populatedAttributeCount, (string) config('catalog.connection'));
    expect($connection->table('series_custom_field')->where('field_scope', SeriesFieldService::SCOPE_PRODUCT)->count())
        ->toBe($seriesCount * $attributeCount);
    $seriesId = $connection->table('product')->where('sku', 'DENSE-'.$productCount)->value('series_id');
    $fieldId = $connection->table('series_custom_field')->where('series_id', $seriesId)->where('field_key', 'attribute_'.$attributeCount)->value('id');
    $productId = $connection->table('product')->where('sku', 'DENSE-'.$productCount)->value('id');
    $this->assertDatabaseHas('product_custom_field_value', [
        'product_id' => $productId,
        'series_custom_field_id' => $fieldId,
        'value' => 'Quoted "value", with comma',
    ], (string) config('catalog.connection'));

    $connection->table('product_custom_field_value')->where('product_id', $productId)->update(['value' => 'Changed']);
    $queryCount = 0;
    $this->postJson('/catalog/catalog.php?action=v1.restoreCsv', ['id' => $response->json('data.fileId')])
        ->assertOk()->assertJsonPath('data.importedProducts', $productCount);
    expect($queryCount)->toBeLessThan($queryBudget);
    $this->assertDatabaseHas('product_custom_field_value', [
        'product_id' => $productId,
        'series_custom_field_id' => $fieldId,
        'value' => 'Quoted "value", with comma',
    ], (string) config('catalog.connection'));
})->with([
    'dense snapshot' => [512, 32, 1, 32, 4000],
    'many series snapshot' => [512, 110, 32, 7, 4000],
    'large snapshot' => [6101, 110, 482, 7, 30000],
]);

it('synchronizes CSV field order without changing existing field settings or metadata', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection, 'Power', 'Components', 'Dense series');
    $fieldId = catalogBehaviorInsertField($connection, $tree['seriesId'], 'voltage', overrides: [
        'label' => 'Rated voltage',
        'field_type' => 'number',
        'default_value' => '12',
        'sort_order' => 9,
        'is_required' => 1,
        'is_public_portal_hidden' => 1,
        'is_backend_portal_hidden' => 1,
    ]);
    $metadataId = catalogBehaviorInsertField($connection, $tree['seriesId'], 'voltage', SeriesFieldService::SCOPE_SERIES, ['sort_order' => 8]);
    $csv = "category_path,product_name,voltage,123\nPower > Components > Dense series,CSV-1,16,Numeric header\n";

    $this->post('/catalog/catalog.php?action=v1.importCsv', [
        'file' => UploadedFile::fake()->createWithContent('fields.csv', $csv),
    ])->assertOk()->assertJsonPath('data.importedProducts', 1);

    $this->assertDatabaseHas('series_custom_field', [
        'id' => $fieldId,
        'label' => 'Rated voltage',
        'field_type' => 'number',
        'default_value' => '12',
        'sort_order' => 0,
        'is_required' => 1,
        'is_public_portal_hidden' => 1,
        'is_backend_portal_hidden' => 1,
    ], (string) config('catalog.connection'));
    $this->assertDatabaseHas('series_custom_field', ['id' => $metadataId, 'sort_order' => 8], (string) config('catalog.connection'));
    $numericFieldId = $connection->table('series_custom_field')->where('series_id', $tree['seriesId'])->where('field_key', '123')->value('id');
    $this->assertDatabaseHas('product_custom_field_value', [
        'series_custom_field_id' => $numericFieldId,
        'value' => 'Numeric header',
    ], (string) config('catalog.connection'));
});

it('rolls back CSV snapshots when field keys collide under the database collation', function (bool $existingField): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection, 'Power', 'Components', 'Dense series');
    $productId = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'ORIGINAL', 'Original product');
    if ($existingField) {
        catalogBehaviorInsertField($connection, $tree['seriesId'], 'VOLTAGE');
    }
    $columns = $existingField ? 'voltage' : 'voltage,VOLTAGE';
    $values = $existingField ? '16' : '16,25';
    $csv = "category_path,product_name,$columns\nPower > Components > Dense series,CSV-1,$values\n";

    $this->post('/catalog/catalog.php?action=v1.importCsv', [
        'file' => UploadedFile::fake()->createWithContent('duplicate-fields.csv', $csv),
    ])->assertBadRequest()
        ->assertJsonPath('errorCode', 'VALIDATION_ERROR')
        ->assertJsonPath('message', 'Field key must be unique within the series and scope.');

    $this->assertDatabaseHas('product', ['id' => $productId, 'sku' => 'ORIGINAL'], (string) config('catalog.connection'));
    $this->assertDatabaseCount('product', 1, (string) config('catalog.connection'));
    expect($connection->table('series_custom_field')->where('series_id', $tree['seriesId'])
        ->where('field_scope', SeriesFieldService::SCOPE_PRODUCT)->count())
        ->toBe($existingField ? 1 : 0);
})->with(['CSV headers' => [false], 'existing definition' => [true]]);

it('treats a header-only CSV as an empty full snapshot', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'CAP-01', 'Capacitor');
    $this->get('/catalog/catalog.php?action=v1.listHierarchy')->assertOk();

    $this->post('/catalog/api/catalog/csv-import.php', [
        'file' => UploadedFile::fake()->createWithContent('empty.csv', "category_path,product_name\n"),
    ])->assertStatus(202)->assertJsonPath('data.importedProducts', 0);

    expect($connection->table('product')->count())->toBe(0)
        ->and($connection->table('category')->count())->toBe(0)
        ->and($connection->table('series_custom_field')->count())->toBe(0)
        ->and($connection->table('series_custom_field_value')->count())->toBe(0);
});

it('exports products in stable SKU order and restores the saved CSV snapshot', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection, 'Power', 'Components', 'Capacitors');
    $fieldId = catalogBehaviorInsertField($connection, $tree['seriesId'], 'voltage_rating', overrides: ['sort_order' => 1]);
    $productB = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'CAP-B', 'Capacitor B');
    $productA = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'CAP-A', 'Capacitor A');
    foreach ([[$productB, '25V'], [$productA, '16V']] as [$productId, $value]) {
        $connection->table('product_custom_field_value')->insert([
            'product_id' => $productId,
            'series_custom_field_id' => $fieldId,
            'value' => $value,
        ]);
    }
    $this->get('/catalog/catalog.php?action=v1.listHierarchy')->assertOk();

    $export = $this->postJson('/catalog/api/catalog/csv-export.php', []);
    $export->assertOk()->assertJsonPath('data.type', 'export');
    $fileId = (string) $export->json('data.id');
    $csvPath = rtrim((string) config('catalog.storage_root'), '/\\').DIRECTORY_SEPARATOR.'csv'.DIRECTORY_SEPARATOR.$fileId;
    $rows = array_map('str_getcsv', file($csvPath, FILE_IGNORE_NEW_LINES));
    expect($rows[0])->toBe(['category_path', 'product_name', 'voltage_rating'])
        ->and($rows[1])->toBe(['Power > Components > Capacitors', 'CAP-A', '16V'])
        ->and($rows[2])->toBe(['Power > Components > Capacitors', 'CAP-B', '25V']);

    catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'SURPLUS', 'Not in snapshot');
    $this->postJson('/catalog/api/catalog/csv-restore.php', ['id' => $fileId])
        ->assertOk()->assertJsonPath('data.importedProducts', 2)->assertJsonPath('data.fileId', $fileId);

    expect($connection->table('product')->where('series_id', $tree['seriesId'])->count())->toBe(2)
        ->and($connection->table('product')->where('sku', 'SURPLUS')->exists())->toBeFalse()
        ->and($connection->table('product')->where('sku', 'CAP-A')->exists())->toBeTrue();
});

it('requires the exact truncate confirmation and keeps catalog rows unchanged on rejection', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    $productId = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'CAP-01', 'Capacitor');

    $this->postJson('/catalog/api/catalog/truncate.php', [
        'reason' => 'Maintenance window',
        'confirmToken' => 'CLEAR',
    ])->assertBadRequest()
        ->assertJsonPath('error.code', 'TRUNCATE_CONFIRMATION_REQUIRED')
        ->assertJsonPath('error.message', 'Confirmation text mismatch. Please type TRUNCATE to proceed.');

    expect($connection->table('product')->where('id', $productId)->exists())->toBeTrue();
});

it('blocks truncate while another connection holds the MySQL advisory lock', function (): void {
    $connection = catalogBehaviorPrepare($this);
    $tree = catalogBehaviorCreateTree($connection);
    $productId = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'LOCK-1', 'Locked product');
    catalogBehaviorConfigureParallelConnection('catalog_behavior_lock');
    $lockConnection = DB::connection('catalog_behavior_lock');
    $lock = $lockConnection->selectOne('SELECT GET_LOCK(?, 0) AS acquired', ['catalog_truncate_lock']);

    try {
        expect((int) $lock->acquired)->toBe(1);
        $this->postJson('/catalog/api/catalog/truncate.php', [
            'reason' => 'Lock test',
            'confirmToken' => 'TRUNCATE',
        ])->assertConflict()
            ->assertJsonPath('error.code', 'TRUNCATE_IN_PROGRESS')
            ->assertJsonPath('error.message', 'Another destructive operation is already running. Try again shortly.');
    } finally {
        $lockConnection->selectOne('SELECT RELEASE_LOCK(?) AS released', ['catalog_truncate_lock']);
    }

    expect($connection->table('product')->where('id', $productId)->exists())->toBeTrue();
});

it('truncates through HTTP, writes an audit entry, and resets catalog auto-increment IDs', function (): void {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogDatabaseSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('Super Admin');
    $this->actingAs($user);

    catalogBehaviorConfigureParallelConnection('catalog_behavior_truncate');
    config(['catalog.connection' => 'catalog_behavior_truncate']);
    $connection = catalogBehaviorConnection();
    catalogBehaviorMarkInitialSeeded($connection);
    $tree = catalogBehaviorCreateTree($connection);
    $productFieldId = catalogBehaviorInsertField($connection, $tree['seriesId'], 'voltage_rating');
    $voltageFieldId = catalogBehaviorInsertField(
        $connection,
        $tree['seriesId'],
        'series_voltage',
        SeriesFieldService::SCOPE_SERIES
    );
    $notesFieldId = catalogBehaviorInsertField(
        $connection,
        $tree['seriesId'],
        'series_notes',
        SeriesFieldService::SCOPE_SERIES
    );
    $productId = catalogBehaviorInsertProduct($connection, $tree['seriesId'], 'CAP-01', 'Capacitor');
    $connection->table('product_custom_field_value')->insert([
        'product_id' => $productId,
        'series_custom_field_id' => $productFieldId,
        'value' => '16V',
    ]);
    foreach ([$voltageFieldId, $notesFieldId] as $fieldId) {
        $connection->table('series_custom_field_value')->insert([
            'series_id' => $tree['seriesId'],
            'series_custom_field_id' => $fieldId,
            'value' => $fieldId === $voltageFieldId ? '16V' : 'local notes',
        ]);
    }

    $correlationId = 'catalog-truncate-feature-test';
    $response = $this->postJson('/catalog/api/catalog/truncate.php', [
        'reason' => "Routine\ncleanup",
        'confirmToken' => 'truncate',
        'correlationId' => $correlationId,
    ]);
    $response->assertOk()
        ->assertJsonPath('data.auditId', $correlationId)
        ->assertJsonPath('data.deleted.categories', 2)
        ->assertJsonPath('data.deleted.series', 1)
        ->assertJsonPath('data.deleted.products', 1)
        ->assertJsonPath('data.deleted.fieldDefinitions', 3)
        ->assertJsonPath('data.deleted.productValues', 1)
        ->assertJsonPath('data.deleted.seriesValues', 2);

    $auditPath = rtrim((string) config('catalog.storage_root'), '/\\').DIRECTORY_SEPARATOR.'csv'.DIRECTORY_SEPARATOR.'truncate_audit.jsonl';
    $auditEntries = array_map(static fn (string $line): array => json_decode($line, true, 512, JSON_THROW_ON_ERROR), file($auditPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    expect($auditEntries[0]['id'])->toBe($correlationId)
        ->and($auditEntries[0]['reason'])->toBe('Routine cleanup')
        ->and($auditEntries[0]['deleted']['products'])->toBe(1)
        ->and($connection->table('category')->count())->toBe(0)
        ->and($connection->table('product')->count())->toBe(0)
        ->and($connection->table('series_custom_field')->count())->toBe(0)
        ->and($connection->table('seed_migration')->count())->toBe(0);

    $firstId = catalogBehaviorInsertNode($connection, 'After truncate', 'category', null);
    expect($firstId)->toBe(1);
});

it('requires catalog permissions for mutations and CSV uploads', function (): void {
    $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'name' => 'Unauthorized',
        'type' => 'category',
    ])->assertUnauthorized()->assertJsonPath('errorCode', 'UNAUTHENTICATED');

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->seed(CatalogDatabaseSeeder::class);
    $user = User::factory()->create();
    $user->assignRole('User');
    $this->actingAs($user);

    $this->postJson('/catalog/catalog.php?action=v1.saveNode', [
        'name' => 'Unauthorized',
        'type' => 'category',
    ])->assertForbidden()->assertJsonPath('errorCode', 'FORBIDDEN');
});
