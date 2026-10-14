<?php
namespace Method_GCal_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Accumulates what a run did (or, for a dry run, would do).
 */
final class Report {

	const MAX_LINES = 300;

	/** @var bool */
	public $dry_run;

	/** @var int Unix timestamp. */
	public $started;

	/** @var int|null */
	public $finished = null;

	/** @var array<string, int> */
	public $counts = [
		'fetched'        => 0,
		'created'        => 0,
		'updated'        => 0,
		'unchanged'      => 0,
		'preserved'      => 0, // Events where at least one locally-edited field was left alone.
		'trashed'        => 0,
		'skipped'        => 0,
		'venues_created' => 0,
		'venues_matched' => 0,
		'errors'         => 0,
	];

	/** @var array<int, array{type: string, message: string}> */
	public $lines = [];

	public function __construct( bool $dry_run = false ) {
		$this->dry_run = $dry_run;
		$this->started = time();
	}

	public function add( string $type, string $message ): void {
		if ( isset( $this->counts[ $type ] ) ) {
			$this->counts[ $type ]++;
		}
		if ( count( $this->lines ) < self::MAX_LINES ) {
			$this->lines[] = [ 'type' => $type, 'message' => $message ];
		}
	}

	public function error( string $message ): void {
		$this->add( 'errors', $message );
	}

	public function finish(): void {
		$this->finished = time();
	}

	public function summary(): string {
		$c     = $this->counts;
		$parts = [];
		foreach ( [ 'fetched', 'created', 'updated', 'unchanged', 'preserved', 'trashed', 'skipped', 'venues_created', 'venues_matched', 'errors' ] as $k ) {
			if ( $c[ $k ] > 0 || in_array( $k, [ 'created', 'updated', 'trashed', 'errors' ], true ) ) {
				$parts[] = str_replace( '_', ' ', $k ) . ' ' . $c[ $k ];
			}
		}
		return ( $this->dry_run ? '[dry run] ' : '' ) . implode( ', ', $parts );
	}

	public function to_array(): array {
		return [
			'dry_run'  => $this->dry_run,
			'started'  => $this->started,
			'finished' => $this->finished,
			'counts'   => $this->counts,
			'lines'    => $this->lines,
			'summary'  => $this->summary(),
		];
	}
}
