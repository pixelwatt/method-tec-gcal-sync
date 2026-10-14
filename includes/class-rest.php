<?php
namespace Method_GCal_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * External trigger, for hosts where WP-Cron is unreliable:
 *
 *   GET|POST /wp-json/method-gcal-sync/v1/run?key=SECRET[&calendar=ID][&dry_run=1]
 *
 * The secret is on the settings page.
 */
final class Rest {

	public static function boot(): void {
		add_action( 'rest_api_init', [ __CLASS__, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( 'method-gcal-sync/v1', '/run', [
			'methods'             => [ 'GET', 'POST' ],
			'callback'            => [ __CLASS__, 'run' ],
			'permission_callback' => [ __CLASS__, 'permission' ],
			'args'                => [
				'key'      => [ 'type' => 'string', 'required' => true ],
				'calendar' => [ 'type' => 'string', 'required' => false ],
				'dry_run'  => [ 'type' => 'boolean', 'required' => false, 'default' => false ],
			],
		] );
	}

	public static function permission( \WP_REST_Request $request ) {
		$key = (string) $request->get_param( 'key' );
		if ( '' !== $key && hash_equals( Settings::secret(), $key ) ) {
			return true;
		}
		return new \WP_Error( 'forbidden', __( 'Invalid key.', 'method-tec-gcal-sync' ), [ 'status' => 403 ] );
	}

	public static function run( \WP_REST_Request $request ): \WP_REST_Response {
		$calendar = trim( (string) $request->get_param( 'calendar' ) );
		$report   = Sync::run( $calendar ? [ $calendar ] : [], (bool) $request->get_param( 'dry_run' ) );

		return new \WP_REST_Response( $report->to_array(), $report->counts['errors'] > 0 && 0 === array_sum( $report->counts ) - $report->counts['errors'] ? 500 : 200 );
	}

	public static function url(): string {
		return add_query_arg( 'key', Settings::secret(), rest_url( 'method-gcal-sync/v1/run' ) );
	}
}
