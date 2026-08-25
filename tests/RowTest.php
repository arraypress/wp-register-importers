<?php
/**
 * Row pipeline tests.
 *
 * @package ArrayPress\RegisterImporters
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Tests;

use ArrayPress\RegisterImporters\Row\Pipeline;
use ArrayPress\RegisterImporters\Row\Resolve;
use PHPUnit\Framework\TestCase;
use WP_Error;
use WP_Post;

/**
 * What happens to a cell of a CSV file between being read and being stored.
 *
 * The thing worth testing hardest is that there is only one answer to that.
 * There used to be two paths — a dry run and an import — with the dry run a
 * hand-copied subset of the other, and they had already drifted far enough
 * that a file could be reported clean and then import nulls.
 */
final class RowTest extends TestCase {

	/**
	 * Reset the stubbed globals.
	 */
	protected function setUp(): void {
		ri_reset_globals();
	}

	/**
	 * Run one field.
	 *
	 * @param mixed                $value  The raw cell.
	 * @param array<string, mixed> $field  Its declaration.
	 * @param bool                 $commit Whether this is the real thing.
	 *
	 * @return mixed
	 */
	private function field( mixed $value, array $field, bool $commit = true ): mixed {
		return Pipeline::field( 'field', $value, $field, [], $commit );
	}

	/**
	 * The message from a result that is an error.
	 *
	 * @param mixed $result The result.
	 *
	 * @return string
	 */
	private function message( mixed $result ): string {
		$this->assertInstanceOf( WP_Error::class, $result, 'That was not refused.' );

		return $result->get_error_message();
	}

	/* ---------------------------------------------------------------------
	 * Casting
	 * ------------------------------------------------------------------ */

	/**
	 * A price out of a spreadsheet is a number.
	 */
	public function test_a_price_survives_its_currency_symbol(): void {
		$this->assertSame( 1299.0, $this->field( '£1,299.00', [ 'type' => 'number' ] ) );
		$this->assertSame( 1299.0, $this->field( '$1,299.00', [ 'type' => 'number' ] ) );
	}

	/**
	 * Something that is not a number is refused rather than becoming nought.
	 */
	public function test_a_word_is_not_a_number(): void {
		$this->assertStringContainsString(
			'must be a number',
			$this->message( $this->field( 'banana', [ 'type' => 'number' ] ) )
		);
	}

	/**
	 * A whole number written with decimals is still whole.
	 *
	 * Spreadsheets write a quantity of twelve as 12.00 often enough that
	 * refusing it would be useless. And 12.9 is not twelve — the cleaning
	 * used to strip the point and make it a hundred and twenty nine.
	 */
	public function test_a_whole_number_written_with_decimals(): void {
		$this->assertSame( 12, $this->field( '12.00', [ 'type' => 'integer' ] ) );

		$this->assertStringContainsString(
			'whole number',
			$this->message( $this->field( '12.9', [ 'type' => 'integer' ] ) )
		);
	}

	/**
	 * A typo in a yes/no column is a mistake, not a no.
	 *
	 * This is the one that mattered. Anything unrecognised became false, so a
	 * column of Yes/No with "Ys" in one row imported as No and said nothing
	 * — and the only way to find out was to notice, later, that a product was
	 * not on sale.
	 */
	public function test_a_typo_in_a_yes_no_column_is_refused(): void {
		$this->assertTrue( $this->field( 'Yes', [ 'type' => 'boolean' ] ) );
		$this->assertTrue( $this->field( 'TRUE', [ 'type' => 'boolean' ] ) );
		$this->assertFalse( $this->field( 'off', [ 'type' => 'boolean' ] ) );
		$this->assertFalse( $this->field( '0', [ 'type' => 'boolean' ] ) );

		$this->assertStringContainsString(
			'not a yes or a no',
			$this->message( $this->field( 'Ys', [ 'type' => 'boolean' ] ) )
		);
	}

	/**
	 * A date is normalised, and a declared format is enforced exactly.
	 *
	 * Without the format, 03/04/2026 is read the American way, which is what
	 * strtotime() does. With it, the file says which it means — and that is
	 * the difference between importing March and importing April.
	 */
	public function test_a_declared_date_format_settles_the_ambiguity(): void {
		$this->assertSame( '2026-03-04', $this->field( '03/04/2026', [ 'type' => 'date' ] ) );

		$this->assertSame(
			'2026-04-03',
			$this->field( '03/04/2026', [ 'type' => 'date', 'date_format' => 'd/m/Y' ] )
		);
	}

	/**
	 * A date that does not keep the declared format is refused.
	 *
	 * Including one that only nearly does: createFromFormat reads 2026-13-45
	 * against Y-m-d and rolls it forward into 2027, which is not what the
	 * file said.
	 */
	public function test_a_date_that_does_not_match_its_format_is_refused(): void {
		$message = $this->message(
			$this->field( '2026-13-45', [ 'type' => 'date', 'date_format' => 'Y-m-d' ] )
		);

		$this->assertStringContainsString( 'written as Y-m-d', $message );
	}

	/* ---------------------------------------------------------------------
	 * Lists
	 * ------------------------------------------------------------------ */

	/**
	 * A separated cell becomes a list, and nought is one of its values.
	 *
	 * array_filter() with no callback reads "0" as nothing, so a list of
	 * 0,1,2 arrived with two values in it.
	 */
	public function test_a_separated_list_keeps_a_nought(): void {
		$this->assertSame(
			[ 0, 1, 2 ],
			$this->field( '0,1,2', [ 'type' => 'integer', 'separator' => ',' ] )
		);
	}

	/**
	 * Every character of the separator separates.
	 *
	 * A separator of ",;" used to mean "whichever of these appears first in
	 * the value", so `a;b,c` split on the comma alone and handed back `a;b`
	 * as one item.
	 */
	public function test_every_character_of_the_separator_separates(): void {
		$this->assertSame(
			[ 'a', 'b', 'c' ],
			$this->field( 'a;b,c', [ 'type' => 'string', 'separator' => ',;' ] )
		);
	}

	/**
	 * Every item of a list is checked, not the list.
	 */
	public function test_every_item_of_a_list_is_checked(): void {
		$this->assertSame(
			[ 'a@example.test', 'b@example.test' ],
			$this->field( 'a@example.test, b@example.test', [ 'type' => 'email', 'separator' => ',' ] )
		);

		$this->assertStringContainsString(
			'email address',
			$this->message( $this->field( 'a@example.test, nope', [ 'type' => 'email', 'separator' => ',' ] ) )
		);
	}

	/* ---------------------------------------------------------------------
	 * Checking
	 * ------------------------------------------------------------------ */

	/**
	 * A required field is required, and nought counts as filled in.
	 *
	 * A price of nought, a quantity of nought and a rate of nought are all
	 * things a file can legitimately say.
	 */
	public function test_nought_is_a_value(): void {
		$this->assertSame( 0.0, $this->field( '0', [ 'type' => 'number', 'required' => true ] ) );

		$this->assertStringContainsString(
			'is required',
			$this->message( $this->field( '', [ 'type' => 'number', 'required' => true ] ) )
		);
	}

	/**
	 * An empty optional field is left empty rather than checked.
	 */
	public function test_an_empty_optional_field_is_left_alone(): void {
		$this->assertSame( '', $this->field( '', [ 'type' => 'email' ] ) );
	}

	/**
	 * A default fills an empty cell before anything else looks at it.
	 */
	public function test_a_default_fills_an_empty_cell(): void {
		$this->assertSame( 5, $this->field( '', [ 'type' => 'integer', 'default' => 5 ] ) );
	}

	/**
	 * The range, length, options and pattern rules.
	 *
	 * @dataProvider ruleProvider
	 *
	 * @param mixed                $value    The cell.
	 * @param array<string, mixed> $field    Its declaration.
	 * @param string               $expected Part of the message it should give.
	 */
	#[\PHPUnit\Framework\Attributes\DataProvider( 'ruleProvider' )]
	public function test_a_rule_is_enforced( mixed $value, array $field, string $expected ): void {
		$this->assertStringContainsString( $expected, $this->message( $this->field( $value, $field ) ) );
	}

	/**
	 * One case per rule.
	 *
	 * @return array<string, array{0: mixed, 1: array<string, mixed>, 2: string}>
	 */
	public static function ruleProvider(): array {
		return [
			'below the minimum' => [ '1', [ 'type' => 'number', 'minimum' => 5 ], 'at least 5' ],
			'above the maximum' => [ '9', [ 'type' => 'number', 'maximum' => 5 ], 'no more than 5' ],
			'too short'         => [ 'ab', [ 'type' => 'string', 'min_length' => 3 ], 'at least 3 characters' ],
			'too long'          => [ 'abcd', [ 'type' => 'string', 'max_length' => 3 ], 'no more than 3 characters' ],
			'not an option'     => [ 'purple', [ 'type' => 'string', 'options' => [ 'red', 'blue' ] ], 'one of: red, blue' ],
			'not the pattern'   => [ 'nope', [ 'type' => 'string', 'pattern' => '/^SKU-\d+$/' ], 'expected format' ],
			'not an email'      => [ 'nope', [ 'type' => 'email' ], 'email address' ],
			'not a url'         => [ 'nope', [ 'type' => 'url' ], 'must be a URL' ],
		];
	}

	/**
	 * An option list is compared loosely.
	 *
	 * The declaration is written by hand and the value came out of a text
	 * file. Comparing 1 with '1' strictly rejects a row for having been read
	 * from a CSV.
	 */
	public function test_an_option_list_is_compared_loosely(): void {
		$this->assertSame( 1, $this->field( '1', [ 'type' => 'integer', 'options' => [ 1, 2, 3 ] ] ) );
	}

	/**
	 * A pattern that will not compile is the author's mistake, said once.
	 *
	 * Reporting it against every row of a fifty-thousand-row import would
	 * bury it under fifty thousand copies of itself.
	 */
	public function test_a_broken_pattern_is_reported_to_the_developer(): void {
		$this->assertSame( 'anything', $this->field( 'anything', [ 'type' => 'string', 'pattern' => '/[unclosed/' ] ) );
		$this->assertNotEmpty( $GLOBALS['ri_wrong'] );
	}

	/* ---------------------------------------------------------------------
	 * Callbacks
	 * ------------------------------------------------------------------ */

	/**
	 * A field's own validator runs after everything built in.
	 */
	public function test_a_field_can_check_itself(): void {
		$field = [
			'type'              => 'string',
			'validate_callback' => static fn( $value ) => str_starts_with( (string) $value, 'SKU-' )
				? true
				: new WP_Error( 'sku', 'Every code starts with SKU-.' ),
		];

		$this->assertSame( 'SKU-1', $this->field( 'SKU-1', $field ) );
		$this->assertSame( 'Every code starts with SKU-.', $this->message( $this->field( 'X-1', $field ) ) );
	}

	/**
	 * A field's own processor runs on an import and not on a dry run.
	 *
	 * It is the consumer's code and it may write. A dry run is a promise that
	 * nothing happened.
	 */
	public function test_a_processor_does_not_run_on_a_dry_run(): void {
		$ran   = 0;
		$field = [
			'type'             => 'string',
			'process_callback' => static function ( $value ) use ( &$ran ) {
				++$ran;

				return strtoupper( (string) $value );
			},
		];

		$this->assertSame( 'ACME', $this->field( 'acme', $field ) );
		$this->assertSame( 1, $ran );

		$this->assertSame( 'acme', $this->field( 'acme', $field, false ) );
		$this->assertSame( 1, $ran, 'The processor ran during a dry run.' );
	}

	/* ---------------------------------------------------------------------
	 * Resolution
	 * ------------------------------------------------------------------ */

	/**
	 * A term is found by id, slug or name, whichever the file holds.
	 */
	public function test_a_term_is_found_however_the_file_names_it(): void {
		$GLOBALS['ri_terms'][7] = (object) [
			'term_id'  => 7,
			'name'     => 'Widgets',
			'slug'     => 'widgets',
			'taxonomy' => 'category',
		];

		$field = [ 'type' => 'term', 'taxonomy' => 'category' ];

		$this->assertSame( 7, $this->field( '7', $field ) );
		$this->assertSame( 7, $this->field( 'widgets', $field ) );
		$this->assertSame( 7, $this->field( 'Widgets', $field ) );
	}

	/**
	 * A value that resolves to nothing is reported, required or not.
	 *
	 * It used to be swallowed whenever the field was optional: the row
	 * imported with null in that column and nobody was told the file had
	 * named a category that does not exist. "Optional" means the cell may be
	 * empty, not that a value in it may be wrong.
	 */
	public function test_a_value_that_resolves_to_nothing_is_reported(): void {
		$this->assertStringContainsString(
			'was not found',
			$this->message( $this->field( 'Nothing', [ 'type' => 'term', 'taxonomy' => 'category', 'required' => false ] ) )
		);
	}

	/**
	 * An empty optional cell is still empty, not an error.
	 */
	public function test_an_empty_cell_does_not_have_to_resolve(): void {
		$this->assertNull( $this->field( '', [ 'type' => 'term', 'taxonomy' => 'category' ] ) );
	}

	/**
	 * A term the field is willing to create is created on an import.
	 */
	public function test_a_missing_term_can_be_created(): void {
		$field = [ 'type' => 'term', 'taxonomy' => 'category', 'create' => true ];

		$id = $this->field( 'Brand New', $field );

		$this->assertIsInt( $id );
		$this->assertSame( 'Brand New', $GLOBALS['ri_terms'][ $id ]->name );
	}

	/**
	 * And is not created by a dry run, which still does not call it an error.
	 *
	 * That is what makes a dry run's answer the same answer the import gives:
	 * it looked, it did not find it, and it knows the import would make it.
	 */
	public function test_a_dry_run_does_not_create_the_term_or_refuse_it(): void {
		$field = [ 'type' => 'term', 'taxonomy' => 'category', 'create' => true ];

		$this->assertSame( 0, $this->field( 'Brand New', $field, false ) );
		$this->assertSame( [], $GLOBALS['ri_terms'] );
	}

	/**
	 * The same value is looked up once.
	 *
	 * A file of ten thousand products with six categories between them asked
	 * the database ten thousand times.
	 */
	public function test_the_same_value_is_looked_up_once(): void {
		$GLOBALS['ri_terms'][7] = (object) [
			'term_id'  => 7,
			'name'     => 'Widgets',
			'slug'     => 'widgets',
			'taxonomy' => 'category',
		];

		$field = [ 'type' => 'term', 'taxonomy' => 'category' ];

		$this->field( 'widgets', $field );
		$after_one = $GLOBALS['ri_queries'];

		for ( $i = 0; $i < 20; $i++ ) {
			$this->field( 'widgets', $field );
		}

		$this->assertSame( $after_one, $GLOBALS['ri_queries'], 'It asked again.' );
	}

	/**
	 * A post is found by id, slug or title.
	 */
	public function test_a_post_is_found_however_the_file_names_it(): void {
		$GLOBALS['ri_posts'][12] = new WP_Post( 12, 'product', 'blue-widget', 'Blue Widget' );

		$field = [ 'type' => 'post', 'post_type' => 'product' ];

		$this->assertSame( 12, $this->field( '12', $field ) );
		$this->assertSame( 12, $this->field( 'blue-widget', $field ) );
		$this->assertSame( 12, $this->field( 'Blue Widget', $field ) );
	}

	/**
	 * Matching on a meta value without saying which key is the author's bug.
	 */
	public function test_matching_on_meta_needs_a_key(): void {
		$this->assertStringContainsString(
			'does not say which meta key',
			$this->message( $this->field( 'X', [ 'type' => 'post', 'match_by' => 'meta', 'label' => 'Product' ] ) )
		);
	}

	/**
	 * A user is found by email, login or slug.
	 */
	public function test_a_user_is_found_however_the_file_names_them(): void {
		$GLOBALS['ri_users'][3] = (object) [
			'ID'            => 3,
			'user_email'    => 'jane@example.test',
			'user_login'    => 'jane',
			'user_nicename' => 'jane-doe',
		];

		$field = [ 'type' => 'user' ];

		$this->assertSame( 3, $this->field( 'jane@example.test', $field ) );
		$this->assertSame( 3, $this->field( 'jane', $field ) );
		$this->assertSame( 3, $this->field( 'jane-doe', $field ) );
	}

	/* ---------------------------------------------------------------------
	 * Whole rows
	 * ------------------------------------------------------------------ */

	/**
	 * A row is every field, and the first thing wrong with it stops it.
	 */
	public function test_a_row_stops_at_the_first_thing_wrong(): void {
		$fields = [
			'name'  => [ 'type' => 'string', 'required' => true ],
			'price' => [ 'type' => 'number', 'minimum' => 1 ],
		];

		$this->assertSame(
			[ 'name' => 'Widget', 'price' => 9.99 ],
			Pipeline::row( [ 'name' => 'Widget', 'price' => '9.99' ], $fields )
		);

		$error = Pipeline::row( [ 'name' => 'Widget', 'price' => '0' ], $fields );

		$this->assertInstanceOf( WP_Error::class, $error );
		$this->assertSame( [ 'field' => 'price' ], $error->get_error_data() );
	}

	/**
	 * A dry run and an import agree about the same file.
	 *
	 * The reason this class exists. Whatever a dry run says about a row, the
	 * import says the same — otherwise the dry run is a button that makes
	 * people confident and tells them nothing.
	 */
	public function test_a_dry_run_and_an_import_agree(): void {
		$GLOBALS['ri_terms'][7] = (object) [
			'term_id'  => 7,
			'name'     => 'Widgets',
			'slug'     => 'widgets',
			'taxonomy' => 'category',
		];

		$fields = [
			'name'     => [ 'type' => 'string', 'required' => true ],
			'price'    => [ 'type' => 'number', 'minimum' => 0.01 ],
			'on_sale'  => [ 'type' => 'boolean' ],
			'category' => [ 'type' => 'term', 'taxonomy' => 'category' ],
		];

		$rows = [
			'good'          => [ 'name' => 'A', 'price' => '9.99', 'on_sale' => 'yes', 'category' => 'Widgets' ],
			'no name'       => [ 'name' => '', 'price' => '9.99', 'on_sale' => 'yes', 'category' => 'Widgets' ],
			'free'          => [ 'name' => 'B', 'price' => '0', 'on_sale' => 'no', 'category' => 'Widgets' ],
			'odd boolean'   => [ 'name' => 'C', 'price' => '1', 'on_sale' => 'Ys', 'category' => 'Widgets' ],
			'no such term'  => [ 'name' => 'D', 'price' => '1', 'on_sale' => 'no', 'category' => 'Gadgets' ],
		];

		foreach ( $rows as $label => $row ) {
			Resolve::forget();
			$dry = Pipeline::row( $row, $fields, false );

			Resolve::forget();
			$real = Pipeline::row( $row, $fields, true );

			$this->assertSame(
				$dry instanceof WP_Error,
				$real instanceof WP_Error,
				sprintf( 'The dry run and the import disagree about "%s".', $label )
			);

			if ( $dry instanceof WP_Error ) {
				$this->assertSame(
					$dry->get_error_message(),
					$real->get_error_message(),
					sprintf( 'They disagree about why "%s" is wrong.', $label )
				);
			}
		}
	}
}
