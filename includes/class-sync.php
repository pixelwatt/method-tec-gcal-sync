<?php
namespace Method_GCal_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * The sync engine.
 *
 * Identity:   each TEC event carries _gcs_calendar_id + _gcs_event_id (Google's per-instance id,
 *             so expanded recurrences are independent TEC events).
 *
 * Merge:      _gcs_snapshot holds, per synced field, the value the importer last wrote (as it
 *             landed in the database) — or, for a field it left alone, the value Google had.
 *             On each run, per field:
 *               no snapshot / forced      → write source
 *               local == snapshot         → untouched locally → write source if it changed
 *               local != snapshot         → edited locally   → keep local, remember source
 *             "Reset to Google values" just deletes the snapshot.
 *
 * Deletions:  the whole window is fetched each run; a local event in the window whose id is not
 *             in the response is checked with one GET — gone (404/cancelled) → trash/delete,
 *             still there (moved out of the window) → updated like any other event.
 */
final class Sync {

	const META_CAL       = '_gcs_calendar_id';
	const META_ID        = '_gcs_event_id';
	const META_UPDATED   = '_gcs_updated';
	const META_SNAPSHOT  = '_gcs_snapshot';
	const META_LINK      = '_gcs_link';
	const META_SYNCED    = '_gcs_synced';
	const META_LOCATION  = '_gcs_location';
	const META_ICAL      = '_gcs_ical_uid';
	const META_RECURRING = '_gcs_recurring_id';

	const FIELDS = [ 'title', 'content', 'start_date', 'end_date', 'all_day', 'timezone', 'venue', 'url' ];
	const DATE_FIELDS = [ 'start_date', 'end_date', 'all_day', 'timezone' ];

	const LOCK = 'method_gcal_sync_lock';

	/** @var array */
	private $settings;

	/** @var Google_Client */
	private $client;

	/** @var Venues */
	private $venues;

	/** @var Report */
	private $report;

	/** @var bool */
	private $dry_run;

	private function __construct( bool $dry_run ) {
		$this->settings = Settings::get();
		$this->client   = new Google_Client( $this->settings['api_key'] );
		$this->venues   = new Venues();
		$this->report   = new Report( $dry_run );
		$this->dry_run  = $dry_run;
	}

	/* ---------------------------------------------------------------------
	 * Entry points
	 * ------------------------------------------------------------------ */

	/**
	 * Sync every configured calendar (or just the given ids).
	 *
	 * @param string[] $only_calendars
	 */
	public static function run( array $only_calendars = [], bool $dry_run = false ): Report {
		$sync = new self( $dry_run );
		$r    = $sync->report;

		if ( '' === $sync->settings['api_key'] ) {
			$r->error( __( 'No Google API key configured.', 'method-tec-gcal-sync' ) );
			return $sync->done();
		}
		if ( ! $sync->settings['calendars'] ) {
			$r->error( __( 'No calendars configured.', 'method-tec-gcal-sync' ) );
			return $sync->done();
		}
		if ( ! $dry_run && get_transient( self::LOCK ) ) {
			$r->error( __( 'A sync is already running.', 'method-tec-gcal-sync' ) );
			return $sync->done( false );
		}
		if ( ! $dry_run ) {
			set_transient( self::LOCK, time(), 15 * MINUTE_IN_SECONDS );
		}

		do_action( 'method_gcal_sync_before_run', $sync->settings, $dry_run );

		try {
			foreach ( $sync->settings['calendars'] as $calendar ) {
				if ( $only_calendars && ! in_array( $calendar['calendar_id'], $only_calendars, true ) ) {
					continue;
				}
				$sync->sync_calendar( $calendar );
			}
		} finally {
			if ( ! $dry_run ) {
				delete_transient( self::LOCK );
			}
		}

		do_action( 'method_gcal_sync_after_run', $r, $sync->settings, $dry_run );

		return $sync->done();
	}

	/**
	 * Re-fetch one local event from Google and apply it. $force ignores the snapshot, i.e.
	 * "reset to Google values".
	 */
	public static function sync_post( int $post_id, bool $force = true, bool $dry_run = false ): Report {
		$sync = new self( $dry_run );
		$r    = $sync->report;

		$calendar_id = (string) get_post_meta( $post_id, self::META_CAL, true );
		$event_id    = (string) get_post_meta( $post_id, self::META_ID, true );
		$calendar    = $calendar_id ? Settings::calendar( $calendar_id ) : null;

		if ( '' === $event_id || ! $calendar ) {
			$r->error( sprintf( __( 'Event #%d is not linked to a configured Google calendar.', 'method-tec-gcal-sync' ), $post_id ) );
			return $sync->done( false );
		}

		$item = $sync->client->get_event( $calendar_id, $event_id );
		if ( is_wp_error( $item ) ) {
			if ( 'not_found' === $item->get_error_code() ) {
				$sync->remove( $post_id, 'deleted from Google' );
			} else {
				$r->error( $item->get_error_message() );
			}
			return $sync->done( false );
		}
		if ( 'cancelled' === ( $item['status'] ?? '' ) ) {
			$sync->remove( $post_id, 'cancelled on Google' );
			return $sync->done( false );
		}

		$sync->apply_item( $calendar, $item, '', $post_id, $force );
		return $sync->done( false );
	}

	private function done( bool $store = true ): Report {
		$this->report->finish();
		if ( $store ) {
			update_option( Settings::LAST_RUN, $this->report->to_array(), false );
		}
		return $this->report;
	}

	/* ---------------------------------------------------------------------
	 * One calendar
	 * ------------------------------------------------------------------ */

	private function sync_calendar( array $calendar ): void {
		$r        = $this->report;
		$cal_id   = $calendar['calendar_id'];
		$now      = time();
		$time_min = gmdate( 'Y-m-d\TH:i:s\Z', $now - $this->settings['lookback_days'] * DAY_IN_SECONDS );
		$time_max = gmdate( 'Y-m-d\TH:i:s\Z', $now + $this->settings['window_days'] * DAY_IN_SECONDS );

		$response = $this->client->list_events( $cal_id, $time_min, $time_max );
		if ( is_wp_error( $response ) ) {
			$r->error( sprintf( '%s: %s', $calendar['label'], $response->get_error_message() ) );
			return;
		}

		$cal_tz = (string) $response['timeZone'];
		$index  = $this->local_index( $cal_id );
		$seen   = [];

		foreach ( $response['items'] as $item ) {
			$r->counts['fetched']++;
			$event_id = (string) $item['id'];
			$seen[ $event_id ] = true;
			$local = $index[ $event_id ] ?? null;

			if ( 'cancelled' === ( $item['status'] ?? '' ) ) {
				if ( $local && 'trash' !== $local['status'] ) {
					$this->remove( $local['id'], 'cancelled on Google' );
				}
				continue;
			}

			if ( $local && 'trash' === $local['status'] ) {
				$r->add( 'skipped', sprintf( 'Skipped "%s" — trashed locally (#%d)', $this->title_of( $item ), $local['id'] ) );
				continue;
			}

			$this->apply_item( $calendar, $item, $cal_tz, $local ? $local['id'] : 0, false );
		}

		// Local events inside the window that Google did not return.
		$min_utc = gmdate( 'Y-m-d H:i:s', $now - $this->settings['lookback_days'] * DAY_IN_SECONDS );
		$max_utc = gmdate( 'Y-m-d H:i:s', $now + $this->settings['window_days'] * DAY_IN_SECONDS );

		foreach ( $index as $event_id => $local ) {
			if ( isset( $seen[ $event_id ] ) || 'trash' === $local['status'] ) {
				continue;
			}
			if ( '' === $local['start_utc'] || $local['start_utc'] < $min_utc || $local['start_utc'] > $max_utc ) {
				continue; // Outside the window: not ours to judge this run.
			}

			$item = $this->client->get_event( $cal_id, $event_id );
			if ( is_wp_error( $item ) ) {
				if ( 'not_found' === $item->get_error_code() ) {
					$this->remove( $local['id'], 'deleted from Google' );
				} else {
					$r->error( sprintf( 'Could not verify #%d: %s', $local['id'], $item->get_error_message() ) );
				}
				continue;
			}
			if ( 'cancelled' === ( $item['status'] ?? '' ) ) {
				$this->remove( $local['id'], 'cancelled on Google' );
				continue;
			}
			// Still exists — it was moved out of the window. Apply the move.
			$this->apply_item( $calendar, $item, $cal_tz, $local['id'], false );
		}
	}

	/**
	 * @return array<string, array{id: int, status: string, start_utc: string}> keyed by Google event id.
	 */
	private function local_index( string $calendar_id ): array {
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_status, i.meta_value AS event_id, s.meta_value AS start_utc
			 FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = %s AND c.meta_value = %s
			 INNER JOIN {$wpdb->postmeta} i ON i.post_id = p.ID AND i.meta_key = %s
			 LEFT JOIN {$wpdb->postmeta} s ON s.post_id = p.ID AND s.meta_key = '_EventStartDateUTC'
			 WHERE p.post_type = %s AND p.post_status <> 'auto-draft'
			 ORDER BY p.ID ASC",
			self::META_CAL,
			$calendar_id,
			self::META_ID,
			\Tribe__Events__Main::POSTTYPE
		) );

		$index = [];
		foreach ( (array) $rows as $row ) {
			$key = (string) $row->event_id;
			// If two local posts somehow share an id, prefer the one that isn't trashed.
			if ( isset( $index[ $key ] ) && 'trash' !== $index[ $key ]['status'] ) {
				continue;
			}
			$index[ $key ] = [
				'id'        => (int) $row->ID,
				'status'    => (string) $row->post_status,
				'start_utc' => (string) $row->start_utc,
			];
		}
		return $index;
	}

	/* ---------------------------------------------------------------------
	 * One event
	 * ------------------------------------------------------------------ */

	private function apply_item( array $calendar, array $item, string $cal_tz, int $post_id, bool $force ): void {
		$r     = $this->report;
		$title = $this->title_of( $item );

		$src = $this->source_fields( $item, $cal_tz, $calendar );
		if ( ! $src ) {
			$r->error( sprintf( 'No usable dates on "%s" (%s)', $title, $item['id'] ) );
			return;
		}

		// ---- Create -------------------------------------------------------------------------
		if ( ! $post_id ) {
			if ( $this->dry_run ) {
				$r->add( 'created', sprintf( 'Would create "%s" (%s%s)', $title, $src['start_date'], $src['venue'] ? ', venue ' . $this->venue_label( $src['venue'] ) : '' ) );
				return;
			}
			$new_id = $this->write( 0, $src, $src, $calendar, $item );
			if ( ! $new_id ) {
				$r->error( sprintf( 'Failed to create "%s"', $title ) );
				return;
			}
			$this->save_snapshot( $new_id, $src, [] );
			$r->add( 'created', sprintf( 'Created #%d "%s" (%s)', $new_id, $title, $src['start_date'] ) );
			do_action( 'method_gcal_sync_event_synced', $new_id, $item, true, self::FIELDS, [] );
			return;
		}

		// ---- Update (three-way) ---------------------------------------------------------------
		$local     = self::local_fields( $post_id );
		$snapshot  = $force ? [] : self::snapshot( $post_id );
		$write     = [];
		$preserved = [];

		foreach ( self::FIELDS as $field ) {
			$s = $src[ $field ];
			$l = $local[ $field ];

			if ( null === $s ) {
				continue; // Unresolvable on the source side (e.g. venue creation failed): hands off.
			}

			if ( 'venue' === $field && -1 === $s ) {
				// Dry run: the venue would be created; it can only differ from whatever is local.
				if ( array_key_exists( $field, $snapshot ) && ! self::equal( $field, $l, $snapshot[ $field ] ) ) {
					$preserved[] = $field;
				} else {
					$write[ $field ] = $s;
				}
				continue;
			}

			if ( self::equal( $field, $s, $l ) ) {
				continue; // Already in sync, whichever side moved.
			}
			if ( ! array_key_exists( $field, $snapshot ) || self::equal( $field, $l, $snapshot[ $field ] ) ) {
				$write[ $field ] = $s; // Untouched locally (or never tracked): follow Google.
			} else {
				$preserved[] = $field; // Edited locally: keep.
			}
		}

		if ( ! $write && ! $preserved ) {
			$r->counts['unchanged']++;
			if ( ! $this->dry_run ) {
				$this->touch( $post_id, $item );
			}
			return;
		}

		$fields_list = implode( ', ', array_keys( $write ) );
		$kept_list   = $preserved ? ' — kept local: ' . implode( ', ', $preserved ) : '';

		if ( $this->dry_run ) {
			if ( $write ) {
				$r->add( 'updated', sprintf( 'Would update #%d "%s": %s%s', $post_id, $title, $fields_list, $kept_list ) );
			}
			if ( $preserved ) {
				$r->add( 'preserved', sprintf( 'Preserving local edits on #%d "%s": %s', $post_id, $title, implode( ', ', $preserved ) ) );
			}
			return;
		}

		if ( $write ) {
			$this->write( $post_id, $write, $src, $calendar, $item );
			$r->add( 'updated', sprintf( 'Updated #%d "%s": %s%s', $post_id, $title, $fields_list, $kept_list ) );
		}
		if ( $preserved ) {
			$r->add( 'preserved', sprintf( 'Kept local edits on #%d "%s": %s', $post_id, $title, implode( ', ', $preserved ) ) );
		}

		$this->save_snapshot( $post_id, $src, $preserved );
		$this->touch( $post_id, $item );
		do_action( 'method_gcal_sync_event_synced', $post_id, $item, false, array_keys( $write ), $preserved );
	}

	/**
	 * Write fields to a new or existing event.
	 *
	 * @param int   $post_id 0 to create.
	 * @param array $write   field => value — the fields to change.
	 * @param array $src     all source fields (for composing a coherent date set).
	 * @return int Post id, 0 on failure.
	 */
	private function write( int $post_id, array $write, array $src, array $calendar, array $item ): int {
		$local = $post_id ? self::local_fields( $post_id ) : [];
		$args  = [];

		if ( isset( $write['title'] ) ) {
			$args['title'] = $write['title'];
		}
		if ( isset( $write['content'] ) ) {
			$args['content'] = $write['content'];
		}

		// Dates travel as a set: TEC recomputes UTC + duration from start/end/timezone/all_day,
		// so send the effective value of each — the source where it changed, the local otherwise.
		if ( ! $post_id || array_intersect( self::DATE_FIELDS, array_keys( $write ) ) ) {
			foreach ( self::DATE_FIELDS as $field ) {
				$args[ $field ] = array_key_exists( $field, $write ) ? $write[ $field ] : ( $local[ $field ] ?? $src[ $field ] );
			}
			$args['all_day'] = (bool) $args['all_day'];
		}

		if ( ! $post_id ) {
			$args['status'] = $this->settings['post_status'];
			if ( $this->settings['author'] ) {
				$args['author'] = $this->settings['author'];
			}
		}

		$args = (array) apply_filters( 'method_gcal_sync_event_args', $args, $item, $calendar, $post_id );

		if ( ! $post_id ) {
			$post = tribe_events()->set_args( $args )->create();
			if ( ! $post instanceof \WP_Post ) {
				return 0;
			}
			$post_id = (int) $post->ID;

			update_post_meta( $post_id, self::META_CAL, $calendar['calendar_id'] );
			update_post_meta( $post_id, self::META_ID, (string) $item['id'] );
			if ( ! empty( $item['iCalUID'] ) ) {
				update_post_meta( $post_id, self::META_ICAL, (string) $item['iCalUID'] );
			}
			if ( ! empty( $item['recurringEventId'] ) ) {
				update_post_meta( $post_id, self::META_RECURRING, (string) $item['recurringEventId'] );
			}
			if ( $calendar['category'] ) {
				$term_id = $this->category_term( $calendar['category'] );
				if ( $term_id ) {
					wp_set_object_terms( $post_id, [ $term_id ], \Tribe__Events__Main::TAXONOMY, true );
				}
			}
		} elseif ( $args ) {
			tribe_events()->where( 'id', $post_id )->set_args( $args )->save();
		}

		if ( isset( $args['all_day'] ) ) {
			// Belt and braces: the ORM handles all_day, but a stale 'yes' is what TEC renders from.
			if ( $args['all_day'] ) {
				update_post_meta( $post_id, '_EventAllDay', 'yes' );
			} else {
				delete_post_meta( $post_id, '_EventAllDay' );
			}
		}

		if ( array_key_exists( 'venue', $write ) && is_int( $write['venue'] ) && $write['venue'] >= 0 ) {
			if ( $write['venue'] > 0 ) {
				update_post_meta( $post_id, '_EventVenueID', (int) $write['venue'] );
			} else {
				delete_post_meta( $post_id, '_EventVenueID' );
			}
		}
		if ( array_key_exists( 'url', $write ) ) {
			if ( '' !== $write['url'] ) {
				update_post_meta( $post_id, '_EventURL', $write['url'] );
			} else {
				delete_post_meta( $post_id, '_EventURL' );
			}
		}

		$this->touch( $post_id, $item );
		return $post_id;
	}

	private function touch( int $post_id, array $item ): void {
		update_post_meta( $post_id, self::META_UPDATED, (string) ( $item['updated'] ?? '' ) );
		update_post_meta( $post_id, self::META_LINK, esc_url_raw( (string) ( $item['htmlLink'] ?? '' ) ) );
		update_post_meta( $post_id, self::META_LOCATION, (string) ( $item['location'] ?? '' ) );
		update_post_meta( $post_id, self::META_SYNCED, time() );
	}

	/**
	 * Snapshot = the fields as they now sit in the database (what we wrote, post-sanitisation),
	 * except preserved fields, which record what Google currently says.
	 */
	private function save_snapshot( int $post_id, array $src, array $preserved ): void {
		clean_post_cache( $post_id );
		$snapshot = self::local_fields( $post_id );
		foreach ( $preserved as $field ) {
			$snapshot[ $field ] = $src[ $field ];
		}
		update_post_meta( $post_id, self::META_SNAPSHOT, $snapshot );
	}

	private function remove( int $post_id, string $why ): void {
		$title = get_the_title( $post_id );
		if ( $this->dry_run ) {
			$this->report->add( 'trashed', sprintf( 'Would %s #%d "%s" — %s', 'delete' === $this->settings['delete_mode'] ? 'delete' : 'trash', $post_id, $title, $why ) );
			return;
		}
		do_action( 'method_gcal_sync_before_remove', $post_id, $why );
		if ( 'delete' === $this->settings['delete_mode'] ) {
			wp_delete_post( $post_id, true );
		} else {
			wp_trash_post( $post_id );
		}
		$this->report->add( 'trashed', sprintf( '%s #%d "%s" — %s', 'delete' === $this->settings['delete_mode'] ? 'Deleted' : 'Trashed', $post_id, $title, $why ) );
	}

	/* ---------------------------------------------------------------------
	 * Field extraction & comparison
	 * ------------------------------------------------------------------ */

	/**
	 * @return array|null null when the item has no usable dates.
	 */
	private function source_fields( array $item, string $cal_tz, array $calendar ): ?array {
		$dates = $this->dates( $item, $cal_tz );
		if ( ! $dates ) {
			return null;
		}

		$description = (string) ( $item['description'] ?? '' );
		$content     = wp_kses_post( $description );
		if ( '' !== $content && false === strpos( $content, '<' ) ) {
			$content = wpautop( $content ); // Plain text from Google: keep its paragraphs.
		}

		$url = '';
		if ( ! empty( $item['source']['url'] ) && filter_var( $item['source']['url'], FILTER_VALIDATE_URL ) ) {
			$url = esc_url_raw( (string) $item['source']['url'] );
		}

		$location = trim( (string) ( $item['location'] ?? '' ) );
		$venue    = '' !== $location ? $this->venues->resolve( $location, $this->report, $this->dry_run ) : 0;
		if ( '' !== $location && 0 === $venue ) {
			$venue = null; // Could not resolve or create: leave the local venue untouched.
		}

		$fields = [
			'title'      => $this->title_of( $item ),
			'content'    => $content,
			'start_date' => $dates['start'],
			'end_date'   => $dates['end'],
			'all_day'    => $dates['all_day'],
			'timezone'   => $dates['timezone'],
			'venue'      => $venue,
			'url'        => $url,
		];

		return (array) apply_filters( 'method_gcal_sync_source_fields', $fields, $item, $calendar );
	}

	/**
	 * Google → TEC local-time strings in the event's own zone.
	 *
	 * @return array{start: string, end: string, all_day: bool, timezone: string}|null
	 */
	private function dates( array $item, string $cal_tz ): ?array {
		$s = (array) ( $item['start'] ?? [] );
		$e = (array) ( $item['end'] ?? [] );

		$tz_name = (string) ( $s['timeZone'] ?? $cal_tz ?: wp_timezone_string() );
		if ( ! in_array( $tz_name, timezone_identifiers_list(), true ) ) {
			$tz_name = wp_timezone_string();
			if ( ! in_array( $tz_name, timezone_identifiers_list(), true ) ) {
				$tz_name = 'UTC';
			}
		}

		try {
			$tz = new \DateTimeZone( $tz_name );

			if ( ! empty( $s['date'] ) ) {
				$start = new \DateTimeImmutable( (string) $s['date'], $tz );
				$end   = new \DateTimeImmutable( (string) ( $e['date'] ?? $s['date'] ), $tz );
				$end   = $end->modify( '-1 day' ); // Google's all-day end is exclusive.
				if ( $end < $start ) {
					$end = $start;
				}
				return [
					'start'    => $start->format( 'Y-m-d' ) . ' 00:00:00',
					'end'      => $end->format( 'Y-m-d' ) . ' 23:59:59',
					'all_day'  => true,
					'timezone' => $tz_name,
				];
			}

			if ( ! empty( $s['dateTime'] ) ) {
				$start = ( new \DateTimeImmutable( (string) $s['dateTime'] ) )->setTimezone( $tz );
				$end   = ( new \DateTimeImmutable( (string) ( $e['dateTime'] ?? $s['dateTime'] ) ) )->setTimezone( $tz );
				if ( $end < $start ) {
					$end = $start;
				}
				return [
					'start'    => $start->format( 'Y-m-d H:i:s' ),
					'end'      => $end->format( 'Y-m-d H:i:s' ),
					'all_day'  => false,
					'timezone' => $tz_name,
				];
			}
		} catch ( \Exception $e ) {
			return null;
		}

		return null;
	}

	private function title_of( array $item ): string {
		$title = trim( wp_strip_all_tags( (string) ( $item['summary'] ?? '' ) ) );
		return '' !== $title ? $title : __( '(No title)', 'method-tec-gcal-sync' );
	}

	/**
	 * The synced fields as they currently are on the local event.
	 */
	public static function local_fields( int $post_id ): array {
		$post = get_post( $post_id );
		return [
			'title'      => $post ? (string) $post->post_title : '',
			'content'    => $post ? (string) $post->post_content : '',
			'start_date' => (string) get_post_meta( $post_id, '_EventStartDate', true ),
			'end_date'   => (string) get_post_meta( $post_id, '_EventEndDate', true ),
			'all_day'    => tribe_is_truthy( get_post_meta( $post_id, '_EventAllDay', true ) ),
			'timezone'   => (string) get_post_meta( $post_id, '_EventTimezone', true ),
			'venue'      => (int) get_post_meta( $post_id, '_EventVenueID', true ),
			'url'        => (string) get_post_meta( $post_id, '_EventURL', true ),
		];
	}

	public static function snapshot( int $post_id ): array {
		$snapshot = get_post_meta( $post_id, self::META_SNAPSHOT, true );
		return is_array( $snapshot ) ? $snapshot : [];
	}

	/**
	 * Fields the next sync would leave alone because they differ from the snapshot.
	 *
	 * @return string[]
	 */
	public static function preserved_fields( int $post_id ): array {
		$snapshot = self::snapshot( $post_id );
		if ( ! $snapshot ) {
			return [];
		}
		$local = self::local_fields( $post_id );
		$out   = [];
		foreach ( self::FIELDS as $field ) {
			if ( array_key_exists( $field, $snapshot ) && ! self::equal( $field, $local[ $field ], $snapshot[ $field ] ) ) {
				$out[] = $field;
			}
		}
		return $out;
	}

	public static function equal( string $field, $a, $b ): bool {
		switch ( $field ) {
			case 'all_day':
				return (bool) $a === (bool) $b;
			case 'venue':
				return (int) $a === (int) $b;
			case 'content':
			case 'title':
				return self::norm_text( (string) $a ) === self::norm_text( (string) $b );
			default:
				return trim( (string) $a ) === trim( (string) $b );
		}
	}

	/**
	 * Whitespace- and entity-insensitive comparison, so slashing/kses round-trips don't
	 * read as local edits.
	 */
	private static function norm_text( string $value ): string {
		$value = html_entity_decode( wp_unslash( $value ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$value = str_replace( [ "\r\n", "\r" ], "\n", $value );
		$value = preg_replace( '/>\s+</', '><', $value );
		$value = preg_replace( '/[ \t]+/', ' ', $value );
		$value = preg_replace( '/\n{2,}/', "\n", $value );
		return trim( $value );
	}

	private function category_term( string $slug ): int {
		$term = get_term_by( 'slug', $slug, \Tribe__Events__Main::TAXONOMY );
		if ( $term instanceof \WP_Term ) {
			return (int) $term->term_id;
		}
		$created = wp_insert_term( ucwords( str_replace( [ '-', '_' ], ' ', $slug ) ), \Tribe__Events__Main::TAXONOMY, [ 'slug' => $slug ] );
		return is_wp_error( $created ) ? 0 : (int) $created['term_id'];
	}

	private function venue_label( int $venue_id ): string {
		return -1 === $venue_id ? 'new' : '#' . $venue_id;
	}
}
