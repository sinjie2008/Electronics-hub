import assert from 'node:assert/strict';
import { test } from 'node:test';

globalThis.window = { location: { origin: 'http://example.test', href: 'http://example.test/admin/catalog/csv' } };
await import('../Modules/Catalog/resources/assets/js/app_error.js');
globalThis.AppError = window.AppError;
const { CatalogCsvPage } = await import('../Modules/Catalog/resources/assets/js/catalog_csv.js');
const { setCatalogContext } = await import('../Modules/Catalog/resources/assets/js/catalog-request.js');
globalThis.$ = (element) => element;

class Element {
    constructor() {
        this.handlers = new Map();
        this.disabled = false;
        this.value = '';
        this.message = '';
        this.classes = new Set();
        this[0] = {};
    }

    on(event, selector, handler) {
        this.handlers.set(`${event}:${typeof selector === 'string' ? selector : ''}`, handler || selector);
        return this;
    }

    prop(name, value) { this[name] = value; return this; }
    val() { return this.value; }
    text(value) {
        if (value === undefined) return this.message;
        this.message = value;
        return this;
    }
    removeClass() { return this; }
    addClass() { return this; }
    toggleClass(name, enabled) {
        if (enabled) this.classes.add(name);
        else this.classes.delete(name);
        return this;
    }
    find() { return this.buttons; }
    data() { return '20261002080000_import_snapshot.csv'; }
}

function pageFixture() {
    setCatalogContext({ apiBaseUrl: 'http://example.test/catalog/' });
    const page = new CatalogCsvPage({});
    const elements = Object.fromEntries(Object.keys(page.selectors).map((key) => [key, new Element()]));
    elements.csvHistoryTable.buttons = new Element();
    elements.truncateConfirmButton.disabled = true;
    elements.truncateConfirmButton.classes.add('fi-disabled');
    elements.csvImportFile[0] = { files: [new File(['category_path,product_name\n'], 'snapshot.csv')], value: 'snapshot.csv' };
    page.$el = (key) => elements[key];
    page.loadCsvHistory = async () => {};
    page.bindCsvEvents();
    page.bindTruncateEvents();
    return { page, elements };
}

const submitEvent = { preventDefault() {} };
const fire = (element, event, selector = '', payload = submitEvent) => element.handlers.get(`${event}:${selector}`)(payload);

test('an import blocks overlapping restores and releases all controls after completion', async () => {
    const { page, elements } = pageFixture();
    let complete;
    let restores = 0;
    page.postMultipart = () => new Promise((resolve) => { complete = resolve; });
    page.postJson = async () => { restores++; };

    const importing = fire(elements.csvImportForm, 'submit');
    assert.equal(elements.truncateButton.disabled, true);
    assert.equal(elements.csvHistoryTable.buttons.disabled, true);
    await fire(elements.csvHistoryTable, 'click', 'button[data-csv-restore]', { currentTarget: new Element() });
    assert.equal(restores, 0);
    complete({ success: true, data: { importedProducts: 2 } });
    await importing;

    assert.equal(elements.csvImportSubmit.disabled, false);
    assert.equal(elements.csvHistoryTable.buttons.disabled, false);
    assert.equal(elements.truncateConfirmButton.disabled, true);
    assert.match(elements.status.message, /CSV import completed \(2 products/);
});

test('a rejected restore reports its reason and allows retry', async () => {
    const { page, elements } = pageFixture();
    const responses = [
        { success: false, message: 'Missing required column "product_name".' },
        { success: true, data: { importedProducts: 4 } },
    ];
    page.postJson = async () => responses.shift();
    const event = { currentTarget: new Element() };

    await fire(elements.csvHistoryTable, 'click', 'button[data-csv-restore]', event);
    assert.match(elements.status.message, /Missing required column/);
    assert.equal(elements.csvHistoryTable.buttons.disabled, false);
    await fire(elements.csvHistoryTable, 'click', 'button[data-csv-restore]', event);
    assert.match(elements.status.message, /CSV restore completed \(4 products/);
});

test('an HTTP import failure releases the controls and shows its reference once', async (context) => {
    context.mock.method(console, 'error', () => {});
    const { page, elements } = pageFixture();
    const xhr = {
        status: 500,
        responseJSON: { message: 'Import failed. Please retry.', correlationId: 'csv-test-reference' },
    };
    page.postMultipart = () => page.toPromise({
        done() { return this; },
        fail(callback) { callback(xhr); return this; },
    }, 'v1.importCsv');

    const importing = fire(elements.csvImportForm, 'submit');
    assert.equal(elements.csvImportSubmit.disabled, true);
    await importing;

    assert.equal(elements.status.message, 'Error: Import failed. Please retry. (Ref: csv-test-reference)');
    assert.equal(elements.csvImportSubmit.disabled, false);
    assert.equal(elements.csvHistoryTable.buttons.disabled, false);
    assert.equal(elements.truncateButton.disabled, false);
});

test('a rejected truncate keeps its error visible and allows a corrected retry', async () => {
    const { page, elements } = pageFixture();
    elements.truncateConfirmInput.value = 'TRUNCATE';
    elements.truncateReasonInput.value = 'Isolated test';
    const responses = [
        { success: false, message: 'Another operation is already running.' },
        { success: true, data: { auditId: 'test-audit' } },
    ];
    page.postJson = async () => responses.shift();
    let closed = false;
    page.closeTruncateModal = () => { closed = true; };

    await fire(elements.truncateForm, 'submit');
    assert.equal(elements.truncateConfirmButton.disabled, false);
    assert.equal(elements.truncateConfirmButton.classes.has('fi-disabled'), false);
    assert.equal(elements.truncateModalError.message, 'Another operation is already running.');
    assert.equal(closed, false);
    await fire(elements.truncateForm, 'submit');
    assert.equal(closed, true);
    assert.match(elements.status.message, /Catalog truncated \(audit test-audit\)/);
});

test('server lock keeps the controls disabled when a local operation ends', async () => {
    const { page, elements } = pageFixture();
    page.beginCsvOperation('Importing...');
    page.state.truncate.serverLock = true;
    page.endCsvOperation();

    assert.equal(elements.csvImportSubmit.disabled, true);
    assert.equal(elements.csvHistoryTable.buttons.disabled, true);
    assert.equal(elements.truncateConfirmButton.disabled, true);
});

test('valid truncate confirmation clears the initial Filament disabled style', () => {
    const { elements } = pageFixture();
    elements.truncateConfirmInput.value = 'TRUNCATE';
    elements.truncateReasonInput.value = 'Isolated test';

    fire(elements.truncateReasonInput, 'input');

    assert.equal(elements.truncateConfirmButton.disabled, false);
    assert.equal(elements.truncateConfirmButton.classes.has('fi-disabled'), false);
    elements.truncateReasonInput.value = '';
    fire(elements.truncateReasonInput, 'input');
    assert.equal(elements.truncateConfirmButton.disabled, true);
});
