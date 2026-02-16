<?php
/**
 * Operation Renderer Trait
 *
 * Handles rendering of CSV import operation cards with a
 * 3-step wizard: Upload → Map Fields → Import.
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Traits;

use ArrayPress\RegisterImporters\StatsManager;

/**
 * Trait OperationRenderer
 *
 * Handles rendering of import operation cards.
 */
trait OperationRenderer {

    /**
     * Render all operations for a tab.
     *
     * @param string $tab Tab key.
     *
     * @return void
     * @since 2.0.0
     *
     */
    protected function render_operations( string $tab ): void {
        $operations = $this->get_operations_for_tab( $tab );

        if ( empty( $operations ) ) {
            $this->render_empty_state();

            return;
        }

        echo '<div class="importers-operations-list">';

        foreach ( $operations as $id => $operation ) {
            $this->render_import_card( $id, $operation );
        }

        echo '</div>';
    }

    /**
     * Render a single import card.
     *
     * Uses a 3-step wizard: Upload → Map Fields → Import.
     * Cards are full-width and stack vertically.
     *
     * @param string $id        Operation ID.
     * @param array  $operation Operation configuration.
     *
     * @return void
     * @since 2.0.0
     *
     */
    protected function render_import_card( string $id, array $operation ): void {
        $stats = StatsManager::get_stats( $this->id, $id );

        // Normalize icon
        $icon = $operation['icon'] ?? 'dashicons-upload';
        if ( ! str_starts_with( $icon, 'dashicons-' ) ) {
            $icon = 'dashicons-' . $icon;
        }

        ?>
        <div class="importers-card"
             data-operation-id="<?php echo esc_attr( $id ); ?>"
             data-operation-type="import">

            <div class="importers-card-header">
                <div class="importers-card-icon">
                    <span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
                </div>
                <div class="importers-card-title-wrap">
                    <h3 class="importers-card-title"><?php echo esc_html( $operation['title'] ); ?></h3>
                    <?php if ( ! empty( $operation['description'] ) ) : ?>
                        <p class="importers-card-description"><?php echo esc_html( $operation['description'] ); ?></p>
                    <?php endif; ?>
                </div>
                <div class="importers-card-header-actions">
                    <a href="#"
                       class="importers-download-sample"
                       data-operation-id="<?php echo esc_attr( $id ); ?>"
                       title="<?php esc_attr_e( 'Download a sample CSV with the correct headers', 'arraypress' ); ?>">
                        <span class="dashicons dashicons-download"></span>
                        <?php esc_html_e( 'Sample CSV', 'arraypress' ); ?>
                    </a>
                </div>
            </div>

            <div class="importers-card-body">
                <!-- Step 1: File Upload -->
                <div class="importers-step" data-step="1">
                    <div class="importers-dropzone">
                        <input type="file"
                               class="importers-file-input"
                               accept=".csv"
                               id="import-file-<?php echo esc_attr( $id ); ?>">
                        <label for="import-file-<?php echo esc_attr( $id ); ?>" class="importers-dropzone-label">
                            <span class="dashicons dashicons-upload"></span>
                            <span class="importers-dropzone-text">
								<?php esc_html_e( 'Drop CSV file here or click to browse', 'arraypress' ); ?>
							</span>
                            <?php if ( ! empty( $operation['max_file_size'] ) ) : ?>
                                <span class="importers-dropzone-hint">
									<?php
                                    printf(
                                            esc_html__( 'Maximum file size: %s', 'arraypress' ),
                                            esc_html( size_format( $operation['max_file_size'] ) )
                                    );
                                    ?>
								</span>
                            <?php endif; ?>
                        </label>
                    </div>

                    <div class="importers-file-info" style="display: none;">
                        <div class="importers-file-details">
                            <span class="dashicons dashicons-media-spreadsheet"></span>
                            <div class="importers-file-meta">
                                <span class="importers-file-name"></span>
                                <span class="importers-file-size"></span>
                            </div>
                            <button type="button" class="importers-file-remove"
                                    title="<?php esc_attr_e( 'Remove file', 'arraypress' ); ?>">
                                <span class="dashicons dashicons-no-alt"></span>
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Step 2: Field Mapping -->
                <div class="importers-step" data-step="2" style="display: none;">
                    <div class="importers-mapping-grid">
                        <!-- Populated by JavaScript -->
                    </div>
                    <div class="importers-preview-section">
                        <h4><?php esc_html_e( 'Preview', 'arraypress' ); ?></h4>
                        <div class="importers-preview-table-wrap">
                            <table class="importers-preview-table">
                                <thead></thead>
                                <tbody></tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Step 3: Progress & Results -->
                <div class="importers-step" data-step="3" style="display: none;">
                    <div class="importers-progress-wrap">
                        <div class="importers-progress-bar">
                            <div class="importers-progress-fill"></div>
                        </div>
                        <div class="importers-progress-text">
                            <span class="importers-progress-status"><?php esc_html_e( 'Starting...', 'arraypress' ); ?></span>
                            <span class="importers-progress-percent">0%</span>
                        </div>
                    </div>

                    <div class="importers-live-stats">
                        <div class="importers-stat">
                            <span class="importers-stat-value importers-stat-created">0</span>
                            <span class="importers-stat-label"><?php esc_html_e( 'Created', 'arraypress' ); ?></span>
                        </div>
                        <div class="importers-stat">
                            <span class="importers-stat-value importers-stat-updated">0</span>
                            <span class="importers-stat-label"><?php esc_html_e( 'Updated', 'arraypress' ); ?></span>
                        </div>
                        <div class="importers-stat">
                            <span class="importers-stat-value importers-stat-skipped">0</span>
                            <span class="importers-stat-label"><?php esc_html_e( 'Skipped', 'arraypress' ); ?></span>
                        </div>
                        <div class="importers-stat importers-stat-error">
                            <span class="importers-stat-value importers-stat-failed">0</span>
                            <span class="importers-stat-label"><?php esc_html_e( 'Failed', 'arraypress' ); ?></span>
                        </div>
                    </div>

                    <div class="importers-log">
                        <h4><?php esc_html_e( 'Activity Log', 'arraypress' ); ?></h4>
                        <div class="importers-log-entries"></div>
                    </div>

                    <!-- Results summary (shown when complete) -->
                    <div class="importers-complete-summary" style="display: none;">
                        <div class="importers-complete-icon">
                            <span class="dashicons dashicons-yes-alt"></span>
                        </div>
                        <h3 class="importers-complete-title"><?php esc_html_e( 'Import Complete!', 'arraypress' ); ?></h3>
                        <div class="importers-complete-errors" style="display: none;">
                            <h4><?php esc_html_e( 'Errors', 'arraypress' ); ?></h4>
                            <div class="importers-errors-table-wrap">
                                <table class="importers-errors-table">
                                    <thead>
                                    <tr>
                                        <th><?php esc_html_e( 'Row', 'arraypress' ); ?></th>
                                        <th><?php esc_html_e( 'Item', 'arraypress' ); ?></th>
                                        <th><?php esc_html_e( 'Error', 'arraypress' ); ?></th>
                                    </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="importers-card-footer">
                <div class="importers-footer-left">
                    <div class="importers-step-indicator">
                        <span class="importers-step-dot active" data-step="1"></span>
                        <span class="importers-step-dot" data-step="2"></span>
                        <span class="importers-step-dot" data-step="3"></span>
                    </div>
                    <?php if ( $stats['last_run'] ) : ?>
                        <span class="importers-card-meta importers-last-import">
							<?php
                            printf(
                                    esc_html__( 'Last import: %s', 'arraypress' ),
                                    esc_html( StatsManager::get_relative_time( $stats['last_run'] ) )
                            );
                            if ( $stats['source_file'] ) {
                                echo ' <span class="importers-history-file">(' . esc_html( $stats['source_file'] ) . ')</span>';
                            }
                            ?>
						</span>
                    <?php endif; ?>
                </div>
                <div class="importers-card-actions">
                    <button type="button" class="button importers-back-button" style="display: none;">
                        <?php esc_html_e( 'Back', 'arraypress' ); ?>
                    </button>
                    <button type="button" class="button importers-cancel-button" style="display: none;">
                        <?php esc_html_e( 'Cancel', 'arraypress' ); ?>
                    </button>
                    <button type="button" class="button importers-dry-run-button" style="display: none;">
                        <span class="dashicons dashicons-visibility"></span>
                        <span class="button-text"><?php esc_html_e( 'Preview', 'arraypress' ); ?></span>
                    </button>
                    <button type="button" class="button button-primary importers-next-button" disabled>
                        <span class="button-text"><?php esc_html_e( 'Continue', 'arraypress' ); ?></span>
                        <span class="dashicons dashicons-arrow-right-alt"></span>
                    </button>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render empty state when no operations are configured.
     *
     * @return void
     * @since 2.0.0
     *
     */
    protected function render_empty_state(): void {
        ?>
        <div class="importers-empty-state">
            <span class="dashicons dashicons-upload"></span>
            <h3><?php esc_html_e( 'No Importers Configured', 'arraypress' ); ?></h3>
            <p><?php esc_html_e( 'Register import operations to upload and process CSV data.', 'arraypress' ); ?></p>
        </div>
        <?php
    }

}
