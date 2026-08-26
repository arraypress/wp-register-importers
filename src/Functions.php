<?php
/**
 * Registration
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

use ArrayPress\RegisterImporters\Importer;
use ArrayPress\RegisterImporters\Importers;

if ( ! function_exists( 'register_importers' ) ) {
	/**
	 * Register an import screen.
	 *
	 *     register_importers( 'myplugin-import', [
	 *         'parent_slug' => 'tools.php',
	 *         'operations'  => [
	 *             'products' => [
	 *                 'title'  => __( 'Products', 'my-plugin' ),
	 *                 'fields' => [
	 *                     'sku'   => [ 'label' => __( 'Code', 'my-plugin' ), 'required' => true ],
	 *                     'name'  => __( 'Name', 'my-plugin' ),
	 *                     'price' => [ 'label' => __( 'Price', 'my-plugin' ), 'type' => 'number', 'minimum' => 0 ],
	 *                 ],
	 *                 'process_callback' => 'myplugin_import_product',
	 *             ],
	 *         ],
	 *     ] );
	 *
	 * The callback is handed one row, where every value is already what its
	 * column said it would be. It returns `created`, `updated` or `skipped`,
	 * or a WP_Error naming what was wrong with the row.
	 *
	 * @param string               $id     The screen's identifier.
	 * @param array<string, mixed> $config Its configuration.
	 *
	 * @return Importer|null Null when it has nothing to import.
	 */
	function register_importers( string $id, array $config ): ?Importer {
		return Importers::register( $id, $config );
	}
}

if ( ! function_exists( 'get_importers' ) ) {
	/**
	 * A registered import screen.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return Importer|null
	 */
	function get_importers( string $id ): ?Importer {
		return Importers::get( $id );
	}
}

if ( ! function_exists( 'get_importers_url' ) ) {
	/**
	 * Where an import screen is, so a plugin can link to it.
	 *
	 * @param string $id  Its identifier.
	 * @param string $tab A tab to open it on.
	 *
	 * @return string Empty when there is no such screen.
	 */
	function get_importers_url( string $id, string $tab = '' ): string {
		return Importers::get( $id )?->url( $tab ) ?? '';
	}
}

if ( ! function_exists( 'unregister_importers' ) ) {
	/**
	 * Forget an import screen.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return bool
	 */
	function unregister_importers( string $id ): bool {
		return Importers::unregister( $id );
	}
}
