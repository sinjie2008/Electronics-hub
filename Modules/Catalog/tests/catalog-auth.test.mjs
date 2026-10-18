import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import vm from 'node:vm';

const source = readFileSync(new URL('../resources/assets/js/catalog-auth.js', import.meta.url), 'utf8');
const origin = 'https://catalog.example.test';

function browser() {
    const calls = [];
    let prefilter;
    const window = {
        location: { origin },
        fetch(input, options) {
            calls.push({ input, options });
            return Promise.resolve();
        },
        jQuery: { ajaxPrefilter(callback) { prefilter = callback; } },
    };
    const document = {
        baseURI: origin + '/catalog/catalog_ui.html',
        querySelector: () => ({ content: 'session-token' }),
    };
    vm.runInNewContext(source, { window, document, URL, Headers, Request });
    return { window, calls, prefilter };
}

test('same-origin string writes attach CSRF and preserve caller headers', async () => {
    const { window, calls } = browser();
    await window.catalogFetch('/catalog/catalog.php', { method: 'POST', headers: { 'X-Client': 'catalog' } });
    assert.equal(calls[0].options.headers.get('X-CSRF-TOKEN'), 'session-token');
    assert.equal(calls[0].options.headers.get('X-Client'), 'catalog');
    assert.equal(calls[0].options.credentials, 'same-origin');
    await window.catalogFetch('/api/tree');
    assert.equal(calls[1].options.headers.has('X-CSRF-TOKEN'), false);
});

test('same-origin Request writes inherit their method and headers', async () => {
    const { window, calls } = browser();
    const request = new Request(origin + '/catalog/catalog.php', { method: 'POST', headers: { 'X-Client': 'request' } });
    await window.catalogFetch(request);
    assert.equal(calls[0].input, request);
    assert.equal(calls[0].options.headers.get('X-CSRF-TOKEN'), 'session-token');
    assert.equal(calls[0].options.headers.get('X-Client'), 'request');
});

test('cross-origin strings and Request objects never receive the session token', async () => {
    const { window, calls } = browser();
    await window.catalogFetch('https://consumer.example.test/write', { method: 'POST' });
    await window.catalogFetch(new Request('https://consumer.example.test/write', { method: 'POST' }), { method: 'POST' });
    for (const { options } of calls) assert.equal(options.headers.has('X-CSRF-TOKEN'), false);
});

test('jQuery only attaches CSRF to same-origin unsafe requests', () => {
    const { prefilter } = browser();
    const headers = [];
    const xhr = { setRequestHeader: (...header) => headers.push(header) };
    prefilter({ url: '/catalog/catalog.php', type: 'POST' }, {}, xhr);
    prefilter({ url: '/api/tree', type: 'GET' }, {}, xhr);
    prefilter({ url: 'https://consumer.example.test/write', type: 'POST' }, {}, xhr);
    assert.deepEqual(headers, [['X-CSRF-TOKEN', 'session-token']]);
});
