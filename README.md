# Electronics Hub

A modular Laravel 13 application foundation for internal administration, OAuth-protected APIs, and optional AI integrations. The project runs from the repository root as a single Laravel application; `Modules/` holds separately autoloaded application modules.

## Features

- A Filament 5 admin panel at `/admin` with session authentication, password reset, email verification, and profile management. Public registration is disabled.
- Role and permission management backed by Spatie Laravel Permission. The seeded system roles and permissions are protected from unauthorized changes.
- Three baseline modules: `Core`, `IAM`, and `System`. Their activation is protected so core access-control and system features cannot be disabled accidentally.
- An optional `Catalog` module preserves the original catalog pages and APIs, including products, scoped fields, specification search, CSV snapshots, media, and Typst/LaTeX PDF generation.
- Laravel Passport 13 bearer-token APIs, with user tokens, PKCE public clients, and client-credentials integration clients.
- User search through Laravel Scout's database driver by default; no separate search service is needed for the baseline setup.
- Database-backed system settings and administrative activity logs.
- Queued database, private-file, or full backups to private local storage, with scheduling disabled by default.
- Laravel AI SDK support with optional provider credentials, plus a local, standard-I/O-only Laravel MCP server.
- Laravel Telescope for local development, disabled by default and installed as a development dependency. Its provider is registered only for an explicitly enabled local environment, and is absent in production even if dev dependencies are accidentally present.

See [the architecture guide](docs/architecture.md), [module guide](docs/modules.md), [Catalog migration and setup](Modules/Catalog/README.md), [permissions guide](docs/permissions.md), [OAuth guide](docs/oauth2.md), [search guide](docs/search.md), [backup guide](docs/backup.md), [AI guide](docs/ai.md), and [MCP guide](docs/mcp.md) for operational detail.

## Requirements

- Linux runtime with PHP **8.4.1 or later** and Composer 2.
- Node.js 24 LTS and npm.
- MySQL 8 or later and Redis 7 or later.
- The PHP extensions listed in [`composer.json`](composer.json): DOM, fileinfo, JSON, mbstring, PDO, PDO MySQL, Redis, SimpleXML, and ZIP. The CI environment also enables the extensions used by process management and the test tooling.
- `mysqldump` on backup workers that create MySQL database dumps.
- Typst and `pdflatex` for Catalog PDF compilation.

The locked package versions and their upstream compatibility declarations are recorded in [package compatibility](docs/package-compatibility.md). Use the committed lock files when installing dependencies.

## Installation

Clone the repository, then work from its root:

```bash
git clone https://github.com/sinjie2008/Electronics-hub.git Electronics-hub
cd Electronics-hub
cp .env.example .env
```

Set `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`, and the Redis connection values in `.env` for your local MySQL and Redis services. Keep real secrets in the deployment environment or local ignored `.env` file; `.env.example` contains placeholders only.

For Catalog, also create a separate MySQL database (default: `electronics_catalog_migrated`) and configure its `CATALOG_DB_*` connection values before migrating. Keep the original source database untouched. Follow [Catalog setup](Modules/Catalog/README.md) to import an independent snapshot and its files; a fresh Catalog database receives the original small baseline tree.

```bash
composer install
npm ci
php artisan key:generate
php artisan passport:keys
php artisan migrate --seed
npm run build
```

`composer run dev` starts the Laravel server, default queue listener, and Vite development server. For queued backups, run a worker in a separate terminal:

```bash
php artisan queue:work --queue=backups,default --timeout=900
```

Open `http://localhost:8000/admin`. In a development environment, create the first admin with the interactive command:

```bash
php artisan app:create-admin
```

For a one-time deployment bootstrap, the seeder can create an initial administrator from `INITIAL_ADMIN_NAME`, `INITIAL_ADMIN_EMAIL`, and `INITIAL_ADMIN_PASSWORD`. Supply those values only through the deployment environment for the first seed, then remove them. Do not commit administrator credentials.

## Database and Redis setup

Create a dedicated MySQL database and application account, then set the `DB_*` values before running `php artisan migrate --seed`. All schema changes, including Passport, permissions, settings, activity, AI conversation storage, and backup-job history, use migrations. The generic seeder creates roles and permissions; it does not install demonstration business data. Never run `migrate:fresh` against a database containing data you need.

Redis backs cache, encrypted browser sessions, and queues by default. Set `REDIS_HOST`, `REDIS_PORT`, and an optional deployment-owned `REDIS_PASSWORD`; configure the database numbers when sharing a Redis server. The `backups` queue needs a running worker. Backup jobs have a two-hour retry deadline for overlap releases. Keep the worker timeout at 900 seconds and Redis queue `retry_after` above that timeout; the configured value is 960 seconds.

## Admin setup and initial administrator

Use `php artisan app:create-admin` for an interactive setup with a hidden password prompt, or the one-time initial-administrator environment variables described above. The setup path validates the password and refuses to overwrite an existing account. Configure a real mail transport to deliver reset and verification messages; the development default writes mail to the ignored application log.

The Filament panel supports login, logout, password recovery, email verification, profile editing, and password changes requiring the current password. Accounts must be active and hold `access.admin`; individual resources and actions also enforce policies. Administrators create users; public self-registration is disabled. Ordinary OAuth users can sign in and recover their passwords through the session login at `/login`.

## Roles and permissions

The seed creates `Super Admin`, `Admin`, and `User`. An ordered Laravel Gate hook denies inactive accounts, grants the protected Super Admin bypass, and delegates ordinary permissions to Spatie. Policies authorize resource actions, and service methods check escalation and integrity rules again.

Admin receives normal account administration, system viewing, settings editing, backup creation, OAuth client administration, and permitted search. Permission administration, module toggling, and backup download require additional grants. User receives no administrative grants by default. Seeded roles/permissions cannot be renamed or deleted, assigned roles/permissions cannot be deleted, and the final active Super Admin cannot be removed. See [the complete ACL guide](docs/permissions.md).

## Modules and module management

The included modules are `Core`, `IAM`, `System`, and optional `Catalog`. Core holds shared abstractions, IAM owns identity and access services/policies/resources, System owns operational administration, and Catalog owns the migrated business pages at `/catalog/catalog_ui.html`. Modules declare their own Composer namespaces; the permitted Wikimedia merge plugin loads their manifests. Filament discovers enabled module resources and pages through Coolsam `ModulesPlugin`; Catalog retains its original interface with Laravel session, CSRF, and IAM integration.

`/admin/system/modules` lists names, descriptions, versions, status, paths, and dependencies for installed modules. View, enable, and disable permissions are separate. The foundational modules are protected in the UI and activator. Optional module changes validate dependencies, serialize state writes, and generate audit records. The status page only changes activation state: installing code, running migrations, and deployment cache work belong in the release process. Every application instance must read the same writable module-status file. After module code or activation changes, rebuild route and Filament caches and restart long-lived workers. Read [module development and shared activation-state deployment](docs/modules.md).

## OAuth2, Passport, and API

The API is versioned under `/api/v1` and uses Passport bearer tokens. The baseline scopes are `profile:read`, `users:search`, and `system:read`. The search endpoint also checks the caller's application permissions; an OAuth scope alone does not grant administrative access.

| Endpoint | Access |
| --- | --- |
| `GET /api/v1/me` | User access token with `profile:read` |
| `GET /api/v1/search/users` | User access token with `users:search` plus `search.use` and `users.view` permissions |
| `GET /api/v1/integration/status` | Client-credentials token with `system:read` |
| `GET /api/v1/health` | Public, generic health response |

Register OAuth clients from the admin panel or Passport's supported client command. Public clients use authorization code with PKCE. Client-credentials secrets are shown only when a client is created; store them securely at that point. See [permissions](docs/permissions.md) for the separation between OAuth scopes and application permissions.

## Search

Scout uses `SCOUT_DRIVER=database`. The working user search indexes only ID, name, and email, returns active accounts, and requires both Passport scope and administrative permissions. Query validation and pagination limit the surface. No external search server or client package is installed. See [search and future engine changes](docs/search.md).

## Backup

The admin backup page at `/admin/system/backups` queues database, private-file, or full archives to `storage/app/backups`. Archives contain private database and file data and must be handled as sensitive records. The page shows archive inventory (including archives created through the package CLI), size/date/disk, health, and recent runs queued by the application; direct CLI runs do not create application run-history rows. Viewing, creating, and downloading require separate permissions. Workers need Linux, ZIP, and `mysqldump`; upstream backup execution is unsupported on Windows servers. There is no web restore action.

Scheduling is opt-in through `BACKUP_SCHEDULE_ENABLED`, with backup and cleanup times of `02:00` and `03:00`. Scheduled creation uses the same audited queue as web requests. Restore is an operator-run process with guidance in [backup operations](docs/backup.md).

## Settings and activity logging

`/admin/system/settings` persists application name/description, timezone, locale, pagination size, and default AI provider/model names. Application naming and locale/timezone are applied for each HTTP request; Filament pagination uses the saved pagination setting. Scheduled backups use the deployment's `APP_TIMEZONE` and `BACKUP_RUN_AT`/`BACKUP_CLEAN_AT` values, not the admin timezone setting. The configured locale allowlist is in `config/enterprise.php`. Settings contain no provider keys or deployment credentials.

`/admin/system/activity-log` is a read-only administrative audit viewer. Account, role, permission, module, backup, setting, and OAuth client changes record explicit safe properties. Passwords, tokens, client secrets, and keys are excluded. `/admin/system/information` exposes authorized, read-only runtime details without credentials. Both pages enforce their permissions on the server.

## AI and MCP

The optional AI guide uses Laravel's AI SDK. Provider keys are configured only in deployment secrets or a local ignored `.env`; tests use the SDK fake and do not make provider calls. The MCP surface is a local standard input/output process, started with `php artisan mcp:start system`, and exposes only system name and Laravel version. The app does not register an HTTP MCP server. See [AI](docs/ai.md) and [MCP](docs/mcp.md).

The example AI agent has one safe application-information tool. Its configuration and SDK migrations are published, and automated tests use fakes without provider credentials. No chat, RAG, vector database, or AI dashboard is included.

## Development and testing

Laravel Boost is installed for Codex with the portable configuration in `.codex/config.toml`, official package skills, and project rules in `AGENTS.md`. Pint handles formatting; Larastan uses level 5. Telescope is an explicit local-only opt-in (`TELESCOPE_ENABLED=true`) guarded by `system-info.view`. Watchers that can capture mail, job, cache, Redis, query, model, or external-request payloads are disabled by default.

Run the checks locally with:

```bash
composer validate --strict
composer audit
npm ci
npm run build
vendor/bin/pint --test
composer run analyse
php artisan test
```

The default local suite uses isolated SQLite/array/sync services; Catalog feature tests require MySQL. For the complete Linux integration run, provide a disposable MySQL database and Redis, set `RUN_REDIS_INTEGRATION=true`, and install Typst and `pdflatex`. Database/full backup tests execute actual MySQL dumps; Redis integration exercises cache locks, browser sessions, and a real backup queue worker. The suite also covers Filament authentication/resources/actions, ACL escalation, module protection, PKCE/token issuance/scopes, search, settings, audit redaction, AI fakes, safe MCP output, and Catalog APIs, uploads, CSV snapshots, and real PDF compilation. See [development operations](docs/development.md).

## CI

GitHub Actions runs on Ubuntu with PHP 8.4, Node 24, and ephemeral MySQL 8 and Redis 7 services. It installs the required extensions, MySQL dump tools, Typst, and `pdflatex`, validates and audits Composer dependencies, builds assets, verifies the Catalog asset manifest, exercises migrations plus Laravel and Filament caches, and runs Pint, Larastan, and Pest. The database and Redis credentials in CI are disposable test-only values. Both dependency lock files are committed.

## Security and deployment notes

Serve only `public/` over HTTPS, set secure session cookies in production, and configure trusted reverse proxies for your deployment. Browser routes use Laravel CSRF protection and sessions; API routes use Passport scopes, rate limits, active-account checks, and policies. Passwords use Laravel hashing and strong validation. Backups remain private and downloads validate the existing archive inventory.

For production, install PHP dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader --classmap-authoritative`, build the frontend with `npm ci && npm run build`, set `APP_ENV=production` and `APP_DEBUG=false`, and provide secrets and service endpoints through the environment. Generate and securely persist the Laravel application key and Passport keys before serving traffic. Serve only the `public/` directory, run `php artisan migrate --seed --force` to establish baseline roles and permissions, keep the queue worker and Laravel scheduler running, and grant the web/worker user write access only to the required `storage/` and `bootstrap/cache/` paths.

For optional module toggles on a read-only release, set `MODULE_STATUSES_PATH` to a shared writable JSON file outside the release and initialize it from `modules_statuses.json`. Ensure all instances use the same file. After module registration or activation changes, clear and rebuild the Laravel route and Filament component caches in the release process, then restart long-lived workers. The web UI does not run install, update, migration, or deployment commands. Configure off-host backup storage, retention, monitoring, mail, and scheduler/worker supervision for the target environment.

Generated Passport keys, `.env`, secrets, logs, archives, database dumps, dependency directories, and compiled assets are ignored. Never commit deployment credentials or suppress a Composer advisory to pass CI. Review [architecture](docs/architecture.md), [development operations](docs/development.md), and [package compatibility](docs/package-compatibility.md) before extending the foundation.
