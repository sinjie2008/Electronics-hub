# Public Product API implementation

Target: `sinjie2008/Electronics-hub`, base `codex/integrate-boilerplate-catalog`, baseline commit `7f55314392d6db53db170e2e41a64f7e6e2229c3`. Work branch: `codex/secure-public-product-api-v1`.

Authoritative source: `sinjie2008/Foundational-Electronics-Core-Systems`, exact branch `plan/public-product-api-v1`, commit `c10faf4b5314668f7695a9c138a8e9333bee469d`. The source repository was read only. The committed oracle records controller/service SHA256 values and actual source responses.

## Ownership and dependency map

`Modules/Catalog/app/Http/ModuleRoutes.php` owns public and legacy URLs. `PublicProductApiController` validates bounded transport inputs, dispatches the source operations and preserves its flat envelopes. Request-scoped `PublicProductApiService` performs read-only queries through the existing `catalog.connection` MySQLi binding. It uses existing category/product/custom-field tables. There is no new database, product repository, publication status, synchronization job, dependency or migration.

Existing hierarchy/product/field/metadata/CSV/Typst/LaTeX services continue to own writes. Filament continues to use `CatalogAdminService` and `CatalogPolicy`. Laravel PDO and Catalog MySQLi have separate transactions; a host transaction cannot roll back committed Catalog work.

The necessary host hooks are in `bootstrap/app.php`: a module-owned guard runs before global CORS, the module handles CORS at its own paths, and unknown public API URLs receive the source error envelope without a catch-all route over `/api/v1`. CI installs the compiler sandbox tools. All Catalog business behavior remains inside the module.

## Public API

All ten operations allow anonymous GET while enabled. Other registered methods return 405 with `Allow: GET`. Existing `/api/v1` health, Passport, IAM, Scout and System routes retain their ownership.

| GET endpoint | Result in `data` |
| --- | --- |
| `/api/` | Discovery metadata, ten endpoint patterns and tree |
| `/api/health` | `status`, `database`, `writeOperations` |
| `/api/tree` | Ordered recursive category/series nodes and series part counts |
| `/api/resolve/{path}` | Resource and breadcrumb |
| `/api/categories/{path}` | Resource, breadcrumb and direct children |
| `/api/series/{path}` | Resource, breadcrumb, metadata, fields, part count |
| `/api/series/{path}/fields` | Public product-attribute definitions |
| `/api/series/{path}/parts` | Series, fields, part page and pagination |
| `/api/series/{path}/facets` | Series, total parts and public facet counts |
| `/api/search?q={keyword}` | Query, matching nodes and up to 100 product matches |

Success is `{success:true,data:...,correlationId}`. Errors are `{success:false,errorCode,message,details,correlationId}`. Numeric IDs/counts, nullable parents/descriptions/defaults, camelCase keys, PHP facet key coercion, and original `page/per_page/total/last_page` keys are retained. Responses carry `X-Correlation-ID`, `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`. Correlation IDs are sanitized and limited to 64 characters.

Paths preserve nested categories, Unicode source slugs, sibling duplicate `-ID` suffixes and source-compatible percent decoding. Full series paths are resolved before treating trailing `fields`, `parts` or `facets` as subresources. `/api/index.php`, its path suffixes and `?route=` compatibility are also supported. Exact legacy `/api/series/details.php` is registered before the public series matcher.

Parts support `search`, `filter[key]=value` (OR arrays per field, AND across fields), `sort`, `direction`, `page` and `per_page`. Sorting is source-compatible natural case-insensitive PHP ordering with ID tie breaks. Pagination clamps page to the available range and page size to 1–100, default 25. Search preserves the source SQL LIKE wildcard semantics. Facets cover the full selected series rather than the current page.

Public field and metadata queries require `COALESCE(is_public_portal_hidden,0)=0`. Product custom values are restricted to that allowlist. The source's separate backend visibility flag does not itself mean a field is private to the public portal; public visibility remains controlled by the public flag. Public file-valued metadata remains an identifier, not anonymous permission to download private files.

## Security and explicit compatibility deviations

- Legacy controllers, aliases, six pages, CSV history/exports, templates/variables and private files require an active session or a Passport **user** token and `catalog.view`. Invalid Authorization headers never fall back to a browser session; inactive accounts, client-only tokens and scopes without application permissions do not grant access.
- Create/update/delete operations require corresponding Catalog permissions. Import/restore require create+update+delete; truncate/delete use delete; compilation/export use update. Template POST always means create, regardless of an ignored supplied ID. Save authorization uses the same raw JSON or multipart reader as its target controller, including case-insensitive multipart and variable uploads.
- Browser writes use Laravel request-forgery protection. Only successfully authenticated bearer requests bypass ambient-cookie CSRF. All six existing pages include a token and their fetch/jQuery transports attach it only to same-origin unsafe requests.
- File CSV mutations/export and file PDF compilation now require POST. Old anonymous management requests and authenticated GET side effects are intentionally rejected.
- Public requests are limited to 120/minute per observed client IP by default (configurable with a bounded 1–600 limit). Management requests use a separate 120/minute actor limit. No untrusted forwarded IP is automatically trusted. Public CORS accepts only configured exact origins, never wildcard/credentialed CORS. OPTIONS remains source-compatible 405.
- Paths are bounded to 2,048 bytes; malformed percent encodings, invalid UTF-8, controls, backslashes and dot traversal are rejected. Query strings/search/filter values are bounded to 2,000 characters; filter key/value counts to 50; scalar/number structure is validated. Inputs the source coerced unsafely can now return 422. Unknown filter/sort fields remain source errors.
- Ordinary duplicate slugs remain compatible. A secondary collision that could resolve the wrong source record gets further `-ID` suffixes. Cycles and ambiguous duplicate paths fail closed. A dedicated test covers the secondary-collision deviation.
- Legacy internal failures remove SQL, paths, traces and compiler diagnostics. Existing safe static `Missing ID` compatibility remains. No raw database exception is returned publicly.
- Media paths require canonical root-plus-separator containment. Uploads require actual raster-image/PDF/GLB content rather than filename-only acceptance; active SVG is rejected. Private downloads are attachments with no-store/nosniff.

## Compiler execution

`CompilerRunner` requires Linux bubblewrap and prlimit with working unprivileged namespaces. It exposes only read-only compiler/library/font/TeX trees and a private writable job directory, clears inherited credentials, disables host networking and capabilities, and disables TeX shell escape. Typst root/package storage and TeX file access stay inside the isolated filesystem. Templates cannot read `.env`, application files, arbitrary absolute paths or private host files. Only approved module assets are staged; traversal and symlinks are rejected.

Limits: 1 MiB source; 35 seconds wall time; 30 seconds CPU; 1 GiB address space; 256 KiB captured output; 32 MiB per file; 64 MiB/256 files per workspace. Temporary workspaces are removed after success/failure. Output filenames include random suffixes to prevent same-second overwrites. Missing isolation returns a sanitized failure rather than falling back to unsandboxed compilation.

The cloud's native Linux runtime passed real sandboxed Typst and pdflatex tests. Its nested Docker PHP runtime denies namespace creation and correctly fails closed. A production container must explicitly support this isolation; do not use privileged mode or disable the sandbox as a workaround. No production environment was tested or deployed.

## Deactivation and recovery

`ModuleEnabled` protects every registered Catalog entry point. `CatalogBoundary` also checks shared activation state before global middleware can answer CORS or a stale route. `ProtectedModuleActivator` rereads the status file under a shared lock, so a long-lived application observes a disable without refreshing its activator. Optional root/public mounts are owned only when configured; unrelated host assets remain available.

While disabled, public/legacy/page/storage/asset routes and all Catalog Filament resource pages return 404, with and without cached routes/config. Filament navigation and server-side actions also check activation. Core, IAM, System, host login and `/api/v1/health` continue to work.

There are no Catalog queue jobs or schedules in this checkout. A reused compiler service checks activation before beginning work, and controllers check again before saving PDF database metadata. An already-running isolated compile may finish its private file operation; its route remains inaccessible while disabled. In-flight synchronous transactions are allowed to finish atomically rather than being killed mid-write. Restart long-lived workers after code/registration changes; activation checks do not require a restart to deny new Catalog work. Do not introduce a future job without the same execution-time activation guard.

Deactivation changes only module activation/audit state. It never migrates, seeds, drops/truncates data or deletes files. Re-enabling restores existing IDs, relationships, private files, APIs and Filament without imports or manual sync. Lifecycle tests hash all Catalog tables (including ledgers) and fixture files before/after.

## Configuration and release requirements

Keep the existing `CATALOG_DB_*`, private `CATALOG_STORAGE_ROOT`, `CATALOG_TYPST_BIN` and `CATALOG_PDFLATEX_BIN` configuration. Add trusted consumer origins to `catalog.public_api.allowed_origins` in host configuration; the default is no cross-origin grant. Preserve Redis-backed sessions/cache/queues and TLS. Configure explicit trusted proxy addresses at deployment so TLS/client IP metadata is accurate; do not blindly trust arbitrary forwarded headers.

Serve only Laravel `public/`; keep Catalog storage outside the document root and deny direct static aliases/symlinks to private media/PDF directories. A web server serving files directly bypasses any Laravel activation/authentication guard. Module asset publishing is optional; do not publish private storage. Apply the normal Laravel/Filament cache rebuild and worker-restart procedure on reviewed release. There is no migration for this change.

Rollback uses the prior application release plus rebuilt caches/restarted workers; leave database/storage untouched. A rollback to the insecure anonymous legacy release must remain behind an authenticated gateway or have legacy access blocked. Disabling Catalog is the immediate containment option without data loss.

Remaining operational limits: source-compatible parts/facets still materialize the selected series for exact natural sorting/filtering; the 2,000-part test is not a production load test. Counts are grouped to avoid per-series N+1 queries and values are selected-series scoped. There is no shared product cache to invalidate or synchronize. Compiler limits are per job, not a global worker concurrency policy. Production capacity, TLS/proxy/static-server configuration and live WordPress remain deployment verification responsibilities.

See [the test report](public-product-api-test-report.md) for executed commands, results and review findings. Nothing was merged or deployed.
