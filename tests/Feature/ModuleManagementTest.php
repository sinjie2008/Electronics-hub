<?php

use App\Models\User;
use App\Modules\ProtectedModuleActivator;
use Filament\Facades\Filament;
use Filament\Panel;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\IAM\Filament\Resources\Users\UserResource;
use Modules\Optional\Filament\Resources\OptionalResource;
use Modules\System\Filament\Pages\ModuleManagement;
use Modules\System\Services\ModuleManager;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Facades\Module;
use Spatie\Activitylog\Models\Activity;
use Tests\Fixtures\OptionalPlugin;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    $this->moduleStatuses = storage_path('framework/testing/modules-'.uniqid().'.json');
    File::ensureDirectoryExists(dirname($this->moduleStatuses));
    File::put($this->moduleStatuses, json_encode(['Core' => true, 'IAM' => true, 'System' => true, 'Optional' => false, 'Dependent' => false]));
    config([
        'modules.scan.enabled' => true,
        'modules.scan.paths' => [base_path('tests/Fixtures/modules')],
        'modules.activators.file.statuses-file' => $this->moduleStatuses,
    ]);
    app()->forgetInstance(ActivatorInterface::class);
    $this->actor = User::factory()->create();
    $this->actor->assignRole('Super Admin');
    $this->manager = app(ModuleManager::class);
});

afterEach(function () {
    File::delete($this->moduleStatuses, $this->moduleStatuses.'.lock');
});

it('lists module metadata and the real enabled and disabled states', function () {
    $modules = collect($this->manager->list($this->actor))->keyBy('name');
    expect($modules->keys()->all())->toContain('Core', 'IAM', 'System', 'Optional');
    expect($modules['Core']['enabled'])->toBeTrue()
        ->and($modules['Core']['protected'])->toBeTrue()
        ->and($modules['Optional']['enabled'])->toBeFalse()
        ->and($modules['Optional']['version'])->toBe('1.2.3')
        ->and($modules['Optional']['dependencies'])->toBe(['Core']);
});

it('enables and disables optional modules and records both administrative actions', function () {
    $this->manager->enable($this->actor, 'Optional');
    expect(Module::findOrFail('Optional')->isEnabled())->toBeTrue();
    $this->manager->disable($this->actor, 'Optional');
    expect(Module::findOrFail('Optional')->isDisabled())->toBeTrue();
    expect(Activity::query()->where('event', 'module.enabled')->exists())->toBeTrue()
        ->and(Activity::query()->where('event', 'module.disabled')->exists())->toBeTrue();
});

it('prevents protected modules from being disabled through UI services or the native activator', function (string $name) {
    expect(fn () => $this->manager->disable($this->actor, $name))->toThrow(ValidationException::class);
    expect(fn () => Module::findOrFail($name)->disable())->toThrow(ValidationException::class);
    expect(Module::findOrFail($name)->isEnabled())->toBeTrue();
})->with(ProtectedModuleActivator::PROTECTED);

it('keeps protected modules enabled even if the status file was tampered with', function () {
    File::put($this->moduleStatuses, json_encode(['Core' => false, 'IAM' => false, 'System' => false]));
    foreach (ProtectedModuleActivator::PROTECTED as $name) {
        expect(Module::findOrFail($name)->isEnabled())->toBeTrue();
    }
});

it('requires enabled dependencies and prevents disabling an active dependency', function () {
    expect(fn () => $this->manager->enable($this->actor, 'Dependent'))->toThrow(ValidationException::class);
    $this->manager->enable($this->actor, 'Optional');
    $this->manager->enable($this->actor, 'Dependent');
    expect(fn () => $this->manager->disable($this->actor, 'Optional'))->toThrow(ValidationException::class);
    $this->manager->disable($this->actor, 'Dependent');
    $this->manager->disable($this->actor, 'Optional');
    expect(Module::findOrFail('Optional')->isDisabled())->toBeTrue();
});

it('authorizes listing and each state-changing action separately', function () {
    $restricted = User::factory()->create();
    expect(fn () => $this->manager->list($restricted))->toThrow(AuthorizationException::class);
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    expect($this->manager->list($admin))->not->toBeEmpty();
    expect(fn () => $this->manager->enable($admin, 'Optional'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->manager->disable($admin, 'Optional'))->toThrow(AuthorizationException::class);
});

it('enforces module permissions on HTTP and tampered Livewire calls', function () {
    $this->get('/admin/system/modules')->assertRedirect('/admin/login');
    $restricted = User::factory()->create();
    $this->actingAs($restricted)->get('/admin/system/modules')->assertForbidden();
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $this->actingAs($admin)->get('/admin/system/modules')->assertOk()->assertSee('Protected system module');
    Livewire::test(ModuleManagement::class)->call('enableModule', 'Optional')->assertForbidden();
    expect(Module::findOrFail('Optional')->isDisabled())->toBeTrue();
});

it('discovers real IAM and System Filament components through coolsam modules', function () {
    $panel = Filament::getPanel('admin');
    expect($panel->hasPlugin('modules'))->toBeTrue()
        ->and($panel->hasPlugin('iam'))->toBeTrue()
        ->and($panel->hasPlugin('system'))->toBeTrue()
        ->and($panel->getResources())->toContain(UserResource::class)
        ->and($panel->getPages())->toContain(ModuleManagement::class);
});

it('returns 404 for disabled Catalog routes and unsupported resource URLs even for a Super Admin', function (string $path) {
    $this->actingAs($this->actor)->get($path)->assertNotFound();
})->with([
    'catalog page' => '/catalog/catalog_ui.html',
    'legacy catalog API' => '/catalog.php?action=v1.category.list',
    'legacy Typst API' => '/api/typst/templates.php',
    'Filament catalog' => '/admin/catalog',
    'Filament CSV' => '/admin/catalog/csv',
    'Filament search' => '/admin/catalog/spec-search',
    'Filament LaTeX' => '/admin/catalog/latex-templating',
    'Filament global Typst' => '/admin/catalog/global-typst-template',
    'Filament series Typst' => '/admin/catalog/series-typst-template',
    'admin categories and series' => '/admin/nodes/catalog-nodes',
    'admin products' => '/admin/products/catalog-products',
    'admin series fields' => '/admin/series-fields',
    'admin Typst templates' => '/admin/typst-templates',
    'admin LaTeX templates' => '/admin/latex-templates',
]);

it('discovers optional module resources only while that module is enabled', function () {
    require_once base_path('tests/Fixtures/modules/Optional/app/Filament/Resources/OptionalResource.php');
    $disabled = Panel::make()->id('disabled-optional-test');
    OptionalPlugin::make()->register($disabled);
    expect($disabled->getResources())->toBe([]);
    $this->manager->enable($this->actor, 'Optional');
    $enabled = Panel::make()->id('enabled-optional-test');
    OptionalPlugin::make()->register($enabled);
    expect($enabled->getResources())->toContain(OptionalResource::class);
});

it('normalizes case variants when enforcing dependencies and protected status', function () {
    $this->manager->enable($this->actor, 'OPTIONAL');
    $this->manager->enable($this->actor, 'dependent');
    expect(fn () => $this->manager->disable($this->actor, 'OPTIONAL'))->toThrow(ValidationException::class);
    expect(fn () => app(ActivatorInterface::class)->setActiveByName('CORE', false))->toThrow(ValidationException::class);
    expect(Module::findOrFail('Optional')->isEnabled())->toBeTrue();
});

it('compensates module state when audit persistence fails', function () {
    Event::listen('eloquent.creating: '.Activity::class, function (): void {
        throw new RuntimeException('Simulated audit persistence failure');
    });
    expect(fn () => $this->manager->enable($this->actor, 'Optional'))->toThrow(RuntimeException::class);
    expect(Module::findOrFail('Optional')->isDisabled())->toBeTrue();
    expect(Activity::query()->where('event', 'module.enabled')->count())->toBe(0);
});
