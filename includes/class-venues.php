<?php
namespace Method_GCal_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Turns Google's single-line `location` string into a TEC venue id.
 *
 * Resolution order:
 *   1. Alias — a venue that has this exact string in its `_gcs_location_alias` meta
 *      (added automatically on every match or create; editable on the venue screen).
 *   2. Match — normalised comparison against existing venue titles / street addresses.
 *   3. Create — parse the string into name / street / city / state / zip / country and
 *      create through Tribe__Events__Venue::create(), which fires tribe_events_venue_created
 *      with TEC's unprefixed data array — the hook method-tec-mapbox geocodes from.
 */
final class Venues {

	const ALIAS_META   = '_gcs_location_alias';
	const CREATED_META = '_gcs_created';

	/** @var array<string, int> location string → venue id, for this run. */
	private $cache = [];

	/** @var array<int, array{title: string, address: string, city: string}>|null */
	private $index = null;

	/**
	 * @return int Venue id, or 0 when the location is empty / could not be resolved.
	 *             In a dry run, -1 means "would create".
	 */
	public function resolve( string $location, Report $report, bool $dry_run ): int {
		$location = trim( preg_replace( '/\s+/u', ' ', $location ) );
		if ( '' === $location ) {
			return 0;
		}
		if ( isset( $this->cache[ $location ] ) ) {
			return $this->cache[ $location ];
		}

		$venue_id = (int) apply_filters( 'method_gcal_sync_resolve_venue', 0, $location );

		if ( ! $venue_id ) {
			$venue_id = $this->find_by_alias( $location );
		}

		if ( ! $venue_id ) {
			$venue_id = $this->find_by_match( $location );
			if ( $venue_id ) {
				$report->add( 'venues_matched', sprintf( 'Venue #%d matched "%s"', $venue_id, $location ) );
				if ( ! $dry_run ) {
					self::add_alias( $venue_id, $location );
				}
			}
		}

		if ( ! $venue_id ) {
			if ( $dry_run ) {
				$parts = self::parse( $location );
				$report->add( 'venues_created', sprintf( 'Would create venue "%s" (%s)', $parts['name'], self::describe( $parts ) ) );
				return $this->cache[ $location ] = -1;
			}
			$venue_id = $this->create( $location );
			if ( $venue_id ) {
				$report->add( 'venues_created', sprintf( 'Created venue #%d "%s"', $venue_id, get_the_title( $venue_id ) ) );
			} else {
				$report->error( sprintf( 'Could not create a venue for "%s"', $location ) );
			}
		}

		return $this->cache[ $location ] = (int) $venue_id;
	}

	/* ---------------------------------------------------------------------
	 * 1. Alias
	 * ------------------------------------------------------------------ */

	private function find_by_alias( string $location ): int {
		global $wpdb;

		$id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} p
			 INNER JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = %s AND m.meta_value = %s
			 WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')
			 ORDER BY p.ID ASC LIMIT 1",
			self::ALIAS_META,
			$location,
			\Tribe__Events__Main::VENUE_POST_TYPE
		) );

		return (int) $id;
	}

	public static function add_alias( int $venue_id, string $location ): void {
		$existing = (array) get_post_meta( $venue_id, self::ALIAS_META, false );
		if ( ! in_array( $location, $existing, true ) ) {
			add_post_meta( $venue_id, self::ALIAS_META, $location, false );
		}
	}

	/* ---------------------------------------------------------------------
	 * 2. Match against existing venues
	 * ------------------------------------------------------------------ */

	private function find_by_match( string $location ): int {
		$index = $this->index();
		if ( ! $index ) {
			return 0;
		}

		$parts      = self::parse( $location );
		$norm_full  = self::normalise( $location );
		$first      = self::normalise( (string) strtok( $location, ',' ) );
		$norm_name  = self::normalise( $parts['name'] );
		$norm_addr  = self::normalise( $parts['address'] );
		$norm_city  = self::normalise( $parts['city'] );

		foreach ( $index as $venue_id => $venue ) {
			// Whole string is the venue's name, or the venue's name is the first comma-part.
			if ( '' !== $venue['title'] && ( $venue['title'] === $norm_full || $venue['title'] === $first || $venue['title'] === $norm_name ) ) {
				return $venue_id;
			}
			// Same street address (and same city, when both sides have one). One side may carry
			// a trailing quadrant or suite the other lacks, so a prefix match counts.
			if ( '' !== $norm_addr && '' !== $venue['address'] && self::same_street( $norm_addr, $venue['address'] ) ) {
				if ( '' === $norm_city || '' === $venue['city'] || $norm_city === $venue['city'] ) {
					return $venue_id;
				}
			}
		}

		return 0;
	}

	/**
	 * @return array<int, array{title: string, address: string, city: string}>
	 */
	private function index(): array {
		if ( null !== $this->index ) {
			return $this->index;
		}
		global $wpdb;

		$rows = $wpdb->get_results( $wpdb->prepare(
			"SELECT p.ID, p.post_title,
			        a.meta_value AS address, c.meta_value AS city
			 FROM {$wpdb->posts} p
			 LEFT JOIN {$wpdb->postmeta} a ON a.post_id = p.ID AND a.meta_key = '_VenueAddress'
			 LEFT JOIN {$wpdb->postmeta} c ON c.post_id = p.ID AND c.meta_key = '_VenueCity'
			 WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft')",
			\Tribe__Events__Main::VENUE_POST_TYPE
		) );

		$this->index = [];
		foreach ( (array) $rows as $row ) {
			$this->index[ (int) $row->ID ] = [
				'title'   => self::normalise( (string) $row->post_title ),
				'address' => self::normalise( (string) $row->address ),
				'city'    => self::normalise( (string) $row->city ),
			];
		}
		return $this->index;
	}

	/**
	 * Both normalised. Equal, or one is the other plus a trailing word or two — never a bare
	 * house number matching a longer address.
	 */
	private static function same_street( string $a, string $b ): bool {
		if ( $a === $b ) {
			return true;
		}
		[ $short, $long ] = strlen( $a ) <= strlen( $b ) ? [ $a, $b ] : [ $b, $a ];
		return strlen( $short ) >= 8 && 0 === strpos( $long, $short . ' ' );
	}

	public static function normalise( string $value ): string {
		$value = remove_accents( html_entity_decode( $value, ENT_QUOTES, 'UTF-8' ) );
		$value = strtolower( $value );
		// "S.W." → "sw" before punctuation is stripped, otherwise it becomes "s w".
		$value = preg_replace( '/\b([nsew])\.\s*([ew])\.?/', '$1$2', $value );
		$value = preg_replace( '/[^a-z0-9]+/', ' ', $value );
		// Common street-type abbreviations, so "Main Street" and "Main St." compare equal.
		$map = [
			'street'    => 'st',
			'avenue'    => 'ave',
			'boulevard' => 'blvd',
			'drive'     => 'dr',
			'road'      => 'rd',
			'parkway'   => 'pkwy',
			'circle'    => 'cir',
			'court'     => 'ct',
			'lane'      => 'ln',
			'place'     => 'pl',
			'highway'   => 'hwy',
			'terrace'   => 'ter',
			'suite'     => 'ste',
			'northwest' => 'nw',
			'northeast' => 'ne',
			'southwest' => 'sw',
			'southeast' => 'se',
			'north'     => 'n',
			'south'     => 's',
			'east'      => 'e',
			'west'      => 'w',
		];
		$value = preg_replace_callback( '/\b(' . implode( '|', array_keys( $map ) ) . ')\b/', static function ( $m ) use ( $map ) {
			return $map[ $m[1] ];
		}, $value );
		// "s w" / "n e" left over from "S. W." → "sw".
		$value = preg_replace( '/\b([ns]) ([ew])\b/', '$1$2', $value );
		return trim( preg_replace( '/\s+/', ' ', $value ) );
	}

	/* ---------------------------------------------------------------------
	 * 3. Parse and create
	 * ------------------------------------------------------------------ */

	/**
	 * Best-effort split of Google's free-text location. Google's own place picker writes
	 * "Name, 123 Street, City, ST 12345, USA"; people write anything.
	 *
	 * @return array{name: string, address: string, city: string, state: string, zip: string, country: string}
	 */
	public static function parse( string $location ): array {
		$out = [ 'name' => '', 'address' => '', 'city' => '', 'state' => '', 'zip' => '', 'country' => '' ];

		$parts = array_values( array_filter( array_map( 'trim', explode( ',', $location ) ), 'strlen' ) );
		if ( ! $parts ) {
			return $out;
		}

		// Country.
		$last = end( $parts );
		if ( preg_match( '/^(usa?|u\.s\.a?\.?|united states( of america)?)$/i', $last ) ) {
			$out['country'] = 'United States';
			array_pop( $parts );
		} elseif ( count( $parts ) >= 4 && ! preg_match( '/\d/', $last ) && preg_match( '/^[A-Za-z][A-Za-z .]{1,30}\s+\d{4,6}(-\d{4})?$/', $parts[ count( $parts ) - 2 ] ) ) {
			$out['country'] = $last;
			array_pop( $parts );
		}

		// "ST 12345", "Alabama 35801", "ST", "12345".
		if ( $parts ) {
			$last = end( $parts );
			if ( preg_match( '/^([A-Za-z][A-Za-z .]{0,30}?)\s+(\d{5}(?:-\d{4})?)$/', $last, $m ) ) {
				$out['state'] = trim( $m[1] );
				$out['zip']   = $m[2];
				array_pop( $parts );
			} elseif ( preg_match( '/^[A-Z]{2}$/', $last ) ) {
				$out['state'] = $last;
				array_pop( $parts );
			} elseif ( preg_match( '/^\d{5}(?:-\d{4})?$/', $last ) ) {
				$out['zip'] = $last;
				array_pop( $parts );
				if ( $parts && preg_match( '/^[A-Z]{2}$/', end( $parts ) ) ) {
					$out['state'] = array_pop( $parts );
				}
			}
		}

		// City: the last remaining part, when something is left in front of it.
		if ( count( $parts ) >= 2 && ! preg_match( '/^\d/', end( $parts ) ) ) {
			$out['city'] = array_pop( $parts );
		}

		// Name and/or street from what is left.
		$looks_like_street = static function ( string $p ): bool {
			return (bool) preg_match( '/^\d+[\w-]*\s+\S/', $p ) || (bool) preg_match( '/^(p\.?o\.? box|po box)\s*\d+/i', $p );
		};

		if ( 1 === count( $parts ) ) {
			$p = $parts[0];
			if ( $looks_like_street( $p ) ) {
				$out['address'] = $p;
			} else {
				$out['name'] = $p;
				// "Huntsville, AL" — a lone non-street part with no city is itself the city.
				if ( '' === $out['city'] && ( '' !== $out['state'] || '' !== $out['zip'] ) ) {
					$out['city'] = $p;
				}
			}
		} elseif ( count( $parts ) >= 2 ) {
			if ( $looks_like_street( $parts[0] ) ) {
				$out['address'] = implode( ', ', $parts );
			} else {
				// Leading non-street parts are the name ("Council Chambers, City Hall"); the first
				// street-looking part and everything after it is the address.
				$names = [];
				while ( $parts && ! $looks_like_street( $parts[0] ) && count( $parts ) > 1 ) {
					$names[] = array_shift( $parts );
				}
				if ( $parts && ! $looks_like_street( $parts[0] ) ) {
					// Nothing street-like at all: "Name, Something" → name + loose address.
					$names[] = array_shift( $parts );
				}
				$out['name']    = implode( ', ', $names );
				$out['address'] = implode( ', ', $parts );
			}
		}

		if ( '' === $out['name'] ) {
			$out['name'] = $out['address'] ?: $out['city'] ?: $location;
		}

		// A US-looking address (5-digit ZIP or two-letter state) with no country gets TEC's
		// default country so geocoders have one.
		$looks_us = preg_match( '/^\d{5}(-\d{4})?$/', $out['zip'] ) || preg_match( '/^[A-Z]{2}$/', $out['state'] );
		if ( '' === $out['country'] && '' !== $out['address'] && $looks_us && function_exists( 'tribe_get_option' ) ) {
			$default = tribe_get_option( 'defaultCountry' );
			if ( is_array( $default ) && ! empty( $default[1] ) ) {
				$out['country'] = (string) $default[1];
			}
		}

		return (array) apply_filters( 'method_gcal_sync_parse_location', $out, $location );
	}

	private function create( string $location ): int {
		$parts = self::parse( $location );

		// TEC's classic venue data keys — exactly what tribe_events_venue_created hands to
		// method-tec-mapbox's build_address().
		$data = [
			'Venue'         => $parts['name'],
			'Address'       => $parts['address'],
			'City'          => $parts['city'],
			'StateProvince' => $parts['state'],
			'State'         => $parts['state'],
			'Province'      => '',
			'Zip'           => $parts['zip'],
			'Country'       => $parts['country'],
			'ShowMap'       => 'true',
			'ShowMapLink'   => 'true',
		];
		$data = (array) apply_filters( 'method_gcal_sync_venue_data', $data, $location, $parts );

		if ( '' === trim( (string) $data['Venue'] ) ) {
			return 0;
		}

		// avoid_duplicates: TEC's own duplicate finder (title + address) as a last guard.
		$venue_id = (int) \Tribe__Events__Venue::instance()->create( $data, 'publish', true );
		if ( ! $venue_id ) {
			return 0;
		}

		// Only mark venues this plugin actually created (a duplicate hit returns an existing id).
		$created_gmt = (string) get_post_field( 'post_date_gmt', $venue_id );
		if ( '' !== $created_gmt && abs( time() - strtotime( $created_gmt . ' UTC' ) ) < 60 ) {
			update_post_meta( $venue_id, self::CREATED_META, 1 );
		}
		self::add_alias( $venue_id, $location );
		$this->index = null; // Next match sees it.

		do_action( 'method_gcal_sync_venue_created', $venue_id, $location, $parts );

		return $venue_id;
	}

	private static function describe( array $parts ): string {
		return implode( ', ', array_filter( [ $parts['address'], $parts['city'], trim( $parts['state'] . ' ' . $parts['zip'] ), $parts['country'] ] ) ) ?: 'no address';
	}
}
