<?php
/**
 * Operation
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

use ArrayPress\RegisterImporters\Csv\Sample;
use ArrayPress\RegisterImporters\Row\Pipeline;
use WP_Error;

/**
 * One thing an import screen can import.
 *
 * A screen usually has several — products, customers, orders — and they share
 * nothing but the page they sit on. Each declares its columns and one
 * callback that is handed a finished row.
 *
 * That callback is the whole contract. Everything before it is this library's
 * problem: reading the file, mapping the columns, coercing the strings,
 * checking the rules, turning "Widgets" into a term id. What arrives is a
 * row where every value is what its column said it would be.
 */
final class Operation {

	/**
	 * Its key.
	 *
	 * @var string
	 */
	private string $key;

	/**
	 * Its configuration.
	 *
	 * @var array<string, mixed>
	 */
	private array $config;

	/**
	 * Construct.
	 *
	 * @param string               $key    Its key.
	 * @param array<string, mixed> $config Its configuration.
	 */
	public function __construct( string $key, array $config ) {
		$this->key    = sanitize_key( $key );
		$this->config = array_merge(
			[
				'title'             => ucfirst( str_replace( [ '_', '-' ], ' ', $this->key ) ),
				'description'       => '',
				'icon'              => 'upload',
				'tab'               => '',
				'fields'            => [],

				// A hundred rows a request. Small enough that a slow callback
				// still answers before the server gives up, large enough that
				// a ten thousand row file is a hundred requests rather than
				// ten thousand.
				'batch_size'        => 100,
				'separator'         => ',',

				// What to do with a row that has already been imported. The
				// callback decides; this is only what the screen offers.
				'process_callback'  => null,
				'validate_callback' => null,
				'before_import'     => null,
				'after_import'      => null,
			],
			$config
		);

		$this->config['fields'] = self::normalize_fields( (array) $this->config['fields'] );
	}

	/**
	 * Fill in what each field did not say.
	 *
	 * A field may be declared as just a label, because most of them are just
	 * a label:
	 *
	 *     'sku' => 'Product code',
	 *
	 * @param array<string, mixed> $fields The declarations.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function normalize_fields( array $fields ): array {
		$normalized = [];

		foreach ( $fields as $key => $field ) {
			$field = is_array( $field ) ? $field : [ 'label' => (string) $field ];

			$normalized[ (string) $key ] = array_merge(
				[
					'label'    => ucfirst( str_replace( [ '_', '-' ], ' ', (string) $key ) ),
					'type'     => 'string',
					'required' => false,
				],
				$field
			);
		}

		return $normalized;
	}

	/**
	 * Its key.
	 *
	 * @return string
	 */
	public function key(): string {
		return $this->key;
	}

	/**
	 * One configuration value.
	 *
	 * @param string $key      The key.
	 * @param mixed  $fallback Returned when it is not set.
	 *
	 * @return mixed
	 */
	public function get( string $key, mixed $fallback = null ): mixed {
		return $this->config[ $key ] ?? $fallback;
	}

	/**
	 * Its columns.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function fields(): array {
		return (array) $this->config['fields'];
	}

	/**
	 * The columns that must be mapped to something.
	 *
	 * @return string[]
	 */
	public function required(): array {
		return array_keys( array_filter( $this->fields(), static fn( array $field ): bool => ! empty( $field['required'] ) ) );
	}

	/**
	 * How many rows to do at a time.
	 *
	 * @return int
	 */
	public function batch_size(): int {
		return max( 1, min( 1000, (int) $this->config['batch_size'] ) );
	}

	/**
	 * A file showing what this expects.
	 *
	 * @return string
	 */
	public function sample(): string {
		return Sample::of( $this->fields(), (string) $this->config['separator'] );
	}

	/**
	 * The name that file should be offered under.
	 *
	 * @return string
	 */
	public function sample_filename(): string {
		return sprintf( '%s-sample.csv', $this->key );
	}

	/**
	 * Whether this operation can actually run.
	 *
	 * An operation with no callback imports nothing, and would otherwise
	 * report every row as skipped and finish looking successful.
	 *
	 * @return bool
	 */
	public function is_runnable(): bool {
		return is_callable( $this->config['process_callback'] );
	}

	/**
	 * Take one row as far as it goes.
	 *
	 * @param array<string, mixed> $row    The row, already mapped to field keys.
	 * @param bool                 $commit Whether to import it or only check it.
	 *
	 * @return string|WP_Error What happened to it: created, updated or skipped.
	 */
	public function row( array $row, bool $commit = true ): string|WP_Error {
		$values = Pipeline::row( $row, $this->fields(), $commit );

		if ( $values instanceof WP_Error ) {
			return $values;
		}

		if ( is_callable( $this->config['validate_callback'] ) ) {
			$checked = call_user_func( $this->config['validate_callback'], $values );

			if ( $checked instanceof WP_Error ) {
				return $checked;
			}

			if ( false === $checked ) {
				return new WP_Error( 'row_refused', __( 'This row was refused.', 'arraypress' ) );
			}
		}

		// A dry run stops here, having done everything except the one thing
		// that writes.
		if ( ! $commit ) {
			return 'skipped';
		}

		if ( ! $this->is_runnable() ) {
			return new WP_Error(
				'no_callback',
				sprintf(
					/* translators: %s: the operation's title. */
					__( '%s has no process callback, so there is nothing for it to do with a row.', 'arraypress' ),
					(string) $this->config['title']
				)
			);
		}

		$result = call_user_func( $this->config['process_callback'], $values );

		if ( $result instanceof WP_Error ) {
			return $result;
		}

		// Anything the callback does not recognise is counted as an import
		// that happened, because it did: the callback returned rather than
		// failing.
		return in_array( $result, [ 'created', 'updated', 'skipped' ], true ) ? (string) $result : 'created';
	}
}
