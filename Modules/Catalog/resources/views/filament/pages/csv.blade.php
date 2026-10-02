<div class="container-fluid catalog-shell px-0 app-shell">
        <div class="row flex-lg-nowrap g-0">

            <div class="col-12 content-column">


                <div id="status-message"></div>

                <div class="section row g-0 no-bootstrap-gap">
                    <div class="panel panel-csv csv-section">
                        <h2>CSV Import / Export</h2>
                        <div class="csv-actions">
                            <x-filament::button color="primary" :loading-indicator="false" type="button" id="csv-export-button">Export Catalog CSV</x-filament::button>
                            <form id="csv-import-form" enctype="multipart/form-data">
                                <label for="csv-import-file">Import CSV:</label>
                                <input type="file" id="csv-import-file" accept=".csv">
                                <x-filament::button color="primary" :loading-indicator="false" type="submit" id="csv-import-submit">Import</x-filament::button>
                            </form>
                            <x-filament::button color="danger" :loading-indicator="false" type="button" id="truncate-button">Truncate Catalog</x-filament::button>
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
            </div>
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
                        <x-filament::button color="gray" :loading-indicator="false" type="button" id="truncate-cancel-button">Cancel</x-filament::button>
                        <x-filament::button color="danger" :loading-indicator="false" type="submit" id="truncate-confirm-button" disabled>Confirm
                            Truncate</x-filament::button>
                    </div>
                </form>
            </div>
        </div>
    </div>
