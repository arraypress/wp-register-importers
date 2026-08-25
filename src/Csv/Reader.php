<?php
/**
 * Reader
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Csv;

use WP_Error;

/**
 * Reading a CSV file a batch at a time.
 *
 * An import runs in batches because a browser will not wait for fifty
 * thousand rows, so the same file is opened once per batch and has to start
 * where the last one stopped. The old version did that by reading and
 * throwing away every row before the offset: batch five hundred re-read fifty
 * thousand rows to reach row fifty thousand, and the work grew with the
 * square of the file. A hundred-thousand-row import spent most of itself
 * skipping.
 *
 * So the byte offset each batch ended at is remembered, and the next batch
 * seeks straight to it. A file read in order never scans anything twice; one
 * read out of order scans from the nearest earlier point that is known.
 *
 * Two other things a CSV importer has to get right and this one did not:
 *
 * **The byte order mark.** A file saved by Excel begins with three bytes that
 * are not part of the first header, so a column called `sku` arrives as
 * `\xEF\xBB\xBFsku`, matches nothing, and every row imports with that column
 * empty. Nothing in the file looks wrong when you open it.
 *
 * **A row with the wrong number of columns.** It used to be handed on as a
 * numerically indexed array while every other row was keyed by header, so the
 * code reading `$row['sku']` got nothing, said nothing, and imported a blank.
 */
final class Reader {

	/**
	 * Where each row number begins, in bytes.
	 *
	 * Kept per file for the length of the request. The map is also handed
	 * back so a caller that spans requests can carry it.
	 *
	 * @var array<string, array<int, int>>
	 */
	private static array $checkpoints = [];

	/**
	 * The column names of each file, and where the first row begins.
	 *
	 * Read once per file rather than once per batch. Rewinding to re-read the
	 * header pulls the front of the file back off disk every time, which on a
	 * file read in twenty batches was most of what was left to save.
	 *
	 * @var array<string, array{headers: string[], first: int}>
	 */
	private static array $head = [];

	/**
	 * The file being read.
	 *
	 * @var string
	 */
	private string $path;

	/**
	 * How the file separates values.
	 *
	 * @var string
	 */
	private string $delimiter;

	/**
	 * How it quotes them.
	 *
	 * @var string
	 */
	private string $enclosure;

	/**
	 * Construct.
	 *
	 * @param string $path      The file.
	 * @param string $delimiter How it separates values.
	 * @param string $enclosure How it quotes them.
	 */
	public function __construct( string $path, string $delimiter = ',', string $enclosure = '"' ) {
		$this->path      = $path;
		$this->delimiter = '' === $delimiter ? ',' : $delimiter;
		$this->enclosure = '' === $enclosure ? '"' : $enclosure;
	}

	/**
	 * The column names.
	 *
	 * @return string[]|WP_Error
	 */
	public function headers(): array|WP_Error {
		$handle = $this->open();

		if ( $handle instanceof WP_Error ) {
			return $handle;
		}

		$headers = $this->read_headers( $handle );

		fclose( $handle );

		return $headers;
	}

	/**
	 * How many rows there are, not counting the header.
	 *
	 * Counted by reading rather than by counting newlines: a quoted value may
	 * contain one, and a file where it does would report more rows than it
	 * has and then report an import as incomplete for ever.
	 *
	 * @return int|WP_Error
	 */
	public function count(): int|WP_Error {
		$handle = $this->open();

		if ( $handle instanceof WP_Error ) {
			return $handle;
		}

		$headers = $this->read_headers( $handle );

		if ( $headers instanceof WP_Error ) {
			fclose( $handle );

			return $headers;
		}

		$rows = 0;

		while ( false !== $this->read_row( $handle ) ) {
			++$rows;
		}

		fclose( $handle );

		return $rows;
	}

	/**
	 * Read a batch.
	 *
	 * @param int $offset The row to start at, counting from nought after the header.
	 * @param int $limit  How many to read.
	 *
	 * @return array{rows: array<int, array<string, string>>, malformed: array<int, int>, offset: int, count: int, has_more: bool}|WP_Error
	 */
	public function batch( int $offset, int $limit ): array|WP_Error {
		$handle = $this->open();

		if ( $handle instanceof WP_Error ) {
			return $handle;
		}

		$headers = $this->read_headers( $handle );

		if ( $headers instanceof WP_Error ) {
			fclose( $handle );

			return $headers;
		}

		$row = $this->seek( $handle, $offset );

		$rows      = [];
		$malformed = [];

		while ( count( $rows ) < $limit ) {
			$this->checkpoint( $row, (int) ftell( $handle ) );

			$values = $this->read_row( $handle );

			if ( false === $values ) {
				break;
			}

			if ( count( $values ) !== count( $headers ) ) {
				$malformed[] = $row;
			}

			// Padded or trimmed to the header either way, so every row in the
			// batch is keyed the same. A short row's missing columns are
			// empty, which is what the file says; a long row's extras are
			// dropped, because there is no column for them to be in.
			$rows[] = array_combine(
				$headers,
				array_slice( array_pad( $values, count( $headers ), '' ), 0, count( $headers ) )
			);

			++$row;
		}

		$has_more = ! feof( $handle ) && false !== $this->peek( $handle );

		fclose( $handle );

		return [
			'rows'      => $rows,
			'malformed' => $malformed,
			'offset'    => $offset,
			'count'     => count( $rows ),
			'has_more'  => $has_more,
		];
	}

	/**
	 * Forget where the rows of a file begin.
	 *
	 * @param string $path The file, or empty for all of them.
	 *
	 * @return void
	 */
	public static function forget( string $path = '' ): void {
		if ( '' === $path ) {
			self::$checkpoints = [];
			self::$head        = [];

			return;
		}

		unset( self::$checkpoints[ $path ], self::$head[ $path ] );
	}

	/**
	 * Open the file.
	 *
	 * @return resource|WP_Error
	 */
	private function open() {
		if ( ! is_readable( $this->path ) ) {
			return new WP_Error(
				'file_unreadable',
				__( 'The import file is gone, or cannot be read.', 'arraypress' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- a file read row by row, which WP_Filesystem cannot do: its only read is the whole file into memory.
		$handle = fopen( $this->path, 'rb' );

		return false === $handle
			? new WP_Error( 'file_unreadable', __( 'The import file could not be opened.', 'arraypress' ) )
			: $handle;
	}

	/**
	 * Read the header row, byte order mark and all.
	 *
	 * @param resource $handle The open file.
	 *
	 * @return string[]|WP_Error
	 */
	private function read_headers( $handle ): array|WP_Error {
		if ( isset( self::$head[ $this->path ] ) ) {
			fseek( $handle, self::$head[ $this->path ]['first'] );

			return self::$head[ $this->path ]['headers'];
		}

		rewind( $handle );

		$headers = $this->read_row( $handle );

		if ( false === $headers || [] === $headers ) {
			return new WP_Error( 'file_empty', __( 'The import file has no header row.', 'arraypress' ) );
		}

		// Three bytes Excel writes and nothing shows. Left on, the first
		// column matches nothing and imports empty on every row.
		$headers[0] = (string) preg_replace( '/^\xEF\xBB\xBF/', '', (string) $headers[0] );

		$headers = array_map( static fn( $one ): string => trim( (string) $one ), $headers );

		// Two columns with the same name would collapse into one when the
		// row is combined with them, silently keeping whichever came last.
		if ( count( array_unique( $headers ) ) !== count( $headers ) ) {
			return new WP_Error(
				'duplicate_headers',
				sprintf(
					/* translators: %s: the column names that appear more than once. */
					__( 'The file has more than one column called %s. Every column needs its own name.', 'arraypress' ),
					implode( ', ', array_unique( array_diff_assoc( $headers, array_unique( $headers ) ) ) )
				)
			);
		}

		self::$head[ $this->path ] = [
			'headers' => $headers,
			'first'   => (int) ftell( $handle ),
		];

		return $headers;
	}

	/**
	 * Read one row.
	 *
	 * @param resource $handle The open file.
	 *
	 * @return array<int, string>|false
	 */
	private function read_row( $handle ): array|false {
		while ( true ) {
			$row = fgetcsv( $handle, 0, $this->delimiter, $this->enclosure, '' );

			if ( false === $row ) {
				return false;
			}

			// fgetcsv() returns [ null ] for a blank line. A file with a
			// trailing newline — which is most of them — would otherwise
			// finish with one empty row that fails every required field.
			if ( [ null ] === $row || [ '' ] === $row ) {
				continue;
			}

			return array_map( static fn( $one ): string => (string) $one, $row );
		}
	}

	/**
	 * Whether there is another row, without taking it.
	 *
	 * @param resource $handle The open file.
	 *
	 * @return array<int, string>|false
	 */
	private function peek( $handle ): array|false {
		$at  = ftell( $handle );
		$row = $this->read_row( $handle );

		fseek( $handle, (int) $at );

		return $row;
	}

	/**
	 * Get to a row, as directly as what is known allows.
	 *
	 * @param resource $handle The open file.
	 * @param int      $offset The row wanted.
	 *
	 * @return int The row the handle is now at.
	 */
	private function seek( $handle, int $offset ): int {
		$known = self::$checkpoints[ $this->path ] ?? [];

		// The nearest row at or before the one wanted whose position is
		// known. In an import read in order that is the row itself.
		$from = 0;

		foreach ( array_keys( $known ) as $row ) {
			if ( $row <= $offset && $row > $from ) {
				$from = $row;
			}
		}

		if ( $from > 0 || isset( $known[0] ) ) {
			fseek( $handle, $known[ $from ] );
		}

		for ( $row = $from; $row < $offset; $row++ ) {
			if ( false === $this->read_row( $handle ) ) {
				return $row;
			}
		}

		return $offset;
	}

	/**
	 * Remember where a row begins.
	 *
	 * @param int $row  The row number.
	 * @param int $byte Where it starts.
	 *
	 * @return void
	 */
	private function checkpoint( int $row, int $byte ): void {
		self::$checkpoints[ $this->path ][ $row ] = $byte;
	}
}
