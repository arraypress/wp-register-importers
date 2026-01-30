<?php
/**
 * REST API Class
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

use Exception;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class RestApi
 *
 * Handles REST API endpoints for import/sync operations.
 */
class RestApi {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'importers/v1';

	/**
	 * Whether the API has been registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Register REST API endpoints.
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

		// Start sync
		register_rest_route( self::NAMESPACE, '/sync/start', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_sync_start' ],
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

		// Process sync batch
		register_rest_route( self::NAMESPACE, '/sync/batch', [
			'methods'             => WP_REST_Server::CREATABLE,
			'callback'            => [ __CLASS__, 'handle_sync_batch' ],
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
				'cursor'       => [
					'default' => '',
					'type'    => 'string',
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
				'duration'     => [
					'default'           => 0,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				],
				'file_uuid'    => [
					'type' => 'string',
				],
			],
		] );

		// Get operation stats
		register_rest_route( self::NAMESPACE, '/stats/(?P<page_id>[a-z0-9_-]+)/(?P<operation_id>[a-z0-9_-]+)', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ __CLASS__, 'handle_get_stats' ],
			'permission_callback' => [ __CLASS__, 'check_permission' ],
		] );
	}

	/**
	 * Check if the current user has permission.
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

		// Default to manage_options
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
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_upload( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );

		// Verify operation exists
		$importers = Registry::instance()->get( $page_id );
		if ( ! $importers || ! $importers->has_operation( $operation_id ) ) {
			return new WP_Error(
				'invalid_operation',
				__( 'Invalid operation specified.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		// Handle the upload
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
	 * Handle import start.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_import_start( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );
		$file_uuid    = $request->get_param( 'file_uuid' );

		// Get file info
		$file_data = FileManager::get_file( $file_uuid );

		if ( ! $file_data ) {
			return new WP_Error(
				'file_not_found',
				__( 'Import file not found or expired.', 'arraypress' ),
				[ 'status' => 404 ]
			);
		}

		// Initialize stats
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

		// Get operation
		$importers = Registry::instance()->get( $page_id );
		if ( ! $importers ) {
			return new WP_Error(
				'invalid_page',
				__( 'Invalid importer page.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		$operation = $importers->get_operation( $operation_id );
		if ( ! $operation || $operation['type'] !== 'import' ) {
			return new WP_Error(
				'invalid_operation',
				__( 'Invalid import operation.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		// Get batch size
		$batch_size = $operation['batch_size'] ?? 100;

		// Read batch from file
		$batch_data = FileManager::read_batch( $file_uuid, $offset, $batch_size );

		if ( is_wp_error( $batch_data ) ) {
			return $batch_data;
		}

		// Process each row
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
			return new WP_Error(
				'no_callback',
				__( 'No process callback defined for this operation.', 'arraypress' ),
				[ 'status' => 500 ]
			);
		}

		$row_number = $offset + 1; // Start from 1-based row number (after header)

		foreach ( $batch_data['rows'] as $row ) {
			$row_number++;
			$results['processed']++;

			// Map fields according to field_map
			$mapped_row = self::map_row( $row, $field_map, $operation['fields'] ?? [] );

			// Skip empty rows if configured
			if ( $operation['skip_empty_rows'] && self::is_empty_row( $mapped_row ) ) {
				$results['skipped']++;
				continue;
			}

			// Run validate callback if defined
			if ( isset( $operation['validate_callback'] ) && is_callable( $operation['validate_callback'] ) ) {
				$validation = call_user_func( $operation['validate_callback'], $mapped_row );

				if ( is_wp_error( $validation ) ) {
					$results['failed']++;
					$results['errors'][] = [
						'row'     => $row_number,
						'item'    => self::get_row_identifier( $mapped_row ),
						'message' => $validation->get_error_message(),
					];
					continue;
				}
			}

			try {
				$result = call_user_func( $process_callback, $mapped_row );

				if ( is_wp_error( $result ) ) {
					$results['failed']++;
					$results['errors'][] = [
						'row'     => $row_number,
						'item'    => self::get_row_identifier( $mapped_row ),
						'message' => $result->get_error_message(),
					];
				} elseif ( $result === 'created' ) {
					$results['created']++;
				} elseif ( $result === 'updated' ) {
					$results['updated']++;
				} elseif ( $result === 'skipped' ) {
					$results['skipped']++;
				} else {
					// Assume success if truthy
					$results['created']++;
				}
			} catch ( Exception $e ) {
				$results['failed']++;
				$results['errors'][] = [
					'row'     => $row_number,
					'item'    => self::get_row_identifier( $mapped_row ),
					'message' => $e->getMessage(),
				];
			}
		}

		// Update stats
		StatsManager::update_batch( $page_id, $operation_id, $results );

		// Get updated stats
		$stats = StatsManager::get_stats( $page_id, $operation_id );

		// Calculate totals
		$total_processed = $stats['created'] + $stats['updated'] + $stats['skipped'] + $stats['failed'];
		$total_items     = $stats['source_total'] ?? $stats['total'];
		$percentage      = $total_items > 0 ? round( ( $total_processed / $total_items ) * 100 ) : 0;

		return new WP_REST_Response( [
			'success'         => true,
			'batch'           => floor( $offset / $batch_size ) + 1,
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
	 * Handle sync start.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_sync_start( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );

		// Initialize stats (total unknown until first fetch)
		$stats = StatsManager::init_run( $page_id, $operation_id );

		return new WP_REST_Response( [
			'success'    => true,
			'batch_size' => self::get_batch_size( $page_id, $operation_id ),
			'stats'      => $stats,
		], 200 );
	}

	/**
	 * Handle sync batch processing.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_sync_batch( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );
		$cursor       = $request->get_param( 'cursor' );

		// Get operation
		$importers = Registry::instance()->get( $page_id );
		if ( ! $importers ) {
			return new WP_Error(
				'invalid_page',
				__( 'Invalid importer page.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		$operation = $importers->get_operation( $operation_id );
		if ( ! $operation || $operation['type'] !== 'sync' ) {
			return new WP_Error(
				'invalid_operation',
				__( 'Invalid sync operation.', 'arraypress' ),
				[ 'status' => 400 ]
			);
		}

		// Get batch size
		$batch_size = $operation['batch_size'] ?? 100;

		// Call data callback to fetch items
		$data_callback = $operation['data_callback'] ?? null;

		if ( ! is_callable( $data_callback ) ) {
			return new WP_Error(
				'no_data_callback',
				__( 'No data callback defined for this sync operation.', 'arraypress' ),
				[ 'status' => 500 ]
			);
		}

		try {
			$fetch_result = call_user_func( $data_callback, $cursor, $batch_size );
		} catch ( Exception $e ) {
			return new WP_Error(
				'fetch_error',
				$e->getMessage(),
				[ 'status' => 500 ]
			);
		}

		// Validate fetch result
		if ( ! is_array( $fetch_result ) || ! isset( $fetch_result['items'] ) ) {
			return new WP_Error(
				'invalid_fetch_result',
				__( 'Data callback returned invalid result.', 'arraypress' ),
				[ 'status' => 500 ]
			);
		}

		$items      = $fetch_result['items'] ?? [];
		$has_more   = $fetch_result['has_more'] ?? false;
		$new_cursor = $fetch_result['cursor'] ?? '';
		$total      = $fetch_result['total'] ?? null;

		// Process each item
		$results = [
			'created'   => 0,
			'updated'   => 0,
			'skipped'   => 0,
			'failed'    => 0,
			'errors'    => [],
			'processed' => 0,
			'total'     => $total,
		];

		$process_callback = $operation['process_callback'] ?? null;

		if ( ! is_callable( $process_callback ) ) {
			return new WP_Error(
				'no_callback',
				__( 'No process callback defined for this operation.', 'arraypress' ),
				[ 'status' => 500 ]
			);
		}

		foreach ( $items as $item ) {
			$results['processed']++;

			try {
				$result = call_user_func( $process_callback, $item );

				if ( is_wp_error( $result ) ) {
					$results['failed']++;
					$results['errors'][] = [
						'item'    => self::get_item_identifier( $item ),
						'message' => $result->get_error_message(),
					];
				} elseif ( $result === 'created' ) {
					$results['created']++;
				} elseif ( $result === 'updated' ) {
					$results['updated']++;
				} elseif ( $result === 'skipped' ) {
					$results['skipped']++;
				} else {
					// Assume success if truthy
					$results['created']++;
				}
			} catch ( Exception $e ) {
				$results['failed']++;
				$results['errors'][] = [
					'item'    => self::get_item_identifier( $item ),
					'message' => $e->getMessage(),
				];
			}
		}

		// Update stats
		StatsManager::update_batch( $page_id, $operation_id, $results );

		// Get updated stats
		$stats = StatsManager::get_stats( $page_id, $operation_id );

		// Calculate totals
		$total_processed = $stats['created'] + $stats['updated'] + $stats['skipped'] + $stats['failed'];
		$total_items     = $stats['source_total'] ?? $total_processed;
		$percentage      = $total_items > 0 ? round( ( $total_processed / $total_items ) * 100 ) : 0;

		return new WP_REST_Response( [
			'success'         => true,
			'processed'       => $results['processed'],
			'created'         => $results['created'],
			'updated'         => $results['updated'],
			'skipped'         => $results['skipped'],
			'failed'          => $results['failed'],
			'errors'          => $results['errors'],
			'has_more'        => $has_more,
			'cursor'          => $new_cursor,
			'total_processed' => $total_processed,
			'total_items'     => $total_items,
			'percentage'      => $percentage,
			'stats'           => $stats,
		], 200 );
	}

	/**
	 * Handle operation completion.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function handle_complete( WP_REST_Request $request ) {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );
		$status       = $request->get_param( 'status' );
		$duration     = $request->get_param( 'duration' );
		$file_uuid    = $request->get_param( 'file_uuid' );

		// Complete the stats
		$stats = StatsManager::complete_run( $page_id, $operation_id, $status, $duration );

		// Clean up file if import
		if ( $file_uuid ) {
			FileManager::delete_file( $file_uuid );
		}

		return new WP_REST_Response( [
			'success' => true,
			'stats'   => $stats,
		], 200 );
	}

	/**
	 * Handle get stats request.
	 *
	 * @param WP_REST_Request $request The request object.
	 *
	 * @return WP_REST_Response
	 */
	public static function handle_get_stats( WP_REST_Request $request ): WP_REST_Response {
		$page_id      = $request->get_param( 'page_id' );
		$operation_id = $request->get_param( 'operation_id' );

		$stats = StatsManager::get_stats( $page_id, $operation_id );

		return new WP_REST_Response( [
			'success' => true,
			'stats'   => $stats,
		], 200 );
	}

	/**
	 * Get batch size for an operation.
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
	 * @param array $row       Raw row data.
	 * @param array $field_map Mapping of field_key => csv_column.
	 * @param array $fields    Field definitions.
	 *
	 * @return array Mapped and sanitized data.
	 */
	private static function map_row( array $row, array $field_map, array $fields ): array {
		$mapped = [];

		foreach ( $field_map as $field_key => $csv_column ) {
			$value = $row[ $csv_column ] ?? null;

			// Apply sanitize callback if defined
			if ( isset( $fields[ $field_key ]['sanitize_callback'] ) && is_callable( $fields[ $field_key ]['sanitize_callback'] ) ) {
				$value = call_user_func( $fields[ $field_key ]['sanitize_callback'], $value );
			} elseif ( $value !== null ) {
				$value = sanitize_text_field( $value );
			}

			// Apply default if empty
			if ( ( $value === null || $value === '' ) && isset( $fields[ $field_key ]['default'] ) ) {
				$value = $fields[ $field_key ]['default'];
			}

			$mapped[ $field_key ] = $value;
		}

		return $mapped;
	}

	/**
	 * Check if a row is empty.
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
	 * @param array $row Row data.
	 *
	 * @return string
	 */
	private static function get_row_identifier( array $row ): string {
		// Try common identifier fields
		$id_fields = [ 'id', 'sku', 'email', 'name', 'title', 'slug' ];

		foreach ( $id_fields as $field ) {
			if ( ! empty( $row[ $field ] ) ) {
				return (string) $row[ $field ];
			}
		}

		// Return first non-empty value
		foreach ( $row as $value ) {
			if ( ! empty( $value ) ) {
				return (string) $value;
			}
		}

		return __( 'Unknown', 'arraypress' );
	}

	/**
	 * Get a human-readable identifier for an item.
	 *
	 * @param mixed $item Item data (object or array).
	 *
	 * @return string
	 */
	private static function get_item_identifier( $item ): string {
		if ( is_object( $item ) ) {
			$item = (array) $item;
		}

		if ( ! is_array( $item ) ) {
			return (string) $item;
		}

		return self::get_row_identifier( $item );
	}

}
