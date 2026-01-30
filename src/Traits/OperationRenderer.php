<?php
/**
 * Operation Renderer Trait
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Traits;

use ArrayPress\RegisterImporters\StatsManager;

/**
 * Trait OperationRenderer
 *
 * Handles rendering of sync and import operation cards.
 */
trait OperationRenderer {

	/**
	 * Render all operations for a tab.
	 *
	 * @param string $tab Tab key.
	 *
	 * @return void
	 */
	protected function render_operations( string $tab ): void {
		$operations = $this->get_operations_for_tab( $tab );

		if ( empty( $operations ) ) {
			$this->render_empty_state( $tab );

			return;
		}

		// Separate syncs and imports
		$syncs   = [];
		$imports = [];

		foreach ( $operations as $id => $operation ) {
			if ( $operation['type'] === 'sync' ) {
				$syncs[ $id ] = $operation;
			} else {
				$imports[ $id ] = $operation;
			}
		}

		// Render syncs grid
		if ( ! empty( $syncs ) ) {
			$this->render_syncs_grid( $syncs );
		}

		// Render imports grid
		if ( ! empty( $imports ) ) {
			$this->render_imports_grid( $imports );
		}
	}

	/**
	 * Render the syncs grid.
	 *
	 * @param array $syncs Array of sync operations.
	 *
	 * @return void
	 */
	protected function render_syncs_grid( array $syncs ): void {
		?>
		<div class="importers-operations-grid importers-syncs-grid">
			<?php foreach ( $syncs as $id => $operation ) : ?>
				<?php $this->render_sync_card( $id, $operation ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a single sync card.
	 *
	 * @param string $id        Operation ID.
	 * @param array  $operation Operation configuration.
	 *
	 * @return void
	 */
	protected function render_sync_card( string $id, array $operation ): void {
		$stats = StatsManager::get_stats( $this->id, $id );

		// Normalize icon
		$icon = $operation['icon'] ?? 'dashicons-update';
		if ( ! str_starts_with( $icon, 'dashicons-' ) ) {
			$icon = 'dashicons-' . $icon;
		}

		$status_class = $this->get_status_class( $stats['last_status'] );
		$status_label = $this->get_status_label( $stats['last_status'] );
		?>
		<div class="importers-card importers-sync-card"
		     data-operation-id="<?php echo esc_attr( $id ); ?>"
		     data-operation-type="sync">

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
				<div class="importers-card-status">
					<span class="importers-status-badge <?php echo esc_attr( $status_class ); ?>">
						<?php echo esc_html( $status_label ); ?>
					</span>
				</div>
			</div>

			<div class="importers-card-stats">
				<div class="importers-stat">
					<span class="importers-stat-value"><?php echo esc_html( number_format_i18n( $stats['total'] ) ); ?></span>
					<span class="importers-stat-label"><?php esc_html_e( 'Total', 'arraypress' ); ?></span>
				</div>
				<div class="importers-stat importers-stat-success">
					<span class="importers-stat-value"><?php echo esc_html( number_format_i18n( $stats['created'] + $stats['updated'] ) ); ?></span>
					<span class="importers-stat-label"><?php esc_html_e( 'Synced', 'arraypress' ); ?></span>
				</div>
				<div class="importers-stat importers-stat-error<?php echo $stats['failed'] > 0 ? ' has-errors' : ''; ?>">
					<span class="importers-stat-value"><?php echo esc_html( number_format_i18n( $stats['failed'] ) ); ?></span>
					<span class="importers-stat-label"><?php esc_html_e( 'Errors', 'arraypress' ); ?></span>
				</div>
			</div>

			<?php if ( ! empty( $stats['errors'] ) ) : ?>
			<!-- Stored errors panel (hidden by default) -->
			<div class="importers-errors-panel" style="display: none;">
				<div class="importers-errors-panel-header">
					<h4><?php esc_html_e( 'Recent Errors', 'arraypress' ); ?></h4>
					<div class="importers-errors-actions">
						<button type="button" class="button button-small importers-errors-copy" title="<?php esc_attr_e( 'Copy all errors', 'arraypress' ); ?>">
							<span class="dashicons dashicons-clipboard"></span>
							<span class="button-text"><?php esc_html_e( 'Copy', 'arraypress' ); ?></span>
						</button>
						<button type="button" class="importers-errors-close" title="<?php esc_attr_e( 'Close', 'arraypress' ); ?>">
							<span class="dashicons dashicons-no-alt"></span>
						</button>
					</div>
				</div>
				<div class="importers-errors-table-wrap">
					<table class="importers-errors-table">
						<thead>
						<tr>
							<th><?php esc_html_e( 'Item', 'arraypress' ); ?></th>
							<th><?php esc_html_e( 'Error', 'arraypress' ); ?></th>
						</tr>
						</thead>
						<tbody>
						<?php foreach ( array_slice( $stats['errors'], 0, 20 ) as $error ) : ?>
							<tr>
								<td><?php echo esc_html( $error['item'] ?? '-' ); ?></td>
								<td><?php echo esc_html( $error['message'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</div>
			<?php endif; ?>

			<!-- Progress bar (hidden by default) -->
			<div class="importers-progress-wrap" style="display: none;">
				<div class="importers-progress-bar">
					<div class="importers-progress-fill"></div>
				</div>
				<div class="importers-progress-text">
					<span class="importers-progress-status"></span>
					<span class="importers-progress-percent">0%</span>
				</div>
			</div>

			<!-- Activity log (hidden by default, shown during sync) -->
			<div class="importers-log" style="display: none;">
				<div class="importers-log-header">
					<h4><?php esc_html_e( 'Activity Log', 'arraypress' ); ?></h4>
					<button type="button" class="importers-log-close" title="<?php esc_attr_e( 'Close log', 'arraypress' ); ?>">
						<span class="dashicons dashicons-no-alt"></span>
					</button>
				</div>
				<div class="importers-log-entries">
					<div class="importers-log-placeholder"><?php esc_html_e( 'Waiting to start...', 'arraypress' ); ?></div>
				</div>
			</div>

			<div class="importers-card-footer">
				<div class="importers-card-meta">
					<span class="importers-last-run">
						<?php
						printf(
							esc_html__( 'Last sync: %s', 'arraypress' ),
							esc_html( StatsManager::get_relative_time( $stats['last_run'] ) )
						);
						?>
					</span>
				</div>
				<div class="importers-card-actions">
					<button type="button"
					        class="button button-primary importers-sync-button"
					        data-operation-id="<?php echo esc_attr( $id ); ?>">
						<span class="dashicons dashicons-update"></span>
						<span class="button-text"><?php esc_html_e( 'Sync Now', 'arraypress' ); ?></span>
					</button>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the imports grid.
	 *
	 * @param array $imports Array of import operations.
	 *
	 * @return void
	 */
	protected function render_imports_grid( array $imports ): void {
		?>
		<div class="importers-operations-grid importers-imports-grid">
			<?php foreach ( $imports as $id => $operation ) : ?>
				<?php $this->render_import_card( $id, $operation ); ?>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render a single import card.
	 *
	 * @param string $id        Operation ID.
	 * @param array  $operation Operation configuration.
	 *
	 * @return void
	 */
	protected function render_import_card( string $id, array $operation ): void {
		$stats = StatsManager::get_stats( $this->id, $id );

		// Normalize icon
		$icon = $operation['icon'] ?? 'dashicons-upload';
		if ( ! str_starts_with( $icon, 'dashicons-' ) ) {
			$icon = 'dashicons-' . $icon;
		}

		$nonce = wp_create_nonce( 'importer_upload_' . $id );
		?>
		<div class="importers-card importers-import-card"
		     data-operation-id="<?php echo esc_attr( $id ); ?>"
		     data-operation-type="import"
		     data-nonce="<?php echo esc_attr( $nonce ); ?>">

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
			</div>

			<div class="importers-card-body">
				<!-- Step 1: File Upload -->
				<div class="importers-step importers-step-upload importers-step-active" data-step="1">
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
							<span class="importers-dropzone-hint">
								<?php esc_html_e( 'Maximum file size: 50MB', 'arraypress' ); ?>
							</span>
						</label>
					</div>

					<div class="importers-file-info" style="display: none;">
						<div class="importers-file-details">
							<span class="dashicons dashicons-media-spreadsheet"></span>
							<div class="importers-file-meta">
								<span class="importers-file-name"></span>
								<span class="importers-file-size"></span>
							</div>
							<button type="button" class="importers-file-remove" title="<?php esc_attr_e( 'Remove file', 'arraypress' ); ?>">
								<span class="dashicons dashicons-no-alt"></span>
							</button>
						</div>
					</div>
				</div>

				<!-- Step 2: Field Mapping -->
				<div class="importers-step importers-step-mapping" data-step="2" style="display: none;">
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

				<!-- Step 3: Progress -->
				<div class="importers-step importers-step-progress" data-step="3" style="display: none;">
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
				</div>

				<!-- Step 4: Complete -->
				<div class="importers-step importers-step-complete" data-step="4" style="display: none;">
					<div class="importers-complete-summary">
						<div class="importers-complete-icon">
							<span class="dashicons dashicons-yes-alt"></span>
						</div>
						<h3 class="importers-complete-title"><?php esc_html_e( 'Import Complete!', 'arraypress' ); ?></h3>
						<div class="importers-complete-stats">
							<!-- Populated by JavaScript -->
						</div>
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
						<span class="importers-step-dot" data-step="4"></span>
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
	 * Render empty state for a tab.
	 *
	 * @param string $tab Tab key.
	 *
	 * @return void
	 */
	protected function render_empty_state( string $tab ): void {
		$icon    = 'dashicons-database-import';
		$title   = __( 'No Operations Configured', 'arraypress' );
		$message = __( 'Add sync or import operations to this tab.', 'arraypress' );

		if ( $tab === 'syncs' ) {
			$icon    = 'dashicons-update';
			$title   = __( 'No Syncs Configured', 'arraypress' );
			$message = __( 'Register sync operations to pull data from external sources.', 'arraypress' );
		} elseif ( $tab === 'importers' ) {
			$icon    = 'dashicons-upload';
			$title   = __( 'No Importers Configured', 'arraypress' );
			$message = __( 'Register import operations to upload CSV data.', 'arraypress' );
		}

		?>
		<div class="importers-empty-state">
			<span class="dashicons <?php echo esc_attr( $icon ); ?>"></span>
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php echo esc_html( $message ); ?></p>
		</div>
		<?php
	}

	/**
	 * Get CSS class for status badge.
	 *
	 * @param string|null $status Status string.
	 *
	 * @return string
	 */
	protected function get_status_class( ?string $status ): string {
		switch ( $status ) {
			case 'running':
				return 'importers-status-running';
			case 'complete':
				return 'importers-status-success';
			case 'error':
			case 'cancelled':
				return 'importers-status-error';
			default:
				return 'importers-status-idle';
		}
	}

	/**
	 * Get display label for status.
	 *
	 * @param string|null $status Status string.
	 *
	 * @return string
	 */
	protected function get_status_label( ?string $status ): string {
		switch ( $status ) {
			case 'running':
				return __( 'Running', 'arraypress' );
			case 'complete':
				return __( 'Complete', 'arraypress' );
			case 'error':
				return __( 'Error', 'arraypress' );
			case 'cancelled':
				return __( 'Cancelled', 'arraypress' );
			default:
				return __( 'Ready', 'arraypress' );
		}
	}

}
