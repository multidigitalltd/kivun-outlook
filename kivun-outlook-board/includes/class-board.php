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
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_board( $request ) {
		Kivun_Board_Sync::maybe_run_stale();
		$date  = self::parse_date( $request->get_param( 'date' ) );
		$inner = self::render_inner( self::model( $date ) );

		$response = new WP_REST_Response(
			array(
				'date' => $date->format( 'Y-m-d' ),
				'hash' => md5( $inner ),
				'html' => $inner,
			)
		);
		$response->header( 'Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0' );
		return $response;
	}

	/* ------------------------------------------------------------------ */
	/* Shortcode & assets                                                 */
	/* ------------------------------------------------------------------ */

	public static function register_assets() {
		wp_register_style( 'kivun-board-font', 'https://fonts.googleapis.com/css2?family=Heebo:wght@400;500;700;800;900&display=swap', array(), null ); // phpcs:ignore WordPress.WP.EnqueuedResourceVersion.MissingVersion
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

	/* ------------------------------------------------------------------ */
	/* Model                                                              */
	/* ------------------------------------------------------------------ */

	/**
	 * Date from ?kb_date= (for previewing other days) or today.
	 *
	 * @return DateTimeImmutable
	 */
	private static function requested_date() {
		$value = isset( $_GET['kb_date'] ) ? sanitize_text_field( wp_unslash( $_GET['kb_date'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return self::parse_date( $value );
	}

	/**
	 * @param string|null $value YYYY-MM-DD.
	 * @return DateTimeImmutable Midnight of that date (or today) in the site zone.
	 */
	public static function parse_date( $value ) {
		$tz = wp_timezone();
		if ( is_string( $value ) && preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $value, $m ) && checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return ( new DateTimeImmutable( 'now', $tz ) )->setDate( (int) $m[1], (int) $m[2], (int) $m[3] )->setTime( 0, 0, 0 );
		}
		return new DateTimeImmutable( 'today', $tz );
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
				$time = wp_date( 'H:i', $event['start'] ) . '-' . wp_date( 'H:i', $event['end'] );
			}

			$slot                              = $minutes < $split ? 'morning' : 'afternoon';
			$rows[ $event['room'] ][ $slot ][] = array(
				'title' => $event['title'],
				'time'  => $time,
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

	/* ------------------------------------------------------------------ */
	/* Rendering                                                          */
	/* ------------------------------------------------------------------ */

	/**
	 * Complete board widget (wrapper + inner markup).
	 *
	 * @param DateTimeImmutable $day  Day to show.
	 * @param string            $mode 'screen' or 'embed'.
	 * @return string
	 */
	public static function render( DateTimeImmutable $day, $mode ) {
		$s        = Kivun_Board_Settings::all();
		$inner    = self::render_inner( self::model( $day ) );
		$is_today = $day->format( 'Y-m-d' ) === ( new DateTimeImmutable( 'today', wp_timezone() ) )->format( 'Y-m-d' );

		$style = sprintf(
			'--kb-accent:%s;--kb-board:%s;--kb-arrow:%s;',
			esc_attr( self::color( $s['accent_color'], '#e8334a' ) ),
			esc_attr( self::color( $s['board_color'], '#3b3b3b' ) ),
			esc_attr( self::color( $s['arrow_color'], '#29b6e8' ) )
		);

		$endpoint = rest_url( self::REST_NS . '/board' );
		if ( ! $is_today ) {
			$endpoint = add_query_arg( 'date', $day->format( 'Y-m-d' ), $endpoint );
		}

		return sprintf(
			'<div class="kb-board kb-mode-%1$s" dir="rtl" lang="he" style="%2$s" data-mode="%1$s" data-endpoint="%3$s" data-refresh="%4$d" data-rows-per-page="%5$d" data-page-seconds="%6$d" data-hash="%7$s"><div class="kb-scaler"><div class="kb-stage">%8$s</div></div></div>',
			esc_attr( $mode ),
			$style,
			esc_url( $endpoint ),
			max( 15, (int) $s['refresh_seconds'] ),
			max( 0, (int) $s['rows_per_page'] ),
			max( 5, (int) $s['page_seconds'] ),
			esc_attr( md5( $inner ) ),
			$inner
		);
	}

	/**
	 * Inner markup of the stage (what the screen refreshes).
	 *
	 * @param array $model Model from self::model().
	 * @return string
	 */
	public static function render_inner( $model ) {
		$s = Kivun_Board_Settings::all();

		$title_lines = array_filter( array_map( 'trim', preg_split( '/\r\n|\r|\n|\|/', (string) $s['title'] ) ), 'strlen' );
		$title_html  = implode( '<br>', array_map( 'esc_html', $title_lines ) );

		ob_start();
		?>
		<div class="kb-main">
			<aside class="kb-hero">
				<div class="kb-title"><?php echo $title_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped per line. ?></div>
				<?php if ( '' !== trim( (string) $s['subtitle'] ) ) : ?>
					<div class="kb-subtitle"><?php echo esc_html( $s['subtitle'] ); ?></div>
				<?php endif; ?>
			</aside>
			<div class="kb-arrow" aria-hidden="true"></div>
			<section class="kb-panel">
				<h2 class="kb-day">
					<span class="kb-day-name">יום <?php echo esc_html( $model['day_name'] ); ?></span>
					<?php if ( ! empty( $s['show_date'] ) ) : ?>
						<span class="kb-date"><?php echo esc_html( $model['date_label'] ); ?></span>
					<?php endif; ?>
				</h2>
				<div class="kb-table-wrap">
					<table class="kb-table">
						<thead>
							<tr>
								<th class="kb-col-room"><?php echo esc_html( $s['col_room'] ); ?></th>
								<th class="kb-col-course"><?php echo esc_html( $s['col_course'] ); ?></th>
								<th class="kb-col-time"><?php echo esc_html( $s['col_morning'] ); ?></th>
								<th class="kb-col-course"><?php echo esc_html( $s['col_course'] ); ?></th>
								<th class="kb-col-time"><?php echo esc_html( $s['col_afternoon'] ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $model['rows'] as $row ) : ?>
								<tr>
									<th scope="row" class="kb-room">
										<span class="kb-room-name"><?php echo esc_html( $row['name'] ); ?></span>
										<?php if ( '' !== $row['sub'] ) : ?>
											<span class="kb-room-sub">(<?php echo esc_html( $row['sub'] ); ?>)</span>
										<?php endif; ?>
									</th>
									<?php
									foreach ( array( 'morning', 'afternoon' ) as $slot ) {
										echo self::render_slot( $row[ $slot ] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped inside.
									}
									?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<div class="kb-pages" aria-hidden="true"></div>
			</section>
		</div>
		<?php if ( $s['logo_url'] || $s['footer_image_url'] ) : ?>
			<footer class="kb-footer">
				<?php if ( $s['logo_url'] ) : ?>
					<img class="kb-logo" src="<?php echo esc_url( $s['logo_url'] ); ?>" alt="">
				<?php endif; ?>
				<?php if ( $s['footer_image_url'] ) : ?>
					<img class="kb-partners" src="<?php echo esc_url( $s['footer_image_url'] ); ?>" alt="">
				<?php endif; ?>
			</footer>
		<?php endif; ?>
		<?php
		return trim( preg_replace( '/>\s+</', '><', ob_get_clean() ) );
	}

	/**
	 * Course cell + time cell of one slot.
	 *
	 * @param array[] $items Events of the slot: title, time.
	 * @return string
	 */
	private static function render_slot( $items ) {
		$multi   = count( $items ) > 1 ? ' kb-multi' : '';
		$courses = '';
		$times   = '';
		foreach ( $items as $item ) {
			$courses .= '<div class="kb-item">' . esc_html( $item['title'] ) . '</div>';
			$times   .= '<div class="kb-item"><bdi dir="ltr">' . esc_html( $item['time'] ) . '</bdi></div>';
		}
		return '<td class="kb-course' . $multi . '">' . $courses . '</td><td class="kb-time' . $multi . '">' . $times . '</td>';
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
