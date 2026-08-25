<?php
/**
 * Screen, operation and endpoint tests.
 *
 * @package ArrayPress\RegisterImporters
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Tests;

use ArrayPress\RegisterImporters\Importer;
use ArrayPress\RegisterImporters\Importers;
use ArrayPress\RegisterImporters\Progress;
use ArrayPress\RegisterImporters\Rest\Controller;
use ArrayPress\RegisterImporters\Screen;
use ArrayPress\RegisterImporters\Utils\Runtime;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_Error;
use WP_REST_Request;

/**
 * The screen around the row pipeline, and the endpoints it talks to.
 *
 * Two of these are about who is allowed to do what, and they are the reason
 * the endpoints exist rather than a form post: a UUID in a URL is not an
 * authorisation, and a screen's capability is not the same as an
 * administrator's.
 */
final class ImporterTest extends TestCase {

	/**
	 * Reset the stubbed globals and the registry.
	 */
	protected function setUp(): void {
		ri_reset_globals();
	}

	/**
	 * Register an import screen.
	 *
	 * @param array<string, mixed> $config Overrides.
	 *
	 * @return Importer
	 */
	private function importer( array $config = [] ): Importer {
		$importer = Importers::register(
			'myplugin',
			array_merge(
				[
					'operations' => [
						'products' => [
							'title'            => 'Products',
							'fields'           => [
								'sku'   => [ 'label' => 'Code', 'required' => true ],
								'name'  => 'Name',
								'price' => [ 'label' => 'Price', 'type' => 'number', 'minimum' => 0 ],
							],
							'process_callback' => static fn( array $row ): string => 'created',
						],
					],
				],
				$config
			)
		);

		$this->assertInstanceOf( Importer::class, $importer );

		return $importer;
	}

	/* ---------------------------------------------------------------------
	 * Registration
	 * ------------------------------------------------------------------ */

	/**
	 * A screen with nothing to import is not a screen.
	 */
	public function test_a_screen_with_no_operations_is_refused(): void {
		$this->assertNull( Importers::register( 'empty', [] ) );
		$this->assertNull( Importers::register( '', [ 'operations' => [ 'a' => [] ] ] ) );
	}

	/**
	 * Registering attaches the hooks the screen needs, once.
	 */
	public function test_registering_attaches_the_shared_hooks_once(): void {
		$this->importer();
		Importers::register( 'second', [ 'operations' => [ 'a' => [] ] ] );

		$this->assertCount( 1, $GLOBALS['ri_hooks']['admin_menu'] );
		$this->assertArrayHasKey( 'rest_api_init', $GLOBALS['ri_hooks'] );
	}

	/**
	 * Abandoned uploads are swept up on a schedule.
	 *
	 * The transient describing a file expires; the file does not. A year of
	 * imports nobody finished is a directory full of customer lists.
	 */
	public function test_abandoned_files_are_swept_up(): void {
		$this->importer();

		$this->assertArrayHasKey( Runtime::hook( 'sweep' ), $GLOBALS['ri_cron'] );
		$this->assertArrayHasKey( Runtime::hook( 'sweep' ), $GLOBALS['ri_hooks'] );
	}

	/**
	 * A field declared as just a label is a field.
	 */
	public function test_a_field_can_be_declared_as_just_a_label(): void {
		$fields = $this->importer()->operation( 'products' )->fields();

		$this->assertSame( 'Name', $fields['name']['label'] );
		$this->assertSame( 'string', $fields['name']['type'] );
		$this->assertFalse( $fields['name']['required'] );
	}

	/* ---------------------------------------------------------------------
	 * The endpoints
	 * ------------------------------------------------------------------ */

	/**
	 * The REST namespace is derived, not written down.
	 *
	 * Strauss renames classes and leaves string literals alone, so two
	 * plugins each carrying a prefixed copy would register the same route.
	 * register_route() appends handlers rather than replacing them and
	 * dispatch runs the first whose methods match — so one plugin's importer
	 * would answer the other's requests, under its own capability, writing
	 * rows through its own callback.
	 */
	public function test_the_rest_namespace_is_derived_from_the_namespace(): void {
		$this->assertSame( 'importers/v1', Runtime::rest_namespace() );

		Controller::register();

		foreach ( array_keys( $GLOBALS['ri_routes'] ) as $route ) {
			$this->assertStringStartsWith( Runtime::rest_namespace(), (string) $route );
		}

		// And that it is actually derived rather than being that string.
		// Unprefixed the two are identical, so asserting the value proves
		// nothing — this loads the same class under the namespace Strauss
		// would give it and checks the answer moved.
		$prefixed = $this->as_prefixed_build();

		$this->assertSame( 'myplugin-importers/v1', $prefixed::rest_namespace() );
		$this->assertSame( 'myplugin-importers', $prefixed::handle() );
		$this->assertSame( 'myplugin_importers_file_x', $prefixed::key( 'file_x' ) );
	}

	/**
	 * Load Runtime again under the namespace a prefixed build would give it.
	 *
	 * Strauss rewrites the namespace and nothing else, so this is exactly
	 * what a second plugin bundling the library would be running.
	 *
	 * @return string The class name.
	 */
	private function as_prefixed_build(): string {
		$prefixed = 'MyPlugin\\ArrayPress\\RegisterImporters\\Utils\\Runtime';

		if ( ! class_exists( $prefixed ) ) {
			$source = (string) file_get_contents( dirname( __DIR__ ) . '/src/Utils/Runtime.php' );

			$source = str_replace(
				'namespace ArrayPress\\RegisterImporters\\Utils;',
				'namespace MyPlugin\\ArrayPress\\RegisterImporters\\Utils;',
				$source
			);

			// phpcs:ignore Squiz.PHP.Eval.Discouraged -- loading one class a second time under a different namespace is the thing being tested; there is no other way to have two of it.
			eval( '?>' . $source );
		}

		return $prefixed;
	}

	/**
	 * A request naming a screen nobody registered is refused.
	 *
	 * It used to fall back to manage_options, which answers for a screen this
	 * library has never heard of using a rule it made up.
	 */
	public function test_a_request_for_an_unregistered_screen_is_refused(): void {
		$this->importer();

		$refused = Controller::may( new WP_REST_Request( [ 'page' => 'somebody-elses' ] ) );

		$this->assertInstanceOf( WP_Error::class, $refused );
		$this->assertSame( 404, $refused->get_error_data()['status'] );
	}

	/**
	 * The capability is the screen's own, not an assumed one.
	 */
	public function test_the_capability_is_the_screens_own(): void {
		$this->importer( [ 'capability' => 'manage_shop_settings' ] );

		$GLOBALS['ri_caps'] = [ 'manage_options' ];

		$this->assertInstanceOf( WP_Error::class, Controller::may( new WP_REST_Request( [ 'page' => 'myplugin' ] ) ) );

		$GLOBALS['ri_caps'] = [ 'manage_shop_settings' ];

		$this->assertTrue( Controller::may( new WP_REST_Request( [ 'page' => 'myplugin' ] ) ) );
	}

	/**
	 * Every route asks the same question before doing anything.
	 */
	public function test_every_route_is_behind_the_permission_check(): void {
		Controller::register();

		$this->assertNotEmpty( $GLOBALS['ri_routes'] );

		foreach ( $GLOBALS['ri_routes'] as $route => $args ) {
			foreach ( isset( $args['methods'] ) ? [ $args ] : $args as $handler ) {
				$this->assertSame(
					[ Controller::class, 'may' ],
					$handler['permission_callback'] ?? null,
					sprintf( '%s is not behind the permission check.', $route )
				);
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * The screen
	 * ------------------------------------------------------------------ */

	/**
	 * The page is registered under the parent it named.
	 */
	public function test_the_page_is_registered(): void {
		$this->importer();

		Importers::register_menus();

		$this->assertArrayHasKey( 'myplugin', $GLOBALS['ri_menu'] );
		$this->assertSame( 'tools.php', $GLOBALS['ri_menu']['myplugin']['parent_slug'] );
	}

	/**
	 * Its URL goes through that parent, not admin.php regardless.
	 */
	public function test_the_url_uses_the_parent_file(): void {
		$importer = $this->importer();

		Importers::register_menus();

		$this->assertSame( 'https://example.test/wp-admin/tools.php?page=myplugin', $importer->url() );
	}

	/**
	 * Somebody who may not import does not get the screen.
	 */
	public function test_the_screen_is_refused_without_the_capability(): void {
		$importer = $this->importer( [ 'capability' => 'manage_shop_settings' ] );

		$this->expectException( RuntimeException::class );

		$this->render( $importer );
	}

	/**
	 * The screen says what every column holds and whether it is needed.
	 *
	 * This is the only place somebody learns what to put in their file, so it
	 * is drawn from the declarations rather than written by hand — a
	 * description that does not match the rules is worse than none.
	 */
	public function test_the_screen_explains_the_columns(): void {
		$html = $this->render( $this->importer() );

		$this->assertStringContainsString( '<code>sku</code>', $html );
		$this->assertStringContainsString( 'Required', $html );
		$this->assertStringContainsString( 'A number', $html );
		$this->assertStringContainsString( 'Download a sample file', $html );
	}

	/**
	 * An operation with no callback says so, rather than looking successful.
	 *
	 * It would otherwise report every row as skipped and finish green.
	 */
	public function test_an_operation_with_no_callback_says_so(): void {
		Importers::unregister( 'myplugin' );

		$importer = $this->importer(
			[
				'operations' => [
					'products' => [ 'title' => 'Products', 'fields' => [ 'sku' => 'Code' ] ],
				],
			]
		);

		$html = $this->render( $importer );

		$this->assertStringContainsString( 'no process callback', $html );
		$this->assertStringContainsString( 'disabled', $html );
	}

	/**
	 * Tabs appear when there is more than one group, and not before.
	 */
	public function test_tabs_appear_only_when_there_is_more_than_one(): void {
		$this->assertStringNotContainsString( 'nav-tab-wrapper', $this->render( $this->importer() ) );

		Importers::unregister( 'myplugin' );

		$importer = $this->importer(
			[
				'operations' => [
					'products'  => [ 'tab' => 'catalogue', 'fields' => [ 'sku' => 'Code' ], 'process_callback' => '__return_true' ],
					'customers' => [ 'tab' => 'people', 'fields' => [ 'email' => 'Email' ], 'process_callback' => '__return_true' ],
				],
			]
		);

		$html = $this->render( $importer );

		$this->assertStringContainsString( 'nav-tab-wrapper', $html );
		$this->assertSame( 1, substr_count( $html, 'nav-tab-active' ) );

		// One tab at a time, so the other's operation is not on the page.
		$this->assertStringContainsString( 'Products', $html );
		$this->assertStringNotContainsString( 'Customers', $html );
	}

	/**
	 * An operation naming a tab nobody declared still gets one.
	 *
	 * The alternative is an operation that is registered, has no tab to
	 * appear on, and silently never renders.
	 */
	public function test_an_undeclared_tab_is_created_rather_than_losing_the_operation(): void {
		Importers::unregister( 'myplugin' );

		$importer = $this->importer(
			[
				'operations' => [
					'products' => [ 'tab' => 'catalogue', 'fields' => [ 'sku' => 'Code' ], 'process_callback' => '__return_true' ],
				],
			]
		);

		$this->assertArrayHasKey( 'catalogue', $importer->tabs() );
		$this->assertCount( 1, $importer->operations( 'catalogue' ) );
	}

	/* ---------------------------------------------------------------------
	 * Rows and progress
	 * ------------------------------------------------------------------ */

	/**
	 * A row goes through the pipeline and then the callback.
	 */
	public function test_a_row_reaches_the_callback_already_typed(): void {
		$seen = null;

		Importers::unregister( 'myplugin' );

		$importer = $this->importer(
			[
				'operations' => [
					'products' => [
						'fields'           => [ 'price' => [ 'type' => 'number' ] ],
						'process_callback' => static function ( array $row ) use ( &$seen ): string {
							$seen = $row;

							return 'created';
						},
					],
				],
			]
		);

		$this->assertSame( 'created', $importer->operation( 'products' )->row( [ 'price' => '£9.99' ] ) );
		$this->assertSame( [ 'price' => 9.99 ], $seen );
	}

	/**
	 * A dry run does not reach the callback.
	 */
	public function test_a_dry_run_does_not_reach_the_callback(): void {
		$ran = 0;

		Importers::unregister( 'myplugin' );

		$importer = $this->importer(
			[
				'operations' => [
					'products' => [
						'fields'           => [ 'price' => [ 'type' => 'number' ] ],
						'process_callback' => static function () use ( &$ran ): string {
							++$ran;

							return 'created';
						},
					],
				],
			]
		);

		$this->assertSame( 'skipped', $importer->operation( 'products' )->row( [ 'price' => '1' ], false ) );
		$this->assertSame( 0, $ran );
	}

	/**
	 * The batch size is clamped to something a request can finish.
	 */
	public function test_the_batch_size_is_clamped(): void {
		Importers::unregister( 'myplugin' );

		$importer = $this->importer(
			[
				'operations' => [
					'products' => [ 'batch_size' => 100000, 'fields' => [ 'sku' => 'Code' ], 'process_callback' => '__return_true' ],
				],
			]
		);

		$this->assertSame( 1000, $importer->operation( 'products' )->batch_size() );
	}

	/**
	 * A sample file is offered, and it passes the operation's own rules.
	 *
	 * A sample that fails the import it is a sample of is worse than none.
	 */
	public function test_the_sample_file_would_import(): void {
		$operation = $this->importer()->operation( 'products' );

		$sample = $operation->sample();

		$this->assertStringStartsWith( "\xEF\xBB\xBF", $sample, 'Excel will read it in the wrong encoding.' );

		$path = (string) tempnam( sys_get_temp_dir(), 'ri' );

		file_put_contents( $path, $sample );

		$batch = ( new \ArrayPress\RegisterImporters\Csv\Reader( $path ) )->batch( 0, 1 );

		unlink( $path );

		$this->assertSame( array_keys( $operation->fields() ), array_keys( $batch['rows'][0] ) );
		$this->assertSame( 'created', $operation->row( $batch['rows'][0] ) );
	}

	/**
	 * Progress counts what happened, and stops keeping every message.
	 *
	 * A file that is wrong is usually wrong the same way in every row, and
	 * thirty thousand copies of one message is not a report — it is a way of
	 * making the browser stop responding.
	 */
	public function test_progress_keeps_a_hundred_messages_and_counts_the_rest(): void {
		$progress = new Progress( 'run' );

		$progress->begin( 500 );
		$progress->add( [ 'created', 'updated', 'skipped', null ] );

		$errors = [];

		for ( $row = 1; $row <= 250; $row++ ) {
			$errors[ $row ] = 'Price must be a number.';
		}

		$state = $progress->add( [], $errors );

		$this->assertSame( 4, $state['done'] );
		$this->assertSame( 1, $state['created'] );
		$this->assertSame( 1, $state['updated'] );
		$this->assertSame( 1, $state['skipped'] );
		$this->assertSame( 1, $state['failed'] );
		$this->assertCount( 100, $state['errors'] );
		$this->assertSame( 150, $state['truncated'] );
	}

	/**
	 * Render a screen and return the markup.
	 *
	 * @param Importer $importer The screen.
	 *
	 * @return string
	 */
	private function render( Importer $importer ): string {
		ob_start();

		try {
			( new Screen( $importer ) )->render();
		} finally {
			// Cleaned here and returned below: returning from a finally
			// block discards whatever exception was in flight.
			$html = (string) ob_get_clean();
		}

		return $html;
	}
}
