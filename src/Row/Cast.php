<?php
/**
 * Cast
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Row;

use DateTimeImmutable;
use WP_Error;

/**
 * A cell of a CSV file is a string. This turns it into what it is meant to be.
 *
 * Everything here is deliberately forgiving about *shape* and strict about
 * *meaning*. A price exported from a spreadsheet arrives as `£1,299.00` and
 * should become 1299.0; a quantity of `banana` should not become 0.
 *
 * That is the rule the old version broke in one place, and it is the one that
 * matters: an unrecognised boolean became false. A column of Yes/No with
 * `Ys` in one row imported as No, silently, and the only way to find out was
 * to notice later that a product was not on sale.
 */
final class Cast {

	/**
	 * Words for yes.
	 *
	 * @var string[]
	 */
	private const TRUE = [ '1', 'true', 'yes', 'y', 'on', 't' ];

	/**
	 * Words for no.
	 *
	 * @var string[]
	 */
	private const FALSE = [ '0', 'false', 'no', 'n', 'off', 'f' ];

	/**
	 * The types this class knows how to cast.
	 *
	 * @var string[]
	 */
	public const SCALARS = [ 'string', 'number', 'integer', 'boolean', 'email', 'url', 'currency', 'country', 'date', 'datetime' ];

	/**
	 * Cast one value.
	 *
	 * @param mixed                $value The raw cell.
	 * @param string               $type  The declared type.
	 * @param array<string, mixed> $field The field's declaration.
	 *
	 * @return mixed|WP_Error The value, or an error when it cannot mean what it says.
	 */
	public static function to( mixed $value, string $type, array $field = [] ): mixed {
		if ( null === $value || '' === $value ) {
			return $value;
		}

		return match ( $type ) {
			'number'   => self::number( $value ),
			'integer'  => self::integer( $value ),
			'boolean'  => self::boolean( $value ),
			// A code, and nothing more can be said about it here. Whether it
			// is a code that exists is Check's question.
			'currency',
			'country'  => strtoupper( trim( (string) $value ) ),
			'date'     => self::date( (string) $value, $field, 'Y-m-d' ),
			'datetime' => self::date( (string) $value, $field, 'Y-m-d H:i:s' ),
			default    => (string) $value,
		};
	}

	/**
	 * A number, however a spreadsheet wrote it.
	 *
	 * Currency symbols, thousands separators and stray spaces come off. What
	 * is left has to be a number — the value is handed back untouched when it
	 * is not, so Check reports it against the field's own label rather than
	 * this returning 0 and the import storing a price of nothing.
	 *
	 * @param mixed $value The raw cell.
	 *
	 * @return float|mixed
	 */
	private static function number( mixed $value ): mixed {
		$cleaned = preg_replace( '/[^\d.\-+eE]/', '', (string) $value ) ?? '';

		return is_numeric( $cleaned ) ? (float) $cleaned : $value;
	}

	/**
	 * A whole number.
	 *
	 * The decimal point is kept while cleaning, not stripped: a spreadsheet
	 * writes a quantity of twelve as `12.00` often enough that refusing it
	 * would be useless, and stripping the point turns `12.9` into a hundred
	 * and twenty nine.
	 *
	 * So `12.00` is twelve, and `12.9` is handed back untouched for Check to
	 * report — truncating it silently is the same mistake as reading a typo
	 * in a Yes/No column as No.
	 *
	 * @param mixed $value The raw cell.
	 *
	 * @return int|mixed
	 */
	private static function integer( mixed $value ): mixed {
		$cleaned = preg_replace( '/[^\d.\-+]/', '', (string) $value ) ?? '';

		if ( ! is_numeric( $cleaned ) ) {
			return $value;
		}

		return (float) $cleaned === floor( (float) $cleaned ) ? (int) (float) $cleaned : $value;
	}

	/**
	 * Yes or no, and an error for anything that is neither.
	 *
	 * The old version took a list of words for yes and called everything else
	 * no, so a typo in a Yes/No column imported as No and said nothing. A
	 * cell that means neither is a mistake in the file, and the reader is the
	 * only one who can fix it.
	 *
	 * @param mixed $value The raw cell.
	 *
	 * @return bool|WP_Error
	 */
	private static function boolean( mixed $value ): bool|WP_Error {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$word = strtolower( trim( (string) $value ) );

		if ( in_array( $word, self::TRUE, true ) ) {
			return true;
		}

		if ( in_array( $word, self::FALSE, true ) ) {
			return false;
		}

		return new WP_Error(
			'invalid_boolean',
			sprintf(
				/* translators: 1: the value found in the file, 2: the words that are accepted. */
				__( '"%1$s" is not a yes or a no. Use one of: %2$s.', 'arraypress' ),
				$value,
				implode( ', ', array_merge( self::TRUE, self::FALSE ) )
			)
		);
	}

	/**
	 * A date, normalised.
	 *
	 * No timezone conversion: the value is parsed and written back in a
	 * standard shape, and a file that means local time still means local
	 * time. Converting here would be a guess about a file this library has
	 * never seen.
	 *
	 * @param string               $value  The raw cell.
	 * @param array<string, mixed> $field  The field's declaration.
	 * @param string               $format What to write it back as.
	 *
	 * @return string|mixed
	 */
	private static function date( string $value, array $field, string $format ): mixed {
		$expected = (string) ( $field['date_format'] ?? '' );

		// A declared input format is a promise about the file. Anything that
		// does not keep it is handed back for Check to report, rather than
		// being parsed by a looser reading that happens to work — which is
		// how 03/04/2026 imports as the fourth of March in one file and the
		// third of April in the next.
		if ( '' !== $expected ) {
			$parsed = DateTimeImmutable::createFromFormat( '!' . $expected, $value );

			// Round-tripped, because createFromFormat is lenient: it reads
			// 2026-13-45 against Y-m-d and rolls it forward into 2027. A
			// value that does not come back as it went in did not match.
			return false !== $parsed && $parsed->format( $expected ) === $value
				? $parsed->format( $format )
				: $value;
		}

		$parsed = strtotime( $value );

		return false === $parsed ? $value : gmdate( $format, $parsed );
	}
}
