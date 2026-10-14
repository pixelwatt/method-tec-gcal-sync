<?php
namespace Method_GCal_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Settings-page status panel (Run now / Preview), event metabox (source link, preserved
 * fields, Reset to Google values), venue alias metabox, list-table column.
 */
final class Admin {

	const CAP = 'manage_options';

	public static function boot(): void {
		add_action( 'admin_post_method_gcal_sync_run', [ __CLASS__, 'handle_run' ] );
		add_action( 'admin_post_method_gcal_sync_preview', [ __CLASS__, 'handle_preview' ] );
		add_action( 'admin_post_method_gcal_sync_reset', [ __CLASS__, 'handle_reset' ] );
		add_action( 'admin_notices', [ __CLASS__, 'notices' ] );

		add_action( 'add_meta_boxes', [ __CLASS__, 'meta_boxes' ] );
		add_action( 'save_post_' . \Tribe__Events__Main::VENUE_POST_TYPE, [ __CLASS__, 'save_venue_aliases' ], 10, 2 );

		add_filter( 'manage_' . \Tribe__Events__Main::POSTTYPE . '_posts_columns', [ __CLASS__, 'column' ] );
		add_action( 'manage_' . \Tribe__Events__Main::POSTTYPE . '_posts_custom_column', [ __CLASS__, 'column_content' ], 10, 2 );
	}

	/* ---------------------------------------------------------------------
	 * Settings page: status panel
	 * ------------------------------------------------------------------ */

	/**
	 * CMB2 after_field callback on the "Status & tools" title field.
	 */
	public static function render_status_panel( $field_args = null, $field = null ): void {
		if ( ! current_user_can( self::CAP ) ) {
			return;
		}
		$settings = Settings::get();
		$last     = get_option( Settings::LAST_RUN );
		$preview  = get_transient( Settings::PREVIEW );
		$nonce    = wp_create_nonce( 'method_gcal_sync' );
		$run_url  = add_query_arg( [ 'action' => 'method_gcal_sync_run', '_wpnonce' => $nonce ], admin_url( 'admin-post.php' ) );
		$prev_url = add_query_arg( [ 'action' => 'method_gcal_sync_preview', '_wpnonce' => $nonce ], admin_url( 'admin-post.php' ) );
		?>
		<div class="method-gcal-sync-status" style="margin: 8px 0 16px; padding: 12px 16px; background: #fff; border: 1px solid #c3c4c7; border-radius: 4px; max-width: 900px;">
			<p>
				<strong><?php esc_html_e( 'Configured calendars:', 'method-tec-gcal-sync' ); ?></strong>
				<?php echo esc_html( (string) count( $settings['calendars'] ) ); ?>
				&nbsp;·&nbsp;
				<strong><?php esc_html_e( 'Next scheduled run:', 'method-tec-gcal-sync' ); ?></strong>
				<?php echo esc_html( class_exists( 'Tribe__Events__Main' ) ? Scheduler::next_run() : __( 'The Events Calendar is not active', 'method-tec-gcal-sync' ) ); ?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( $run_url ); ?>"><?php esc_html_e( 'Sync now', 'method-tec-gcal-sync' ); ?></a>
				<a class="button" href="<?php echo esc_url( $prev_url ); ?>"><?php esc_html_e( 'Preview (dry run)', 'method-tec-gcal-sync' ); ?></a>
				<span class="description" style="margin-left: 8px;"><?php esc_html_e( 'Save settings first — both buttons use what is saved.', 'method-tec-gcal-sync' ); ?></span>
			</p>

			<?php if ( is_array( $preview ) ) : ?>
				<?php self::render_report( $preview, __( 'Preview', 'method-tec-gcal-sync' ), true ); ?>
			<?php endif; ?>

			<?php if ( is_array( $last ) ) : ?>
				<?php self::render_report( $last, __( 'Last run', 'method-tec-gcal-sync' ), false ); ?>
			<?php else : ?>
				<p class="description"><?php esc_html_e( 'No run yet.', 'method-tec-gcal-sync' ); ?></p>
			<?php endif; ?>

			<p class="description" style="margin-top: 12px;">
				<strong><?php esc_html_e( 'External cron trigger:', 'method-tec-gcal-sync' ); ?></strong>
				<code style="user-select: all;"><?php echo esc_html( Rest::url() ); ?></code><br>
				<?php esc_html_e( 'WP-CLI:', 'method-tec-gcal-sync' ); ?> <code>wp method gcal-sync [--dry-run]</code>
			</p>
		</div>
		<?php
	}

	private static function render_report( array $report, string $heading, bool $open ): void {
		$when = ! empty( $report['finished'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $report['finished'] ) : '';
		?>
		<details <?php echo $open ? 'open' : ''; ?> style="margin: 8px 0;">
			<summary style="cursor: pointer;">
				<strong><?php echo esc_html( $heading ); ?></strong>
				<?php if ( $when ) : ?><span class="description">(<?php echo esc_html( $when ); ?>)</span><?php endif; ?>
				— <?php echo esc_html( (string) ( $report['summary'] ?? '' ) ); ?>
			</summary>
			<?php if ( ! empty( $report['lines'] ) ) : ?>
				<ul style="margin: 8px 0 0 0; max-height: 320px; overflow: auto; font-family: monospace; font-size: 12px; line-height: 1.5;">
					<?php foreach ( (array) $report['lines'] as $line ) : ?>
						<li style="margin: 0; <?php echo 'errors' === $line['type'] ? 'color:#b32d2e;' : ''; ?>">
							<span style="display:inline-block; width: 9em; opacity: .7;"><?php echo esc_html( strtoupper( str_replace( '_', ' ', $line['type'] ) ) ); ?></span>
							<?php echo esc_html( $line['message'] ); ?>
						</li>
					<?php endforeach; ?>
				</ul>
			<?php endif; ?>
		</details>
		<?php
	}

	public static function handle_run(): void {
		self::guard();
		$report = Sync::run();
		delete_transient( Settings::PREVIEW );
		self::back( 'ran', $report->summary() );
	}

	public static function handle_preview(): void {
		self::guard();
		$report = Sync::run( [], true );
		set_transient( Settings::PREVIEW, $report->to_array(), HOUR_IN_SECONDS );
		self::back( 'previewed', $report->summary() );
	}

	public static function handle_reset(): void {
		self::guard();
		$post_id = isset( $_GET['post'] ) ? (int) $_GET['post'] : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Not allowed.', 'method-tec-gcal-sync' ) );
		}
		$report = Sync::sync_post( $post_id, true );
		wp_safe_redirect( add_query_arg( [ 'gcal_sync' => 'reset', 'gcal_msg' => rawurlencode( $report->summary() ) ], get_edit_post_link( $post_id, 'raw' ) ) );
		exit;
	}

	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) && ! current_user_can( 'edit_tribe_events' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'method-tec-gcal-sync' ) );
		}
		check_admin_referer( 'method_gcal_sync' );
	}

	private static function back( string $what, string $message ): void {
		$referer = wp_get_referer();
		$target  = $referer ? remove_query_arg( [ 'gcal_sync', 'gcal_msg' ], $referer ) : Settings::settings_url();
		wp_safe_redirect( add_query_arg( [ 'gcal_sync' => $what, 'gcal_msg' => rawurlencode( $message ) ], $target ) );
		exit;
	}

	public static function notices(): void {
		if ( empty( $_GET['gcal_sync'] ) ) {
			return;
		}
		$what = sanitize_key( (string) $_GET['gcal_sync'] );
		$msg  = isset( $_GET['gcal_msg'] ) ? sanitize_text_field( rawurldecode( (string) $_GET['gcal_msg'] ) ) : '';
		$labels = [
			'ran'       => __( 'Sync finished.', 'method-tec-gcal-sync' ),
			'previewed' => __( 'Preview finished — nothing was written.', 'method-tec-gcal-sync' ),
			'reset'     => __( 'Event reset to its Google Calendar values.', 'method-tec-gcal-sync' ),
		];
		if ( ! isset( $labels[ $what ] ) ) {
			return;
		}
		$class = false !== strpos( $msg, 'errors 0' ) || '' === $msg ? 'notice-success' : 'notice-warning';
		printf(
			'<div class="notice %s is-dismissible"><p><strong>%s</strong> %s</p></div>',
			esc_attr( $class ),
			esc_html( $labels[ $what ] ),
			esc_html( $msg )
		);
	}

	/* ---------------------------------------------------------------------
	 * Event & venue metaboxes
	 * ------------------------------------------------------------------ */

	public static function meta_boxes(): void {
		add_meta_box(
			'method-gcal-sync-event',
			__( 'Google Calendar', 'method-tec-gcal-sync' ),
			[ __CLASS__, 'event_box' ],
			\Tribe__Events__Main::POSTTYPE,
			'side',
			'default',
			[ '__back_compat_meta_box' => false ]
		);
		add_meta_box(
			'method-gcal-sync-venue',
			__( 'Google Calendar location aliases', 'method-tec-gcal-sync' ),
			[ __CLASS__, 'venue_box' ],
			\Tribe__Events__Main::VENUE_POST_TYPE,
			'normal',
			'default'
		);
	}

	public static function event_box( \WP_Post $post ): void {
		$event_id = (string) get_post_meta( $post->ID, Sync::META_ID, true );
		if ( '' === $event_id ) {
			echo '<p class="description">' . esc_html__( 'Not synced from Google Calendar.', 'method-tec-gcal-sync' ) . '</p>';
			return;
		}

		$calendar_id = (string) get_post_meta( $post->ID, Sync::META_CAL, true );
		$calendar    = Settings::calendar( $calendar_id );
		$link        = (string) get_post_meta( $post->ID, Sync::META_LINK, true );
		$synced      = (int) get_post_meta( $post->ID, Sync::META_SYNCED, true );
		$location    = (string) get_post_meta( $post->ID, Sync::META_LOCATION, true );
		$preserved   = Sync::preserved_fields( $post->ID );
		$reset_url   = add_query_arg( [ 'action' => 'method_gcal_sync_reset', 'post' => $post->ID, '_wpnonce' => wp_create_nonce( 'method_gcal_sync' ) ], admin_url( 'admin-post.php' ) );
		?>
		<p>
			<strong><?php esc_html_e( 'Calendar:', 'method-tec-gcal-sync' ); ?></strong>
			<?php echo esc_html( $calendar ? $calendar['label'] : $calendar_id ); ?>
			<?php if ( ! $calendar ) : ?><span class="description">(<?php esc_html_e( 'no longer configured', 'method-tec-gcal-sync' ); ?>)</span><?php endif; ?>
		</p>
		<?php if ( $link ) : ?>
			<p><a href="<?php echo esc_url( $link ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open in Google Calendar ↗', 'method-tec-gcal-sync' ); ?></a></p>
		<?php endif; ?>
		<?php if ( $location ) : ?>
			<p><strong><?php esc_html_e( 'Google location:', 'method-tec-gcal-sync' ); ?></strong><br><span class="description"><?php echo esc_html( $location ); ?></span></p>
		<?php endif; ?>
		<p>
			<strong><?php esc_html_e( 'Last synced:', 'method-tec-gcal-sync' ); ?></strong>
			<?php echo $synced ? esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $synced ) ) : '—'; ?>
		</p>
		<p>
			<strong><?php esc_html_e( 'Local edits kept on sync:', 'method-tec-gcal-sync' ); ?></strong><br>
			<?php if ( $preserved ) : ?>
				<code><?php echo esc_html( implode( ', ', $preserved ) ); ?></code>
			<?php else : ?>
				<span class="description"><?php esc_html_e( 'none — every field follows Google', 'method-tec-gcal-sync' ); ?></span>
			<?php endif; ?>
		</p>
		<p class="description"><?php esc_html_e( 'A field you change here stops following Google until you reset it. Trashing this event stops it from being re-created.', 'method-tec-gcal-sync' ); ?></p>
		<p>
			<a class="button" href="<?php echo esc_url( $reset_url ); ?>" onclick="return confirm('<?php echo esc_js( __( 'Overwrite every synced field with the current Google Calendar values?', 'method-tec-gcal-sync' ) ); ?>');">
				<?php esc_html_e( 'Reset to Google values', 'method-tec-gcal-sync' ); ?>
			</a>
		</p>
		<?php
	}

	public static function venue_box( \WP_Post $post ): void {
		$aliases = (array) get_post_meta( $post->ID, Venues::ALIAS_META, false );
		wp_nonce_field( 'method_gcal_sync_venue_aliases', 'method_gcal_sync_venue_nonce' );
		?>
		<p class="description">
			<?php esc_html_e( 'One per line. Any Google Calendar event whose Location field exactly matches a line here is assigned this venue. Lines are added automatically when the sync matches or creates a venue; add your own to point a Google location at an existing venue instead of letting the sync create a new one.', 'method-tec-gcal-sync' ); ?>
		</p>
		<textarea name="method_gcal_sync_venue_aliases" rows="4" style="width: 100%;"><?php echo esc_textarea( implode( "\n", $aliases ) ); ?></textarea>
		<?php
	}

	public static function save_venue_aliases( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_POST['method_gcal_sync_venue_nonce'] ) || ! wp_verify_nonce( (string) $_POST['method_gcal_sync_venue_nonce'], 'method_gcal_sync_venue_aliases' ) ) {
			return;
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		$lines = isset( $_POST['method_gcal_sync_venue_aliases'] ) ? (string) wp_unslash( $_POST['method_gcal_sync_venue_aliases'] ) : '';
		$lines = array_values( array_unique( array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n/', $lines ) ), 'strlen' ) ) );

		delete_post_meta( $post_id, Venues::ALIAS_META );
		foreach ( $lines as $line ) {
			add_post_meta( $post_id, Venues::ALIAS_META, sanitize_text_field( $line ), false );
		}
	}

	/* ---------------------------------------------------------------------
	 * Events list column
	 * ------------------------------------------------------------------ */

	public static function column( array $columns ): array {
		$columns['method_gcal'] = '<span class="dashicons dashicons-calendar-alt" title="' . esc_attr__( 'Google Calendar', 'method-tec-gcal-sync' ) . '"></span><span class="screen-reader-text">' . esc_html__( 'Google Calendar', 'method-tec-gcal-sync' ) . '</span>';
		return $columns;
	}

	public static function column_content( string $column, int $post_id ): void {
		if ( 'method_gcal' !== $column ) {
			return;
		}
		$calendar_id = (string) get_post_meta( $post_id, Sync::META_CAL, true );
		if ( '' === $calendar_id ) {
			return;
		}
		$calendar  = Settings::calendar( $calendar_id );
		$preserved = Sync::preserved_fields( $post_id );
		printf(
			'<span title="%s">%s%s</span>',
			esc_attr( $calendar ? $calendar['label'] : $calendar_id ),
			esc_html( $calendar ? $calendar['label'] : __( 'Google', 'method-tec-gcal-sync' ) ),
			$preserved ? ' <span class="dashicons dashicons-edit" title="' . esc_attr( sprintf( __( 'Local edits kept: %s', 'method-tec-gcal-sync' ), implode( ', ', $preserved ) ) ) . '"></span>' : ''
		);
	}
}
