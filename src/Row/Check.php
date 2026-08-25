<?php
/**
 * Check
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
 * Whether a value is allowed to be what it is.
 *
 * Separate from Cast because the two questions are different. Cast asks what
 * `£1,299.00` means; this asks whether 1299 is within the range the field
 * declared. Running them together is how a value that could not be cast ends
 * up validated as though it had been.
 *
 * Every message names the field's own label, because the reader is looking at
 * a spreadsheet with a hundred columns and "must be a valid number" on its
 * own tells them nothing about which one.
 */
final class Check {

	/**
	 * Check one value against its declaration.
	 *
	 * @param string               $key   The field key.
	 * @param mixed                $value The value, already cast.
	 * @param array<string, mixed> $field The field's declaration.
	 *
	 * @return WP_Error|null Null when there is nothing wrong with it.
	 */
	public static function value( string $key, mixed $value, array $field ): ?WP_Error {
		$label = (string) ( $field['label'] ?? $key );

		if ( self::is_missing( $value ) ) {
			if ( empty( $field['required'] ) ) {
				return null;
			}

			/* translators: %s: the column's name. */
			return self::error( 'required_field', __( '%s is required.', 'arraypress' ), $label );
		}

		return self::of_type( (string) ( $field['type'] ?? 'string' ), $value, $field, $label )
			?? self::within_range( $value, $field, $label )
			?? self::within_length( $value, $field, $label )
			?? self::among_options( $value, $field, $label )
			?? self::matching_pattern( $value, $field, $label );
	}

	/**
	 * Whether there is no value at all.
	 *
	 * Nought is a value. A price of nought, a quantity of nought and a rate
	 * of nought are all things a file can legitimately say, and treating them
	 * as absent is how a required field rejects a row that filled it in.
	 *
	 * @param mixed $value The value.
	 *
	 * @return bool
	 */
	public static function is_missing( mixed $value ): bool {
		return null === $value || '' === $value || ( is_array( $value ) && [] === $value );
	}

	/**
	 * Whether the value is what its type says it is.
	 *
	 * @param string               $type  The declared type.
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field The field's declaration.
	 * @param string               $label The field's label.
	 *
	 * @return WP_Error|null
	 */
	private static function of_type( string $type, mixed $value, array $field, string $label ): ?WP_Error {
		// A separated field holds a list, and every item in it is of the
		// type. Checking the array itself would report a perfectly good list
		// of email addresses as not being an email address.
		if ( is_array( $value ) ) {
			foreach ( $value as $item ) {
				$error = self::of_type( $type, $item, $field, $label );

				if ( null !== $error ) {
					return $error;
				}
			}

			return null;
		}

		switch ( $type ) {
			case 'number':
				/* translators: %s: the column's name. */
				return is_numeric( $value ) ? null : self::error( 'invalid_number', __( '%s must be a number.', 'arraypress' ), $label );

			case 'integer':
				/* translators: %s: the column's name. */
				$message = __( '%s must be a whole number.', 'arraypress' );

				return is_int( $value ) || ctype_digit( ltrim( (string) $value, '-' ) )
					? null
					: self::error( 'invalid_integer', $message, $label );

			case 'email':
				/* translators: %s: the column's name. */
				return is_email( (string) $value ) ? null : self::error( 'invalid_email', __( '%s must be an email address.', 'arraypress' ), $label );

			case 'url':
				/* translators: %s: the column's name. */
				$message = __( '%s must be a URL.', 'arraypress' );

				return false !== filter_var( (string) $value, FILTER_VALIDATE_URL )
					? null
					: self::error( 'invalid_url', $message, $label );

			case 'currency':
				/* translators: %s: the column's name. */
				$message = __( '%s must be a currency code, such as USD, EUR or GBP.', 'arraypress' );

				return self::supported( 'ArrayPress\\Currencies\\Currency', 'is_supported', (string) $value )
					? null
					: self::error( 'invalid_currency', $message, $label );

			case 'country':
				/* translators: %s: the column's name. */
				$message = __( '%s must be a two-letter country code, such as US, GB or DE.', 'arraypress' );

				return self::supported( 'ArrayPress\\Countries\\Countries', 'exists', (string) $value )
					? null
					: self::error( 'invalid_country', $message, $label );

			case 'date':
			case 'datetime':
				return self::is_a_date( (string) $value, $type, $field, $label );
		}

		return null;
	}

	/**
	 * Whether a date cast into shape.
	 *
	 * Cast hands a value back untouched when it could not read it, so the
	 * test is whether it now looks like what Cast writes.
	 *
	 * @param string               $value The value.
	 * @param string               $type  date or datetime.
	 * @param array<string, mixed> $field The field's declaration.
	 * @param string               $label The field's label.
	 *
	 * @return WP_Error|null
	 */
	private static function is_a_date( string $value, string $type, array $field, string $label ): ?WP_Error {
		$format = 'date' === $type ? 'Y-m-d' : 'Y-m-d H:i:s';
		$parsed = \DateTimeImmutable::createFromFormat( '!' . $format, $value );

		if ( false !== $parsed && $parsed->format( $format ) === $value ) {
			return null;
		}

		$expected = (string) ( $field['date_format'] ?? '' );

		if ( '' !== $expected ) {
			return self::error(
				'invalid_date_format',
				/* translators: 1: the field's label, 2: the date format it must be written in. */
				__( '%1$s must be written as %2$s.', 'arraypress' ),
				$label,
				$expected
			);
		}

		if ( 'date' === $type ) {
			/* translators: %s: the column's name. */
			return self::error( 'invalid_date', __( '%s must be a date.', 'arraypress' ), $label );
		}

		/* translators: %s: the column's name. */
		return self::error( 'invalid_datetime', __( '%s must be a date and a time.', 'arraypress' ), $label );
	}

	/**
	 * Whether a number is within the range the field declared.
	 *
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field The field's declaration.
	 * @param string               $label The field's label.
	 *
	 * @return WP_Error|null
	 */
	private static function within_range( mixed $value, array $field, string $label ): ?WP_Error {
		if ( ! is_numeric( $value ) ) {
			return null;
		}

		if ( isset( $field['minimum'] ) && (float) $value < (float) $field['minimum'] ) {
			return self::error(
				'below_minimum',
				/* translators: 1: the field's label, 2: the smallest value allowed. */
				__( '%1$s must be at least %2$s.', 'arraypress' ),
				$label,
				(string) $field['minimum']
			);
		}

		if ( isset( $field['maximum'] ) && (float) $value > (float) $field['maximum'] ) {
			return self::error(
				'above_maximum',
				/* translators: 1: the field's label, 2: the largest value allowed. */
				__( '%1$s must be no more than %2$s.', 'arraypress' ),
				$label,
				(string) $field['maximum']
			);
		}

		return null;
	}

	/**
	 * Whether a string is as long as the field declared.
	 *
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field The field's declaration.
	 * @param string               $label The field's label.
	 *
	 * @return WP_Error|null
	 */
	private static function within_length( mixed $value, array $field, string $label ): ?WP_Error {
		if ( ! is_string( $value ) ) {
			return null;
		}

		$length = mb_strlen( $value );

		if ( isset( $field['min_length'] ) && $length < (int) $field['min_length'] ) {
			return self::error(
				'too_short',
				/* translators: 1: the field's label, 2: the fewest characters allowed. */
				__( '%1$s must be at least %2$s characters.', 'arraypress' ),
				$label,
				number_format_i18n( (int) $field['min_length'] )
			);
		}

		if ( isset( $field['max_length'] ) && $length > (int) $field['max_length'] ) {
			return self::error(
				'too_long',
				/* translators: 1: the field's label, 2: the most characters allowed. */
				__( '%1$s must be no more than %2$s characters.', 'arraypress' ),
				$label,
				number_format_i18n( (int) $field['max_length'] )
			);
		}

		return null;
	}

	/**
	 * Whether the value is one the field allows.
	 *
	 * Loosely compared. The declaration is written by hand and the value came
	 * out of a text file, so `'1'` and `1` are the same answer — comparing
	 * them strictly rejects a row for having been read from a CSV.
	 *
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field The field's declaration.
	 * @param string               $label The field's label.
	 *
	 * @return WP_Error|null
	 */
	private static function among_options( mixed $value, array $field, string $label ): ?WP_Error {
		$options = $field['options'] ?? null;

		if ( ! is_array( $options ) || [] === $options ) {
			return null;
		}

		foreach ( is_array( $value ) ? $value : [ $value ] as $item ) {
			// phpcs:ignore WordPress.PHP.StrictInArray.FoundNonStrictFalse -- see the docblock.
			if ( ! in_array( $item, $options, false ) ) {
				return self::error(
					'invalid_option',
					/* translators: 1: the field's label, 2: the values it accepts. */
					__( '%1$s must be one of: %2$s.', 'arraypress' ),
					$label,
					implode( ', ', array_map( 'strval', $options ) )
				);
			}
		}

		return null;
	}

	/**
	 * Whether the value matches the field's pattern.
	 *
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field The field's declaration.
	 * @param string               $label The field's label.
	 *
	 * @return WP_Error|null
	 */
	private static function matching_pattern( mixed $value, array $field, string $label ): ?WP_Error {
		$pattern = (string) ( $field['pattern'] ?? '' );

		if ( '' === $pattern || ! is_string( $value ) ) {
			return null;
		}

		// A pattern that will not compile is the plugin author's mistake, not
		// the file's, and reporting it against every row of the import would
		// bury it. Said once, where a developer will see it.
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- preg_match() warns on a pattern that will not compile; the warning is turned into the _doing_it_wrong() below, which says which field it was.
		$matched = @preg_match( $pattern, $value );

		if ( false === $matched ) {
			_doing_it_wrong(
				__METHOD__,
				sprintf(
					/* translators: %s: the field's label. */
					esc_html__( 'The pattern declared for %s is not a valid regular expression, so nothing was checked against it.', 'arraypress' ),
					esc_html( $label )
				),
				'3.0.0'
			);

			return null;
		}

		if ( 1 === $matched ) {
			return null;
		}

		/* translators: %s: the column's name. */
		return self::error( 'invalid_pattern', __( '%s is not in the expected format.', 'arraypress' ), $label );
	}

	/**
	 * Whether an optional dependency says a code is real.
	 *
	 * The currency and country lists are libraries of their own, suggested
	 * rather than required — an importer of blog posts should not pull in a
	 * table of every ISO currency. Absent, the code is accepted as given,
	 * which is what the field would have got as a plain string anyway.
	 *
	 * @param string $holder The class that holds the list.
	 * @param string $method What to ask it.
	 * @param string $code   The code.
	 *
	 * @return bool
	 */
	private static function supported( string $holder, string $method, string $code ): bool {
		return ! method_exists( $holder, $method ) || (bool) $holder::$method( $code );
	}

	/**
	 * Build one message.
	 *
	 * @param string $code     The error code.
	 * @param string $template The message, already translated.
	 * @param string ...$parts What goes in it.
	 *
	 * @return WP_Error
	 */
	private static function error( string $code, string $template, string ...$parts ): WP_Error {
		// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the template arrived translated.
		return new WP_Error( $code, vsprintf( $template, $parts ) );
	}
}
