<?php
/**
 * Sample
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Csv;

/**
 * A file showing what the importer expects.
 *
 * The single most useful thing an import screen offers. A list of column
 * names on the page tells somebody what the columns are called; a file they
 * can open in the thing they will actually be exporting from tells them the
 * shape, the order, the separator and what a date is supposed to look like.
 *
 * The example row is built from each field's own declaration, so a field with
 * `options` shows one of them and a field with a `date_format` shows a date
 * in it. A sample that does not match the rules is worse than none.
 */
final class Sample {

	/**
	 * Build a sample file.
	 *
	 * @param array<string, array<string, mixed>> $fields    The field declarations.
	 * @param string                              $separator What separates values.
	 *
	 * @return string The file's contents.
	 */
	public static function of( array $fields, string $separator = ',' ): string {
		$handle = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return '';
		}

		// A byte order mark, on purpose. Excel reads a CSV without one as the
		// system's legacy encoding, so an accented name in the sample comes
		// back mangled — which is exactly the mistake the sample exists to
		// stop somebody making with their own file.
		fwrite( $handle, "\xEF\xBB\xBF" );

		fputcsv( $handle, array_keys( $fields ), $separator, '"', '' );
		fputcsv( $handle, array_map( [ self::class, 'example' ], $fields, array_keys( $fields ) ), $separator, '"', '' );

		rewind( $handle );

		$contents = (string) stream_get_contents( $handle );

		fclose( $handle );

		return $contents;
	}

	/**
	 * What one column's example value should be.
	 *
	 * @param array<string, mixed> $field The field's declaration.
	 * @param string               $key   Its key.
	 *
	 * @return string
	 */
	private static function example( array $field, string $key ): string {
		// Whatever the field itself says is best: it is the only thing that
		// knows this file's own vocabulary.
		if ( isset( $field['example'] ) ) {
			return (string) $field['example'];
		}

		if ( isset( $field['default'] ) && '' !== (string) $field['default'] ) {
			return (string) $field['default'];
		}

		// A field with a list of allowed values has to show one of them, or
		// the sample fails its own validation.
		$options = $field['options'] ?? null;

		if ( is_array( $options ) && [] !== $options ) {
			return (string) reset( $options );
		}

		$type = (string) ( $field['type'] ?? 'string' );

		if ( in_array( $type, [ 'date', 'datetime' ], true ) ) {
			$format = (string) ( $field['date_format'] ?? '' );

			if ( '' === $format ) {
				$format = 'date' === $type ? 'Y-m-d' : 'Y-m-d H:i:s';
			}

			return gmdate( $format );
		}

		return match ( $type ) {
			'number'     => '9.99',
			'integer'    => '10',
			'boolean'    => 'yes',
			'email'      => 'someone@example.com',
			'url'        => 'https://example.com',
			'currency'   => 'USD',
			'country'    => 'US',
			'post'       => 'a-post-slug',
			'term'       => 'A Category',
			'user'       => 'someone@example.com',
			'attachment' => 'https://example.com/image.jpg',
			default      => ucfirst( str_replace( [ '_', '-' ], ' ', $key ) ),
		};
	}
}
