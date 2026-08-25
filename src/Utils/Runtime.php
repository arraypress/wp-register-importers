<?php
/**
 * Runtime Key Derivation
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Utils;

/**
 * Every runtime string this library registers, derived from its own namespace.
 *
 * Strauss rewrites class namespaces and leaves string literals alone. Two
 * plugins each bundling a prefixed copy of this library therefore get
 * distinct classes but would otherwise register identical script handles and
 * identical transient keys.
 *
 * That is not merely untidy, because the worst of them is the REST namespace.
 * `WP_REST_Server::register_route()` merges same-path registrations with
 * `array_merge()` over a numerically indexed handler list, so handlers are
 * appended rather than replaced and dispatch runs the first whose methods
 * match. The plugin that registered first therefore answers the other's
 * import requests — under its own capability, against its own registry of
 * operations, writing rows through its own process callback.
 *
 * The derivation exploits the one thing Strauss does rewrite: this file's
 * namespace. In a prefixed build `__NAMESPACE__` begins with the consumer's
 * prefix ("MyPlugin\ArrayPress\RegisterImporters\Utils"), unique per plugin
 * by construction, so every key comes out distinct with no configuration.
 */
final class Runtime {

	/**
	 * This library's own identifier, used when running unprefixed.
	 */
	private const LIBRARY = 'importers';

	/**
	 * The per-build prefix.
	 *
	 * "importers" for a plain Composer install — development, or a single
	 * consumer that does not use Strauss — and "{prefix}-importers" for a
	 * prefixed build.
	 *
	 * @return string
	 */
	public static function prefix(): string {
		$segments = explode( '\\', __NAMESPACE__ );
		$root     = $segments[0] ?? '';

		if ( '' === $root || 'ArrayPress' === $root ) {
			return self::LIBRARY;
		}

		return self::slug( $root ) . '-' . self::LIBRARY;
	}

	/**
	 * The REST namespace for this build.
	 *
	 * @return string
	 */
	public static function rest_namespace(): string {
		return self::prefix() . '/v1';
	}

	/**
	 * A script or style handle for this build.
	 *
	 * @param string $suffix Optional handle suffix.
	 *
	 * @return string
	 */
	public static function handle( string $suffix = '' ): string {
		return '' === $suffix ? self::prefix() : self::prefix() . '-' . $suffix;
	}

	/**
	 * An option or transient key for this build.
	 *
	 * @param string $suffix Optional key suffix.
	 *
	 * @return string
	 */
	public static function key( string $suffix = '' ): string {
		$base = str_replace( '-', '_', self::prefix() );

		return '' === $suffix ? $base : $base . '_' . $suffix;
	}

	/**
	 * A hook name for this build.
	 *
	 * @param string $suffix What the hook is for.
	 *
	 * @return string
	 */
	public static function hook( string $suffix ): string {
		return self::key( $suffix );
	}

	/**
	 * Reduce a namespace segment to a lowercase slug.
	 *
	 * Not sanitize_title(): this runs from `__NAMESPACE__` at class-load
	 * time, which can precede WordPress being fully loaded.
	 *
	 * @param string $value Value to slug.
	 *
	 * @return string
	 */
	private static function slug( string $value ): string {
		$value = preg_replace( '/[^A-Za-z0-9]+/', '-', $value ) ?? '';

		return strtolower( trim( $value, '-' ) );
	}
}
