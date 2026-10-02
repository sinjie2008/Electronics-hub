<div class="container-fluid px-0 app-shell">
        <div class="row flex-lg-nowrap g-0">

            <div class="col-12 content-column">



                <div id="statusAlert" class="alert-placeholder" aria-live="polite"></div>

                <section class="template-panel">
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-3">
                <div>
                    <h2 class="mb-0">Templates Catalog</h2>
                    <p class="text-muted mb-0">Search, sort, and manage existing LaTeX templates.</p>
                </div>
                <x-filament::button color="gray" outlined :loading-indicator="false" type="button" id="refreshTemplatesButton" class="mt-3 mt-md-0">
                    Refresh List
                </x-filament::button>
            </div>
            <div class="table-responsive">
                <table id="latexTemplatesTable" class="table table-striped table-hover align-middle w-100">
                    <thead>
                        <tr>
                            <th scope="col">Title</th>
                            <th scope="col">Description</th>
                            <th scope="col">Created</th>
                            <th scope="col">Updated</th>
                            <th scope="col">Actions</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </section>

        <div class="row g-4">
            <div class="col-12 col-lg-6">
                <section class="template-panel h-100">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="mb-0">Template Editor</h2>
                        <span id="formModeBadge" class="badge bg-info text-dark">New</span>
                    </div>
                    <form id="templateForm" novalidate>
                        <input type="hidden" id="templateId">
                        <div class="mb-3">
                            <label for="templateTitle" class="form-label">Title<span class="text-danger">*</span></label>
                            <input type="text" id="templateTitle" class="form-control" placeholder="Invoice Template" required>
                        </div>
                        <div class="mb-3">
                            <label for="templateDescription" class="form-label">Description</label>
                            <textarea id="templateDescription" class="form-control" rows="3" placeholder="Optional summary for quick reference."></textarea>
                        </div>
                        <div class="mb-4">
                            <label for="latexSource" class="form-label">LaTeX Source<span class="text-danger">*</span></label>
                            <textarea id="latexSource" class="form-control" rows="15" spellcheck="false" placeholder="\\documentclass{article}\n\\begin{document}\n Hello LaTeX!\n\\end{document}" required></textarea>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <x-filament::button color="gray" outlined :loading-indicator="false" type="button" id="newTemplateButton">New Template</x-filament::button>
                            <x-filament::button color="primary" :loading-indicator="false" type="submit" id="saveTemplateButton">Save Template</x-filament::button>
                            <x-filament::button color="success" :loading-indicator="false" type="button" id="buildTemplateButton">Build LaTeX &amp; Generate PDF</x-filament::button>
                            <x-filament::button color="danger" outlined :loading-indicator="false" type="button" id="deleteTemplateButton">Delete Template</x-filament::button>
                        </div>
                    </form>
                </section>
            </div>
            <div class="col-12 col-lg-6">
                <section class="template-panel mb-4">
                    <h2 class="mb-3">Live Preview</h2>
                    <div id="latex-preview-render" class="mb-3 text-muted">
                        Start typing LaTeX to see a live preview rendered with MathJax.
                    </div>
                    <div>
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="mb-0 fs-6">Raw Source Snapshot</h3>
                            <small class="text-muted">Auto-updated</small>
                        </div>
                        <pre id="latex-preview-source"></pre>
                    </div>
                </section>
                <section class="template-panel">
                    <h2 class="mb-3">PDF Output</h2>
                    <div class="d-flex flex-wrap align-items-center gap-3 mb-3">
                        <a id="pdfDownloadLink" class="btn btn-outline-primary d-none" target="_blank" rel="noopener">Download Latest PDF</a>
                        <span id="pdfStatusText" class="text-muted"></span>
                    </div>
                    <div id="pdfPreviewFrame" class="pdf-frame d-none" aria-label="PDF preview"></div>
                    <div class="mt-4">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h3 class="mb-0 fs-6">Build Log</h3>
                            <small class="text-muted" id="buildMetaDetails"></small>
                        </div>
                        <pre id="latex-build-log" class="mb-0"></pre>
                    </div>
                </section>
            </div>
        </div>
            </div>
        </div>
    </div>
