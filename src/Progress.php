<?php
/**
 * Progress
 *
 * @package     ArrayPress\RegisterImporters
 * @copyright   Copyright (c) 2026, ArrayPress Limited
 * @license     GPL2+
 * @since       3.0.0
 */

declare( strict_types=1 );

namespace ArrayPress\RegisterImporters;

use ArrayPress\RegisterImporters\Utils\Runtime;

/**
 * What has happened so far in one import.
 *
 * An import is a hundred requests, and each of them needs to know what the
 * ninety-nine before it did. That state is small, short-lived and belongs to
 * one person's run of one file, so it lives in a transient keyed by the run
 * rather than in an option — an option would be a row in the database for
 * every import anybody ever abandoned.
 *
 * Errors are kept, but not all of them. A file that is wrong is usually
 * wrong in the same way in every row, and thirty thousand copies of one
 * message is not a report — it is a way of making the browser stop
 * responding. The first hundred are kept and the rest are counted.
 */
final class Progress {

	/**
	 * How many messages are worth keeping.
	 */
	private const KEEP = 100;

	/**
	 * How long a run is remembered.
	 */
	private const LIFETIME = HOUR_IN_SECONDS;

	/**
	 * The run.
	 *
	 * @var string
	 */
	private string $id;

	/**
	 * Construct.
	 *
	 * @param string $id The run.
	 */
	public function __construct( string $id ) {
		$this->id = sanitize_key( $id );
	}

	/**
	 * Start a run.
	 *
	 * @param int $total How many rows there are.
	 *
	 * @return array<string, mixed>
	 */
	public function begin( int $total ): array {
		return $this->put(
			[
				'total'     => $total,
				'done'      => 0,
				'created'   => 0,
				'updated'   => 0,
				'skipped'   => 0,
				'failed'    => 0,
				'errors'    => [],
				'truncated' => 0,
				'began'     => time(),
				'finished'  => 0,
			]
		);
	}

	/**
	 * What has happened so far.
	 *
	 * @return array<string, mixed>|null
	 */
	public function read(): ?array {
		$state = get_transient( $this->key() );

		return is_array( $state ) ? $state : null;
	}

	/**
	 * Record what a batch did.
	 *
	 * @param array<int, string|null> $outcomes One per row: created, updated,
	 *                                          skipped, or null for a failure.
	 * @param array<int, string>      $errors   What went wrong, by row number.
	 *
	 * @return array<string, mixed>
	 */
	public function add( array $outcomes, array $errors = [] ): array {
		$state = $this->read() ?? $this->begin( 0 );

		foreach ( $outcomes as $outcome ) {
			++$state['done'];

			$counter = in_array( $outcome, [ 'created', 'updated', 'skipped' ], true ) ? $outcome : 'failed';

			++$state[ $counter ];
		}

		foreach ( $errors as $row => $message ) {
			if ( count( $state['errors'] ) < self::KEEP ) {
				$state['errors'][ $row ] = $message;

				continue;
			}

			++$state['truncated'];
		}

		return $this->put( $state );
	}

	/**
	 * Mark a run finished.
	 *
	 * @return array<string, mixed>
	 */
	public function finish(): array {
		$state = $this->read() ?? $this->begin( 0 );

		$state['finished'] = time();

		return $this->put( $state );
	}

	/**
	 * Forget a run.
	 *
	 * @return void
	 */
	public function forget(): void {
		delete_transient( $this->key() );
	}

	/**
	 * Store the state.
	 *
	 * @param array<string, mixed> $state The state.
	 *
	 * @return array<string, mixed>
	 */
	private function put( array $state ): array {
		set_transient( $this->key(), $state, self::LIFETIME );

		return $state;
	}

	/**
	 * Where it is kept.
	 *
	 * @return string
	 */
	private function key(): string {
		return Runtime::key( 'run_' . $this->id );
	}
}
