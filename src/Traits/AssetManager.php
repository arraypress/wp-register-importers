<?php
/**
 * Asset Manager Trait
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Traits;

/**
 * Trait AssetManager
 *
 * Handles enqueueing of scripts and styles using wp-composer-assets library.
 */
trait AssetManager {

	/**
	 * Maybe enqueue assets on the importers page.
	 *
	 * @param string $hook_suffix The current admin page hook suffix.
	 *
	 * @return void
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->hook_suffix ) {
			return;
		}

		$this->enqueue_assets();
	}

	/**
	 * Enqueue all required assets.
	 *
	 * @return void
	 */
	protected function enqueue_assets(): void {
		$this->enqueue_core_assets();
		$this->localize_scripts();
	}

	/**
	 * Enqueue core CSS and JS using wp-composer-assets library.
	 *
	 * @return void
	 */
	protected function enqueue_core_assets(): void {
		wp_enqueue_composer_style(
			'arraypress-importers',
			__FILE__,
			'css/importers.css'
		);

		wp_enqueue_composer_script(
			'arraypress-importers',
			__FILE__,
			'js/importers.js',
			[ 'jquery' ]
		);
	}

	/**
	 * Localize scripts with necessary data.
	 *
	 * @return void
	 */
	protected function localize_scripts(): void {
		$operations_config = [];

		foreach ( $this->get_all_operations() as $id => $operation ) {
			$operations_config[ $id ] = [
				'type'      => $operation['type'],
				'title'     => $operation['title'],
				'singular'  => $operation['singular'],
				'plural'    => $operation['plural'],
				'batchSize' => $operation['batch_size'],
				'fields'    => $operation['fields'] ?? [],
			];
		}

		wp_localize_script( 'arraypress-importers', 'ImportersAdmin', [
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'restUrl'    => rest_url( 'importers/v1/' ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'pageId'     => $this->id,
			'operations' => $operations_config,
			'i18n'       => $this->get_i18n_strings(),
		] );
	}

	/**
	 * Get localized strings for JavaScript.
	 *
	 * @return array
	 */
	protected function get_i18n_strings(): array {
		return [
			// General
			'loading'          => __( 'Loading...', 'arraypress' ),
			'error'            => __( 'Error', 'arraypress' ),
			'success'          => __( 'Success', 'arraypress' ),
			'cancel'           => __( 'Cancel', 'arraypress' ),
			'close'            => __( 'Close', 'arraypress' ),
			'confirm'          => __( 'Confirm', 'arraypress' ),

			// File upload
			'dropFileHere'     => __( 'Drop your CSV file here or click to browse', 'arraypress' ),
			'fileSelected'     => __( 'File selected', 'arraypress' ),
			'invalidFile'      => __( 'Invalid file type. Please upload a CSV file.', 'arraypress' ),
			'uploadFailed'     => __( 'File upload failed', 'arraypress' ),
			'removeFile'       => __( 'Remove file', 'arraypress' ),
			'rowsDetected'     => __( '%d rows detected', 'arraypress' ),
			'rows'             => __( 'rows', 'arraypress' ),

			// Field mapping
			'mapFields'        => __( 'Map Fields', 'arraypress' ),
			'selectColumn'     => __( '-- Select Column --', 'arraypress' ),
			'required'         => __( 'Required', 'arraypress' ),
			'optional'         => __( 'Optional', 'arraypress' ),
			'unmapped'         => __( 'unmapped', 'arraypress' ),
			'previewData'      => __( 'Preview Data', 'arraypress' ),
			'mapRequiredFields' => __( 'Please map the following required fields:', 'arraypress' ),

			// Processing
			'processing'       => __( 'Processing...', 'arraypress' ),
			'processed'        => __( 'Processed', 'arraypress' ),
			'progress'         => __( 'Progress', 'arraypress' ),
			'batch'            => __( 'Batch', 'arraypress' ),
			'created'          => __( 'Created', 'arraypress' ),
			'updated'          => __( 'Updated', 'arraypress' ),
			'skipped'          => __( 'Skipped', 'arraypress' ),
			'failed'           => __( 'Failed', 'arraypress' ),
			'itemsPerSecond'   => __( '%s items/sec', 'arraypress' ),

			// Sync specific
			'syncNow'          => __( 'Sync Now', 'arraypress' ),
			'syncing'          => __( 'Syncing...', 'arraypress' ),
			'lastSync'         => __( 'Last sync', 'arraypress' ),
			'neverSynced'      => __( 'Never synced', 'arraypress' ),
			'startingSync'     => __( 'Starting sync...', 'arraypress' ),
			'startingSyncOp'   => __( 'Starting sync operation...', 'arraypress' ),
			'connectedFetching' => __( 'Connected successfully, fetching data...', 'arraypress' ),
			'batchProcessed'   => __( 'Batch %d: %d %s', 'arraypress' ),
			'syncCompleteMsg'  => __( 'Sync complete! %d %s processed in %s', 'arraypress' ),
			'itemsHadErrors'   => __( '%d %s had errors', 'arraypress' ),
			'batchFailed'      => __( 'Batch failed:', 'arraypress' ),
			'failedToStart'    => __( 'Failed to start:', 'arraypress' ),

			// Import specific
			'startImport'      => __( 'Start Import', 'arraypress' ),
			'importing'        => __( 'Importing...', 'arraypress' ),
			'uploadFile'       => __( 'Upload File', 'arraypress' ),
			'continueToMap'    => __( 'Continue', 'arraypress' ),
			'reviewImport'     => __( 'Review & Import', 'arraypress' ),
			'startingImport'   => __( 'Starting import...', 'arraypress' ),
			'processingRows'   => __( 'Processing %d rows...', 'arraypress' ),
			'importCompleteMsg' => __( 'Import complete in %s!', 'arraypress' ),
			'rowError'         => __( 'Row %d:', 'arraypress' ),
			'errorItem'        => __( 'Error [%s]:', 'arraypress' ),

			// Completion
			'complete'         => __( 'Complete!', 'arraypress' ),
			'syncComplete'     => __( 'Sync complete!', 'arraypress' ),
			'importComplete'   => __( 'Import complete!', 'arraypress' ),
			'completedIn'      => __( 'Completed in %s', 'arraypress' ),
			'viewItems'        => __( 'View %s', 'arraypress' ),
			'downloadErrorLog' => __( 'Download Error Log', 'arraypress' ),
			'runAnother'       => __( 'Run Another', 'arraypress' ),

			// Errors
			'errorOccurred'    => __( 'An error occurred', 'arraypress' ),
			'rowErrors'        => __( '%d rows had errors', 'arraypress' ),
			'viewErrors'       => __( 'View Errors', 'arraypress' ),
			'retryFailed'      => __( 'Retry Failed', 'arraypress' ),

			// Confirmation
			'confirmCancel'    => __( 'Are you sure you want to cancel? Progress will be lost.', 'arraypress' ),
			'confirmSync'      => __( 'Start syncing %s from %s?', 'arraypress' ),

			// Status
			'statusIdle'       => __( 'Ready', 'arraypress' ),
			'statusRunning'    => __( 'Running', 'arraypress' ),
			'statusComplete'   => __( 'Complete', 'arraypress' ),
			'statusError'      => __( 'Error', 'arraypress' ),
			'statusCancelled'  => __( 'Cancelled', 'arraypress' ),

			// Time
			'justNow'          => __( 'Just now', 'arraypress' ),
			'minutesAgo'       => __( '%d minutes ago', 'arraypress' ),
			'hoursAgo'         => __( '%d hours ago', 'arraypress' ),
			'daysAgo'          => __( '%d days ago', 'arraypress' ),

			// Activity log
			'activityLog'      => __( 'Activity Log', 'arraypress' ),
			'copyLog'          => __( 'Copy Log', 'arraypress' ),
			'logCopied'        => __( 'Log copied to clipboard', 'arraypress' ),
			'clearLog'         => __( 'Clear Log', 'arraypress' ),
		];
	}

}