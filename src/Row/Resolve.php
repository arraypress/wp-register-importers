<?php
/**
 * Resolve
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Row;

use WP_Error;
use WP_Post;

/**
 * Turning what a file calls something into what WordPress calls it.
 *
 * A CSV says `Category: Widgets` and a post needs a term id; it says
 * `Author: jane@example.test` and a post needs a user id. Nobody exports the
 * ids, because the ids are meaningless in the system the file came from.
 *
 * So each type is looked up by whatever the cell looks like — an id, a slug,
 * a title, an email address — and a field that knows which of those its file
 * holds can say so with `match_by` and skip the guessing.
 *
 * Two things worth knowing:
 *
 * **The same value is looked up once.** A file of ten thousand products with
 * six categories between them asked the database ten thousand times. The
 * answers are memoised for the request, which is the length of one batch.
 *
 * **A dry run resolves but does not create.** A missing term is reported
 * rather than made, and a field that *would* create it is told so rather
 * than being called an error — which is what makes the dry run's answer the
 * same answer the import gives.
 */
final class Resolve {

	/**
	 * The types that resolve to an id.
	 *
	 * @var string[]
	 */
	public const TYPES = [ 'post', 'term', 'user', 'attachment' ];

	/**
	 * What has already been looked up this request.
	 *
	 * @var array<string, int|null>
	 */
	private static array $memo = [];

	/**
	 * Resolve a value, or a list of them.
	 *
	 * @param mixed                $value  The value, already cast.
	 * @param array<string, mixed> $field  The field's declaration.
	 * @param bool                 $commit Whether this is the real thing.
	 *
	 * @return int|int[]|null|WP_Error
	 */
	public static function entity( mixed $value, array $field, bool $commit = true ): mixed {
		if ( Check::is_missing( $value ) ) {
			return null;
		}

		if ( is_array( $value ) ) {
			$ids = [];

			foreach ( $value as $one ) {
				$id = self::one( $one, $field, $commit );

				if ( $id instanceof WP_Error ) {
					return $id;
				}

				$ids[] = $id;
			}

			return $ids;
		}

		return self::one( $value, $field, $commit );
	}

	/**
	 * Forget what was looked up.
	 *
	 * Between batches, and between tests. A term created by an earlier batch
	 * is a term this one should find.
	 *
	 * @return void
	 */
	public static function forget(): void {
		self::$memo = [];
	}

	/**
	 * Resolve one value.
	 *
	 * @param mixed                $value  The value.
	 * @param array<string, mixed> $field  The field's declaration.
	 * @param bool                 $commit Whether this is the real thing.
	 *
	 * @return int|WP_Error
	 */
	private static function one( mixed $value, array $field, bool $commit ): int|WP_Error {
		$type     = (string) ( $field['type'] ?? '' );
		$match_by = (string) ( $field['match_by'] ?? 'identifier' );
		$label    = (string) ( $field['label'] ?? $type );

		$memo_key = implode(
			'|',
			[
				$type,
				$match_by,
				(string) ( $field['post_type'] ?? '' ),
				(string) ( $field['taxonomy'] ?? '' ),
				(string) ( $field['meta_key'] ?? '' ),
				(string) $value,
			]
		);

		if ( array_key_exists( $memo_key, self::$memo ) ) {
			$found = self::$memo[ $memo_key ];

			if ( null !== $found ) {
				return $found;
			}
		} else {
			$found = match ( $type ) {
				'post'       => self::post( $value, $field, $match_by ),
				'term'       => self::term( $value, $field, $match_by ),
				'user'       => self::user( $value, $match_by ),
				'attachment' => self::attachment( $value, $field, $match_by ),
				default      => null,
			};

			if ( $found instanceof WP_Error ) {
				return $found;
			}

			self::$memo[ $memo_key ] = $found;

			if ( null !== $found ) {
				return $found;
			}
		}

		// Nothing found. A term the field is willing to create is the one
		// case that is not yet a failure.
		if ( 'term' === $type && ! empty( $field['create'] ) ) {
			if ( ! $commit ) {
				// The dry run's answer for "this will be made": not an id,
				// and not an error either.
				return 0;
			}

			$made = self::create_term( $value, $field );

			if ( ! $made instanceof WP_Error ) {
				self::$memo[ $memo_key ] = $made;
			}

			return $made;
		}

		return new WP_Error(
			$type . '_not_found',
			sprintf(
				/* translators: 1: the field's label, 2: the value found in the file. */
				__( '%1$s "%2$s" was not found.', 'arraypress' ),
				$label,
				(string) $value
			)
		);
	}

	/**
	 * Find a post.
	 *
	 * @param mixed                $value    The value.
	 * @param array<string, mixed> $field    The field's declaration.
	 * @param string               $match_by How to look.
	 *
	 * @return int|null|WP_Error
	 */
	private static function post( mixed $value, array $field, string $match_by ): int|null|WP_Error {
		$post_type = (string) ( $field['post_type'] ?? 'post' );

		$args = [
			'post_type'      => $post_type,
			'post_status'    => $field['post_status'] ?? 'any',
			'posts_per_page' => 1,
			'no_found_rows'  => true,
			'fields'         => 'ids',
		];

		$by_id = static function () use ( $value, $post_type ): ?int {
			if ( ! is_numeric( $value ) ) {
				return null;
			}

			$post = get_post( (int) $value );

			return $post instanceof WP_Post && ( 'any' === $post_type || $post->post_type === $post_type )
				? $post->ID
				: null;
		};

		$first = static function ( array $query ): ?int {
			$ids = get_posts( $query );

			return [] === $ids ? null : (int) $ids[0];
		};

		return match ( $match_by ) {
			'id'    => $by_id(),
			'slug'  => $first( array_merge( $args, [ 'name' => sanitize_title( (string) $value ) ] ) ),
			'title' => $first( array_merge( $args, [ 'title' => (string) $value ] ) ),
			'meta'  => self::post_by_meta( $value, $field, $args, $first ),

			// Whatever it looks like, in the order that is cheapest and most
			// certain first: an id is exact, a slug is unique, a title is
			// neither and is tried last.
			default => $by_id()
				?? $first( array_merge( $args, [ 'name' => sanitize_title( (string) $value ) ] ) )
				?? $first( array_merge( $args, [ 'title' => (string) $value ] ) ),
		};
	}

	/**
	 * Find a post by one of its meta values.
	 *
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field The field's declaration.
	 * @param array<string, mixed> $args  The base query.
	 * @param callable             $first Runs a query and returns the first id.
	 *
	 * @return int|null|WP_Error
	 */
	private static function post_by_meta( mixed $value, array $field, array $args, callable $first ): int|null|WP_Error {
		$meta_key = (string) ( $field['meta_key'] ?? '' );

		if ( '' === $meta_key ) {
			return new WP_Error(
				'missing_meta_key',
				sprintf(
					/* translators: %s: the field's label. */
					__( '%s is matched on a meta value but does not say which meta key.', 'arraypress' ),
					(string) ( $field['label'] ?? 'Field' )
				)
			);
		}

		return $first(
			array_merge(
				$args,
				[
					// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- matching an imported row against a stored key is exactly this query; the answers are memoised so it runs once per distinct value.
					'meta_query' => [
						[
							'key'     => $meta_key,
							'value'   => (string) $value,
							'compare' => '=',
						],
					],
				]
			)
		);
	}

	/**
	 * Find a term.
	 *
	 * @param mixed                $value    The value.
	 * @param array<string, mixed> $field    The field's declaration.
	 * @param string               $match_by How to look.
	 *
	 * @return int|null
	 */
	private static function term( mixed $value, array $field, string $match_by ): ?int {
		$taxonomy = (string) ( $field['taxonomy'] ?? 'category' );

		$by = static function ( string $by, mixed $needle ) use ( $taxonomy ): ?int {
			$term = get_term_by( $by, $needle, $taxonomy );

			return $term && ! is_wp_error( $term ) ? (int) $term->term_id : null;
		};

		return match ( $match_by ) {
			'id'    => is_numeric( $value ) ? $by( 'id', (int) $value ) : null,
			'slug'  => $by( 'slug', sanitize_title( (string) $value ) ),
			'name'  => $by( 'name', (string) $value ),
			default => ( is_numeric( $value ) ? $by( 'id', (int) $value ) : null )
				?? $by( 'slug', sanitize_title( (string) $value ) )
				?? $by( 'name', (string) $value ),
		};
	}

	/**
	 * Make a term the file named and the site does not have.
	 *
	 * @param mixed                $value The value.
	 * @param array<string, mixed> $field The field's declaration.
	 *
	 * @return int|WP_Error
	 */
	private static function create_term( mixed $value, array $field ): int|WP_Error {
		$made = wp_insert_term( (string) $value, (string) ( $field['taxonomy'] ?? 'category' ) );

		if ( is_wp_error( $made ) ) {
			// Two batches importing the same new category at once: the second
			// insert loses the race and comes back with the id of the term
			// the first one made, which is the answer either way.
			$existing = $made->get_error_data( 'term_exists' );

			return null === $existing ? $made : (int) $existing;
		}

		return (int) $made['term_id'];
	}

	/**
	 * Find a user.
	 *
	 * @param mixed  $value    The value.
	 * @param string $match_by How to look.
	 *
	 * @return int|null
	 */
	private static function user( mixed $value, string $match_by ): ?int {
		$by = static function ( string $by, mixed $needle ): ?int {
			$user = get_user_by( $by, $needle );

			return $user ? (int) $user->ID : null;
		};

		return match ( $match_by ) {
			'id'    => is_numeric( $value ) ? $by( 'id', (int) $value ) : null,
			'email' => $by( 'email', (string) $value ),
			'login' => $by( 'login', (string) $value ),
			'slug'  => $by( 'slug', (string) $value ),
			default => ( is_numeric( $value ) ? $by( 'id', (int) $value ) : null )
				?? ( is_email( (string) $value ) ? $by( 'email', (string) $value ) : null )
				?? $by( 'login', (string) $value )
				?? $by( 'slug', (string) $value ),
		};
	}

	/**
	 * Find an attachment.
	 *
	 * @param mixed                $value    The value.
	 * @param array<string, mixed> $field    The field's declaration.
	 * @param string               $match_by How to look.
	 *
	 * @return int|null
	 */
	private static function attachment( mixed $value, array $field, string $match_by ): ?int {
		$by_id = static function () use ( $value ): ?int {
			return is_numeric( $value ) && 'attachment' === get_post_type( (int) $value ) ? (int) $value : null;
		};

		$by_url = static function () use ( $value ): ?int {
			$id = attachment_url_to_postid( (string) $value );

			return 0 === $id ? null : $id;
		};

		$by_filename = static fn(): ?int => self::attachment_by_filename( basename( (string) $value ) );

		return match ( $match_by ) {
			'id'       => $by_id(),
			'url'      => $by_url(),
			'filename' => $by_filename(),
			default    => $by_id() ?? $by_url() ?? $by_filename(),
		};
	}

	/**
	 * Find an attachment by the name of its file.
	 *
	 * @param string $filename The file name.
	 *
	 * @return int|null
	 */
	private static function attachment_by_filename( string $filename ): ?int {
		if ( '' === $filename ) {
			return null;
		}

		$ids = get_posts(
			[
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'posts_per_page' => 1,
				'no_found_rows'  => true,
				'fields'         => 'ids',
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- _wp_attached_file is how core stores the path; there is no other way to ask this, and the answers are memoised.
				'meta_query'     => [
					[
						'key'     => '_wp_attached_file',
						'value'   => $filename,
						'compare' => 'LIKE',
					],
				],
			]
		);

		return [] === $ids ? null : (int) $ids[0];
	}
}
