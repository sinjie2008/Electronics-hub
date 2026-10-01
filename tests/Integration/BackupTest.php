<?php

use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Modules\System\Filament\Pages\Backups;
use Modules\System\Jobs\RunBackup;
use Modules\System\Models\BackupRun;
use Modules\System\Services\BackupManager;
use Spatie\Activitylog\Models\Activity;
use Spatie\Backup\Config\Config as BackupConfig;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    $this->seed();
    $this->withoutVite();
    Storage::fake('backups');
    $this->sourceDirectory = storage_path('app/private/backup-test-'.uniqid());
    File::ensureDirectoryExists($this->sourceDirectory);
    File::put($this->sourceDirectory.'/sample.txt', 'Private application test content');
    config([
        'backup.backup.source.files.include' => [$this->sourceDirectory],
        'backup.backup.source.files.relative_path' => dirname($this->sourceDirectory),
        'backup.backup.destination.disks' => ['backups'],
    ]);
    BackupConfig::rebind();
    app(Kernel::class)->setArtisan(null);
    $this->actor = User::factory()->create();
    $this->actor->assignRole('Super Admin');
    $this->manager = app(BackupManager::class);
});

afterEach(function () {
    File::deleteDirectory($this->sourceDirectory);
});

it('creates a real files archive through the queued backup workflow and reports health', function () {
    $run = $this->manager->request($this->actor, 'files')->refresh();
    expect($run->status)->toBe('completed')
        ->and(Storage::disk('backups')->exists($run->archive_path))->toBeTrue();
    $archives = $this->manager->archives($this->actor);
    expect($archives)->toHaveCount(1)
        ->and($archives[0]['size'])->toBeGreaterThan(0)
        ->and($this->manager->health($this->actor)[0]['healthy'])->toBeTrue();
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('backups')->path($run->archive_path)))->toBeTrue();
    $content = $zip->getFromIndex(0);
    $zip->close();
    expect($content)->toBe('Private application test content');
    $activity = Activity::query()->where('event', 'backup.initiated')->sole();
    expect($activity->properties->all())->toEqual(['type' => 'files', 'disk' => 'backups']);
});

it('runs actual MySQL database and full backups on Linux with mysqldump', function (string $type) {
    if (PHP_OS_FAMILY !== 'Linux' || config('database.default') !== 'mysql') {
        $this->markTestSkipped('MySQL backup integration is exercised by the Linux/MySQL CI job.');
    }
    $run = $this->manager->request($this->actor, $type)->refresh();
    expect($run->status)->toBe('completed');
    $zip = new ZipArchive;
    expect($zip->open(Storage::disk('backups')->path($run->archive_path)))->toBeTrue();
    $dump = null;
    for ($index = 0; $index < $zip->numFiles; $index++) {
        if (str_ends_with($zip->getNameIndex($index), '.sql')) {
            $dump = $zip->getFromIndex($index);
        }
    }
    $zip->close();
    expect($dump)->toBeString()->toContain('CREATE TABLE `users`');
    expect($this->manager->health($this->actor)[0]['reachable'])->toBeTrue();
})->with(['database', 'full']);

it('queues the backup on the backups queue and rejects unauthorized or invalid requests', function () {
    Queue::fake();
    $run = $this->manager->request($this->actor, 'database');
    expect($run->status)->toBe('queued');
    Queue::assertPushedOn('backups', RunBackup::class);
    $restricted = User::factory()->create();
    expect(fn () => $this->manager->request($restricted, 'files'))->toThrow(AuthorizationException::class);
    expect(fn () => $this->manager->request($this->actor, 'restore'))->toThrow(ValidationException::class);
});

it('keeps a backup queued after more than ten held overlap releases', function () {
    config(['queue.default' => 'database']);
    app()->forgetInstance('queue');

    $run = BackupRun::create([
        'user_id' => $this->actor->getKey(), 'type' => 'files', 'source' => 'admin',
        'disk' => 'backups', 'status' => 'queued',
    ]);

    $this->travelTo(Carbon::parse('2026-10-01 00:00:00'));
    try {
        RunBackup::dispatch((string) $run->getKey())->onQueue('backups')->beforeCommit();
        $queuedJob = DB::table('jobs')->where('queue', 'backups')->sole();
        $payload = json_decode($queuedJob->payload, true, flags: JSON_THROW_ON_ERROR);
        $dispatchTime = Carbon::now();

        expect($payload['maxTries'])->toBe(0)
            ->and($payload['retryUntil'])->toBe($dispatchTime->copy()->addHours(2)->getTimestamp());

        $overlapLock = Cache::lock('laravel-queue-overlap:backup-run', 960);
        expect($overlapLock->get())->toBeTrue();

        for ($attempt = 1; $attempt <= 11; $attempt++) {
            expect(Artisan::call('queue:work', [
                'connection' => 'database', '--queue' => 'backups', '--once' => true,
                '--timeout' => 900, '--tries' => 1,
            ]))->toBe(0);

            expect($run->fresh()->status)->toBe('queued')
                ->and(DB::table('jobs')->where('queue', 'backups')->sole()->attempts)->toBe($attempt)
                ->and(DB::table('failed_jobs')->count())->toBe(0);

            $this->travel(60)->seconds();
        }

        $overlapLock->release();
        expect(Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'backups', '--once' => true,
            '--timeout' => 900, '--tries' => 1,
        ]))->toBe(0);
        expect($run->fresh()->status)->toBe('completed')
            ->and(Storage::disk('backups')->exists($run->fresh()->archive_path))->toBeTrue();
    } finally {
        if (isset($overlapLock)) {
            $overlapLock->release();
        }
        $this->travelBack();
    }
});

it('keeps a completed archive completed when its audit write fails and retries the audit without rerunning backup', function () {
    config(['queue.default' => 'database']);
    app()->forgetInstance('queue');
    $run = BackupRun::create([
        'user_id' => $this->actor->getKey(), 'type' => 'files', 'source' => 'admin',
        'disk' => 'backups', 'status' => 'queued',
    ]);
    $failedFirstCompletionAudit = false;
    $originalDispatcher = Activity::getEventDispatcher();
    $isolatedDispatcher = clone $originalDispatcher;
    $isolatedDispatcher->listen('eloquent.creating: '.Activity::class, function (Activity $activity) use (&$failedFirstCompletionAudit): void {
        if ($activity->event === 'backup.completed' && ! $failedFirstCompletionAudit) {
            $failedFirstCompletionAudit = true;
            throw new RuntimeException('Simulated audit persistence failure');
        }
    });
    Activity::setEventDispatcher($isolatedDispatcher);

    $this->travelTo(Carbon::parse('2026-10-01 00:00:00'));
    try {
        RunBackup::dispatch((string) $run->getKey())->onQueue('backups')->beforeCommit();
        expect(Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'backups', '--once' => true,
            '--timeout' => 900, '--tries' => 1,
        ]))->toBe(0);

        $completed = $run->fresh();
        $archivePath = $completed->archive_path;
        $archiveFiles = Storage::disk('backups')->allFiles();

        expect($completed->status)->toBe('completed')
            ->and($failedFirstCompletionAudit)->toBeTrue()
            ->and($completed->failure_message)->toBeNull()
            ->and(Storage::disk('backups')->exists($archivePath))->toBeTrue()
            ->and(Activity::query()->where('subject_id', $run->getKey())->where('event', 'backup.completed')->exists())->toBeFalse()
            ->and(Activity::query()->where('subject_id', $run->getKey())->where('event', 'backup.failed')->exists())->toBeFalse()
            ->and(DB::table('jobs')->where('queue', 'backups')->sole()->attempts)->toBe(1);

        $this->travel(10)->seconds();
        expect(Artisan::call('queue:work', [
            'connection' => 'database', '--queue' => 'backups', '--once' => true,
            '--timeout' => 900, '--tries' => 1,
        ]))->toBe(0);
    } finally {
        Activity::setEventDispatcher($originalDispatcher);
        $this->travelBack();
    }

    expect($run->fresh()->status)->toBe('completed')
        ->and($run->fresh()->archive_path)->toBe($archivePath)
        ->and(Storage::disk('backups')->allFiles())->toEqual($archiveFiles)
        ->and(DB::table('jobs')->where('queue', 'backups')->count())->toBe(0)
        ->and(Activity::query()->where('log_name', 'administration')->where('subject_id', $run->getKey())->where('event', 'backup.completed')->count())->toBe(1);
});

it('requires download permission and rejects paths outside the configured archive inventory', function () {
    $run = $this->manager->request($this->actor, 'files')->refresh();
    $filename = basename($run->archive_path);
    $url = route('system.backups.download', ['disk' => 'backups', 'filename' => $filename]);
    $this->actingAs($this->actor)->get($url)->assertOk()->assertDownload($filename)->assertHeader('Cache-Control', 'no-store, private');
    $admin = User::factory()->create();
    $admin->assignRole('Admin');
    $this->actingAs($admin)->get($url)->assertForbidden();
    $this->actingAs($this->actor)->get(route('system.backups.download', ['disk' => 'public', 'filename' => $filename]))->assertNotFound();
    expect(fn () => $this->manager->download($this->actor, 'backups', '../.env'))->toThrow(HttpException::class);
});

it('enforces create permissions against a forged Filament backup action', function () {
    $viewer = User::factory()->create();
    $viewer->givePermissionTo('access.admin', 'backups.view');
    $this->actingAs($viewer)->get('/admin/system/backups')->assertOk();
    Livewire::test(Backups::class)->assertActionHidden('backupNow');
    expect(fn () => $this->manager->request($viewer, 'files'))->toThrow(AuthorizationException::class);
    expect(BackupRun::query()->count())->toBe(0);
});

it('refuses to execute queued jobs after the requesting administrator loses permission', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $actor->givePermissionTo('backups.create');
    $run = $this->manager->request($actor, 'files');
    $actor->revokePermissionTo('backups.create');
    expect(fn () => $this->manager->execute($run->refresh()))->toThrow(HttpException::class);
    expect($run->refresh()->status)->toBe('failed')
        ->and($run->failure_message)->toBe('Backup failed. Review the protected server logs.');
});

it('boots the package configuration and executes the installed backup list command', function () {
    expect(app(BackupConfig::class)->backup->destination->disks)->toBe(['backups']);
    expect(Artisan::call('backup:list'))->toBe(0);
});

it('rejects an orphaned backup after its requesting user is deleted', function () {
    Queue::fake();
    $actor = User::factory()->create();
    $actor->givePermissionTo('backups.create');
    $run = $this->manager->request($actor, 'files');
    $actor->delete();
    expect($run->refresh()->user_id)->toBeNull();
    expect(fn () => $this->manager->execute($run))->toThrow(HttpException::class);
    expect($run->refresh()->status)->toBe('failed');
});

it('queues and audits explicitly enabled scheduled backups and rejects public disks', function () {
    Queue::fake();
    expect(fn () => $this->manager->requestScheduled())->toThrow(RuntimeException::class);
    config(['enterprise.backups.schedule_enabled' => true]);
    $run = $this->manager->requestScheduled();
    expect($run->source)->toBe('scheduled')->and($run->user_id)->toBeNull();
    Queue::assertPushedOn('backups', RunBackup::class);
    expect(Activity::query()->where('event', 'backup.initiated')->where('subject_id', $run->getKey())->exists())->toBeTrue();
    config(['backup.backup.destination.disks' => ['public']]);
    expect(fn () => $this->manager->disks())->toThrow(RuntimeException::class);
});

it('requires every backup destination to be an existing disk with explicit private visibility', function () {
    config(['backup.backup.destination.disks' => ['not-configured']]);
    expect(fn () => $this->manager->disks())->toThrow(RuntimeException::class);

    config(['backup.backup.destination.disks' => ['public']]);
    expect(fn () => $this->manager->disks())->toThrow(RuntimeException::class);

    config(['backup.backup.destination.disks' => ['backups'], 'filesystems.disks.backups.visibility' => null]);
    expect(fn () => $this->manager->disks())->toThrow(RuntimeException::class);

    config(['filesystems.disks.backups.visibility' => 'private']);
    expect($this->manager->disks())->toBe(['backups']);
});
