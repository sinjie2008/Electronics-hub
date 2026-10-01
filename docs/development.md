# Development and operations

## Local setup

Use Linux with PHP 8.4.1+, Composer 2, Node.js 24, MySQL 8+, and Redis 7+. Install the PHP extensions declared in the root `composer.json`, including DOM, fileinfo, JSON, mbstring, MySQLi, PDO MySQL, Redis, SimpleXML, and ZIP. Catalog and the application use separate named MySQL connections; backup work also needs `mysqldump` available to the worker process.

```bash
cp .env.example .env
composer install
npm ci
php artisan key:generate
php artisan passport:keys
php artisan migrate --seed
php artisan module:migrate Catalog --database=catalog --force
php artisan db:seed --class='Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder' --database=catalog --force
npm run build
(cd Modules/Catalog && npm ci && npm run build:assets)
```

Set the application `DB_*` values, the Catalog `CATALOG_DB_*` values, and Redis connection variables in `.env` before running migrations. The `catalog` connection defaults to a separate Catalog schema with an empty table prefix; ensure the account can create and alter its tables. Host `migrate --seed` does not run Catalog's module migration or data seeder, so run those commands explicitly against a disposable database for local development and against the reviewed Catalog schema during deployment. Use the interactive `php artisan app:create-admin` command to create a development administrator. Do not put passwords or private keys in tracked files.

`composer run dev` starts the Laravel server, the default queue listener, and Vite. Run `npm run build` when you need a production-like asset bundle. If you need to exercise backup jobs, also run a worker that listens to the named backup queue:

```bash
php artisan queue:work --queue=backups,default --timeout=900
```

To exercise opt-in scheduled backups locally, set `BACKUP_SCHEDULE_ENABLED=true` in the ignored `.env` and run `php artisan schedule:work`. Scheduled backups use the same backups queue. The configured backup and cleanup times default to 02:00 and 03:00 in the application timezone set by `APP_TIMEZONE`; the admin System Settings timezone applies only to HTTP requests and does not change scheduler time. Keep backup schedules disabled in environments that do not have a worker and sufficient private storage.

Telescope is disabled by default. Enable it only in the local environment with `TELESCOPE_ENABLED=true`; access additionally requires an active session with `system-info.view`. Request credentials are redacted and OAuth/login/reset/Livewire paths are ignored. Payload-bearing cache, Redis, command, event, job, mail, notification, dump, model, query, and outbound HTTP watchers are opt-in because they can capture secrets. Review redaction before deliberately enabling any of them.

## Useful commands

| Purpose | Command |
| --- | --- |
| Check dependency declarations | `composer validate --strict` |
| Audit locked Composer packages | `composer audit` |
| Refresh module autoloading | `composer dump-autoload` |
| Inspect registered HTTP routes | `php artisan route:list` |
| Inspect module state | `php artisan module:list` |
| Create Passport signing keys | `php artisan passport:keys` |
| Run PHP style checks | `vendor/bin/pint --test` |
| Run Larastan/PHPStan | `composer run analyse` |
| Run the test suite | `php artisan test` |
| Build frontend assets | `npm run build` |
| Build Catalog assets | `(cd Modules/Catalog && npm ci && npm run build:assets)` |
| Migrate Catalog schema | `php artisan module:migrate Catalog --database=catalog --force` |
| Seed initial Catalog data | `php artisan db:seed --class='Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder' --database=catalog --force` |

See the [module guide](modules.md) before adding module code and the [package compatibility matrix](package-compatibility.md) before changing direct dependencies.

## Tests and CI

The test suite uses Pest. The local PHPUnit configuration supplies fast isolated defaults; configure disposable application and Catalog MySQL databases when running checks that need to match CI. Never point tests at a shared or production database. Run with shell environment values for disposable MySQL databases to override the local SQLite defaults, and set `RUN_REDIS_INTEGRATION=true` with a reachable Redis service. This enables the real dump/archive, Redis session/cache, and Redis queue-worker cases. SQLite intentionally skips service-specific cases and cannot run Catalog's MySQLi workflows.

With the configured account granted access to these two disposable databases, run the complete integration suite using:

```bash
DB_CONNECTION=mysql DB_DATABASE=electronics_hub_testing \
CATALOG_DB_DATABASE=electronics_catalog_testing \
RUN_REDIS_INTEGRATION=true RUN_CATALOG_COMPILER_INTEGRATION=true \
php artisan test --compact
```

The compiler flag enables actual global/series Typst and both LaTeX compilation paths and their PDF downloads. Install Typst and `pdflatex` first; when this flag is enabled, missing or failing compilers fail the tests. Catalog setup refuses database names without the `_test` / `_testing` suffix and refuses to share the host database. Tests clean only Catalog-owned rows and their isolated test storage.

The GitHub Actions workflow installs PHP 8.4 extensions, Node 24, and disposable MySQL 8 and Redis 7 services. It creates separate application and Catalog databases, installs MySQLi/fileinfo and the other required PHP extensions, builds host and Catalog assets from their own lock files, applies both schemas and seeders, checks route/module registration and framework cache commands, then runs Pint, Larastan, and Pest. MySQL dump tooling is installed so backup integration tests can exercise the Linux dump-and-ZIP path. CI credentials are ephemeral test values, not application secrets.

The workflow checks configuration, route, view, and Filament component/icon caches, then clears generated caches before tests. Preserve route-cache compatibility when adding routes; avoid route closures in route files.

## Production release checklist

1. Set deployment-only environment values, including `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, mail, database, Redis, and any optional provider credentials. Use a secure HTTPS origin and production cookie settings.
2. Run `composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative`, `npm ci && npm run build`, and `(cd Modules/Catalog && npm ci && npm run build:assets)`. Boost and Telescope are development dependencies and are not installed in this production dependency set. Telescope is registered only when the app is local and `TELESCOPE_ENABLED=true`; production does not register its provider even if dev dependencies are accidentally present.
3. Generate a unique Laravel `APP_KEY` and Passport keys for the deployment, then persist them through the environment or a protected secret mount. Do not regenerate them on every release.
4. Run `php artisan migrate --seed --force` against the application database for host schema and permissions. Then run `php artisan module:migrate Catalog --database=catalog --force` and, only for an initial Catalog install, seed reviewed Catalog data with `php artisan db:seed --class='Modules\Catalog\Database\Seeders\CatalogDatabaseSeeder' --database=catalog --force`. After environment values, routes, and module activation are final, run `php artisan optimize` and `php artisan filament:optimize`. These cache Laravel's deployment metadata and Filament's component indexes/icons. See [Filament's deployment guide](https://filamentphp.com/docs/5.x/deployment). Serve only the `public/` document root.
5. Keep a Redis-backed worker running. Backup jobs need a worker subscribed to `backups,default` with a 900-second job timeout. They have a two-hour retry deadline to allow overlap releases. Configure Redis queue `retry_after` above that timeout; this application's configured value is 960 seconds.
6. Run Laravel's scheduler every minute. For a cron-based deployment, use an absolute application path:

   ```cron
   * * * * * cd /srv/app && php artisan schedule:run >> /dev/null 2>&1
   ```

   Enable backup scheduling explicitly and confirm the worker can invoke the configured `mysqldump` binary and write the private backup disk.
7. Grant the web and worker users write access to the required `storage/` and `bootstrap/cache/` paths. Set `MODULE_STATUSES_PATH` to a shared writable file outside a read-only release when runtime module toggles are enabled; initialize it from `modules_statuses.json` and keep the path consistent across instances. The web module page only changes activation state; it does not run install/update/migration/deployment commands. After module code or activation changes, clear and rebuild route and Filament component caches in the deployment pipeline.
8. After each release or module-state change, restart long-lived queue workers (`php artisan queue:restart`) so they load the new code. Keep generated OAuth keys, client secrets, logs, and backup archives out of the public document root.

The application does not provide web-based backup restoration. Operators should follow the [backup guide](backup.md) and their deployment's tested restore procedure.
