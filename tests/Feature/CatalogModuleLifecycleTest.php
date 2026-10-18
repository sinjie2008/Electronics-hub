<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use Modules\Catalog\Http\ModuleRoutes;
use Modules\System\Services\ModuleManager;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Tests\Support\CatalogLifecycleProbe;
use Tests\Support\CatalogTestDatabase;
use Tests\Support\PublicProductApiFixture;

beforeEach(function () {
    $this->withoutVite();
    $this->lifecycleDirectory = storage_path('framework/testing/catalog-lifecycle-'.bin2hex(random_bytes(8)));
    File::ensureDirectoryExists($this->lifecycleDirectory);
    $this->lifecycleOriginalStatuses = config('modules.activators.file.statuses-file');
    $this->lifecycleStatuses = $this->lifecycleDirectory.'/statuses.json';
    File::put($this->lifecycleStatuses, json_encode([
        'Core' => true, 'IAM' => true, 'System' => true, 'Catalog' => true,
    ], JSON_THROW_ON_ERROR));
    config([
        'modules.activators.file.statuses-file' => $this->lifecycleStatuses,
        'catalog.project_root_urls' => true,
        'catalog.public_api.requests_per_minute' => 10000,
    ]);
    app()->forgetInstance(ActivatorInterface::class);
    ModuleRoutes::register();
});

afterEach(function () {
    CatalogTestDatabase::cleanup($this->lifecycleStorage ?? null);
    config(['modules.activators.file.statuses-file' => $this->lifecycleOriginalStatuses]);
    app()->forgetInstance(ActivatorInterface::class);
    File::deleteDirectory($this->lifecycleDirectory);
});

it('returns 404 for every registered Catalog surface after disabling in a running application and preserves data for reenable', function () {
    $this->lifecycleStorage = CatalogTestDatabase::prepare();
    PublicProductApiFixture::seed();
    CatalogLifecycleProbe::seedTemplatesAndFiles($this->lifecycleStorage);
    $this->seed();
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    $manager = app(ModuleManager::class);
    $activator = app(ActivatorInterface::class);
    $module = app('modules')->findOrFail('Catalog');
    $filamentUris = CatalogLifecycleProbe::filamentUris();

    $this->actingAs($actor)->getJson('/catalog/api/catalog/hierarchy.php')
        ->assertOk()->assertJsonPath('data.0.id', 100);
    $this->get($filamentUris['product view'])->assertOk()->assertSee('SW-10');
    $this->get('/catalog/storage/media/lifecycle.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    Auth::logout();
    foreach (CatalogLifecycleProbe::publicUris() as $uri) {
        $this->getJson($uri)->assertOk();
    }
    foreach (CatalogLifecycleProbe::managementUris() as $uri) {
        expect($uri.' '.$this->getJson($uri)->getStatusCode())->toBe($uri.' 401');
    }
    foreach (CatalogLifecycleProbe::assetUris() as $uri) {
        $this->get($uri)->assertOk();
    }
    $before = CatalogLifecycleProbe::fingerprint($this->lifecycleStorage);

    $manager->disable($actor, 'Catalog');

    expect($module->isEnabled())->toBeFalse();
    expect(json_decode(File::get($this->lifecycleStatuses), true, flags: JSON_THROW_ON_ERROR)['Catalog'])->toBeFalse();
    expect(app(ActivatorInterface::class))->toBe($activator);
    foreach ([...CatalogLifecycleProbe::ownedUris(), ...array_values($filamentUris)] as $uri) {
        $this->getJson($uri)->assertNotFound()->assertHeaderMissing('Location')->assertHeaderMissing('Retry-After');
    }
    foreach (['/catalog/catalog.php?action=v1.createNode', '/catalog/api/catalog/truncate.php', '/public/api/catalog/csv-import.php'] as $uri) {
        $this->postJson($uri, ['name' => 'Disabled write'])->assertNotFound();
    }
    $this->withHeaders(['Origin' => 'https://consumer.example', 'Access-Control-Request-Method' => 'GET'])
        ->options('/api/tree')->assertNotFound();
    $this->flushHeaders();
    $this->getJson('/api/v1/health')->assertOk();
    $this->get('/login')->assertOk();
    $this->actingAs($actor);
    foreach ($filamentUris as $uri) {
        $this->get($uri)->assertNotFound();
    }
    $this->actingAs($actor)->get('/admin/users')->assertOk();
    $this->get('/admin/system/modules')->assertOk();
    foreach (['Core', 'IAM', 'System'] as $name) {
        expect(app('modules')->isEnabled($name))->toBeTrue();
    }
    expect(CatalogLifecycleProbe::fingerprint($this->lifecycleStorage))->toBe($before);

    $manager->enable($actor, 'Catalog');

    expect($module->isEnabled())->toBeTrue();
    expect(app(ActivatorInterface::class))->toBe($activator);
    expect(json_decode(File::get($this->lifecycleStatuses), true, flags: JSON_THROW_ON_ERROR)['Catalog'])->toBeTrue();
    $this->getJson('/catalog/api/catalog/hierarchy.php')->assertOk()->assertJsonPath('data.0.id', 100);
    $this->get($filamentUris['product view'])->assertOk()->assertSee('SW-10');
    $this->get('/catalog/storage/media/lifecycle.pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');
    Auth::logout();
    $this->getJson('/api/resolve/'.PublicProductApiFixture::SERIES_PATH)->assertOk()->assertJsonPath('data.resource.id', 110);
    $this->getJson('/api/series/'.PublicProductApiFixture::SERIES_PATH.'/parts')->assertOk()->assertJsonPath('data.parts.0.id', 2003);
    expect(CatalogLifecycleProbe::fingerprint($this->lifecycleStorage))->toBe($before);
});

it('returns 404 on fresh HTTP boot after disabling and restores public reads with stable IDs using :dataset', function (bool $cached) {
    $this->lifecycleStorage = CatalogTestDatabase::prepare();
    PublicProductApiFixture::seed();
    CatalogLifecycleProbe::seedTemplatesAndFiles($this->lifecycleStorage);
    $probe = new CatalogLifecycleProbe($this->lifecycleDirectory, $cached);
    $before = CatalogLifecycleProbe::fingerprint($this->lifecycleStorage);
    $activator = app(ActivatorInterface::class);
    if ($cached) {
        $probe->cacheEnabledApplication();
    }
    $enabled = $probe->request(CatalogLifecycleProbe::ownedUris());
    foreach ($enabled['responses'] as $uri => $response) {
        $status = in_array($uri, CatalogLifecycleProbe::managementUris(), true) ? 401 : 200;
        expect($uri.' '.$response['status'])->toBe($uri.' '.$status);
    }
    expect($enabled['routes_cached'])->toBe($cached);
    expect($enabled['config_cached'])->toBe($cached);

    $activator->setActiveByName('Catalog', false);

    expect(app('modules')->isEnabled('Catalog'))->toBeFalse();
    $disabled = $probe->request([
        ...CatalogLifecycleProbe::ownedUris(), ...array_values(CatalogLifecycleProbe::filamentUris()),
        '/api/v1/health', '/login', '/admin/users', '/admin/system/modules',
    ]);
    expect($disabled['catalog_enabled'])->toBeFalse();
    expect($disabled['routes_cached'])->toBe($cached);
    expect($disabled['config_cached'])->toBe($cached);
    foreach ([...CatalogLifecycleProbe::ownedUris(), ...array_values(CatalogLifecycleProbe::filamentUris())] as $uri) {
        expect($disabled['responses'][$uri]['status'], $uri)->toBe(404);
        expect($disabled['responses'][$uri]['location'], $uri)->toBeNull();
    }
    expect($disabled['responses']['/api/v1/health']['status'])->toBe(200);
    expect($disabled['responses']['/login']['status'])->toBe(200);
    expect($disabled['responses']['/admin/users']['status'])->toBe(302);
    expect($disabled['responses']['/admin/system/modules']['status'])->toBe(302);
    expect($disabled['protected_modules'])->toBe(['Core' => true, 'IAM' => true, 'System' => true]);
    expect(CatalogLifecycleProbe::fingerprint($this->lifecycleStorage))->toBe($before);

    $activator->setActiveByName('Catalog', true);

    $reenabled = $probe->request(['/api/resolve/'.PublicProductApiFixture::SERIES_PATH, '/api/series/'.PublicProductApiFixture::SERIES_PATH.'/parts']);
    expect($reenabled['catalog_enabled'])->toBeTrue();
    expect($reenabled['responses']['/api/resolve/'.PublicProductApiFixture::SERIES_PATH]['json']['data']['resource']['id'])->toBe(110);
    expect($reenabled['responses']['/api/series/'.PublicProductApiFixture::SERIES_PATH.'/parts']['json']['data']['parts'][0]['id'])->toBe(2003);
    expect(CatalogLifecycleProbe::fingerprint($this->lifecycleStorage))->toBe($before);
})->with(['uncached routes and config' => false, 'cached routes and config built while enabled' => true]);
