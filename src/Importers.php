<?php
/**
 * Importers
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

use ArrayPress\RegisterImporters\Csv\Upload;
use ArrayPress\RegisterImporters\Rest\Controller;
use ArrayPress\RegisterImporters\Utils\Runtime;

/**
 * The registered import screens, and the hooks they share.
 */
final class Importers {

	/**
	 * The screens, by identifier.
	 *
	 * @var array<string, Importer>
	 */
	private static array $importers = [];

	/**
	 * Whether the shared hooks are attached.
	 *
	 * @var bool
	 */
	private static bool $hooked = false;

	/**
	 * Register a screen.
	 *
	 * @param string               $id     Its identifier.
	 * @param array<string, mixed> $config Its configuration.
	 *
	 * @return Importer|null Null when there is nothing for it to import.
	 */
	public static function register( string $id, array $config ): ?Importer {
		$id = sanitize_key( $id );

		if ( '' === $id || [] === (array) ( $config['operations'] ?? [] ) ) {
			return null;
		}

		self::hook();

		$importer = new Importer( $id, $config );

		self::$importers[ $id ] = $importer;

		return $importer;
	}

	/**
	 * Attach the hooks every screen shares, once.
	 *
	 * @return void
	 */
	private static function hook(): void {
		if ( self::$hooked ) {
			return;
		}

		self::$hooked = true;

		add_action( 'admin_menu', [ __CLASS__, 'register_menus' ] );
		add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue' ] );

		Controller::boot();

		// Files outlive the transients that describe them, and an import
		// nobody finished leaves a customer list on disk. Once a day is
		// often enough for something with a lifetime of a day.
		add_action( Runtime::hook( 'sweep' ), [ Upload::class, 'sweep' ] );

		if ( ! wp_next_scheduled( Runtime::hook( 'sweep' ) ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', Runtime::hook( 'sweep' ) );
		}
	}

	/**
	 * A registered screen.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return Importer|null
	 */
	public static function get( string $id ): ?Importer {
		return self::$importers[ sanitize_key( $id ) ] ?? null;
	}

	/**
	 * Every registered screen.
	 *
	 * @return array<string, Importer>
	 */
	public static function all(): array {
		return self::$importers;
	}

	/**
	 * Forget one.
	 *
	 * @param string $id Its identifier.
	 *
	 * @return bool
	 */
	public static function unregister( string $id ): bool {
		$id = sanitize_key( $id );

		if ( ! isset( self::$importers[ $id ] ) ) {
			return false;
		}

		unset( self::$importers[ $id ] );

		return true;
	}

	/**
	 * The screen being looked at, if any.
	 *
	 * @return Importer|null
	 */
	public static function current(): ?Importer {
		foreach ( self::$importers as $importer ) {
			if ( $importer->is_current() ) {
				return $importer;
			}
		}

		return null;
	}

	/**
	 * Put every screen on the menu.
	 *
	 * @return void
	 */
	public static function register_menus(): void {
		foreach ( self::$importers as $importer ) {
			$importer->register_menu();
		}
	}

	/**
	 * Load what the screen being looked at needs.
	 *
	 * @return void
	 */
	public static function enqueue(): void {
		self::current()?->enqueue();
	}
}
