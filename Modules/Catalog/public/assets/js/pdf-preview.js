import * as pdfjsLib from 'pdfjs-dist/build/pdf.mjs';
import pdfWorkerUrl from 'pdfjs-dist/build/pdf.worker.mjs?url';
import { catalogEndpoint } from './catalog-request.js';
import '../css/pdf-preview.css';

pdfjsLib.GlobalWorkerOptions.workerSrc = pdfWorkerUrl;

const previewStates = new WeakMap();
const previewRevisions = new WeakMap();
const MIN_SCALE = 0.25;
const MAX_SCALE = 4;
const SCALE_STEP = 0.1;
const MAX_PRINT_CANVAS_PIXELS = 18_000_000;

let nextPrintSessionId = 0;
let activePrintSession = null;

/**
 * Render a same-origin PDF inside a native canvas and selectable text layer.
 *
 * @param {HTMLElement} container
 * @param {string|URL} url
 * @returns {Promise<HTMLElement|null>}
 */
export async function renderPdfPreview(container, url) {
    if (!(container instanceof HTMLElement)) {
        throw new TypeError('A PDF preview container element is required.');
    }

    const revision = nextRevision(container);

    await clearPreviewState(container);

    if (previewRevisions.get(container) !== revision) {
        return null;
    }

    let sourceUrl = null;
    let urlError = null;

    try {
        sourceUrl = normalizePdfUrl(url);
    } catch (error) {
        urlError = error;
    }

    const state = createPreviewState(container, sourceUrl, revision);

    previewStates.set(container, state);
    previewStates.set(state.root, state);
    container.replaceChildren(state.root);
    bindPreviewControls(state);
    observePreviewSize(state);
    updateControls(state);

    if (urlError) {
        showError(state, urlError.message);
        return state.root;
    }

    setStatus(state, 'Loading PDF…', { busy: true });

    try {
        state.loadingTask = pdfjsLib.getDocument(createDocumentOptions(sourceUrl));
        state.loadingTask.onProgress = ({ loaded, total }) => {
            if (!isCurrent(state) || !total) {
                return;
            }

            const percent = Math.min(100, Math.floor((loaded / total) * 100));

            if (percent !== state.lastProgressPercent) {
                state.lastProgressPercent = percent;
                setStatus(state, `Loading PDF… ${percent}%`, { busy: true });
            }
        };

        const pdf = await state.loadingTask.promise;

        if (!isCurrent(state)) {
            return null;
        }

        state.loadingTask.onProgress = null;
        state.pdf = pdf;
        state.pageCount = pdf.numPages;
        state.pageNumber = 1;
        updateControls(state);
        await renderCurrentPage(state);

        return isCurrent(state) ? state.root : null;
    } catch (error) {
        if (isCurrent(state) && !isCancellation(error)) {
            showError(state, 'Unable to load this PDF. Check the file and your access, then try again.');
        }

        return isCurrent(state) ? state.root : null;
    }
}

/**
 * Cancel PDF work, disconnect observers, and remove the preview UI.
 *
 * @param {HTMLElement} container
 * @returns {Promise<void>}
 */
export async function clearPdfPreview(container) {
    if (!(container instanceof HTMLElement)) {
        return;
    }

    nextRevision(container);
    await clearPreviewState(container);
}

function nextRevision(container) {
    const revision = (previewRevisions.get(container) ?? 0) + 1;
    previewRevisions.set(container, revision);

    return revision;
}

function normalizePdfUrl(url) {
    if (typeof url !== 'string' && !(url instanceof URL)) {
        throw new TypeError('The PDF address must be a same-origin URL.');
    }

    const sourceUrl = new URL(String(url), document.baseURI);

    if (
        sourceUrl.origin !== window.location.origin ||
        !['http:', 'https:'].includes(sourceUrl.protocol) ||
        sourceUrl.username ||
        sourceUrl.password
    ) {
        throw new TypeError('Only same-origin PDF addresses are allowed.');
    }

    return sourceUrl;
}

function createDocumentOptions(sourceUrl) {
    const options = {
        url: sourceUrl.href,
        withCredentials: true,
    };

    try {
        options.cMapUrl = catalogEndpoint('assets/pdfjs/cmaps/');
        options.cMapPacked = true;
        options.standardFontDataUrl = catalogEndpoint('assets/pdfjs/standard_fonts/');
        options.wasmUrl = catalogEndpoint('assets/pdfjs/wasm/');
    } catch (error) {
        if (!(error instanceof TypeError)) {
            throw error;
        }
    }

    return options;
}

function createPreviewState(container, sourceUrl, revision) {
    const root = document.createElement('section');
    root.className = 'catalog-pdf-preview';
    root.setAttribute('aria-label', 'PDF preview');

    const toolbar = document.createElement('div');
    toolbar.className = 'catalog-pdf-preview__toolbar';
    toolbar.setAttribute('role', 'toolbar');
    toolbar.setAttribute('aria-label', 'PDF tools');

    const pageTools = document.createElement('div');
    pageTools.className = 'catalog-pdf-preview__tool-group';

    const previousPageButton = createButton('Previous page', '‹', 'Previous page');
    const pageInput = document.createElement('input');
    pageInput.className = 'catalog-pdf-preview__page-input';
    pageInput.type = 'number';
    pageInput.min = '1';
    pageInput.step = '1';
    pageInput.inputMode = 'numeric';
    pageInput.setAttribute('aria-label', 'Current page');

    const pageCountLabel = document.createElement('span');
    pageCountLabel.className = 'catalog-pdf-preview__page-count';
    pageCountLabel.textContent = 'of 0';

    const nextPageButton = createButton('Next page', '›', 'Next page');
    pageTools.append(previousPageButton, pageInput, pageCountLabel, nextPageButton);

    const zoomTools = document.createElement('div');
    zoomTools.className = 'catalog-pdf-preview__tool-group';
    const zoomOutButton = createButton('Zoom out', '−', 'Zoom out');
    const zoomLabel = document.createElement('span');
    zoomLabel.className = 'catalog-pdf-preview__zoom-label';
    zoomLabel.textContent = '100%';
    zoomLabel.setAttribute('aria-live', 'polite');
    const zoomInButton = createButton('Zoom in', '+', 'Zoom in');
    const fitButton = createButton('Fit to width', 'Fit', 'Fit page to width');
    fitButton.classList.add('catalog-pdf-preview__button--text');
    fitButton.setAttribute('aria-pressed', 'true');
    const rotateButton = createButton('Rotate page clockwise', '↻', 'Rotate page clockwise');
    zoomTools.append(zoomOutButton, zoomLabel, zoomInButton, fitButton, rotateButton);

    const fileTools = document.createElement('div');
    fileTools.className = 'catalog-pdf-preview__tool-group catalog-pdf-preview__tool-group--file';
    const downloadLink = document.createElement('a');
    downloadLink.className = 'catalog-pdf-preview__button catalog-pdf-preview__download';
    downloadLink.textContent = 'Download';
    downloadLink.setAttribute('aria-label', 'Download PDF');
    downloadLink.rel = 'noopener noreferrer';
    downloadLink.hidden = true;

    const printButton = createButton('Print PDF', 'Print', 'Print PDF');
    printButton.classList.add('catalog-pdf-preview__button--text');
    fileTools.append(downloadLink, printButton);

    toolbar.append(pageTools, zoomTools, fileTools);

    const searchForm = document.createElement('form');
    searchForm.className = 'catalog-pdf-preview__search';
    searchForm.setAttribute('role', 'search');
    const searchInput = document.createElement('input');
    searchInput.className = 'catalog-pdf-preview__search-input';
    searchInput.type = 'search';
    searchInput.placeholder = 'Search in PDF';
    searchInput.setAttribute('aria-label', 'Search text in PDF');

    const searchButton = createButton('Find text', 'Find', 'Find text in PDF');
    searchButton.type = 'submit';
    searchButton.classList.add('catalog-pdf-preview__button--text');
    const previousMatchButton = createButton('Previous match', '↑', 'Previous match');
    const nextMatchButton = createButton('Next match', '↓', 'Next match');
    const searchStatus = document.createElement('span');
    searchStatus.className = 'catalog-pdf-preview__search-status';
    searchStatus.setAttribute('aria-live', 'polite');
    searchForm.append(searchInput, searchButton, previousMatchButton, nextMatchButton, searchStatus);

    const status = document.createElement('div');
    status.className = 'catalog-pdf-preview__status';
    status.setAttribute('role', 'status');
    status.setAttribute('aria-live', 'polite');
    status.setAttribute('aria-busy', 'false');
    status.hidden = true;

    const viewer = document.createElement('div');
    viewer.className = 'catalog-pdf-preview__viewer';
    viewer.setAttribute('role', 'region');
    viewer.setAttribute('aria-label', 'PDF document page');

    const pageStage = document.createElement('div');
    pageStage.className = 'catalog-pdf-preview__page-stage';
    viewer.append(pageStage);

    root.append(toolbar, searchForm, status, viewer);

    const state = {
        container,
        root,
        revision,
        sourceUrl,
        pageStage,
        viewer,
        status,
        searchStatus,
        controls: {
            previousPageButton,
            pageInput,
            pageCountLabel,
            nextPageButton,
            zoomOutButton,
            zoomLabel,
            zoomInButton,
            fitButton,
            rotateButton,
            downloadLink,
            printButton,
            searchForm,
            searchInput,
            searchButton,
            previousMatchButton,
            nextMatchButton,
        },
        loadingTask: null,
        pdf: null,
        pageCount: 0,
        pageNumber: 1,
        zoom: 1,
        fitWidth: true,
        rotation: 0,
        resizeObserver: null,
        resizeFrame: 0,
        lastObservedWidth: 0,
        lastProgressPercent: -1,
        renderRequestId: 0,
        renderTask: null,
        textLayer: null,
        pageCanvas: null,
        disposed: false,
        searchSequence: 0,
        searchIndex: new Map(),
        searchResults: [],
        searchResultIndex: -1,
        searchTerm: '',
        isSearching: false,
        printSession: null,
    };

    if (sourceUrl) {
        downloadLink.href = sourceUrl.href;
        downloadLink.download = getDownloadName(sourceUrl);
        downloadLink.hidden = false;
    }

    return state;
}

function createButton(ariaLabel, text, title) {
    const button = document.createElement('button');
    button.className = 'catalog-pdf-preview__button';
    button.type = 'button';
    button.setAttribute('aria-label', ariaLabel);
    button.title = title;
    button.textContent = text;

    return button;
}

function bindPreviewControls(state) {
    const controls = state.controls;

    controls.previousPageButton.addEventListener('click', () => {
        goToPage(state, state.pageNumber - 1);
    });
    controls.nextPageButton.addEventListener('click', () => {
        goToPage(state, state.pageNumber + 1);
    });
    controls.pageInput.addEventListener('change', () => {
        goToPage(state, Number.parseInt(controls.pageInput.value, 10));
    });
    controls.pageInput.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
            event.preventDefault();
            goToPage(state, Number.parseInt(controls.pageInput.value, 10));
        }
    });

    controls.zoomOutButton.addEventListener('click', () => {
        setZoom(state, state.zoom - SCALE_STEP);
    });
    controls.zoomInButton.addEventListener('click', () => {
        setZoom(state, state.zoom + SCALE_STEP);
    });
    controls.fitButton.addEventListener('click', () => {
        state.fitWidth = true;
        updateControls(state);
        void renderCurrentPage(state);
    });
    controls.rotateButton.addEventListener('click', () => {
        state.rotation = (state.rotation + 90) % 360;
        void renderCurrentPage(state);
    });
    controls.printButton.addEventListener('click', () => {
        void printPdf(state);
    });

    controls.searchForm.addEventListener('submit', (event) => {
        event.preventDefault();
        void searchPdf(state, controls.searchInput.value);
    });
    controls.previousMatchButton.addEventListener('click', () => {
        void moveToSearchMatch(state, -1);
    });
    controls.nextMatchButton.addEventListener('click', () => {
        void moveToSearchMatch(state, 1);
    });
}

function observePreviewSize(state) {
    if (typeof ResizeObserver === 'undefined') {
        return;
    }

    state.resizeObserver = new ResizeObserver((entries) => {
        const width = Math.round(entries[0]?.contentRect.width ?? 0);

        if (!width || Math.abs(width - state.lastObservedWidth) < 2) {
            return;
        }

        state.lastObservedWidth = width;

        if (!state.pdf || !state.fitWidth || !isCurrent(state)) {
            return;
        }

        if (state.resizeFrame) {
            cancelAnimationFrame(state.resizeFrame);
        }

        state.resizeFrame = requestAnimationFrame(() => {
            state.resizeFrame = 0;
            void renderCurrentPage(state);
        });
    });

    state.resizeObserver.observe(state.viewer);
}

function updateControls(state) {
    const controls = state.controls;
    const hasDocument = Boolean(state.pdf);

    controls.pageInput.value = String(state.pageNumber);
    controls.pageInput.max = String(Math.max(state.pageCount, 1));
    controls.pageCountLabel.textContent = `of ${state.pageCount}`;
    controls.previousPageButton.disabled = !hasDocument || state.pageNumber <= 1;
    controls.nextPageButton.disabled = !hasDocument || state.pageNumber >= state.pageCount;
    controls.zoomOutButton.disabled = !hasDocument || state.zoom <= MIN_SCALE;
    controls.zoomInButton.disabled = !hasDocument || state.zoom >= MAX_SCALE;
    controls.fitButton.disabled = !hasDocument;
    controls.fitButton.setAttribute('aria-pressed', state.fitWidth ? 'true' : 'false');
    controls.rotateButton.disabled = !hasDocument;
    controls.printButton.disabled = !hasDocument || Boolean(state.printSession);
    controls.zoomLabel.textContent = `${Math.round(state.zoom * 100)}%`;
    controls.searchInput.disabled = !hasDocument;
    controls.searchButton.disabled = !hasDocument || state.isSearching;
    controls.previousMatchButton.disabled = !state.searchResults.length || state.isSearching;
    controls.nextMatchButton.disabled = !state.searchResults.length || state.isSearching;
}

function setStatus(state, message, { busy = false, error = false } = {}) {
    state.status.textContent = message;
    state.status.hidden = message.length === 0;
    state.status.setAttribute('aria-busy', busy ? 'true' : 'false');
    state.status.classList.toggle('catalog-pdf-preview__status--error', error);
}

function showError(state, message) {
    setStatus(state, message, { error: true });
}

function goToPage(state, pageNumber) {
    if (!state.pdf || !Number.isFinite(pageNumber)) {
        updateControls(state);
        return;
    }

    state.pageNumber = clamp(Math.trunc(pageNumber), 1, state.pageCount);
    updateControls(state);
    void renderCurrentPage(state);
}

function setZoom(state, zoom) {
    if (!state.pdf) {
        return;
    }

    state.fitWidth = false;
    state.zoom = clamp(Math.round(zoom * 100) / 100, MIN_SCALE, MAX_SCALE);
    updateControls(state);
    void renderCurrentPage(state);
}

async function renderCurrentPage(state) {
    if (!isCurrent(state) || !state.pdf) {
        return false;
    }

    const requestId = ++state.renderRequestId;
    state.renderTask?.cancel();
    state.textLayer?.cancel();
    state.renderTask = null;
    state.textLayer = null;

    setStatus(state, `Rendering page ${state.pageNumber}…`, { busy: true });

    try {
        const page = await state.pdf.getPage(state.pageNumber);

        if (!isCurrentRender(state, requestId)) {
            return false;
        }

        const rotation = (page.rotate + state.rotation) % 360;
        const scale = state.fitWidth ? calculateFitScale(state, page, rotation) : state.zoom;
        const viewport = page.getViewport({ scale, rotation });
        const pageElement = document.createElement('div');
        pageElement.className = 'catalog-pdf-preview__page';
        pageElement.setAttribute('role', 'group');
        pageElement.setAttribute('aria-label', `Page ${state.pageNumber} of ${state.pageCount}`);
        pageElement.style.width = `${viewport.width}px`;
        pageElement.style.height = `${viewport.height}px`;
        pageElement.style.setProperty('--total-scale-factor', String(viewport.scale));

        const canvas = document.createElement('canvas');
        canvas.className = 'catalog-pdf-preview__canvas';
        canvas.setAttribute('aria-hidden', 'true');
        const outputScale = Math.min(window.devicePixelRatio || 1, 2);
        canvas.width = Math.max(1, Math.floor(viewport.width * outputScale));
        canvas.height = Math.max(1, Math.floor(viewport.height * outputScale));
        canvas.style.width = `${viewport.width}px`;
        canvas.style.height = `${viewport.height}px`;

        const textLayerContainer = document.createElement('div');
        textLayerContainer.className = 'textLayer';
        textLayerContainer.setAttribute('aria-label', `Selectable text on page ${state.pageNumber}`);

        pageElement.append(canvas, textLayerContainer);
        state.pageStage.replaceChildren(pageElement);
        state.pageCanvas = canvas;
        state.zoom = scale;
        state.lastObservedWidth = Math.round(state.viewer.clientWidth);
        updateControls(state);

        state.renderTask = page.render({
            canvas,
            viewport,
            transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0],
        });
        state.textLayer = new pdfjsLib.TextLayer({
            textContentSource: page.streamTextContent(),
            container: textLayerContainer,
            viewport,
        });

        await Promise.all([state.renderTask.promise, state.textLayer.render()]);

        if (!isCurrentRender(state, requestId)) {
            return false;
        }

        applySearchHighlights(state);
        setStatus(state, '');

        return true;
    } catch (error) {
        if (isCurrentRender(state, requestId) && !isCancellation(error)) {
            showError(state, 'Unable to render this PDF page.');
        }

        return false;
    }
}

function calculateFitScale(state, page, rotation) {
    const unscaledViewport = page.getViewport({ scale: 1, rotation });
    const availableWidth = Math.max(180, state.viewer.clientWidth - 32);

    return clamp(availableWidth / unscaledViewport.width, MIN_SCALE, MAX_SCALE);
}

async function searchPdf(state, query) {
    const term = query.trim();
    const sequence = ++state.searchSequence;

    state.searchTerm = term;
    state.searchResults = [];
    state.searchResultIndex = -1;
    state.isSearching = false;
    updateControls(state);

    if (!term || !state.pdf) {
        state.searchStatus.textContent = '';
        applySearchHighlights(state);
        return;
    }

    state.isSearching = true;
    state.searchStatus.textContent = 'Searching…';
    updateControls(state);

    const foldedTerm = term.toLocaleLowerCase();
    const results = [];

    try {
        for (let pageNumber = 1; pageNumber <= state.pageCount; pageNumber += 1) {
            if (!isCurrent(state) || sequence !== state.searchSequence) {
                return;
            }

            let pageIndex = state.searchIndex.get(pageNumber);

            if (!pageIndex) {
                const page = await state.pdf.getPage(pageNumber);
                const textContent = await page.getTextContent();

                if (!isCurrent(state) || sequence !== state.searchSequence) {
                    return;
                }

                pageIndex = createTextIndex(textContent.items);
                state.searchIndex.set(pageNumber, pageIndex);
            }

            let matchPosition = 0;
            while ((matchPosition = pageIndex.text.indexOf(foldedTerm, matchPosition)) !== -1) {
                const itemIndexes = findOverlappingTextItems(
                    pageIndex.itemRanges,
                    matchPosition,
                    matchPosition + foldedTerm.length,
                );

                results.push({ pageNumber, itemIndexes });
                matchPosition += Math.max(1, foldedTerm.length);
            }

            state.searchStatus.textContent = `Searching ${pageNumber} of ${state.pageCount}…`;
        }

        if (!isCurrent(state) || sequence !== state.searchSequence) {
            return;
        }

        state.searchResults = results;

        if (!results.length) {
            state.searchStatus.textContent = 'No matches';
            applySearchHighlights(state);
            return;
        }

        state.searchResultIndex = 0;
        state.searchStatus.textContent = `1 of ${results.length}`;
        await displaySearchResult(state, sequence);
    } catch (error) {
        if (isCurrent(state) && sequence === state.searchSequence) {
            state.searchStatus.textContent = 'Search failed';
        }
    } finally {
        if (isCurrent(state) && sequence === state.searchSequence) {
            state.isSearching = false;
            updateControls(state);
        }
    }
}

function createTextIndex(items) {
    const itemRanges = [];
    let text = '';

    for (const item of items) {
        if (typeof item.str !== 'string') {
            continue;
        }

        if (itemRanges.length > 0) {
            text += ' ';
        }

        const start = text.length;
        text += item.str.toLocaleLowerCase();
        itemRanges.push({ start, end: text.length });
    }

    return { text, itemRanges };
}

function findOverlappingTextItems(itemRanges, start, end) {
    let lowerBound = 0;
    let upperBound = itemRanges.length;

    while (lowerBound < upperBound) {
        const middle = Math.floor((lowerBound + upperBound) / 2);

        if (itemRanges[middle].end <= start) {
            lowerBound = middle + 1;
        } else {
            upperBound = middle;
        }
    }

    const indexes = [];

    for (let index = lowerBound; index < itemRanges.length && itemRanges[index].start < end; index += 1) {
        if (itemRanges[index].end > start) {
            indexes.push(index);
        }
    }

    return indexes;
}

async function moveToSearchMatch(state, direction) {
    if (!state.searchResults.length || !isCurrent(state)) {
        return;
    }

    state.searchResultIndex = (
        state.searchResultIndex + direction + state.searchResults.length
    ) % state.searchResults.length;
    await displaySearchResult(state, state.searchSequence);
}

async function displaySearchResult(state, sequence) {
    const result = state.searchResults[state.searchResultIndex];

    if (!result || !isCurrent(state)) {
        return;
    }

    state.pageNumber = result.pageNumber;
    state.searchStatus.textContent = `${state.searchResultIndex + 1} of ${state.searchResults.length}`;
    updateControls(state);
    await renderCurrentPage(state);

    if (isCurrent(state) && sequence === state.searchSequence) {
        state.searchStatus.textContent = `${state.searchResultIndex + 1} of ${state.searchResults.length}`;
    }
}

function applySearchHighlights(state) {
    const currentResult = state.searchResults[state.searchResultIndex];
    const textDivs = state.textLayer?.textDivs ?? [];
    const currentPageResults = state.searchResults.filter(
        (result) => result.pageNumber === state.pageNumber,
    );
    const matchingItems = new Set();
    const activeItems = new Set(currentResult?.pageNumber === state.pageNumber ? currentResult.itemIndexes : []);

    for (const result of currentPageResults) {
        for (const itemIndex of result.itemIndexes) {
            matchingItems.add(itemIndex);
        }
    }

    textDivs.forEach((textDiv, index) => {
        textDiv.classList.toggle('catalog-pdf-preview__search-hit', matchingItems.has(index));
        textDiv.classList.toggle('catalog-pdf-preview__search-hit--active', activeItems.has(index));
    });
}

async function printPdf(state) {
    if (!state.pdf || !isCurrent(state)) {
        return;
    }

    if (activePrintSession) {
        setStatus(state, 'Another PDF is already being prepared for printing.');
        return;
    }

    const session = createPrintSession(state);
    state.printSession = session;
    activePrintSession = session;
    document.body.append(session.root);
    updateControls(state);
    setStatus(state, 'Preparing PDF for printing…', { busy: true });
    let printFailed = false;

    try {
        for (let pageNumber = 1; pageNumber <= state.pageCount; pageNumber += 1) {
            if (session.cancelled || !isCurrent(state)) {
                return;
            }

            setStatus(state, `Preparing page ${pageNumber} of ${state.pageCount} for printing…`, { busy: true });
            await renderPrintPage(session, pageNumber);
        }

        if (session.cancelled || !isCurrent(state)) {
            return;
        }

        await new Promise((resolve) => requestAnimationFrame(resolve));

        if (session.cancelled || !isCurrent(state)) {
            return;
        }

        document.documentElement.setAttribute('data-catalog-pdf-printing', 'true');
        window.print();
    } catch (error) {
        if (isCurrent(state) && !session.cancelled && !isCancellation(error)) {
            printFailed = true;
            showError(state, 'Unable to prepare this PDF for printing.');
        }
    } finally {
        session.cleanup();

        if (isCurrent(state)) {
            if (!printFailed) {
                setStatus(state, '');
            }
            updateControls(state);
        }
    }
}

function createPrintSession(state) {
    const id = ++nextPrintSessionId;
    const root = document.createElement('div');
    root.className = 'catalog-pdf-preview__print-document';
    root.setAttribute('aria-hidden', 'true');

    const pages = document.createElement('div');
    pages.className = 'catalog-pdf-preview__print-pages';
    root.append(pages);

    const pageStyle = document.createElement('style');
    pageStyle.dataset.catalogPdfPrintSession = String(id);
    document.head.append(pageStyle);

    const session = {
        id,
        state,
        root,
        pages,
        pageStyle,
        canvases: [],
        renderTasks: new Set(),
        cancelled: false,
        cleaned: false,
        originalPrintAttribute: document.documentElement.getAttribute('data-catalog-pdf-printing'),
        cleanup: null,
    };

    session.cleanup = () => {
        if (session.cleaned) {
            return;
        }

        session.cleaned = true;

        for (const renderTask of session.renderTasks) {
            try {
                renderTask.cancel();
            } catch (error) {
                // A completed render task no longer needs cancellation.
            }
        }

        session.renderTasks.clear();
        session.canvases.forEach((canvas) => {
            canvas.width = 0;
            canvas.height = 0;
        });
        session.canvases.length = 0;
        session.root.remove();
        session.pageStyle.remove();

        if (session.originalPrintAttribute === null) {
            document.documentElement.removeAttribute('data-catalog-pdf-printing');
        } else {
            document.documentElement.setAttribute('data-catalog-pdf-printing', session.originalPrintAttribute);
        }

        if (session.state.printSession === session) {
            session.state.printSession = null;
        }

        if (activePrintSession === session) {
            activePrintSession = null;
        }
    };

    return session;
}

async function renderPrintPage(session, pageNumber) {
    const { state } = session;
    const page = await state.pdf.getPage(pageNumber);

    if (session.cancelled || !isCurrent(state)) {
        return;
    }

    const rotation = (page.rotate + state.rotation) % 360;
    const viewport = page.getViewport({ scale: 96 / 72, rotation });
    const pageSize = page.getViewport({ scale: 1, rotation });
    const pageName = `catalog-pdf-print-${session.id}-${pageNumber}`;
    const pageElement = document.createElement('section');
    pageElement.className = 'catalog-pdf-preview__print-page';
    pageElement.style.width = `${viewport.width}px`;
    pageElement.style.height = `${viewport.height}px`;
    pageElement.style.setProperty('page', pageName);

    const canvas = document.createElement('canvas');
    canvas.className = 'catalog-pdf-preview__print-canvas';
    const outputScale = Math.min(
        2,
        Math.sqrt(MAX_PRINT_CANVAS_PIXELS / (viewport.width * viewport.height)),
    );
    canvas.width = Math.max(1, Math.floor(viewport.width * outputScale));
    canvas.height = Math.max(1, Math.floor(viewport.height * outputScale));
    canvas.style.width = `${viewport.width}px`;
    canvas.style.height = `${viewport.height}px`;
    session.canvases.push(canvas);
    pageElement.append(canvas);
    session.pages.append(pageElement);
    session.pageStyle.textContent += (
        `@page ${pageName} { size: ${pageSize.width}pt ${pageSize.height}pt; margin: 0; }\n`
    );

    const renderTask = page.render({
        canvas,
        viewport,
        transform: outputScale === 1 ? null : [outputScale, 0, 0, outputScale, 0, 0],
    });
    session.renderTasks.add(renderTask);

    try {
        await renderTask.promise;
    } finally {
        session.renderTasks.delete(renderTask);
    }
}

function cancelPrintSession(state) {
    const session = state.printSession;

    if (!session) {
        return;
    }

    session.cancelled = true;

    for (const renderTask of session.renderTasks) {
        try {
            renderTask.cancel();
        } catch (error) {
            // A completed render task no longer needs cancellation.
        }
    }

    session.cleanup();
}

async function clearPreviewState(container) {
    const state = previewStates.get(container);

    if (!state) {
        container.replaceChildren();
        return;
    }

    state.disposed = true;
    cancelPrintSession(state);
    state.resizeObserver?.disconnect();

    if (state.resizeFrame) {
        cancelAnimationFrame(state.resizeFrame);
    }

    state.renderTask?.cancel();
    state.textLayer?.cancel();

    if (state.pageCanvas) {
        state.pageCanvas.width = 0;
        state.pageCanvas.height = 0;
    }

    state.searchSequence += 1;
    state.pdf = null;
    state.pageStage.replaceChildren();
    previewStates.delete(state.container);
    previewStates.delete(state.root);

    if (container === state.root) {
        state.root.remove();
        state.root.replaceChildren();
    } else {
        state.container.replaceChildren();
    }

    if (state.loadingTask) {
        try {
            await state.loadingTask.destroy();
        } catch (error) {
            // The task may already have been cancelled by PDF.js.
        }
    }
}

function isCurrent(state) {
    return (
        !state.disposed &&
        previewStates.get(state.container) === state &&
        previewRevisions.get(state.container) === state.revision
    );
}

function isCurrentRender(state, requestId) {
    return isCurrent(state) && state.renderRequestId === requestId;
}

function isCancellation(error) {
    return ['AbortException', 'RenderingCancelledException', 'TaskCancelledException'].includes(error?.name);
}

function getDownloadName(sourceUrl) {
    const lastPathSegment = sourceUrl.pathname.split('/').filter(Boolean).at(-1) ?? 'document.pdf';
    let fileName;

    try {
        fileName = decodeURIComponent(lastPathSegment);
    } catch (error) {
        fileName = lastPathSegment;
    }

    fileName = fileName.replace(/[\\/:*?"<>|\u0000-\u001f]/g, '_');

    return /\.pdf$/i.test(fileName) ? fileName : 'document.pdf';
}

function clamp(value, minimum, maximum) {
    return Math.min(maximum, Math.max(minimum, value));
}
