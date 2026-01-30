<?php
/**
 * File Manager
 *
 * Handles secure file uploads, storage, and cleanup for CSV imports.
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

use WP_Error;

/**
 * Class FileManager
 *
 * Manages secure file operations for import files.
 */
class FileManager {

	/**
	 * Base directory name within uploads.
	 *
	 * @var string
	 */
	const UPLOAD_DIR = 'importers';

	/**
	 * Maximum file age in seconds before cleanup.
	 *
	 * @var int
	 */
	const MAX_FILE_AGE = DAY_IN_SECONDS;

	/**
	 * Transient prefix for file metadata.
	 *
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'importer_file_';

	/**
	 * Allowed MIME types for CSV files.
	 *
	 * @var array
	 */
	const ALLOWED_MIME_TYPES = [
		'text/csv',
		'text/plain',
		'application/csv',
		'application/vnd.ms-excel',
		'text/comma-separated-values',
	];

	/**
	 * Get the base upload directory for importers.
	 *
	 * @return string
	 */
	public static function get_base_dir(): string {
		$upload_dir = wp_upload_dir();

		return trailingslashit( $upload_dir['basedir'] ) . self::UPLOAD_DIR;
	}

	/**
	 * Get secure upload directory for a specific importer page.
	 *
	 * @param string $page_id The importer page ID.
	 *
	 * @return string
	 */
	public static function get_upload_dir( string $page_id ): string {
		$base_dir = self::get_base_dir();
		$page_dir = trailingslashit( $base_dir ) . sanitize_key( $page_id );

		if ( ! file_exists( $base_dir ) ) {
			wp_mkdir_p( $base_dir );
			self::protect_directory( $base_dir );
		}

		if ( ! file_exists( $page_dir ) ) {
			wp_mkdir_p( $page_dir );
		}

		return $page_dir;
	}

	/**
	 * Protect a directory from direct web access.
	 *
	 * @param string $dir Directory path to protect.
	 *
	 * @return void
	 */
	private static function protect_directory( string $dir ): void {
		// Create .htaccess for Apache
		$htaccess_file = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $htaccess_file ) ) {
			file_put_contents( $htaccess_file, "Options -Indexes\ndeny from all\n" );
		}

		// Create index.php as fallback
		$index_file = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $index_file ) ) {
			file_put_contents( $index_file, '<?php // Silence is golden' );
		}
	}

	/**
	 * Handle file upload with secure naming.
	 *
	 * @param string $page_id    The importer page ID.
	 * @param string $field_name The form field name (default: 'import_file').
	 *
	 * @return array|WP_Error File data array on success, WP_Error on failure.
	 */
	public static function handle_upload( string $page_id, string $field_name = 'import_file' ) {
		if ( empty( $_FILES[ $field_name ] ) ) {
			return new WP_Error(
				'no_file',
				__( 'No file was uploaded.', 'arraypress' )
			);
		}

		$file = $_FILES[ $field_name ];

		// Check for upload errors
		if ( $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error(
				'upload_error',
				self::get_upload_error_message( $file['error'] )
			);
		}

		// Validate file type by MIME
		$mime_type = self::get_file_mime_type( $file['tmp_name'] );
		if ( ! in_array( $mime_type, self::ALLOWED_MIME_TYPES, true ) ) {
			return new WP_Error(
				'invalid_type',
				__( 'Invalid file type. Please upload a CSV file.', 'arraypress' )
			);
		}

		// Validate file extension
		$extension = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( $extension !== 'csv' ) {
			return new WP_Error(
				'invalid_extension',
				__( 'File must have a .csv extension.', 'arraypress' )
			);
		}

		// Generate secure filename with UUID
		$uuid     = wp_generate_uuid4();
		$filename = $uuid . '.csv';
		$dir      = self::get_upload_dir( $page_id );
		$filepath = trailingslashit( $dir ) . $filename;

		// Move uploaded file
		if ( ! move_uploaded_file( $file['tmp_name'], $filepath ) ) {
			return new WP_Error(
				'move_failed',
				__( 'Failed to save the uploaded file.', 'arraypress' )
			);
		}

		// Get file info
		$row_count = self::count_csv_rows( $filepath );
		$headers   = self::get_csv_headers( $filepath );

		// Store metadata in transient
		$file_data = [
			'uuid'          => $uuid,
			'original_name' => sanitize_file_name( $file['name'] ),
			'path'          => $filepath,
			'size'          => filesize( $filepath ),
			'size_human'    => size_format( filesize( $filepath ) ),
			'rows'          => $row_count,
			'headers'       => $headers,
			'uploaded_at'   => current_time( 'mysql', true ),
			'uploaded_by'   => get_current_user_id(),
			'page_id'       => $page_id,
		];

		set_transient( self::TRANSIENT_PREFIX . $uuid, $file_data, self::MAX_FILE_AGE );

		return $file_data;
	}

	/**
	 * Get file metadata by UUID.
	 *
	 * @param string $uuid The file UUID.
	 *
	 * @return array|null File data or null if not found.
	 */
	public static function get_file( string $uuid ): ?array {
		$file_data = get_transient( self::TRANSIENT_PREFIX . $uuid );

		if ( ! $file_data || ! is_array( $file_data ) ) {
			return null;
		}

		// Verify file still exists
		if ( ! file_exists( $file_data['path'] ) ) {
			delete_transient( self::TRANSIENT_PREFIX . $uuid );

			return null;
		}

		return $file_data;
	}

	/**
	 * Read a batch of rows from a CSV file.
	 *
	 * @param string $uuid   The file UUID.
	 * @param int    $offset Starting row (0-indexed, after header).
	 * @param int    $limit  Number of rows to read.
	 *
	 * @return array|WP_Error Array of rows or WP_Error on failure.
	 */
	public static function read_batch( string $uuid, int $offset, int $limit ) {
		$file_data = self::get_file( $uuid );

		if ( ! $file_data ) {
			return new WP_Error(
				'file_not_found',
				__( 'Import file not found or expired.', 'arraypress' )
			);
		}

		$filepath = $file_data['path'];
		$handle   = fopen( $filepath, 'r' );

		if ( ! $handle ) {
			return new WP_Error(
				'file_read_error',
				__( 'Unable to read the import file.', 'arraypress' )
			);
		}

		$rows    = [];
		$headers = fgetcsv( $handle ); // Skip header row
		$current = 0;

		// Skip to offset
		while ( $current < $offset && fgetcsv( $handle ) !== false ) {
			$current++;
		}

		// Read batch
		while ( count( $rows ) < $limit && ( $row = fgetcsv( $handle ) ) !== false ) {
			// Create associative array with headers as keys
			if ( count( $row ) === count( $headers ) ) {
				$rows[] = array_combine( $headers, $row );
			} else {
				// Handle row with different column count
				$rows[] = $row;
			}
			$current++;
		}

		$has_more = fgetcsv( $handle ) !== false;

		fclose( $handle );

		return [
			'rows'     => $rows,
			'offset'   => $offset,
			'count'    => count( $rows ),
			'has_more' => $has_more,
		];
	}

	/**
	 * Get preview rows from a CSV file.
	 *
	 * @param string $uuid      The file UUID.
	 * @param int    $max_rows  Maximum rows to preview (default: 5).
	 *
	 * @return array|WP_Error Preview data or WP_Error on failure.
	 */
	public static function get_preview( string $uuid, int $max_rows = 5 ) {
		$file_data = self::get_file( $uuid );

		if ( ! $file_data ) {
			return new WP_Error(
				'file_not_found',
				__( 'Import file not found or expired.', 'arraypress' )
			);
		}

		$filepath = $file_data['path'];
		$handle   = fopen( $filepath, 'r' );

		if ( ! $handle ) {
			return new WP_Error(
				'file_read_error',
				__( 'Unable to read the import file.', 'arraypress' )
			);
		}

		$headers = fgetcsv( $handle );
		$rows    = [];

		while ( count( $rows ) < $max_rows && ( $row = fgetcsv( $handle ) ) !== false ) {
			$rows[] = $row;
		}

		fclose( $handle );

		return [
			'headers' => $headers,
			'rows'    => $rows,
			'total'   => $file_data['rows'],
		];
	}

	/**
	 * Delete a file by UUID.
	 *
	 * @param string $uuid The file UUID.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function delete_file( string $uuid ): bool {
		$file_data = get_transient( self::TRANSIENT_PREFIX . $uuid );

		if ( $file_data && ! empty( $file_data['path'] ) && file_exists( $file_data['path'] ) ) {
			unlink( $file_data['path'] );
		}

		delete_transient( self::TRANSIENT_PREFIX . $uuid );

		return true;
	}

	/**
	 * Cleanup expired files.
	 *
	 * Removes files older than MAX_FILE_AGE.
	 *
	 * @return int Number of files deleted.
	 */
	public static function cleanup_expired(): int {
		$base_dir = self::get_base_dir();

		if ( ! is_dir( $base_dir ) ) {
			return 0;
		}

		$deleted = 0;
		$now     = time();

		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base_dir, \RecursiveDirectoryIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && strtolower( $file->getExtension() ) === 'csv' ) {
				if ( ( $now - $file->getMTime() ) > self::MAX_FILE_AGE ) {
					unlink( $file->getPathname() );
					$deleted++;
				}
			}
		}

		return $deleted;
	}

	/**
	 * Count data rows in a CSV file (excluding header).
	 *
	 * @param string $filepath Path to the CSV file.
	 *
	 * @return int Number of data rows.
	 */
	public static function count_csv_rows( string $filepath ): int {
		$count  = 0;
		$handle = fopen( $filepath, 'r' );

		if ( $handle ) {
			while ( fgets( $handle ) !== false ) {
				$count++;
			}
			fclose( $handle );
		}

		// Subtract 1 for header row, ensure non-negative
		return max( 0, $count - 1 );
	}

	/**
	 * Get CSV headers from a file.
	 *
	 * @param string $filepath Path to the CSV file.
	 *
	 * @return array Array of header column names.
	 */
	public static function get_csv_headers( string $filepath ): array {
		$handle = fopen( $filepath, 'r' );

		if ( ! $handle ) {
			return [];
		}

		$headers = fgetcsv( $handle );
		fclose( $handle );

		return is_array( $headers ) ? $headers : [];
	}

	/**
	 * Get the MIME type of a file.
	 *
	 * @param string $filepath Path to the file.
	 *
	 * @return string MIME type.
	 */
	private static function get_file_mime_type( string $filepath ): string {
		if ( function_exists( 'finfo_open' ) ) {
			$finfo     = finfo_open( FILEINFO_MIME_TYPE );
			$mime_type = finfo_file( $finfo, $filepath );
			finfo_close( $finfo );

			return $mime_type;
		}

		// Fallback to mime_content_type
		if ( function_exists( 'mime_content_type' ) ) {
			return mime_content_type( $filepath );
		}

		return 'application/octet-stream';
	}

	/**
	 * Get human-readable upload error message.
	 *
	 * @param int $error_code PHP upload error code.
	 *
	 * @return string Error message.
	 */
	private static function get_upload_error_message( int $error_code ): string {
		$messages = [
			UPLOAD_ERR_INI_SIZE   => __( 'The uploaded file exceeds the server size limit.', 'arraypress' ),
			UPLOAD_ERR_FORM_SIZE  => __( 'The uploaded file exceeds the form size limit.', 'arraypress' ),
			UPLOAD_ERR_PARTIAL    => __( 'The file was only partially uploaded.', 'arraypress' ),
			UPLOAD_ERR_NO_FILE    => __( 'No file was uploaded.', 'arraypress' ),
			UPLOAD_ERR_NO_TMP_DIR => __( 'Server missing temporary folder.', 'arraypress' ),
			UPLOAD_ERR_CANT_WRITE => __( 'Failed to write file to disk.', 'arraypress' ),
			UPLOAD_ERR_EXTENSION  => __( 'File upload stopped by extension.', 'arraypress' ),
		];

		return $messages[ $error_code ] ?? __( 'Unknown upload error.', 'arraypress' );
	}

}
