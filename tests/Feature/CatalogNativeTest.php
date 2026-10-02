<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\File;
use Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Tests\TestCase;

beforeEach(function (): void {
    $this->withoutVite();
    $this->moduleStatuses = storage_path('framework/testing/catalog-native-'.uniqid().'.json');
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

it('serializes only approved scalar deep-link values into native workspace configuration', function (string $path, array $query, array $present, array $absent): void {
    $actor = catalogNativeUser($this);

    $response = $this->actingAs($actor, 'web')
        ->get($path.'?'.http_build_query($query))
        ->assertOk()
        ->assertSee('catalogWorkspace(', false);

    $content = $response->getContent();

    foreach ($present as $value) {
        expect($content)->toContain($value);
    }

    foreach ($absent as $value) {
        expect($content)->not->toContain($value);
    }
})->with([
    'catalog page escapes hostile category input and drops non-scalar or unknown keys' => [
        '/admin/catalog',
        [
            'category' => '<script>alert(1)</script>',
            'product' => 'native-product-93',
            'series' => ['array-sentinel'],
            'unexpected' => 'discard-me',
        ],
        ['native-product-93', 'u003Cscript'],
        ['<script>alert(1)</script>', 'array-sentinel', 'discard-me'],
    ],
    'series template keeps both approved series identifiers' => [
        '/admin/catalog/series-typst-template',
        [
            'series_id' => '9821',
            'seriesId' => '9822',
            'unexpected' => 'discard-me',
        ],
        ['9821', '9822'],
        ['discard-me'],
    ],
]);

function catalogNativeUser(TestCase $test): User
{
    $test->seed([RolesAndPermissionsSeeder::class, CatalogDatabaseSeeder::class]);

    $user = User::factory()->create();
    $user->givePermissionTo(['access.admin', 'catalog.view']);

    return $user;
}
