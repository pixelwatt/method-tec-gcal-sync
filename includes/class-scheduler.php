<?php
namespace Method_GCal_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Recurring run through Action Scheduler (TEC ships it) with a WP-Cron fallback.
 */
final class Scheduler {

	const HOOK     = 'method_gcal_sync_run';
	const GROUP    = 'method-gcal-sync';
	const SCHEDULE = 'method_gcal_sync_interval';
	const STATE    = 'method_tec_gcal_sync_schedule'; // what is currently scheduled

	public static function boot(): void {
		add_action( self::HOOK, [ __CLASS__, 'run' ] );
		add_filter( 'cron_schedules', [ __CLASS__, 'cron_schedules' ] );
		// After settings have been saved on an admin request, and on every request cheaply.
		add_action( 'init', [ __CLASS__, 'ensure' ], 110 );
	}

	public static function run(): void {
		Sync::run();
	}

	private static function use_action_scheduler(): bool {
		return function_exists( 'as_schedule_recurring_action' ) && function_exists( 'as_next_scheduled_action' );
	}

	/**
	 * Make what is scheduled match the configured interval. Cheap: one option read unless
	 * something changed.
	 */
	public static function ensure(): void {
		$interval = (int) Settings::get()['interval'];
		$state    = get_option( self::STATE );
		$state    = is_array( $state ) ? $state : [];
		$backend  = self::use_action_scheduler() ? 'as' : 'cron';

		if ( ( $state['interval'] ?? null ) === $interval && ( $state['backend'] ?? null ) === $backend ) {
			// Also self-heal: Action Scheduler can drop a recurring action after a failure.
			if ( $interval > 0 && 'as' === $backend && false === as_next_scheduled_action( self::HOOK, [], self::GROUP ) ) {
				as_schedule_recurring_action( time() + 60, $interval * MINUTE_IN_SECONDS, self::HOOK, [], self::GROUP );
			}
			return;
		}

		self::unschedule();

		if ( $interval > 0 ) {
			if ( 'as' === $backend ) {
				as_schedule_recurring_action( time() + 60, $interval * MINUTE_IN_SECONDS, self::HOOK, [], self::GROUP );
			} else {
				wp_schedule_event( time() + 60, self::SCHEDULE, self::HOOK );
			}
		}

		update_option( self::STATE, [ 'interval' => $interval, 'backend' => $backend ], false );
	}

	public static function unschedule(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK, [], self::GROUP );
		}
		$ts = wp_next_scheduled( self::HOOK );
		while ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK );
			$ts = wp_next_scheduled( self::HOOK );
		}
		delete_option( self::STATE );
	}

	public static function cron_schedules( array $schedules ): array {
		$interval = max( 1, (int) Settings::get()['interval'] );
		$schedules[ self::SCHEDULE ] = [
			'interval' => $interval * MINUTE_IN_SECONDS,
			'display'  => sprintf( __( 'Every %d minutes (Google Calendar Sync)', 'method-tec-gcal-sync' ), $interval ),
		];
		return $schedules;
	}

	/**
	 * Human-readable "next run" for the status panel.
	 */
	public static function next_run(): string {
		$interval = (int) Settings::get()['interval'];
		if ( $interval <= 0 ) {
			return __( 'Scheduled run disabled', 'method-tec-gcal-sync' );
		}
		$ts = self::use_action_scheduler() ? as_next_scheduled_action( self::HOOK, [], self::GROUP ) : wp_next_scheduled( self::HOOK );
		if ( ! $ts || ! is_numeric( $ts ) ) {
			return __( 'Not scheduled yet (will be on the next page load)', 'method-tec-gcal-sync' );
		}
		return sprintf(
			__( '%s via %s', 'method-tec-gcal-sync' ),
			wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $ts ),
			self::use_action_scheduler() ? 'Action Scheduler' : 'WP-Cron'
		);
	}
}
