<?php
/**
 * Plugin Name: Method – Google Calendar Sync for The Events Calendar
 * Description: Keeps a rolling window of events from one or more public Google Calendars synced into The Events Calendar. Field-level three-way merge preserves local edits; source deletions trash the local event; venues are matched or created through TEC's venue API so Method – Mapbox for The Events Calendar geocodes them.
 * Version: 0.1.0
 * Author: Rob Clark
 * Author URI: https://robclark.io
 * License: GPLv2 or later
 * Text Domain: method-tec-gcal-sync
 * Requires PHP: 7.4
 * GitHub Plugin URI: https://github.com/pixelwatt/method-tec-gcal-sync
 * Primary Branch: main
 *
 * Configuration (any combination):
 *
 *   1. Standalone: Events → Google Calendar Sync (CMB2 options page, option key
 *      'method_tec_gcal_sync').
 *
 *   2. Method options page: opt in and the same fields are appended to Method's
 *      unified options page (option key 'method_options', ids prefixed gcal_):
 *
 *        add_filter( 'method_gcal_sync_use_method', '__return_true' );
 *
 *   3. Code only (no UI needed, overrides both of the above):
 *
 *        add_filter( 'method_gcal_sync_settings', function ( $s ) {
 *            $s['api_key']   = 'AIza...';
 *            $s['calendars'] = [
 *                [ 'calendar_id' => 'xxx@group.calendar.google.com', 'label' => 'City Council', 'category' => 'council' ],
 *            ];
 *            return $s;
 *        } );
 *
 * Runs on a schedule through Action Scheduler (bundled with TEC) or WP-Cron,
 * on demand from the settings page, from WP-CLI (`wp method gcal-sync`), or
 * from an external cron via REST (`/wp-json/method-gcal-sync/v1/run?key=…`).
 *
 * Requires: The Events Calendar. CMB2 for the settings UI (not needed when
 * settings are supplied by filter).
 */

defined( 'ABSPATH' ) || exit;

define( 'METHOD_GCAL_SYNC_VERSION', '0.1.0' );
define( 'METHOD_GCAL_SYNC_FILE', __FILE__ );
define( 'METHOD_GCAL_SYNC_DIR', plugin_dir_path( __FILE__ ) );

require_once METHOD_GCAL_SYNC_DIR . 'includes/class-report.php';
require_once METHOD_GCAL_SYNC_DIR . 'includes/class-settings.php';
require_once METHOD_GCAL_SYNC_DIR . 'includes/class-google-client.php';
require_once METHOD_GCAL_SYNC_DIR . 'includes/class-venues.php';
require_once METHOD_GCAL_SYNC_DIR . 'includes/class-sync.php';
require_once METHOD_GCAL_SYNC_DIR . 'includes/class-scheduler.php';
require_once METHOD_GCAL_SYNC_DIR . 'includes/class-rest.php';
require_once METHOD_GCAL_SYNC_DIR . 'includes/class-admin.php';

final class Method_GCal_Sync {

	public static function boot(): void {
		// Settings UI registers on cmb2_admin_init / method_options_before_fields regardless of TEC,
		// so a half-configured site still shows the page (with a notice).
		Method_GCal_Sync\Settings::boot();

		// Everything else needs TEC. Late enough that TEC, ECP and child themes have registered.
		add_action( 'init', [ __CLASS__, 'maybe_enable' ], 100 );

		register_deactivation_hook( METHOD_GCAL_SYNC_FILE, [ 'Method_GCal_Sync\Scheduler', 'unschedule' ] );
	}

	public static function maybe_enable(): void {
		if ( ! class_exists( 'Tribe__Events__Main' ) ) {
			add_action( 'admin_notices', static function () {
				if ( current_user_can( 'activate_plugins' ) ) {
					echo '<div class="notice notice-warning"><p>' . esc_html__( 'Method – Google Calendar Sync needs The Events Calendar to be active.', 'method-tec-gcal-sync' ) . '</p></div>';
				}
			} );
			return;
		}

		Method_GCal_Sync\Scheduler::boot();
		Method_GCal_Sync\Rest::boot();
		Method_GCal_Sync\Admin::boot();

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			require_once METHOD_GCAL_SYNC_DIR . 'includes/class-cli.php';
			WP_CLI::add_command( 'method gcal-sync', 'Method_GCal_Sync\CLI' );
		}
	}
}

Method_GCal_Sync::boot();
