<?php
/**
 * Pulls events from Outlook (ICS feeds or Microsoft Graph), assigns them to rooms
 * and caches them in an option that the board reads.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kivun_Board_Sync {

	const CRON_HOOK    = 'kivun_board_sync';
	const SCHEDULE     = 'kivun_board_interval';
	const DATA_OPTION  = 'kivun_board_data';
	const LOCK         = 'kivun_board_sync_lock';
	const DAYS_BEFORE  = 1;
	const DAYS_AFTER   = 14;

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'cron_schedules' ) );
		add_action( self::CRON_HOOK, array( __CLASS__, 'run' ) );
		add_action( 'init', array( __CLASS__, 'ensure_scheduled' ) );
	}

	/**
	 * @param array $schedules Cron schedules.
	 * @return array
	 */
	public static function cron_schedules( $schedules ) {
		$minutes                     = max( 1, (int) Kivun_Board_Settings::get( 'sync_interval' ) );
		$schedules[ self::SCHEDULE ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: minutes */
			'display'  => sprintf( 'Kivun board – every %d minutes', $minutes ),
		);
		return $schedules;
	}

	public static function ensure_scheduled() {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			self::schedule( false );
		}
	}

	/**
	 * (Re)schedules the recurring sync.
	 *
	 * @param bool $reset Clear an existing schedule first (e.g. after the interval changed).
	 */
	public static function schedule( $reset ) {
		if ( $reset ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
		wp_schedule_event( time() + 5, self::SCHEDULE, self::CRON_HOOK );
	}

	/**
	 * Cached sync result.
	 *
	 * @return array { events, synced_at, error, unmatched }
	 */
	public static function data() {
		$data = get_option( self::DATA_OPTION, array() );
		return array_merge(
			array(
				'events'    => array(),
				'synced_at' => 0,
				'error'     => '',
				'unmatched' => array(),
				'count'     => 0,
			),
			is_array( $data ) ? $data : array()
		);
	}

	/**
	 * Syncs if the cache is older than twice the interval (backup for sites where WP-Cron rarely runs).
	 */
	public static function maybe_run_stale() {
		$data = self::data();
		$max  = 2 * max( 1, (int) Kivun_Board_Settings::get( 'sync_interval' ) ) * MINUTE_IN_SECONDS;
		if ( time() - (int) $data['synced_at'] > $max ) {
			self::run();
		}
	}

	/**
	 * Performs a sync.
	 *
	 * @return array The stored data.
	 */
	public static function run() {
		if ( get_transient( self::LOCK ) ) {
			return self::data();
		}
		set_transient( self::LOCK, 1, 2 * MINUTE_IN_SECONDS );

		$tz      = wp_timezone();
		$today   = new DateTimeImmutable( 'today', $tz );
		$from_ts = $today->modify( '-' . self::DAYS_BEFORE . ' days' )->getTimestamp();
		$to_ts   = $today->modify( '+' . ( self::DAYS_AFTER + 1 ) . ' days' )->getTimestamp();

		$result   = self::fetch( $from_ts, $to_ts );
		$previous = self::data();

		if ( is_wp_error( $result ) ) {
			// Keep showing the last good data; just record the error.
			$previous['error']      = $result->get_error_message();
			$previous['attempt_at'] = time();
			update_option( self::DATA_OPTION, $previous, false );
			delete_transient( self::LOCK );
			return $previous;
		}

		list( $raw_events, $errors ) = $result;

		$rooms     = Kivun_Board_Settings::rooms();
		$hide_priv = (bool) Kivun_Board_Settings::get( 'hide_private' );
		$events    = array();
		$unmatched = array();
		foreach ( $raw_events as $event ) {
			if ( $hide_priv && ! empty( $event['private'] ) ) {
				continue;
			}
			$room = self::match_room( $event, $rooms );
			if ( null === $room ) {
				$unmatched[] = array(
					'title'    => $event['title'],
					'location' => $event['location'],
					'start'    => $event['start'],
				);
				continue;
			}
			$event['room'] = $room;
			unset( $event['source_room'], $event['categories'], $event['private'] );
			$events[] = $event;
		}

		usort(
			$events,
			function ( $a, $b ) {
				return $a['start'] - $b['start'];
			}
		);

		$data = array(
			'events'     => $events,
			'synced_at'  => time(),
			'attempt_at' => time(),
			'error'      => implode( ' | ', $errors ),
			'unmatched'  => array_slice( $unmatched, 0, 100 ),
			'count'      => count( $raw_events ),
		);
		update_option( self::DATA_OPTION, $data, false );
		delete_transient( self::LOCK );

		/**
		 * Fires after a successful sync.
		 *
		 * @param array $data Stored data.
		 */
		do_action( 'kivun_board_synced', $data );
		return $data;
	}

	/**
	 * Fetches events from all configured sources.
	 *
	 * @param int $from_ts Window start.
	 * @param int $to_ts   Window end.
	 * @return array|WP_Error [events, errors]. WP_Error only if every source failed.
	 */
	private static function fetch( $from_ts, $to_ts ) {
		$source  = Kivun_Board_Settings::get( 'source' );
		$field   = 'graph' === $source ? 'graph_mailboxes' : 'ics_urls';
		$sources = Kivun_Board_Settings::sources( $field );
		if ( ! $sources ) {
			return new WP_Error( 'kivun_no_sources', 'graph' === $source ? 'לא הוגדרו תיבות דואר לסנכרון.' : 'לא הוגדר קישור ICS ליומן.' );
		}

		$events = array();
		$errors = array();
		$ok     = 0;

		if ( 'graph' === $source ) {
			$client = new Kivun_Board_Graph_Client(
				Kivun_Board_Settings::get( 'graph_tenant_id' ),
				Kivun_Board_Settings::get( 'graph_client_id' ),
				Kivun_Board_Settings::get( 'graph_client_secret' )
			);
		} else {
			$parser = new Kivun_Board_ICS_Parser( wp_timezone() );
		}

		foreach ( $sources as $src ) {
			if ( 'graph' === $source ) {
				$list = $client->events( $src['address'], $from_ts, $to_ts );
			} else {
				$list = self::fetch_ics( $parser, $src['address'], $from_ts, $to_ts );
			}
			if ( is_wp_error( $list ) ) {
				$errors[] = self::mask( $src['address'] ) . ': ' . $list->get_error_message();
				continue;
			}
			$ok++;
			foreach ( $list as $event ) {
				$event['source_room'] = $src['room'];
				$events[]             = $event;
			}
		}

		if ( 0 === $ok ) {
			return new WP_Error( 'kivun_sync_failed', implode( ' | ', $errors ) );
		}
		return array( $events, $errors );
	}

	/**
	 * Downloads and parses one ICS feed.
	 *
	 * @param Kivun_Board_ICS_Parser $parser  Parser.
	 * @param string                 $url     Feed URL (webcal:// is accepted).
	 * @param int                    $from_ts Window start.
	 * @param int                    $to_ts   Window end.
	 * @return array|WP_Error
	 */
	private static function fetch_ics( $parser, $url, $from_ts, $to_ts ) {
		$url = preg_replace( '#^webcals?://#i', 'https://', trim( $url ) );
		if ( ! wp_http_validate_url( $url ) ) {
			return new WP_Error( 'kivun_bad_url', 'כתובת ICS לא תקינה' );
		}
		$response = wp_safe_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 5,
				'headers'     => array( 'Accept' => 'text/calendar, */*' ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		if ( 200 !== (int) $code ) {
			return new WP_Error( 'kivun_http', 'HTTP ' . $code );
		}
		if ( false === stripos( $body, 'BEGIN:VCALENDAR' ) ) {
			return new WP_Error( 'kivun_not_ics', 'התשובה אינה קובץ יומן ICS' );
		}
		return $parser->parse( $body, $from_ts, $to_ts );
	}

	/**
	 * Assigns an event to a room key.
	 *
	 * Order: room bound to the source calendar, then location / categories, then (optionally) title.
	 *
	 * @param array   $event Event.
	 * @param array[] $rooms Rooms.
	 * @return string|null Room key.
	 */
	public static function match_room( $event, $rooms ) {
		if ( ! empty( $event['source_room'] ) ) {
			$bound = Kivun_Board_Settings::normalize( $event['source_room'] );
			foreach ( $rooms as $room ) {
				if ( Kivun_Board_Settings::normalize( $room['name'] ) === $bound ) {
					return $room['key'];
				}
			}
		}

		$haystacks = array( $event['location'] . ' ' . implode( ' ', isset( $event['categories'] ) ? (array) $event['categories'] : array() ) );
		if ( Kivun_Board_Settings::get( 'match_title' ) ) {
			$haystacks[] = $event['title'];
		}

		foreach ( $haystacks as $text ) {
			$text = ' ' . Kivun_Board_Settings::normalize( $text ) . ' ';
			if ( '  ' === $text ) {
				continue;
			}
			// Prefer the longest keyword so "גבעת רם 2" beats "גבעת רם".
			$best     = null;
			$best_len = 0;
			foreach ( $rooms as $room ) {
				foreach ( $room['keywords'] as $keyword ) {
					$len = function_exists( 'mb_strlen' ) ? mb_strlen( $keyword ) : strlen( $keyword );
					if ( $len > $best_len && false !== strpos( $text, $keyword ) ) {
						$best     = $room['key'];
						$best_len = $len;
					}
				}
			}
			if ( null !== $best ) {
				return $best;
			}
		}
		return null;
	}

	/**
	 * Hides the secret part of a feed URL in error messages.
	 *
	 * @param string $address URL or mailbox.
	 * @return string
	 */
	private static function mask( $address ) {
		$host = wp_parse_url( $address, PHP_URL_HOST );
		return $host ? $host . '/…' : $address;
	}
}
