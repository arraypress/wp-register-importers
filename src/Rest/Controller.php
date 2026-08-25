<?php
/**
 * Controller
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters\Rest;

use ArrayPress\RegisterImporters\Csv\Reader;
use ArrayPress\RegisterImporters\Csv\Upload;
use ArrayPress\RegisterImporters\Importer;
use ArrayPress\RegisterImporters\Importers;
use ArrayPress\RegisterImporters\Operation;
use ArrayPress\RegisterImporters\Progress;
use ArrayPress\RegisterImporters\Row\Resolve;
use ArrayPress\RegisterImporters\Utils\Runtime;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * The endpoints an import screen talks to.
 *
 * The namespace comes from Runtime rather than being written here, and that
 * is not tidiness. Strauss renames classes and leaves string literals alone,
 * so two plugins each bundling a prefixed copy of this library would register
 * the same route — and `WP_REST_Server::register_route()` merges same-path
 * registrations by appending handlers, then dispatches to the first whose
 * methods match.
 *
 * One plugin's importer would answer the other's requests: its capability,
 * its registry of operations, its process callback writing rows out of a file
 * it was never shown.
 */
final class Controller {

	/**
	 * Whether the routes are attached.
	 *
	 * @var bool
	 */
	private static bool $booted = false;

	/**
	 * Attach the routes, once.
	 *
	 * @return void
	 */
	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}

		self::$booted = true;

		add_action( 'rest_api_init', [ __CLASS__, 'register' ] );
	}

	/**
	 * Register them.
	 *
	 * @return void
	 */
	public static function register(): void {
		$namespace = Runtime::rest_namespace();

		$page = [
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
		];

		$operation = [
			'required'          => true,
			'type'              => 'string',
			'sanitize_callback' => 'sanitize_key',
		];

		$uuid = [
			'required'          => true,
			'type'              => 'string',
			'validate_callback' => static fn( $value ): bool => 1 === preg_match( '/^[a-f0-9-]{36}$/', (string) $value ),
		];

		register_rest_route(
			$namespace,
			'/upload',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'upload' ],
				'permission_callback' => [ __CLASS__, 'may' ],
				'args'                => [ 'page' => $page ],
			]
		);

		register_rest_route(
			$namespace,
			'/file/(?P<uuid>[a-f0-9-]{36})',
			[
				[
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => [ __CLASS__, 'preview' ],
					'permission_callback' => [ __CLASS__, 'may' ],
					'args'                => [
						'page' => $page,
						'uuid' => $uuid,
					],
				],
				[
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => [ __CLASS__, 'discard' ],
					'permission_callback' => [ __CLASS__, 'may' ],
					'args'                => [
						'page' => $page,
						'uuid' => $uuid,
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/sample',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'sample' ],
				'permission_callback' => [ __CLASS__, 'may' ],
				'args'                => [
					'page' => $page,
					'operation' => $operation,
				],
			]
		);

		register_rest_route(
			$namespace,
			'/run',
			[
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => [ __CLASS__, 'run' ],
				'permission_callback' => [ __CLASS__, 'may' ],
				'args'                => [
					'page'      => $page,
					'operation' => $operation,
					'uuid'      => $uuid,
					'mapping'   => [
						'required' => true,
						'type' => 'object',
					],
					'offset'    => [
						'type' => 'integer',
						'default' => 0,
						'minimum' => 0,
					],
					'commit'    => [
						'type' => 'boolean',
						'default' => true,
					],
				],
			]
		);

		register_rest_route(
			$namespace,
			'/progress',
			[
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => [ __CLASS__, 'progress' ],
				'permission_callback' => [ __CLASS__, 'may' ],
				'args'                => [
					'page' => $page,
					'uuid' => $uuid,
				],
			]
		);
	}

	/**
	 * Whether this user may do what they are asking.
	 *
	 * The capability is the one the named screen declared. A request naming a
	 * screen that is not registered is refused rather than falling back to
	 * `manage_options` — an unregistered page is not a page this library can
	 * speak for, and answering anyway is how a request for one plugin's
	 * importer gets checked against another's rules.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return true|WP_Error
	 */
	public static function may( WP_REST_Request $request ): bool|WP_Error {
		$importer = Importers::get( (string) $request->get_param( 'page' ) );

		if ( null === $importer ) {
			return new WP_Error(
				'rest_no_such_importer',
				__( 'There is no import screen by that name.', 'arraypress' ),
				[ 'status' => 404 ]
			);
		}

		if ( ! $importer->is_permitted() ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You are not allowed to import here.', 'arraypress' ),
				[ 'status' => rest_authorization_required_code() ]
			);
		}

		return true;
	}

	/**
	 * Take a file.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$stored = Upload::receive( (string) $request->get_param( 'page' ) );

		if ( $stored instanceof WP_Error ) {
			return self::failed( $stored );
		}

		unset( $stored['path'], $stored['user'] );

		return rest_ensure_response( $stored );
	}

	/**
	 * The first few rows of a file, to check the columns line up.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function preview( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$file = self::file( $request );

		if ( $file instanceof WP_Error ) {
			return self::failed( $file );
		}

		$batch = ( new Reader( (string) $file['path'] ) )->batch( 0, 5 );

		if ( $batch instanceof WP_Error ) {
			return self::failed( $batch );
		}

		return rest_ensure_response(
			[
				'headers'   => $file['headers'],
				'rows'      => $batch['rows'],
				'malformed' => $batch['malformed'],
				'total'     => $file['rows'],
			]
		);
	}

	/**
	 * Throw a file away.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response
	 */
	public static function discard( WP_REST_Request $request ): WP_REST_Response {
		return rest_ensure_response(
			[ 'discarded' => Upload::discard( (string) $request->get_param( 'uuid' ) ) ]
		);
	}

	/**
	 * A file showing what an operation expects.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function sample( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$operation = self::operation( $request );

		if ( $operation instanceof WP_Error ) {
			return self::failed( $operation );
		}

		return rest_ensure_response(
			[
				'filename' => $operation->sample_filename(),
				'contents' => $operation->sample(),
			]
		);
	}

	/**
	 * Do one batch, for real or as a rehearsal.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function run( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$operation = self::operation( $request );

		if ( $operation instanceof WP_Error ) {
			return self::failed( $operation );
		}

		$file = self::file( $request );

		if ( $file instanceof WP_Error ) {
			return self::failed( $file );
		}

		$commit  = (bool) $request->get_param( 'commit' );
		$offset  = (int) $request->get_param( 'offset' );
		$mapping = self::mapping( (array) $request->get_param( 'mapping' ), $operation );

		if ( $mapping instanceof WP_Error ) {
			return self::failed( $mapping );
		}

		$progress = new Progress( (string) $file['uuid'] . ( $commit ? '' : '_check' ) );

		if ( 0 === $offset ) {
			$progress->begin( (int) $file['rows'] );

			if ( $commit && is_callable( $operation->get( 'before_import' ) ) ) {
				call_user_func( $operation->get( 'before_import' ), $file );
			}
		}

		$batch = ( new Reader( (string) $file['path'], (string) $operation->get( 'separator', ',' ) ) )
			->batch( $offset, $operation->batch_size() );

		if ( $batch instanceof WP_Error ) {
			return self::failed( $batch );
		}

		// A term created earlier in this run is a term this batch should
		// find, and a batch is its own request, so the memo starts empty.
		Resolve::forget();

		$outcomes = [];
		$errors   = [];

		foreach ( $batch['rows'] as $index => $row ) {
			$number = $offset + $index + 1;
			$result = $operation->row( self::map( $row, $mapping ), $commit );

			if ( $result instanceof WP_Error ) {
				$outcomes[]        = null;
				$errors[ $number ] = $result->get_error_message();

				continue;
			}

			$outcomes[] = $result;
		}

		foreach ( $batch['malformed'] as $malformed ) {
			$errors[ $malformed + 1 ] = __( 'This row does not have the same number of columns as the header.', 'arraypress' );
		}

		$state    = $progress->add( $outcomes, $errors );
		$finished = ! $batch['has_more'];

		if ( $finished ) {
			$state = $progress->finish();

			if ( $commit && is_callable( $operation->get( 'after_import' ) ) ) {
				call_user_func( $operation->get( 'after_import' ), $state, $file );
			}
		}

		return rest_ensure_response(
			[
				'offset'   => $offset + $batch['count'],
				'finished' => $finished,
				'progress' => $state,
			]
		);
	}

	/**
	 * What has happened so far.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function progress( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$file = self::file( $request );

		if ( $file instanceof WP_Error ) {
			return self::failed( $file );
		}

		return rest_ensure_response(
			[ 'progress' => ( new Progress( (string) $file['uuid'] ) )->read() ]
		);
	}

	/**
	 * The importer a request names.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return Importer|WP_Error
	 */
	private static function importer( WP_REST_Request $request ): Importer|WP_Error {
		$importer = Importers::get( (string) $request->get_param( 'page' ) );

		return null === $importer
			? new WP_Error( 'no_such_importer', __( 'There is no import screen by that name.', 'arraypress' ) )
			: $importer;
	}

	/**
	 * The operation a request names.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return Operation|WP_Error
	 */
	private static function operation( WP_REST_Request $request ): Operation|WP_Error {
		$importer = self::importer( $request );

		if ( $importer instanceof WP_Error ) {
			return $importer;
		}

		$operation = $importer->operation( (string) $request->get_param( 'operation' ) );

		return null === $operation
			? new WP_Error( 'no_such_operation', __( 'That import screen has nothing by that name to import.', 'arraypress' ) )
			: $operation;
	}

	/**
	 * The file a request names.
	 *
	 * @param WP_REST_Request $request The request.
	 *
	 * @return array<string, mixed>|WP_Error
	 */
	private static function file( WP_REST_Request $request ): array|WP_Error {
		$file = Upload::find( (string) $request->get_param( 'uuid' ) );

		if ( null === $file ) {
			return new WP_Error(
				'no_such_file',
				__( 'That file has expired, or was uploaded by somebody else.', 'arraypress' )
			);
		}

		// A file uploaded for one screen cannot be imported through another.
		// Both checks are needed: the capability says who, this says what.
		return (string) $file['page'] === (string) $request->get_param( 'page' )
			? $file
			: new WP_Error( 'wrong_screen', __( 'That file was uploaded for a different import screen.', 'arraypress' ) );
	}

	/**
	 * Check the column mapping covers what has to be covered.
	 *
	 * @param array<string, mixed> $mapping   Field key => column name.
	 * @param Operation            $operation The operation.
	 *
	 * @return array<string, string>|WP_Error
	 */
	private static function mapping( array $mapping, Operation $operation ): array|WP_Error {
		$clean = [];

		foreach ( $operation->fields() as $key => $field ) {
			$column = trim( (string) ( $mapping[ $key ] ?? '' ) );

			if ( '' !== $column ) {
				$clean[ $key ] = $column;
			}
		}

		$missing = array_diff( $operation->required(), array_keys( $clean ) );

		if ( [] !== $missing ) {
			$labels = array_map(
				static fn( string $key ): string => (string) ( $operation->fields()[ $key ]['label'] ?? $key ),
				$missing
			);

			return new WP_Error(
				'unmapped_columns',
				sprintf(
					/* translators: %s: the columns that still need a source. */
					__( 'These columns still need a column from the file: %s.', 'arraypress' ),
					implode( ', ', $labels )
				)
			);
		}

		return $clean;
	}

	/**
	 * Turn a row of the file into a row of field keys.
	 *
	 * @param array<string, string> $row     The row, keyed by the file's headers.
	 * @param array<string, string> $mapping Field key => column name.
	 *
	 * @return array<string, mixed>
	 */
	private static function map( array $row, array $mapping ): array {
		$mapped = [];

		foreach ( $mapping as $key => $column ) {
			$mapped[ $key ] = $row[ $column ] ?? null;
		}

		return $mapped;
	}

	/**
	 * Give an error a status, so the browser can tell a refusal from a bug.
	 *
	 * @param WP_Error $error The error.
	 *
	 * @return WP_Error
	 */
	private static function failed( WP_Error $error ): WP_Error {
		$error->add_data( [ 'status' => 400 ], $error->get_error_code() );

		return $error;
	}
}
