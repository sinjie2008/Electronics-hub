<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Modules\System\Services\BackupManager;
use Spatie\Backup\Config\Config as BackupConfig;

beforeEach(function () {
    if (! filter_var(getenv('RUN_REDIS_INTEGRATION'), FILTER_VALIDATE_BOOLEAN)) {
        $this->markTestSkipped('Set RUN_REDIS_INTEGRATION=true with a disposable Redis service to run these integration tests.');
    }
    $this->seed();
    $this->withoutVite();
});

it('uses real Redis caching and mutually exclusive locks', function () {
    $cache = Cache::store('redis');
    $key = 'integration-test:'.uniqid();
    $cache->put($key, 'safe value', 60);
    expect($cache->get($key))->toBe('safe value');
    $lock = $cache->lock($key.':lock', 60);
    expect($lock->get())->toBeTrue()
        ->and($cache->lock($key.':lock', 60)->get())->toBeFalse();
    $lock->release();
    $cache->forget($key);
});

it('authenticates a browser session stored in real Redis', function () {
    config(['session.driver' => 'redis']);
    app()->forgetInstance('session');
    app()->forgetInstance('session.store');
    Auth::forgetGuards();
    $admin = User::factory()->create(['password' => 'Redis-Session-Pass-6428']);
    $admin->assignRole('Admin');
    $this->post('/login', ['email' => $admin->email, 'password' => 'Redis-Session-Pass-6428'])->assertRedirect('/');
    $this->assertAuthenticatedAs($admin);
    $this->get('/admin')->assertOk();
    $this->post('/logout')->assertRedirect('/');
    $this->assertGuest();
});

it('runs a real Redis queued backup through the Laravel worker', function () {
    Storage::fake('backups');
    $directory = storage_path('app/private/redis-backup-test-'.uniqid());
    File::ensureDirectoryExists($directory);
    File::put($directory.'/sample.txt', 'Redis queue backup test');
    config([
        'queue.default' => 'redis', 'cache.default' => 'redis',
        'backup.backup.source.files.include' => [$directory],
        'backup.backup.destination.disks' => ['backups'],
    ]);
    app()->forgetInstance('queue');
    app()->forgetInstance('queue.connection');
    BackupConfig::rebind();
    app(Kernel::class)->setArtisan(null);
    $actor = User::factory()->create();
    $actor->assignRole('Super Admin');
    try {
        $run = app(BackupManager::class)->request($actor, 'files');
        expect($run->refresh()->status)->toBe('queued');
        expect(Artisan::call('queue:work', ['connection' => 'redis', '--queue' => 'backups', '--once' => true, '--timeout' => 900]))->toBe(0);
        expect($run->refresh()->status)->toBe('completed')
            ->and(Storage::disk('backups')->exists($run->archive_path))->toBeTrue();
    } finally {
        File::deleteDirectory($directory);
    }
});
