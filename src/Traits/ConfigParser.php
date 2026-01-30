<?php
/**
 * Config Parser Trait
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
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
	 * @return void
	 */
	protected function parse_config(): void {
		$this->parse_tabs();
		$this->parse_operations();
	}

	/**
	 * Parse tabs configuration.
	 *
	 * @return void
	 */
	protected function parse_tabs(): void {
		if ( empty( $this->config['tabs'] ) ) {
			// Create default tabs if operations exist
			if ( ! empty( $this->config['operations'] ) ) {
				$has_syncs   = false;
				$has_imports = false;

				foreach ( $this->config['operations'] as $operation ) {
					$type = $operation['type'] ?? 'import';
					if ( $type === 'sync' ) {
						$has_syncs = true;
					} else {
						$has_imports = true;
					}
				}

				if ( $has_syncs ) {
					$this->tabs['syncs'] = [
						'label' => __( 'Syncs', 'arraypress' ),
						'icon'  => 'dashicons-update',
					];
				}

				if ( $has_imports ) {
					$this->tabs['importers'] = [
						'label' => __( 'Importers', 'arraypress' ),
						'icon'  => 'dashicons-upload',
					];
				}
			}

			return;
		}

		foreach ( $this->config['tabs'] as $key => $tab ) {
			// Handle simple string format: 'syncs' => 'Syncs'
			if ( is_string( $tab ) ) {
				$this->tabs[ $key ] = [
					'label' => $tab,
					'icon'  => '',
				];
			} else {
				// Full array format
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
	 * @return void
	 */
	protected function parse_operations(): void {
		if ( empty( $this->config['operations'] ) ) {
			$this->operations = [];

			return;
		}

		$first_tab = ! empty( $this->tabs ) ? array_key_first( $this->tabs ) : 'default';

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
	 * @param string $key       Operation key.
	 * @param array  $operation Operation configuration.
	 * @param string $first_tab First tab key for default.
	 *
	 * @return array
	 */
	protected function normalize_operation( string $key, array $operation, string $first_tab ): array {
		$type = $operation['type'] ?? 'import';

		$defaults = [
			'type'             => $type,
			'title'            => ucfirst( str_replace( [ '_', '-' ], ' ', $key ) ),
			'description'      => '',
			'tab'              => $type === 'sync' ? 'syncs' : 'importers',
			'icon'             => $type === 'sync' ? 'dashicons-update' : 'dashicons-upload',
			'singular'         => 'item',
			'plural'           => 'items',
			'batch_size'       => 100,
			'capability'       => 'manage_options',
			'process_callback' => null,
		];

		// Ensure tab exists, fallback to first tab
		if ( ! isset( $this->tabs[ $defaults['tab'] ] ) ) {
			$defaults['tab'] = $first_tab;
		}

		$operation = wp_parse_args( $operation, $defaults );

		// Apply type-specific defaults
		$operation = $this->apply_operation_type_defaults( $operation );

		return $operation;
	}

	/**
	 * Apply type-specific default values to operations.
	 *
	 * @param array $operation Operation configuration.
	 *
	 * @return array
	 */
	protected function apply_operation_type_defaults( array $operation ): array {
		switch ( $operation['type'] ) {
			case 'sync':
				$operation = wp_parse_args( $operation, [
					'data_callback' => null,
				] );
				break;

			case 'import':
				$operation = wp_parse_args( $operation, [
					'fields'            => [],
					'update_existing'   => true,
					'match_field'       => null,
					'skip_empty_rows'   => true,
					'validate_callback' => null,
				] );

				// Normalize fields
				if ( ! empty( $operation['fields'] ) ) {
					$operation['fields'] = $this->normalize_fields( $operation['fields'] );
				}
				break;
		}

		return $operation;
	}

	/**
	 * Normalize field definitions for imports.
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
					'label'             => $field,
					'required'          => false,
					'default'           => null,
					'sanitize_callback' => 'sanitize_text_field',
				];
			} else {
				// Full array format
				$normalized[ $key ] = wp_parse_args( $field, [
					'label'             => ucfirst( str_replace( [ '_', '-' ], ' ', $key ) ),
					'required'          => false,
					'default'           => null,
					'sanitize_callback' => 'sanitize_text_field',
				] );
			}
		}

		return $normalized;
	}

	/**
	 * Get operations for a specific tab.
	 *
	 * @param string $tab Tab key.
	 *
	 * @return array
	 */
	protected function get_operations_for_tab( string $tab ): array {
		return $this->operations[ $tab ] ?? [];
	}

	/**
	 * Get all operations.
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
	 * @param string $operation_id Operation ID.
	 *
	 * @return bool
	 */
	public function has_operation( string $operation_id ): bool {
		return $this->get_operation( $operation_id ) !== null;
	}

}
