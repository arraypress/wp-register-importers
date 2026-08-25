<?php
/**
 * Pipeline
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Row;

use WP_Error;

/**
 * One row of a CSV file, on its way to becoming something.
 *
 * The steps are in one place and run in one order, and a dry run is the same
 * pass with `$commit` false. That is the whole point of this class.
 *
 * There used to be two: process_row() ran nine steps and validate_row() ran a
 * hand-copied seven of them, "to avoid side effects". They had already
 * drifted. A dry run skipped entity resolution entirely, so a file naming a
 * category that did not exist was reported as five thousand rows, no
 * problems — and then the import silently stored null for every one of them,
 * because the real pass swallowed the resolution failure whenever the field
 * was not required.
 *
 * A dry run that disagrees with the import is worse than no dry run, because
 * it is the thing people trust before pressing the button.
 *
 * What `$commit` actually turns off is small and deliberate:
 *
 * - the consumer's `process_callback`, which is theirs and may write
 * - creating a term that is missing, though the lookup still happens, so a
 *   dry run reports what is missing rather than pretending it is there
 */
final class Pipeline {

	/**
	 * Run a whole row.
	 *
	 * @param array<string, mixed>                $row    The mapped row.
	 * @param array<string, array<string, mixed>> $fields The field declarations.
	 * @param bool                                $commit Whether this is the real thing.
	 *
	 * @return array<string, mixed>|WP_Error The row, or the first thing wrong with it.
	 */
	public static function row( array $row, array $fields, bool $commit = true ): array|WP_Error {
		$done = [];

		foreach ( $fields as $key => $field ) {
			$value = self::field( (string) $key, $row[ $key ] ?? null, (array) $field, $row, $commit );

			if ( $value instanceof WP_Error ) {
				return self::in_field( $value, (string) $key );
			}

			$done[ (string) $key ] = $value;
		}

		return $done;
	}

	/**
	 * Run one field.
	 *
	 * @param string               $key    The field key.
	 * @param mixed                $value  The raw cell.
	 * @param array<string, mixed> $field  The field's declaration.
	 * @param array<string, mixed> $row    The whole row, for a callback that needs it.
	 * @param bool                 $commit Whether this is the real thing.
	 *
	 * @return mixed|WP_Error
	 */
	public static function field( string $key, mixed $value, array $field, array $row = [], bool $commit = true ): mixed {
		$type = (string) ( $field['type'] ?? 'string' );

		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		if ( Check::is_missing( $value ) && isset( $field['default'] ) ) {
			$value = $field['default'];
		}

		$value = self::transform( $value, $field );

		if ( '' !== (string) ( $field['separator'] ?? '' ) && is_string( $value ) && '' !== $value ) {
			$value = self::split( $value, (string) $field['separator'] );
		}

		if ( in_array( $type, Cast::SCALARS, true ) ) {
			$value = is_array( $value )
				? self::cast_each( $value, $type, $field )
				: Cast::to( $value, $type, $field );

			if ( $value instanceof WP_Error ) {
				return $value;
			}
		}

		$error = Check::value( $key, $value, $field );

		if ( null !== $error ) {
			return $error;
		}

		if ( is_callable( $field['validate_callback'] ?? null ) ) {
			$result = call_user_func( $field['validate_callback'], $value, $row );

			if ( $result instanceof WP_Error ) {
				return $result;
			}
		}

		// The consumer's, and it may write. A dry run is a promise that
		// nothing happened.
		if ( $commit && is_callable( $field['process_callback'] ?? null ) ) {
			$value = call_user_func( $field['process_callback'], $value, $row );

			if ( $value instanceof WP_Error ) {
				return $value;
			}
		}

		if ( in_array( $type, Resolve::TYPES, true ) ) {
			return Resolve::entity( $value, $field, $commit );
		}

		return $value;
	}

	/**
	 * Cast every item of a separated list.
	 *
	 * @param array<int, mixed>    $values The items.
	 * @param string               $type   The declared type.
	 * @param array<string, mixed> $field  The field's declaration.
	 *
	 * @return array<int, mixed>|WP_Error
	 */
	private static function cast_each( array $values, string $type, array $field ): array|WP_Error {
		$cast = [];

		foreach ( $values as $one ) {
			$result = Cast::to( $one, $type, $field );

			if ( $result instanceof WP_Error ) {
				return $result;
			}

			$cast[] = $result;
		}

		return $cast;
	}

	/**
	 * Upper or lower case, if the field asked.
	 *
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field The field's declaration.
	 *
	 * @return mixed
	 */
	private static function transform( mixed $value, array $field ): mixed {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		if ( ! empty( $field['uppercase'] ) ) {
			return mb_strtoupper( $value );
		}

		if ( ! empty( $field['lowercase'] ) ) {
			return mb_strtolower( $value );
		}

		return $value;
	}

	/**
	 * Split a cell that holds a list.
	 *
	 * Every character of the separator is one, rather than whichever of them
	 * the value happens to contain first — a separator of `,;` on `a;b,c`
	 * used to split on the comma alone and hand back `a;b` as an item.
	 *
	 * Empty items are dropped and nought is not empty: a list of `0,1,2` kept
	 * two of its three values before, because array_filter() with no callback
	 * reads "0" as nothing.
	 *
	 * @param string $value     The cell.
	 * @param string $separator The character or characters that separate items.
	 *
	 * @return array<int, string>
	 */
	private static function split( string $value, string $separator ): array {
		$parts = preg_split( '/[' . preg_quote( $separator, '/' ) . ']/', $value ) ?: [];

		return array_values(
			array_filter(
				array_map( 'trim', $parts ),
				static fn( string $part ): bool => '' !== $part
			)
		);
	}

	/**
	 * Say which column an error came from.
	 *
	 * A file has a hundred columns and five thousand rows. "must be a number"
	 * with no column named is a message the reader cannot act on, and the
	 * field's own label is already in the message — this adds the key, which
	 * is what the CSV header says.
	 *
	 * @param WP_Error $error The error.
	 * @param string   $key   The field key.
	 *
	 * @return WP_Error
	 */
	private static function in_field( WP_Error $error, string $key ): WP_Error {
		$error->add_data( [ 'field' => $key ], $error->get_error_code() );

		return $error;
	}
}
