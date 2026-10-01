<?php

declare(strict_types=1);

namespace Modules\System\Services;

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Modules\System\Jobs\RunBackup;
use Modules\System\Models\BackupRun;
use RuntimeException;
use Spatie\Activitylog\Models\Activity;
use Spatie\Backup\BackupDestination\Backup;
use Spatie\Backup\BackupDestination\BackupDestination;
use Spatie\Backup\Config\Config;
use Spatie\Backup\Tasks\Monitor\BackupDestinationStatusFactory;
use Throwable;

final class BackupManager
{
    public const TYPES = ['database', 'files', 'full'];

    public function request(User $actor, string $type): BackupRun
    {
        Gate::forUser($actor)->authorize('backups.create');
        Validator::make(['type' => $type], ['type' => ['required', Rule::in(self::TYPES)]])->validate();

        return $this->queue($actor, $type, 'admin');
    }

    public function requestScheduled(): BackupRun
    {
        if (! app()->runningInConsole() || ! config('enterprise.backups.schedule_enabled')) {
            throw new RuntimeException('Scheduled backups must be enabled in deployment configuration.');
        }

        return $this->queue(null, 'full', 'scheduled');
    }

    private function queue(?User $actor, string $type, string $source): BackupRun
    {
        return DB::transaction(function () use ($actor, $type, $source): BackupRun {
            $run = BackupRun::create([
                'user_id' => $actor?->getKey(), 'type' => $type, 'source' => $source,
                'disk' => $this->disks()[0], 'status' => 'queued',
            ]);
            $audit = activity('administration')->performedOn($run)->event('backup.initiated')
                ->withProperties(['type' => $type, 'disk' => $run->disk]);
            if ($actor) {
                $audit->causedBy($actor);
            }
            $audit->log('backup.initiated');
            RunBackup::dispatch((string) $run->getKey())->onQueue('backups')->afterCommit();

            return $run;
        });
    }

    /** @return list<string> */
    public function disks(): array
    {
        $disks = config('backup.backup.destination.disks', []);
        $configuredDisks = config('filesystems.disks');
        if (! is_array($disks) || $disks === [] || ! is_array($configuredDisks)) {
            throw new RuntimeException('Configure at least one backup destination.');
        }

        foreach ($disks as $disk) {
            if (! is_string($disk)
                || ! array_key_exists($disk, $configuredDisks)
                || ! is_array($configuredDisks[$disk])
                || ($configuredDisks[$disk]['visibility'] ?? null) !== 'private') {
                throw new RuntimeException('Backup destinations must be private configured disks.');
            }
        }

        return array_values($disks);
    }

    /** @return array<int, array<string, mixed>> */
    public function archives(User $actor): array
    {
        Gate::forUser($actor)->authorize('backups.view');
        $archives = [];
        foreach ($this->disks() as $disk) {
            foreach ($this->destination($disk)->backups() as $backup) {
                $archives[] = [
                    'disk' => $disk, 'filename' => basename($backup->path()),
                    'size' => $backup->sizeInBytes(), 'date' => $backup->date(),
                ];
            }
        }
        usort($archives, fn (array $left, array $right): int => $right['date'] <=> $left['date']);

        return $archives;
    }

    /** @return array<int, array{disk: string, reachable: bool, healthy: bool}> */
    public function health(User $actor): array
    {
        Gate::forUser($actor)->authorize('backups.view');

        return BackupDestinationStatusFactory::createForMonitorConfig(Config::fromArray(config('backup'))->monitoredBackups)
            ->map(fn ($status): array => [
                'disk' => $status->backupDestination()->diskName(),
                'reachable' => $status->backupDestination()->isReachable(),
                'healthy' => $status->isHealthy(),
            ])->values()->all();
    }

    public function download(User $actor, string $disk, string $filename): Backup
    {
        Gate::forUser($actor)->authorize('backups.download');
        abort_unless(in_array($disk, $this->disks(), true), 404);
        abort_unless(preg_match('/\A[A-Za-z0-9_.-]+\.zip\z/', $filename) === 1, 404);
        $backup = $this->destination($disk)->backups()
            ->first(fn (Backup $backup): bool => basename($backup->path()) === $filename);
        abort_unless($backup instanceof Backup && $backup->exists(), 404);

        return $backup;
    }

    public function execute(BackupRun $run): void
    {
        $run->refresh();
        if ($run->status === 'completed') {
            $this->auditOutcome($run, 'backup.completed');

            return;
        }

        if ($run->status !== 'queued') {
            return;
        }

        $lock = Cache::lock('system:backup:run', 960);
        if (! $lock->get()) {
            throw new RuntimeException('Another backup is already running.');
        }

        try {
            $run->refresh();
            if ($run->status === 'completed') {
                $this->auditOutcome($run, 'backup.completed');

                return;
            }
            if ($run->status !== 'queued') {
                return;
            }

            if ($run->source === 'scheduled') {
                abort_unless(config('enterprise.backups.schedule_enabled'), 403);
            } else {
                $actor = $run->user;
                abort_unless($actor && $actor->is_active && Gate::forUser($actor)->allows('backups.create'), 403);
            }
            Validator::make(['type' => $run->type, 'disk' => $run->disk, 'source' => $run->source], [
                'type' => ['required', Rule::in(self::TYPES)],
                'source' => ['required', Rule::in(['admin', 'scheduled'])],
                'disk' => ['required', Rule::in($this->disks())],
            ])->validate();
            $run->update(['status' => 'running', 'started_at' => now()]);
            $filename = now()->format('Y-m-d-H-i-s').'-'.$run->getKey().'.zip';
            $options = ['--filename' => $filename, '--only-to-disk' => $run->disk];
            if ($run->type === 'database') {
                $options['--only-db'] = true;
            } elseif ($run->type === 'files') {
                $options['--only-files'] = true;
            }
            if (Artisan::call('backup:run', $options) !== 0) {
                throw new RuntimeException('Backup command failed. Review the protected server logs.');
            }
            $path = $this->destination($run->disk)->backupName().'/'.$filename;
            if (! $this->destination($run->disk)->disk()->exists($path)) {
                throw new RuntimeException('The backup archive was not created.');
            }
            $run->update(['status' => 'completed', 'archive_path' => $path, 'failure_message' => null, 'finished_at' => now()]);
            $this->auditOutcome($run, 'backup.completed');
        } catch (Throwable $exception) {
            $run->refresh();
            if ($run->status !== 'completed') {
                $run->update([
                    'status' => 'failed', 'failure_message' => 'Backup failed. Review the protected server logs.',
                    'finished_at' => now(),
                ]);
                $this->auditOutcome($run, 'backup.failed');
            }
            throw $exception;
        } finally {
            $lock->release();
        }
    }

    public function auditOutcome(BackupRun $run, string $event): void
    {
        DB::transaction(function () use ($run, $event): void {
            $lockedRun = BackupRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();
            $subjectType = $lockedRun->getMorphClass();

            if (Activity::query()
                ->where('log_name', 'administration')
                ->where('subject_type', $subjectType)
                ->where('subject_id', $lockedRun->getKey())
                ->where('event', $event)
                ->exists()) {
                return;
            }

            $activity = activity('administration')->performedOn($lockedRun)->event($event)
                ->withProperties(['type' => $lockedRun->type, 'disk' => $lockedRun->disk]);
            if ($lockedRun->user) {
                $activity->causedBy($lockedRun->user);
            }
            $activity->log($event);
        });
    }

    private function destination(string $disk): BackupDestination
    {
        return BackupDestination::create($disk, (string) config('backup.backup.name'));
    }
}
