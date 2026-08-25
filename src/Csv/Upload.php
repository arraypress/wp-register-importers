<?php
/**
 * Upload
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Csv;

use ArrayPress\RegisterImporters\Utils\Runtime;
use WP_Error;

/**
 * Taking a CSV file off somebody and putting it somewhere it can be read from.
 *
 * A file uploaded here is not a media library attachment and must not become
 * one: it holds a customer list or a price sheet, it is wanted for the length
 * of one import, and an attachment is a public URL and a row in the media
 * screen for ever.
 *
 * So it goes in a directory of its own under uploads, named by a UUID, with
 * an index.php and a .htaccess beside it, and it is remembered in a transient
 * that expires. What is stored about it — including who uploaded it — is the
 * only thing standing between one administrator's import and another's, so
 * the ownership check is not optional and not skippable.
 */
final class Upload {

	/**
	 * The directory under uploads.
	 */
	private const DIRECTORY = 'importers';

	/**
	 * How long a file is kept.
	 */
	private const LIFETIME = DAY_IN_SECONDS;

	/**
	 * What a CSV file is allowed to look like from the inside.
	 *
	 * A CSV has no format of its own — it is a text file — so this is as
	 * tight as it can honestly be.
	 *
	 * @var string[]
	 */
	private const TYPES = [
		'text/csv',
		'text/plain',
		'application/csv',
		'application/vnd.ms-excel',
		'text/comma-separated-values',
		'text/x-comma-separated-values',
	];

	/**
	 * Take a file.
	 *
	 * @param string $page  Which importer page it belongs to.
	 * @param string $field The form field it arrived in.
	 *
	 * @return array<string, mixed>|WP_Error What was stored about it.
	 */
	public static function receive( string $page, string $field = 'import_file' ): array|WP_Error {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- the REST controller verified the request; every part of $_FILES that is used is sanitized or validated below, and tmp_name is PHP's own path rather than anything the client sent.
		$file = $_FILES[ $field ] ?? null;

		if ( ! is_array( $file ) || ! isset( $file['tmp_name'], $file['name'], $file['error'] ) ) {
			return new WP_Error( 'no_file', __( 'No file arrived.', 'arraypress' ) );
		}

		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'upload_failed', self::why( (int) $file['error'] ) );
		}

		// The name is the reader's, not the filesystem's: what is written to
		// disk is a UUID. This is only kept to show them which file they
		// picked.
		$name = sanitize_file_name( (string) $file['name'] );

		if ( 'csv' !== strtolower( (string) pathinfo( $name, PATHINFO_EXTENSION ) ) ) {
			return new WP_Error( 'not_a_csv', __( 'The file needs a .csv extension.', 'arraypress' ) );
		}

		$type = self::contents_look_like( (string) $file['tmp_name'] );

		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new WP_Error(
				'not_a_csv',
				__( 'That is not a CSV file. It has the right name but something else inside it.', 'arraypress' )
			);
		}

		$directory = self::directory( $page );

		if ( $directory instanceof WP_Error ) {
			return $directory;
		}

		$uuid = wp_generate_uuid4();
		$path = trailingslashit( $directory ) . $uuid . '.csv';

		if ( ! move_uploaded_file( (string) $file['tmp_name'], $path ) ) {
			return new WP_Error( 'not_saved', __( 'The file could not be saved.', 'arraypress' ) );
		}

		$reader  = new Reader( $path );
		$headers = $reader->headers();

		if ( $headers instanceof WP_Error ) {
			wp_delete_file( $path );

			return $headers;
		}

		$stored = [
			'uuid'    => $uuid,
			'name'    => $name,
			'path'    => $path,
			'size'    => (int) filesize( $path ),
			'rows'    => $reader->count(),
			'headers' => $headers,
			'page'    => $page,
			'user'    => get_current_user_id(),
			'at'      => time(),
		];

		set_transient( Runtime::key( 'file_' . $uuid ), $stored, self::LIFETIME );

		return $stored;
	}

	/**
	 * What is known about an uploaded file.
	 *
	 * Null when it has expired, been cleaned up, or belongs to somebody else.
	 * The last of those is why this exists rather than the caller reading the
	 * transient: a UUID in a URL is not an authorisation, and two people with
	 * the same capability are still two people.
	 *
	 * @param string $uuid The file.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function find( string $uuid ): ?array {
		$stored = get_transient( Runtime::key( 'file_' . $uuid ) );

		if ( ! is_array( $stored ) || ! isset( $stored['path'] ) ) {
			return null;
		}

		if ( ! file_exists( (string) $stored['path'] ) ) {
			delete_transient( Runtime::key( 'file_' . $uuid ) );

			return null;
		}

		return (int) ( $stored['user'] ?? 0 ) === get_current_user_id() ? $stored : null;
	}

	/**
	 * Throw a file away.
	 *
	 * @param string $uuid The file.
	 *
	 * @return bool
	 */
	public static function discard( string $uuid ): bool {
		$stored = self::find( $uuid );

		if ( null === $stored ) {
			return false;
		}

		Reader::forget( (string) $stored['path'] );
		wp_delete_file( (string) $stored['path'] );
		delete_transient( Runtime::key( 'file_' . $uuid ) );

		return true;
	}

	/**
	 * Remove what is left of imports nobody finished.
	 *
	 * The transient expires on its own; the file it described does not. A
	 * year of abandoned uploads is a directory of customer lists.
	 *
	 * @return int How many were removed.
	 */
	public static function sweep(): int {
		$base = self::base();

		if ( ! is_dir( $base ) ) {
			return 0;
		}

		$removed = 0;
		$cutoff  = time() - self::LIFETIME;

		foreach ( (array) glob( trailingslashit( $base ) . '*/*.csv' ) as $path ) {
			if ( (int) filemtime( (string) $path ) > $cutoff ) {
				continue;
			}

			Reader::forget( (string) $path );
			wp_delete_file( (string) $path );

			++$removed;
		}

		return $removed;
	}

	/**
	 * Where a page's uploads go, made if it is not there.
	 *
	 * @param string $page The page.
	 *
	 * @return string|WP_Error
	 */
	private static function directory( string $page ): string|WP_Error {
		$base = self::base();

		if ( ! is_dir( $base ) && ! wp_mkdir_p( $base ) ) {
			return new WP_Error( 'no_directory', __( 'The uploads directory could not be written to.', 'arraypress' ) );
		}

		self::protect( $base );

		$directory = trailingslashit( $base ) . sanitize_key( $page );

		if ( ! is_dir( $directory ) && ! wp_mkdir_p( $directory ) ) {
			return new WP_Error( 'no_directory', __( 'The import directory could not be created.', 'arraypress' ) );
		}

		return $directory;
	}

	/**
	 * The directory all of them go under.
	 *
	 * @return string
	 */
	private static function base(): string {
		$uploads = wp_upload_dir();

		return trailingslashit( (string) $uploads['basedir'] ) . self::DIRECTORY;
	}

	/**
	 * Keep the directory from being served or listed.
	 *
	 * Both files, and the result of writing them is checked — the old version
	 * wrote and moved on, so a directory that could not be protected looked
	 * exactly like one that could.
	 *
	 * Worth being honest about what this buys: `.htaccess` is Apache's, and a
	 * site on nginx ignores it. What actually keeps these files private is
	 * that nothing links to them and the name is a UUID. The two files are
	 * the belt; the name is the braces.
	 *
	 * @param string $directory The directory.
	 *
	 * @return void
	 */
	private static function protect( string $directory ): void {
		$files = [
			'.htaccess' => "Options -Indexes\nRequire all denied\n",
			'index.php' => "<?php\n// Silence is golden.\n",
		];

		foreach ( $files as $name => $contents ) {
			$path = trailingslashit( $directory ) . $name;

			if ( file_exists( $path ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- WP_Filesystem needs credentials that an upload handler has no way to ask for; this writes two fixed files into a directory it just created.
			if ( false === file_put_contents( $path, $contents ) ) {
				_doing_it_wrong(
					__METHOD__,
					sprintf(
						/* translators: %s: a directory path. */
						esc_html__( 'The import directory %s could not be protected from being served directly. Uploaded files are named unguessably, but the directory should not be public.', 'arraypress' ),
						esc_html( $directory )
					),
					'3.0.0'
				);
			}
		}
	}

	/**
	 * What the file's own bytes say it is.
	 *
	 * @param string $path The temporary file.
	 *
	 * @return string
	 */
	private static function contents_look_like( string $path ): string {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );

			if ( false !== $finfo ) {
				$type = (string) finfo_file( $finfo, $path );

				finfo_close( $finfo );

				return $type;
			}
		}

		// No fileinfo extension. wp_check_filetype() only reads the name,
		// which the extension check has already covered, so this falls back
		// to trusting it rather than refusing every upload.
		return 'text/plain';
	}

	/**
	 * Why an upload failed, in words.
	 *
	 * @param int $code PHP's own code.
	 *
	 * @return string
	 */
	private static function why( int $code ): string {
		return match ( $code ) {
			UPLOAD_ERR_INI_SIZE,
			UPLOAD_ERR_FORM_SIZE => sprintf(
				/* translators: %s: the largest file size the server accepts. */
				__( 'The file is larger than this site accepts (%s).', 'arraypress' ),
				size_format( wp_max_upload_size() )
			),
			UPLOAD_ERR_PARTIAL   => __( 'The file only partly arrived. Try again.', 'arraypress' ),
			UPLOAD_ERR_NO_FILE   => __( 'No file was chosen.', 'arraypress' ),
			UPLOAD_ERR_NO_TMP_DIR,
			UPLOAD_ERR_CANT_WRITE,
			UPLOAD_ERR_EXTENSION => __( 'The server could not accept the file. This is a problem with the site rather than the file.', 'arraypress' ),
			default              => __( 'The upload failed.', 'arraypress' ),
		};
	}
}
