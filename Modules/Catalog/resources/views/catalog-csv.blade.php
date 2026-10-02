<!DOCTYPE html>
<html lang="en">

<head>
    <base target="_top">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Catalog CSV Import / Export</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="assets/css/catalog-shell.css?v={{ $catalogAssetVersions['css/catalog-shell.css'] ?? '' }}">
    <link rel="stylesheet" href="assets/css/catalog_ui.css?v={{ $catalogAssetVersions['css/catalog_ui.css'] ?? '' }}">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="assets/js/catalog-csrf-bridge.js?v={{ $catalogAssetVersions['js/catalog-csrf-bridge.js'] ?? '' }}"></script>
</head>

<body data-logging-enabled="true">
    <div class="container-fluid catalog-shell px-0 app-shell">
        <div class="row flex-lg-nowrap g-0">

            <main class="col-12 content-column">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                    <div>
                        <h1 class="mb-1">Catalog CSV Tools</h1>
                        <p class="text-muted mb-0">Export, import, restore, and audit CSV catalog snapshots.</p>
                    </div>
                </div>

                <div id="status-message"></div>

                <div class="section row g-0 no-bootstrap-gap">
                    <div class="panel panel-csv csv-section">
                        <h2>CSV Import / Export</h2>
                        <div class="csv-actions">
                            <button type="button" id="csv-export-button">Export Catalog CSV</button>
                            <form id="csv-import-form" enctype="multipart/form-data">
                                <label for="csv-import-file">Import CSV:</label>
                                <input type="file" id="csv-import-file" accept=".csv">
                                <button type="submit" id="csv-import-submit">Import</button>
                            </form>
                            <button type="button" id="truncate-button" class="danger-button">Truncate Catalog</button>
                        </div>
                        <div class="truncate-warning">
                            Truncate wipes every category, series, product, and custom field definition/value before
                            reseeding
                            the baseline tree. Use only when you are ready to import a fresh CSV snapshot.
                        </div>
                        <div class="csv-history">
                            <h3>CSV History</h3>
                            <table id="csv-history-table"
                                class="table table-striped table-hover align-middle datatable w-100">
                                <thead>
                                    <tr>
                                        <th>Type</th>
                                        <th>Name</th>
                                        <th>Timestamp</th>
                                        <th>Size (bytes)</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="5">Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="truncate-history">
                            <h3>Truncate Audit History</h3>
                            <p class="truncate-history-note">Latest destructive actions with operator reason and audit
                                identifiers.</p>
                            <table id="truncate-audit-table"
                                class="table table-striped table-hover align-middle datatable w-100">
                                <thead>
                                    <tr>
                                        <th>Timestamp</th>
                                        <th>Reason</th>
                                        <th>Deleted</th>
                                        <th>Audit ID</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <td colspan="4">No truncate actions logged.</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </main>
        </div>

        <div id="truncate-modal-backdrop" class="modal-backdrop" hidden></div>
        <div id="truncate-modal" class="modal" role="dialog" aria-modal="true" aria-labelledby="truncate-modal-title"
            hidden>
            <div class="modal-content">
                <h3 id="truncate-modal-title">Confirm Catalog Truncate</h3>
                <p>Type <strong>TRUNCATE</strong> and provide a short reason before continuing. This will delete every
                    record.</p>
                <form id="truncate-form">
                    <label for="truncate-confirm-input">Confirmation Text</label>
                    <input type="text" id="truncate-confirm-input" autocomplete="off" placeholder="TRUNCATE" required>
                    <label for="truncate-reason-input">Reason</label>
                    <textarea id="truncate-reason-input" rows="3" maxlength="256"
                        placeholder="Describe why you are truncating." required></textarea>
                    <div id="truncate-modal-error" class="modal-error" aria-live="polite"></div>
                    <div class="modal-actions">
                        <button type="button" id="truncate-cancel-button">Cancel</button>
                        <button type="submit" id="truncate-confirm-button" class="danger-button" disabled>Confirm
                            Truncate</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
    <script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
    <script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>
    <script src="assets/js/app_loading.js?v={{ $catalogAssetVersions['js/app_loading.js'] ?? '' }}"></script>
    <script src="assets/js/app_error.js?v={{ $catalogAssetVersions['js/app_error.js'] ?? '' }}"></script>
    <script type="module" src="assets/js/catalog_csv.js?v={{ $catalogAssetVersions['js/catalog_csv.js'] ?? '' }}"></script>
</body>

</html>
