<?php
/**
 * Stats Manager
 *
 * Handles automatic tracking and storage of import/sync statistics.
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2025, ArrayPress Limited
 * @license     GPL2+
 * @since       1.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

/**
 * Class StatsManager
 *
 * Manages statistics storage and retrieval for import/sync operations.
 */
class StatsManager {

	/**
	 * Option prefix for stats storage.
	 *
	 * @var string
	 */
	const OPTION_PREFIX = 'importers_stats_';

	/**
	 * Maximum number of errors to store per operation.
	 *
	 * @var int
	 */
	const MAX_STORED_ERRORS = 50;

	/**
	 * Maximum number of history entries to keep.
	 *
	 * @var int
	 */
	const MAX_HISTORY_ENTRIES = 20;

	/**
	 * Get the option key for an operation's stats.
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 *
	 * @return string
	 */
	public static function get_option_key( string $page_id, string $operation_id ): string {
		return self::OPTION_PREFIX . sanitize_key( $page_id ) . '_' . sanitize_key( $operation_id );
	}

	/**
	 * Get stats for an operation.
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 *
	 * @return array Stats array with defaults if not found.
	 */
	public static function get_stats( string $page_id, string $operation_id ): array {
		$option_key = self::get_option_key( $page_id, $operation_id );
		$stats      = get_option( $option_key, [] );

		return wp_parse_args( $stats, self::get_default_stats() );
	}

	/**
	 * Get default stats structure.
	 *
	 * @return array
	 */
	public static function get_default_stats(): array {
		return [
			'last_run'     => null,
			'last_status'  => null, // 'complete', 'cancelled', 'error'
			'duration'     => 0,
			'total'        => 0,
			'created'      => 0,
			'updated'      => 0,
			'skipped'      => 0,
			'failed'       => 0,
			'errors'       => [],
			'history'      => [],
			'run_count'    => 0,
			'source_file'  => null, // For imports: original filename
			'source_total' => null, // For syncs: total items in source (if known)
		];
	}

	/**
	 * Initialize stats for a new operation run.
	 *
	 * @param string      $page_id      The importer page ID.
	 * @param string      $operation_id The operation ID.
	 * @param string|null $source_file  Original filename for imports.
	 * @param int|null    $total        Total items to process (if known).
	 *
	 * @return array The initialized stats.
	 */
	public static function init_run( string $page_id, string $operation_id, ?string $source_file = null, ?int $total = null ): array {
		$stats = self::get_stats( $page_id, $operation_id );

		// Reset current run stats
		$stats['last_run']     = current_time( 'mysql', true );
		$stats['last_status']  = 'running';
		$stats['duration']     = 0;
		$stats['total']        = $total ?? 0;
		$stats['created']      = 0;
		$stats['updated']      = 0;
		$stats['skipped']      = 0;
		$stats['failed']       = 0;
		$stats['errors']       = [];
		$stats['source_file']  = $source_file;
		$stats['source_total'] = $total;
		$stats['run_count']    = ( $stats['run_count'] ?? 0 ) + 1;

		self::save_stats( $page_id, $operation_id, $stats );

		return $stats;
	}

	/**
	 * Update stats after processing a batch.
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 * @param array  $batch_result Results from the batch processing.
	 *
	 * @return array Updated stats.
	 */
	public static function update_batch( string $page_id, string $operation_id, array $batch_result ): array {
		$stats = self::get_stats( $page_id, $operation_id );

		// Increment counters
		$stats['created'] += $batch_result['created'] ?? 0;
		$stats['updated'] += $batch_result['updated'] ?? 0;
		$stats['skipped'] += $batch_result['skipped'] ?? 0;
		$stats['failed']  += $batch_result['failed'] ?? 0;

		// Update total if provided
		if ( isset( $batch_result['total'] ) && $batch_result['total'] > 0 ) {
			$stats['total']        = $batch_result['total'];
			$stats['source_total'] = $batch_result['total'];
		}

		// Append errors (limited to MAX_STORED_ERRORS)
		if ( ! empty( $batch_result['errors'] ) ) {
			$stats['errors'] = array_merge( $stats['errors'], $batch_result['errors'] );
			$stats['errors'] = array_slice( $stats['errors'], - self::MAX_STORED_ERRORS );
		}

		self::save_stats( $page_id, $operation_id, $stats );

		return $stats;
	}

	/**
	 * Complete an operation run.
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 * @param string $status       Final status ('complete', 'cancelled', 'error').
	 * @param int    $duration     Total duration in seconds.
	 *
	 * @return array Final stats.
	 */
	public static function complete_run( string $page_id, string $operation_id, string $status = 'complete', int $duration = 0 ): array {
		$stats = self::get_stats( $page_id, $operation_id );

		$stats['last_status'] = $status;
		$stats['duration']    = $duration;

		// Calculate total processed
		$total_processed = $stats['created'] + $stats['updated'] + $stats['skipped'] + $stats['failed'];
		if ( $stats['total'] === 0 ) {
			$stats['total'] = $total_processed;
		}

		// Add to history
		$history_entry = [
			'date'     => $stats['last_run'],
			'status'   => $status,
			'duration' => $duration,
			'total'    => $stats['total'],
			'created'  => $stats['created'],
			'updated'  => $stats['updated'],
			'skipped'  => $stats['skipped'],
			'failed'   => $stats['failed'],
		];

		if ( $stats['source_file'] ) {
			$history_entry['file'] = $stats['source_file'];
		}

		$stats['history']   = array_slice( $stats['history'], 0, self::MAX_HISTORY_ENTRIES - 1 );
		$stats['history'][] = $history_entry;

		self::save_stats( $page_id, $operation_id, $stats );

		return $stats;
	}

	/**
	 * Record a single item result.
	 *
	 * Helper method for recording individual item processing results.
	 *
	 * @param string          $page_id      The importer page ID.
	 * @param string          $operation_id The operation ID.
	 * @param string|\WP_Error $result       'created', 'updated', 'skipped', or WP_Error.
	 * @param string|null     $item_id      Optional item identifier for error logging.
	 *
	 * @return void
	 */
	public static function record_item( string $page_id, string $operation_id, $result, ?string $item_id = null ): void {
		$stats = self::get_stats( $page_id, $operation_id );

		if ( is_wp_error( $result ) ) {
			$stats['failed']++;
			$stats['errors'][] = [
				'item'    => $item_id ?? 'Unknown',
				'message' => $result->get_error_message(),
				'code'    => $result->get_error_code(),
			];
			$stats['errors'] = array_slice( $stats['errors'], - self::MAX_STORED_ERRORS );
		} elseif ( $result === 'created' ) {
			$stats['created']++;
		} elseif ( $result === 'updated' ) {
			$stats['updated']++;
		} elseif ( $result === 'skipped' ) {
			$stats['skipped']++;
		}

		self::save_stats( $page_id, $operation_id, $stats );
	}

	/**
	 * Save stats to the database.
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 * @param array  $stats        Stats array to save.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function save_stats( string $page_id, string $operation_id, array $stats ): bool {
		$option_key = self::get_option_key( $page_id, $operation_id );

		return update_option( $option_key, $stats, false );
	}

	/**
	 * Clear stats for an operation.
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 *
	 * @return bool True on success.
	 */
	public static function clear_stats( string $page_id, string $operation_id ): bool {
		$option_key = self::get_option_key( $page_id, $operation_id );

		return delete_option( $option_key );
	}

	/**
	 * Get stats for all operations on a page.
	 *
	 * @param string $page_id       The importer page ID.
	 * @param array  $operation_ids Array of operation IDs.
	 *
	 * @return array Associative array of operation_id => stats.
	 */
	public static function get_all_stats( string $page_id, array $operation_ids ): array {
		$all_stats = [];

		foreach ( $operation_ids as $operation_id ) {
			$all_stats[ $operation_id ] = self::get_stats( $page_id, $operation_id );
		}

		return $all_stats;
	}

	/**
	 * Format duration for display.
	 *
	 * @param int $seconds Duration in seconds.
	 *
	 * @return string Formatted duration string.
	 */
	public static function format_duration( int $seconds ): string {
		if ( $seconds < 60 ) {
			return sprintf(
				_n( '%d second', '%d seconds', $seconds, 'arraypress' ),
				$seconds
			);
		}

		$minutes = floor( $seconds / 60 );
		$secs    = $seconds % 60;

		if ( $minutes < 60 ) {
			if ( $secs > 0 ) {
				return sprintf(
					__( '%d min %d sec', 'arraypress' ),
					$minutes,
					$secs
				);
			}

			return sprintf(
				_n( '%d minute', '%d minutes', $minutes, 'arraypress' ),
				$minutes
			);
		}

		$hours = floor( $minutes / 60 );
		$mins  = $minutes % 60;

		return sprintf(
			__( '%d hr %d min', 'arraypress' ),
			$hours,
			$mins
		);
	}

	/**
	 * Format a timestamp for display.
	 *
	 * @param string|null $timestamp MySQL timestamp (GMT).
	 *
	 * @return string Formatted date/time or 'Never'.
	 */
	public static function format_timestamp( ?string $timestamp ): string {
		if ( ! $timestamp ) {
			return __( 'Never', 'arraypress' );
		}

		$local_time = get_date_from_gmt( $timestamp );

		return date_i18n(
			get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
			strtotime( $local_time )
		);
	}

	/**
	 * Get relative time string (e.g., "2 hours ago").
	 *
	 * @param string|null $timestamp MySQL timestamp (GMT).
	 *
	 * @return string Relative time string.
	 */
	public static function get_relative_time( ?string $timestamp ): string {
		if ( ! $timestamp ) {
			return __( 'Never', 'arraypress' );
		}

		$time_diff = time() - strtotime( $timestamp );

		if ( $time_diff < 60 ) {
			return __( 'Just now', 'arraypress' );
		}

		return sprintf(
			__( '%s ago', 'arraypress' ),
			human_time_diff( strtotime( $timestamp ), time() )
		);
	}

}
