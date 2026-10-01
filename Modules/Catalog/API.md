# API Compatibility Reference

The native Laravel module uses /catalog by default. Prefix the paths below with /catalog, for example /catalog/catalog.php?action=v1.ping. legacy_urls=true also exposes the original root URLs; project_root_urls=true exposes /public/* for old repository-root deployments. Host APP_URL or catalog.base_url supplies any deployment subdirectory. Fields remain case-sensitive.

## JSON, methods and input

File APIs retain success {"success":true,"data":...,"correlationId":"..."} and errors {"error":{"code":"...","message":"...","correlationId":"..."}}. Legacy errors retain {"success":false,"errorCode":"...","message":"...","details":...,"correlationId":"..."}. Legacy successful actions without a result omit data. Error casing, validation messages, numeric/null values and status codes stay distinct.

JSON replies carry Content-Type: application/json; charset=utf-8 and X-Correlation-ID. Legacy actions and selected file APIs accept inbound IDs; Typst templates/variables/compile retain their generated-ID behaviour. Downloads stream files with original attachment names, MIME types, cache policy and IDs.

The module skips Laravel trimming/empty-to-null conversion only at exact Catalog API addresses. It reads raw JSON, query/form values, native uploaded files and the real HTTP method. JSON/form method spoofing does not change legacy method validation.

Hierarchy, search, CSV, PDF and file Spec Search retain their original lack of explicit method restrictions. Series details accepts GET. Typst templates accept GET/POST/PUT/DELETE; variables GET/POST/DELETE; compile POST; preferences GET/PUT. LaTeX templates accept GET/POST/PUT/DELETE; variables GET/POST/DELETE (PUT is 405); compile POST. Other methods retain each controller's own 405 behaviour.

## File endpoints


| Endpoint | Inputs / behaviour |
|---|---|
| `/api/catalog/hierarchy.php` | Category, series and product tree; Typst enablement flag retained. |
| `/api/catalog/search.php` | Query `q`; flat category/product matches. |
| `/api/catalog/csv-import.php` | Multipart `file`; success HTTP 202. |
| `/api/catalog/csv-export.php` | Export catalog and return stored file metadata. |
| `/api/catalog/csv-download.php` | Query `id`; streams stored CSV. |
| `/api/catalog/csv-history.php` | Stored CSV history and truncate state. |
| `/api/catalog/csv-restore.php` | JSON `id`; restore selected stored CSV. |
| `/api/catalog/truncate.php` | JSON `reason`, `confirmToken` (or `token` alias), optional `correlationId`; destructive operation. |
| `/api/catalog/pdf.php` | Query `id` identifies legacy LaTeX template; compiles and returns PDF metadata. |
| `/api/spec-search/root-categories.php` | Returns `data.categories`. |
| `/api/spec-search/product-categories.php` | Query `root_id`; returns `data.groups`. |
| `/api/spec-search/facets.php` | JSON `category_ids`; returns `data.facets`. |
| `/api/spec-search/products.php` | JSON `category_ids`, `filters`; returns `data.items` and `data.total`; maximum 500 products. |
| `/api/series/details.php` | GET query `id`; series details. |
| `/api/typst/templates.php` | GET query `id`/`seriesId`; POST/PUT JSON `title`, `description`, `typst`, optional `seriesId`, `lastPdfPath`; PUT also `id`; DELETE query `id`. |
| `/api/typst/variables.php` | GET query `id`/`seriesId`; POST creates or updates with `key`, `type`, `value`, optional `id`, `seriesId`; supports multipart file uploads; DELETE query `id`, optional `seriesId`. |
| `/api/typst/compile.php` | POST JSON `typst`, optional `seriesId`; returns PDF `url`, `path`. |
| `/api/typst/series-preferences.php` | GET query `seriesId`; PUT JSON `seriesId`, `lastGlobalTemplateId` (nullable). |
| `/api/latex/templates.php` | GET query `series_id`; POST/PUT JSON `title`, `description`, `latex`, optional `seriesId`; PUT also `id`; DELETE query `id`. POST success HTTP 201. |
| `/api/latex/variables.php` | GET globals; POST JSON `key`, `type`, `value`, optional `id`; DELETE query `id`. |
| `/api/latex/compile.php` | POST JSON `latex`, `series_id`; returns PDF `url`, `path`. |

Spec search keeps `seriesImage` and `pdfDownload`. The PDF source is the latest series Typst PDF when Typst is enabled, otherwise the `series_product_spec` metadata file.

Typst multipart variables use key/type/value, optional id/seriesId, and file field file. Image variables validate a real image. LaTeX templates also accept form fields, source aliases latex_content / latex_code, and templateTitle / templateDescription.

## Legacy catalog actions


Call `/catalog.php?action=<action>` with the existing query/JSON/multipart fields. The controller preserves validation, payloads, transactions and file responses. Keep these addresses when integrating WordPress or migrating to Laravel.

| Action | Method |
|---|---|
| `v1.ping` | GET |
| `v1.listHierarchy` | GET |
| `v1.saveNode` | POST |
| `v1.deleteNode` | POST |
| `v1.setSeriesTypstTemplating` | PUT |
| `v1.listSeriesFields` | GET |
| `v1.publicCatalogSnapshot` | GET |
| `v1.specSearchRootCategories` | GET |
| `v1.specSearchProductCategories` | GET |
| `v1.specSearchFacets` | POST |
| `v1.specSearchProducts` | POST |
| `v1.listLatexTemplates` | GET |
| `v1.getLatexTemplate` | GET |
| `v1.createLatexTemplate` | POST |
| `v1.updateLatexTemplate` | PUT |
| `v1.deleteLatexTemplate` | DELETE |
| `v1.buildLatexTemplate` | POST |
| `v1.getSeriesAttributes` | GET |
| `v1.saveSeriesField` | POST |
| `v1.saveSeriesAttributes` | POST |
| `v1.deleteSeriesField` | POST |
| `v1.listProducts` | GET |
| `v1.saveProduct` | POST |
| `v1.deleteProduct` | POST |
| `v1.truncateCatalog` | POST |
| `v1.listCsvHistory` | GET |
| `v1.exportCsv` | POST |
| `v1.importCsv` | POST |
| `v1.restoreCsv` | POST |
| `v1.downloadCsv` | GET |
| `v1.downloadMedia` | GET |
| `v1.deleteCsv` | POST |

Node/product/field save actions retain their existing `id`, `parentId`, `seriesId`, `fieldKey`, `fieldType`, `fieldScope`, `sku`, `name`, custom values and upload fields. `listSeriesFields` accepts `seriesId` and optional `scope`. Attribute/product saves accept JSON or multipart `metadata` plus `files`. `setSeriesTypstTemplating` accepts `seriesId` and boolean `enabled`.

Legacy LaTeX get/update/delete/build actions use query `id`. CSV restore/download/delete actions use the selected stored file `id`. Media downloads use the stored relative media `id`.

The legacy Spec Search actions require `root_id` and retain their original category/filter formats. They are separate contracts from the file endpoints above.

## Storage and installation

HTTP never creates a database, alters tables, rebuilds indexes or seeds initial data. Use the controlled migrations and Seeder in [README.md](README.md). Legacy LaTeX uses latex_template; file LaTeX uses latex_templates / latex_variables. These are separate contracts.

Declared module routes serve media, PDFs and Typst images from host-configured directories. Canonical file URLs include the module prefix and host subdirectory. Existing stored paths are resolved without rewriting their database values.

## Historical adapters

| Address | Behaviour |
|---|---|
| /api/catalog/hierarchy.php, /api/catalog/search.php | Original public file controller. |
| /api/spec-search/{root-categories,product-categories,facets,products}/index.php | Original file API; suffixless aliases also work. |
| /api/typst/templates.php, /api/typst/variables.php | root_typst_api=public selects public controller; root selects original root adapter. |
| /legacy/api/typst/templates.php, /legacy/api/typst/variables.php | Explicit original root adapter. |
| /api/typst/compile.php | Original public compiler. |
| /public/* | Available with project_root_urls=true. |

Root Typst variables are global-only, with different validation and no modern multipart/category/series behaviour. Root templates retain different validation/scoping/DELETE behaviour. Canonical /catalog/api/typst/* always uses the public controllers. The unused api/spec-search/db_connect.php was an internal connection include, not a JSON API; it has no public route.

## Preserved original defects

The user chose strict preservation. Root Typst template DELETE returns HTTP 500 Missing ID even with a valid id. Typst textarea variables save as text. Legacy LaTeX empty descriptions produce the existing internal type error; successful CRUD/compile fixtures use a nonempty description. Compatibility equality for these failures is reported separately from functional success.

WordPress clients keep their URLs, methods and fields using the relevant compatibility configuration. Authentication, cross-origin and proxy settings belong to the host. Temporary host verification does not establish a real WordPress deployment result.
