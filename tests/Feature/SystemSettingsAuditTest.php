<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\IAM\Filament\Resources\Users\Pages\ListUsers;
use Modules\IAM\Models\Permission;
use Modules\System\Filament\Pages\ActivityLog;
use Modules\System\Filament\Pages\SystemSettingsPage;
use Modules\System\Services\SettingsManager;
use Modules\System\Settings\SystemSettings;
use Spatie\Activitylog\Models\Activity;
use Spatie\LaravelSettings\Models\SettingsProperty;

function grantSystemPermissions(User $user, string ...$permissionNames): void
{
    $permissions = array_map(
        static fn (string $name): Permission => Permission::findOrCreate($name, 'web'),
        $permissionNames,
    );

    $user->givePermissionTo($permissions);
}

/**
 * @return array{
 *     application_name: string,
 *     application_description: string,
 *     timezone: string,
 *     default_locale: string,
 *     pagination_size: int,
 *     default_ai_provider: string,
 *     default_ai_model: string
 * }
 */
function validSystemSettingsPayload(): array
{
    return [
        'application_name' => 'System Control Room',
        'application_description' => 'Administration settings for the platform.',
        'timezone' => 'Pacific/Honolulu',
        'default_locale' => 'fr',
        'pagination_size' => 30,
        'default_ai_provider' => 'anthropic',
        'default_ai_model' => 'claude-sonnet',
    ];
}

it('persists only validated settings and records explicit safe old and new values', function () {
    config(['enterprise.locales' => ['en', 'fr']]);

    $admin = User::factory()->create();
    grantSystemPermissions($admin, 'settings.update');
    $this->actingAs($admin);

    $previousValues = app(SystemSettings::class)->toArray();
    $input = validSystemSettingsPayload();

    $savedSettings = app(SettingsManager::class)->save($input);
    $activity = Activity::query()
        ->inLog('administration')
        ->where('event', 'settings.updated')
        ->latest('id')
        ->firstOrFail();

    expect($savedSettings->toArray())->toBe($input);
    expect(app(SystemSettings::class)->refresh()->toArray())->toBe($input);
    expect($activity->properties->toArray())->toEqual([
        'old' => $previousValues,
        'new' => $input,
    ]);
    expect(array_keys($activity->properties->toArray()))->toEqualCanonicalizing(['old', 'new']);
});

it('rejects secret or unexpected keys without changing settings or writing an audit row', function () {
    $admin = User::factory()->create();
    grantSystemPermissions($admin, 'settings.update');
    $this->actingAs($admin);

    $previousValues = app(SystemSettings::class)->toArray();
    $input = [...validSystemSettingsPayload(), 'openai_api_key' => 'must-not-be-stored'];
    $exception = null;

    try {
        app(SettingsManager::class)->save($input);
    } catch (ValidationException $validationException) {
        $exception = $validationException;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class);
    expect($exception->errors())->toHaveKey('openai_api_key');
    expect(app(SystemSettings::class)->refresh()->toArray())->toBe($previousValues);
    expect(Activity::query()->inLog('administration')->count())->toBe(0);
    expect(SettingsProperty::query()->where('payload', 'like', '%must-not-be-stored%')->exists())->toBeFalse();
});

it('rejects values outside the settings validation rules', function (string $field, mixed $value) {
    $admin = User::factory()->create();
    grantSystemPermissions($admin, 'settings.update');
    $this->actingAs($admin);

    $input = validSystemSettingsPayload();
    $input[$field] = $value;
    $exception = null;

    try {
        app(SettingsManager::class)->save($input);
    } catch (ValidationException $validationException) {
        $exception = $validationException;
    }

    expect($exception)->toBeInstanceOf(ValidationException::class);
    expect($exception->errors())->toHaveKey($field);
})->with([
    'timezone is not a known identifier' => ['timezone', 'Mars/Phobos'],
    'locale is not configured' => ['default_locale', 'de'],
    'pagination is below the minimum' => ['pagination_size', 4],
    'pagination is above the maximum' => ['pagination_size', 101],
    'AI provider is unsupported' => ['default_ai_provider', 'ollama'],
    'AI model exceeds 100 characters' => ['default_ai_model', str_repeat('m', 101)],
    'application name exceeds 255 characters' => ['application_name', str_repeat('n', 256)],
    'description exceeds 1000 characters' => ['application_description', str_repeat('d', 1001)],
    'setting values must be scalar strings' => ['application_name', ['unexpected']],
]);

it('lets settings viewers read disabled fields but forbids a tampered save with 403', function () {
    $viewer = User::factory()->create();
    grantSystemPermissions($viewer, 'access.admin', 'settings.view');

    $this->actingAs($viewer)
        ->get('/admin/system/settings')
        ->assertOk()
        ->assertSee('System Settings')
        ->assertSeeHtml('disabled');

    Livewire::actingAs($viewer)
        ->test(SystemSettingsPage::class)
        ->set('data.application_name', 'Tampered by Livewire')
        ->call('save')
        ->assertStatus(403);

    expect(app(SystemSettings::class)->refresh()->application_name)->toBe(config('app.name'));
    expect(Activity::query()->inLog('administration')->count())->toBe(0);
});

it('forbids users without settings.view from opening the settings page', function () {
    $user = User::factory()->create();
    grantSystemPermissions($user, 'access.admin');

    $this->actingAs($user)
        ->get('/admin/system/settings')
        ->assertForbidden();
});

it('shows only safe system details to users with system-info.view', function () {
    config([
        'database.connections.sqlite.password' => 'database-password-must-not-render',
        'services.openai.key' => 'provider-key-must-not-render',
    ]);

    $viewer = User::factory()->create();
    grantSystemPermissions($viewer, 'access.admin', 'system-info.view');

    $this->actingAs($viewer)
        ->get('/admin/system/information')
        ->assertOk()
        ->assertSee((string) config('enterprise.version'))
        ->assertSee(PHP_VERSION)
        ->assertSee((string) config('database.default'))
        ->assertDontSee('database-password-must-not-render')
        ->assertDontSee('provider-key-must-not-render');
});

it('forbids users without system-info.view from opening system information', function () {
    $user = User::factory()->create();
    grantSystemPermissions($user, 'access.admin');

    $this->actingAs($user)
        ->get('/admin/system/information')
        ->assertForbidden();
});

it('shows administration activity and only allowlisted properties in its read-only modal', function () {
    $viewer = User::factory()->create();
    grantSystemPermissions($viewer, 'access.admin', 'activity-log.view');

    activity('administration')
        ->event('settings.updated')
        ->withProperties([
            'module' => 'ExampleModule',
            'client_secret' => 'must-not-render-from-audit',
            'old' => [
                'application_name' => 'Old platform name',
                'openai_api_key' => 'must-not-render-from-audit',
            ],
            'new' => [
                'application_name' => 'New platform name',
                'openai_api_key' => 'must-not-render-from-audit',
            ],
        ])
        ->log('System settings updated');

    $administrationActivity = Activity::query()->inLog('administration')->firstOrFail();
    activity('default')
        ->withProperties(['openai_api_key' => 'unrelated-secret'])
        ->log('Unrelated activity');
    $unrelatedActivity = Activity::query()->where('log_name', 'default')->firstOrFail();

    $test = Livewire::actingAs($viewer)
        ->test(ActivityLog::class)
        ->assertCanSeeTableRecords([$administrationActivity])
        ->assertCanNotSeeTableRecords([$unrelatedActivity])
        ->mountTableAction('safeProperties', (string) $administrationActivity->getKey())
        ->assertSet('mountedActions.0.name', 'safeProperties');

    $modalHtml = $test->getMountedActionModalHtml();

    expect($modalHtml)
        ->toContain('Safe activity properties', 'Old platform name', 'New platform name', 'ExampleModule')
        ->not->toContain('must-not-render-from-audit', 'unrelated-secret');
});

it('forbids users without activity-log.view from opening the activity log', function () {
    $user = User::factory()->create();
    grantSystemPermissions($user, 'access.admin');

    $this->actingAs($user)
        ->get('/admin/system/activity-log')
        ->assertForbidden();
});

it('applies a configured pagination size to module-owned Filament resources', function () {
    $admin = User::factory()->create();
    grantSystemPermissions($admin, 'access.admin', 'settings.update', 'users.view');
    $this->actingAs($admin);

    app(SettingsManager::class)->save([
        ...validSystemSettingsPayload(),
        'default_locale' => 'en',
        'pagination_size' => 33,
    ]);

    Livewire::actingAs($admin)
        ->test(ListUsers::class)
        ->assertSet('tableRecordsPerPage', 33);
});
