<?php

use App\Models\User;
use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Laravel\Telescope\TelescopeServiceProvider;
use Laravel\Telescope\Watchers\CacheWatcher;
use Laravel\Telescope\Watchers\CommandWatcher;
use Laravel\Telescope\Watchers\DumpWatcher;
use Laravel\Telescope\Watchers\EventWatcher;
use Laravel\Telescope\Watchers\JobWatcher;
use Laravel\Telescope\Watchers\MailWatcher;
use Laravel\Telescope\Watchers\NotificationWatcher;
use Laravel\Telescope\Watchers\RedisWatcher;
use Modules\IAM\Models\Permission;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
});

it('restricts opt-in local Telescope and redacts request secrets while omitting payload watchers', function () {
    $this->app->detectEnvironment(fn (): string => 'local');
    config([
        'telescope.enabled' => true,
        'telescope.storage.database.connection' => config('database.default'),
    ]);

    (new AppServiceProvider($this->app))->register();

    expect(app()->environment())->toBe('local')
        ->and(app()->providerIsLoaded(TelescopeServiceProvider::class))->toBeTrue();

    $this->get('/telescope')->assertForbidden();

    $unprivileged = User::factory()->create();
    $this->actingAs($unprivileged, 'web')->get('/telescope')->assertForbidden();

    $viewer = User::factory()->create();
    $viewer->givePermissionTo(Permission::findOrCreate('system-info.view', 'web'));
    $this->actingAs($viewer, 'web')->get('/telescope')->assertOk();

    $sensitiveWatchers = [
        CacheWatcher::class,
        CommandWatcher::class,
        DumpWatcher::class,
        EventWatcher::class,
        JobWatcher::class,
        MailWatcher::class,
        NotificationWatcher::class,
        RedisWatcher::class,
    ];

    foreach ($sensitiveWatchers as $watcher) {
        $options = config("telescope.watchers.{$watcher}");
        $enabled = is_array($options) ? ($options['enabled'] ?? true) : $options;

        expect($enabled)->toBeFalse("{$watcher} should be disabled by default");
    }

    $marker = 'telescope-secret-marker-'.bin2hex(random_bytes(12));
    Route::post('/__telescope-redaction-probe', fn () => response()->json(['ok' => true]));

    $this->postJson('/__telescope-redaction-probe', [
        'password' => $marker,
        'safe_field' => 'ordinary-value',
    ], [
        'Authorization' => 'Bearer '.$marker,
    ])->assertOk()->assertExactJson(['ok' => true]);

    Cache::put('telescope-sensitive-test-value', $marker, 60);
    event('telescope.sensitive.payload', [$marker]);

    $request = DB::table('telescope_entries')
        ->where('type', 'request')
        ->where('content', 'like', '%__telescope-redaction-probe%')
        ->orderByDesc('sequence')
        ->first();

    expect($request)->not->toBeNull();

    $content = json_decode($request->content, true, flags: JSON_THROW_ON_ERROR);

    expect($content['payload']['password'] ?? null)->toBe('********')
        ->and($content['payload']['safe_field'] ?? null)->toBe('ordinary-value')
        ->and($content['headers']['authorization'] ?? null)->toBe('********');

    $storedEntries = DB::table('telescope_entries')->pluck('content')->implode("\n");

    expect($storedEntries)->not->toContain($marker)
        ->and(DB::table('telescope_entries')->where('type', 'cache')->exists())->toBeFalse()
        ->and(DB::table('telescope_entries')->where('type', 'event')->exists())->toBeFalse();
});
