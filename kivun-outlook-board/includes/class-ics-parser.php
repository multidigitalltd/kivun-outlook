<?php
/**
 * Minimal iCalendar (RFC 5545) parser tailored to calendars published from Outlook / Exchange.
 *
 * Supports: line folding, escaped text, TZID (IANA and Windows names), all-day events,
 * DURATION, RRULE (DAILY/WEEKLY/MONTHLY/YEARLY with INTERVAL, COUNT, UNTIL, BYDAY,
 * BYMONTHDAY, BYMONTH, BYSETPOS, WKST), RDATE, EXDATE, RECURRENCE-ID overrides and
 * cancelled events.
 *
 * Pure PHP: no WordPress dependency, so it can be unit tested on its own.
 */

if ( ! defined( 'ABSPATH' ) && ! defined( 'KIVUN_BOARD_TESTING' ) ) {
	exit;
}

class Kivun_Board_ICS_Parser {

	/** Upper bound on generated periods per recurring event (protects against runaway rules). */
	const MAX_PERIODS = 20000;

	/** Windows time zone names used by Outlook, mapped to IANA names. */
	const WINDOWS_ZONES = array(
		'israel standard time'          => 'Asia/Jerusalem',
		'jerusalem standard time'       => 'Asia/Jerusalem',
		'utc'                           => 'UTC',
		'coordinated universal time'    => 'UTC',
		'gmt standard time'             => 'Europe/London',
		'greenwich standard time'       => 'Atlantic/Reykjavik',
		'w. europe standard time'       => 'Europe/Berlin',
		'central europe standard time'  => 'Europe/Budapest',
		'central european standard time' => 'Europe/Warsaw',
		'romance standard time'         => 'Europe/Paris',
		'e. europe standard time'       => 'Europe/Chisinau',
		'fle standard time'             => 'Europe/Kiev',
		'gtb standard time'             => 'Europe/Bucharest',
		'russian standard time'         => 'Europe/Moscow',
		'turkey standard time'          => 'Europe/Istanbul',
		'egypt standard time'           => 'Africa/Cairo',
		'jordan standard time'          => 'Asia/Amman',
		'middle east standard time'     => 'Asia/Beirut',
		'arabian standard time'         => 'Asia/Dubai',
		'india standard time'           => 'Asia/Kolkata',
		'china standard time'           => 'Asia/Shanghai',
		'tokyo standard time'           => 'Asia/Tokyo',
		'aus eastern standard time'     => 'Australia/Sydney',
		'eastern standard time'         => 'America/New_York',
		'central standard time'         => 'America/Chicago',
		'mountain standard time'        => 'America/Denver',
		'pacific standard time'         => 'America/Los_Angeles',
	);

	const WEEKDAYS = array( 'SU' => 0, 'MO' => 1, 'TU' => 2, 'WE' => 3, 'TH' => 4, 'FR' => 5, 'SA' => 6 );

	/** @var DateTimeZone */
	private $default_tz;

	/**
	 * @param DateTimeZone $default_tz Zone for floating times, all-day events and unknown TZIDs.
	 */
	public function __construct( DateTimeZone $default_tz ) {
		$this->default_tz = $default_tz;
	}

	/**
	 * Parses an iCalendar feed and returns the event instances overlapping [from, to).
	 *
	 * @param string $ics     Raw feed.
	 * @param int    $from_ts Window start (unix time).
	 * @param int    $to_ts   Window end (unix time).
	 * @return array[] Events: uid, title, location, categories, start, end, all_day, private.
	 */
	public function parse( $ics, $from_ts, $to_ts ) {
		$components = $this->read_events( (string) $ics );

		// Modified / cancelled single instances of a series, keyed by uid then instance key.
		$overrides = array();
		foreach ( $components as $props ) {
			if ( isset( $props['RECURRENCE-ID'] ) ) {
				$uid = $this->text( $props, 'UID' );
				$rid = $this->parse_date( $props['RECURRENCE-ID'][0] );
				if ( $rid ) {
					$overrides[ $uid ][ $this->instance_key( $rid[0], $rid[1] ) ] = true;
				}
			}
		}

		$events = array();
		foreach ( $components as $props ) {
			if ( 'CANCELLED' === strtoupper( $this->text( $props, 'STATUS' ) ) ) {
				continue;
			}
			$base = $this->base_event( $props );
			if ( ! $base ) {
				continue;
			}

			$uid = $base['uid'];
			if ( isset( $props['RRULE'] ) && ! isset( $props['RECURRENCE-ID'] ) ) {
				$starts = $this->expand( $props, $base['dtstart'], $base['all_day'], $to_ts );
				$skip   = isset( $overrides[ $uid ] ) ? $overrides[ $uid ] : array();
			} else {
				$starts = array( $base['dtstart'] );
				$skip   = array();
			}

			foreach ( $starts as $start ) {
				if ( isset( $skip[ $this->instance_key( $start, $base['all_day'] ) ] ) ) {
					continue;
				}
				$end = $this->add_duration( $start, $base['duration'], $base['all_day'] );
				$s   = $start->getTimestamp();
				$e   = max( $s, $end->getTimestamp() );
				if ( $s >= $to_ts || ( $e <= $from_ts && ! ( $e === $s && $s >= $from_ts ) ) ) {
					continue;
				}
				$events[] = array(
					'uid'        => $uid,
					'title'      => $base['title'],
					'location'   => $base['location'],
					'categories' => $base['categories'],
					'start'      => $s,
					'end'        => $e,
					'all_day'    => $base['all_day'],
					'private'    => $base['private'],
				);
			}
		}

		usort(
			$events,
			function ( $a, $b ) {
				return $a['start'] - $b['start'];
			}
		);
		return $events;
	}

	/* ------------------------------------------------------------------ */
	/* Reading                                                            */
	/* ------------------------------------------------------------------ */

	/**
	 * Splits the feed into VEVENT property maps: NAME => list of [params, value].
	 *
	 * @param string $ics Raw feed.
	 * @return array[]
	 */
	private function read_events( $ics ) {
		$ics   = preg_replace( '/^\xEF\xBB\xBF/', '', $ics );
		$ics   = preg_replace( "/\r\n[ \t]|\n[ \t]|\r[ \t]/", '', $ics ); // Unfold.
		$lines = preg_split( '/\r\n|\n|\r/', $ics );

		$events  = array();
		$current = null;
		$depth   = 0; // Nesting inside the current VEVENT (e.g. VALARM).

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$parsed = $this->parse_line( $line );
			if ( ! $parsed ) {
				continue;
			}
			list( $name, $params, $value ) = $parsed;

			if ( 'BEGIN' === $name ) {
				if ( null === $current && 'VEVENT' === strtoupper( $value ) ) {
					$current = array();
					$depth   = 0;
				} elseif ( null !== $current ) {
					$depth++;
				}
				continue;
			}
			if ( 'END' === $name ) {
				if ( null !== $current ) {
					if ( 0 === $depth ) {
						$events[] = $current;
						$current  = null;
					} else {
						$depth--;
					}
				}
				continue;
			}
			if ( null !== $current && 0 === $depth ) {
				$current[ $name ][] = array( $params, $value );
			}
		}
		return $events;
	}

	/**
	 * Splits "NAME;PARAM=a;PARAM2="b:c":value" respecting quoted parameter values.
	 *
	 * @param string $line Unfolded content line.
	 * @return array|null [NAME, params, value]
	 */
	private function parse_line( $line ) {
		$len      = strlen( $line );
		$in_quote = false;
		$colon    = -1;
		for ( $i = 0; $i < $len; $i++ ) {
			$c = $line[ $i ];
			if ( '"' === $c ) {
				$in_quote = ! $in_quote;
			} elseif ( ':' === $c && ! $in_quote ) {
				$colon = $i;
				break;
			}
		}
		if ( $colon < 1 ) {
			return null;
		}
		$head  = substr( $line, 0, $colon );
		$value = substr( $line, $colon + 1 );

		$parts    = preg_split( '/;(?=(?:[^"]*"[^"]*")*[^"]*$)/', $head );
		$name     = strtoupper( trim( array_shift( $parts ) ) );
		$params   = array();
		foreach ( $parts as $part ) {
			$eq = strpos( $part, '=' );
			if ( false === $eq ) {
				continue;
			}
			$params[ strtoupper( trim( substr( $part, 0, $eq ) ) ) ] = trim( substr( $part, $eq + 1 ), '"' );
		}
		return array( $name, $params, $value );
	}

	/**
	 * First value of a text property, unescaped.
	 *
	 * @param array  $props Property map.
	 * @param string $name  Property name.
	 * @return string
	 */
	private function text( $props, $name ) {
		if ( empty( $props[ $name ] ) ) {
			return '';
		}
		return trim( $this->unescape( $props[ $name ][0][1] ) );
	}

	/**
	 * @param string $value Escaped TEXT value.
	 * @return string
	 */
	private function unescape( $value ) {
		return strtr(
			$value,
			array(
				'\\n'  => "\n",
				'\\N'  => "\n",
				'\\,'  => ',',
				'\\;'  => ';',
				'\\\\' => '\\',
			)
		);
	}

	/**
	 * Common data of a VEVENT.
	 *
	 * @param array $props Property map.
	 * @return array|null
	 */
	private function base_event( $props ) {
		if ( empty( $props['DTSTART'] ) ) {
			return null;
		}
		$start = $this->parse_date( $props['DTSTART'][0] );
		if ( ! $start ) {
			return null;
		}
		list( $dtstart, $all_day ) = $start;

		// Duration is kept as [days, seconds] so all-day events stay whole days across DST changes.
		$duration = array( $all_day ? 1 : 0, 0 );
		if ( ! empty( $props['DTEND'] ) ) {
			$end = $this->parse_date( $props['DTEND'][0] );
			if ( $end ) {
				if ( $all_day ) {
					$days     = (int) $dtstart->setTime( 0, 0 )->diff( $end[0]->setTimezone( $dtstart->getTimezone() )->setTime( 0, 0 ) )->format( '%r%a' );
					$duration = array( max( 1, $days ), 0 );
				} else {
					$duration = array( 0, max( 0, $end[0]->getTimestamp() - $dtstart->getTimestamp() ) );
				}
			}
		} elseif ( ! empty( $props['DURATION'] ) ) {
			$duration = $this->parse_duration( $props['DURATION'][0][1] );
		}

		$categories = array();
		if ( ! empty( $props['CATEGORIES'] ) ) {
			foreach ( $props['CATEGORIES'] as $prop ) {
				foreach ( preg_split( '/(?<!\\\\),/', $prop[1] ) as $cat ) {
					$cat = trim( $this->unescape( $cat ) );
					if ( '' !== $cat ) {
						$categories[] = $cat;
					}
				}
			}
		}

		$class = strtoupper( $this->text( $props, 'CLASS' ) );
		$uid   = $this->text( $props, 'UID' );

		return array(
			'uid'        => '' !== $uid ? $uid : md5( serialize( $props['DTSTART'] ) . $this->text( $props, 'SUMMARY' ) ),
			'title'      => $this->text( $props, 'SUMMARY' ),
			'location'   => $this->text( $props, 'LOCATION' ),
			'categories' => $categories,
			'private'    => in_array( $class, array( 'PRIVATE', 'CONFIDENTIAL' ), true ),
			'dtstart'    => $dtstart,
			'all_day'    => $all_day,
			'duration'   => $duration,
		);
	}

	/**
	 * Parses a DATE / DATE-TIME property.
	 *
	 * @param array $prop [params, value].
	 * @return array|null [DateTimeImmutable, bool all_day]
	 */
	private function parse_date( $prop ) {
		list( $params, $value ) = $prop;
		$value = trim( explode( ',', $value )[0] );
		return $this->parse_date_value( $value, $params );
	}

	/**
	 * @param string $value  Date value.
	 * @param array  $params Property parameters.
	 * @return array|null [DateTimeImmutable, bool all_day]
	 */
	private function parse_date_value( $value, $params ) {
		$value = trim( $value );
		if ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $m ) ) {
			$dt = new DateTimeImmutable( 'now', $this->default_tz );
			return array( $dt->setDate( (int) $m[1], (int) $m[2], (int) $m[3] )->setTime( 0, 0, 0 ), true );
		}
		if ( ! preg_match( '/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})?(Z)?$/i', $value, $m ) ) {
			return null;
		}
		if ( ! empty( $m[7] ) ) {
			$tz = new DateTimeZone( 'UTC' );
		} elseif ( ! empty( $params['TZID'] ) ) {
			$tz = $this->resolve_tz( $params['TZID'] );
		} else {
			$tz = $this->default_tz;
		}
		$dt = new DateTimeImmutable( 'now', $tz );
		$dt = $dt->setDate( (int) $m[1], (int) $m[2], (int) $m[3] )->setTime( (int) $m[4], (int) $m[5], isset( $m[6] ) ? (int) $m[6] : 0 );
		return array( $dt, false );
	}

	/**
	 * Resolves a TZID (IANA name, Windows name or Outlook display name) to a zone.
	 *
	 * @param string $tzid TZID parameter value.
	 * @return DateTimeZone
	 */
	public function resolve_tz( $tzid ) {
		$tzid = trim( $tzid, " \"/" );
		$key  = strtolower( $tzid );
		if ( isset( self::WINDOWS_ZONES[ $key ] ) ) {
			return new DateTimeZone( self::WINDOWS_ZONES[ $key ] );
		}
		if ( false !== strpos( $key, 'jerusalem' ) || false !== strpos( $key, 'israel' ) ) {
			return new DateTimeZone( 'Asia/Jerusalem' );
		}
		if ( preg_match( '#^[A-Za-z_]+/[A-Za-z_/+\-]+$#', $tzid ) || 'UTC' === $tzid ) {
			try {
				return new DateTimeZone( $tzid );
			} catch ( Exception $e ) {
				// Fall through.
			}
		}
		return $this->default_tz;
	}

	/**
	 * Parses an ISO 8601 duration ("PT1H30M", "P1D", "P2W").
	 *
	 * @param string $value Duration.
	 * @return array [days, seconds]
	 */
	private function parse_duration( $value ) {
		if ( ! preg_match( '/^([+-])?P(?:(\d+)W)?(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+)S)?)?$/i', trim( $value ), $m ) ) {
			return array( 0, 0 );
		}
		$days    = ( isset( $m[2] ) ? (int) $m[2] * 7 : 0 ) + ( isset( $m[3] ) ? (int) $m[3] : 0 );
		$seconds = ( isset( $m[4] ) ? (int) $m[4] * 3600 : 0 ) + ( isset( $m[5] ) ? (int) $m[5] * 60 : 0 ) + ( isset( $m[6] ) ? (int) $m[6] : 0 );
		if ( '-' === $m[1] ) {
			return array( 0, 0 );
		}
		return array( $days, $seconds );
	}

	/**
	 * @param DateTimeImmutable $start    Instance start.
	 * @param array             $duration [days, seconds].
	 * @param bool              $all_day  All-day event.
	 * @return DateTimeImmutable
	 */
	private function add_duration( DateTimeImmutable $start, $duration, $all_day ) {
		$end = $start;
		if ( $duration[0] ) {
			$end = $end->modify( '+' . (int) $duration[0] . ' days' );
		}
		if ( $duration[1] ) {
			$end = $end->setTimestamp( $end->getTimestamp() + (int) $duration[1] );
		}
		return $end;
	}

	/**
	 * Key identifying one instance of a series (matches EXDATE / RECURRENCE-ID).
	 *
	 * @param DateTimeImmutable $dt      Instance start.
	 * @param bool              $all_day All-day.
	 * @return string
	 */
	private function instance_key( DateTimeImmutable $dt, $all_day ) {
		return $all_day ? $dt->format( 'Ymd' ) : (string) $dt->getTimestamp();
	}

	/* ------------------------------------------------------------------ */
	/* Recurrence                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Expands the recurrence set of a series up to $to_ts.
	 *
	 * @param array             $props   Property map.
	 * @param DateTimeImmutable $dtstart Series start.
	 * @param bool              $all_day All-day series.
	 * @param int               $to_ts   Stop after this time.
	 * @return DateTimeImmutable[]
	 */
	private function expand( $props, DateTimeImmutable $dtstart, $all_day, $to_ts ) {
		$rule = array();
		foreach ( explode( ';', strtoupper( $props['RRULE'][0][1] ) ) as $pair ) {
			$kv = explode( '=', $pair, 2 );
			if ( 2 === count( $kv ) ) {
				$rule[ trim( $kv[0] ) ] = trim( $kv[1] );
			}
		}

		$starts = $this->rrule_starts( $rule, $dtstart, $to_ts );

		// RDATE: extra instances.
		if ( ! empty( $props['RDATE'] ) ) {
			foreach ( $props['RDATE'] as $prop ) {
				if ( isset( $prop[0]['VALUE'] ) && 'PERIOD' === strtoupper( $prop[0]['VALUE'] ) ) {
					continue;
				}
				foreach ( explode( ',', $prop[1] ) as $value ) {
					$d = $this->parse_date_value( $value, $prop[0] );
					if ( $d ) {
						$starts[] = $all_day ? $d[0] : $d[0]->setTimezone( $dtstart->getTimezone() );
					}
				}
			}
		}

		// EXDATE: removed instances.
		$excluded = array();
		if ( ! empty( $props['EXDATE'] ) ) {
			foreach ( $props['EXDATE'] as $prop ) {
				foreach ( explode( ',', $prop[1] ) as $value ) {
					$d = $this->parse_date_value( $value, $prop[0] );
					if ( $d ) {
						$excluded[ $all_day || $d[1] ? $d[0]->format( 'Ymd' ) : (string) $d[0]->getTimestamp() ] = true;
					}
				}
			}
		}

		$out  = array();
		$seen = array();
		foreach ( $starts as $start ) {
			$key = $this->instance_key( $start, $all_day );
			if ( isset( $excluded[ $key ] ) || isset( $seen[ $key ] ) ) {
				continue;
			}
			// A date-only EXDATE on a timed series removes that whole day.
			if ( ! $all_day && isset( $excluded[ $start->format( 'Ymd' ) ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$out[]        = $start;
		}
		return $out;
	}

	/**
	 * Generates RRULE instance starts, in DTSTART's zone, in chronological order.
	 *
	 * @param array             $rule    Parsed RRULE.
	 * @param DateTimeImmutable $dtstart Series start.
	 * @param int               $to_ts   Stop after this time.
	 * @return DateTimeImmutable[]
	 */
	private function rrule_starts( $rule, DateTimeImmutable $dtstart, $to_ts ) {
		$freq     = isset( $rule['FREQ'] ) ? $rule['FREQ'] : '';
		$interval = isset( $rule['INTERVAL'] ) ? max( 1, (int) $rule['INTERVAL'] ) : 1;
		$count    = isset( $rule['COUNT'] ) ? max( 0, (int) $rule['COUNT'] ) : 0;
		$until_ts = null;
		if ( isset( $rule['UNTIL'] ) ) {
			$u = $this->parse_date_value( $rule['UNTIL'], array() );
			if ( $u ) {
				// A date-only UNTIL includes that whole day in the series' zone.
				$until_ts = $u[1]
					? $dtstart->setDate( (int) $u[0]->format( 'Y' ), (int) $u[0]->format( 'n' ), (int) $u[0]->format( 'j' ) )->setTime( 23, 59, 59 )->getTimestamp()
					: $u[0]->getTimestamp();
			}
		}

		$byday = array();
		if ( ! empty( $rule['BYDAY'] ) ) {
			foreach ( explode( ',', $rule['BYDAY'] ) as $token ) {
				if ( preg_match( '/^([+-]?\d{1,2})?(SU|MO|TU|WE|TH|FR|SA)$/', trim( $token ), $m ) ) {
					$byday[] = array( '' === $m[1] ? 0 : (int) $m[1], self::WEEKDAYS[ $m[2] ] );
				}
			}
		}
		$bymonthday = empty( $rule['BYMONTHDAY'] ) ? array() : array_map( 'intval', explode( ',', $rule['BYMONTHDAY'] ) );
		$bymonth    = empty( $rule['BYMONTH'] ) ? array() : array_map( 'intval', explode( ',', $rule['BYMONTH'] ) );
		$bysetpos   = empty( $rule['BYSETPOS'] ) ? array() : array_map( 'intval', explode( ',', $rule['BYSETPOS'] ) );
		$wkst       = isset( $rule['WKST'], self::WEEKDAYS[ $rule['WKST'] ] ) ? self::WEEKDAYS[ $rule['WKST'] ] : 1;

		$y = (int) $dtstart->format( 'Y' );
		$n = (int) $dtstart->format( 'n' );
		$d = (int) $dtstart->format( 'j' );
		$w = (int) $dtstart->format( 'w' );
		$h = (int) $dtstart->format( 'G' );
		$i = (int) $dtstart->format( 'i' );
		$s = (int) $dtstart->format( 's' );

		$start_ts  = $dtstart->getTimestamp();
		$base_date = $dtstart->setTime( 0, 0, 0 );
		$out       = array();
		$generated = 0;

		for ( $p = 0; $p < self::MAX_PERIODS; $p++ ) {
			$dates = array(); // List of [y, m, d].

			switch ( $freq ) {
				case 'DAILY':
					$day     = $base_date->modify( '+' . ( $p * $interval ) . ' days' );
					$dates[] = array( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), (int) $day->format( 'j' ) );
					break;

				case 'WEEKLY':
					$week_start = $base_date->modify( '-' . ( ( $w - $wkst + 7 ) % 7 ) . ' days' )->modify( '+' . ( $p * $interval * 7 ) . ' days' );
					$days       = $byday ? array_unique( array_map( function ( $b ) { return $b[1]; }, $byday ) ) : array( $w );
					$offsets    = array();
					foreach ( $days as $wd ) {
						$offsets[] = ( $wd - $wkst + 7 ) % 7;
					}
					sort( $offsets );
					foreach ( $offsets as $off ) {
						$day     = $week_start->modify( '+' . $off . ' days' );
						$dates[] = array( (int) $day->format( 'Y' ), (int) $day->format( 'n' ), (int) $day->format( 'j' ) );
					}
					break;

				case 'MONTHLY':
					$months = $n - 1 + $p * $interval;
					$yy     = $y + intdiv( $months, 12 );
					$mm     = $months % 12 + 1;
					$dates  = $this->month_dates( $yy, $mm, $d, $byday, $bymonthday, $bysetpos );
					break;

				case 'YEARLY':
					$yy = $y + $p * $interval;
					if ( ! $bymonth && ! $byday && ! $bymonthday ) {
						if ( checkdate( $n, $d, $yy ) ) {
							$dates[] = array( $yy, $n, $d );
						}
						break;
					}
					$all = array();
					foreach ( $bymonth ? $bymonth : array( $n ) as $mm ) {
						$all = array_merge( $all, $this->month_dates( $yy, $mm, $d, $byday, $bymonthday, array() ) );
					}
					$dates = $this->apply_setpos( $all, $bysetpos );
					break;

				default:
					return array( $dtstart );
			}

			foreach ( $dates as $date ) {
				list( $yy, $mm, $dd ) = $date;
				if ( $bymonth && 'YEARLY' !== $freq && ! in_array( $mm, $bymonth, true ) ) {
					continue;
				}
				if ( 'DAILY' === $freq ) {
					$wd = (int) gmdate( 'w', gmmktime( 0, 0, 0, $mm, $dd, $yy ) );
					if ( $byday && ! in_array( $wd, array_map( function ( $b ) { return $b[1]; }, $byday ), true ) ) {
						continue;
					}
					if ( $bymonthday && ! in_array( $dd, $bymonthday, true ) ) {
						continue;
					}
				}

				$instance = $dtstart->setDate( $yy, $mm, $dd )->setTime( $h, $i, $s );
				$ts       = $instance->getTimestamp();
				if ( $ts < $start_ts ) {
					continue;
				}
				if ( null !== $until_ts && $ts > $until_ts ) {
					return $out;
				}
				$generated++;
				if ( $count && $generated > $count ) {
					return $out;
				}
				if ( $ts >= $to_ts ) {
					return $out;
				}
				$out[] = $instance;
			}
		}
		return $out;
	}

	/**
	 * Candidate dates of one month for MONTHLY/YEARLY rules.
	 *
	 * @param int   $y          Year.
	 * @param int   $m          Month.
	 * @param int   $start_day  DTSTART day of month (default when no BY* parts).
	 * @param array $byday      [[ordinal, weekday], ...].
	 * @param int[] $bymonthday Days of month (negative counts from the end).
	 * @param int[] $bysetpos   Positions within the set.
	 * @return array[] [y, m, d] sorted.
	 */
	private function month_dates( $y, $m, $start_day, $byday, $bymonthday, $bysetpos ) {
		$dim  = (int) gmdate( 't', gmmktime( 0, 0, 0, $m, 1, $y ) );
		$days = null;

		if ( $bymonthday ) {
			$days = array();
			foreach ( $bymonthday as $md ) {
				$md = $md < 0 ? $dim + $md + 1 : $md;
				if ( $md >= 1 && $md <= $dim ) {
					$days[] = $md;
				}
			}
		}

		if ( $byday ) {
			$first_wd = (int) gmdate( 'w', gmmktime( 0, 0, 0, $m, 1, $y ) );
			$matched  = array();
			foreach ( $byday as $b ) {
				list( $ord, $wd ) = $b;
				$all = array();
				for ( $dd = 1 + ( $wd - $first_wd + 7 ) % 7; $dd <= $dim; $dd += 7 ) {
					$all[] = $dd;
				}
				if ( 0 === $ord ) {
					$matched = array_merge( $matched, $all );
				} elseif ( $ord > 0 && isset( $all[ $ord - 1 ] ) ) {
					$matched[] = $all[ $ord - 1 ];
				} elseif ( $ord < 0 && isset( $all[ count( $all ) + $ord ] ) ) {
					$matched[] = $all[ count( $all ) + $ord ];
				}
			}
			$days = null === $days ? $matched : array_intersect( $days, $matched );
		}

		if ( null === $days ) {
			$days = $start_day <= $dim ? array( $start_day ) : array();
		}

		$days = array_values( array_unique( $days ) );
		sort( $days );
		$dates = array();
		foreach ( $days as $dd ) {
			$dates[] = array( $y, $m, $dd );
		}
		return $this->apply_setpos( $dates, $bysetpos );
	}

	/**
	 * Applies BYSETPOS to a sorted candidate set.
	 *
	 * @param array $dates    Sorted dates.
	 * @param int[] $bysetpos Positions (1-based, negative from the end).
	 * @return array
	 */
	private function apply_setpos( $dates, $bysetpos ) {
		if ( ! $bysetpos || ! $dates ) {
			return $dates;
		}
		$out = array();
		$n   = count( $dates );
		foreach ( $bysetpos as $pos ) {
			$idx = $pos > 0 ? $pos - 1 : $n + $pos;
			if ( $idx >= 0 && $idx < $n ) {
				$out[ $idx ] = $dates[ $idx ];
			}
		}
		ksort( $out );
		return array_values( $out );
	}
}
