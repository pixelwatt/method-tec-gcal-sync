<?php
namespace Method_GCal_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Where settings come from, in order of precedence (last wins):
 *
 *   defaults → stored option (standalone 'method_tec_gcal_sync' OR Method's 'method_options'
 *   with gcal_ prefixed keys) → 'method_gcal_sync_settings' filter.
 *
 * The UI mirrors cmb2-mapbox: a standalone CMB2 options page, or the same fields appended to
 * Method's options page when 'method_gcal_sync_use_method' returns true.
 */
final class Settings {

	const OPTION        = 'method_tec_gcal_sync';
	const SECRET_OPTION = 'method_tec_gcal_sync_secret';
	const LAST_RUN      = 'method_tec_gcal_sync_last_run';
	const PREVIEW       = 'method_tec_gcal_sync_preview'; // transient
	const PAGE_SLUG     = 'method_tec_gcal_sync';
	const BOX_ID        = 'method_tec_gcal_sync_box';
	const PREFIX        = 'gcal_';

	public static function boot(): void {
		add_action( 'cmb2_admin_init', [ __CLASS__, 'register_standalone_page' ] );
		add_action( 'method_options_before_fields', [ __CLASS__, 'register_method_fields' ], 99 );
	}

	public static function use_method(): bool {
		return (bool) apply_filters( 'method_gcal_sync_use_method', false );
	}

	public static function defaults(): array {
		return [
			'api_key'       => '',
			'calendars'     => [],
			'window_days'   => 90,
			'lookback_days' => 1,
			'interval'      => 60,        // minutes; 0 disables the scheduled run
			'post_status'   => 'publish',
			'delete_mode'   => 'trash',   // trash | delete
			'author'        => 0,
		];
	}

	/**
	 * Fully resolved, normalised settings.
	 */
	public static function get(): array {
		$defaults = self::defaults();
		$stored   = self::stored();
		$settings = $defaults;

		foreach ( $defaults as $key => $default ) {
			if ( array_key_exists( $key, $stored ) && '' !== $stored[ $key ] && null !== $stored[ $key ] ) {
				$settings[ $key ] = $stored[ $key ];
			}
		}

		$settings = (array) apply_filters( 'method_gcal_sync_settings', $settings );
		$settings = array_merge( $defaults, $settings );

		$settings['api_key']       = trim( (string) $settings['api_key'] );
		$settings['window_days']   = max( 1, (int) $settings['window_days'] );
		$settings['lookback_days'] = max( 0, (int) $settings['lookback_days'] );
		$settings['interval']      = max( 0, (int) $settings['interval'] );
		$settings['author']        = max( 0, (int) $settings['author'] );
		$settings['delete_mode']   = 'delete' === $settings['delete_mode'] ? 'delete' : 'trash';
		if ( ! in_array( $settings['post_status'], [ 'publish', 'draft', 'pending', 'private' ], true ) ) {
			$settings['post_status'] = 'publish';
		}
		$settings['calendars'] = self::normalise_calendars( $settings['calendars'] );

		return $settings;
	}

	/**
	 * The stored option with the gcal_ prefix stripped, from whichever option array is in use.
	 */
	private static function stored(): array {
		$raw = get_option( self::use_method() ? 'method_options' : self::OPTION );
		if ( ! is_array( $raw ) ) {
			return [];
		}
		$out = [];
		foreach ( $raw as $key => $value ) {
			if ( 0 === strpos( (string) $key, self::PREFIX ) ) {
				$out[ substr( $key, strlen( self::PREFIX ) ) ] = $value;
			}
		}
		return $out;
	}

	/**
	 * @return array<int, array{calendar_id: string, label: string, category: string}>
	 */
	private static function normalise_calendars( $calendars ): array {
		$out = [];
		if ( ! is_array( $calendars ) ) {
			return $out;
		}
		foreach ( $calendars as $row ) {
			if ( is_string( $row ) ) {
				$row = [ 'calendar_id' => $row ];
			}
			if ( ! is_array( $row ) ) {
				continue;
			}
			$id = trim( (string) ( $row['calendar_id'] ?? '' ) );
			if ( '' === $id ) {
				continue;
			}
			$out[] = [
				'calendar_id' => $id,
				'label'       => trim( (string) ( $row['label'] ?? '' ) ) ?: $id,
				'category'    => sanitize_title( (string) ( $row['category'] ?? '' ) ),
			];
		}
		return $out;
	}

	public static function calendar( string $calendar_id ): ?array {
		foreach ( self::get()['calendars'] as $cal ) {
			if ( $cal['calendar_id'] === $calendar_id ) {
				return $cal;
			}
		}
		return null;
	}

	/**
	 * Secret for the REST trigger. Generated once, stored in its own option so Method mode
	 * never has to write into method_options.
	 */
	public static function secret(): string {
		$secret = (string) get_option( self::SECRET_OPTION, '' );
		if ( '' === $secret ) {
			$secret = wp_generate_password( 32, false, false );
			update_option( self::SECRET_OPTION, $secret, false );
		}
		return (string) apply_filters( 'method_gcal_sync_rest_secret', $secret );
	}

	public static function settings_url(): string {
		if ( self::use_method() ) {
			return (string) apply_filters( 'method_gcal_sync_settings_url', admin_url( 'admin.php?page=method_options' ) );
		}
		return admin_url( 'edit.php?post_type=tribe_events&page=' . self::PAGE_SLUG );
	}

	/* ---------------------------------------------------------------------
	 * CMB2 UI
	 * ------------------------------------------------------------------ */

	public static function register_standalone_page(): void {
		if ( self::use_method() || ! function_exists( 'new_cmb2_box' ) ) {
			return;
		}

		$box = new_cmb2_box( [
			'id'           => self::BOX_ID,
			'title'        => esc_html__( 'Google Calendar Sync', 'method-tec-gcal-sync' ),
			'object_types' => [ 'options-page' ],
			'option_key'   => self::OPTION,
			'parent_slug'  => 'edit.php?post_type=tribe_events',
			'menu_title'   => esc_html__( 'Google Calendar Sync', 'method-tec-gcal-sync' ),
			'capability'   => 'manage_options',
			'save_button'  => esc_html__( 'Save Settings', 'method-tec-gcal-sync' ),
		] );

		self::add_fields( $box, false );
	}

	/**
	 * @param \CMB2 $box Method's options box.
	 */
	public static function register_method_fields( $box ): void {
		if ( ! self::use_method() || ! is_object( $box ) ) {
			return;
		}
		$box->add_field( [
			'name' => __( 'Google Calendar Sync', 'method-tec-gcal-sync' ),
			'id'   => self::PREFIX . 'options_title',
			'type' => 'title',
		] );
		self::add_fields( $box, true );
	}

	private static function add_fields( $box, bool $in_method ): void {
		$p = self::PREFIX;

		$box->add_field( [
			'name'       => __( 'Google API Key', 'method-tec-gcal-sync' ),
			'desc'       => __( 'From a Google Cloud project with the Google Calendar API enabled. Calendars must be public (Settings → Access permissions → “Make available to public”).', 'method-tec-gcal-sync' ),
			'id'         => $p . 'api_key',
			'type'       => 'text',
			'attributes' => [ 'style' => 'width: 100%; max-width: 480px;', 'autocomplete' => 'off' ],
		] );

		$group = $box->add_field( [
			'name'        => __( 'Calendars', 'method-tec-gcal-sync' ),
			'id'          => $p . 'calendars',
			'type'        => 'group',
			'description' => __( 'Calendar ID is shown under the calendar’s Settings → “Integrate calendar” (looks like xxx@group.calendar.google.com).', 'method-tec-gcal-sync' ),
			'options'     => [
				'group_title'   => __( 'Calendar {#}', 'method-tec-gcal-sync' ),
				'add_button'    => __( 'Add calendar', 'method-tec-gcal-sync' ),
				'remove_button' => __( 'Remove', 'method-tec-gcal-sync' ),
				'sortable'      => true,
				'closed'        => false,
			],
		] );
		$box->add_group_field( $group, [
			'name'       => __( 'Calendar ID', 'method-tec-gcal-sync' ),
			'id'         => 'calendar_id',
			'type'       => 'text',
			'attributes' => [ 'style' => 'width: 100%;' ],
		] );
		$box->add_group_field( $group, [
			'name' => __( 'Label', 'method-tec-gcal-sync' ),
			'desc' => __( 'Shown in the admin only.', 'method-tec-gcal-sync' ),
			'id'   => 'label',
			'type' => 'text',
		] );
		$box->add_group_field( $group, [
			'name' => __( 'Event category slug', 'method-tec-gcal-sync' ),
			'desc' => __( 'Optional. Every event from this calendar is assigned this Event Category (created if missing).', 'method-tec-gcal-sync' ),
			'id'   => 'category',
			'type' => 'text',
		] );

		$box->add_field( [
			'name'       => __( 'Sync window (days ahead)', 'method-tec-gcal-sync' ),
			'desc'       => __( 'Events starting between now and this many days ahead are kept in sync. Events that drop out of the window are left as they are.', 'method-tec-gcal-sync' ),
			'id'         => $p . 'window_days',
			'type'       => 'text_small',
			'default'    => 90,
			'attributes' => [ 'type' => 'number', 'min' => 1 ],
		] );
		$box->add_field( [
			'name'       => __( 'Look back (days)', 'method-tec-gcal-sync' ),
			'desc'       => __( 'Also include events that started up to this many days ago, so in-progress and just-finished events still receive changes.', 'method-tec-gcal-sync' ),
			'id'         => $p . 'lookback_days',
			'type'       => 'text_small',
			'default'    => 1,
			'attributes' => [ 'type' => 'number', 'min' => 0 ],
		] );
		$box->add_field( [
			'name'       => __( 'Sync every (minutes)', 'method-tec-gcal-sync' ),
			'desc'       => __( '0 disables the scheduled run (WP-CLI and the REST trigger still work).', 'method-tec-gcal-sync' ),
			'id'         => $p . 'interval',
			'type'       => 'text_small',
			'default'    => 60,
			'attributes' => [ 'type' => 'number', 'min' => 0 ],
		] );
		$box->add_field( [
			'name'    => __( 'New events are created as', 'method-tec-gcal-sync' ),
			'id'      => $p . 'post_status',
			'type'    => 'select',
			'default' => 'publish',
			'options' => [
				'publish' => __( 'Published', 'method-tec-gcal-sync' ),
				'draft'   => __( 'Draft', 'method-tec-gcal-sync' ),
				'pending' => __( 'Pending review', 'method-tec-gcal-sync' ),
				'private' => __( 'Private', 'method-tec-gcal-sync' ),
			],
		] );
		$box->add_field( [
			'name'    => __( 'When an event is deleted from Google', 'method-tec-gcal-sync' ),
			'id'      => $p . 'delete_mode',
			'type'    => 'radio_inline',
			'default' => 'trash',
			'options' => [
				'trash'  => __( 'Move the local event to Trash', 'method-tec-gcal-sync' ),
				'delete' => __( 'Delete it permanently', 'method-tec-gcal-sync' ),
			],
		] );
		$box->add_field( [
			'name'    => __( 'Status & tools', 'method-tec-gcal-sync' ),
			'id'      => $p . 'status',
			'type'    => 'title',
			'after_field' => [ Admin::class, 'render_status_panel' ],
		] );
	}
}
