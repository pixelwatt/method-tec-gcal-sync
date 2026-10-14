<?php
namespace Method_GCal_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Sync Google Calendars into The Events Calendar.
 *
 * ## OPTIONS
 *
 * [--calendar=<id>]
 * : Only this calendar id (repeatable as a comma-separated list).
 *
 * [--dry-run]
 * : Print the plan without writing anything.
 *
 * [--post=<id>]
 * : Re-sync one local event by post id instead of running the calendars.
 *
 * [--reset]
 * : With --post: ignore local edits and reapply every field from Google.
 *
 * [--quiet-lines]
 * : Only print the summary line.
 *
 * ## EXAMPLES
 *
 *     wp method gcal-sync --dry-run
 *     wp method gcal-sync --calendar=xxx@group.calendar.google.com
 *     wp method gcal-sync --post=123 --reset
 */
final class CLI {

	public function __invoke( array $args, array $assoc ): void {
		$dry = ! empty( $assoc['dry-run'] );

		if ( ! empty( $assoc['post'] ) ) {
			$report = Sync::sync_post( (int) $assoc['post'], ! empty( $assoc['reset'] ), $dry );
		} else {
			$only   = ! empty( $assoc['calendar'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc['calendar'] ) ) ) : [];
			$report = Sync::run( $only, $dry );
		}

		if ( empty( $assoc['quiet-lines'] ) ) {
			foreach ( $report->lines as $line ) {
				$prefix = str_pad( strtoupper( $line['type'] ), 15 );
				if ( 'errors' === $line['type'] ) {
					\WP_CLI::warning( $prefix . $line['message'] );
				} else {
					\WP_CLI::log( $prefix . $line['message'] );
				}
			}
		}

		if ( $report->counts['errors'] > 0 ) {
			\WP_CLI::warning( $report->summary() );
		} else {
			\WP_CLI::success( $report->summary() );
		}
	}
}
