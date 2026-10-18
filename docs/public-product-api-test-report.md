# Public Product API verification report

Verified in Codex Cloud Linux on 2026-10-18. Target baseline: `7f55314392d6db53db170e2e41a64f7e6e2229c3` (`codex/integrate-boilerplate-catalog`). Source: `c10faf4b5314668f7695a9c138a8e9333bee469d` (`plan/public-product-api-v1`). Work branch: `codex/secure-public-product-api-v1`.

The baseline ran **146 tests / 1,203 assertions** successfully before implementation. The final local complete run executed **338 tests / 3,915 assertions**, with **0 failures, 0 errors and 0 skips**; exit **0**, duration **62.74 seconds**. The additional real-source differential execution passed **50/50 cases**, exit **0**. Counts below overlap across functional areas; the complete-run total is not their sum.

## Verification table

| Test area | Passed | Failed | Not run | Evidence |
| --- | --- | --- | --- | --- |
| Public API | 130 cases / 479 assertions | 0 | 0 | `tests/Feature/PublicProductApiTest.php`; all ten endpoints, empty/missing/type/error/order/query/method/header/Unicode/duplicate cases; 2,000-part series |
| Contract parity | 50/50 source/target cases | 0 | 0 | `tests/Support/ComparePublicProductApi.php`; source-generated `tests/Fixtures/public-product-api/source-oracle.json`; only top-level correlation ID normalized |
| Security | 54 authorization/transport cases; 4 compiler-boundary cases | 0 | 0 | `CatalogApiSecurityTest`: 345 assertions; `CatalogCompilerSecurityTest`: 12 assertions; real expired tokens, raw JSON/multipart bypass regressions, permissions, inactive accounts, CSRF, protected media, CORS/throttle and malformed encodings |
| Catalog CRUD | Covered in 15 Catalog integration and 26 Catalog Filament cases | 0 | 0 | Real category/series/product/field/metadata writes and underlying SQL values; authorization boundaries; live public visibility of changes |
| CSV | Import/export/history/restore and destructive confirmation cases passed | 0 | 0 | `CatalogIntegrationTest`, `CatalogApiSecurityTest`; disposable Catalog database only |
| Typst/LaTeX | 3 real PDF cases / 37 assertions; compiler security probes passed | 0 | 0 | `CatalogCompilerTest`, `CatalogCompilerSecurityTest`; real Typst/pdfTeX outputs, protected downloads and sandbox denial of private-file access |
| Filament | 26 Catalog cases / 326 assertions; 2 IAM cases / 53 assertions; 11 admin cases / 100 assertions | 0 | 0 | Existing creation/view/search/edit/delete tests retained; disabled resource pages require 404 |
| Passport/IAM | 11 Passport cases / 100 assertions plus existing IAM/session tests | 0 | 0 | PKCE, real token issuance, scopes + policy checks, inactive users, role/permission protections; real HTTP Passport write also passed |
| Database | 1 legacy/fresh Catalog migration case / 14 assertions; full MySQL suite passed | 0 | 0 | Separate `electronics_hub_testing` and `electronics_catalog_testing`; protected migration connection checks and idempotent seeds |
| Module disable/enable | 3 cases / 1,847 assertions | 0 | 0 | `CatalogModuleLifecycleTest` + `CatalogLifecycleProbe`; long-lived application and fresh cached/uncached kernels; every alias/page/file kind, 20 Filament URLs, host services, all table/ledger/file hashes |
| Full regression | 338 tests / 3,915 assertions | 0 | 0 | Final complete Pest JUnit: tests=338, failures=0, errors=0, skipped=0; includes Core/IAM/System, Scout, settings/audit, AI/MCP fakes, backups, Redis and PDF integrations |
| HTTP integration | 1 real-socket case / 29 assertions | 0 | 0 | `CatalogHttpTest`: all ten public URLs, anonymous denial, JWT-authorized write, browser login, real CSRF 419/200 and Spec Search |
| WordPress integration | Reproducible source-contract JSON checks passed | 0 | Live WordPress site | No authorized WordPress installation supplied; no WordPress write/deployment attempted |

## Environment and exact commands

Native Linux PHP **8.4.26** with required extensions and **512 MiB** PHP memory; Composer **2**; Node **24.19.0**, npm **11.9.0**; MySQL **8.0.46** and Redis **7**; Typst **0.15.1**; pdfTeX **1.40.26**; bubblewrap and prlimit. MySQL/Redis run as local disposable development services. The test database helper refuses unsafe Catalog database names, a shared host schema, and unsupported connection/prefix settings.

The prepared cloud instance uses the native PHP/Composer helpers below because nested Docker disallows the namespaces required for secure compilation. A normal Linux installation with the documented dependencies runs the same commands directly. The nested container's compiler-unavailable path was separately observed to fail closed; successful PDF results here came from the native sandbox.

```bash
cd /workspace/Electronics-hub
export PATH=/workspace/onboarding/native-php/bin:/workspace/onboarding/typst-x86_64-unknown-linux-musl:$PATH

composer validate --strict
composer audit
npm ci --cache /workspace/.npm-cache
npm run build
(cd Modules/Catalog && npm ci --cache /workspace/.npm-cache && npm run build:assets)
php vendor/bin/pint --dirty --format agent
php vendor/bin/pint --test
composer run analyse
php artisan route:list --json
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan filament:optimize
php artisan optimize:clear
php artisan filament:optimize-clear

DB_CONNECTION=mysql DB_DATABASE=electronics_hub_testing \
CATALOG_DB_DATABASE=electronics_catalog_testing \
RUN_REDIS_INTEGRATION=true RUN_CATALOG_COMPILER_INTEGRATION=true \
php artisan test --compact --log-junit /tmp/public-api-evidence/final-junit.xml
```

Every final command above exited **0**. Composer audit returned **“No security vulnerability advisories found.”** Larastan returned **“No errors.”** Both asset builds passed and Catalog assets/manifest match their source build. No dependency declarations or lockfiles changed.

Focused reruns used the same database/integration environment followed by:

```bash
php artisan test --compact tests/Feature/PublicProductApiTest.php
php artisan test --compact tests/Feature/CatalogApiSecurityTest.php tests/Integration/CatalogCompilerSecurityTest.php
php artisan test --compact tests/Feature/CatalogModuleLifecycleTest.php
php artisan test --compact tests/Feature/PassportSearchTest.php tests/Integration/CatalogHttpTest.php
```

The final complete run includes all these cases. Instance evidence is in `/tmp/public-api-evidence/`: `final-full-tests.log`, `final-full-tests.exit`, `final-junit.xml`, `final-source-parity.log`, `pint-test.log`, `analyse.log`, `composer-audit.log`, `npm-host.log`, `npm-catalog.log`, and `framework-caches.exit`. These are local evidence files, not credentials or repository fixtures.

To repeat the actual source comparison, check out the exact source commit read only, then run with the same disposable database environment:

```bash
php tests/Support/ComparePublicProductApi.php /path/to/Foundational-Electronics-Core-Systems
```

The runner verifies the source HEAD, uses deterministic fixtures and existing source HTTP capture, compares source/target status and complete normalized JSON, exits nonzero on mismatches, and refreshes the committed oracle only after a complete match. Default tests need no external source checkout or network. The source working tree remained clean.

## Diagnosed failures and repairs

- Initial environment defaults allowed only 128 MiB and the first-install merge plugin upgraded a dependency. Onboarding restored the committed dependency versions, used 512 MiB and installed from lockfiles; Laravel 13.34.0 was verified.
- Exact legacy `/api/series/details.php` was initially shadowed by the public path matcher. Route registration now puts exact legacy aliases first; enabled/disabled/cached tests caught and verified the fix.
- Independent Astra review found content-type/multipart create-vs-update permission bypasses, a missing Spec Search CSRF token and ownership of disabled compatibility mounts. All were fixed with executable regressions; the follow-up review found no remaining concrete blocker.
- Initial regressions exposed asset-cache header drift and a safe legacy `Missing ID` response change. Asset compatibility and the documented safe error were restored; sensitive internal diagnostics remain removed.
- The new HTTP test initially used a different Passport signing-key path in its child process when run after existing OAuth tests. It now generates isolated ephemeral keys shared by both processes and passes in that order and the full suite. No production keys or credentials were committed.
- Invalid UTF-8 is rejected before Symfony route matching can produce a debug error. Public `Cache-Control` uses the existing explicit-header bag to preserve source `no-store` exactly.

No assertions were disabled and no tests were deleted. The existing disabled-Filament assertion changed from 403 to the required 404; successful legacy regressions now authenticate explicitly rather than retaining anonymous write access.

## CI and limits

Local verification is complete. GitHub Actions status is pending initial branch publication; the PR check result will be recorded before final delivery. The workflow now installs bubblewrap/prlimit alongside its existing MySQL/Redis/PDF prerequisites.

Live WordPress, production TLS/reverse-proxy/static-server configuration, production load/capacity and Octane/Swoole were not run and are not claimed. The tested long-lived application is a Laravel kernel/activator lifecycle, not an Octane deployment. The selected-series in-memory natural sort/facets and per-job rather than global compiler quotas are explicit operational limits. No production migration, deployment or merge occurred.
