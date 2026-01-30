/**
 * Importers Admin JavaScript
 *
 * @package ArrayPress\RegisterImporters
 * @version 1.0.0
 */

(function($) {
    'use strict';

    // Ensure ImportersAdmin is available
    if (typeof ImportersAdmin === 'undefined') {
        console.error('ImportersAdmin not defined');
        return;
    }

    const { ajaxUrl, restUrl, restNonce, pageId, operations, i18n } = ImportersAdmin;

    /**
     * Main Importers Controller
     */
    const Importers = {
        /**
         * Initialize
         */
        init: function() {
            this.repositionNotices();
            this.bindEvents();
            this.initDropzones();
        },

        /**
         * Move any stray notices into the notices container
         */
        repositionNotices: function() {
            const $wrap = $('.importers-wrap');
            const $noticesContainer = $wrap.find('.importers-notices');

            if (!$noticesContainer.length) return;

            // Move any notices that appear inside the header into our container
            $wrap.find('.importers-header .notice, .importers-header .updated, .importers-header .error').each(function() {
                $(this).appendTo($noticesContainer);
            });

            // Move any notices that appear before or after the header into our container
            $wrap.find('.notice, .updated, .error').not('.importers-notices .notice, .importers-notices .updated, .importers-notices .error').each(function() {
                $(this).appendTo($noticesContainer);
            });

            // Also catch notices WordPress injects at wrap level
            $wrap.siblings('.notice, .updated, .error').each(function() {
                $(this).appendTo($noticesContainer);
            });
        },

        /**
         * Bind event handlers
         */
        bindEvents: function() {
            // Sync buttons
            $(document).on('click', '.importers-sync-button', this.handleSyncClick.bind(this));

            // Import flow
            $(document).on('change', '.importers-file-input', this.handleFileSelect.bind(this));
            $(document).on('click', '.importers-file-remove', this.handleFileRemove.bind(this));
            $(document).on('click', '.importers-next-button', this.handleNextStep.bind(this));
            $(document).on('click', '.importers-back-button', this.handleBackStep.bind(this));

            // Errors panel
            $(document).on('click', '.importers-stat-error.has-errors', this.handleErrorsClick.bind(this));
            $(document).on('click', '.importers-errors-close', this.handleErrorsClose.bind(this));
            $(document).on('click', '.importers-errors-copy', this.handleErrorsCopy.bind(this));
        },

        /**
         * Initialize dropzone interactions
         */
        initDropzones: function() {
            $('.importers-dropzone').each(function() {
                const $dropzone = $(this);

                $dropzone
                    .on('dragover dragenter', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        $dropzone.addClass('is-dragover');
                    })
                    .on('dragleave dragend drop', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        $dropzone.removeClass('is-dragover');
                    });
            });
        },

        /**
         * Handle click on errors stat to show errors panel
         */
        handleErrorsClick: function(e) {
            const $stat = $(e.currentTarget);
            const $card = $stat.closest('.importers-card');
            const $panel = $card.find('.importers-errors-panel');

            if ($panel.length) {
                $panel.slideToggle(200);
            }
        },

        /**
         * Handle close button on errors panel
         */
        handleErrorsClose: function(e) {
            e.preventDefault();
            const $panel = $(e.currentTarget).closest('.importers-errors-panel');
            $panel.slideUp(200);
        },

        /**
         * Handle copy errors button
         */
        handleErrorsCopy: function(e) {
            e.preventDefault();
            const $button = $(e.currentTarget);
            const $panel = $button.closest('.importers-errors-panel');
            const $table = $panel.find('.importers-errors-table');
            
            // Build text from table rows
            let text = 'Item\tError\n';
            text += '----\t-----\n';
            
            $table.find('tbody tr').each(function() {
                const $cells = $(this).find('td');
                const item = $cells.eq(0).text().trim();
                const error = $cells.eq(1).text().trim();
                text += `${item}\t${error}\n`;
            });

            // Copy to clipboard
            navigator.clipboard.writeText(text).then(() => {
                // Show feedback
                const $text = $button.find('.button-text');
                const originalText = $text.text();
                $button.addClass('copied');
                $text.text(i18n.logCopied || 'Copied!');
                
                setTimeout(() => {
                    $button.removeClass('copied');
                    $text.text(originalText);
                }, 2000);
            }).catch(err => {
                console.error('Failed to copy:', err);
            });
        },

        /**
         * Handle sync button click
         */
        handleSyncClick: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const $card = $button.closest('.importers-card');
            const operationId = $button.data('operation-id');

            if ($button.hasClass('is-syncing')) {
                return;
            }

            this.startSync($card, operationId);
        },

        /**
         * Start sync operation
         */
        startSync: function($card, operationId) {
            const $button = $card.find('.importers-sync-button');
            const $progress = $card.find('.importers-progress-wrap');
            const $log = $card.find('.importers-log');
            const $statusBadge = $card.find('.importers-status-badge');

            // Update UI - show running state
            $button.addClass('is-syncing').prop('disabled', true);
            $button.find('.button-text').text(i18n.syncing);
            $progress.show();
            $log.show();
            
            // Update status badge
            $statusBadge
                .removeClass('importers-status-idle importers-status-success importers-status-error')
                .addClass('importers-status-running')
                .text(i18n.statusRunning || 'Running');

            // Clear previous log entries
            $card.find('.importers-log-entries').html('<div class="importers-log-placeholder">Starting sync...</div>');

            const startTime = Date.now();
            let cursor = '';

            // Log start
            this.logActivity($card, 'Starting sync operation...', 'info');

            // Start sync
            this.apiRequest('sync/start', {
                page_id: pageId,
                operation_id: operationId
            }).then(response => {
                this.logActivity($card, 'Connected successfully, fetching data...', 'success');
                // Process batches
                this.processSyncBatch($card, operationId, cursor, startTime, 1);
            }).catch(error => {
                this.logActivity($card, 'Failed to start: ' + (error.message || i18n.errorOccurred), 'error');
                this.resetSyncUI($card, 'error');
            });
        },

        /**
         * Process a sync batch
         */
        processSyncBatch: function($card, operationId, cursor, startTime, batchNum) {
            const operation = operations[operationId] || {};
            const singular = operation.singular || 'item';
            const plural = operation.plural || 'items';

            this.apiRequest('sync/batch', {
                page_id: pageId,
                operation_id: operationId,
                cursor: cursor
            }).then(response => {
                // Update progress
                this.updateProgress($card, response.percentage, response.total_processed, response.total_items);
                this.updateSyncLiveStats($card, response);

                // Log batch progress with all counts
                const created = response.created || 0;
                const updated = response.updated || 0;
                const skipped = response.skipped || 0;
                const failed = response.failed || 0;
                
                let batchMsg = `Batch ${batchNum}: ${response.processed} ${response.processed === 1 ? singular : plural}`;
                let details = [];
                if (created > 0) details.push(`${created} created`);
                if (updated > 0) details.push(`${updated} updated`);
                if (skipped > 0) details.push(`${skipped} skipped`);
                if (failed > 0) details.push(`${failed} failed`);
                if (details.length > 0) {
                    batchMsg += ` (${details.join(', ')})`;
                }
                
                this.logActivity($card, batchMsg, failed > 0 ? 'warning' : 'info');

                // Log any errors from this batch
                if (response.errors && response.errors.length > 0) {
                    response.errors.forEach(err => {
                        this.logActivity($card, `Error [${err.item}]: ${err.message}`, 'error');
                    });
                }

                if (response.has_more) {
                    // Continue with next batch
                    this.processSyncBatch($card, operationId, response.cursor, startTime, batchNum + 1);
                } else {
                    // Complete
                    const duration = Math.round((Date.now() - startTime) / 1000);
                    const totalFailed = response.stats?.failed || 0;
                    
                    this.logActivity($card, `Sync complete! ${response.total_processed} ${plural} processed in ${this.formatDuration(duration)}`, 'success');
                    
                    if (totalFailed > 0) {
                        this.logActivity($card, `${totalFailed} ${totalFailed === 1 ? singular : plural} had errors`, 'warning');
                    }
                    
                    this.completeOperation($card, operationId, 'sync', duration, response.stats);
                }
            }).catch(error => {
                this.logActivity($card, 'Batch failed: ' + (error.message || i18n.errorOccurred), 'error');
                this.resetSyncUI($card, 'error');
            });
        },

        /**
         * Handle file selection
         */
        handleFileSelect: function(e) {
            const $input = $(e.target);
            const $card = $input.closest('.importers-card');
            const operationId = $card.data('operation-id');
            const file = e.target.files[0];

            if (!file) {
                return;
            }

            // Validate file type
            if (!file.name.toLowerCase().endsWith('.csv')) {
                alert(i18n.invalidFile);
                $input.val('');
                return;
            }

            // Upload file
            this.uploadFile($card, operationId, file);
        },

        /**
         * Upload file to server
         */
        uploadFile: function($card, operationId, file) {
            const $dropzone = $card.find('.importers-dropzone');
            const $fileInfo = $card.find('.importers-file-info');
            const $nextButton = $card.find('.importers-next-button');

            // Show loading state
            $dropzone.addClass('is-loading');

            // Create FormData
            const formData = new FormData();
            formData.append('import_file', file);

            // Upload via REST API
            fetch(`${restUrl}upload?page_id=${pageId}&operation_id=${operationId}`, {
                method: 'POST',
                headers: {
                    'X-WP-Nonce': restNonce
                },
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                $dropzone.removeClass('is-loading');

                if (data.success) {
                    // Store file data
                    $card.data('file', data.file);

                    // Update UI
                    $dropzone.hide();
                    $fileInfo.show();
                    $fileInfo.find('.importers-file-name').text(data.file.original_name);
                    $fileInfo.find('.importers-file-size').text(`${data.file.size_human} • ${data.file.rows} rows`);

                    // Enable next button
                    $nextButton.prop('disabled', false);
                } else {
                    alert(data.message || i18n.uploadFailed);
                }
            })
            .catch(error => {
                $dropzone.removeClass('is-loading');
                alert(i18n.uploadFailed);
                console.error('Upload error:', error);
            });
        },

        /**
         * Handle file removal
         */
        handleFileRemove: function(e) {
            e.preventDefault();

            const $card = $(e.target).closest('.importers-card');
            const $dropzone = $card.find('.importers-dropzone');
            const $fileInfo = $card.find('.importers-file-info');
            const $fileInput = $card.find('.importers-file-input');
            const $nextButton = $card.find('.importers-next-button');

            // Clear file data
            $card.removeData('file');
            $fileInput.val('');

            // Reset UI
            $fileInfo.hide();
            $dropzone.show();
            $nextButton.prop('disabled', true);
        },

        /**
         * Handle next step in import wizard
         */
        handleNextStep: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const $card = $button.closest('.importers-card');
            const $activeStep = $card.find('.importers-step:visible');
            const currentStep = parseInt($activeStep.data('step'), 10);
            const operationId = $card.data('operation-id');

            switch (currentStep) {
                case 1:
                    // Move to field mapping
                    this.showFieldMapping($card, operationId);
                    break;
                case 2:
                    // Start import
                    this.startImport($card, operationId);
                    break;
                case 4:
                    // Reset to start
                    this.resetImportUI($card);
                    break;
            }
        },

        /**
         * Handle back step in import wizard
         */
        handleBackStep: function(e) {
            e.preventDefault();

            const $button = $(e.currentTarget);
            const $card = $button.closest('.importers-card');
            const $activeStep = $card.find('.importers-step:visible');
            const currentStep = parseInt($activeStep.data('step'), 10);

            if (currentStep > 1) {
                this.goToStep($card, currentStep - 1);
            }
        },

        /**
         * Show field mapping interface
         */
        showFieldMapping: function($card, operationId) {
            const fileData = $card.data('file');
            const operation = operations[operationId];

            if (!fileData || !operation) {
                return;
            }

            // Build mapping UI
            const $mappingGrid = $card.find('.importers-mapping-grid');
            $mappingGrid.empty();

            const fields = operation.fields || {};
            const csvHeaders = fileData.headers || [];

            Object.entries(fields).forEach(([fieldKey, field]) => {
                const isRequired = field.required;
                const label = field.label || fieldKey;

                // Try to auto-match by name
                const autoMatch = csvHeaders.find(h => 
                    h.toLowerCase() === fieldKey.toLowerCase() || 
                    h.toLowerCase() === label.toLowerCase()
                );

                let selectOptions = `<option value="">${i18n.selectColumn}</option>`;
                csvHeaders.forEach(header => {
                    const selected = header === autoMatch ? ' selected' : '';
                    selectOptions += `<option value="${this.escapeHtml(header)}"${selected}>${this.escapeHtml(header)}</option>`;
                });

                const rowHtml = `
                    <div class="importers-mapping-row" data-field="${this.escapeHtml(fieldKey)}">
                        <div class="importers-mapping-field">
                            <span class="importers-mapping-field-label">${this.escapeHtml(label)}</span>
                            ${isRequired ? `<span class="importers-mapping-required">*</span>` : ''}
                        </div>
                        <span class="importers-mapping-arrow"><span class="dashicons dashicons-arrow-left-alt"></span></span>
                        <select class="importers-mapping-select">${selectOptions}</select>
                    </div>
                `;

                $mappingGrid.append(rowHtml);
            });

            // Load preview
            this.loadPreview($card, fileData.uuid);

            // Move to step 2
            this.goToStep($card, 2);
        },

        /**
         * Load CSV preview
         */
        loadPreview: function($card, uuid) {
            this.apiRequest(`preview/${uuid}`, null, 'GET').then(response => {
                if (response.success && response.preview) {
                    const preview = response.preview;
                    const $table = $card.find('.importers-preview-table');
                    const $thead = $table.find('thead');
                    const $tbody = $table.find('tbody');

                    // Build header
                    let headerHtml = '<tr>';
                    preview.headers.forEach(h => {
                        headerHtml += `<th>${this.escapeHtml(h)}</th>`;
                    });
                    headerHtml += '</tr>';
                    $thead.html(headerHtml);

                    // Build rows
                    let bodyHtml = '';
                    preview.rows.forEach(row => {
                        bodyHtml += '<tr>';
                        row.forEach(cell => {
                            bodyHtml += `<td>${this.escapeHtml(cell || '')}</td>`;
                        });
                        bodyHtml += '</tr>';
                    });
                    $tbody.html(bodyHtml);
                }
            });
        },

        /**
         * Start import operation
         */
        startImport: function($card, operationId) {
            const fileData = $card.data('file');
            const fieldMap = this.getFieldMap($card);

            // Validate required fields
            const operation = operations[operationId];
            const fields = operation.fields || {};
            const missingRequired = [];

            Object.entries(fields).forEach(([key, field]) => {
                if (field.required && !fieldMap[key]) {
                    missingRequired.push(field.label || key);
                }
            });

            if (missingRequired.length > 0) {
                alert(`Please map the following required fields: ${missingRequired.join(', ')}`);
                return;
            }

            // Move to progress step
            this.goToStep($card, 3);

            // Clear log and add starting message
            $card.find('.importers-log-entries').html('');
            this.logActivity($card, 'Starting import...', 'info');

            const startTime = Date.now();

            // Initialize import
            this.apiRequest('import/start', {
                page_id: pageId,
                operation_id: operationId,
                file_uuid: fileData.uuid,
                field_map: fieldMap
            }).then(response => {
                $card.data('totalItems', response.total_items);
                this.logActivity($card, `Processing ${response.total_items} rows...`, 'info');
                this.processImportBatch($card, operationId, fileData.uuid, fieldMap, 0, startTime, 1);
            }).catch(error => {
                this.logActivity($card, 'Failed to start: ' + (error.message || i18n.errorOccurred), 'error');
            });
        },

        /**
         * Process an import batch
         */
        processImportBatch: function($card, operationId, uuid, fieldMap, offset, startTime, batchNum) {
            this.apiRequest('import/batch', {
                page_id: pageId,
                operation_id: operationId,
                file_uuid: uuid,
                field_map: fieldMap,
                offset: offset
            }).then(response => {
                // Update progress
                this.updateProgress($card, response.percentage, response.total_processed, response.total_items);
                this.updateLiveStats($card, response);

                // Log activity
                this.logActivity($card, `Batch ${batchNum}: ${response.processed} rows (${response.created} created, ${response.updated} updated, ${response.skipped} skipped)`);

                if (response.errors && response.errors.length > 0) {
                    response.errors.forEach(err => {
                        this.logActivity($card, `Row ${err.row}: ${err.message}`, 'error');
                    });
                }

                if (response.has_more) {
                    // Continue with next batch
                    this.processImportBatch($card, operationId, uuid, fieldMap, response.offset, startTime, batchNum + 1);
                } else {
                    // Complete
                    const duration = Math.round((Date.now() - startTime) / 1000);
                    this.logActivity($card, `Import complete in ${this.formatDuration(duration)}!`, 'success');
                    this.completeOperation($card, operationId, 'import', duration, response.stats, uuid);
                }
            }).catch(error => {
                this.logActivity($card, 'Batch failed: ' + (error.message || i18n.errorOccurred), 'error');
            });
        },

        /**
         * Get field mapping from UI
         */
        getFieldMap: function($card) {
            const fieldMap = {};

            $card.find('.importers-mapping-row').each(function() {
                const $row = $(this);
                const fieldKey = $row.data('field');
                const csvColumn = $row.find('.importers-mapping-select').val();

                if (csvColumn) {
                    fieldMap[fieldKey] = csvColumn;
                }
            });

            return fieldMap;
        },

        /**
         * Complete operation
         */
        completeOperation: function($card, operationId, type, duration, stats, fileUuid = null) {
            // Notify server
            this.apiRequest('complete', {
                page_id: pageId,
                operation_id: operationId,
                status: 'complete',
                duration: duration,
                file_uuid: fileUuid
            });

            if (type === 'sync') {
                const hasErrors = (stats?.failed || 0) > 0;
                this.resetSyncUI($card, hasErrors ? 'error' : 'success');
                this.updateSyncCardStats($card, stats);
            } else {
                // Show completion step
                this.showImportComplete($card, stats, duration);
            }
        },

        /**
         * Show import completion summary
         */
        showImportComplete: function($card, stats, duration) {
            const $completeStats = $card.find('.importers-complete-stats');
            const $completeErrors = $card.find('.importers-complete-errors');

            // Build stats
            $completeStats.html(`
                <div class="importers-stat importers-stat-success">
                    <span class="importers-stat-value">${stats.created}</span>
                    <span class="importers-stat-label">${i18n.created}</span>
                </div>
                <div class="importers-stat">
                    <span class="importers-stat-value">${stats.updated}</span>
                    <span class="importers-stat-label">${i18n.updated}</span>
                </div>
                <div class="importers-stat">
                    <span class="importers-stat-value">${stats.skipped}</span>
                    <span class="importers-stat-label">${i18n.skipped}</span>
                </div>
                <div class="importers-stat importers-stat-error">
                    <span class="importers-stat-value">${stats.failed}</span>
                    <span class="importers-stat-label">${i18n.failed}</span>
                </div>
            `);

            // Show errors if any
            if (stats.errors && stats.errors.length > 0) {
                const $tbody = $completeErrors.find('tbody');
                $tbody.empty();

                stats.errors.forEach(err => {
                    $tbody.append(`
                        <tr>
                            <td>${err.row || '-'}</td>
                            <td>${this.escapeHtml(err.item || '')}</td>
                            <td>${this.escapeHtml(err.message)}</td>
                        </tr>
                    `);
                });

                $completeErrors.show();
            } else {
                $completeErrors.hide();
            }

            // Update button
            const $nextButton = $card.find('.importers-next-button');
            $nextButton.find('.button-text').text(i18n.runAnother);
            $nextButton.find('.dashicons').removeClass('dashicons-arrow-right-alt').addClass('dashicons-update');

            this.goToStep($card, 4);
        },

        /**
         * Go to specific step
         */
        goToStep: function($card, step) {
            const $steps = $card.find('.importers-step');
            const $dots = $card.find('.importers-step-dot');
            const $backButton = $card.find('.importers-back-button');
            const $nextButton = $card.find('.importers-next-button');

            // Hide all steps, show target
            $steps.hide();
            $card.find(`.importers-step[data-step="${step}"]`).show();

            // Update dots
            $dots.removeClass('active completed');
            $dots.each(function() {
                const dotStep = parseInt($(this).data('step'), 10);
                if (dotStep < step) {
                    $(this).addClass('completed');
                } else if (dotStep === step) {
                    $(this).addClass('active');
                }
            });

            // Update buttons
            $backButton.toggle(step > 1 && step < 4);

            switch (step) {
                case 1:
                    $nextButton.prop('disabled', !$card.data('file'));
                    $nextButton.find('.button-text').text(i18n.continueToMap);
                    break;
                case 2:
                    $nextButton.prop('disabled', false);
                    $nextButton.find('.button-text').text(i18n.startImport);
                    break;
                case 3:
                    $nextButton.prop('disabled', true);
                    $nextButton.find('.button-text').text(i18n.importing);
                    break;
                case 4:
                    $nextButton.prop('disabled', false);
                    break;
            }
        },

        /**
         * Update progress bar
         */
        updateProgress: function($card, percentage, processed, total) {
            const $fill = $card.find('.importers-progress-fill');
            const $status = $card.find('.importers-progress-status');
            const $percent = $card.find('.importers-progress-percent');

            $fill.css('width', `${percentage}%`);
            $status.text(`${processed} / ${total}`);
            $percent.text(`${percentage}%`);
        },

        /**
         * Update live stats display (for imports)
         */
        updateLiveStats: function($card, data) {
            $card.find('.importers-stat-created').text(data.stats?.created || 0);
            $card.find('.importers-stat-updated').text(data.stats?.updated || 0);
            $card.find('.importers-stat-skipped').text(data.stats?.skipped || 0);
            $card.find('.importers-stat-failed').text(data.stats?.failed || 0);
        },

        /**
         * Update sync live stats (for sync cards - in the card stats section)
         */
        updateSyncLiveStats: function($card, data) {
            const $stats = $card.find('.importers-card-stats .importers-stat-value');
            if ($stats.length >= 3) {
                $stats.eq(0).text(data.total_items || 0);
                $stats.eq(1).text((data.stats?.created || 0) + (data.stats?.updated || 0));
                $stats.eq(2).text(data.stats?.failed || 0);
            }
        },

        /**
         * Update sync card stats after completion
         */
        updateSyncCardStats: function($card, stats) {
            const $stats = $card.find('.importers-card-stats .importers-stat-value');
            if ($stats.length >= 3) {
                $stats.eq(0).text(stats.total || (stats.created + stats.updated + stats.skipped + stats.failed) || 0);
                $stats.eq(1).text((stats.created || 0) + (stats.updated || 0));
                $stats.eq(2).text(stats.failed || 0);
            }
            $card.find('.importers-last-run').text(`${i18n.lastSync}: ${i18n.justNow}`);
        },

        /**
         * Log activity message
         */
        logActivity: function($card, message, type = 'info') {
            const $log = $card.find('.importers-log-entries');
            
            // If no log element exists, just console log
            if ($log.length === 0) {
                console.log(`[Importers ${type}]`, message);
                return;
            }

            // Remove placeholder if present
            $log.find('.importers-log-placeholder').remove();

            const time = new Date().toLocaleTimeString();
            const entryClass = type === 'error' ? 'is-error' : (type === 'success' ? 'is-success' : (type === 'warning' ? 'is-warning' : ''));

            $log.append(`
                <div class="importers-log-entry ${entryClass}">
                    <span class="importers-log-time">[${time}]</span>
                    <span class="importers-log-message">${this.escapeHtml(message)}</span>
                </div>
            `);

            // Scroll to bottom
            if ($log[0]) {
                $log.scrollTop($log[0].scrollHeight);
            }
        },

        /**
         * Reset sync UI to initial state
         * @param {jQuery} $card - The card element
         * @param {string} finalStatus - 'idle', 'success', or 'error'
         */
        resetSyncUI: function($card, finalStatus = 'idle') {
            const $button = $card.find('.importers-sync-button');
            const $progress = $card.find('.importers-progress-wrap');
            const $statusBadge = $card.find('.importers-status-badge');

            // Reset button
            $button.removeClass('is-syncing').prop('disabled', false);
            $button.find('.button-text').text(i18n.syncNow);
            
            // Hide progress bar
            $progress.hide();

            // Reset progress bar values
            $card.find('.importers-progress-fill').css('width', '0');
            $card.find('.importers-progress-status').text('');
            $card.find('.importers-progress-percent').text('0%');

            // Update status badge based on result
            $statusBadge.removeClass('importers-status-idle importers-status-running importers-status-success importers-status-error');
            
            switch (finalStatus) {
                case 'success':
                    $statusBadge.addClass('importers-status-success').text(i18n.statusComplete || 'Complete');
                    break;
                case 'error':
                    $statusBadge.addClass('importers-status-error').text(i18n.statusError || 'Error');
                    break;
                default:
                    $statusBadge.addClass('importers-status-idle').text(i18n.statusIdle || 'Ready');
            }
        },

        /**
         * Reset import UI to initial state
         */
        resetImportUI: function($card) {
            // Clear file data
            $card.removeData('file');
            $card.removeData('totalItems');
            $card.find('.importers-file-input').val('');

            // Reset dropzone
            $card.find('.importers-dropzone').show();
            $card.find('.importers-file-info').hide();

            // Clear mapping
            $card.find('.importers-mapping-grid').empty();
            $card.find('.importers-preview-table thead, .importers-preview-table tbody').empty();

            // Clear log
            $card.find('.importers-log-entries').empty();

            // Reset progress
            $card.find('.importers-progress-fill').css('width', '0');

            // Reset button
            const $nextButton = $card.find('.importers-next-button');
            $nextButton.find('.button-text').text(i18n.continueToMap);
            $nextButton.find('.dashicons').removeClass('dashicons-update').addClass('dashicons-arrow-right-alt');
            $nextButton.prop('disabled', true);

            // Go to step 1
            this.goToStep($card, 1);
        },

        /**
         * Show error message
         */
        showError: function($card, message) {
            console.error('[Importers Error]', message);
            this.logActivity($card, message, 'error');
        },

        /**
         * Make API request
         */
        apiRequest: function(endpoint, data = null, method = 'POST') {
            const options = {
                method: method,
                headers: {
                    'Content-Type': 'application/json',
                    'X-WP-Nonce': restNonce
                }
            };

            if (data && method !== 'GET') {
                options.body = JSON.stringify(data);
            }

            let url = restUrl + endpoint;
            if (data && method === 'GET') {
                const params = new URLSearchParams(data);
                url += '?' + params.toString();
            }

            return fetch(url, options)
                .then(response => response.json())
                .then(data => {
                    if (data.code && data.message) {
                        throw new Error(data.message);
                    }
                    return data;
                });
        },

        /**
         * Format duration for display
         */
        formatDuration: function(seconds) {
            if (seconds < 60) {
                return `${seconds}s`;
            }
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins}m ${secs}s`;
        },

        /**
         * Escape HTML entities
         */
        escapeHtml: function(str) {
            if (!str) return '';
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }
    };

    // Initialize when DOM is ready
    $(document).ready(function() {
        Importers.init();
    });

})(jQuery);
