<?php
/**
 * REST API Class
 *
 * Handles REST API endpoints for CSV import operations.
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

use ArrayPress\RegisterImporters\Validation\FieldValidator;
use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class RestApi
 *
 * Handles REST API endpoints for import operations.
 */
class RestApi {

	/**
	 * REST namespace.
	 *
	 * @since 2.0.0
	 */
	const NAMESPACE = 'importers/v1';

	/**
	 * Whether the API has been registered.
	 *
	 * @since 2.0.0
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register REST API endpoints.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}

		add_action( 'rest_api_init', [ __CLASS__, 'register_routes' ] );

		self::$registered = true;
	}

	/**
	 * Register REST routes.
	 *
	 * @since 2.0.0
	 *
	 * @return void
	 */
	public static function register_routes(): void {
		// Upload file
		register_rest_route( self::NAMESPACE, '/upload', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_upload' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'page_id'      => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'operation_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
			],
		] );

		// Get file preview
		register_rest_route( self::NAMESPACE, '/preview/(?P<uuid>[a-f0-9-]+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'handle_preview' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'uuid'     => [
					'required' => true,
					'type'     => 'string',
				],
				'max_rows' => [
					'default'           => 5,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
			],
		] );

		// Download sample CSV
		register_rest_route( self::NAMESPACE, '/sample/(?P<page_id>[a-z0-9_-]+)/(?P<operation_id>[a-z0-9_-]+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'handle_sample_download' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
		] );

		// Dry run (validate without importing)
		register_rest_route( self::NAMESPACE, '/dry-run', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_dry_run' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'page_id'      => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'operation_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'file_uuid'    => [
					'required' => true,
					'type'     => 'string',
				],
				'field_map'    => [
					'required' => true,
					'type'     => 'object',
				],
			],
		] );

		// Start import
		register_rest_route( self::NAMESPACE, '/import/start', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_import_start' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'page_id'      => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'operation_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'file_uuid'    => [
					'required' => true,
					'type'     => 'string',
				],
				'field_map'    => [
					'required' => true,
					'type'     => 'object',
				],
			],
		] );

		// Process import batch
		register_rest_route( self::NAMESPACE, '/import/batch', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_import_batch' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'page_id'      => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'operation_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'file_uuid'    => [
					'required' => true,
					'type'     => 'string',
				],
				'offset'       => [
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
				'field_map'    => [
					'required' => true,
					'type'     => 'object',
				],
			],
		] );

		// Complete operation
		register_rest_route( self::NAMESPACE, '/complete', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_complete' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'page_id'      => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'operation_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'status'       => [
					'default' => 'complete',
					'type'    => 'string',
					'enum'    => [ 'complete', 'cancelled', 'error' ],
				],
				'file_uuid'    => [
					'type' => 'string',
				],
			],
		] );

		// Cancel operation
		register_rest_route( self::NAMESPACE, '/cancel', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_cancel' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
			'args'                => [
				'page_id'      => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'operation_id' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'file_uuid'    => [
					'type' => 'string',
				],
			],
		] );

		// Clear stats
		register_rest_route( self::NAMESPACE, '/stats/(?P<page_id>[a-z0-9_-]+)/(?P<operation_id>[a-z0-9_-]+)/clear', [
			'methods'             => WP_REST_Server::DELETABLE,
			'callback'            => [ __CLASS__, 'handle_clear_stats' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
		] );
	}

	/**
	 * Check if the current user has permission.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return bool|WP_Error
	 */
	public static function check_permission( WP_REST_Request $request ) {
		$page_id = $request->get_param( 'page_id' );

		if ( $page_id ) {
			$importers = Registry::instance()->get( $page_id );

			if ( $importers ) {
				$capability = $importers->get_config( 'capability', 'manage_options' );

				if ( ! current_user_can( $capability ) ) {
					return new WP_Error(
						'rest_forbidden',
						__( 'You do not have permission to perform this action.', 'arraypress' ),
						[ 'status' => 403 ]
					);
				}

				return true;
			}
		}

		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'arraypress' ),
				[ 'status' => 403 ]
			);
		}

		return true;
	}

	/**
	 * Handle file upload.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_upload( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );

		$importers = Registry::instance()->get( $page_id );
		if ( ! $importers || ! $importers->has_operation( $operation_id ) ) {
			return new WP_Error(
				'invalid_operation',
				__( 'Invalid operation specified.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		// Check max file size if configured
		$operation = $importers->get_operation( $operation_id );
		if ( ! empty( $operation['max_file_size'] ) && ! empty( $_FILES['import_file']['size'] ) ) {
			if ( $_FILES['import_file']['size'] > $operation['max_file_size'] ) {
				return new WP_Error(
					'file_too_large',
					sprintf(
						__( 'File exceeds maximum size of %s.', 'arraypress' ),
						size_format( $operation['max_file_size'] )
					),
					[ 'status' => 400 ]
				);
			}
		}

		$result = FileManager::handle_upload( $page_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new WP_REST_Response( [
			'success' => true,
			'file'    => [
				'uuid'          => $result['uuid'],
				'original_name' => $result['original_name'],
				'size'          => $result['size'],
				'size_human'    => $result['size_human'],
				'rows'          => $result['rows'],
				'headers'       => $result['headers'],
			],
		], 200 );
	}

	/**
	 * Handle file preview request.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_preview( WP_REST_Request $request ) {
		$uuid     = $request->get_param( 'uuid' );
		$max_rows = $request->get_param( 'max_rows' ) ?? 5;

		$preview = FileManager::get_preview( $uuid, $max_rows );

		if ( is_wp_error( $preview ) ) {
			return $preview;
		}

		return new WP_REST_Response( [
			'success' => true,
			'preview' => $preview,
		], 200 );
	}

	/**
	 * Handle sample CSV download.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_sample_download( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );

		$importers = Registry::instance()->get( $page_id );
		if ( ! $importers ) {
			return new WP_Error( 'invalid_page', __( 'Invalid importer page.', 'arraypress' ), [ 'status' => 400 ] );
		}

		$operation = $importers->get_operation( $operation_id );
		if ( ! $operation ) {
			return new WP_Error( 'invalid_operation', __( 'Invalid operation.', 'arraypress' ), [ 'status' => 400 ] );
		}

		$fields = $operation['fields'] ?? [];
		$csv    = FieldValidator::generate_sample_csv( $fields );

		return new WP_REST_Response( [
			'success'  => true,
			'csv'      => $csv,
			'filename' => sanitize_file_name( $operation_id . '-sample.csv' ),
		], 200 );
	}

	/**
	 * Handle dry run validation.
	 *
	 * Validates all rows without executing the process callback.
	 * Reports what would be created, updated, skipped, and any errors.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_dry_run( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );
		$file_uuid    = $request->get_param( 'file_uuid' );
		$field_map    = $request->get_param( 'field_map' );

		$importers = Registry::instance()->get( $page_id );
		if ( ! $importers ) {
			return new WP_Error( 'invalid_page', __( 'Invalid importer page.', 'arraypress' ), [ 'status' => 400 ] );
		}

		$operation = $importers->get_operation( $operation_id );
		if ( ! $operation ) {
			return new WP_Error( 'invalid_operation', __( 'Invalid operation.', 'arraypress' ), [ 'status' => 400 ] );
		}

		// Read all rows
		$all_rows = FileManager::read_all_rows( $file_uuid );
		if ( is_wp_error( $all_rows ) ) {
			return $all_rows;
		}

		$fields = $operation['fields'] ?? [];
		$errors = [];
		$valid  = 0;

		// Map all rows first
		$mapped_rows = [];
		foreach ( $all_rows as $row ) {
			$mapped_rows[] = self::map_row( $row, $field_map, $fields );
		}

		// Check for duplicates on unique fields
		$duplicate_errors = FieldValidator::check_duplicates( $mapped_rows, $fields );
		$errors           = array_merge( $errors, $duplicate_errors );

		// Validate each row
		$row_number = 1;
		foreach ( $mapped_rows as $mapped_row ) {
			$row_number ++;

			// Skip empty rows if configured
			if ( ! empty( $operation['skip_empty_rows'] ) && self::is_empty_row( $mapped_row ) ) {
				continue;
			}

			$validation = FieldValidator::validate_row( $mapped_row, $fields );

			if ( is_wp_error( $validation ) ) {
				$errors[] = [
					'row'     => $row_number,
					'item'    => self::get_row_identifier( $mapped_row ),
					'message' => $validation->get_error_message(),
				];
			} else {
				// Run custom validate_callback if defined
				if ( isset( $operation['validate_callback'] ) && is_callable( $operation['validate_callback'] ) ) {
					$custom_validation = call_user_func( $operation['validate_callback'], $mapped_row );
					if ( is_wp_error( $custom_validation ) ) {
						$errors[] = [
							'row'     => $row_number,
							'item'    => self::get_row_identifier( $mapped_row ),
							'message' => $custom_validation->get_error_message(),
						];
						continue;
					}
				}

				$valid ++;
			}
		}

		return new WP_REST_Response( [
			'success'     => true,
			'total_rows'  => count( $mapped_rows ),
			'valid_rows'  => $valid,
			'error_count' => count( $errors ),
			'errors'      => array_slice( $errors, 0, 20 ),
		], 200 );
	}

	/**
	 * Handle import start.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_import_start( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );
		$file_uuid    = $request->get_param( 'file_uuid' );

		$file_data = FileManager::get_file( $file_uuid );

		if ( ! $file_data ) {
			return new WP_Error(
				'file_not_found',
				__( 'Import file not found or expired.', 'arraypress' ),
				[ 'status' => 404 ]
			);
		}

		$importers = Registry::instance()->get( $page_id );
		$operation = $importers ? $importers->get_operation( $operation_id ) : null;

		// Fire before_import callback
		if ( $operation && isset( $operation['before_import'] ) && is_callable( $operation['before_import'] ) ) {
			call_user_func( $operation['before_import'] );
		}

		$stats = StatsManager::init_run(
			$page_id,
			$operation_id,
			$file_data['original_name'],
			$file_data['rows']
		);

		return new WP_REST_Response( [
			'success'     => true,
			'total_items' => $file_data['rows'],
			'batch_size'  => self::get_batch_size( $page_id, $operation_id ),
			'stats'       => $stats,
		], 200 );
	}

	/**
	 * Handle import batch processing.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_import_batch( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );
		$file_uuid    = $request->get_param( 'file_uuid' );
		$offset       = $request->get_param( 'offset' );
		$field_map    = $request->get_param( 'field_map' );

		$importers = Registry::instance()->get( $page_id );
		if ( ! $importers ) {
			return new WP_Error( 'invalid_page', __( 'Invalid importer page.', 'arraypress' ), [ 'status' => 400 ] );
		}

		$operation = $importers->get_operation( $operation_id );
		if ( ! $operation ) {
			return new WP_Error( 'invalid_operation', __( 'Invalid import operation.', 'arraypress' ), [ 'status' => 400 ] );
		}

		$batch_size = $operation['batch_size'] ?? 100;
		$batch_data = FileManager::read_batch( $file_uuid, $offset, $batch_size );

		if ( is_wp_error( $batch_data ) ) {
			return $batch_data;
		}

		$results = [
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'errors'    => [],
			'processed' => 0,
		];

		$process_callback = $operation['process_callback'] ?? null;

		if ( ! is_callable( $process_callback ) ) {
			return new WP_Error( 'no_callback', __( 'No process callback defined.', 'arraypress' ), [ 'status' => 500 ] );
		}

		$fields     = $operation['fields'] ?? [];
		$row_number = $offset + 1;

		foreach ( $batch_data['rows'] as $row ) {
			$row_number ++;
			$results['processed'] ++;

			// Map CSV columns to field keys
			$mapped_row = self::map_row( $row, $field_map, $fields );

			// Skip empty rows if configured
			if ( ! empty( $operation['skip_empty_rows'] ) && self::is_empty_row( $mapped_row ) ) {
				$results['skipped'] ++;
				continue;
			}

			// Run field validation pipeline
			$validated_row = FieldValidator::process_row( $mapped_row, $fields );

			if ( is_wp_error( $validated_row ) ) {
				$results['failed'] ++;
				$results['errors'][] = [
					'row'     => $row_number,
					'item'    => self::get_row_identifier( $mapped_row ),
					'message' => $validated_row->get_error_message(),
				];
				continue;
			}

			// Run custom validate_callback if defined
			if ( isset( $operation['validate_callback'] ) && is_callable( $operation['validate_callback'] ) ) {
				$validation = call_user_func( $operation['validate_callback'], $validated_row );

				if ( is_wp_error( $validation ) ) {
					$results['failed'] ++;
					$results['errors'][] = [
						'row'     => $row_number,
						'item'    => self::get_row_identifier( $validated_row ),
						'message' => $validation->get_error_message(),
					];
					continue;
				}
			}

			try {
				$result = call_user_func( $process_callback, $validated_row );

				if ( is_wp_error( $result ) ) {
					$results['failed'] ++;
					$results['errors'][] = [
						'row'     => $row_number,
						'item'    => self::get_row_identifier( $validated_row ),
						'message' => $result->get_error_message(),
					];
				} elseif ( $result === 'created' ) {
					$results['created'] ++;
				} elseif ( $result === 'updated' ) {
					$results['updated'] ++;
				} elseif ( $result === 'skipped' ) {
					$results['skipped'] ++;
				} else {
					$results['created'] ++;
				}
			} catch ( Exception $e ) {
				$results['failed'] ++;
				$results['errors'][] = [
					'row'     => $row_number,
					'item'    => self::get_row_identifier( $validated_row ),
					'message' => $e->getMessage(),
				];
			}
		}

		// Update stats
		StatsManager::update_batch( $page_id, $operation_id, $results );

		$stats           = StatsManager::get_stats( $page_id, $operation_id );
		$total_processed = $stats['created'] + $stats['updated'] + $stats['skipped'] + $stats['failed'];
		$total_items     = $stats['total'] ?: $total_processed;
		$percentage      = $total_items > 0 ? round( ( $total_processed / $total_items ) * 100 ) : 0;

		return new WP_REST_Response( [
			'success'         => true,
			'processed'       => $results['processed'],
			'created'         => $results['created'],
			'updated'         => $results['updated'],
			'skipped'         => $results['skipped'],
			'failed'          => $results['failed'],
			'errors'          => $results['errors'],
			'has_more'        => $batch_data['has_more'],
			'offset'          => $offset + $batch_data['count'],
			'total_processed' => $total_processed,
			'total_items'     => $total_items,
			'percentage'      => $percentage,
			'stats'           => $stats,
		], 200 );
	}

	/**
	 * Handle operation completion.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_complete( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );
		$status       = $request->get_param( 'status' );
		$file_uuid    = $request->get_param( 'file_uuid' );

		$stats = StatsManager::complete_run( $page_id, $operation_id, $status );

		// Fire after_import callback
		$importers = Registry::instance()->get( $page_id );
		$operation = $importers ? $importers->get_operation( $operation_id ) : null;

		if ( $operation && isset( $operation['after_import'] ) && is_callable( $operation['after_import'] ) ) {
			call_user_func( $operation['after_import'], $stats );
		}

		// Clean up file
		if ( $file_uuid ) {
			FileManager::delete_file( $file_uuid );
		}

		return new WP_REST_Response( [
			'success' => true,
			'stats'   => $stats,
		], 200 );
	}

	/**
	 * Handle cancel operation request.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_cancel( WP_REST_Request $request ): WP_REST_Response {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );
		$file_uuid    = $request->get_param( 'file_uuid' );

		$stats = StatsManager::complete_run( $page_id, $operation_id, 'cancelled' );

		if ( $file_uuid ) {
			FileManager::delete_file( $file_uuid );
		}

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Operation cancelled.', 'arraypress' ),
			'stats'   => $stats,
		], 200 );
	}

	/**
	 * Handle clear stats request.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_clear_stats( WP_REST_Request $request ): WP_REST_Response {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );

		StatsManager::clear_stats( $page_id, $operation_id );

		return new WP_REST_Response( [
			'success' => true,
			'message' => __( 'Stats cleared successfully.', 'arraypress' ),
		], 200 );
	}

	/** Helpers *****************************************************************/

	/**
	 * Get batch size for an operation.
	 *
	 * @since 2.0.0
	 *
	 * @param string $page_id      Page ID.
	 * @param string $operation_id Operation ID.
	 *
	 * @return int
	 */
	private static function get_batch_size( string $page_id, string $operation_id ): int {
		$importers = Registry::instance()->get( $page_id );

		if ( $importers ) {
			$operation = $importers->get_operation( $operation_id );

			if ( $operation ) {
				return $operation['batch_size'] ?? 100;
			}
		}

		return 100;
	}

	/**
	 * Map a CSV row to defined fields.
	 *
	 * @since 2.0.0
	 *
	 * @param array $row       Raw row data.
	 * @param array $field_map Mapping of field_key => csv_column.
	 * @param array $fields    Field definitions.
	 *
	 * @return array Mapped data (keys are field keys, values are raw CSV values).
	 */
	private static function map_row( array $row, array $field_map, array $fields ): array {
		$mapped = [];

		foreach ( $field_map as $field_key => $csv_column ) {
			$value = $row[ $csv_column ] ?? null;

			// Apply default if empty and not handled by FieldValidator
			if ( ( $value === null || $value === '' ) && isset( $fields[ $field_key ]['default'] ) ) {
				$value = $fields[ $field_key ]['default'];
			}

			$mapped[ $field_key ] = $value;
		}

		// Include unmapped fields with defaults
		foreach ( $fields as $field_key => $field ) {
			if ( ! isset( $mapped[ $field_key ] ) && isset( $field['default'] ) ) {
				$mapped[ $field_key ] = $field['default'];
			}
		}

		return $mapped;
	}

	/**
	 * Check if a row is empty.
	 *
	 * @since 2.0.0
	 *
	 * @param array $row Row data.
	 *
	 * @return bool
	 */
	private static function is_empty_row( array $row ): bool {
		foreach ( $row as $value ) {
			if ( $value !== null && $value !== '' ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Get a human-readable identifier for a row.
	 *
	 * @since 2.0.0
	 *
	 * @param array $row Row data.
	 *
	 * @return string
	 */
	private static function get_row_identifier( array $row ): string {
		$id_fields = [ 'id', 'sku', 'email', 'name', 'title', 'slug', 'code' ];

		foreach ( $id_fields as $field ) {
			if ( ! empty( $row[ $field ] ) ) {
				return (string) $row[ $field ];
			}
		}

		foreach ( $row as $value ) {
			if ( ! empty( $value ) ) {
				return (string) $value;
			}
		}

		return __( 'Unknown', 'arraypress' );
	}

}
