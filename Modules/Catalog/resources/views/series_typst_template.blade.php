<!DOCTYPE html>
<html lang="en">

<head>
    <base target="_top">
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Series Typst Template</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="assets/css/catalog-shell.css?v={{ $catalogAssetVersions['css/catalog-shell.css'] ?? '' }}">
    <link rel="stylesheet" href="assets/css/latex-templating.css?v={{ $catalogAssetVersions['css/latex-templating.css'] ?? '' }}">
    <link rel="stylesheet" href="assets/css/series_typst_template.css?v={{ $catalogAssetVersions['css/series_typst_template.css'] ?? '' }}">
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="assets/js/catalog-csrf-bridge.js?v={{ $catalogAssetVersions['js/catalog-csrf-bridge.js'] ?? '' }}"></script>
</head>

<body data-logging-enabled="true">
    <div class="container-fluid px-0 app-shell">
        <div class="row flex-lg-nowrap g-0">

            <main class="col-12 content-column">
                <div class="d-flex flex-column flex-lg-row align-items-lg-center justify-content-between gap-3 mb-3">
                    <div>
                        <h1 class="h3 mb-1">Series Typst Template</h1>
                        <p class="text-muted mb-0">Generate PDF for a specific series using Typst.</p>
                    </div>
                </div>

                <div id="statusAlert" class="alert-placeholder" aria-live="polite"></div>

                <!-- Series Details -->
                <section class="template-panel mb-4">
                    <h2 class="h5 mb-3">Series Details</h2>
                    <div class="row g-3" id="seriesDetailsContainer">
                        <div class="col-md-3">
                            <label class="fw-bold small text-muted">Name</label>
                            <div id="seriesName">Loading...</div>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-bold small text-muted">Node ID</label>
                            <div id="seriesId">-</div>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-bold small text-muted">Parent ID</label>
                            <div id="seriesParentId">-</div>
                        </div>
                        <div class="col-md-3">
                            <label class="fw-bold small text-muted">Type</label>
                            <div id="seriesType">series</div>
                        </div>
                    </div>
                </section>

                <!-- Series Metadata -->
                <section class="template-panel mb-4">
                    <h2 class="h5 mb-3">Series Metadata</h2>
                    <div class="border p-3 bg-white rounded" id="seriesMetadataContainer">
                        <span class="text-muted">Loading metadata...</span>
                    </div>
                </section>

                <!-- Series Custom Fields -->
                <section class="template-panel mb-4">
                    <h2 class="h5 mb-3">Series Custom Fields</h2>
                    <div class="border p-3 bg-white rounded" id="seriesCustomFieldsContainer">
                        <span class="text-muted">Loading custom fields...</span>
                    </div>
                </section>

                <!-- Import Global Template -->
                <section class="template-panel mb-4">
                    <h2 class="h5 mb-3">Import Global Template</h2>
                    <div class="row align-items-center">
                        <div class="col-md-8">
                            <select id="templateSelect" class="form-select">
                                <option value="">Select a global template...</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <button type="button" id="loadTemplateBtn" class="btn btn-outline-primary w-100">Import
                                Template</button>
                        </div>
                    </div>
                </section>

                <!-- Series Template Details -->
                <section class="template-panel mb-4">
                    <h2 class="h5 mb-3">Series Template Details</h2>
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="seriesTemplateTitle" class="form-label fw-bold">Series Template Title <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="seriesTemplateTitle"
                                placeholder="Enter template title" required>
                        </div>
                        <div class="col-md-6">
                            <label for="seriesTemplateDesc" class="form-label fw-bold">Description <span
                                    class="text-danger">*</span></label>
                            <input type="text" class="form-control" id="seriesTemplateDesc"
                                placeholder="Enter description" required>
                        </div>
                    </div>
                </section>

                <!-- Typst Compile Section -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-lg-6">
                        <section class="template-panel h-100">
                            <h2 class="mb-3">Code Editor</h2>
                            <textarea id="latexSource" class="form-control" rows="20" spellcheck="false"
                                placeholder="Select a template to load code..."></textarea>
                            <div class="mt-3 d-flex flex-wrap gap-2">
                                <button type="button" id="compileBtn" class="btn btn-primary">Compile Typst</button>
                                <button type="button" id="saveCompileBtn" class="btn btn-outline-secondary">Save
                                    Compile</button>
                                <button type="button" id="savePdfBtn" class="btn btn-outline-secondary">Save
                                    PDF</button>
                                <button type="button" id="downloadPdfBtn" class="btn btn-outline-secondary">PDF
                                    Download</button>
                            </div>
                        </section>
                    </div>
                    <div class="col-12 col-lg-6">
                        <section class="template-panel h-100">
                            <h2 class="mb-3">Compile Result</h2>
                            <div id="latex-preview-render" class="border p-3 bg-white template-preview-render">
                                <p class="text-muted text-center mt-5">Preview will appear here.</p>
                            </div>
                        </section>
                    </div>
                </div>

            </main>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/app_loading.js?v={{ $catalogAssetVersions['js/app_loading.js'] ?? '' }}"></script>
    <script src="assets/js/app_error.js?v={{ $catalogAssetVersions['js/app_error.js'] ?? '' }}"></script>
    <script type="module" src="assets/js/series-typst-templating.js?v={{ $catalogAssetVersions['js/series-typst-templating.js'] ?? '' }}"></script>
</body>

</html>
