<?php
/**
 * Helper Functions
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

use ArrayPress\RegisterImporters\Importers;
use ArrayPress\RegisterImporters\Registry;
use ArrayPress\RegisterImporters\StatsManager;

if ( ! function_exists( 'register_importers' ) ) {
	/**
	 * Register an importers page.
	 *
	 * @param string $id     Unique identifier for this importers page.
	 * @param array  $config Configuration array.
	 *
	 * @return Importers|null The Importers instance or null on failure.
	 */
	function register_importers( string $id, array $config ): ?Importers {
		if ( empty( $id ) ) {
			return null;
		}

		return new Importers( $id, $config );
	}
}

if ( ! function_exists( 'get_importer' ) ) {
	/**
	 * Get a registered importers page by ID.
	 *
	 * @param string $id Importers ID.
	 *
	 * @return Importers|null
	 */
	function get_importer( string $id ): ?Importers {
		return Registry::instance()->get( $id );
	}
}

if ( ! function_exists( 'get_importer_stats' ) ) {
	/**
	 * Get stats for a specific operation.
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 *
	 * @return array Stats array.
	 */
	function get_importer_stats( string $page_id, string $operation_id ): array {
		return StatsManager::get_stats( $page_id, $operation_id );
	}
}

if ( ! function_exists( 'clear_importer_stats' ) ) {
	/**
	 * Clear stats for a specific operation.
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 *
	 * @return bool True on success.
	 */
	function clear_importer_stats( string $page_id, string $operation_id ): bool {
		return StatsManager::clear_stats( $page_id, $operation_id );
	}
}