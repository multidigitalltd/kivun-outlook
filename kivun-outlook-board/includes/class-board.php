<?php
/**
 * Public board: data model, HTML rendering, shortcode, REST endpoint and full-screen screen page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kivun_Board {

	const QUERY_VAR = 'kivun_board_screen';
	const REST_NS   = 'kivun-board/v1';

	const DAY_NAMES = array( 'ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי', 'שישי', 'שבת' );

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rules' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush_rewrites' ), 99 );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render_screen' ), 1 );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_assets' ) );
		add_shortcode( 'kivun_board', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'kivun_week', array( __CLASS__, 'week_shortcode' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Routing                                                            */
	/* ------------------------------------------------------------------ */

	public static function add_rewrite_rules() {
		$slug = self::slug();
		add_rewrite_rule( '^' . preg_quote( $slug, '#' ) . '/?$', 'index.php?' . self::QUERY_VAR . '=1', 'top' );
	}

	public static function maybe_flush_rewrites() {
		if ( get_option( 'kivun_board_flush_rewrites' ) ) {
			delete_option( 'kivun_board_flush_rewrites' );
			flush_rewrite_rules();
		}
	}

	/**
	 * @param string[] $vars Public query vars.
	 * @return string[]
	 */
	public static function query_vars( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/**
	 * @return string
	 */
	public static function slug() {
		$slug = sanitize_title( (string) Kivun_Board_Settings::get( 'screen_slug' ) );
		return '' !== $slug ? $slug : 'kivun-board';
	}

	/**
	 * Public URL of the full-screen board.
	 *
	 * @return string
	 */
	public static function screen_url() {
		if ( get_option( 'permalink_structure' ) ) {
			return home_url( '/' . self::slug() . '/' );
		}
		return add_query_arg( self::QUERY_VAR, '1', home_url( '/' ) );
	}

	/**
	 * Outputs the stand-alone screen page (no theme) and exits.
	 */
	public static function maybe_render_screen() {
		if ( ! get_query_var( self::QUERY_VAR ) && empty( $_GET[ self::QUERY_VAR ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		Kivun_Board_Sync::maybe_run_stale();
		nocache_headers();
		status_header( 200 );
		header( 'Content-Type: text/html; charset=utf-8' );

		$date = self::requested_date();
		$html = self::render( $date, 'screen' );
		include KIVUN_BOARD_DIR . 'templates/screen.php';
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* REST                                                               */
	/* ------------------------------------------------------------------ */

	public static function register_rest() {
		register_rest_route(
			self::REST_NS,
			'/board',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_board' ),
				'args'                => array(
					'date' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
		register_rest_route(
			self::REST_NS,
			'/week',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => array( __CLASS__, 'rest_week' ),
				'args'                => array(
					'start'    => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'days'     => array(
						'type'     => 'integer',
						'required' => false,
						'default'  => 7,
					),
					'saturday' => array(
						'type'              => 'string',
						'required'          => false,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_week( $request ) {
		Kivun_Board_Sync::maybe_run_stale();
		$model = self::week_model( self::week_start( $request->get_param( 'start' ) ), $request->get_param( 'days' ), self::is_yes( $request->get_param( 'saturday' ) ) );

		$response = new WP_REST_Response(
			array(
				'hash' => self::hash( $model ),
				'now'  => self::now_ms(),
				'html' => self::render_week_body( $model ),
			)
		);
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0' );
		return $response;
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_board( $request ) {
		Kivun_Board_Sync::maybe_run_stale();
		$date  = self::parse_date( $request->get_param( 'date' ) );
		$model = self::model( $date );

		$response = new WP_REST_Response(
			array(
				'date' => $date->format( 'Y-m-d' ),
				'hash' => self::hash( $model ),
				'now'  => self::now_ms(),
				'html' => self::render_inner( $model ),
			)
		);
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0' );
		return $response;
	}

	/* ------------------------------------------------------------------ */
	/* Shortcode & assets                                                 */
	/* ------------------------------------------------------------------ */

	public static function register_assets() {
		wp_register_style( 'kivun-board-font', 'https://fonts.googleapis.com/css2?family=Rubik:wght@500;700;800;900&family=Heebo:wght@400;500;700;800&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceVersion.MissingVersion
		wp_register_style( 'kivun-board', KIVUN_BOARD_URL . 'assets/board.css', array( 'kivun-board-font' ), KIVUN_BOARD_VERSION );
		wp_register_script( 'kivun-board', KIVUN_BOARD_URL . 'assets/board.js', array(), KIVUN_BOARD_VERSION, true );
	}

	/**
	 * [kivun_board] – embeds the board in any page.
	 *
	 * @param array $atts { date: YYYY-MM-DD (optional) }.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'date' => '' ), $atts, 'kivun_board' );
		self::register_assets();
		wp_enqueue_style( 'kivun-board' );
		wp_enqueue_script( 'kivun-board' );
		$date = '' !== $atts['date'] ? self::parse_date( $atts['date'] ) : self::requested_date();
		return self::render( $date, 'embed' );
	}

	/**
	 * [kivun_week] – the coming week: a row per day, a column per room.
	 *
	 * @param array $atts {
	 *     start:    first day – any date parse_date() accepts, or "ראשון" / "sunday" for this week's Sunday. Default: today.
	 *     days:     number of calendar days (1–14). Default: 7.
	 *     saturday: "yes" to include Saturday. Default: hidden.
	 *     title:    heading; its last word is shown in red. Default: "מה הכיוון השבוע?".
	 *     subtitle: line under the heading. Default: "לו״ז שבועי".
	 * }
	 * @return string
	 */
	public static function week_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'start'    => '',
				'days'     => 7,
				'saturday' => '',
				'title'    => 'מה הכיוון השבוע?',
				'subtitle' => 'לו״ז שבועי',
			),
			$atts,
			'kivun_week'
		);
		self::register_assets();
		wp_enqueue_style( 'kivun-board' );
		wp_enqueue_script( 'kivun-board' );

		$s        = Kivun_Board_Settings::all();
		$saturday = self::is_yes( $atts['saturday'] );
		$model    = self::week_model( self::week_start( $atts['start'] ), $atts['days'], $saturday );

		$endpoint = add_query_arg(
			array_filter(
				array(
					'start'    => rawurlencode( trim( (string) $atts['start'] ) ),
					'days'     => (int) $atts['days'],
					'saturday' => $saturday ? '1' : '',
				)
			),
			rest_url( self::REST_NS . '/week' )
		);

		$style = sprintf(
			'--kb-red:%s;--kb-dark:%s;--kb-sky:%s;',
			esc_attr( self::color( $s['accent_color'], '#df2950' ) ),
			esc_attr( self::color( $s['board_color'], '#2e2e31' ) ),
			esc_attr( self::color( $s['arrow_color'], '#5cc8f0' ) )
		);
		$logo  = $s['logo_url'] ? $s['logo_url'] : KIVUN_BOARD_URL . 'assets/kivun-logo.png?ver=' . KIVUN_BOARD_VERSION;

		$subtitle = trim( (string) $atts['subtitle'] );
		$header   = '<header class="kb-week-header"><div class="kb-week-heading"><div class="kb-week-title">' . self::accent_title( $atts['title'] ) . '</div>';
		if ( '' !== $subtitle ) {
			$header .= '<div class="kb-week-subtitle">' . esc_html( $subtitle ) . '</div>';
		}
		$header .= '</div><img class="kb-week-logo" src="' . esc_url( $logo ) . '" alt="כיוון"></header>';

		return sprintf(
			'<div class="kb-week" dir="rtl" lang="he" style="%1$s" data-endpoint="%2$s" data-refresh="%3$d" data-hash="%4$s" data-now="%5$s"><div class="kb-week-inner">%6$s<div class="kb-week-body">%7$s</div></div></div>',
			$style,
			esc_url( $endpoint ),
			max( 15, (int) $s['refresh_seconds'] ),
			esc_attr( self::hash( $model ) ),
			esc_attr( self::now_ms() ),
			$header,
			self::render_week_body( $model )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Model                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Date for previewing another day: "?13.10.2026", "?13/10", "?2026-10-13", "?מחר"
	 * or "?kb_date=…". Defaults to today.
	 *
	 * @return DateTimeImmutable
	 */
	private static function requested_date() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['kb_date'] ) ) {
			return self::parse_date( sanitize_text_field( wp_unslash( $_GET['kb_date'] ) ) );
		}
		// The bare "?13.10.2026" form has no key, and PHP mangles dots in $_GET keys, so read the raw query string.
		$query = isset( $_SERVER['QUERY_STRING'] ) ? wp_unslash( $_SERVER['QUERY_STRING'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		// phpcs:enable
		foreach ( explode( '&', (string) $query ) as $part ) {
			if ( '' !== $part && false === strpos( $part, '=' ) ) {
				return self::parse_date( sanitize_text_field( rawurldecode( $part ) ) );
			}
		}
		return self::parse_date( null );
	}

	/**
	 * Parses a date written in any common way (day before month, as is usual in Israel).
	 *
	 * Accepted: 2026-10-13, 13.10.2026, 13/10/2026, 13-10-26, 13.10, 13/10 (current year),
	 * היום / מחר / מחרתיים / אתמול, today / tomorrow / yesterday.
	 *
	 * @param string|null $value Date text.
	 * @return DateTimeImmutable Midnight of that date (or today if not understood) in the site zone.
	 */
	public static function parse_date( $value ) {
		$tz    = wp_timezone();
		$today = new DateTimeImmutable( 'today', $tz );
		$value = trim( (string) $value );

		$relative = array(
			'היום'      => 0,
			'today'     => 0,
			'מחר'       => 1,
			'tomorrow'  => 1,
			'מחרתיים'   => 2,
			'אתמול'     => -1,
			'yesterday' => -1,
		);
		$key = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value );
		if ( isset( $relative[ $key ] ) ) {
			return $today->modify( sprintf( '%+d days', $relative[ $key ] ) );
		}

		$y = null;
		$m = null;
		$d = null;
		if ( preg_match( '/^(\d{4})\D(\d{1,2})\D(\d{1,2})$/', $value, $p ) ) {
			list( , $y, $m, $d ) = $p;
		} elseif ( preg_match( '/^(\d{1,2})\D(\d{1,2})(?:\D(\d{2}|\d{4}))?$/', $value, $p ) ) {
			$d = $p[1];
			$m = $p[2];
			$y = isset( $p[3] ) ? $p[3] : $today->format( 'Y' );
			if ( strlen( $y ) === 2 ) {
				$y = '20' . $y;
			}
		} elseif ( preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $value, $p ) ) {
			list( , $y, $m, $d ) = $p;
		}

		if ( null !== $y && checkdate( (int) $m, (int) $d, (int) $y ) ) {
			return $today->setDate( (int) $y, (int) $m, (int) $d );
		}
		return $today;
	}

	/**
	 * Board data for one day.
	 *
	 * @param DateTimeImmutable $day Midnight of the day.
	 * @return array
	 */
	public static function model( DateTimeImmutable $day ) {
		$day_start = $day->getTimestamp();
		$day_end   = $day->modify( '+1 day' )->getTimestamp();
		$split     = Kivun_Board_Settings::split_minutes();
		$data      = Kivun_Board_Sync::data();

		$rows = array();
		foreach ( Kivun_Board_Settings::rooms() as $room ) {
			$rows[ $room['key'] ] = array(
				'name'      => $room['name'],
				'sub'       => $room['sub'],
				'morning'   => array(),
				'afternoon' => array(),
			);
		}

		foreach ( $data['events'] as $event ) {
			if ( ! isset( $rows[ $event['room'] ] ) ) {
				continue;
			}
			$overlaps = $event['start'] < $day_end && ( $event['end'] > $day_start || ( $event['end'] === $event['start'] && $event['start'] >= $day_start ) );
			if ( ! $overlaps ) {
				continue;
			}

			$start_on_day = max( $event['start'], $day_start );
			$minutes      = (int) wp_date( 'G', $start_on_day ) * 60 + (int) wp_date( 'i', $start_on_day );

			if ( ! empty( $event['all_day'] ) ) {
				$time = 'כל היום';
			} else {
				$time = wp_date( 'H:i', $event['start'] ) . ' – ' . wp_date( 'H:i', $event['end'] );
			}

			$slot                              = $minutes < $split ? 'morning' : 'afternoon';
			$rows[ $event['room'] ][ $slot ][] = array(
				'title' => $event['title'],
				'time'  => $time,
				'start' => (int) $event['start'],
				'end'   => (int) $event['end'],
			);
		}

		$w = (int) $day->format( 'w' );
		return array(
			'date'       => $day->format( 'Y-m-d' ),
			'day_name'   => self::DAY_NAMES[ $w ],
			'date_label' => $day->format( 'd.m.Y' ),
			'rows'       => array_values( $rows ),
		);
	}


	/**
	 * First day of the week view. "ראשון" / "sunday" is this week's Sunday
	 * (on Saturday, the next one); anything else goes through parse_date().
	 *
	 * @param string|null $value Start text.
	 * @return DateTimeImmutable
	 */
	public static function week_start( $value ) {
		$value = trim( (string) $value );
		if ( in_array( function_exists( 'mb_strtolower' ) ? mb_strtolower( $value, 'UTF-8' ) : strtolower( $value ), array( 'ראשון', 'יום ראשון', 'sunday' ), true ) ) {
			$today = new DateTimeImmutable( 'today', wp_timezone() );
			$w     = (int) $today->format( 'w' );
			return 6 === $w ? $today->modify( '+1 day' ) : $today->modify( '-' . $w . ' days' );
		}
		return self::parse_date( $value );
	}

	/**
	 * Board data for several days: one row per day, one cell per room, events in time order.
	 *
	 * @param DateTimeImmutable $start    First day.
	 * @param int               $days     Calendar days to cover (1–14).
	 * @param bool              $saturday Include Saturday.
	 * @return array
	 */
	public static function week_model( DateTimeImmutable $start, $days, $saturday ) {
		$days  = min( 14, max( 1, (int) $days ) );
		$today = ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );
		$out   = array();
		$first = null;
		$last  = null;

		for ( $i = 0; $i < $days; $i++ ) {
			$day = $start->modify( '+' . $i . ' days' );
			if ( ! $saturday && 6 === (int) $day->format( 'w' ) ) {
				continue;
			}
			$first = $first ? $first : $day;
			$last  = $day;
			$model = self::model( $day );
			$cells = array();
			foreach ( $model['rows'] as $row ) {
				$cells[] = array_merge( $row['morning'], $row['afternoon'] );
			}
			$out[] = array(
				'date'       => $model['date'],
				'day_name'   => $model['day_name'],
				'date_label' => $day->format( 'd.m' ),
				'today'      => $model['date'] === $today,
				'cells'      => $cells,
			);
		}

		$rooms = array();
		foreach ( Kivun_Board_Settings::rooms() as $room ) {
			$rooms[] = array(
				'name' => $room['name'],
				'sub'  => $room['sub'],
			);
		}

		return array(
			'range' => $first ? $first->format( 'd.m' ) . ' – ' . $last->format( 'd.m.Y' ) : '',
			'rooms' => $rooms,
			'days'  => $out,
		);
	}

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	/** Minutes before a class starts when it is flagged "soon". */
	const SOON_SECONDS = 3600;

	/**
	 * Complete board widget (wrapper + inner markup).
	 *
	 * @param DateTimeImmutable $day  Day to show.
	 * @param string            $mode 'screen' or 'embed'.
	 * @return string
	 */
	public static function render( DateTimeImmutable $day, $mode ) {
		$s        = Kivun_Board_Settings::all();
		$model    = self::model( $day );
		$is_today = $day->format( 'Y-m-d' ) === ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );

		$style = sprintf(
			'--kb-red:%s;--kb-dark:%s;--kb-sky:%s;',
			esc_attr( self::color( $s['accent_color'], '#df2950' ) ),
			esc_attr( self::color( $s['board_color'], '#2e2e31' ) ),
			esc_attr( self::color( $s['arrow_color'], '#5cc8f0' ) )
		);

		$endpoint = rest_url( self::REST_NS . '/board' );
		if ( ! $is_today ) {
			$endpoint = add_query_arg( 'date', $day->format( 'Y-m-d' ), $endpoint );
		}

		return sprintf(
			'<div class="kb-board kb-mode-%1$s" dir="rtl" lang="he" style="%2$s" data-mode="%1$s" data-endpoint="%3$s" data-refresh="%4$d" data-rows-per-page="%5$d" data-page-seconds="%6$d" data-hash="%7$s" data-tz="%9$s" data-now="%10$s"><div class="kb-scaler"><div class="kb-stage">%8$s</div></div></div>',
			esc_attr( $mode ),
			$style,
			esc_url( $endpoint ),
			max( 15, (int) $s['refresh_seconds'] ),
			max( 0, (int) $s['rows_per_page'] ),
			max( 5, (int) $s['page_seconds'] ),
			esc_attr( self::hash( $model ) ),
			self::render_inner( $model ),
			esc_attr( wp_timezone_string() ),
			esc_attr( self::now_ms() )
		);
	}

	/**
	 * Server time in milliseconds. The screen corrects its own clock with it,
	 * so the time shown is right even when the TV's clock is off.
	 *
	 * @return string
	 */
	private static function now_ms() {
		return (string) (int) round( microtime( true ) * 1000 );
	}

	/**
	 * Fingerprint of what the board shows; the screen swaps its markup only when it changes.
	 * (The markup itself also contains the clock and live statuses, which change every minute.)
	 *
	 * @param array $model Model.
	 * @return string
	 */
	private static function hash( $model ) {
		return md5( wp_json_encode( $model ) . wp_json_encode( Kivun_Board_Settings::all() ) . KIVUN_BOARD_VERSION );
	}

	/**
	 * Inner markup of the stage (what the screen refreshes).
	 *
	 * @param array $model Model from self::model().
	 * @return string
	 */
	public static function render_inner( $model ) {
		$s   = Kivun_Board_Settings::all();
		$now = time();

		$title = self::accent_title( $s['title'] );

		$subline = array();
		if ( '' !== trim( (string) $s['subtitle'] ) ) {
			$subline[] = esc_html( $s['subtitle'] );
		}
		$subline[] = esc_html( 'יום ' . $model['day_name'] );
		if ( ! empty( $s['show_date'] ) ) {
			$subline[] = esc_html( $model['date_label'] );
			$subline[] = '<span class="kb-hebdate" data-date="' . esc_attr( $model['date'] ) . '">' . esc_html( self::hebrew_date( $model['date'] ) ) . '</span>';
		}

		$logo = $s['logo_url'] ? $s['logo_url'] : KIVUN_BOARD_URL . 'assets/kivun-logo.png?ver=' . KIVUN_BOARD_VERSION;
		$cols = count( $model['rows'] );
		if ( (int) $s['rows_per_page'] > 0 ) {
			$cols = min( $cols, (int) $s['rows_per_page'] );
		}

		$parts = array(
			'morning'   => (string) $s['col_morning'],
			'afternoon' => (string) $s['col_afternoon'],
		);

		ob_start();
		?>
		<header class="kb-header">
			<div class="kb-heading">
				<div class="kb-title"><?php echo $title; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></div>
				<div class="kb-subline"><?php echo implode( ' · ', $subline ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above. ?></div>
			</div>
			<div class="kb-brand">
				<div class="kb-clock">
					<span class="kb-clock-icon"><svg width="35" height="35" viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="2.6"></circle><path d="M12 7 V12 L15.5 14" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"></path></svg></span>
					<span class="kb-clock-time"><span class="kb-clock-hm"><?php echo esc_html( wp_date( 'H:i', $now ) ); ?></span><span class="kb-clock-sec"><?php echo esc_html( wp_date( ':s', $now ) ); ?></span></span>
				</div>
				<img class="kb-logo" src="<?php echo esc_url( $logo ); ?>" alt="כיוון">
			</div>
		</header>
		<div class="kb-grid" style="--kb-cols:<?php echo (int) max( 1, $cols ); ?>">
			<?php foreach ( $model['rows'] as $row ) : ?>
				<section class="kb-card">
					<div class="kb-room">
						<div class="kb-room-name"><?php echo esc_html( $row['name'] ); ?></div>
						<div class="kb-room-floor"><?php echo '' !== $row['sub'] ? esc_html( $row['sub'] ) : '&nbsp;'; ?></div>
					</div>
					<?php
					foreach ( $parts as $slot => $label ) {
						echo self::render_slot( $row[ $slot ], $label, $now ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
					}
					?>
				</section>
			<?php endforeach; ?>
		</div>
		<div class="kb-pages" aria-hidden="true"></div>
		<?php
		return trim( preg_replace( '/>\s+</', '><', ob_get_clean() ) );
	}

	/**
	 * One morning / afternoon slot of a room card.
	 *
	 * @param array[] $items Events of the slot: title, time, start, end.
	 * @param string  $label Slot label ("בוקר" / "אחר הצהריים").
	 * @param int     $now   Current unix time (initial state; board.js keeps it live).
	 * @return string
	 */
	private static function render_slot( $items, $label, $now ) {
		$state  = self::slot_state( $items, $now );
		$badges = array(
			'live'  => 'עכשיו',
			'soon'  => 'בקרוב',
			'ended' => 'הסתיים',
		);
		$badge  = isset( $badges[ $state ] ) ? $badges[ $state ] : '';

		$html  = '<div class="kb-slot kb-slot--' . esc_attr( $state ) . ( count( $items ) > 1 ? ' kb-slot--multi' : '' ) . '">';
		$html .= '<div class="kb-slot-top"><div class="kb-slot-part"><span class="kb-dot"></span><span class="kb-part">' . esc_html( $label ) . '</span></div>';
		$html .= '<span class="kb-badge">' . esc_html( $badge ) . '</span></div>';

		if ( ! $items ) {
			$html .= '<div class="kb-empty">פנוי</div>';
		}
		foreach ( $items as $item ) {
			$ended = $now >= $item['end'] && $item['end'] > $item['start'];
			$html .= sprintf(
				'<div class="kb-event%1$s" data-start="%2$d" data-end="%3$d"><div class="kb-event-title">%4$s</div><div class="kb-event-time" dir="ltr">%5$s</div></div>',
				$ended ? ' kb-event--ended' : '',
				(int) $item['start'],
				(int) $item['end'],
				esc_html( $item['title'] ),
				esc_html( $item['time'] )
			);
		}
		return $html . '</div>';
	}

	/**
	 * Week view markup below the heading (what the page refreshes): date range and the day × room table.
	 *
	 * @param array $model Model from self::week_model().
	 * @return string
	 */
	public static function render_week_body( $model ) {
		$now  = time();
		$html = '<div class="kb-week-range">' . esc_html( $model['range'] ) . '</div>';
		$html .= '<div class="kb-week-scroll"><div class="kb-week-table" style="--kb-rooms:' . (int) max( 1, count( $model['rooms'] ) ) . '">';
		$html .= '<div class="kb-week-corner"></div>';
		foreach ( $model['rooms'] as $room ) {
			$html .= '<div class="kb-week-roomhead"><div class="kb-week-roomname">' . esc_html( $room['name'] ) . '</div>';
			if ( '' !== $room['sub'] ) {
				$html .= '<div class="kb-week-roomsub">' . esc_html( $room['sub'] ) . '</div>';
			}
			$html .= '</div>';
		}

		foreach ( $model['days'] as $day ) {
			$busy  = count( array_filter( $day['cells'] ) ) > 0;
			$html .= '<div class="kb-week-row' . ( $day['today'] ? ' kb-week-row--today' : '' ) . ( $busy ? '' : ' kb-week-row--free' ) . '">';
			$html .= '<div class="kb-week-day"><div class="kb-week-dayname">' . esc_html( 'יום ' . $day['day_name'] ) . '</div>';
			$html .= '<div class="kb-week-date">' . esc_html( $day['date_label'] ) . ' · <span class="kb-hebdate" data-date="' . esc_attr( $day['date'] ) . '" data-short="1">' . esc_html( self::hebrew_date( $day['date'], false ) ) . '</span></div>';
			if ( $day['today'] ) {
				$html .= '<div class="kb-week-todaytag">היום</div>';
			}
			$html .= '</div>';

			foreach ( $day['cells'] as $i => $items ) {
				$html .= '<div class="kb-week-cell' . ( $items ? '' : ' kb-week-cell--free' ) . '">';
				$html .= '<div class="kb-week-cellroom">' . esc_html( isset( $model['rooms'][ $i ] ) ? $model['rooms'][ $i ]['name'] : '' ) . '</div>';
				if ( ! $items ) {
					$html .= '<div class="kb-week-free">פנוי</div>';
				}
				foreach ( $items as $item ) {
					$state = '';
					if ( $item['end'] > $item['start'] && $now >= $item['end'] ) {
						$state = ' kb-week-event--ended';
					} elseif ( $now >= $item['start'] && $now < $item['end'] ) {
						$state = ' kb-week-event--live';
					}
					$html .= sprintf(
						'<div class="kb-week-event%1$s" data-start="%2$d" data-end="%3$d"><div class="kb-week-time" dir="ltr">%4$s</div><div class="kb-week-name">%5$s</div></div>',
						$state,
						(int) $item['start'],
						(int) $item['end'],
						esc_html( $item['time'] ),
						esc_html( $item['title'] )
					);
				}
				$html .= '</div>';
			}
			if ( ! $busy ) {
				$html .= '<div class="kb-week-nothing">אין פעילות ביום זה</div>';
			}
			$html .= '</div>';
		}
		return $html . '</div></div>';
	}

	/**
	 * Heading with its last word in red ("מה הכיוון *היום?*").
	 *
	 * @param string $text Heading.
	 * @return string Escaped HTML.
	 */
	private static function accent_title( $text ) {
		$words  = preg_split( '/\s+/u', trim( (string) $text ), -1, PREG_SPLIT_NO_EMPTY );
		$accent = count( $words ) > 1 ? array_pop( $words ) : '';
		$title  = esc_html( implode( ' ', $words ) );
		if ( '' !== $accent ) {
			$title .= ' <span class="kb-title-accent">' . esc_html( $accent ) . '</span>';
		}
		return $title;
	}

	/**
	 * Shortcode yes/no attribute.
	 *
	 * @param mixed $value Attribute value.
	 * @return bool
	 */
	private static function is_yes( $value ) {
		$value = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $value ), 'UTF-8' ) : strtolower( trim( (string) $value ) );
		return in_array( $value, array( '1', 'yes', 'true', 'on', 'show', 'כן' ), true );
	}

	/**
	 * Slot status. Mirrors slotState() in board.js, which re-evaluates it every 10 seconds.
	 *
	 * @param array[] $items Events with start / end.
	 * @param int     $now   Unix time.
	 * @return string empty | live | soon | upcoming | ended
	 */
	public static function slot_state( $items, $now ) {
		if ( ! $items ) {
			return 'empty';
		}
		$next = null;
		foreach ( $items as $item ) {
			if ( $now >= $item['start'] && $now < $item['end'] ) {
				return 'live';
			}
			if ( $now < $item['start'] && ( null === $next || $item['start'] < $next ) ) {
				$next = $item['start'];
			}
		}
		if ( null === $next ) {
			return 'ended';
		}
		return $next - $now <= self::SOON_SECONDS ? 'soon' : 'upcoming';
	}

	/**
	 * Hebrew calendar date in Hebrew letters ("ח׳ בחשוון תשפ״ז").
	 * board.js does the same in the browser when PHP lacks the intl extension.
	 *
	 * @param string $ymd       Date as Y-m-d.
	 * @param bool   $with_year Include the year (the week view leaves it out).
	 * @return string
	 */
	private static function hebrew_date( $ymd, $with_year = true ) {
		if ( ! class_exists( 'IntlDateFormatter' ) ) {
			return '';
		}
		$date  = new DateTime( $ymd . ' 12:00:00', new DateTimeZone( 'UTC' ) );
		$parts = array();
		foreach ( array( 'd', 'MMMM', 'y' ) as $pattern ) {
			// numbers=latn: always get plain digits, whatever the ICU defaults are.
			$formatter = new IntlDateFormatter( 'he_IL@calendar=hebrew;numbers=latn', IntlDateFormatter::NONE, IntlDateFormatter::NONE, 'UTC', IntlDateFormatter::TRADITIONAL, $pattern );
			$value     = $formatter->format( $date );
			if ( false === $value || '' === $value ) {
				return '';
			}
			$parts[] = $value;
		}
		$text = self::gematria( (int) $parts[0] ) . ' ב' . $parts[1];
		return $with_year ? $text . ' ' . self::gematria( (int) $parts[2] % 1000 ) : $text;
	}

	/**
	 * A number in Hebrew letters with geresh / gershayim: 8 → ח׳, 15 → ט״ו, 787 → תשפ״ז.
	 *
	 * @param int $n Number (1–999).
	 * @return string
	 */
	public static function gematria( $n ) {
		$letters = array(
			400 => 'ת',
			300 => 'ש',
			200 => 'ר',
			100 => 'ק',
			90  => 'צ',
			80  => 'פ',
			70  => 'ע',
			60  => 'ס',
			50  => 'נ',
			40  => 'מ',
			30  => 'ל',
			20  => 'כ',
			10  => 'י',
			9   => 'ט',
			8   => 'ח',
			7   => 'ז',
			6   => 'ו',
			5   => 'ה',
			4   => 'ד',
			3   => 'ג',
			2   => 'ב',
			1   => 'א',
		);
		$out = '';
		$n   = (int) $n;
		while ( $n > 0 ) {
			// 15 and 16 are written ט״ו / ט״ז, never as the letters of the divine name.
			if ( 15 === $n || 16 === $n ) {
				$out .= 'ט' . ( 15 === $n ? 'ו' : 'ז' );
				break;
			}
			foreach ( $letters as $value => $letter ) {
				if ( $n >= $value ) {
					$out .= $letter;
					$n   -= $value;
					break;
				}
			}
		}
		$chars = preg_split( '//u', $out, -1, PREG_SPLIT_NO_EMPTY );
		if ( count( $chars ) < 2 ) {
			return $out . '׳';
		}
		$last = array_pop( $chars );
		return implode( '', $chars ) . '״' . $last;
	}

	/**
	 * @param string $value    Hex color.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function color( $value, $fallback ) {
		$value = sanitize_hex_color( $value );
		return $value ? $value : $fallback;
	}
}
