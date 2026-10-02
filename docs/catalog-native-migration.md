# Catalog native migration — 2 October 2026

## Baseline and boundaries

Before this migration, the implementation contained six Filament Pages pointing to six full HTML Blade documents through a shared iframe. `PageController` rendered the documents when `Sec-Fetch-Dest: iframe` was supplied and otherwise redirected to the panel. Three template editors also used PDF preview iframes. This migration replaces both kinds of frames.

Port 8768 was a temporary Laravel preview, not a separate business service. Controllers, services, repositories, the separate Catalog MySQL connection, private media, template compilers and their response contracts already belong to this application. Preserve them, existing IDs and relationships. No schema migration or data import is needed.

The pre-change Catalog MySQL suite passed: **84 tests, 597 assertions**, including real Typst/pdflatex success and failure, upload persistence/rollback and module toggles. Source inspection covered all six views, their scripts, styles, API controllers and routes. The code graph has known SCSS coverage gaps; direct source inspection was used for the frontend inventory.

## Functional parity checklist

The following contracts were checked against the original source. The verification column distinguishes HTTP/service coverage from browser checks; it does not imply every possible input or device was tested.

| Area | Existing behavior to preserve | Verification |
| --- | --- | --- |
| Panel | Six navigation entries, shared header/sidebar/theme, full-width content, responsive layouts | Native page HTTP tests and desktop/mobile browser review |
| Access | Active verified session; `access.admin` and `catalog.view`; separate mutation, CSV, templates and truncate permissions | Existing authorization tests, guest/direct URL tests, Livewire refresh after revocation |
| Module | Runtime enable/disable hides navigation and blocks pages, assets, APIs and stale registered routes | Module Management and Catalog Filament tests |
| Hierarchy | Expanded tree, selection, 300ms search, highlight/ancestor expansion, category/series create/edit/delete and display order | Catalog behavior tests; browser CRUD/search |
| Deep links | `category`, `series`, `product`, `series_id`, `seriesId`; Spec Search Edit locates series/product; template link selects series | Redirect/config tests and browser refresh/back/forward |
| Category fields | text/textarea/file fields, editing, deletion, image previews; Typst variables payload and scope | Existing variable/upload tests and browser controls |
| Series fields | Product attribute and metadata scopes; key/label/text/number/file/order/required/public-hidden/backend-hidden | Field behavior/validation tests |
| Metadata | Dynamic values, reset/save, retain/replace/remove files; concurrent panel reads | Metadata and media tests; browser save/refresh |
| Products | Existing records/IDs, SKU/name/description and dynamic fields; create/edit/delete, required and numeric validation | Product CRUD/validation tests; browser record round-trip |
| Tables | Search, sort, pagination, empty states, dynamic columns, horizontal scrolling, first three fixed product columns | Browser tables and existing API filters |
| Images/files | Existing authenticated media URLs, previews, downloads, multipart `files[key]` + JSON metadata; image/PDF/GLB and 10MB validation; rollback | Media/image/Typst image compilation tests; browser existing-image preview |
| CSV | Export/download, upload/import, snapshots/history restore/delete; newest first, five rows per page, search/sort | CSV HTTP tests; browser history/export/confirmation |
| Truncate | Case-insensitive TRUNCATE plus reason, cancel/confirmation, submission/server locks, audit/history and counts | Lock, validation, rollback and audit tests; cancel-only browser check |
| Spec Search | Default first root, category multi-select, facet value search/multi-select, removable chips/clear; counts, dynamic columns, images/PDF/Edit | SQL filtering tests and browser facet/table workflow |
| LaTeX | Template create/edit/delete/list/refresh; source snapshot, 250ms MathJax preview; build/download/PDF preview/log/time/correlation ID and button states | Document tests and browser editor/build |
| Global Typst | text/textarea/image variables CRUD/preview; normalized unique token badges at saved caret; line-number editor; template save/load/delete/compile/save PDF/download fallback | Document tests and browser variable/editor/template workflow |
| Series Typst | Series details, metadata/field/product-loop tokens; import global code without replacing title/description; remembered preference; save/compile/PDF/download/refresh | Preference/compiler tests and browser series editor |
| Feedback | Loading, duplicate-submit guards, user-facing error messages and correlation IDs, confirmation dialogs | Failure-path tests and browser console/network |
| PDF viewer | Preview without frames; pagination, zoom/fit, rotation, selectable text, search, download/print and loading/errors | Native PDF browser review with real compiled files |

## Integration decision

Render native Blade content inside the existing Filament Page. Use Filament buttons and the existing theme. Keep proven table/editor interactions as page-scoped widgets managed by Alpine's component lifecycle; they do not provide their own routing, authentication, navigation or application shell. Point all requests at named Laravel endpoints through server-supplied configuration and retain existing services/validation. Legacy document URLs become authenticated compatibility redirects only, regardless of Fetch headers.

Scope the existing layout/table styling to the Catalog content so it cannot affect Filament or other modules. Mount loading feedback inside the workspace, never lock the panel body. Initialize and destroy widgets/tables/observers on page entry/exit. Only approved scalar query parameters enter configuration. Do not globally replace `fetch` or add an independent authentication layer.

The user approved the sole new dependency, `pdfjs-dist`, to replace PDF iframes while retaining document preview capabilities. Jev MCP and CLI tools are already installed; use a bounded Jev patch/evidence review as an additional check, with architectural/security decisions and final tests owned by the main agent.

## Verification record

### Automated checks

- Full MySQL Pest suite: **205 passed, 1342 assertions**. This includes the surrounding application, real Passport OAuth grants, Scout, Redis, Linux backup integration, Typst and pdflatex compilation.
- Final affected Catalog access/native/parity tests: **57 passed, 283 assertions**, including all six pages, guest/inactive/unverified/unauthorized access, module disable/re-enable, navigation, Livewire refresh after disable, legacy URL redirects regardless of Fetch headers and hostile/scalar query serialization.
- Composer strict validation and audit passed. `npm ci` and the Vite/Catalog asset build passed with Node 24.21.0; the sole new dependency is the approved `pdfjs-dist` 6.3.289. PHPStan reports zero errors; Pint and `git diff --check` passed.
- Configuration, route, Blade view and Filament component/icon caches built successfully and were cleared in the isolated verification runtime.

### Browser verification

All browser mutations used the disposable `electronics_hub_catalog_admin_testing` database and its private storage. Existing project/reference data, schema, IDs and connections were not migrated or recreated. A read-only Boost query against the configured target found 12 Typst templates and zero stored absolute PDF URLs; their existing local-file URL contract remains intact. Same-origin download paths outside the Catalog mount are accepted separately from the stricter API endpoint helper.

Verified native hierarchy category/series creation, product create/update/delete, saved values and stable IDs after refresh, required numeric-field rejection, multipart image upload and an actual 8×8 authenticated image preview. Verified Spec Search category selection, facets, filter chips, Clear, Edit links, SKU table filtering, and browser back/forward. Verified CSV export/history and full-snapshot restore; truncate confirmation becomes enabled only with its required inputs and Cancel closes it without truncating data.

Verified LaTeX template save/build and Global/Series Typst save/import/compile using real two-page PDFs. Series import retains its own title/description. The canvas viewer supports page navigation, zoom, fit, rotation controls, selectable TextLayer content, text search and downloads. Find now submits on click as well as Enter. Printing prepares both pages in the current document using their PDF dimensions, invokes `window.print()` and releases temporary canvases, styles and attributes afterward; no frame or external application window is created. The browser check intercepted the print invocation to inspect both A4 pages and cleanup; it did not exercise an operating-system print dialog or physical printer.

Desktop review used 1440px width. Actual DevTools viewport emulation used 390×844: all six page roots measured 343px client/scroll width, with wide tables confined to their scrolling containers. All six loaded without initialization errors, iframe/object/embed elements or a second sidebar. Existing table/editor layouts and fields were retained; local fixes address dark-theme contrast, fixed-column blockers, wrapped CSV controls, dynamic file fields and Bootstrap gutters. LaTeX alert dismissal works without loading the old Bootstrap bundle.

The local file chooser remains outside this verification: Chrome's file-URL permission was not changed. The browser upload check assigned a generated PNG `File` to the real file input and used the normal page submit/API/storage/preview flow; Laravel HTTP tests additionally cover uploaded-file validation, persistence and rollback. These are explicit verification boundaries, not a claim of exhaustive browser coverage.

### Final review and cleanup

The main agent reviewed the bounded Luna inventory, tests and PDF implementation. Source review caught and corrected the missing alert-dismiss handler and PDF Find submission, then browser checks confirmed the fixes. The former Series-template link now navigates within the existing Filament panel, as required by the explicit prohibition on separate Catalog windows. Jev's complete bounded entry review was advisory and requested further review (`safe_to_apply=0.30`); it did not provide a specific defect or operational approval. The main agent completed the source, browser, permissions/module and regression review using the evidence above.

After native verification, removed the six unused full-document views and the old global CSRF bridge. Kept the six legacy URLs as authenticated compatibility redirects and retained required services, APIs and asset routes. Native rendering and initialization contain no iframe/object/embed, standalone HTML loading, `window.top` navigation or `window.open` path. Git retains the original source for comparison.
