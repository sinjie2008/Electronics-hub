<?php

use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\Catalog\Filament\Resources\LatexTemplates\LatexTemplateResource;
use Modules\Catalog\Filament\Resources\LatexTemplates\Pages as LatexPages;
use Modules\Catalog\Filament\Resources\Nodes\CatalogNodeResource;
use Modules\Catalog\Filament\Resources\Nodes\Pages as NodePages;
use Modules\Catalog\Filament\Resources\Products\CatalogProductResource;
use Modules\Catalog\Filament\Resources\Products\Pages as ProductPages;
use Modules\Catalog\Filament\Resources\SeriesFields\Pages as FieldPages;
use Modules\Catalog\Filament\Resources\SeriesFields\SeriesFieldResource;
use Modules\Catalog\Filament\Resources\TypstTemplates\Pages as TypstPages;
use Modules\Catalog\Filament\Resources\TypstTemplates\TypstTemplateResource;
use Modules\Catalog\Models\CatalogNode;
use Modules\Catalog\Models\CatalogProduct;
use Modules\Catalog\Models\LatexTemplate;
use Modules\Catalog\Models\SeriesField;
use Modules\Catalog\Models\TypstTemplate;
use Modules\Catalog\Services\CatalogAdminService;
use Modules\IAM\Models\Role;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CatalogTestDatabase;

function catalogFilamentCases(): array
{
    return [
        'nodes' => [CatalogNodeResource::class, NodePages\ListCatalogNodes::class, NodePages\CreateCatalogNode::class, NodePages\EditCatalogNode::class, NodePages\ViewCatalogNode::class, CatalogNode::class, 'name'],
        'products' => [CatalogProductResource::class, ProductPages\ListCatalogProducts::class, ProductPages\CreateCatalogProduct::class, ProductPages\EditCatalogProduct::class, ProductPages\ViewCatalogProduct::class, CatalogProduct::class, 'name'],
        'fields' => [SeriesFieldResource::class, FieldPages\ListSeriesFields::class, FieldPages\CreateSeriesField::class, FieldPages\EditSeriesField::class, FieldPages\ViewSeriesField::class, SeriesField::class, 'label'],
        'typst' => [TypstTemplateResource::class, TypstPages\ListTypstTemplates::class, TypstPages\CreateTypstTemplate::class, TypstPages\EditTypstTemplate::class, TypstPages\ViewTypstTemplate::class, TypstTemplate::class, 'title'],
        'latex' => [LatexTemplateResource::class, LatexPages\ListLatexTemplates::class, LatexPages\CreateLatexTemplate::class, LatexPages\EditLatexTemplate::class, LatexPages\ViewLatexTemplate::class, LatexTemplate::class, 'title'],
    ];
}

function catalogFilamentPayload(string $kind): array
{
    $seriesId = test()->catalogSeries->getKey();

    return match ($kind) {
        'nodes' => ['name' => 'New Filament category', 'type' => 'category', 'parent_id' => null, 'display_order' => 2],
        'products' => ['series_id' => $seriesId, 'sku' => 'FILAMENT-001', 'name' => 'New Filament product', 'description' => 'Shared Catalog data'],
        'fields' => ['series_id' => $seriesId, 'field_key' => 'rating', 'label' => 'New Filament rating', 'field_type' => 'text', 'field_scope' => 'product_attribute', 'sort_order' => 0, 'is_required' => false, 'is_public_portal_hidden' => false, 'is_backend_portal_hidden' => false],
        'typst' => ['title' => 'New Filament Typst template', 'description' => 'Global datasheet', 'typst_content' => '= Datasheet', 'is_global' => true, 'series_id' => null],
        'latex' => ['title' => 'New Filament LaTeX template', 'description' => 'Legacy datasheet', 'latex_source' => '\\documentclass{article}\\begin{document}Datasheet\\end{document}'],
    };
}

beforeEach(function () {
    $this->withoutVite();
    $this->catalogStorageRoot = CatalogTestDatabase::prepare();
    $this->catalogOriginalStatusesPath = config('modules.activators.file.statuses-file');
    $this->catalogStatusesPath = storage_path('framework/testing/catalog-filament-'.bin2hex(random_bytes(8)).'.json');
    File::put($this->catalogStatusesPath, json_encode(['Core' => true, 'IAM' => true, 'System' => true, 'Catalog' => true], JSON_THROW_ON_ERROR));
    config(['modules.activators.file.statuses-file' => $this->catalogStatusesPath]);
    app()->forgetInstance(ActivatorInterface::class);
    $this->seed();
    $this->catalogActor = User::factory()->create();
    $this->catalogActor->assignRole(Role::SUPER_ADMIN);
    $this->catalogAdmin = app(CatalogAdminService::class);
    $this->catalogRoot = $this->catalogAdmin->saveNode($this->catalogActor, ['name' => 'Fixture category', 'type' => 'category', 'display_order' => 0]);
    $this->catalogSeries = $this->catalogAdmin->saveNode($this->catalogActor, ['name' => 'Fixture series', 'type' => 'series', 'parent_id' => $this->catalogRoot->getKey(), 'display_order' => 0]);
    Livewire::actingAs($this->catalogActor, 'web');
});

afterEach(function () {
    CatalogTestDatabase::cleanup($this->catalogStorageRoot ?? null);
    if (isset($this->catalogStatusesPath)) {
        File::delete($this->catalogStatusesPath, $this->catalogStatusesPath.'.lock');
        config(['modules.activators.file.statuses-file' => $this->catalogOriginalStatusesPath]);
        app()->forgetInstance(ActivatorInterface::class);
    }
});

it('creates views searches edits and deletes each major Catalog resource through Filament', function (string $kind) {
    [$resource, $list, $create, $edit, $view, $model, $searchField] = catalogFilamentCases()[$kind];
    $payload = catalogFilamentPayload($kind);
    Livewire::test($create)->fillForm($payload)->call('create')->assertHasNoFormErrors()->assertNotified();
    $record = $model::query()->where($searchField, $payload[$searchField])->firstOrFail();
    expect($record->getConnectionName())->toBe('catalog');
    Livewire::test($list)->assertCanSeeTableRecords([$record])->searchTable($payload[$searchField])->assertCanSeeTableRecords([$record]);
    Livewire::test($view, ['record' => $record->getKey()])->assertOk();
    $this->get($resource::getUrl('view', ['record' => $record]))->assertOk();
    $updated = 'Updated Filament '.$kind;
    Livewire::test($edit, ['record' => $record->getKey()])->fillForm([$searchField => $updated])->call('save')->assertHasNoFormErrors()->assertNotified();
    expect($record->fresh()->getAttribute($searchField))->toBe($updated);
    if ($kind === 'products') {
        $this->getJson('/catalog/catalog.php?action=v1.listProducts&seriesId='.$this->catalogSeries->getKey())
            ->assertOk()->assertJsonPath('data.0.name', $updated)->assertJsonPath('data.0.sku', 'FILAMENT-001');
    }
    Livewire::test($list)->callAction(TestAction::make('delete')->table($record))->assertHasNoActionErrors();
    expect($model::query()->whereKey($record->getKey())->exists())->toBeFalse();
})->with(['nodes', 'products', 'fields', 'typst', 'latex']);

it('validates required fields for every major resource before writing', function (string $kind) {
    [, , $create, , , $model, $requiredField] = catalogFilamentCases()[$kind];
    $payload = catalogFilamentPayload($kind);
    $payload[$requiredField] = '';
    $before = $model::query()->count();
    Livewire::test($create)->fillForm($payload)->call('create')->assertHasFormErrors([$requiredField => 'required']);
    expect($model::query()->count())->toBe($before);
})->with(['nodes', 'products', 'fields', 'typst', 'latex']);

it('uses navigation and direct URLs to enforce Catalog permissions', function (string $kind) {
    [$resource] = catalogFilamentCases()[$kind];
    Auth::logout();
    $this->get($resource::getUrl())->assertRedirect(route('filament.admin.auth.login'));
    $admin = User::factory()->create();
    $admin->assignRole(Role::ADMIN);
    $this->actingAs($admin)->get($resource::getUrl())->assertForbidden();
    expect($resource::canViewAny())->toBeFalse()->and($resource::getNavigationItems())->toBe([]);
    $admin->givePermissionTo('catalog.view');
    $this->get($resource::getUrl())->assertOk();
    expect($resource::canViewAny())->toBeTrue()->and($resource::getNavigationItems())->not->toBe([])
        ->and($resource::canCreate())->toBeFalse();
    $this->get($resource::getUrl('create'))->assertForbidden();
    $admin->givePermissionTo('catalog.create');
    $this->get($resource::getUrl('create'))->assertOk();
})->with(['nodes', 'products', 'fields', 'typst', 'latex']);

it('denies inactive Super Admin and every cached resource page when Catalog is disabled', function (string $kind) {
    [$resource, , , , , $model] = catalogFilamentCases()[$kind];
    $record = $resource::saveRecord($this->catalogActor, catalogFilamentPayload($kind));
    $this->catalogActor->forceFill(['is_active' => false])->save();
    expect($resource::canViewAny())->toBeFalse()->and($resource::canCreate())->toBeFalse()
        ->and($resource::canEdit($record))->toBeFalse()->and($resource::canDelete($record))->toBeFalse();
    $this->catalogActor->forceFill(['is_active' => true])->save();
    app('modules')->findOrFail('Catalog')->disable();
    expect($resource::getNavigationItems())->toBe([])->and($resource::canViewAny())->toBeFalse();
    foreach (['index', 'create', 'view', 'edit'] as $page) {
        $this->get($resource::getUrl($page, ['record' => $record]))->assertForbidden();
    }
    expect(fn () => $this->catalogAdmin->delete($this->catalogActor, $record))->toThrow(HttpException::class);
    expect($model::query()->whereKey($record->getKey())->exists())->toBeTrue();
    app('modules')->findOrFail('Catalog')->enable();
    $this->get($resource::getUrl())->assertOk();
})->with(['nodes', 'products', 'fields', 'typst', 'latex']);

it('enforces scoped product SKU uniqueness and required numeric attributes', function () {
    $field = $this->catalogAdmin->saveField($this->catalogActor, [
        ...catalogFilamentPayload('fields'), 'field_key' => 'current', 'field_type' => 'number', 'is_required' => true,
    ]);
    $payload = [...catalogFilamentPayload('products'), 'attribute_values' => [$field->getKey() => 'not numeric']];
    Livewire::test(ProductPages\CreateCatalogProduct::class)->fillForm($payload)->call('create')
        ->assertHasFormErrors(['attribute_values.'.$field->getKey() => 'numeric']);
    $payload['attribute_values'][$field->getKey()] = '12.5';
    Livewire::test(ProductPages\CreateCatalogProduct::class)->fillForm($payload)->call('create')->assertHasNoFormErrors();
    $product = CatalogProduct::query()->where('sku', $payload['sku'])->firstOrFail();
    expect(DB::connection('catalog')->table('product_custom_field_value')->where('product_id', $product->getKey())->value('value'))->toBe('12.5');
    Livewire::test(ProductPages\CreateCatalogProduct::class)->fillForm($payload)->call('create')->assertHasFormErrors(['sku' => 'unique']);
    $otherSeries = $this->catalogAdmin->saveNode($this->catalogActor, ['name' => 'Other series', 'type' => 'series', 'parent_id' => $this->catalogRoot->getKey(), 'display_order' => 0]);
    Livewire::test(ProductPages\CreateCatalogProduct::class)->fillForm([...catalogFilamentPayload('products'), 'series_id' => $otherSeries->getKey()])
        ->call('create')->assertHasNoFormErrors();
    expect(CatalogProduct::query()->where('sku', $payload['sku'])->count())->toBe(2);
});

it('supports the same field key in distinct scopes and prevents changing a field scope', function () {
    $payload = catalogFilamentPayload('fields');
    Livewire::test(FieldPages\CreateSeriesField::class)->fillForm($payload)->call('create')->assertHasNoFormErrors();
    Livewire::test(FieldPages\CreateSeriesField::class)->fillForm($payload)->call('create')->assertHasFormErrors(['field_key' => 'unique']);
    Livewire::test(FieldPages\CreateSeriesField::class)->fillForm([...$payload, 'field_scope' => 'series_metadata'])->call('create')->assertHasNoFormErrors();
    $field = SeriesField::query()->where('field_key', 'rating')->where('field_scope', 'product_attribute')->firstOrFail();
    expect(fn () => $this->catalogAdmin->saveField($this->catalogActor, [...$payload, 'field_scope' => 'series_metadata'], $field))
        ->toThrow(ValidationException::class);
    Livewire::test(FieldPages\ListSeriesFields::class)->filterTable('field_scope', 'product_attribute')->assertCanSeeTableRecords([$field]);
});

it('saves and validates series metadata through a native table action', function () {
    $field = $this->catalogAdmin->saveField($this->catalogActor, [...catalogFilamentPayload('fields'), 'field_key' => 'current', 'field_scope' => 'series_metadata', 'field_type' => 'number', 'is_required' => true]);
    try {
        $this->catalogAdmin->saveMetadata($this->catalogActor, $this->catalogSeries, []);
        test()->fail('Missing required metadata must fail domain validation.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('data.metadata_values.'.$field->getKey());
    }
    Livewire::test(NodePages\ListCatalogNodes::class)->callAction(TestAction::make('metadata')->table($this->catalogSeries), ['metadata_values' => [$field->getKey() => 'bad']])
        ->assertHasActionErrors(['metadata_values.'.$field->getKey() => 'numeric']);
    Livewire::test(NodePages\ListCatalogNodes::class)->callAction(TestAction::make('metadata')->table($this->catalogSeries), ['metadata_values' => [$field->getKey() => '48']])->assertHasNoActionErrors();
    expect(DB::connection('catalog')->table('series_custom_field_value')->where('series_custom_field_id', $field->getKey())->value('value'))->toBe('48');
    Livewire::test(NodePages\ListCatalogNodes::class)->filterTable('type', 'series')->assertCanSeeTableRecords([$this->catalogSeries])->assertCanNotSeeTableRecords([$this->catalogRoot]);
});

it('filters product and template scopes and protects Typst scope changes and dependent deletion', function () {
    $product = $this->catalogAdmin->saveProduct($this->catalogActor, catalogFilamentPayload('products'));
    Livewire::test(ProductPages\ListCatalogProducts::class)->filterTable('series_id', $this->catalogSeries->getKey())->assertCanSeeTableRecords([$product]);
    $global = $this->catalogAdmin->saveTypstTemplate($this->catalogActor, catalogFilamentPayload('typst'));
    $payload = [...catalogFilamentPayload('typst'), 'is_global' => false, 'series_id' => $this->catalogSeries->getKey(), 'title' => 'Series Typst'];
    $seriesTemplate = $this->catalogAdmin->saveTypstTemplate($this->catalogActor, $payload);
    Livewire::test(TypstPages\ListTypstTemplates::class)->filterTable('is_global', '0')->assertCanSeeTableRecords([$seriesTemplate])->assertCanNotSeeTableRecords([$global]);
    expect(fn () => $this->catalogAdmin->saveTypstTemplate($this->catalogActor, $payload, $global))->toThrow(ValidationException::class);
    expect(fn () => $this->catalogAdmin->delete($this->catalogActor, $this->catalogSeries))->toThrow(ValidationException::class);
    expect($this->catalogSeries->fresh())->not->toBeNull();
});

it('preserves readonly file values and protects file definitions with stored values', function () {
    $field = $this->catalogAdmin->saveField($this->catalogActor, [...catalogFilamentPayload('fields'), 'field_key' => 'datasheet', 'field_type' => 'file']);
    $product = $this->catalogAdmin->saveProduct($this->catalogActor, catalogFilamentPayload('products'));
    $relativePath = 'fixture/series/datasheet.pdf';
    File::ensureDirectoryExists($this->catalogStorageRoot.'/media/fixture/series');
    File::put($this->catalogStorageRoot.'/media/'.$relativePath, '%PDF-1.4 test');
    DB::connection('catalog')->table('product_custom_field_value')->insert(['product_id' => $product->getKey(), 'series_custom_field_id' => $field->getKey(), 'value' => $relativePath]);
    Livewire::test(ProductPages\EditCatalogProduct::class, ['record' => $product->getKey()])
        ->assertFormSet(['attribute_values.'.$field->getKey() => 'datasheet.pdf'])
        ->fillForm(['name' => 'File retained'])->call('save')->assertHasNoFormErrors();
    expect(DB::connection('catalog')->table('product_custom_field_value')->where('product_id', $product->getKey())->value('value'))->toBe($relativePath);
    expect(File::exists($this->catalogStorageRoot.'/media/'.$relativePath))->toBeTrue();
    expect(fn () => $this->catalogAdmin->delete($this->catalogActor, $field))->toThrow(ValidationException::class);
});

it('rejects node cycles and backend writes with only viewing permission', function () {
    $child = $this->catalogAdmin->saveNode($this->catalogActor, ['name' => 'Child category', 'type' => 'category', 'parent_id' => $this->catalogRoot->getKey(), 'display_order' => 0]);
    expect(fn () => $this->catalogAdmin->saveNode($this->catalogActor, ['name' => 'Cycle', 'type' => 'category', 'parent_id' => $child->getKey(), 'display_order' => 0], $this->catalogRoot))->toThrow(ValidationException::class);
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('catalog.view');
    expect(fn () => $this->catalogAdmin->saveProduct($viewer, catalogFilamentPayload('products')))->toThrow(AuthorizationException::class);
    expect(fn () => $this->catalogAdmin->delete($viewer, $child))->toThrow(AuthorizationException::class);
});
