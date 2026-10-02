(() => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content');

    if (!csrfToken) {
        return;
    }

    if (window.jQuery?.ajaxPrefilter) {
        window.jQuery.ajaxPrefilter((options, originalOptions, jqXHR) => {
            try {
                const requestUrl = new URL(options.url || window.location.href, window.location.href);

                if (requestUrl.origin === window.location.origin) {
                    jqXHR.setRequestHeader('X-CSRF-TOKEN', csrfToken);
                }
            } catch (error) {
                return;
            }
        });
    }

    if (typeof window.fetch !== 'function') {
        return;
    }

    const originalFetch = window.fetch.bind(window);

    window.fetch = (resource, options = {}) => {
        const resourceUrl = resource instanceof Request ? resource.url : resource;
        let requestUrl;

        try {
            requestUrl = new URL(resourceUrl, window.location.href);
        } catch (error) {
            return originalFetch(resource, options);
        }

        if (requestUrl.origin !== window.location.origin) {
            return originalFetch(resource, options);
        }

        const headers = new Headers(resource instanceof Request ? resource.headers : undefined);
        new Headers(options.headers || {}).forEach((value, name) => {
            headers.set(name, value);
        });
        headers.set('X-CSRF-TOKEN', csrfToken);

        return originalFetch(resource, { ...options, headers });
    };
})();
