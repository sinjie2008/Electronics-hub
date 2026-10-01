# Backup operations

Spatie Laravel Backup 10 runs on **Linux** with PHP 8.4.1+, the ZIP extension, and `mysqldump` for MySQL. The upstream documentation does not support Windows servers for backup execution. Use Linux locally, in CI, and in production for these operations; WSL/Linux containers can provide the Linux environment for Windows development.

## Storage and source configuration

`config/backup.php` is the deployment-owned source of truth. Defaults are:

- Backup name: `BACKUP_NAME=enterprise`.
- Destination: `BACKUP_DISK=backups`, a private local disk rooted at `storage/app/backups`.
- Every configured destination, including a custom or off-host disk, must explicitly set `visibility` to `private`. The application rejects disks with missing or public visibility.
- Database: the configured `DB_CONNECTION` (MySQL in `.env.example`).
- Files: only `storage/app/private`; symlinks are not followed. Environment files, Passport signing keys, application logs, and backup/temp directories are outside the default file source.
- ZIP verification is enabled. Optional archive encryption reads `BACKUP_ARCHIVE_PASSWORD` from deployment configuration.
- Notification email is optional (`BACKUP_NOTIFICATION_EMAIL`). No notification is sent when it is unset.
- Monitoring checks reachability, a maximum backup age of two days, and total archive storage of 5,000 MB. Adjust age/retention together when changing the schedule.

A database archive contains private application data, including user records and hashed credentials. Protect archives and their encryption password independently of the application. The default local disk is a starting point; configure an additional supported off-host disk and appropriate retention for your deployment. No public storage link exposes the backup disk.

If the MySQL executable is outside `PATH`, set `DB_DUMP_BINARY_PATH` to its **directory**, not the binary filename. `DB_DUMP_TIMEOUT` defaults to 120 seconds. The MySQL dump uses a single transaction and `--no-tablespaces`, so normal database backups do not require granting a broad PROCESS privilege. Grant only the privileges required for your database and chosen dump options.

## Administration

The Filament page is `/admin/system/backups`:

| Operation | Permission |
| --- | --- |
| View archives, recent jobs, disk/size/date, and health | `backups.view` |
| Queue a database, files, or full backup | `backups.create` |
| Download an existing archive | `backups.download` |

Downloads use an authenticated, verified, active session and check the configured disk plus the existing archive inventory. Arbitrary paths and disk names are rejected. Download responses are private and must not be cached. The default Admin role can view and create backups; archive download requires an explicit grant or Super Admin access.

“Backup Now” queues a job on `backups`. Run a worker subscribed to that queue:

```bash
php artisan queue:work --queue=backups,default --timeout=900
```

The job timeout is 900 seconds and Redis `retry_after` defaults to 960. Keep `retry_after` greater than the worker/job timeout. A queued backup remains retryable for two hours so overlap releases do not exhaust attempts before the lock clears. Web requests never invoke a dump synchronously in the default Redis configuration. Backup creation permission is checked again when the job executes. Overlapping jobs wait, and failed jobs retain a safe generic failure message; detailed operational failures belong in protected server logs.

The page inventories all existing archives, including archives created by CLI or scheduler. Its recent-job history records web-requested and scheduled jobs, with queued/running/completed/failed states. The default Laravel failed-job table also supports worker diagnostics. Administrative initiation, completion, and failure are audited without passwords, keys, tokens, or archive contents.

## CLI and scheduling

Commands exposed by the installed package include:

```bash
php artisan backup:run --only-db
php artisan backup:run --only-files
php artisan backup:run
php artisan backup:list
php artisan backup:monitor
php artisan backup:clean
```

Scheduling is opt-in. Set these deployment values:

```dotenv
BACKUP_SCHEDULE_ENABLED=true
BACKUP_RUN_AT=02:00
BACKUP_CLEAN_AT=03:00
```

Run Laravel's scheduler every minute, for example:

```cron
* * * * * cd /srv/app && php artisan schedule:run >> /dev/null 2>&1
```

The schedule runs the backup, cleanup, and a 04:00 health check in the application timezone configured by `APP_TIMEZONE`. The System Settings timezone applies to HTTP requests and does not change scheduler times. Shared Redis scheduler locks prevent overlap across instances. Scheduled backups use the same audited backups queue as web-requested backups; cleanup and monitoring execute package commands directly through the scheduler. Keep a worker running for both scheduled and web-requested backups. Review retention, disk capacity, timeouts, monitoring age, encryption, and off-host copies before enabling the production schedule.

## Manual restoration

There is deliberately no web restore action. Restoration is destructive and depends on the deployment's database, files, and release strategy. Maintain and rehearse an operator-run procedure:

1. Select a known-good archive and verify its date and integrity. Download it through an authorized session or trusted operator access.
2. Inspect the ZIP listing and extract into an isolated directory. Supply an encryption password through a secure interactive workflow when configured.
3. Prepare an isolated target database first. Import the SQL dump with a restore-specific database account, using an interactive password prompt rather than an inline password.
4. Restore required private files to the matching release's private storage location, preserving appropriate ownership and permissions.
5. Validate application behavior and database consistency before switching production traffic. Pause writes and background jobs during a production cutover, then follow the deployment's cache/restart procedure.

Laravel `APP_KEY` and Passport signing keys are deployment secrets outside the default file source. Preserve them separately when an existing environment must continue decrypting data or validating tokens. Never commit archives or extracted SQL dumps.

## Verification

`tests/Integration/BackupTest.php` creates real files archives, checks health/download authorization and queued-job auditing, and runs actual database/full MySQL dumps on Linux. The Linux/MySQL CI job executes those dump tests; the fast SQLite suite skips the two MySQL-specific cases. Restore tests are excluded.

Upstream: [Laravel Backup v10](https://spatie.be/docs/laravel-backup/v10/introduction), [requirements](https://spatie.be/docs/laravel-backup/v10/requirements).
