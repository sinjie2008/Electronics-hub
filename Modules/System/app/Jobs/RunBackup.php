<?php

declare(strict_types=1);

namespace Modules\System\Jobs;

use DateTimeInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Modules\System\Models\BackupRun;
use Modules\System\Services\BackupManager;
use Throwable;

class RunBackup implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public int $tries = 0;

    public bool $failOnTimeout = true;

    public function __construct(public readonly string $runId) {}

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours(2);
    }

    public function middleware(): array
    {
        return [(new WithoutOverlapping('backup-run'))->releaseAfter(60)->expireAfter(960)->shared()];
    }

    public function handle(BackupManager $manager): void
    {
        $run = BackupRun::findOrFail($this->runId);
        try {
            $manager->execute($run);
        } catch (Throwable $exception) {
            if ($run->fresh()?->status === 'failed') {
                $this->fail($exception);

                return;
            }
            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $run = BackupRun::find($this->runId);
        if (! $run || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        $run->update([
            'status' => 'failed', 'failure_message' => 'Backup job failed. Review the protected server logs.',
            'finished_at' => now(),
        ]);
        app(BackupManager::class)->auditOutcome($run, 'backup.failed');
    }
}
