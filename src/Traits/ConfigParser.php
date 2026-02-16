<?php
/**
 * Config Parser Trait
 *
 * Handles parsing and normalizing the configuration array
 * for CSV import operations.
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Traits;

/**
 * Trait ConfigParser
 *
 * Handles parsing and normalizing the configuration array.
 */
trait ConfigParser {

	/**
	 * Parse the configuration array.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	protected function parse_config(): void {
		$this->parse_tabs();
		$this->parse_operations();
	}

	/**
	 * Parse tabs configuration.
	 *
	 * Auto-generates a default tab if none provided and there
	 * are operations configured. Tabs auto-hide when only one exists.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	protected function parse_tabs(): void {
		if ( empty( $this->config['tabs'] ) ) {
			// Create a single default tab if operations exist
			if ( ! empty( $this->config['operations'] ) ) {
				$this->tabs['importers'] = [
					'label' => __( 'Importers', 'arraypress' ),
					'icon'  => 'dashicons-upload',
				];
			}

			return;
		}

		foreach ( $this->config['tabs'] as $key => $tab ) {
			if ( is_string( $tab ) ) {
				$this->tabs[ $key ] = [
					'label' => $tab,
					'icon'  => '',
				];
			} else {
				$this->tabs[ $key ] = wp_parse_args( $tab, [
					'label'           => ucfirst( $key ),
					'icon'            => '',
					'render_callback' => null,
				] );
			}
		}
	}

	/**
	 * Parse operations configuration.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	protected function parse_operations(): void {
		if ( empty( $this->config['operations'] ) ) {
			$this->operations = [];

			return;
		}

		$first_tab = ! empty( $this->tabs ) ? array_key_first( $this->tabs ) : 'importers';

		foreach ( $this->config['operations'] as $key => $operation ) {
			$operation = $this->normalize_operation( $key, $operation, $first_tab );
			$tab       = $operation['tab'];

			if ( ! isset( $this->operations[ $tab ] ) ) {
				$this->operations[ $tab ] = [];
			}

			$this->operations[ $tab ][ $key ] = $operation;
		}
	}

	/**
	 * Normalize a single operation configuration.
	 *
	 * @since 2.0.0
	 *
	 * @param string $key       Operation key.
	 * @param array  $operation Operation configuration.
	 * @param string $first_tab First tab key for default.
	 *
	 * @return array
	 */
	protected function normalize_operation( string $key, array $operation, string $first_tab ): array {
		$defaults = [
			'title'             => ucfirst( str_replace( [ '_', '-' ], ' ', $key ) ),
			'description'       => '',
			'tab'               => $first_tab,
			'icon'              => 'dashicons-upload',
			'batch_size'        => 100,
			'max_file_size'     => 0,
			'skip_empty_rows'   => true,
			'fields'            => [],
			'validate_callback' => null,
			'process_callback'  => null,
			'before_import'     => null,
			'after_import'      => null,
		];

		// Ensure tab exists, fallback to first tab
		if ( isset( $operation['tab'] ) && ! isset( $this->tabs[ $operation['tab'] ] ) ) {
			$operation['tab'] = $first_tab;
		}

		$operation = wp_parse_args( $operation, $defaults );

		// Normalize fields
		if ( ! empty( $operation['fields'] ) ) {
			$operation['fields'] = $this->normalize_fields( $operation['fields'] );
		}

		return $operation;
	}

	/**
	 * Normalize field definitions.
	 *
	 * @since 2.0.0
	 *
	 * @param array $fields Raw field definitions.
	 *
	 * @return array Normalized field definitions.
	 */
	protected function normalize_fields( array $fields ): array {
		$normalized = [];

		foreach ( $fields as $key => $field ) {
			// Handle simple format: 'sku' => 'SKU'
			if ( is_string( $field ) ) {
				$normalized[ $key ] = [
					'label'    => $field,
					'type'     => 'string',
					'required' => false,
					'default'  => null,
				];
			} else {
				$normalized[ $key ] = wp_parse_args( $field, [
					'label'    => ucfirst( str_replace( [ '_', '-' ], ' ', $key ) ),
					'type'     => 'string',
					'required' => false,
					'default'  => null,
				] );
			}
		}

		return $normalized;
	}

	/**
	 * Get operations for a specific tab.
	 *
	 * @since 2.0.0
	 *
	 * @param string $tab Tab key.
	 *
	 * @return array
	 */
	protected function get_operations_for_tab( string $tab ): array {
		return $this->operations[ $tab ] ?? [];
	}

	/**
	 * Get all operations across all tabs.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public function get_all_operations(): array {
		$all = [];

		foreach ( $this->operations as $ops ) {
			$all = array_merge( $all, $ops );
		}

		return $all;
	}

	/**
	 * Get a specific operation by ID.
	 *
	 * @since 2.0.0
	 *
	 * @param string $operation_id Operation ID.
	 *
	 * @return array|null Operation config or null if not found.
	 */
	public function get_operation( string $operation_id ): ?array {
		foreach ( $this->operations as $ops ) {
			if ( isset( $ops[ $operation_id ] ) ) {
				return $ops[ $operation_id ];
			}
		}

		return null;
	}

	/**
	 * Check if an operation exists.
	 *
	 * @since 2.0.0
	 *
	 * @param string $operation_id Operation ID.
	 *
	 * @return bool
	 */
	public function has_operation( string $operation_id ): bool {
		return $this->get_operation( $operation_id ) !== null;
	}

}
