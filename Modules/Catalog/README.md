# Catalog migration

The reference is `C:\laragon\www\test` (`http://localhost/test/`). Its application files and database are not migration targets. Catalog uses an independent MySQL connection and storage directory.

## Migration map

All routes below are relative to the configured `/catalog` mount. Original root aliases remain available when `catalog.legacy_urls` is enabled. The PHP filenames and the two different SpecSearch implementations remain distinct.

| Original entry | Laravel entry and controller | Business logic / database | Interface |
| --- | --- | --- | --- |
| `public/catalog_ui.html`, `catalog.php?action=v1.*` | `catalog_ui.html`, `catalog.php` → `CatalogController` | `HierarchyService`, `SeriesFieldService`, `SeriesAttributeService`, `ProductService`, `PublicCatalogService`; native Laravel connection repositories | `catalog::catalog_ui`, original `catalog_ui.js` and CSS |
| `public/catalog-csv.html`, CSV and truncate APIs | `catalog-csv.html`, `api/catalog/csv-*.php`, `api/catalog/truncate.php` → `CatalogOperationsController` | `CatalogCsvService`, `CatalogTruncateService`; snapshot pruning, rollback, advisory lock and audit | `catalog::catalog-csv`, original CSV script and CSS |
| `public/spec-search.html`, SQL SpecSearch APIs | `spec-search.html`, `api/spec-search/*` → `SpecSearchController` | `SpecSearchService`, `SpecSearchRepository`; category filters, facets and 500-result limit | `catalog::spec-search`, original DataTables interface |
| Legacy `v1.specSearch*` actions | `catalog.php` → `CatalogController` | `LegacySpecSearchService`; original static sample data | Original API contract |
| Hierarchy, keyword search, series details and PDF APIs | `api/catalog/{hierarchy,search,pdf}.php`, `api/series/details.php` → read/operations controllers | `CatalogService`, `CatalogRepository`, product/metadata services | Original tree and search callers |
| `public/latex-templating.html`, legacy LaTeX actions | `latex-templating.html`, `catalog.php` → `CatalogController` | `LatexTemplateService`, `LatexBuildService`; singular `latex_template` | `catalog::latex-templating`, original editor, MathJax and PDF preview |
| Separate LaTeX template/variable/compile APIs | `api/latex/*.php` → `LatexController` | `LatexService`, `LatexRepository`; plural `latex_templates`, `latex_variables` | Original API aliases and response envelopes |
| Global and series Typst pages and APIs | `global_typst_template.html`, `series_typst_template.html`, `api/typst/*.php` → `TypstController` | `TypstService`, `TypstRepository`; variables, images, series preferences and compilation | Two original editors, token substitution and PDF previews |
| Media, images, CSS, JavaScript and generated PDFs | `assets/*`, `storage/{media,latex-pdfs,typst-pdfs,typst-assets}/*` → `StorageController`; legacy media download action | `MediaStorageService`, private storage and contained file resolution | Original relative links, versioned asset manifest |

`RequestInput` and `HttpRequestReader` preserve query, JSON and multipart validation. Controllers preserve status codes, message text and the original legacy/direct response envelopes. Repositories execute parameter-bound SQL through Laravel; they do not load the standalone application or PHP request globals.

## Module and database setup

Use PHP 8.4.1+ and Node 24. Module autoloading is defined in this module's `composer.json` and merged by the application's existing Composer merge plugin. `CatalogServiceProvider` registers configuration, views, services, gates and private backup sources. `RouteServiceProvider` registers native Laravel routes. Cached routes also check whether the module is enabled.

Configure deployment-specific `CATALOG_DB_HOST`, `CATALOG_DB_PORT`, `CATALOG_DB_DATABASE`, `CATALOG_DB_USERNAME`, `CATALOG_DB_PASSWORD`, `CATALOG_STORAGE_ROOT`, `CATALOG_PDFLATEX_BIN` and `CATALOG_TYPST_BIN`. Defaults use the separate `electronics_catalog_migrated` database and `storage/app/catalog`. Never point these settings at the reference database.

After importing a read-only database snapshot into the separate target database and copying its media/CSV/PDF/Typst assets into the target storage directory:

```sh
composer dump-autoload
php artisan module:enable Catalog --no-interaction
php artisan module:migrate Catalog --database=catalog --no-interaction
php artisan module:seed Catalog --no-interaction
npm ci
npm run build
php artisan optimize:clear --no-interaction
```

The additive module migration creates the source's ten tables plus the two tables required by the separate LaTeX API. It preserves imported rows and never drops catalog data on rollback. Schema creation happens during migration. The source's initial seed marker, default series metadata and legacy templating-flag synchronization remain data-only bootstrap behavior. An empty database receives the original small baseline tree; an imported database retains its seed marker and data. CSV import remains a **full snapshot**, including pruning omitted rows and clearing catalog rows for a valid header-only file.

## Required Laravel adaptations

- Catalog administration uses the host's web session, active-user gates and CSRF protection. SQL SpecSearch and public catalog reads remain public. Root protected `/api/*` compatibility aliases use Passport. Permissions are `catalog.view`, `catalog.manage`, `catalog.csv`, `catalog.templates` and `catalog.truncate`; the module seeder adds them to existing administrative roles. The Super Admin bypass stays in the host `AppServiceProvider`.
- The six original layouts, compiled CSS, scripts and external library versions are retained. A shared bridge supplies CSRF headers to same-origin AJAX/fetch requests. The root frontend build also builds Catalog's asset manifest without new dependencies.
- Uploads and generated files live under Laravel storage. URLs use the current route mount. Real Laravel `UploadedFile` instances are accepted without weakening upload validation. CSV files and audit logs have no public storage route.
- Typst and pdflatex must be installed in the execution environment. PHP upload limits must permit the original 10 MiB media allowance (`upload_max_filesize` at least 10M; `post_max_size` larger). Compiler output and failure messages retain the source contracts.
- MySQL `TRUNCATE` commits implicitly. The native PDO implementation retains the source lock, foreign-key handling and audit, without an invalid surrounding transaction.
- The original page loads four series panels concurrently. Default metadata initialization reuses rows created by another request when MySQL reports a duplicate key, while preserving existing definitions and values and propagating other database errors.
- Typst image values keep their original `typst-assets/...` paths. Previews resolve those files through the current mount's storage route, and compilation copies the real image from configured Laravel storage into the build directory. The resolver verifies containment in the configured asset directory.

## Verification

Run MySQL tests against a dedicated disposable database. `phpunit.xml` maps Catalog to the primary testing connection through `CATALOG_CONNECTION=default`, so database refresh and transactions cover its tables. Catalog tests explicitly skip other drivers.

```sh
php artisan test --compact tests/Feature/CatalogParityTest.php tests/Feature/CatalogBehaviorTest.php tests/Feature/CatalogDocumentTest.php
```

These tests cover route/view/assets integration, authorization, request contracts, initial data, hierarchy, scoped fields, metadata, products, SQL filters, CSV snapshots/restore/truncate, media validation/rollback, template CRUD/preferences and real compiler success/failure with PDF downloads. Real compiler cases require installed Linux binaries. Original-project write flows are compared using a verbatim private application copy and independent database snapshot; the actual reference is viewed only through read-only flows.

The legacy LaTeX implementation requires a nonempty description when saving a template: its source service normalizes an empty description to `null`, while its repository accepts `string`. This existing source failure is retained; it is not silently replaced with a different validation rule.
Its MathJax preview also reports an unknown `document` environment for a full pdflatex document in both the reference copy and target; actual LaTeX PDF compilation and preview succeeded on both sides.

### Recorded validation — 2 October 2026

Validation ran in Ubuntu/WSL with PHP 8.4.26, Node 24.21.0, MySQL, Redis, Typst and pdflatex, using independent catalog and host-browser database copies.

| Check | Observed result |
| --- | --- |
| Composer strict validation and audit; `npm ci` and root build | Passed; no dependency changes or advisory findings |
| Pint and PHPStan after the final PHP edit | Passed; PHPStan reports zero errors |
| Full MySQL Pest suite before the final metadata race regression | 153 passed, 1103 assertions; real OAuth, Scout, Redis and Linux backup integration included |
| Catalog suite after the concurrency fix | 45 passed, 437 assertions |
| Metadata tests after the final static-analysis adjustment | 5 passed, 68 assertions |
| Typst tests after the native image-path fix | 7 passed, 91 assertions; real image-token compilation and byte-identical asset copying included |
| Module discovery, route/controller/view resolution and framework caches | Passed, including disabled-module route rejection |
| Original application safety audit | All 2557 application file hashes and all ten source-table checksums match the starting baseline |

Browser comparisons covered hierarchy create/edit, series selection, numeric required fields, product creation and validation, metadata values, SQL category/facet filtering, table search and pagination, navigation, sidebar collapse, CSV history/export/download and truncate confirmation/cancel, legacy LaTeX save/build/PDF preview, global Typst variables/token insertion/save/compile/PDF preview, and series Typst import/preferences/save/compile/refresh. Mutation comparisons used the private verbatim reference copy; the actual original project was kept read-only. The two downloaded 6101-product CSV exports have identical SHA-256 hashes. A newly created series was rechecked through the original four concurrent panel requests after the metadata race fix, with no browser errors.

All six pages were also compared in independent 390×844 iframe viewports, including mobile navigation, category/SKU filtering, template editors, existing image-variable previews and PDF output. Both sides had a 375 px content width after the scrollbar. The CSV page retained the original 779 px horizontal overflow; the other five page roots had no horizontal overflow. These are browser layout checks, not touch-device tests. The advertised browser viewport override itself did not work, so the iframe comparisons supplied the actual narrow layout viewports.

This comparison exposed a missing native storage lookup for Typst images. The fix restored the imported 257×196 image preview and real image-token PDF output on the target. Browser compilation copied the original image bytes instead of creating a placeholder. The first added compile-test PNG fixture had an invalid IDAT CRC; it was replaced with a valid embedded PNG, keeping CI free of a new GD dependency, and the final seven Typst tests passed.

One browser check remains unverified: the Chrome extension still refuses local file selection without its **Allow access to file URLs** permission. A final chooser attempt rejected the file before any upload request was sent. Uploaded-file validation, CSV import rollback and media/image persistence were exercised through Laravel HTTP tests. These observations do not establish complete browser coverage or 100% functional parity.
The user explicitly accepted this browser-upload verification limit on 2 October 2026 and requested that Chrome permissions remain unchanged. The implementation and all executable checks have been reviewed; no unresolved migration-related runtime error was observed after the metadata and native image-path fixes. Complete browser coverage and 100% functional parity are not claimed.

Ignored verification logs and paired desktop/narrow-layout screenshots are saved under `storage/app/private/migration-reference/`. Temporary comparison HTML files under ignored `public/build/` only frame the unchanged application pages. The temporary preview uses port 8768 and an isolated host-browser database; deployment uses the environment-specific settings described above. Browser review records are confined to the target/reference copies. At the time of this recorded validation, no code had been committed or published.

The publication workflow installs checksum-verified Typst 0.15.1 and pdflatex on CI. Real Typst tests resolve the configured binary or `PATH`, without depending on a workstation-specific installation path. Catalog asset builds normalize text line endings before hashing, and CI checks that rebuilding produces no changes to the committed assets.

Sol performed the architecture decisions and final review of the bounded Luna investigations and test preparation. Laravel Boost and direct source tracing supplied version/schema/route context; Chrome supplied the interface comparison; Jev evaluated the browser-upload evidence as a blocked file selection, independently distinguishing it from passing automated tests.
