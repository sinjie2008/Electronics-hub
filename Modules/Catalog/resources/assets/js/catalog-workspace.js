import { CatalogUI } from './catalog_ui.js';
import { CatalogCsvPage } from './catalog_csv.js';
import { SpecSearchPage } from './spec_search.js';
import { LatexTemplatePage } from './latex-templating.js';
import { GlobalTypstTemplatePage } from './global-typst-templating.js';
import { SeriesTypstTemplatePage } from './series-typst-templating.js';
import { LoadingOverlayController } from './app_loading.js';
import './app_error.js';
import { clearCatalogContext, setCatalogContext } from './catalog-request.js';
import './mathjax-config.js';

const scripts = new Map();

function loadScript(url) {
    if (!scripts.has(url)) {
        scripts.set(url, new Promise((resolve, reject) => {
            const script = document.createElement('script');
            script.src = url;
            script.onload = resolve;
            script.onerror = () => {
                scripts.delete(url);
                script.remove();
                reject(new Error('Unable to load Catalog controls. Please refresh to retry.'));
            };
            document.head.append(script);
        }));
    }
    return scripts.get(url);
}

async function loadTableControls() {
    if (!window.jQuery) {
        await loadScript('https://code.jquery.com/jquery-3.7.1.min.js');
    }
    if (!window.jQuery.fn.dataTable) {
        await loadScript('https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js');
        await loadScript('https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js');
        await loadScript('https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js');
    }
}

const controllers = {
    'product-catalog': CatalogUI,
    csv: CatalogCsvPage,
    'spec-search': SpecSearchPage,
    'latex-templating': LatexTemplatePage,
    'global-typst-template': GlobalTypstTemplatePage,
    'series-typst-template': SeriesTypstTemplatePage,
};

export default function catalogWorkspace(configuration) {
    let controller;
    let loading;
    let root;
    let disposed = false;
    let observer;
    let pdfPreview;

    return {
        async init() {
            root = this.$el;
            try {
                await loadTableControls();
                if (disposed) {
                    return;
                }
                setCatalogContext(configuration);
                loading = new LoadingOverlayController(window, root);
                window.LoadingOverlay = {
                    start: loading.beginLoading.bind(loading),
                    end: loading.endLoading.bind(loading),
                    wrapPromise: loading.wrapPromise.bind(loading),
                };
                if (configuration.page.includes('templating') || configuration.page.includes('typst-template')) {
                    pdfPreview = await import('./pdf-preview.js');
                    if (disposed) {
                        return;
                    }
                    window.CatalogPdfPreview = pdfPreview;
                }
                controller = new controllers[configuration.page](root);
                observer = new ResizeObserver(() => {
                    if (disposed) {
                        return;
                    }
                    window.jQuery(root).find('table').each((_, table) => {
                        if (window.jQuery.fn.dataTable.isDataTable(table)) {
                            window.jQuery(table).DataTable().columns.adjust();
                        }
                    });
                });
                observer.observe(root);
                await controller.init();
                if (configuration.page === 'latex-templating' && !window.MathJax?.typesetPromise) {
                    await loadScript('https://cdn.jsdelivr.net/npm/mathjax@3/es5/tex-mml-chtml.js');
                    if (!disposed) {
                        controller.renderPreview(root.querySelector('#latexSource')?.value || '');
                    }
                }
            } catch (error) {
                if (!disposed) {
                    const message = root.querySelector('[data-catalog-error]');
                    message.textContent = error.message || 'Unable to initialize Catalog.';
                    message.classList.remove('hidden');
                }
            }
        },

        destroy() {
            disposed = true;
            observer?.disconnect();
            clearCatalogContext();
            if (controller?.handleLayoutChange) {
                window.removeEventListener('resize', controller.handleLayoutChange);
            }
            clearTimeout(controller?.state?.previewTimer);
            root?.querySelectorAll('.catalog-pdf-preview').forEach((element) => pdfPreview?.clearPdfPreview(element));
            if (root && window.jQuery?.fn.dataTable) {
                window.jQuery(root).find('table').each((_, table) => {
                    if (window.jQuery.fn.dataTable.isDataTable(table)) {
                        window.jQuery(table).DataTable().destroy();
                    }
                });
                window.jQuery(root).find('*').addBack().off();
            }
            loading?.destroy();
            if (window.LoadingOverlay) {
                delete window.LoadingOverlay;
            }
        },
    };
}
