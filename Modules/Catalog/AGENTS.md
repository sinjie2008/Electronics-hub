# Catalog Module Guidelines

## Scope
- Preserve all features, page URLs and API contracts, including legacy `catalog.php?action=v1.*` actions and WordPress consumers.
- This repository is the native Laravel 13 / nWidart v13 `Catalog` module, installed at `Modules/Catalog/`. Preserve existing SQL semantics and data while migrating the framework.
- Separate PHP implementation from HTML templates. Keep styling and browser behaviour in asset files.

## Structure
- Controllers handle HTTP requests; services implement feature workflows; repositories/models handle database persistence.
- Use `Modules\Catalog\` namespaces and the nWidart v13 generated layout. HTTP controllers belong in `app/Http/Controllers`; business services and repositories retain focused responsibilities.
- Providers load only through `module.json`, respecting module activation. Do not add Laravel package discovery for this module's providers.
- `routes/` exposes compatible pages and APIs. `resources/views/` contains Blade display templates; `resources/assets/scss/` and `resources/assets/js/` contain asset sources. `public/assets/` contains generated production assets.
- `config/` holds settings; `scripts/` holds operational commands. Storage directories contain user/generated files.
- `database/migrations/` and `database/seeders/` own controlled installation and initialization. HTTP requests must never create databases, alter schema or seed initial catalog data.
- Use native Laravel Request/Response and dependency injection; do not capture PHP superglobals, output buffers or shared HTTP response state.
- Preserve distinct legacy and file API envelopes, raw valid inputs, method handling, correlation IDs and file responses. Route/config/view namespaces and resources must not affect host pages.
- Database and storage paths come from host configuration. Preserve transaction boundaries. Do not claim Octane/Swoole or real WordPress verification without executing it.

## Changes and Verification
- Use strict PHP types, four-space indentation, descriptive names and focused responsibilities.
- Preserve camelCase/snake_case fields, response envelopes, SQL ordering, validation, transactions, file paths and errors.
- Run relevant PHP syntax checks, asset builds and functional/API checks. Destructive checks require disposable data.
- No permanent project tests or runner. Temporary verification belongs outside this repository.
- Never delete uploaded/generated files or expose credentials in logs.
- Keep only `README.md`, `API.md` and these instructions as maintenance documents. Edit views and asset sources; generated assets must match the build.
- All fixtures, test scripts, reports and temporary Laravel/Filament hosts stay outside this repository. Validate fresh installation, existing-data migrations, module activation, caches, APIs, pages, uploads, CSV and actual PDF compilation.
- Do not commit, push, deploy or update WordPress unless requested.
