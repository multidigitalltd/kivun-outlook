<?php
/**
 * Plugin settings: defaults, access and parsing of the multi-line fields.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kivun_Board_Settings {

	const OPTION = 'kivun_board_settings';

	/**
	 * Default values for every setting.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			// Calendar source.
			'source'              => 'ics', // 'ics' | 'graph'.
			'ics_urls'            => '',
			'graph_tenant_id'     => '',
			'graph_client_id'     => '',
			'graph_client_secret' => '',
			'graph_mailboxes'     => '',
			'sync_interval'       => 10, // Minutes.
			'hide_private'        => 1,

			// Rooms (table rows). One per line: name | sub label | keywords.
			'rooms'               => "ימין משה\nמחשבים\nהר חוצבים | קומה 0\nגבעת רם | קומה 0\nנחלאות | קומה 0",
			'match_title'         => 1,

			// Board layout.
			'split_time'          => '14:00',
			'title'               => 'מה הכיוון היום?',
			'subtitle'            => 'לו״ז יומי',
			'col_morning'         => 'בוקר',
			'col_afternoon'       => 'אחר הצהריים',
			'show_date'           => 1,
			'logo_url'            => '', // Empty: the bundled Kivun logo.
			'accent_color'        => '#df2950', // Red: title, clock, live classes.
			'board_color'         => '#2e2e31', // Charcoal: title text and lower half.
			'arrow_color'         => '#5cc8f0', // Sky blue: lines and status dots.

			// Screen.
			'screen_slug'         => 'kivun-board',
			'refresh_seconds'     => 60,
			'rows_per_page'       => 5,
			'page_seconds'        => 15,
		);
	}

	/**
	 * All settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		// Values saved with the 1.x defaults follow the new 2.0 design.
		$legacy = array(
			'accent_color'  => '#e8334a',
			'board_color'   => '#3b3b3b',
			'arrow_color'   => '#29b6e8',
			'col_afternoon' => 'אחה"צ',
			'subtitle'      => 'לו"ז יומי',
		);
		foreach ( $legacy as $key => $old ) {
			if ( isset( $saved[ $key ] ) && $saved[ $key ] === $old ) {
				unset( $saved[ $key ] );
			}
		}
		return array_merge( self::defaults(), $saved );
	}

	/**
	 * A single setting.
	 *
	 * @param string $key Setting key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Splits a textarea value into trimmed, non-empty, non-comment lines.
	 *
	 * @param string $text Raw text.
	 * @return string[]
	 */
	public static function lines( $text ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $text ) as $line ) {
			$line = trim( $line );
			if ( '' === $line || 0 === strpos( $line, '#' ) ) {
				continue;
			}
			$out[] = $line;
		}
		return $out;
	}

	/**
	 * Rooms configured as table rows, in display order.
	 *
	 * Line format: "name | sub label | keyword1, keyword2". Only the name is required.
	 *
	 * @return array[] Each: key, name, sub, keywords (normalized strings).
	 */
	public static function rooms() {
		$rooms = array();
		foreach ( self::lines( self::get( 'rooms' ) ) as $i => $line ) {
			$parts = array_map( 'trim', explode( '|', $line ) );
			$name  = $parts[0];
			if ( '' === $name ) {
				continue;
			}
			$sub      = isset( $parts[1] ) ? $parts[1] : '';
			$keywords = array( $name );
			if ( isset( $parts[2] ) && '' !== $parts[2] ) {
				$keywords = array_merge( $keywords, array_map( 'trim', explode( ',', $parts[2] ) ) );
			}
			$keywords = array_values( array_filter( array_unique( array_map( array( __CLASS__, 'normalize' ), $keywords ) ) ) );

			$rooms[] = array(
				'key'      => 'room-' . $i,
				'name'     => $name,
				'sub'      => $sub,
				'keywords' => $keywords,
			);
		}
		return $rooms;
	}

	/**
	 * Calendar sources. Line format: "address" or "address | room name".
	 *
	 * @param string $field 'ics_urls' or 'graph_mailboxes'.
	 * @return array[] Each: address, room (room name or '').
	 */
	public static function sources( $field ) {
		$out = array();
		foreach ( self::lines( self::get( $field ) ) as $line ) {
			$room = '';
			$pos  = strrpos( $line, '|' );
			if ( false !== $pos ) {
				$room = trim( substr( $line, $pos + 1 ) );
				$line = trim( substr( $line, 0, $pos ) );
			}
			if ( '' !== $line ) {
				$out[] = array(
					'address' => $line,
					'room'    => $room,
				);
			}
		}
		return $out;
	}

	/**
	 * Normalizes text for keyword matching: lower case, unified quotes/dashes, single spaces.
	 *
	 * @param string $text Text.
	 * @return string
	 */
	public static function normalize( $text ) {
		$text = (string) $text;
		$text = str_replace( array( '״', '"', '“', '”', "'", '׳', '`', '–', '—', '-', '(', ')', ',', '.' ), ' ', $text );
		$text = function_exists( 'mb_strtolower' ) ? mb_strtolower( $text, 'UTF-8' ) : strtolower( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		return trim( $text );
	}

	/**
	 * Morning/afternoon split as minutes after midnight.
	 *
	 * @return int
	 */
	public static function split_minutes() {
		$value = (string) self::get( 'split_time' );
		if ( preg_match( '/^(\d{1,2}):(\d{2})$/', $value, $m ) ) {
			return min( 23, (int) $m[1] ) * 60 + min( 59, (int) $m[2] );
		}
		return 14 * 60;
	}
}
