<?php
/**
 * Stats Manager
 *
 * Handles lightweight tracking of import operation results.
 * Stores only the last run's stats per operation — no history,
 * no run counts, no duration tracking.
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       2.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

/**
 * Class StatsManager
 *
 * Manages statistics for import operations using transients.
 */
class StatsManager {

	/**
	 * Transient prefix for stats storage.
	 *
	 * @since 2.0.0
	 * @var string
	 */
	const TRANSIENT_PREFIX = 'importers_stats_';

	/**
	 * Maximum number of errors to store per operation.
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const MAX_STORED_ERRORS = 20;

	/**
	 * How long stats persist (7 days).
	 *
	 * @since 2.0.0
	 * @var int
	 */
	const STATS_EXPIRATION = WEEK_IN_SECONDS;

	/**
	 * Get the transient key for an operation's stats.
	 *
	 * @since 2.0.0
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 *
	 * @return string
	 */
	public static function get_transient_key( string $page_id, string $operation_id ): string {
		return self::TRANSIENT_PREFIX . sanitize_key( $page_id ) . '_' . sanitize_key( $operation_id );
	}

	/**
	 * Get stats for an operation.
	 *
	 * @since 2.0.0
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 *
	 * @return array Stats array with defaults if not found.
	 */
	public static function get_stats( string $page_id, string $operation_id ): array {
		$key   = self::get_transient_key( $page_id, $operation_id );
		$stats = get_transient( $key );

		if ( ! $stats || ! is_array( $stats ) ) {
			return self::get_default_stats();
		}

		return wp_parse_args( $stats, self::get_default_stats() );
	}

	/**
	 * Get default stats structure.
	 *
	 * @since 2.0.0
	 *
	 * @return array
	 */
	public static function get_default_stats(): array {
		return [
			'last_run'    => null,
			'last_status' => null,
			'total'       => 0,
			'created'     => 0,
			'updated'     => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'errors'      => [],
			'source_file' => null,
		];
	}

	/**
	 * Initialize stats for a new import run.
	 *
	 * @since 2.0.0
	 *
	 * @param string      $page_id      The importer page ID.
	 * @param string      $operation_id The operation ID.
	 * @param string|null $source_file  Original filename.
	 * @param int|null    $total        Total items to process.
	 *
	 * @return array The initialized stats.
	 */
	public static function init_run( string $page_id, string $operation_id, ?string $source_file = null, ?int $total = null ): array {
		$stats = [
			'last_run'    => current_time( 'mysql', true ),
			'last_status' => null,
			'total'       => $total ?? 0,
			'created'     => 0,
			'updated'     => 0,
			'skipped'     => 0,
			'failed'      => 0,
			'errors'      => [],
			'source_file' => $source_file,
		];

		self::save_stats( $page_id, $operation_id, $stats );

		return $stats;
	}

	/**
	 * Update stats after processing a batch.
	 *
	 * @since 2.0.0
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 * @param array  $batch_result Results from the batch processing.
	 *
	 * @return array Updated stats.
	 */
	public static function update_batch( string $page_id, string $operation_id, array $batch_result ): array {
		$stats = self::get_stats( $page_id, $operation_id );

		$stats['created'] += $batch_result['created'] ?? 0;
		$stats['updated'] += $batch_result['updated'] ?? 0;
		$stats['skipped'] += $batch_result['skipped'] ?? 0;
		$stats['failed']  += $batch_result['failed'] ?? 0;

		// Append errors (capped)
		if ( ! empty( $batch_result['errors'] ) ) {
			$stats['errors'] = array_merge( $stats['errors'], $batch_result['errors'] );
			$stats['errors'] = array_slice( $stats['errors'], - self::MAX_STORED_ERRORS );
		}

		self::save_stats( $page_id, $operation_id, $stats );

		return $stats;
	}

	/**
	 * Complete an import run.
	 *
	 * @since 2.0.0
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 * @param string $status       Final status ('complete', 'cancelled', 'error').
	 *
	 * @return array Final stats.
	 */
	public static function complete_run( string $page_id, string $operation_id, string $status = 'complete' ): array {
		$stats = self::get_stats( $page_id, $operation_id );

		$stats['last_status'] = $status;

		$total_processed = $stats['created'] + $stats['updated'] + $stats['skipped'] + $stats['failed'];
		if ( $stats['total'] === 0 ) {
			$stats['total'] = $total_processed;
		}

		self::save_stats( $page_id, $operation_id, $stats );

		return $stats;
	}

	/**
	 * Save stats to the database.
	 *
	 * @since 2.0.0
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 * @param array  $stats        Stats array to save.
	 *
	 * @return bool True on success, false on failure.
	 */
	public static function save_stats( string $page_id, string $operation_id, array $stats ): bool {
		$key = self::get_transient_key( $page_id, $operation_id );

		return set_transient( $key, $stats, self::STATS_EXPIRATION );
	}

	/**
	 * Clear stats for an operation.
	 *
	 * @since 2.0.0
	 *
	 * @param string $page_id      The importer page ID.
	 * @param string $operation_id The operation ID.
	 *
	 * @return bool True on success.
	 */
	public static function clear_stats( string $page_id, string $operation_id ): bool {
		$key = self::get_transient_key( $page_id, $operation_id );

		return delete_transient( $key );
	}

	/**
	 * Get relative time string (e.g., "2 hours ago").
	 *
	 * @since 2.0.0
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
