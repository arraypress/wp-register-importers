<?php
/**
 * CSV reading tests.
 *
 * @package ArrayPress\RegisterImporters
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Tests;

use ArrayPress\RegisterImporters\Csv\Reader;
use PHPUnit\Framework\TestCase;
use WP_Error;

/**
 * Reading a file in batches.
 *
 * An import runs in batches because a browser will not wait for fifty
 * thousand rows, so the same file is opened once per batch and has to start
 * where the last one stopped. Getting that wrong is not visible on a small
 * file and is the whole cost of a large one.
 */
final class ReaderTest extends TestCase {

	/**
	 * The files written by a test, to be removed after it.
	 *
	 * @var string[]
	 */
	private array $files = [];

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		ri_reset_globals();
	}

	/**
	 * Remove the files.
	 */
	protected function tearDown(): void {
		foreach ( $this->files as $file ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}
		}

		$this->files = [];
	}

	/**
	 * Write a CSV file.
	 *
	 * @param string $contents What goes in it.
	 *
	 * @return string Its path.
	 */
	private function csv( string $contents ): string {
		$path = (string) tempnam( sys_get_temp_dir(), 'ri' );

		file_put_contents( $path, $contents );

		$this->files[] = $path;

		return $path;
	}

	/**
	 * A file of a given number of rows.
	 *
	 * @param int $rows How many.
	 *
	 * @return string Its path.
	 */
	private function rows( int $rows ): string {
		$csv = "sku,name\n";

		for ( $i = 0; $i < $rows; $i++ ) {
			$csv .= sprintf( "SKU-%d,Product %d\n", $i, $i );
		}

		return $this->csv( $csv );
	}

	/**
	 * The headers are the first row.
	 */
	public function test_the_headers_are_the_first_row(): void {
		$reader = new Reader( $this->csv( "sku,name,price\nA,B,1\n" ) );

		$this->assertSame( [ 'sku', 'name', 'price' ], $reader->headers() );
	}

	/**
	 * A file saved by Excel begins with three bytes that are not a column.
	 *
	 * Nothing shows them. The first column matches nothing, imports empty on
	 * every row, and the file looks perfect when you open it.
	 */
	public function test_a_byte_order_mark_is_not_part_of_the_first_column(): void {
		$reader = new Reader( $this->csv( "\xEF\xBB\xBFsku,name\nA,B\n" ) );

		$this->assertSame( [ 'sku', 'name' ], $reader->headers() );
		$this->assertSame( 'A', $reader->batch( 0, 1 )['rows'][0]['sku'] );
	}

	/**
	 * Two columns of the same name are refused rather than collapsed.
	 *
	 * Combining a row with them keeps whichever came last and loses the
	 * other, silently.
	 */
	public function test_two_columns_of_the_same_name_are_refused(): void {
		$reader = new Reader( $this->csv( "sku,name,sku\nA,B,C\n" ) );

		$result = $reader->headers();

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertStringContainsString( 'more than one column called sku', $result->get_error_message() );
	}

	/**
	 * A file with no rows at all is refused rather than read as empty.
	 */
	public function test_an_empty_file_is_refused(): void {
		$this->assertInstanceOf( WP_Error::class, ( new Reader( $this->csv( '' ) ) )->headers() );
	}

	/**
	 * Rows are counted by reading them.
	 *
	 * Not by counting newlines: a quoted value may hold one, and a file where
	 * it does would report more rows than it has and then report the import
	 * as never finishing.
	 */
	public function test_a_newline_inside_a_value_is_not_a_new_row(): void {
		$reader = new Reader( $this->csv( "sku,note\nA,\"line one\nline two\"\nB,plain\n" ) );

		$this->assertSame( 2, $reader->count() );
		$this->assertSame( "line one\nline two", $reader->batch( 0, 2 )['rows'][0]['note'] );
	}

	/**
	 * A trailing newline is not an empty row.
	 *
	 * Most files have one. It used to arrive as a row of nothing that failed
	 * every required field, at the end of every import.
	 */
	public function test_a_trailing_newline_is_not_a_row(): void {
		$this->assertSame( 2, ( new Reader( $this->csv( "sku\nA\nB\n" ) ) )->count() );
		$this->assertSame( 2, ( new Reader( $this->csv( "sku\nA\nB\n\n\n" ) ) )->count() );
	}

	/**
	 * Batches follow each other through the file.
	 */
	public function test_batches_follow_each_other(): void {
		$reader = new Reader( $this->rows( 10 ) );

		$first = $reader->batch( 0, 4 );

		$this->assertSame( 4, $first['count'] );
		$this->assertTrue( $first['has_more'] );
		$this->assertSame( 'SKU-0', $first['rows'][0]['sku'] );

		$second = $reader->batch( 4, 4 );

		$this->assertSame( 'SKU-4', $second['rows'][0]['sku'] );

		$last = $reader->batch( 8, 4 );

		$this->assertSame( 2, $last['count'] );
		$this->assertFalse( $last['has_more'], 'It thinks there is more after the end.' );
	}

	/**
	 * Reading in order does not scan the same rows again.
	 *
	 * The old reader reached row N by reading and throwing away the N rows
	 * before it, once per batch — so the work grew with the square of the
	 * file and a hundred-thousand-row import spent most of itself skipping.
	 *
	 * Asserted on bytes read rather than time, which is the thing that
	 * actually grew and does not depend on how fast the machine is.
	 */
	public function test_reading_in_order_does_not_scan_the_same_rows_twice(): void {
		$path = $this->rows( 4000 );

		// Measured twice against the same file, through the same stream, and
		// compared with itself. The only difference between the two runs is
		// whether the reader is allowed to remember where it got to, so the
		// ratio is the algorithm and nothing else — an absolute number here
		// would be measuring PHP's read buffer.
		$remembering = $this->bytes_to_read_in_order( $path, false );
		$forgetting  = $this->bytes_to_read_in_order( $path, true );

		$this->assertLessThan(
			$forgetting / 4,
			$remembering,
			sprintf(
				'Reading in order took %d bytes; starting from the top each time took %d. The reader is still re-scanning.',
				$remembering,
				$forgetting
			)
		);
	}

	/**
	 * Read a file in order, and say how many bytes that took.
	 *
	 * @param string $path      The file.
	 * @param bool   $forgetful Whether to make it forget between batches,
	 *                          which is what the old reader effectively did.
	 *
	 * @return int
	 */
	private function bytes_to_read_in_order( string $path, bool $forgetful ): int {
		CountingStream::attach();
		Reader::forget();

		$reader = new Reader( CountingStream::url( $path ) );

		for ( $offset = 0; $offset < 4000; $offset += 200 ) {
			$this->assertSame(
				sprintf( 'SKU-%d', $offset ),
				$reader->batch( $offset, 200 )['rows'][0]['sku'],
				'A batch landed on the wrong row.'
			);

			if ( $forgetful ) {
				Reader::forget();
			}
		}

		return CountingStream::bytes( $path );
	}

	/**
	 * A batch out of order still lands on the right row.
	 *
	 * Nothing asks for one, but a retried batch or a resumed import does, and
	 * a reader that only works forwards would quietly return the wrong rows.
	 */
	public function test_a_batch_out_of_order_lands_on_the_right_row(): void {
		$reader = new Reader( $this->rows( 50 ) );

		$reader->batch( 0, 10 );
		$reader->batch( 40, 10 );

		$this->assertSame( 'SKU-20', $reader->batch( 20, 1 )['rows'][0]['sku'] );
		$this->assertSame( 'SKU-5', $reader->batch( 5, 1 )['rows'][0]['sku'] );
	}

	/**
	 * A row with the wrong number of columns is keyed like every other.
	 *
	 * It used to be handed on as a numerically indexed array while every
	 * other row was keyed by header, so the code reading $row['sku'] got
	 * nothing, said nothing, and imported a blank.
	 */
	public function test_a_short_row_is_still_keyed_by_header(): void {
		$batch = ( new Reader( $this->csv( "sku,name,price\nA,Widget,9.99\nB,Gadget\n" ) ) )->batch( 0, 2 );

		$this->assertSame( [ 'sku', 'name', 'price' ], array_keys( $batch['rows'][1] ) );
		$this->assertSame( 'B', $batch['rows'][1]['sku'] );
		$this->assertSame( '', $batch['rows'][1]['price'] );
	}

	/**
	 * And is reported, so a file that is wrong can be seen to be wrong.
	 */
	public function test_a_row_with_the_wrong_number_of_columns_is_reported(): void {
		$batch = ( new Reader( $this->csv( "sku,name\nA,Widget\nB\nC,Gadget,extra\n" ) ) )->batch( 0, 3 );

		$this->assertSame( [ 1, 2 ], $batch['malformed'] );
	}

	/**
	 * A long row's extra values are dropped, because there is no column.
	 */
	public function test_a_long_row_loses_what_has_nowhere_to_go(): void {
		$batch = ( new Reader( $this->csv( "sku,name\nA,Widget,extra\n" ) ) )->batch( 0, 1 );

		$this->assertSame( [ 'sku' => 'A', 'name' => 'Widget' ], $batch['rows'][0] );
	}

	/**
	 * A file that separates with something else can say so.
	 */
	public function test_another_separator_can_be_declared(): void {
		$reader = new Reader( $this->csv( "sku;name\nA;Widget\n" ), ';' );

		$this->assertSame( [ 'sku', 'name' ], $reader->headers() );
		$this->assertSame( 'Widget', $reader->batch( 0, 1 )['rows'][0]['name'] );
	}

	/**
	 * A file that is not there is an error rather than an empty import.
	 */
	public function test_a_missing_file_is_an_error(): void {
		$reader = new Reader( sys_get_temp_dir() . '/definitely-not-here.csv' );

		$this->assertInstanceOf( WP_Error::class, $reader->headers() );
		$this->assertInstanceOf( WP_Error::class, $reader->count() );
		$this->assertInstanceOf( WP_Error::class, $reader->batch( 0, 10 ) );
	}

}
