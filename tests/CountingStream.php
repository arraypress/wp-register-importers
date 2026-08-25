<?php
/**
 * A stream that counts what is read through it.
 *
 * @package ArrayPress\RegisterImporters
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Tests;

/**
 * Counts the bytes a reader actually pulls off a file.
 *
 * The reason the reader was rewritten is cost, not correctness: reaching row
 * N by reading and discarding the N rows before it gives the right answer
 * every time and does it in time proportional to the square of the file.
 *
 * A test that asserts the right rows came back cannot see that. This can: it
 * is a stream wrapper that proxies to the real file and adds up every byte
 * handed out, so a reader that scans the same rows again reads the same bytes
 * again and the number says so.
 *
 * Registered as `counting://`, so the reader under test is given
 * `counting:///tmp/whatever.csv` and knows nothing about it.
 */
final class CountingStream {

	/**
	 * The protocol.
	 */
	public const PROTOCOL = 'counting';

	/**
	 * Bytes read, by path.
	 *
	 * @var array<string, int>
	 */
	public static array $read = [];

	/**
	 * The context, which PHP sets and this does not use.
	 *
	 * @var resource|null
	 */
	public $context;

	/**
	 * The real file.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Its path.
	 *
	 * @var string
	 */
	private string $path = '';

	/**
	 * Make the protocol available.
	 *
	 * @return void
	 */
	public static function attach(): void {
		if ( ! in_array( self::PROTOCOL, stream_get_wrappers(), true ) ) {
			stream_wrapper_register( self::PROTOCOL, self::class );
		}

		self::$read = [];
	}

	/**
	 * How many bytes have been read from a file.
	 *
	 * @param string $path The real path.
	 *
	 * @return int
	 */
	public static function bytes( string $path ): int {
		return self::$read[ $path ] ?? 0;
	}

	/**
	 * The URL for a real file.
	 *
	 * @param string $path The real path.
	 *
	 * @return string
	 */
	public static function url( string $path ): string {
		return self::PROTOCOL . '://' . ltrim( $path, '/' );
	}

	/**
	 * Open it.
	 *
	 * @param string      $path        The URL.
	 * @param string      $mode        The mode.
	 * @param int         $options     Ignored.
	 * @param string|null $opened_path Set to the path actually opened.
	 *
	 * @return bool
	 */
	public function stream_open( string $path, string $mode, int $options, ?string &$opened_path ): bool {
		$this->path = '/' . ltrim( (string) parse_url( $path, PHP_URL_HOST ) . (string) parse_url( $path, PHP_URL_PATH ), '/' );

		$handle = fopen( $this->path, $mode );

		if ( false === $handle ) {
			return false;
		}

		$this->handle = $handle;
		$opened_path  = $this->path;

		return true;
	}

	/**
	 * Read from it, and count what came out.
	 *
	 * @param int $count How many bytes.
	 *
	 * @return string
	 */
	public function stream_read( int $count ): string {
		$data = (string) fread( $this->handle, $count );

		self::$read[ $this->path ] = ( self::$read[ $this->path ] ?? 0 ) + strlen( $data );

		return $data;
	}

	/**
	 * Whether it is finished.
	 *
	 * @return bool
	 */
	public function stream_eof(): bool {
		return feof( $this->handle );
	}

	/**
	 * Where it is.
	 *
	 * @return int
	 */
	public function stream_tell(): int {
		return (int) ftell( $this->handle );
	}

	/**
	 * Move it. Seeking reads nothing, which is the whole point.
	 *
	 * @param int $offset Where to.
	 * @param int $whence From where.
	 *
	 * @return bool
	 */
	public function stream_seek( int $offset, int $whence = SEEK_SET ): bool {
		return 0 === fseek( $this->handle, $offset, $whence );
	}

	/**
	 * What the file looks like.
	 *
	 * @return array<int|string, int>|false
	 */
	public function stream_stat(): array|false {
		return fstat( $this->handle );
	}

	/**
	 * What a file looks like, without opening it.
	 *
	 * @param string $path  The URL.
	 * @param int    $flags Ignored.
	 *
	 * @return array<int|string, int>|false
	 */
	public function url_stat( string $path, int $flags ): array|false {
		$real = '/' . ltrim( (string) parse_url( $path, PHP_URL_HOST ) . (string) parse_url( $path, PHP_URL_PATH ), '/' );

		// The reader asks is_readable() before opening. Warnings are
		// suppressed because a missing file is an answer, not a problem.
		// phpcs:ignore Generic.PHP.NoSilencedErrors.Discouraged -- stat() warns when the file is not there, which is one of the answers.
		return @stat( $real );
	}

	/**
	 * Close it.
	 *
	 * @return void
	 */
	public function stream_close(): void {
		if ( is_resource( $this->handle ) ) {
			fclose( $this->handle );
		}
	}
}
