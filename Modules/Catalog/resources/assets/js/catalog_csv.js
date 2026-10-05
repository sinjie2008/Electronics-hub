import { catalogAjax, catalogGetJson, catalogEndpoint } from './catalog-request.js';

/**
 * catalog_csv.js
 * Dedicated CSV import/export + truncate management page bindings.
 */
export class CatalogCsvPage {
    constructor(root) {
        'use strict';
        this.root = root;
        this.apiBase = catalogEndpoint('catalog.php');
        this.TRUNCATE_TOKEN = 'TRUNCATE';

        this.selectors = {
            status: '#status-message',
            csvExportButton: '#csv-export-button',
            csvImportForm: '#csv-import-form',
            csvImportFile: '#csv-import-file',
            csvImportSubmit: '#csv-import-submit',
            csvHistoryTable: '#csv-history-table',
            truncateButton: '#truncate-button',
            truncateAuditTable: '#truncate-audit-table',
            truncateModal: '#truncate-modal',
            truncateBackdrop: '#truncate-modal-backdrop',
            truncateForm: '#truncate-form',
            truncateConfirmInput: '#truncate-confirm-input',
            truncateReasonInput: '#truncate-reason-input',
            truncateCancelButton: '#truncate-cancel-button',
            truncateConfirmButton: '#truncate-confirm-button',
            truncateModalError: '#truncate-modal-error',
        };

        this.state = {
            csvSubmitting: false,
            truncate: {
                submitting: false,
                serverLock: false,
            },
        };

        this.domCache = new Map();
        this.dataTableRegistry = new Map();

        this.DATA_TABLE_DOM = '<"row g-2 align-items-center mb-2"<"col-12 col-md-6"l><"col-12 col-md-6 text-md-end"f>>' +
        't' +
        '<"row g-2 align-items-center mt-2"<"col-12 col-md-6"i><"col-12 col-md-6 text-md-end"p>>';

        this.DATA_TABLE_LANGUAGE = {
            search: 'Search:',
            searchPlaceholder: 'Search...',
            zeroRecords: 'No matching records found.',
            info: 'Showing _START_ to _END_ of _TOTAL_ entries',
            infoEmpty: 'Showing 0 entries',
            lengthMenu: 'Show _MENU_ entries',
            paginate: {
                previous: 'Prev',
                next: 'Next',
            },
        };

        this.DATA_TABLE_DEFAULTS = {
            paging: true,
            searching: true,
            ordering: true,
            lengthChange: true,
            pageLength: 10,
            lengthMenu: [
                [5, 10, 25, 50, -1],
                [5, 10, 25, 50, 'All'],
            ],
            autoWidth: false,
            info: true,
            dom: this.DATA_TABLE_DOM,
            language: this.DATA_TABLE_LANGUAGE,
            order: [[0, 'asc']],
        };

        for (const methodName of Object.getOwnPropertyNames(Object.getPrototypeOf(this))) {
            if (methodName !== "constructor" && typeof this[methodName] === "function") {
                this[methodName] = this[methodName].bind(this);
            }
        }


    }

    $el(key) {
        const page = this;
        if (!page.domCache.has(key)) {
            page.domCache.set(key, $(page.root).find(page.selectors[key]));
        }
        return page.domCache.get(key);
    }

    escapeHtml(value = '') {
        const page = this;

        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    formatDateTime(timestamp) {
        const page = this;
        if (!timestamp) {
            return '';
        }
        const date = new Date(timestamp);
        if (Number.isNaN(date.getTime())) {
            return '';
        }
        return date.toLocaleString();
    }

    withLoading(factory) {
        const page = this;
        if (window.LoadingOverlay && typeof window.LoadingOverlay.wrapPromise === 'function') {
            return window.LoadingOverlay.wrapPromise(factory);
        }
        return factory();
    }

    toPromise(jqXHR, action = 'ajax') {
        const page = this;

        return page.withLoading(
            () =>
                new Promise((resolve, reject) => {
                    jqXHR
                        .done((data, textStatus, xhr) => {
                            const correlationId = AppError.extractCorrelationId(data, xhr);
                            AppError.logDev({
                                level: 'info',
                                endpoint: action,
                                status: xhr?.status,
                                correlationId,
                                message: 'ok',
                            });
                            resolve(data);
                        })
                        .fail((xhr) => {
                            const error = AppError.handleAjaxFailure(xhr, action, 'Request failed.');
                            reject(error);
                        });
                })
        );
    }

    requestJson(params) {
        const page = this;
        return page.toPromise(catalogGetJson(page.apiBase, params), params?.action ?? 'ajax:get');
    }

    postJson(action, payload = {}) {
        const page = this;

        return page.toPromise(
            catalogAjax({
                url: `${page.apiBase}?action=${encodeURIComponent(action)}`,
                method: 'POST',
                contentType: 'application/json',
                dataType: 'json',
                data: JSON.stringify(payload),
            }),
            action
        );
    }

    postMultipart(action, formData) {
        const page = this;

        return page.toPromise(
            catalogAjax({
                url: `${page.apiBase}?action=${encodeURIComponent(action)}`,
                method: 'POST',
                processData: false,
                contentType: false,
                dataType: 'json',
                data: formData,
            }),
            action
        );
    }

    setStatus(message = '', isError = false) {
        const page = this;
        const $status = page.$el('status');
        $status.removeClass('status-info status-error');
        if (!message) {
            $status.text('');
            return;
        }
        $status
            .text(`${isError ? 'Error' : 'Info'}: ${message}`)
            .addClass(isError ? 'status-error' : 'status-info');
    }

    setStatusWithError(message, error) {
        const page = this;
        const correlationId = error?.correlationId ?? null;
        page.setStatus(error?.message || AppError.buildUserMessage(message, correlationId), true);
    }

    handleErrorResponse(response, fallback = 'Request failed.') {
        const page = this;
        if (!response) {
            page.setStatus(AppError.buildUserMessage('Unexpected error occurred.', null), true);
            return;
        }
        const correlationId = AppError.extractCorrelationId(response);
        let message = response.message || response.error?.message || fallback;
        if (response.details) {
            const parts = Object.values(response.details)
                .filter(Boolean)
                .map((detail) => detail);
            if (parts.length) {
                message = `${message} (${parts.join('; ')})`;
            }
        }
        page.setStatus(AppError.buildUserMessage(message, correlationId), true);
    }

    buildTableHeader(tableKey, columns = []) {
        const page = this;
        const $table = page.$el(tableKey);
        if (!$table.length) {
            return $table;
        }
        const headerHtml = columns.length
            ? `<tr>${columns.map((col) => `<th>${col.title}</th>`).join('')}</tr>`
            : '';
        let $thead = $table.find('thead');
        if (!$thead.length) {
            $thead = $('<thead></thead>').appendTo($table);
        }
        $thead.html(headerHtml);
        if (!$table.find('tbody').length) {
            $('<tbody></tbody>').appendTo($table);
        }
        return $table;
    }

    setEmptyTableState(tableKey, columns = [], message = 'No records found.') {
        const page = this;
        const $table = page.buildTableHeader(tableKey, columns);
        const $tbody = $table.find('tbody');
        const colspan = Math.max(columns.length, 1);
        $tbody.html(
            `<tr><td colspan="${colspan}" class="datatable-empty">${page.escapeHtml(message)}</td></tr>`
        );
    }

    destroyDataTable(tableKey) {
        const page = this;
        const entry = page.dataTableRegistry.get(tableKey);
        if (entry?.instance) {
            entry.instance.destroy();
        }
        page.dataTableRegistry.delete(tableKey);
    }

    syncDataTable(tableKey, columns, rows, options = {}) {
        const page = this;
        if (!Array.isArray(rows) || !rows.length) {
            page.destroyDataTable(tableKey);
            page.setEmptyTableState(tableKey, columns, options.emptyMessage);
            return;
        }
        const $table = page.buildTableHeader(tableKey, columns);
        const extraOptions = options.extraOptions || {};
        let signature = '';
        try {
            signature = JSON.stringify({
                columns: columns.map((col) => col.title),
                extra: options.signatureKey || extraOptions,
            });
        } catch (error) {
            signature = columns.map((col) => col.title).join('|');
        }
        const entry = page.dataTableRegistry.get(tableKey);
        if (entry?.instance && entry.signature === signature) {
            entry.instance.clear();
            entry.instance.rows.add(rows);
            entry.instance.draw(false);
            return;
        }
        page.destroyDataTable(tableKey);
        const instance = $table.DataTable({
            ...page.DATA_TABLE_DEFAULTS,
            ...extraOptions,
            data: rows,
            columns,
        });
        page.dataTableRegistry.set(tableKey, { instance, signature });
    }

    isOperationLocked() {
        return this.state.csvSubmitting || this.state.truncate.submitting || this.state.truncate.serverLock;
    }

    beginCsvOperation(message) {
        if (this.isOperationLocked()) {
            return false;
        }
        this.state.csvSubmitting = true;
        this.applyCsvLockState();
        this.setStatus(message);
        return true;
    }

    endCsvOperation() {
        this.state.csvSubmitting = false;
        this.applyCsvLockState();
    }

    applyCsvLockState() {
        const page = this;
        const locked = page.isOperationLocked();
        [
            'csvExportButton',
            'csvImportSubmit',
            'csvImportFile',
            'truncateButton',
        ].forEach((key) => {
            page.$el(key).prop('disabled', locked);
        });
        page.$el('csvHistoryTable').find('button').prop('disabled', locked);
        page.updateTruncateConfirmState();
    }

    renderCsvHistory(files) {
        const page = this;
        const columns = [
            { title: 'Type', data: 'type', width: '90px' },
            { title: 'Name', data: 'name' },
            {
                title: 'Timestamp',
                data: 'timestampRaw',
                render: (data) => page.formatDateTime(data),
            },
            {
                title: 'Size (bytes)',
                data: 'sizeRaw',
                className: 'text-end',
                render: (data, type, row) => (type === 'display' ? row.sizeDisplay : data ?? 0),
            },
            {
                title: 'Actions',
                data: 'actions',
                orderable: false,
                searchable: false,
                className: 'text-nowrap',
            },
        ];
        if (!files.length) {
            page.destroyDataTable('csvHistoryTable');
            page.setEmptyTableState('csvHistoryTable', columns, 'No CSV files stored.');
            return;
        }
        const rows = files.map((file) => {
            const size = Number(file.size || 0);
            return {
                type: page.escapeHtml((file.type || '').toString().toUpperCase()),
                name: page.escapeHtml(file.name || file.id),
                timestampRaw: file.timestamp,
                sizeRaw: Number.isNaN(size) ? 0 : size,
                sizeDisplay: Number.isNaN(size) ? '0' : size.toLocaleString(),
                actions: `<div class="datatable-actions">
                    <button type="button" data-csv-download="${file.id}">Download</button>
                    <button type="button" data-csv-restore="${file.id}">Restore</button>
                    <button type="button" data-csv-delete="${file.id}">Delete</button>
                </div>`,
            };
        });
        page.syncDataTable('csvHistoryTable', columns, rows, {
            order: [[2, 'desc']],
            pageLength: 5,
            emptyMessage: 'No CSV files stored.',
        });
    }

    formatDeletedSummary(deleted = {}) {
        const page = this;
        const preferred = ['categories', 'series', 'products', 'fieldDefinitions', 'productValues', 'seriesValues'];
        const seen = new Set();
        const parts = [];
        preferred.forEach((key) => {
            if (deleted[key] !== undefined) {
                parts.push(`${key}: ${deleted[key]}`);
                seen.add(key);
            }
        });
        Object.keys(deleted).forEach((key) => {
            if (!seen.has(key)) {
                parts.push(`${key}: ${deleted[key]}`);
            }
        });
        return parts.length ? parts.join(', ') : 'n/a';
    }

    renderTruncateAudits(audits) {
        const page = this;
        const columns = [
            {
                title: 'Timestamp',
                data: 'timestampRaw',
                render: (data) => page.formatDateTime(data),
            },
            { title: 'Reason', data: 'reason' },
            { title: 'Deleted', data: 'deleted' },
            { title: 'Audit ID', data: 'auditId' },
        ];
        if (!audits.length) {
            page.destroyDataTable('truncateAuditTable');
            page.setEmptyTableState('truncateAuditTable', columns, 'No truncate actions logged.');
            return;
        }
        const rows = audits.map((audit) => ({
            timestampRaw: audit.timestamp,
            reason: page.escapeHtml(audit.reason || ''),
            deleted: page.escapeHtml(page.formatDeletedSummary(audit.deleted)),
            auditId: page.escapeHtml(audit.id || ''),
        }));
        page.syncDataTable('truncateAuditTable', columns, rows, {
            order: [[0, 'desc']],
            pageLength: 5,
            emptyMessage: 'No truncate actions logged.',
        });
    }

    async loadCsvHistory() {
        const page = this;
        try {
            const response = await page.requestJson({ action: 'v1.listCsvHistory' });
            if (!response.success) {
                page.handleErrorResponse(response);
                return;
            }
            const payload = response.data || {};
            page.renderCsvHistory(payload.files || []);
            page.renderTruncateAudits(payload.audits || []);
            page.state.truncate.serverLock = payload.truncateInProgress === true;
            page.applyCsvLockState();
        } catch (error) {
            console.error(error);
            page.setStatusWithError('Unable to load CSV history.', error);
        }
    }

    triggerCsvDownload(fileId) {
        const page = this;
        if (!fileId) {
            return;
        }
        window.location = `${page.apiBase}?action=${encodeURIComponent('v1.downloadCsv')}&id=${encodeURIComponent(fileId)}`;
    }

    openTruncateModal() {
        const page = this;
        page.$el('truncateForm')[0].reset();
        page.$el('truncateModalError').text('');
        page.updateTruncateConfirmState();
        page.$el('truncateModal').removeAttr('hidden');
        page.$el('truncateBackdrop').removeAttr('hidden');
        window.setTimeout(() => {
            page.$el('truncateConfirmInput').trigger('focus');
        }, 0);
    }

    closeTruncateModal() {
        const page = this;
        page.$el('truncateModal').attr('hidden', true);
        page.$el('truncateBackdrop').attr('hidden', true);
    }

    updateTruncateConfirmState() {
        const page = this;
        const token = page.$el('truncateConfirmInput')
            .val()
            .toString()
            .trim()
            .toUpperCase();
        const reason = page.$el('truncateReasonInput').val().toString().trim();
        const disabled = page.isOperationLocked() || !(token === page.TRUNCATE_TOKEN && reason.length > 0);
        page.$el('truncateConfirmButton').prop('disabled', disabled).toggleClass('fi-disabled', disabled);
    }

    bindCsvEvents() {
        const page = this;
        page.$el('csvExportButton').on('click', async () => {
            if (!page.beginCsvOperation('Exporting catalog CSV...')) {
                return;
            }
            try {
                const response = await page.postJson('v1.exportCsv', {});
                if (!response.success) {
                    page.handleErrorResponse(response);
                    return;
                }
                const file = response.data || {};
                page.setStatus('Catalog CSV exported.', false);
                await page.loadCsvHistory();
                if (file.id) {
                    page.triggerCsvDownload(file.id);
                }
            } catch (error) {
                console.error(error);
                page.setStatusWithError('Failed to export catalog CSV.', error);
            } finally {
                page.endCsvOperation();
            }
        });

        page.$el('csvImportForm').on('submit', async (event) => {
            event.preventDefault();
            if (page.isOperationLocked()) {
                return;
            }
            const fileInput = page.$el('csvImportFile')[0];
            if (!fileInput.files || !fileInput.files.length) {
                page.setStatus('Select a CSV file to import.', true);
                return;
            }
            const formData = new FormData();
            formData.append('file', fileInput.files[0]);
            if (!page.beginCsvOperation('Importing CSV snapshot. Please wait for completion...')) {
                return;
            }
            try {
                const response = await page.postMultipart('v1.importCsv', formData);
                if (!response.success) {
                    page.handleErrorResponse(response);
                    return;
                }
                const data = response.data || {};
                const message = `CSV import completed (${data.importedProducts ?? 0} products, ${data.createdSeries ?? 0} new series, ${data.createdCategories ?? 0} new categories).`;
                page.setStatus(message, false);
                fileInput.value = '';
                await page.loadCsvHistory();
            } catch (error) {
                console.error(error);
                page.setStatusWithError('Failed to import CSV.', error);
            } finally {
                page.endCsvOperation();
            }
        });

        page.$el('csvHistoryTable').on('click', 'button[data-csv-download]', (event) => {
            const fileId = $(event.currentTarget).data('csv-download');
            page.triggerCsvDownload(fileId);
        });

        page.$el('csvHistoryTable').on('click', 'button[data-csv-restore]', async (event) => {
            const fileId = $(event.currentTarget).data('csv-restore');
            if (!fileId) {
                return;
            }
            if (!page.beginCsvOperation('Restoring CSV snapshot. Please wait for completion...')) {
                return;
            }
            try {
                const response = await page.postJson('v1.restoreCsv', { id: fileId });
                if (!response.success) {
                    page.handleErrorResponse(response);
                    return;
                }
                const data = response.data || {};
                const message = `CSV restore completed (${data.importedProducts ?? 0} products, ${data.createdSeries ?? 0} new series, ${data.createdCategories ?? 0} new categories).`;
                page.setStatus(message, false);
                await page.loadCsvHistory();
            } catch (error) {
                console.error(error);
                page.setStatusWithError('Failed to restore CSV file.', error);
            } finally {
                page.endCsvOperation();
            }
        });

        page.$el('csvHistoryTable').on('click', 'button[data-csv-delete]', async (event) => {
            const fileId = $(event.currentTarget).data('csv-delete');
            if (!fileId || page.isOperationLocked()) {
                return;
            }
            if (!window.confirm('Delete this CSV file?')) {
                return;
            }
            if (!page.beginCsvOperation('Deleting CSV file...')) {
                return;
            }
            try {
                const response = await page.postJson('v1.deleteCsv', { id: fileId });
                if (!response.success) {
                    page.handleErrorResponse(response);
                    return;
                }
                page.setStatus('CSV file deleted.', false);
                await page.loadCsvHistory();
            } catch (error) {
                console.error(error);
                page.setStatusWithError('Failed to delete CSV file.', error);
            } finally {
                page.endCsvOperation();
            }
        });
    }

    bindTruncateEvents() {
        const page = this;
        page.$el('truncateButton').on('click', () => {
            if (page.isOperationLocked()) {
                page.setStatus('Another catalog operation is in progress. Please wait for it to finish.', true);
                return;
            }
            page.openTruncateModal();
        });

        const updateConfirmation = () => {
            page.$el('truncateModalError').text('');
            page.updateTruncateConfirmState();
        };
        page.$el('truncateConfirmInput').on('input', updateConfirmation);
        page.$el('truncateReasonInput').on('input', updateConfirmation);

        page.$el('truncateCancelButton').on('click', () => {
            page.closeTruncateModal();
        });

        page.$el('truncateForm').on('submit', async (event) => {
            event.preventDefault();
            if (page.isOperationLocked()) {
                page.$el('truncateModalError').text('Another catalog operation is running. Try again once it completes.');
                return;
            }
            const confirmToken = page.$el('truncateConfirmInput')
                .val()
                .toString()
                .trim()
                .toUpperCase();
            const reason = page.$el('truncateReasonInput').val().toString().trim();
            if (confirmToken !== page.TRUNCATE_TOKEN || !reason) {
                page.$el('truncateModalError').text('Type TRUNCATE and provide a reason to continue.');
                return;
            }
            const payload = {
                reason,
                confirmToken,
                correlationId: window.crypto?.randomUUID?.() || `truncate-${Date.now()}`,
            };
            page.state.truncate.submitting = true;
            page.applyCsvLockState();
            page.$el('truncateModalError').text('');
            page.setStatus('Truncating catalog...');
            try {
                const response = await page.postJson('v1.truncateCatalog', payload);
                if (!response.success) {
                    page.handleErrorResponse(response);
                    page.$el('truncateModalError').text(response.message || 'Truncate failed.');
                    return;
                }
                const auditId = response.data?.auditId || payload.correlationId;
                page.setStatus(`Catalog truncated (audit ${auditId}).`, false);
                page.closeTruncateModal();
                page.state.truncate.submitting = false;
                await page.loadCsvHistory();
            } catch (error) {
                console.error(error);
                const message = AppError.buildUserMessage('Unable to truncate catalog.', error?.correlationId);
                page.$el('truncateModalError').text(message);
                page.setStatusWithError('Unable to truncate catalog.', error);
            } finally {
                page.state.truncate.submitting = false;
                page.applyCsvLockState();
            }
        });
    }

    async init() {
        const page = this;
        page.bindCsvEvents();
        page.bindTruncateEvents();
        page.updateTruncateConfirmState();
        await page.loadCsvHistory();
    }
}
