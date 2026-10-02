<div class="container-fluid px-0 app-shell">
        <div class="row flex-lg-nowrap g-0">

            <div class="col-12 content-column">


                <div id="status-message" class="small text-muted mb-3" role="status" aria-live="polite"></div>

                <section class="section-block mb-3">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                        <div>
                            <div class="text-uppercase text-muted small mb-1">Root Selection</div>
                            <div class="fw-semibold">Choose starting category</div>
                        </div>
                        <span class="badge bg-primary" id="root-count">0 roots</span>
                    </div>
                    <div id="root-category-options" class="d-flex flex-wrap gap-3 mt-3"></div>
                </section>

                <section class="section-block mb-3">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                        <div>
                            <div class="text-uppercase text-muted small mb-1">Product Categories</div>
                            <div class="fw-semibold">Pick one or more</div>
                        </div>
                        <span class="badge bg-secondary" id="category-count">0 selected</span>
                    </div>
                    <div id="product-categories" class="category-groups mt-3"></div>
                </section>

                <section class="section-block mb-3">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between">
                        <div>
                            <div class="text-uppercase text-muted small mb-1">Filters</div>
                            <div class="fw-semibold">Series & custom fields</div>
                        </div>
                        <x-filament::button color="gray" outlined :loading-indicator="false" id="clear-filters" type="button">Clear</x-filament::button>
                    </div>
                    <div id="selected-filters" class="mb-3 d-flex flex-wrap gap-2 mt-3"></div>
                    <div id="facet-container" class="facet-grid overflow-x-auto"></div>
                </section>

                <section class="section-block">
                    <div class="d-flex flex-column flex-md-row align-items-md-center justify-content-between mb-2">
                        <div>
                            <div class="text-uppercase text-muted small mb-1">Results</div>
                            <div class="fw-semibold">Products</div>
                        </div>
                        <span class="badge bg-info text-dark" id="result-count">0 items</span>
                    </div>
                    <div class="table-responsive">
                        <table id="results-table" class="table table-striped table-hover w-100"></table>
                    </div>
                </section>
            </div>
        </div>
    </div>
