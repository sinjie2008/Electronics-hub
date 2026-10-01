<?php

use Illuminate\Support\Facades\Schedule;
use Modules\System\Services\BackupManager;

if (config('enterprise.backups.schedule_enabled')) {
    Schedule::call(fn () => app(BackupManager::class)->requestScheduled())->name('system:scheduled-backup')
        ->dailyAt(config('enterprise.backups.run_at'))
        ->withoutOverlapping(30)->onOneServer();
    Schedule::command('backup:clean')->dailyAt(config('enterprise.backups.clean_at'))
        ->withoutOverlapping(30)->onOneServer();
    Schedule::command('backup:monitor')->dailyAt('04:00')->withoutOverlapping()->onOneServer();
}
