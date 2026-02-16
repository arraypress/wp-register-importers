/**
 * Importers Admin JavaScript
 *
 * 3-step wizard: Upload → Map Fields → Import
 * Handles file upload, field mapping, dry run validation,
 * batch processing, and progress tracking.
 *
 * @package ArrayPress\RegisterImporters
 * @since   2.0.0
 */

(function ($) {
    'use strict';

    /**
     * Importers Manager
     *
     * Manages all import card instances on the page.
     */
    const ImportersManager = {

        cards: {},

        /**
         * Initialize all import cards on the page.
         */
        init: function () {
            const self = this;

            $('.importers-card').each(function () {
                const $card = $(this);
                const operationId = $card.data('operation-id');

                self.cards[operationId] = new ImportCard($card, operationId);
            });

            // Sample CSV downloads
            $(document).on('click', '.importers-download-sample', function (e) {
                e.preventDefault();
                const opId = $(this).data('operation-id');
                self.downloadSampleCsv(opId);
            });
        },

        /**
         * Download a sample CSV file for an operation.
         *
         * @param {string} operationId
         */
        downloadSampleCsv: function (operationId) {
            $.ajax({
                url: ImportersAdmin.restUrl + 'sample/' + ImportersAdmin.pageId + '/' + operationId,
                method: 'GET',
                headers: {'X-WP-Nonce': ImportersAdmin.restNonce},
                success: function (response) {
                    if (response.success && response.csv) {
                        const blob = new Blob([response.csv], {type: 'text/csv'});
                        const url = URL.createObjectURL(blob);
                        const a = document.createElement('a');
                        a.href = url;
                        a.download = response.filename || 'sample.csv';
                        document.body.appendChild(a);
                        a.click();
                        document.body.removeChild(a);
                        URL.revokeObjectURL(url);
                    }
                }
            });
        }
    };

    /**
     * Import Card
     *
     * Manages a single import operation's 3-step wizard.
     *
     * @param {jQuery}  $card       The card element.
     * @param {string}  operationId The operation ID.
     */
    function ImportCard($card, operationId) {
        this.$card = $card;
        this.operationId = operationId;
        this.currentStep = 1;
        this.fileData = null;
        this.fieldMap = {};
        this.isProcessing = false;
        this.isCancelled = false;

        this.init();
    }

    ImportCard.prototype = {

        /**
         * Initialize the card.
         */
        init: function () {
            this.cacheElements();
            this.bindEvents();
        },

        /**
         * Cache frequently used DOM elements.
         */
        cacheElements: function () {
            this.$steps = this.$card.find('.importers-step');
            this.$dots = this.$card.find('.importers-step-dot');
            this.$nextBtn = this.$card.find('.importers-next-button');
            this.$backBtn = this.$card.find('.importers-back-button');
            this.$cancelBtn = this.$card.find('.importers-cancel-button');
            this.$dryRunBtn = this.$card.find('.importers-dry-run-button');
            this.$fileInput = this.$card.find('.importers-file-input');
            this.$dropzone = this.$card.find('.importers-dropzone');
            this.$fileInfo = this.$card.find('.importers-file-info');
            this.$mappingGrid = this.$card.find('.importers-mapping-grid');
            this.$progressFill = this.$card.find('.importers-progress-fill');
            this.$progressStatus = this.$card.find('.importers-progress-status');
            this.$progressPercent = this.$card.find('.importers-progress-percent');
            this.$logEntries = this.$card.find('.importers-log-entries');
            this.$completeSummary = this.$card.find('.importers-complete-summary');
        },

        /**
         * Bind events.
         */
        bindEvents: function () {
            const self = this;

            // File input change
            this.$fileInput.on('change', function () {
                if (this.files.length) {
                    self.handleFileSelect(this.files[0]);
                }
            });

            // Drag and drop
            this.$dropzone
                .on('dragover dragenter', function (e) {
                    e.preventDefault();
                    $(this).addClass('dragover');
                })
                .on('dragleave drop', function (e) {
                    e.preventDefault();
                    $(this).removeClass('dragover');
                })
                .on('drop', function (e) {
                    const files = e.originalEvent.dataTransfer.files;
                    if (files.length) {
                        self.handleFileSelect(files[0]);
                    }
                });

            // Remove file
            this.$card.find('.importers-file-remove').on('click', function () {
                self.resetToStep1();
            });

            // Navigation buttons
            this.$nextBtn.on('click', function () {
                self.handleNextStep();
            });

            this.$backBtn.on('click', function () {
                self.goToStep(self.currentStep - 1);
            });

            this.$cancelBtn.on('click', function () {
                self.handleCancel();
            });

            this.$dryRunBtn.on('click', function () {
                self.runDryRun();
            });
        },

        /* =================================================================
           File Upload
           ================================================================= */

        /**
         * Handle file selection.
         *
         * @param {File} file
         */
        handleFileSelect: function (file) {
            const self = this;

            // Validate extension
            if (!file.name.toLowerCase().endsWith('.csv')) {
                this.showNotice(ImportersAdmin.i18n.invalidFile, 'error');
                return;
            }

            const formData = new FormData();
            formData.append('import_file', file);
            formData.append('page_id', ImportersAdmin.pageId);
            formData.append('operation_id', this.operationId);

            // Show loading state
            this.$dropzone.hide();
            this.$fileInfo.show();
            this.$card.find('.importers-file-name').text(file.name);
            this.$card.find('.importers-file-size').text(ImportersAdmin.i18n.loading);

            $.ajax({
                url: ImportersAdmin.restUrl + 'upload',
                method: 'POST',
                headers: {'X-WP-Nonce': ImportersAdmin.restNonce},
                data: formData,
                processData: false,
                contentType: false,
                success: function (response) {
                    if (response.success) {
                        self.fileData = response.file;
                        self.$card.find('.importers-file-size').text(
                            response.file.size_human + ' — ' + response.file.rows + ' ' + ImportersAdmin.i18n.rows
                        );
                        self.$nextBtn.prop('disabled', false);
                    } else {
                        self.resetToStep1();
                        self.showNotice(ImportersAdmin.i18n.uploadFailed, 'error');
                    }
                },
                error: function (xhr) {
                    self.resetToStep1();
                    const msg = xhr.responseJSON?.message || ImportersAdmin.i18n.uploadFailed;
                    self.showNotice(msg, 'error');
                }
            });
        },

        /**
         * Reset to step 1 (remove file, clear state).
         */
        resetToStep1: function () {
            this.fileData = null;
            this.fieldMap = {};
            this.$fileInput.val('');
            this.$dropzone.show();
            this.$fileInfo.hide();
            this.$nextBtn.prop('disabled', true);
            this.$card.find('.importers-dry-run-results').remove();
            this.goToStep(1);
        },

        /* =================================================================
           Step Navigation
           ================================================================= */

        /**
         * Handle next step based on current position.
         */
        handleNextStep: function () {
            switch (this.currentStep) {
                case 1:
                    this.goToStep(2);
                    this.buildFieldMapping();
                    this.loadPreview();
                    break;
                case 2:
                    if (this.validateMapping()) {
                        this.goToStep(3);
                        this.startImport();
                    }
                    break;
            }
        },

        /**
         * Navigate to a specific step.
         *
         * @param {number} step
         */
        goToStep: function (step) {
            this.currentStep = step;

            // Show/hide steps
            this.$steps.hide();
            this.$card.find('.importers-step[data-step="' + step + '"]').show();

            // Update dots
            this.$dots.removeClass('active completed');
            this.$dots.each(function () {
                const dotStep = parseInt($(this).data('step'));
                if (dotStep === step) {
                    $(this).addClass('active');
                } else if (dotStep < step) {
                    $(this).addClass('completed');
                }
            });

            // Update buttons
            this.$backBtn.toggle(step === 2);
            this.$cancelBtn.toggle(step === 3 && this.isProcessing);
            this.$dryRunBtn.toggle(step === 2);

            switch (step) {
                case 1:
                    this.$nextBtn.show().prop('disabled', !this.fileData)
                        .find('.button-text').text(ImportersAdmin.i18n.continueToMap);
                    break;
                case 2:
                    this.$nextBtn.show().prop('disabled', false)
                        .find('.button-text').text(ImportersAdmin.i18n.startImport);
                    this.$nextBtn.addClass('button-primary');
                    break;
                case 3:
                    this.$nextBtn.hide();
                    break;
            }
        },

        /* =================================================================
           Field Mapping
           ================================================================= */

        /**
         * Build the field mapping UI.
         */
        buildFieldMapping: function () {
            const self = this;
            const fields = this.getOperationFields();
            const csvHeaders = this.fileData ? this.fileData.headers : [];

            this.$mappingGrid.empty();

            let currentGroup = null;

            Object.keys(fields).forEach(function (fieldKey) {
                const field = fields[fieldKey];
                const group = field.group || null;

                // Group separator
                if (group && group !== currentGroup) {
                    currentGroup = group;
                    self.$mappingGrid.append(
                        '<div class="importers-mapping-group-label">' + self.escHtml(group) + '</div>'
                    );
                }

                const isRequired = field.required;
                const $row = $('<div class="importers-mapping-row" data-field="' + fieldKey + '">');

                // Field label
                const requiredMark = isRequired ? ' <span class="required-indicator">*</span>' : '';
                $row.append('<div class="importers-mapping-field">' + self.escHtml(field.label || fieldKey) + requiredMark + '</div>');

                // Arrow
                $row.append('<div class="importers-mapping-arrow"><span class="dashicons dashicons-arrow-right-alt"></span></div>');

                // Select dropdown
                const $selectWrap = $('<div class="importers-mapping-select">');
                const $select = $('<select data-field="' + fieldKey + '">');
                $select.append('<option value="">' + ImportersAdmin.i18n.selectColumn + '</option>');

                csvHeaders.forEach(function (header) {
                    $select.append('<option value="' + self.escHtml(header) + '">' + self.escHtml(header) + '</option>');
                });

                // Auto-match by label/key
                const autoMatch = self.findBestMatch(fieldKey, field.label || fieldKey, csvHeaders);
                if (autoMatch) {
                    $select.val(autoMatch);
                    self.fieldMap[fieldKey] = autoMatch;
                }

                $select.on('change', function () {
                    self.fieldMap[fieldKey] = $(this).val();
                });

                $selectWrap.append($select);
                $row.append($selectWrap);
                self.$mappingGrid.append($row);
            });
        },

        /**
         * Find the best auto-match between a field key and CSV headers.
         *
         * @param   {string}   key
         * @param   {string}   label
         * @param   {string[]} headers
         * @returns {string|null}
         */
        findBestMatch: function (key, label, headers) {
            const normalizedKey = key.toLowerCase().replace(/[_\-]/g, '');
            const normalizedLabel = label.toLowerCase().replace(/[_\-\s]/g, '');

            for (let i = 0; i < headers.length; i++) {
                const normalizedHeader = headers[i].toLowerCase().replace(/[_\-\s]/g, '');

                if (normalizedHeader === normalizedKey || normalizedHeader === normalizedLabel) {
                    return headers[i];
                }
            }

            return null;
        },

        /**
         * Validate that all required fields are mapped.
         *
         * @returns {boolean}
         */
        validateMapping: function () {
            const fields = this.getOperationFields();
            const missing = [];

            Object.keys(fields).forEach(function (key) {
                if (fields[key].required && !this.fieldMap[key]) {
                    missing.push(fields[key].label || key);
                }
            }.bind(this));

            if (missing.length) {
                this.showNotice(
                    ImportersAdmin.i18n.mapRequiredFields + ' ' + missing.join(', '),
                    'error'
                );
                return false;
            }

            return true;
        },

        /**
         * Load preview data for the mapped fields.
         */
        loadPreview: function () {
            const self = this;

            if (!this.fileData) return;

            $.ajax({
                url: ImportersAdmin.restUrl + 'preview/' + this.fileData.uuid,
                method: 'GET',
                headers: {'X-WP-Nonce': ImportersAdmin.restNonce},
                data: {max_rows: 5},
                success: function (response) {
                    if (response.success) {
                        self.renderPreview(response.preview);
                    }
                }
            });
        },

        /**
         * Render the preview table.
         *
         * @param {object} preview
         */
        renderPreview: function (preview) {
            const $thead = this.$card.find('.importers-preview-table thead');
            const $tbody = this.$card.find('.importers-preview-table tbody');

            $thead.empty();
            $tbody.empty();

            // Headers
            let headerRow = '<tr>';
            preview.headers.forEach(function (h) {
                headerRow += '<th>' + this.escHtml(h) + '</th>';
            }.bind(this));
            headerRow += '</tr>';
            $thead.append(headerRow);

            // Rows
            preview.rows.forEach(function (row) {
                let tr = '<tr>';
                row.forEach(function (cell) {
                    tr += '<td>' + this.escHtml(cell || '') + '</td>';
                }.bind(this));
                tr += '</tr>';
                $tbody.append(tr);
            }.bind(this));
        },

        /* =================================================================
           Dry Run
           ================================================================= */

        /**
         * Run a dry run (validate without importing).
         */
        runDryRun: function () {
            const self = this;

            if (!this.validateMapping()) return;

            this.$dryRunBtn.prop('disabled', true)
                .find('.button-text').text(ImportersAdmin.i18n.dryRunning);

            // Remove previous results
            this.$card.find('.importers-dry-run-results').remove();

            $.ajax({
                url: ImportersAdmin.restUrl + 'dry-run',
                method: 'POST',
                headers: {'X-WP-Nonce': ImportersAdmin.restNonce},
                contentType: 'application/json',
                data: JSON.stringify({
                    page_id: ImportersAdmin.pageId,
                    operation_id: this.operationId,
                    file_uuid: this.fileData.uuid,
                    field_map: this.fieldMap
                }),
                success: function (response) {
                    self.showDryRunResults(response);
                },
                error: function (xhr) {
                    const msg = xhr.responseJSON?.message || ImportersAdmin.i18n.errorOccurred;
                    self.showDryRunResults({success: false, error_count: 1, errors: [{message: msg}]});
                },
                complete: function () {
                    self.$dryRunBtn.prop('disabled', false)
                        .find('.button-text').text(ImportersAdmin.i18n.dryRun);
                }
            });
        },

        /**
         * Display dry run results.
         *
         * @param {object} response
         */
        showDryRunResults: function (response) {
            const hasErrors = response.error_count > 0;
            const cls = hasErrors ? 'has-errors' : 'success';

            let html = '<div class="importers-dry-run-results ' + cls + '">';
            html += '<strong>';
            html += ImportersAdmin.i18n.dryRunComplete
                .replace('%d', response.valid_rows || 0)
                .replace('%d', response.error_count || 0)
                .replace('%d', response.total_rows || 0);
            html += '</strong>';

            if (hasErrors && response.errors && response.errors.length) {
                html += '<ul class="importers-dry-run-errors">';
                response.errors.forEach(function (err) {
                    html += '<li>';
                    if (err.row) html += '<strong>Row ' + err.row + ':</strong> ';
                    if (err.item) html += err.item + ' — ';
                    html += err.message;
                    html += '</li>';
                });
                html += '</ul>';
            }

            html += '</div>';

            this.$mappingGrid.after(html);
        },

        /* =================================================================
           Import Processing
           ================================================================= */

        /**
         * Start the import process.
         */
        startImport: function () {
            const self = this;

            this.isProcessing = true;
            this.isCancelled = false;

            this.$cancelBtn.show();
            this.$logEntries.empty();
            this.$completeSummary.hide();
            this.$progressFill.css('width', '0%').removeClass('complete error');
            this.$progressStatus.text(ImportersAdmin.i18n.startingImport);
            this.$progressPercent.text('0%');

            // Reset stats display
            this.$card.find('.importers-stat-created').text('0');
            this.$card.find('.importers-stat-updated').text('0');
            this.$card.find('.importers-stat-skipped').text('0');
            this.$card.find('.importers-stat-failed').text('0');

            this.addLogEntry(ImportersAdmin.i18n.startingImport, 'info');

            // Start import
            $.ajax({
                url: ImportersAdmin.restUrl + 'import/start',
                method: 'POST',
                headers: {'X-WP-Nonce': ImportersAdmin.restNonce},
                contentType: 'application/json',
                data: JSON.stringify({
                    page_id: ImportersAdmin.pageId,
                    operation_id: this.operationId,
                    file_uuid: this.fileData.uuid,
                    field_map: this.fieldMap
                }),
                success: function (response) {
                    if (response.success) {
                        self.totalItems = response.total_items;
                        self.batchSize = response.batch_size;
                        self.addLogEntry(
                            ImportersAdmin.i18n.processingRows.replace('%d', response.total_items),
                            'info'
                        );
                        self.processBatch(0);
                    }
                },
                error: function (xhr) {
                    const msg = xhr.responseJSON?.message || ImportersAdmin.i18n.errorOccurred;
                    self.addLogEntry(ImportersAdmin.i18n.failedToStart + ' ' + msg, 'error');
                    self.completeImport('error');
                }
            });
        },

        /**
         * Process a batch of rows.
         *
         * @param {number} offset
         */
        processBatch: function (offset) {
            const self = this;

            if (this.isCancelled) return;

            $.ajax({
                url: ImportersAdmin.restUrl + 'import/batch',
                method: 'POST',
                headers: {'X-WP-Nonce': ImportersAdmin.restNonce},
                contentType: 'application/json',
                data: JSON.stringify({
                    page_id: ImportersAdmin.pageId,
                    operation_id: this.operationId,
                    file_uuid: this.fileData.uuid,
                    offset: offset,
                    field_map: this.fieldMap
                }),
                success: function (response) {
                    if (self.isCancelled) return;

                    if (response.success) {
                        self.updateProgress(response);
                        self.updateStats(response);

                        // Log errors
                        if (response.errors && response.errors.length) {
                            response.errors.forEach(function (err) {
                                let msg = '';
                                if (err.row) msg += ImportersAdmin.i18n.rowError.replace('%d', err.row) + ' ';
                                if (err.item) msg += err.item + ' — ';
                                msg += err.message;
                                self.addLogEntry(msg, 'error');
                            });
                        }

                        if (response.has_more) {
                            self.processBatch(response.offset);
                        } else {
                            self.completeImport('complete');
                        }
                    }
                },
                error: function (xhr) {
                    if (self.isCancelled) return;

                    const msg = xhr.responseJSON?.message || ImportersAdmin.i18n.errorOccurred;
                    self.addLogEntry(ImportersAdmin.i18n.batchFailed + ' ' + msg, 'error');
                    self.completeImport('error');
                }
            });
        },

        /**
         * Update progress bar.
         *
         * @param {object} response
         */
        updateProgress: function (response) {
            const pct = response.percentage || 0;

            this.$progressFill.css('width', pct + '%');
            this.$progressPercent.text(pct + '%');
            this.$progressStatus.text(
                response.total_processed + ' / ' + response.total_items
            );
        },

        /**
         * Update live stats display.
         *
         * @param {object} response
         */
        updateStats: function (response) {
            const stats = response.stats || {};

            this.$card.find('.importers-stat-created').text(stats.created || 0);
            this.$card.find('.importers-stat-updated').text(stats.updated || 0);
            this.$card.find('.importers-stat-skipped').text(stats.skipped || 0);
            this.$card.find('.importers-stat-failed').text(stats.failed || 0);
        },

        /**
         * Complete the import process.
         *
         * @param {string} status 'complete', 'cancelled', or 'error'
         */
        completeImport: function (status) {
            const self = this;
            this.isProcessing = false;

            this.$cancelBtn.hide();
            this.$progressFill.addClass(status === 'error' ? 'error' : 'complete');
            this.$progressFill.css('width', '100%');
            this.$progressPercent.text('100%');

            if (status === 'complete') {
                this.addLogEntry(ImportersAdmin.i18n.importCompleteMsg, 'success');
            }

            // Notify server
            $.ajax({
                url: ImportersAdmin.restUrl + 'complete',
                method: 'POST',
                headers: {'X-WP-Nonce': ImportersAdmin.restNonce},
                contentType: 'application/json',
                data: JSON.stringify({
                    page_id: ImportersAdmin.pageId,
                    operation_id: this.operationId,
                    status: status,
                    file_uuid: this.fileData ? this.fileData.uuid : null
                }),
                success: function (response) {
                    self.showCompleteSummary(response.stats || {}, status);
                }
            });

            // Show "Run Another" button
            this.$nextBtn.show().prop('disabled', false)
                .find('.button-text').text(ImportersAdmin.i18n.runAnother);
            this.$nextBtn.off('click').on('click', function () {
                self.resetToStep1();
            });
        },

        /**
         * Show the completion summary overlay.
         *
         * @param {object} stats
         * @param {string} status
         */
        showCompleteSummary: function (stats, status) {
            this.$completeSummary.show();

            // Update progress bar colour based on actual results
            if (stats.failed > 0 && (stats.created || 0) === 0 && (stats.updated || 0) === 0) {
                // All failed — show error state
                this.$progressFill.removeClass('complete').addClass('error');
                this.$completeSummary.addClass('has-errors');
            } else if (stats.failed > 0) {
                // Partial failure — warning state
                this.$completeSummary.addClass('has-errors');
            }

            // Show errors table
            if (stats.errors && stats.errors.length) {
                const $errorsSection = this.$completeSummary.find('.importers-complete-errors');
                const $tbody = $errorsSection.find('tbody');
                $tbody.empty();

                stats.errors.forEach(function (err) {
                    $tbody.append(
                        '<tr>' +
                        '<td>' + (err.row || '—') + '</td>' +
                        '<td>' + this.escHtml(err.item || '—') + '</td>' +
                        '<td>' + this.escHtml(err.message) + '</td>' +
                        '</tr>'
                    );
                }.bind(this));

                $errorsSection.show();
            }
        },

        /**
         * Handle cancel request.
         */
        handleCancel: function () {
            if (!confirm(ImportersAdmin.i18n.confirmCancel)) return;

            this.isCancelled = true;
            this.addLogEntry(ImportersAdmin.i18n.operationCancelled, 'info');
            this.completeImport('cancelled');
        },

        /* =================================================================
           Utilities
           ================================================================= */

        /**
         * Get field definitions for this operation.
         *
         * @returns {object}
         */
        getOperationFields: function () {
            const op = ImportersAdmin.operations[this.operationId];
            return op ? (op.fields || {}) : {};
        },

        /**
         * Add an entry to the activity log.
         *
         * @param {string} message
         * @param {string} type    'info', 'success', 'error'
         */
        addLogEntry: function (message, type) {
            type = type || 'info';
            const time = new Date().toLocaleTimeString();
            const $entry = $('<div class="importers-log-entry ' + type + '">')
                .text('[' + time + '] ' + message);

            this.$logEntries.append($entry);
            this.$logEntries.scrollTop(this.$logEntries[0].scrollHeight);
        },

        /**
         * Show a notice message.
         *
         * @param {string} message
         * @param {string} type 'success', 'error', 'warning'
         */
        showNotice: function (message, type) {
            const $notices = this.$card.closest('.importers-wrap').find('.importers-notices');
            const cls = type === 'error' ? 'notice-error' : (type === 'success' ? 'notice-success' : 'notice-warning');

            const $notice = $('<div class="notice ' + cls + ' is-dismissible"><p>' + this.escHtml(message) + '</p></div>');

            $notices.empty().append($notice);

            // Auto-dismiss after 5 seconds
            setTimeout(function () {
                $notice.fadeOut(300, function () {
                    $(this).remove();
                });
            }, 5000);
        },

        /**
         * Escape HTML entities.
         *
         * @param   {string} str
         * @returns {string}
         */
        escHtml: function (str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.appendChild(document.createTextNode(str));
            return div.innerHTML;
        }
    };

    // Initialize on DOM ready
    $(document).ready(function () {
        if ($('.importers-card').length) {
            ImportersManager.init();
        }
    });

})(jQuery);
