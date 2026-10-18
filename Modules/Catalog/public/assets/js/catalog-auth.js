(() => {
    'use strict';
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const unsafe = method => !['GET', 'HEAD', 'OPTIONS'].includes(String(method || 'GET').toUpperCase());
    const local = url => new URL(url, document.baseURI).origin === window.location.origin;
    window.catalogFetch = (url, options = {}) => {
        const request = url instanceof Request ? url : null;
        const headers = new Headers(options.headers || request?.headers || {});
        if (token && local(request?.url || url) && unsafe(options.method || request?.method)) headers.set('X-CSRF-TOKEN', token);
        return window.fetch(url, { ...options, headers, credentials: 'same-origin' });
    };
    if (window.jQuery) {
        window.jQuery.ajaxPrefilter((options, original, xhr) => {
            if (token && local(options.url) && unsafe(options.type)) xhr.setRequestHeader('X-CSRF-TOKEN', token);
        });
    }
})();
