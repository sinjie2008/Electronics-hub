<div class="container-fluid px-0 app-shell">
        <div class="row flex-lg-nowrap g-0">

            <div class="col-12 content-column">


                <div id="statusAlert" class="alert-placeholder" aria-live="polite"></div>

                <!-- Template Title & Description -->
                <section class="template-panel mb-4">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label for="templateTitle" class="form-label fw-bold">Template Title</label>
                            <input type="text" id="templateTitle" class="form-control"
                                placeholder="Enter template title">
                        </div>
                        <div class="col-md-6">
                            <label for="templateDescription" class="form-label fw-bold">Description</label>
                            <input type="text" id="templateDescription" class="form-control"
                                placeholder="Enter description">
                        </div>
                    </div>
                </section>

                <!-- Global Variables Section -->
                <section class="template-panel mb-4">
                    <h2 class="h5 mb-3">Global Variables</h2>
                    <div class="row g-4">
                        <div class="col-md-6">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <label class="form-label mb-0">Variables List</label>
                                <input type="text" class="form-control form-control-sm w-50" placeholder="Search variables..."
                                    id="variableSearch">
                            </div>
                            <div class="global-vars-list bg-white" id="globalVarsList">
                                <table id="globalVarsTable" class="table table-striped table-hover align-middle mb-0 w-100">
                                    <thead class="table-light">
                                        <tr>
                                            <th scope="col">Field Key</th>
                                            <th scope="col">Field Type</th>
                                            <th scope="col">Field Data</th>
                                            <th scope="col" class="text-end">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                            <div class="form-text small mt-2">
                                Click the Field Key badge to insert <code>@verbatim{{key}}@endverbatim</code> into the editor, or use Edit to load the variable for changes.
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="variable-setup-panel h-100">
                                <h5 class="h6 mb-3 text-primary">Variable Setup</h5>
                                <form id="variableForm">
                                    <input type="hidden" id="varId">
                                    <div class="mb-2">
                                        <label for="varKey" class="form-label small">Field Key</label>
                                        <input type="text" id="varKey" class="form-control form-control-sm" required>
                                    </div>
                                    <div class="mb-2">
                                        <label for="varType" class="form-label small">Field Type</label>
                                        <select id="varType" class="form-select form-select-sm">
                                            <option value="text">Text</option>
                                            <option value="textarea">Text Area</option>
                                            <option value="file">File (Image)</option>
                                        </select>
                                    </div>
                                    <div class="mb-3">
                                        <label for="varData" class="form-label small">Field Data</label>
                                        <div id="varDataContainer">
                                            <input type="text" id="varData" class="form-control form-control-sm"
                                                placeholder="Value">
                                        </div>
                                        <div class="form-text small text-muted mt-1">
                                            Base on the field Type. If Text it will change only text. Field data change
                                            to textArea. If File it will change to upload file.
                                        </div>
                                    </div>
                                    <div class="d-flex align-items-center justify-content-between gap-2">
                                        <x-filament::button color="gray" outlined :loading-indicator="false" type="button" id="addVarBtn">Add</x-filament::button>
                                        <div class="d-flex gap-2">
                                            <x-filament::button color="primary" :loading-indicator="false" type="submit">Save</x-filament::button>
                                            <x-filament::button color="danger" outlined :loading-indicator="false" type="button"
                                                id="deleteVarBtn">Delete</x-filament::button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Typst Compile Section -->
                <div class="row g-4 mb-4">
                    <div class="col-12 col-lg-6">
                        <section class="template-panel h-100">
                            <h2 class="mb-3">Code Editor</h2>
                            <textarea id="latexSource" class="form-control" rows="20" spellcheck="false"
                                placeholder="#set page(paper: &quot;a4&quot;)\n\n= Hello Typst!\n\nThis is a Typst document."></textarea>
                            <div class="mt-3 d-flex gap-2">
                                <x-filament::button color="primary" :loading-indicator="false" type="button" id="compileBtn">Compile Typst</x-filament::button>
                                <x-filament::button color="gray" outlined :loading-indicator="false" type="button" id="saveTemplateBtn">Save
                                    Template</x-filament::button>
                                <x-filament::button color="gray" outlined :loading-indicator="false" type="button" id="savePdfBtn">Save
                                    PDF</x-filament::button>
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

                <!-- Saved Templates Section -->
                <section class="template-panel">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h2 class="mb-0">Saved Templates</h2>
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control form-control-sm" placeholder="Search templates..."
                                id="templateSearch">
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="savedTemplatesTable" class="table table-striped table-hover align-middle w-100">
                            <thead>
                                <tr>
                                    <th>Title</th>
                                    <th>Description</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </section>

            </div>
        </div>
    </div>
