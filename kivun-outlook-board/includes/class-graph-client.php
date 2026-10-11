<?php
/**
 * Microsoft Graph client (app-only / client credentials) for reading Outlook calendars.
 *
 * Requires an Entra ID (Azure AD) app registration with the application permission
 * Calendars.Read (admin consent). Graph expands recurring events itself via calendarView.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kivun_Board_Graph_Client {

	const TOKEN_TRANSIENT = 'kivun_board_graph_token';

	/** @var string */
	private $tenant;
	/** @var string */
	private $client_id;
	/** @var string */
	private $secret;

	/**
	 * @param string $tenant    Directory (tenant) ID or domain.
	 * @param string $client_id Application (client) ID.
	 * @param string $secret    Client secret value.
	 */
	public function __construct( $tenant, $client_id, $secret ) {
		$this->tenant    = trim( $tenant );
		$this->client_id = trim( $client_id );
		$this->secret    = trim( $secret );
	}

	/**
	 * Events of a mailbox overlapping [from, to).
	 *
	 * @param string $mailbox User / room / shared mailbox address.
	 * @param int    $from_ts Window start.
	 * @param int    $to_ts   Window end.
	 * @return array[]|WP_Error Normalized events.
	 */
	public function events( $mailbox, $from_ts, $to_ts ) {
		$token = $this->token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}

		$url = add_query_arg(
			array(
				'startDateTime' => gmdate( 'Y-m-d\TH:i:s\Z', $from_ts ),
				'endDateTime'   => gmdate( 'Y-m-d\TH:i:s\Z', $to_ts ),
				'$select'       => 'id,subject,start,end,location,categories,isAllDay,sensitivity,isCancelled,showAs',
				'$top'          => 200,
			),
			'https://graph.microsoft.com/v1.0/users/' . rawurlencode( trim( $mailbox ) ) . '/calendarView'
		);

		$events = array();
		$pages  = 0;
		while ( $url && $pages < 25 ) {
			$pages++;
			$response = wp_remote_get(
				$url,
				array(
					'timeout' => 20,
					'headers' => array(
						'Authorization' => 'Bearer ' . $token,
						'Prefer'        => 'outlook.timezone="UTC"',
						'Accept'        => 'application/json',
					),
				)
			);
			$data = $this->decode( $response );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( isset( $data['value'] ) ? $data['value'] : array() as $item ) {
				if ( ! empty( $item['isCancelled'] ) ) {
					continue;
				}
				$all_day = ! empty( $item['isAllDay'] );
				$start   = $this->graph_time( $item['start'], $all_day );
				$end     = $this->graph_time( $item['end'], $all_day );
				if ( null === $start ) {
					continue;
				}
				$events[] = array(
					'uid'        => isset( $item['id'] ) ? $item['id'] : md5( wp_json_encode( $item ) ),
					'title'      => isset( $item['subject'] ) ? (string) $item['subject'] : '',
					'location'   => isset( $item['location']['displayName'] ) ? (string) $item['location']['displayName'] : '',
					'categories' => isset( $item['categories'] ) ? (array) $item['categories'] : array(),
					'start'      => $start,
					'end'        => null === $end ? $start : max( $start, $end ),
					'all_day'    => $all_day,
					'private'    => isset( $item['sensitivity'] ) && in_array( $item['sensitivity'], array( 'private', 'confidential' ), true ),
				);
			}
			$url = isset( $data['@odata.nextLink'] ) ? $data['@odata.nextLink'] : '';
		}
		return $events;
	}

	/**
	 * Converts a Graph dateTimeTimeZone (requested in UTC) to unix time.
	 * All-day events are anchored to midnight in the site's zone.
	 *
	 * @param array $value   { dateTime, timeZone }.
	 * @param bool  $all_day All-day event.
	 * @return int|null
	 */
	private function graph_time( $value, $all_day ) {
		if ( empty( $value['dateTime'] ) ) {
			return null;
		}
		$raw = substr( $value['dateTime'], 0, 19 );
		try {
			if ( $all_day ) {
				$dt = new DateTimeImmutable( substr( $raw, 0, 10 ) . ' 00:00:00', wp_timezone() );
			} else {
				$dt = new DateTimeImmutable( $raw, new DateTimeZone( 'UTC' ) );
			}
		} catch ( Exception $e ) {
			return null;
		}
		return $dt->getTimestamp();
	}

	/**
	 * Cached app-only access token.
	 *
	 * @return string|WP_Error
	 */
	private function token() {
		$cache_key = self::TOKEN_TRANSIENT . '_' . md5( $this->tenant . '|' . $this->client_id . '|' . $this->secret );
		$cached    = get_transient( $cache_key );
		if ( $cached ) {
			return $cached;
		}
		if ( '' === $this->tenant || '' === $this->client_id || '' === $this->secret ) {
			return new WP_Error( 'kivun_graph_config', 'חסרים פרטי חיבור ל-Microsoft Graph (Tenant ID / Client ID / Client Secret).' );
		}

		$response = wp_remote_post(
			'https://login.microsoftonline.com/' . rawurlencode( $this->tenant ) . '/oauth2/v2.0/token',
			array(
				'timeout' => 20,
				'body'    => array(
					'client_id'     => $this->client_id,
					'client_secret' => $this->secret,
					'scope'         => 'https://graph.microsoft.com/.default',
					'grant_type'    => 'client_credentials',
				),
			)
		);
		$data = $this->decode( $response );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['access_token'] ) ) {
			return new WP_Error( 'kivun_graph_token', 'לא התקבל טוקן מ-Microsoft.' );
		}
		$ttl = isset( $data['expires_in'] ) ? max( 60, (int) $data['expires_in'] - 300 ) : 3000;
		set_transient( $cache_key, $data['access_token'], $ttl );
		return $data['access_token'];
	}

	/**
	 * Decodes a JSON response, turning HTTP / Graph errors into WP_Error.
	 *
	 * @param array|WP_Error $response HTTP response.
	 * @return array|WP_Error
	 */
	private function decode( $response ) {
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code < 200 || $code >= 300 ) {
			$message = 'HTTP ' . $code;
			if ( isset( $data['error']['message'] ) ) {
				$message .= ': ' . $data['error']['message'];
			} elseif ( isset( $data['error_description'] ) ) {
				$message .= ': ' . $data['error_description'];
			}
			return new WP_Error( 'kivun_graph_http', $message );
		}
		return is_array( $data ) ? $data : array();
	}
}
