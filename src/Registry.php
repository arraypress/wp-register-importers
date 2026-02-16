<?php
/**
 * Importers Registry
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

/**
 * Class Registry
 *
 * Singleton registry for managing multiple importer pages.
 */
class Registry {

	/**
	 * Singleton instance.
	 *
	 * @var Registry|null
	 */
	private static ?Registry $instance = null;

	/**
	 * Registered importer pages.
	 *
	 * @var array<string, Importers>
	 */
	private array $importers = [];

	/**
	 * Get singleton instance.
	 *
	 * @since 1.0.0
	 *
	 * @return Registry
	 */
	public static function instance(): Registry {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Private constructor for singleton.
	 */
	private function __construct() {
	}

	/**
	 * Register an importers instance.
	 *
	 * @since 1.0.0
	 *
	 * @param string    $id        Unique identifier.
	 * @param Importers $importers Importers instance.
	 *
	 * @return void
	 */
	public static function register( string $id, Importers $importers ): void {
		self::instance()->importers[ $id ] = $importers;
	}

	/**
	 * Get a registered importers page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Importers ID.
	 *
	 * @return Importers|null
	 */
	public function get( string $id ): ?Importers {
		return $this->importers[ $id ] ?? null;
	}

	/**
	 * Check if an importers page is registered.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Importers ID.
	 *
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->importers[ $id ] );
	}

	/**
	 * Get all registered importers pages.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, Importers>
	 */
	public function all(): array {
		return $this->importers;
	}

	/**
	 * Unregister an importers page.
	 *
	 * @since 1.0.0
	 *
	 * @param string $id Importers ID.
	 *
	 * @return bool
	 */
	public function unregister( string $id ): bool {
		if ( isset( $this->importers[ $id ] ) ) {
			unset( $this->importers[ $id ] );

			return true;
		}

		return false;
	}

}
