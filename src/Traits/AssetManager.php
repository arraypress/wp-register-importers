<?php
/**
 * Asset Manager Trait
 *
 * Handles enqueueing of scripts and styles.
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 1.0.0
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
	 * @since 2.0.0
	 *
	 * @return void
	 */
	protected function localize_scripts(): void {
		$operations_config = [];

		foreach ( $this->get_all_operations() as $id => $operation ) {
			$operations_config[ $id ] = [
				'title'     => $operation['title'],
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
	 * @since 2.0.0
	 *
	 * @return array
	 */
	protected function get_i18n_strings(): array {
		return [
			'loading'            => __( 'Loading...', 'arraypress' ),
			'error'              => __( 'Error', 'arraypress' ),
			'success'            => __( 'Success', 'arraypress' ),
			'cancel'             => __( 'Cancel', 'arraypress' ),
			'close'              => __( 'Close', 'arraypress' ),
			'invalidFile'        => __( 'Invalid file type. Please upload a CSV file.', 'arraypress' ),
			'uploadFailed'       => __( 'File upload failed.', 'arraypress' ),
			'rows'               => __( 'rows', 'arraypress' ),
			'selectColumn'       => __( '-- Select Column --', 'arraypress' ),
			'unmapped'           => __( 'unmapped', 'arraypress' ),
			'mapRequiredFields'  => __( 'Please map the following required fields:', 'arraypress' ),
			'batch'              => __( 'Batch', 'arraypress' ),
			'created'            => __( 'Created', 'arraypress' ),
			'updated'            => __( 'Updated', 'arraypress' ),
			'skipped'            => __( 'Skipped', 'arraypress' ),
			'failed'             => __( 'Failed', 'arraypress' ),
			'startImport'        => __( 'Start Import', 'arraypress' ),
			'importing'          => __( 'Importing...', 'arraypress' ),
			'continueToMap'      => __( 'Continue', 'arraypress' ),
			'startingImport'     => __( 'Starting import...', 'arraypress' ),
			'processingRows'     => __( 'Processing %d rows...', 'arraypress' ),
			'importCompleteMsg'  => __( 'Import complete!', 'arraypress' ),
			'rowError'           => __( 'Row %d:', 'arraypress' ),
			'runAnother'         => __( 'Run Another', 'arraypress' ),
			'errorOccurred'      => __( 'An error occurred', 'arraypress' ),
			'failedToStart'      => __( 'Failed to start:', 'arraypress' ),
			'batchFailed'        => __( 'Batch failed:', 'arraypress' ),
			'confirmCancel'      => __( 'Are you sure you want to cancel? Progress will be lost.', 'arraypress' ),
			'confirmClearStats'  => __( 'Clear stats for this operation?', 'arraypress' ),
			'operationCancelled' => __( 'Operation cancelled.', 'arraypress' ),
			'lastImport'         => __( 'Last import', 'arraypress' ),
			'neverImported'      => __( 'Never', 'arraypress' ),
			'justNow'            => __( 'Just now', 'arraypress' ),
			'logCopied'          => __( 'Copied!', 'arraypress' ),
			'dryRun'             => __( 'Preview', 'arraypress' ),
			'dryRunning'         => __( 'Validating...', 'arraypress' ),
			'dryRunComplete'     => __( '%d valid, %d errors out of %d rows', 'arraypress' ),
			'downloadSample'     => __( 'Download Sample CSV', 'arraypress' ),
		];
	}

}
