<?php
/**
 * Field Validator
 *
 * Handles field-level validation, transformation, and WordPress entity
 * resolution for CSV import rows based on declarative field definitions.
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Validation;

use WP_Error;

/**
 * Class FieldValidator
 *
 * Processes and validates import row data against field definitions.
 *
 * Processing order per field:
 * 1. Trim whitespace
 * 2. Apply default value (if empty)
 * 3. Transform (uppercase, lowercase)
 * 4. Type cast (number, integer, boolean)
 * 5. Built-in validation (required, minimum, maximum, etc.)
 * 6. Custom validate_callback
 * 7. Custom process_callback / WordPress type resolution
 */
class FieldValidator {

	/**
	 * Scalar field types that perform casting and format validation.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	const SCALAR_TYPES = [ 'string', 'number', 'integer', 'boolean', 'url', 'email', 'currency' ];

	/**
	 * WordPress entity types that resolve to IDs.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	const WP_TYPES = [ 'post', 'term', 'user', 'attachment' ];

	/**
	 * Valid ISO 4217 currency codes.
	 *
	 * @since 2.0.0
	 * @var array
	 */
	const CURRENCY_CODES = [
		'AED',
		'AFN',
		'ALL',
		'AMD',
		'ANG',
		'AOA',
		'ARS',
		'AUD',
		'AWG',
		'AZN',
		'BAM',
		'BBD',
		'BDT',
		'BGN',
		'BHD',
		'BIF',
		'BMD',
		'BND',
		'BOB',
		'BRL',
		'BSD',
		'BTN',
		'BWP',
		'BYN',
		'BZD',
		'CAD',
		'CDF',
		'CHF',
		'CLP',
		'CNY',
		'COP',
		'CRC',
		'CUP',
		'CVE',
		'CZK',
		'DJF',
		'DKK',
		'DOP',
		'DZD',
		'EGP',
		'ERN',
		'ETB',
		'EUR',
		'FJD',
		'FKP',
		'GBP',
		'GEL',
		'GHS',
		'GIP',
		'GMD',
		'GNF',
		'GTQ',
		'GYD',
		'HKD',
		'HNL',
		'HRK',
		'HTG',
		'HUF',
		'IDR',
		'ILS',
		'INR',
		'IQD',
		'IRR',
		'ISK',
		'JMD',
		'JOD',
		'JPY',
		'KES',
		'KGS',
		'KHR',
		'KMF',
		'KPW',
		'KRW',
		'KWD',
		'KYD',
		'KZT',
		'LAK',
		'LBP',
		'LKR',
		'LRD',
		'LSL',
		'LYD',
		'MAD',
		'MDL',
		'MGA',
		'MKD',
		'MMK',
		'MNT',
		'MOP',
		'MRU',
		'MUR',
		'MVR',
		'MWK',
		'MXN',
		'MYR',
		'MZN',
		'NAD',
		'NGN',
		'NIO',
		'NOK',
		'NPR',
		'NZD',
		'OMR',
		'PAB',
		'PEN',
		'PGK',
		'PHP',
		'PKR',
		'PLN',
		'PYG',
		'QAR',
		'RON',
		'RSD',
		'RUB',
		'RWF',
		'SAR',
		'SBD',
		'SCR',
		'SDG',
		'SEK',
		'SGD',
		'SHP',
		'SLE',
		'SOS',
		'SRD',
		'SSP',
		'STN',
		'SVC',
		'SYP',
		'SZL',
		'THB',
		'TJS',
		'TMT',
		'TND',
		'TOP',
		'TRY',
		'TTD',
		'TWD',
		'TZS',
		'UAH',
		'UGX',
		'USD',
		'UYU',
		'UZS',
		'VES',
		'VND',
		'VUV',
		'WST',
		'XAF',
		'XCD',
		'XOF',
		'XPF',
		'YER',
		'ZAR',
		'ZMW',
		'ZWL',
	];

	/**
	 * Process a single field value through the full validation pipeline.
	 *
	 * @param string $key   The field key.
	 * @param mixed  $value The raw value from CSV.
	 * @param array  $field The field definition.
	 * @param array  $row   The full mapped row (for cross-field references).
	 *
	 * @return mixed|WP_Error The processed value or WP_Error on failure.
	 * @since 2.0.0
	 *
	 */
	public static function process_field( string $key, $value, array $field, array $row = [] ) {
		$type = $field['type'] ?? 'string';

		// Step 1: Trim
		if ( is_string( $value ) ) {
			$value = trim( $value );
		}

		// Step 2: Default
		if ( ( $value === null || $value === '' ) && isset( $field['default'] ) ) {
			$value = $field['default'];
		}

		// Step 3: Transform
		$value = self::apply_transforms( $value, $field );

		// Step 4: Separator (split into array before further processing)
		if ( ! empty( $field['separator'] ) && is_string( $value ) && $value !== '' ) {
			$value = self::split_value( $value, $field['separator'] );
		}

		// Step 5: Type cast (scalar types only)
		if ( in_array( $type, self::SCALAR_TYPES, true ) && ! is_array( $value ) ) {
			$value = self::cast_type( $value, $type );
		}

		// Step 6: Built-in validation
		$validation = self::validate_field( $key, $value, $field );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Step 7: Custom validate_callback
		if ( isset( $field['validate_callback'] ) && is_callable( $field['validate_callback'] ) ) {
			$result = call_user_func( $field['validate_callback'], $value, $row );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Step 8: Custom process_callback
		if ( isset( $field['process_callback'] ) && is_callable( $field['process_callback'] ) ) {
			$value = call_user_func( $field['process_callback'], $value, $row );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}

		// Step 9: WordPress type resolution
		if ( in_array( $type, self::WP_TYPES, true ) ) {
			$value = self::resolve_wp_type( $value, $field );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
		}

		return $value;
	}

	/**
	 * Process an entire row through field validation.
	 *
	 * @param array $row    The mapped row data.
	 * @param array $fields The field definitions.
	 *
	 * @return array|WP_Error The processed row or WP_Error on first failure.
	 * @since 2.0.0
	 *
	 */
	public static function process_row( array $row, array $fields ): array|WP_Error {
		$processed = [];

		foreach ( $fields as $key => $field ) {
			$value  = $row[ $key ] ?? null;
			$result = self::process_field( $key, $value, $field, $row );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$processed[ $key ] = $result;
		}

		return $processed;
	}

	/**
	 * Validate a row without processing (dry run mode).
	 *
	 * Runs all validation steps but skips process_callback and
	 * WordPress type resolution to avoid side effects.
	 *
	 * @param array $row    The mapped row data.
	 * @param array $fields The field definitions.
	 *
	 * @return true|WP_Error True if valid, WP_Error on first failure.
	 * @since 2.0.0
	 *
	 */
	public static function validate_row( array $row, array $fields ) {
		foreach ( $fields as $key => $field ) {
			$value = $row[ $key ] ?? null;
			$type  = $field['type'] ?? 'string';

			// Trim
			if ( is_string( $value ) ) {
				$value = trim( $value );
			}

			// Default
			if ( ( $value === null || $value === '' ) && isset( $field['default'] ) ) {
				$value = $field['default'];
			}

			// Transform
			$value = self::apply_transforms( $value, $field );

			// Separator
			if ( ! empty( $field['separator'] ) && is_string( $value ) && $value !== '' ) {
				$value = self::split_value( $value, $field['separator'] );
			}

			// Type cast
			if ( in_array( $type, self::SCALAR_TYPES, true ) && ! is_array( $value ) ) {
				$value = self::cast_type( $value, $type );
			}

			// Built-in validation
			$validation = self::validate_field( $key, $value, $field );
			if ( is_wp_error( $validation ) ) {
				return $validation;
			}

			// Custom validate_callback
			if ( isset( $field['validate_callback'] ) && is_callable( $field['validate_callback'] ) ) {
				$result = call_user_func( $field['validate_callback'], $value, $row );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
			}
		}

		return true;
	}

	/**
	 * Check for duplicate values within a dataset for fields marked as unique.
	 *
	 * @param array $rows   All rows to check.
	 * @param array $fields Field definitions.
	 *
	 * @return array Array of errors, empty if no duplicates found.
	 * @since 2.0.0
	 *
	 */
	public static function check_duplicates( array $rows, array $fields ): array {
		$errors     = [];
		$seen       = [];
		$row_number = 0;

		foreach ( $rows as $row ) {
			$row_number ++;

			foreach ( $fields as $key => $field ) {
				if ( empty( $field['unique'] ) ) {
					continue;
				}

				$value = trim( (string) ( $row[ $key ] ?? '' ) );
				if ( $value === '' ) {
					continue;
				}

				$label = $field['label'] ?? $key;

				if ( isset( $seen[ $key ][ $value ] ) ) {
					$errors[] = [
						'row'     => $row_number,
						'item'    => $value,
						'message' => sprintf(
							__( 'Duplicate %s "%s" (first seen on row %d).', 'arraypress' ),
							$label,
							$value,
							$seen[ $key ][ $value ]
						),
					];
				} else {
					$seen[ $key ][ $value ] = $row_number;
				}
			}
		}

		return $errors;
	}

	/** Transforms **************************************************************/

	/**
	 * Apply transformation rules to a value.
	 *
	 * @param mixed $value The value to transform.
	 * @param array $field The field definition.
	 *
	 * @return mixed The transformed value.
	 * @since 2.0.0
	 *
	 */
	private static function apply_transforms( $value, array $field ) {
		if ( ! is_string( $value ) || $value === '' ) {
			return $value;
		}

		if ( ! empty( $field['uppercase'] ) ) {
			$value = strtoupper( $value );
		}

		if ( ! empty( $field['lowercase'] ) ) {
			$value = strtolower( $value );
		}

		return $value;
	}

	/**
	 * Split a string value by separator into an array.
	 *
	 * @param string $value     The value to split.
	 * @param string $separator The separator character(s).
	 *
	 * @return array Array of trimmed, non-empty values.
	 * @since 2.0.0
	 *
	 */
	private static function split_value( string $value, string $separator ): array {
		// If separator contains multiple characters, try each one
		if ( strlen( $separator ) > 1 ) {
			foreach ( str_split( $separator ) as $sep ) {
				if ( str_contains( $value, $sep ) ) {
					$separator = $sep;
					break;
				}
			}
		}

		return array_values( array_filter( array_map( 'trim', explode( $separator, $value ) ) ) );
	}

	/** Type Casting ************************************************************/

	/**
	 * Cast a value to the specified scalar type.
	 *
	 * @param mixed  $value The value to cast.
	 * @param string $type  The target type.
	 *
	 * @return mixed The cast value.
	 * @since 2.0.0
	 *
	 */
	private static function cast_type( $value, string $type ) {
		if ( $value === null || $value === '' ) {
			return $value;
		}

		switch ( $type ) {
			case 'number':
				$cleaned = str_replace( [ '$', '€', '£', ',', ' ' ], '', (string) $value );

				return is_numeric( $cleaned ) ? (float) $cleaned : $value;

			case 'integer':
				$cleaned = str_replace( [ ',', ' ' ], '', (string) $value );

				return is_numeric( $cleaned ) ? (int) $cleaned : $value;

			case 'boolean':
				return self::cast_boolean( $value );

			case 'currency':
				return strtoupper( trim( (string) $value ) );

			case 'url':
			case 'email':
			case 'string':
			default:
				return (string) $value;
		}
	}

	/**
	 * Cast a value to boolean.
	 *
	 * Handles common truthy/falsy strings from CSV data.
	 *
	 * @param mixed $value The value to cast.
	 *
	 * @return bool
	 * @since 2.0.0
	 *
	 */
	private static function cast_boolean( $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}

		$truthy = [ '1', 'true', 'yes', 'on', 'y' ];

		return in_array( strtolower( trim( (string) $value ) ), $truthy, true );
	}

	/** Validation **************************************************************/

	/**
	 * Run built-in validation rules against a field value.
	 *
	 * @param string $key   The field key.
	 * @param mixed  $value The value to validate.
	 * @param array  $field The field definition.
	 *
	 * @return true|WP_Error True if valid, WP_Error on failure.
	 * @since 2.0.0
	 *
	 */
	private static function validate_field( string $key, $value, array $field ) {
		$label = $field['label'] ?? $key;
		$type  = $field['type'] ?? 'string';

		// Required check
		if ( ! empty( $field['required'] ) ) {
			if ( $value === null || $value === '' || ( is_array( $value ) && empty( $value ) ) ) {
				return new WP_Error(
					'required_field',
					sprintf( __( '%s is required.', 'arraypress' ), $label )
				);
			}
		}

		// Skip further validation for empty optional fields
		if ( $value === null || $value === '' ) {
			return true;
		}

		// Type-specific validation
		switch ( $type ) {
			case 'number':
				if ( ! is_numeric( $value ) ) {
					return new WP_Error(
						'invalid_number',
						sprintf( __( '%s must be a valid number.', 'arraypress' ), $label )
					);
				}
				break;

			case 'integer':
				if ( ! is_int( $value ) && ! ctype_digit( ltrim( (string) $value, '-' ) ) ) {
					return new WP_Error(
						'invalid_integer',
						sprintf( __( '%s must be a whole number.', 'arraypress' ), $label )
					);
				}
				break;

			case 'email':
				if ( ! is_email( $value ) ) {
					return new WP_Error(
						'invalid_email',
						sprintf( __( '%s must be a valid email address.', 'arraypress' ), $label )
					);
				}
				break;

			case 'url':
				if ( ! filter_var( $value, FILTER_VALIDATE_URL ) ) {
					return new WP_Error(
						'invalid_url',
						sprintf( __( '%s must be a valid URL.', 'arraypress' ), $label )
					);
				}
				break;

			case 'currency':
				if ( ! in_array( strtoupper( (string) $value ), self::CURRENCY_CODES, true ) ) {
					return new WP_Error(
						'invalid_currency',
						sprintf( __( '%s must be a valid ISO 4217 currency code (e.g., USD, EUR, GBP).', 'arraypress' ), $label )
					);
				}
				break;
		}

		// Minimum value (for numbers)
		if ( isset( $field['minimum'] ) && is_numeric( $value ) ) {
			if ( (float) $value < (float) $field['minimum'] ) {
				return new WP_Error(
					'below_minimum',
					sprintf(
						__( '%s must be at least %s.', 'arraypress' ),
						$label,
						$field['minimum']
					)
				);
			}
		}

		// Maximum value (for numbers)
		if ( isset( $field['maximum'] ) && is_numeric( $value ) ) {
			if ( (float) $value > (float) $field['maximum'] ) {
				return new WP_Error(
					'above_maximum',
					sprintf(
						__( '%s must be no more than %s.', 'arraypress' ),
						$label,
						$field['maximum']
					)
				);
			}
		}

		// Minimum length (for strings)
		if ( isset( $field['min_length'] ) && is_string( $value ) ) {
			if ( mb_strlen( $value ) < (int) $field['min_length'] ) {
				return new WP_Error(
					'too_short',
					sprintf(
						__( '%s must be at least %d characters.', 'arraypress' ),
						$label,
						$field['min_length']
					)
				);
			}
		}

		// Maximum length (for strings)
		if ( isset( $field['max_length'] ) && is_string( $value ) ) {
			if ( mb_strlen( $value ) > (int) $field['max_length'] ) {
				return new WP_Error(
					'too_long',
					sprintf(
						__( '%s must be no more than %d characters.', 'arraypress' ),
						$label,
						$field['max_length']
					)
				);
			}
		}

		// Options (allowed values)
		if ( isset( $field['options'] ) && is_array( $field['options'] ) ) {
			$check_value = is_array( $value ) ? $value : [ $value ];
			foreach ( $check_value as $item ) {
				if ( ! in_array( $item, $field['options'], true ) ) {
					return new WP_Error(
						'invalid_option',
						sprintf(
							__( '%s must be one of: %s.', 'arraypress' ),
							$label,
							implode( ', ', $field['options'] )
						)
					);
				}
			}
		}

		// Pattern (regex)
		if ( isset( $field['pattern'] ) && is_string( $value ) ) {
			if ( ! preg_match( $field['pattern'], $value ) ) {
				return new WP_Error(
					'invalid_pattern',
					sprintf( __( '%s format is invalid.', 'arraypress' ), $label )
				);
			}
		}

		return true;
	}

	/** WordPress Type Resolution ***********************************************/

	/**
	 * Resolve a value to a WordPress entity ID.
	 *
	 * Handles post, term, user, and attachment lookups with optional
	 * auto-creation for terms.
	 *
	 * @param mixed $value The value to resolve (string or array for separator fields).
	 * @param array $field The field definition.
	 *
	 * @return mixed Resolved ID(s) or WP_Error on failure.
	 * @since 2.0.0
	 *
	 */
	private static function resolve_wp_type( $value, array $field ) {
		if ( $value === null || $value === '' ) {
			return $value;
		}

		// Handle arrays (from separator split)
		if ( is_array( $value ) ) {
			$resolved = [];
			foreach ( $value as $item ) {
				$result = self::resolve_single_wp_entity( $item, $field );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$resolved[] = $result;
			}

			return $resolved;
		}

		return self::resolve_single_wp_entity( $value, $field );
	}

	/**
	 * Resolve a single value to a WordPress entity ID.
	 *
	 * @param mixed $value The value to resolve.
	 * @param array $field The field definition.
	 *
	 * @return int|WP_Error The entity ID or WP_Error.
	 * @since 2.0.0
	 *
	 */
	private static function resolve_single_wp_entity( $value, array $field ): int|WP_Error {
		$type     = $field['type'];
		$match_by = $field['match_by'] ?? 'id';
		$create   = $field['create'] ?? false;
		$label    = $field['label'] ?? $type;

		switch ( $type ) {
			case 'post':
				return self::resolve_post( $value, $field, $match_by, $label );

			case 'term':
				return self::resolve_term( $value, $field, $match_by, $create, $label );

			case 'user':
				return self::resolve_user( $value, $match_by, $label );

			case 'attachment':
				return self::resolve_attachment( $value, $field, $match_by, $label );

			default:
				return new WP_Error(
					'unsupported_type',
					sprintf( __( 'Unsupported WordPress type: %s', 'arraypress' ), $type )
				);
		}
	}

	/**
	 * Resolve a value to a post ID.
	 *
	 * @param mixed  $value    The value to match.
	 * @param array  $field    The field definition.
	 * @param string $match_by How to match (title, slug, id, meta).
	 * @param string $label    The field label for error messages.
	 *
	 * @return int|WP_Error The post ID or WP_Error.
	 * @since 2.0.0
	 *
	 */
	private static function resolve_post( $value, array $field, string $match_by, string $label ): int|WP_Error {
		$post_type = $field['post_type'] ?? 'post';

		switch ( $match_by ) {
			case 'id':
				$post = get_post( (int) $value );
				if ( $post && $post->post_type === $post_type ) {
					return $post->ID;
				}
				break;

			case 'title':
				$posts = get_posts( [
					'post_type'      => $post_type,
					'title'          => $value,
					'posts_per_page' => 1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				] );
				if ( ! empty( $posts ) ) {
					return $posts[0];
				}
				break;

			case 'slug':
				$posts = get_posts( [
					'post_type'      => $post_type,
					'name'           => sanitize_title( $value ),
					'posts_per_page' => 1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				] );
				if ( ! empty( $posts ) ) {
					return $posts[0];
				}
				break;

			case 'meta':
				$meta_key = $field['meta_key'] ?? '';
				if ( empty( $meta_key ) ) {
					return new WP_Error(
						'missing_meta_key',
						sprintf( __( '%s requires a meta_key for matching.', 'arraypress' ), $label )
					);
				}

				$posts = get_posts( [
					'post_type'      => $post_type,
					'meta_key'       => $meta_key,
					'meta_value'     => $value,
					'posts_per_page' => 1,
					'post_status'    => 'any',
					'fields'         => 'ids',
				] );
				if ( ! empty( $posts ) ) {
					return $posts[0];
				}
				break;
		}

		return new WP_Error(
			'post_not_found',
			sprintf( __( '%s "%s" not found.', 'arraypress' ), $label, $value )
		);
	}

	/**
	 * Resolve a value to a term ID, optionally creating it.
	 *
	 * @param mixed  $value    The value to match.
	 * @param array  $field    The field definition.
	 * @param string $match_by How to match (name, slug, id).
	 * @param bool   $create   Whether to create the term if not found.
	 * @param string $label    The field label for error messages.
	 *
	 * @return int|WP_Error The term ID or WP_Error.
	 * @since 2.0.0
	 *
	 */
	private static function resolve_term( $value, array $field, string $match_by, bool $create, string $label ): int|WP_Error {
		$taxonomy = $field['taxonomy'] ?? 'category';

		switch ( $match_by ) {
			case 'id':
				$term = get_term( (int) $value, $taxonomy );
				if ( $term && ! is_wp_error( $term ) ) {
					return $term->term_id;
				}
				break;

			case 'name':
				$term = get_term_by( 'name', $value, $taxonomy );
				if ( $term ) {
					return $term->term_id;
				}
				break;

			case 'slug':
				$term = get_term_by( 'slug', sanitize_title( $value ), $taxonomy );
				if ( $term ) {
					return $term->term_id;
				}
				break;
		}

		// Auto-create if enabled
		if ( $create && ! empty( $value ) ) {
			$result = wp_insert_term( (string) $value, $taxonomy );
			if ( is_wp_error( $result ) ) {
				// If term exists error, get the existing term
				if ( $result->get_error_code() === 'term_exists' ) {
					return (int) $result->get_error_data();
				}

				return $result;
			}

			return $result['term_id'];
		}

		return new WP_Error(
			'term_not_found',
			sprintf( __( '%s "%s" not found.', 'arraypress' ), $label, $value )
		);
	}

	/**
	 * Resolve a value to a user ID.
	 *
	 * @param mixed  $value    The value to match.
	 * @param string $match_by How to match (email, login, id, slug).
	 * @param string $label    The field label for error messages.
	 *
	 * @return int|WP_Error The user ID or WP_Error.
	 * @since 2.0.0
	 *
	 */
	private static function resolve_user( $value, string $match_by, string $label ): int|WP_Error {
		$user = null;

		switch ( $match_by ) {
			case 'id':
				$user = get_user_by( 'id', (int) $value );
				break;

			case 'email':
				$user = get_user_by( 'email', $value );
				break;

			case 'login':
				$user = get_user_by( 'login', $value );
				break;

			case 'slug':
				$user = get_user_by( 'slug', $value );
				break;
		}

		if ( $user ) {
			return $user->ID;
		}

		return new WP_Error(
			'user_not_found',
			sprintf( __( '%s "%s" not found.', 'arraypress' ), $label, $value )
		);
	}

	/**
	 * Resolve a value to an attachment ID.
	 *
	 * @param mixed  $value    The value to match.
	 * @param array  $field    The field definition.
	 * @param string $match_by How to match (url, id, filename).
	 * @param string $label    The field label for error messages.
	 *
	 * @return int|WP_Error The attachment ID or WP_Error.
	 * @since 2.0.0
	 *
	 */
	private static function resolve_attachment( $value, array $field, string $match_by, string $label ): int|WP_Error {
		switch ( $match_by ) {
			case 'id':
				$post = get_post( (int) $value );
				if ( $post && $post->post_type === 'attachment' ) {
					return $post->ID;
				}
				break;

			case 'url':
				$attachment_id = attachment_url_to_postid( $value );
				if ( $attachment_id ) {
					return $attachment_id;
				}

				// Sideload remote image if enabled
				if ( ! empty( $field['sideload'] ) && filter_var( $value, FILTER_VALIDATE_URL ) ) {
					return self::sideload_image( $value );
				}
				break;

			case 'filename':
				global $wpdb;
				$attachment_id = $wpdb->get_var( $wpdb->prepare(
					"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'attachment' AND guid LIKE %s",
					'%' . $wpdb->esc_like( $value )
				) );
				if ( $attachment_id ) {
					return (int) $attachment_id;
				}
				break;
		}

		return new WP_Error(
			'attachment_not_found',
			sprintf( __( '%s "%s" not found.', 'arraypress' ), $label, $value )
		);
	}

	/**
	 * Sideload a remote image into the WordPress media library.
	 *
	 * @param string $url The remote image URL.
	 *
	 * @return int|WP_Error The attachment ID or WP_Error.
	 * @since 2.0.0
	 *
	 */
	private static function sideload_image( string $url ): int|WP_Error {
		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_id = media_sideload_image( $url, 0, null, 'id' );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return (int) $attachment_id;
	}

	/** Sample CSV **************************************************************/

	/**
	 * Generate sample CSV content from field definitions.
	 *
	 * Creates a CSV string with headers and one example row
	 * based on field types, defaults, and options.
	 *
	 * @param array $fields The field definitions.
	 *
	 * @return string CSV content string.
	 * @since 2.0.0
	 *
	 */
	public static function generate_sample_csv( array $fields ): string {
		$headers  = [];
		$examples = [];

		foreach ( $fields as $key => $field ) {
			$headers[] = $field['label'] ?? $key;

			// Generate example value based on field type and config
			$examples[] = self::get_example_value( $key, $field );
		}

		$output = fopen( 'php://temp', 'r+' );
		fputcsv( $output, $headers );
		fputcsv( $output, $examples );
		rewind( $output );
		$csv = stream_get_contents( $output );
		fclose( $output );

		return $csv;
	}

	/**
	 * Generate an example value for a field.
	 *
	 * @param string $key   The field key.
	 * @param array  $field The field definition.
	 *
	 * @return string The example value.
	 * @since 2.0.0
	 *
	 */
	private static function get_example_value( string $key, array $field ): string {
		// Use default if set
		if ( isset( $field['default'] ) && $field['default'] !== '' ) {
			return (string) $field['default'];
		}

		// Use first option if available
		if ( ! empty( $field['options'] ) ) {
			return (string) $field['options'][0];
		}

		$type = $field['type'] ?? 'string';

		// Type-based examples
		switch ( $type ) {
			case 'number':
				return $field['minimum'] ?? '9.99';
			case 'integer':
				return $field['minimum'] ?? '1';
			case 'boolean':
				return 'true';
			case 'email':
				return 'user@example.com';
			case 'url':
				return 'https://example.com/image.jpg';
			case 'currency':
				return 'USD';
			case 'post':
				return $field['match_by'] === 'title' ? 'My Post Title' : '1';
			case 'term':
				return $field['match_by'] === 'name' ? 'Category Name' : '1';
			case 'user':
				return $field['match_by'] === 'email' ? 'user@example.com' : '1';
			case 'attachment':
				return $field['match_by'] === 'url' ? 'https://example.com/image.jpg' : '1';
		}

		// Key-based guesses
		$key_lower = strtolower( $key );
		if ( str_contains( $key_lower, 'name' ) ) {
			return 'Example Name';
		}
		if ( str_contains( $key_lower, 'description' ) ) {
			return 'A brief description of the item.';
		}
		if ( str_contains( $key_lower, 'email' ) ) {
			return 'user@example.com';
		}
		if ( str_contains( $key_lower, 'url' ) || str_contains( $key_lower, 'image' ) ) {
			return 'https://example.com/image.jpg';
		}
		if ( str_contains( $key_lower, 'price' ) || str_contains( $key_lower, 'amount' ) ) {
			return '9.99';
		}

		return 'Example';
	}

}
