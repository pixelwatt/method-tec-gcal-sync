<?php
namespace Method_GCal_Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over the Google Calendar API v3 (public calendars, API-key auth).
 *
 * One list call per 2,500 events per calendar per run, plus one GET per event that vanished
 * from the window (to tell "moved" from "deleted"). Well inside the free quota.
 */
final class Google_Client {

	const BASE = 'https://www.googleapis.com/calendar/v3/calendars/';

	const ITEM_FIELDS = 'id,status,htmlLink,summary,description,location,start,end,updated,recurringEventId,source,iCalUID';

	/** @var string */
	private $api_key;

	public function __construct( string $api_key ) {
		$this->api_key = $api_key;
	}

	/**
	 * Every single-instance event in the window, recurrences expanded server-side.
	 *
	 * @return array{timeZone: string, items: array}|\WP_Error
	 */
	public function list_events( string $calendar_id, string $time_min, string $time_max ) {
		$items      = [];
		$time_zone  = '';
		$page_token = '';
		$pages      = 0;

		do {
			$query = [
				'key'          => $this->api_key,
				'singleEvents' => 'true',
				'showDeleted'  => 'true',
				'orderBy'      => 'startTime',
				'timeMin'      => $time_min,
				'timeMax'      => $time_max,
				'maxResults'   => 2500,
				'fields'       => 'nextPageToken,timeZone,items(' . self::ITEM_FIELDS . ')',
			];
			if ( '' !== $page_token ) {
				$query['pageToken'] = $page_token;
			}

			$body = $this->request( self::BASE . rawurlencode( $calendar_id ) . '/events', $query );
			if ( is_wp_error( $body ) ) {
				return $body;
			}

			$time_zone = $time_zone ?: (string) ( $body['timeZone'] ?? '' );
			foreach ( (array) ( $body['items'] ?? [] ) as $item ) {
				if ( is_array( $item ) && ! empty( $item['id'] ) ) {
					$items[] = $item;
				}
			}
			$page_token = (string) ( $body['nextPageToken'] ?? '' );
		} while ( '' !== $page_token && ++$pages < 20 );

		return [ 'timeZone' => $time_zone, 'items' => $items ];
	}

	/**
	 * One event by id. Returns a WP_Error with code 'not_found' when Google no longer has it.
	 *
	 * @return array|\WP_Error
	 */
	public function get_event( string $calendar_id, string $event_id ) {
		return $this->request(
			self::BASE . rawurlencode( $calendar_id ) . '/events/' . rawurlencode( $event_id ),
			[ 'key' => $this->api_key, 'fields' => self::ITEM_FIELDS ]
		);
	}

	/**
	 * @return array|\WP_Error
	 */
	private function request( string $url, array $query ) {
		$response = wp_remote_get(
			add_query_arg( array_map( 'rawurlencode', $query ), $url ),
			[ 'timeout' => 20, 'headers' => [ 'Accept' => 'application/json' ] ]
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 200 === $code && is_array( $body ) ) {
			return $body;
		}

		$message = is_array( $body ) ? (string) ( $body['error']['message'] ?? '' ) : '';
		if ( '' === $message ) {
			$message = "HTTP {$code}";
		}

		if ( in_array( $code, [ 404, 410 ], true ) ) {
			return new \WP_Error( 'not_found', $message, [ 'status' => $code ] );
		}
		if ( 403 === $code ) {
			return new \WP_Error( 'forbidden', $message . ' — ' . __( 'check that the calendar is public and the API key allows the Calendar API.', 'method-tec-gcal-sync' ), [ 'status' => $code ] );
		}
		return new \WP_Error( 'google_api', $message, [ 'status' => $code ] );
	}
}
