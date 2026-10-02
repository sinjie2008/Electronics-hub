<div class="container-fluid catalog-shell px-0 app-shell">
        <div class="row flex-lg-nowrap g-0">

            <div class="col-12 content-column">


                <div id="status-message-catalog" class="status-message is-empty" role="status" aria-live="polite"></div>
                <div class="section section-inline row g-0 no-bootstrap-gap">
                    <div class="panel panel-hierarchy">
                        <h2>Hierarchy</h2>
                        <div class="mb-2">
                            <input type="text" id="hierarchy-search" class="form-control"
                                placeholder="Search categories, series, products...">
                        </div>
                        <div id="hierarchy-container" class="hierarchy-tree">Loading...</div>
                    </div>
                    <div class="panel panel-add-node">
                        <h2>Add Node</h2>
                        <form id="node-create-form">
                            <div>Parent ID (leave empty for root): <input type="text" id="create-parent-id"
                                    name="parentId">
                            </div>
                            <div>Name: <input type="text" id="create-node-name" name="name" required></div>
                            <div>Type:
                                <select id="create-node-type" name="type">
                                    <option value="category">category</option>
                                    <option value="series">series</option>
                                </select>
                            </div>
                            <div>Display Order: <input type="number" id="create-display-order" name="displayOrder"
                                    value="0">
                            </div>
                            <div><x-filament::button color="primary" :loading-indicator="false" type="submit">Add Node</x-filament::button></div>
                        </form>
                    </div>
                    <div class="panel panel-update-node">
                        <h2>Update Selected Node</h2>
                        <form id="node-update-form">
                            <input type="hidden" id="update-node-id">
                            <input type="hidden" id="update-node-type-value">
                            <div>Node ID: <span id="update-node-id-text">None</span></div>
                            <div>Parent ID: <span id="update-node-parent-id">N/A</span></div>
                            <div>Type: <span id="update-node-type-text">N/A</span></div>
                            <div>Name: <input type="text" id="update-node-name" required></div>
                            <div>Display Order: <input type="number" id="update-node-display-order" value="0"></div>
                            <div class="inline-actions">
                                <x-filament::button color="primary" :loading-indicator="false" type="submit" id="node-update-submit">Save Node</x-filament::button>
                                <x-filament::button color="danger" :loading-indicator="false" type="button" id="node-delete-button">Delete Selected Node</x-filament::button>
                            </div>
                        </form>
                    </div>
                    <div class="panel panel-selected-node">
                        <h2>Selected Node</h2>
                        <div id="selected-node-details">Select a category or series to view details.</div>

                        <div id="typst-templating-control" class="mt-3 d-none">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" role="switch"
                                    id="enableTypstTemplating">
                                <label class="form-check-label" for="enableTypstTemplating">Enable Typst
                                    Templating</label>
                            </div>
                            <div id="typst-templating-link-container" class="mt-2 d-none">
                                <a href="#" id="typst-templating-link">Open Series Typst Template</a>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="series-management" hidden>
                    <div class="section section-inline row g-0 no-bootstrap-gap">
                        <div class="panel panel-series-fields">
                            <h3>Series Custom Fields</h3>
                            <div id="status-message-series-fields" class="status-message is-empty" role="status"
                                aria-live="polite"></div>
                            <table id="series-fields-table"
                                class="table table-striped table-hover align-middle datatable w-100">
                                <thead></thead>
                                <tbody>
                                    <tr>
                                        <td>Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                            <h4>Product Attribute Field Editor</h4>
                            <form id="series-field-form" class="stacked-form">
                                <input type="hidden" id="series-field-id">
                                <label>Field Key:<input type="text" id="series-field-key" required></label>
                                <label>Label:<input type="text" id="series-field-label" required></label>
                                <label>Field Type:
                                    <select id="series-field-type">
                                        <option value="text">Text</option>
                                        <option value="number">Number</option>
                                        <option value="file">File (image/PDF/GLB)</option>
                                    </select>
                                </label>
                                <label>Sort Order:<input type="number" id="series-field-sort-order" value="0"></label>
                                <label class="inline-checkbox"><input type="checkbox" id="series-field-public-hidden">
                                    Public Portal Hidden</label>
                                <label class="inline-checkbox"><input type="checkbox" id="series-field-backend-hidden">
                                    Backend Portal Hidden</label>
                                <label class="inline-checkbox"><input type="checkbox" id="series-field-required">
                                    Required</label>
                                <div class="inline-actions">
                                    <x-filament::button color="primary" :loading-indicator="false" type="submit" id="series-field-submit">Save Field</x-filament::button>
                                    <x-filament::button color="gray" :loading-indicator="false" type="button" id="series-field-clear-button">Clear</x-filament::button>
                                </div>
                            </form>
                        </div>
                        <div class="panel panel-series-metadata">
                            <h3>Series Metadata</h3>
                            <div id="status-message-series-metadata" class="status-message is-empty" role="status"
                                aria-live="polite"></div>
                            <table id="series-metadata-fields-table"
                                class="table table-striped table-hover align-middle datatable w-100">
                                <thead></thead>
                                <tbody>
                                    <tr>
                                        <td>Loading...</td>
                                    </tr>
                                </tbody>
                            </table>
                            <h4>Series Metadata Field Editor</h4>
                            <form id="series-metadata-field-form" class="stacked-form">
                                <input type="hidden" id="series-metadata-field-id">
                                <label>Field Key:<input type="text" id="series-metadata-field-key" required></label>
                                <label>Label:<input type="text" id="series-metadata-field-label" required></label>
                                <label>Field Type:
                                    <select id="series-metadata-field-type">
                                        <option value="text">Text</option>
                                        <option value="number">Number</option>
                                        <option value="file">File (image/PDF/GLB)</option>
                                    </select>
                                </label>
                                <label>Sort Order:<input type="number" id="series-metadata-field-sort-order"
                                        value="0"></label>
                                <label class="inline-checkbox"><input type="checkbox"
                                        id="series-metadata-field-public-hidden">
                                    Public Portal Hidden</label>
                                <label class="inline-checkbox"><input type="checkbox"
                                        id="series-metadata-field-backend-hidden">
                                    Backend Portal Hidden</label>
                                <label class="inline-checkbox"><input type="checkbox"
                                        id="series-metadata-field-required">
                                    Required</label>
                                <div class="inline-actions">
                                    <x-filament::button color="primary" :loading-indicator="false" type="submit" id="series-metadata-field-submit">Save Metadata Field</x-filament::button>
                                    <x-filament::button color="gray" :loading-indicator="false" type="button" id="series-metadata-field-clear-button">Clear</x-filament::button>
                                </div>
                            </form>
                            <h4>Series Metadata Values</h4>
                            <form id="series-metadata-form" class="stacked-form">
                                <div id="series-metadata-values"></div>
                                <div class="inline-actions">
                                    <x-filament::button color="primary" :loading-indicator="false" type="submit" id="series-metadata-save-button">Save Metadata</x-filament::button>
                                    <x-filament::button color="gray" :loading-indicator="false" type="button" id="series-metadata-reset-button">Reset</x-filament::button>
                                </div>
                            </form>
                        </div>
                    </div>
                    <div class="section section-inline row g-0 no-bootstrap-gap">
                        <div class="panel panel-products">
                            <h3>Products</h3>
                            <div id="status-message-products" class="status-message is-empty" role="status"
                                aria-live="polite"></div>
                            <div class="products-list-block">
                                <div class="product-table-scroll">
                                    <table id="product-list-table"
                                        class="table table-striped table-hover align-middle datatable w-100">
                                        <thead></thead>
                                        <tbody>
                                            <tr>
                                                <td>Loading...</td>
                                            </tr>
                                        </tbody>
                                    </table>
                                </div>
                                <x-filament::button color="danger" :loading-indicator="false" type="button" id="product-delete-button">Delete Selected Product</x-filament::button>
                            </div>
                            <form id="product-form" class="stacked-form products-form">
                                <input type="hidden" id="product-id">
                                <label>SKU:<input type="text" id="product-sku" required></label>
                                <label>Name:<input type="text" id="product-name" required></label>
                                <label>Description:<textarea id="product-description" rows="4"></textarea></label>
                                <div id="product-custom-fields"></div>
                                <div class="inline-actions">
                                    <x-filament::button color="primary" :loading-indicator="false" type="submit" id="product-submit">Save Product</x-filament::button>
                                    <x-filament::button color="gray" :loading-indicator="false" type="button" id="product-clear-button">Clear</x-filament::button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>

                <div id="category-fields-section" class="section" hidden>
                    <div class="panel panel-category-fields">
                        <h3>Category Fields Set</h3>
                        <div id="status-message-category-fields" class="status-message is-empty" role="status"
                            aria-live="polite"></div>
                        <div class="row g-3">
                            <div class="col-lg-7">
                                <div class="d-flex align-items-center mb-2">
                                    <span class="fw-semibold">Category Fields List</span>
                                </div>
                                <table id="category-fields-table"
                                    class="table table-striped table-hover align-middle datatable w-100">
                                    <thead></thead>
                                    <tbody>
                                        <tr>
                                            <td>Loading...</td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                            <div class="col-lg-5">
                                <h4 class="h6">Category Fields Editor</h4>
                                <form id="category-field-form" class="stacked-form">
                                    <input type="hidden" id="category-field-id">
                                    <label>Field Key:<input type="text" id="category-field-key" required></label>
                                    <label>Field Type:
                                        <select id="category-field-type">
                                            <option value="text">Text</option>
                                            <option value="textarea">Text Area</option>
                                            <option value="file">File (Image)</option>
                                        </select>
                                    </label>
                                    <div id="category-field-data-container"></div>
                                    <div class="inline-actions category-field-actions">
                                        <x-filament::button color="primary" :loading-indicator="false" type="button" id="category-field-add-button">Add</x-filament::button>
                                        <div class="inline-actions category-field-actions__group">
                                            <x-filament::button color="primary" :loading-indicator="false" type="submit" id="category-field-submit">Save</x-filament::button>
                                            <x-filament::button color="danger" :loading-indicator="false" type="button" id="category-field-delete-button">Delete</x-filament::button>
                                        </div>
                                    </div>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
