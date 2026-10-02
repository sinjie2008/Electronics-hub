let context;
let abortController;
const pendingAjax = new Set();

export function setCatalogContext(configuration) {
    context = configuration;
    abortController = new AbortController();
}

export function clearCatalogContext() {
    abortController?.abort();
    pendingAjax.forEach((request) => request.abort());
    pendingAjax.clear();
    context = null;
}

export function catalogQuery() {
    return new URLSearchParams(context?.query || {});
}

export function catalogPageUrl(page, query = {}) {
    const url = new URL(page === 'series' ? context.seriesTemplateUrl : context.catalogUrl, window.location.href);
    Object.entries(query).forEach(([key, value]) => url.searchParams.set(key, value));
    return url.href;
}

export function catalogEndpoint(path) {
    const base = new URL(context.apiBaseUrl, window.location.origin);
    const url = new URL(path, base);
    if (url.origin !== base.origin || !url.pathname.startsWith(base.pathname)) {
        throw new Error('Invalid Catalog endpoint.');
    }
    return url.href;
}

export function catalogFetch(path, options = {}) {
    const headers = new Headers(options.headers);
    headers.set('X-CSRF-TOKEN', context.csrfToken);
    headers.set('Accept', 'application/json');
    return window.fetch(catalogEndpoint(path), {
        ...options,
        headers,
        credentials: 'same-origin',
        signal: abortController.signal,
    });
}

export function catalogAjax(options) {
    const request = window.jQuery.ajax({
        ...options,
        url: catalogEndpoint(options.url),
        headers: { ...options.headers, 'X-CSRF-TOKEN': context.csrfToken },
    });
    pendingAjax.add(request);
    request.always(() => pendingAjax.delete(request));
    return request;
}

export function catalogGetJson(url, data) {
    return catalogAjax({ url, data, method: 'GET', dataType: 'json' });
}

export function catalogDownload(path) {
    const url = new URL(path, new URL(context.apiBaseUrl, window.location.origin));
    if (url.origin !== window.location.origin || !['http:', 'https:'].includes(url.protocol) || url.username || url.password) {
        throw new Error('Invalid Catalog download address.');
    }
    const link = document.createElement('a');
    link.href = url.href;
    link.download = '';
    link.click();
}
