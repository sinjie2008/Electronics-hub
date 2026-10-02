<div class="container-fluid px-0 app-shell">
        <div class="row flex-lg-nowrap g-0">

            <div class="col-12 content-column">


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
                            <x-filament::button color="primary" outlined :loading-indicator="false" type="button" id="loadTemplateBtn" class="w-100">Import
                                Template</x-filament::button>
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
                                <x-filament::button color="primary" :loading-indicator="false" type="button" id="compileBtn">Compile Typst</x-filament::button>
                                <x-filament::button color="gray" outlined :loading-indicator="false" type="button" id="saveCompileBtn">Save
                                    Compile</x-filament::button>
                                <x-filament::button color="gray" outlined :loading-indicator="false" type="button" id="savePdfBtn">Save
                                    PDF</x-filament::button>
                                <x-filament::button color="gray" outlined :loading-indicator="false" type="button" id="downloadPdfBtn">PDF
                                    Download</x-filament::button>
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

            </div>
        </div>
    </div>
