(() => {
    'use strict';
    const token = document.querySelector('meta[name="csrf-token"]')?.content;
    const unsafe = method => !['GET', 'HEAD', 'OPTIONS'].includes(String(method || 'GET').toUpperCase());
    const local = url => new URL(url, document.baseURI).origin === window.location.origin;
    window.catalogFetch = (url, options = {}) => {
        const headers = new Headers(options.headers || {});
        if (token && local(url) && unsafe(options.method)) headers.set('X-CSRF-TOKEN', token);
        return window.fetch(url, { ...options, headers, credentials: 'same-origin' });
    };
    if (window.jQuery) {
        window.jQuery.ajaxPrefilter((options, original, xhr) => {
            if (token && local(options.url) && unsafe(options.type)) xhr.setRequestHeader('X-CSRF-TOKEN', token);
        });
    }
})();
