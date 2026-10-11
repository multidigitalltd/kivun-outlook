<?php
/**
 * Settings screen (Settings → לוח כיתות Outlook).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Kivun_Board_Admin {

	const PAGE = 'kivun-board';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'register' ) );
		add_action( 'admin_post_kivun_board_sync', array( __CLASS__, 'handle_sync' ) );
		add_action( 'update_option_' . Kivun_Board_Settings::OPTION, array( __CLASS__, 'after_save' ), 10, 2 );
		add_action( 'add_option_' . Kivun_Board_Settings::OPTION, array( __CLASS__, 'after_add' ), 10, 2 );
		add_filter( 'plugin_action_links_' . plugin_basename( KIVUN_BOARD_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page( 'לוח כיתות Outlook', 'לוח כיתות Outlook', 'manage_options', self::PAGE, array( __CLASS__, 'page' ) );
	}

	/**
	 * @param string[] $links Plugin row links.
	 * @return string[]
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=' . self::PAGE ) ) . '">הגדרות</a>' );
		return $links;
	}

	public static function register() {
		register_setting(
			'kivun_board',
			Kivun_Board_Settings::OPTION,
			array(
				'type'              => 'array',
				'sanitize_callback' => array( __CLASS__, 'sanitize' ),
				'default'           => Kivun_Board_Settings::defaults(),
			)
		);
	}

	/**
	 * @param array $input Submitted values.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$old = Kivun_Board_Settings::all();
		$d   = Kivun_Board_Settings::defaults();
		$in  = is_array( $input ) ? $input : array();
		$out = array();

		$out['source']              = isset( $in['source'] ) && 'graph' === $in['source'] ? 'graph' : 'ics';
		$out['ics_urls']            = isset( $in['ics_urls'] ) ? sanitize_textarea_field( $in['ics_urls'] ) : $old['ics_urls'];
		$out['graph_tenant_id']     = isset( $in['graph_tenant_id'] ) ? sanitize_text_field( $in['graph_tenant_id'] ) : $old['graph_tenant_id'];
		$out['graph_client_id']     = isset( $in['graph_client_id'] ) ? sanitize_text_field( $in['graph_client_id'] ) : $old['graph_client_id'];
		$out['graph_client_secret'] = isset( $in['graph_client_secret'] ) && '' !== trim( $in['graph_client_secret'] ) ? trim( sanitize_text_field( $in['graph_client_secret'] ) ) : $old['graph_client_secret'];
		$out['graph_mailboxes']     = isset( $in['graph_mailboxes'] ) ? sanitize_textarea_field( $in['graph_mailboxes'] ) : $old['graph_mailboxes'];
		$out['sync_interval']       = self::int_between( $in, 'sync_interval', 1, 1440, $d['sync_interval'] );
		$out['hide_private']        = empty( $in['hide_private'] ) ? 0 : 1;

		$out['rooms']       = isset( $in['rooms'] ) ? sanitize_textarea_field( $in['rooms'] ) : $old['rooms'];
		$out['match_title'] = empty( $in['match_title'] ) ? 0 : 1;

		$out['split_time'] = isset( $in['split_time'] ) && preg_match( '/^([01]?\d|2[0-3]):[0-5]\d$/', trim( $in['split_time'] ) ) ? trim( $in['split_time'] ) : $d['split_time'];
		$out['title']      = isset( $in['title'] ) ? sanitize_textarea_field( $in['title'] ) : $old['title'];
		foreach ( array( 'subtitle', 'col_room', 'col_course', 'col_morning', 'col_afternoon' ) as $key ) {
			$out[ $key ] = isset( $in[ $key ] ) ? sanitize_text_field( $in[ $key ] ) : $old[ $key ];
		}
		$out['show_date'] = empty( $in['show_date'] ) ? 0 : 1;
		foreach ( array( 'logo_url', 'footer_image_url' ) as $key ) {
			$out[ $key ] = isset( $in[ $key ] ) ? esc_url_raw( trim( $in[ $key ] ) ) : $old[ $key ];
		}
		foreach ( array( 'accent_color', 'board_color', 'arrow_color' ) as $key ) {
			$color       = isset( $in[ $key ] ) ? sanitize_hex_color( $in[ $key ] ) : '';
			$out[ $key ] = $color ? $color : $d[ $key ];
		}

		$slug               = isset( $in['screen_slug'] ) ? sanitize_title( $in['screen_slug'] ) : '';
		$out['screen_slug'] = '' !== $slug ? $slug : $d['screen_slug'];
		if ( $out['screen_slug'] !== $old['screen_slug'] ) {
			update_option( 'kivun_board_flush_rewrites', 1 );
		}
		$out['refresh_seconds'] = self::int_between( $in, 'refresh_seconds', 15, 3600, $d['refresh_seconds'] );
		$out['rows_per_page']   = self::int_between( $in, 'rows_per_page', 0, 50, $d['rows_per_page'] );
		$out['page_seconds']    = self::int_between( $in, 'page_seconds', 5, 300, $d['page_seconds'] );

		return $out;
	}

	/**
	 * @param array  $in       Input.
	 * @param string $key      Key.
	 * @param int    $min      Minimum.
	 * @param int    $max      Maximum.
	 * @param int    $fallback Default.
	 * @return int
	 */
	private static function int_between( $in, $key, $min, $max, $fallback ) {
		if ( ! isset( $in[ $key ] ) || '' === trim( (string) $in[ $key ] ) ) {
			return $fallback;
		}
		return max( $min, min( $max, (int) $in[ $key ] ) );
	}

	/**
	 * Re-sync (and reschedule) right after the settings change.
	 *
	 * @param array $old Old value.
	 * @param array $new New value.
	 */
	public static function after_save( $old, $new ) {
		$old = is_array( $old ) ? $old : array();
		if ( ! isset( $old['sync_interval'] ) || (int) $old['sync_interval'] !== (int) $new['sync_interval'] ) {
			Kivun_Board_Sync::schedule( true );
		}
		delete_transient( Kivun_Board_Sync::LOCK );
		Kivun_Board_Sync::run();
	}

	/**
	 * @param string $option Option name.
	 * @param array  $value  Value.
	 */
	public static function after_add( $option, $value ) {
		self::after_save( array(), $value );
	}

	public static function handle_sync() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Sorry, you are not allowed to do that.' ) );
		}
		check_admin_referer( 'kivun_board_sync' );
		delete_transient( Kivun_Board_Sync::LOCK );
		Kivun_Board_Sync::run();
		wp_safe_redirect( admin_url( 'options-general.php?page=' . self::PAGE . '&kb_synced=1' ) );
		exit;
	}

	/* ------------------------------------------------------------------ */
	/* Page                                                               */
	/* ------------------------------------------------------------------ */

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		wp_enqueue_media();
		$s    = Kivun_Board_Settings::all();
		$data = Kivun_Board_Sync::data();
		$name = Kivun_Board_Settings::OPTION;
		$tz   = wp_timezone_string();
		?>
		<div class="wrap kb-admin">
			<h1>לוח כיתות מאאוטלוק</h1>

			<?php if ( isset( $_GET['kb_synced'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended ?>
				<div class="notice notice-success is-dismissible"><p>הסנכרון הסתיים.</p></div>
			<?php endif; ?>

			<div class="card" style="max-width:none">
				<h2>מצב</h2>
				<p>
					<strong>מסך להקרנה:</strong>
					<a href="<?php echo esc_url( Kivun_Board::screen_url() ); ?>" target="_blank" rel="noopener"><code><?php echo esc_html( Kivun_Board::screen_url() ); ?></code></a>
					&nbsp;|&nbsp; <strong>שורטקוד לדף רגיל:</strong> <code>[kivun_board]</code>
				</p>
				<p>
					<strong>סנכרון אחרון:</strong>
					<?php echo $data['synced_at'] ? esc_html( wp_date( 'd.m.Y H:i:s', $data['synced_at'] ) ) : 'עדיין לא בוצע'; ?>
					&nbsp;|&nbsp; <strong>אירועים שהתקבלו:</strong> <?php echo (int) $data['count']; ?>
					&nbsp;|&nbsp; <strong>שובצו לכיתות:</strong> <?php echo count( $data['events'] ); ?>
					&nbsp;|&nbsp; <strong>אזור זמן האתר:</strong> <?php echo esc_html( $tz ); ?>
				</p>
				<?php if ( $data['error'] ) : ?>
					<div class="notice notice-error inline"><p><strong>שגיאה:</strong> <?php echo esc_html( $data['error'] ); ?></p></div>
				<?php endif; ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<input type="hidden" name="action" value="kivun_board_sync">
					<?php wp_nonce_field( 'kivun_board_sync' ); ?>
					<?php submit_button( 'סנכרן עכשיו', 'secondary', 'kb-sync-now', false ); ?>
				</form>

				<?php if ( $data['unmatched'] ) : ?>
					<details style="margin-top:12px">
						<summary><strong><?php echo count( $data['unmatched'] ); ?> אירועים לא שובצו לאף כיתה</strong> (הוסיפו מילות התאמה בשדה "כיתות")</summary>
						<table class="widefat striped" style="margin-top:8px">
							<thead><tr><th>מועד</th><th>נושא</th><th>מיקום</th></tr></thead>
							<tbody>
								<?php foreach ( array_slice( $data['unmatched'], 0, 30 ) as $u ) : ?>
									<tr>
										<td><?php echo esc_html( wp_date( 'd.m H:i', $u['start'] ) ); ?></td>
										<td><?php echo esc_html( $u['title'] ); ?></td>
										<td><?php echo esc_html( $u['location'] ); ?></td>
									</tr>
								<?php endforeach; ?>
							</tbody>
						</table>
					</details>
				<?php endif; ?>
			</div>

			<form method="post" action="options.php">
				<?php settings_fields( 'kivun_board' ); ?>

				<h2 class="title">1. מקור היומן</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">שיטת חיבור</th>
						<td>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[source]" value="ics" <?php checked( 'ics', $s['source'] ); ?>> קישור ICS מפורסם (הכי פשוט)</label><br>
							<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[source]" value="graph" <?php checked( 'graph', $s['source'] ); ?>> Microsoft Graph API (Microsoft 365 – דורש רישום אפליקציה)</label>
						</td>
					</tr>
					<tr class="kb-src kb-src-ics">
						<th scope="row"><label for="kb-ics">קישורי ICS</label></th>
						<td>
							<textarea id="kb-ics" class="large-text code" rows="4" dir="ltr" name="<?php echo esc_attr( $name ); ?>[ics_urls]"><?php echo esc_textarea( $s['ics_urls'] ); ?></textarea>
							<p class="description">קישור אחד בכל שורה. באאוטלוק: הגדרות ← לוח שנה ← לוחות שנה משותפים ← "פרסם לוח שנה" ← בחרו "יכול להציג את כל הפרטים" והעתיקו את קישור ה-ICS.<br>
							אם לכל כיתה יש יומן נפרד, הוסיפו את שם הכיתה אחרי קו אנכי: <code dir="ltr">https://outlook.office365.com/owa/calendar/.../calendar.ics | מחשבים</code></p>
						</td>
					</tr>
					<tr class="kb-src kb-src-graph">
						<th scope="row"><label for="kb-tenant">Tenant ID</label></th>
						<td><input id="kb-tenant" type="text" class="regular-text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[graph_tenant_id]" value="<?php echo esc_attr( $s['graph_tenant_id'] ); ?>"></td>
					</tr>
					<tr class="kb-src kb-src-graph">
						<th scope="row"><label for="kb-client">Client ID</label></th>
						<td><input id="kb-client" type="text" class="regular-text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[graph_client_id]" value="<?php echo esc_attr( $s['graph_client_id'] ); ?>"></td>
					</tr>
					<tr class="kb-src kb-src-graph">
						<th scope="row"><label for="kb-secret">Client Secret</label></th>
						<td>
							<input id="kb-secret" type="password" class="regular-text" dir="ltr" autocomplete="new-password" name="<?php echo esc_attr( $name ); ?>[graph_client_secret]" value="" placeholder="<?php echo $s['graph_client_secret'] ? '•••••••• (שמור – השאירו ריק כדי לא לשנות)' : ''; ?>">
						</td>
					</tr>
					<tr class="kb-src kb-src-graph">
						<th scope="row"><label for="kb-mailboxes">תיבות דואר / חדרים</label></th>
						<td>
							<textarea id="kb-mailboxes" class="large-text code" rows="4" dir="ltr" name="<?php echo esc_attr( $name ); ?>[graph_mailboxes]"><?php echo esc_textarea( $s['graph_mailboxes'] ); ?></textarea>
							<p class="description">כתובת אחת בכל שורה (משתמש, תיבה משותפת או תיבת חדר). אפשר לשייך תיבה לכיתה: <code dir="ltr">room-computers@kivun.org.il | מחשבים</code><br>
							באפליקציה ב-Entra ID יש להעניק הרשאת Application מסוג <code>Calendars.Read</code> עם Admin consent.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="kb-interval">תדירות סנכרון (דקות)</label></th>
						<td><input id="kb-interval" type="number" min="1" max="1440" class="small-text" name="<?php echo esc_attr( $name ); ?>[sync_interval]" value="<?php echo (int) $s['sync_interval']; ?>"></td>
					</tr>
					<tr>
						<th scope="row">פרטיות</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[hide_private]" value="1" <?php checked( 1, (int) $s['hide_private'] ); ?>> לא להציג פגישות שסומנו "פרטי" באאוטלוק</label></td>
					</tr>
				</table>

				<h2 class="title">2. כיתות (שורות הטבלה)</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kb-rooms">כיתות</label></th>
						<td>
							<textarea id="kb-rooms" class="large-text" rows="8" name="<?php echo esc_attr( $name ); ?>[rooms]"><?php echo esc_textarea( $s['rooms'] ); ?></textarea>
							<p class="description">כיתה בכל שורה, לפי סדר התצוגה: <code>שם הכיתה | תת-כותרת | מילות התאמה נוספות מופרדות בפסיק</code><br>
							דוגמה: <code>הר חוצבים | קומה 0 | חוצבים, כיתה 3</code><br>
							אירוע משובץ לכיתה כששם הכיתה או אחת ממילות ההתאמה מופיעים בשדה <strong>מיקום</strong> של הפגישה באאוטלוק (או בקטגוריה שלה).</p>
						</td>
					</tr>
					<tr>
						<th scope="row">התאמה גם לפי נושא</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[match_title]" value="1" <?php checked( 1, (int) $s['match_title'] ); ?>> אם המיקום ריק או לא מזוהה – לחפש את שם הכיתה גם בנושא הפגישה</label></td>
					</tr>
				</table>

				<h2 class="title">3. עיצוב הלוח</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kb-split">שעת מעבר בוקר ← אחה"צ</label></th>
						<td><input id="kb-split" type="time" name="<?php echo esc_attr( $name ); ?>[split_time]" value="<?php echo esc_attr( $s['split_time'] ); ?>">
						<p class="description">אירוע שמתחיל לפני שעה זו יוצג בעמודות הבוקר, ואחריה – בעמודות אחה"צ.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="kb-title">כותרת (חלק אדום)</label></th>
						<td><textarea id="kb-title" rows="3" class="regular-text" name="<?php echo esc_attr( $name ); ?>[title]"><?php echo esc_textarea( $s['title'] ); ?></textarea>
						<p class="description">כל שורה תוצג בשורה נפרדת.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="kb-subtitle">תת-כותרת</label></th>
						<td><input id="kb-subtitle" type="text" class="regular-text" name="<?php echo esc_attr( $name ); ?>[subtitle]" value="<?php echo esc_attr( $s['subtitle'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row">כותרות עמודות</th>
						<td>
							<input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $name ); ?>[col_room]" value="<?php echo esc_attr( $s['col_room'] ); ?>" aria-label="כיתה">
							<input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $name ); ?>[col_course]" value="<?php echo esc_attr( $s['col_course'] ); ?>" aria-label="קורס">
							<input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $name ); ?>[col_morning]" value="<?php echo esc_attr( $s['col_morning'] ); ?>" aria-label="בוקר">
							<input type="text" class="small-text" style="width:8em" name="<?php echo esc_attr( $name ); ?>[col_afternoon]" value="<?php echo esc_attr( $s['col_afternoon'] ); ?>" aria-label="אחה״צ">
						</td>
					</tr>
					<tr>
						<th scope="row">תאריך</th>
						<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[show_date]" value="1" <?php checked( 1, (int) $s['show_date'] ); ?>> להציג את התאריך ליד שם היום</label></td>
					</tr>
					<?php
					self::image_row( 'logo_url', 'לוגו (פס תחתון, ימין)', $s );
					self::image_row( 'footer_image_url', 'תמונת שותפים/לוגואים (פס תחתון, שמאל)', $s );
					?>
					<tr>
						<th scope="row">צבעים</th>
						<td>
							<label>רקע אדום <input type="color" name="<?php echo esc_attr( $name ); ?>[accent_color]" value="<?php echo esc_attr( $s['accent_color'] ); ?>"></label>
							&nbsp; <label>רקע הטבלה <input type="color" name="<?php echo esc_attr( $name ); ?>[board_color]" value="<?php echo esc_attr( $s['board_color'] ); ?>"></label>
							&nbsp; <label>חץ <input type="color" name="<?php echo esc_attr( $name ); ?>[arrow_color]" value="<?php echo esc_attr( $s['arrow_color'] ); ?>"></label>
						</td>
					</tr>
				</table>

				<h2 class="title">4. מסך הקרנה</h2>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="kb-slug">כתובת המסך</label></th>
						<td><code dir="ltr"><?php echo esc_html( home_url( '/' ) ); ?></code><input id="kb-slug" type="text" class="regular-text" dir="ltr" name="<?php echo esc_attr( $name ); ?>[screen_slug]" value="<?php echo esc_attr( $s['screen_slug'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="kb-refresh">רענון נתונים (שניות)</label></th>
						<td><input id="kb-refresh" type="number" min="15" max="3600" class="small-text" name="<?php echo esc_attr( $name ); ?>[refresh_seconds]" value="<?php echo (int) $s['refresh_seconds']; ?>">
						<p class="description">כל כמה זמן המסך בודק אם יש שינוי (ללא טעינה מחדש של הדף). המעבר ליום הבא קורה אוטומטית בחצות.</p></td>
					</tr>
					<tr>
						<th scope="row"><label for="kb-rows">כיתות בכל עמוד</label></th>
						<td><input id="kb-rows" type="number" min="0" max="50" class="small-text" name="<?php echo esc_attr( $name ); ?>[rows_per_page]" value="<?php echo (int) $s['rows_per_page']; ?>">
						&nbsp; <label for="kb-page-sec">החלפת עמוד כל</label> <input id="kb-page-sec" type="number" min="5" max="300" class="small-text" name="<?php echo esc_attr( $name ); ?>[page_seconds]" value="<?php echo (int) $s['page_seconds']; ?>"> שניות
						<p class="description">אם יש יותר כיתות ממה שנכנס במסך, הן יתחלפו בעמודים. 0 = הכל בעמוד אחד.</p></td>
					</tr>
				</table>

				<?php submit_button( 'שמירה וסנכרון' ); ?>
			</form>
		</div>
		<script>
		( function () {
			var radios = document.querySelectorAll( 'input[name="<?php echo esc_js( $name ); ?>[source]"]' );
			function update() {
				var value = document.querySelector( 'input[name="<?php echo esc_js( $name ); ?>[source]"]:checked' ).value;
				document.querySelectorAll( '.kb-src' ).forEach( function ( row ) {
					row.style.display = row.classList.contains( 'kb-src-' + value ) ? '' : 'none';
				} );
			}
			radios.forEach( function ( r ) { r.addEventListener( 'change', update ); } );
			update();

			document.querySelectorAll( '.kb-pick-image' ).forEach( function ( button ) {
				button.addEventListener( 'click', function ( e ) {
					e.preventDefault();
					var input = document.getElementById( button.getAttribute( 'data-target' ) );
					var frame = wp.media( { title: 'בחירת תמונה', library: { type: 'image' }, multiple: false } );
					frame.on( 'select', function () {
						input.value = frame.state().get( 'selection' ).first().toJSON().url;
					} );
					frame.open();
				} );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * @param string $key   Setting key.
	 * @param string $label Label.
	 * @param array  $s     Settings.
	 */
	private static function image_row( $key, $label, $s ) {
		$id = 'kb-' . $key;
		?>
		<tr>
			<th scope="row"><label for="<?php echo esc_attr( $id ); ?>"><?php echo esc_html( $label ); ?></label></th>
			<td>
				<input id="<?php echo esc_attr( $id ); ?>" type="url" class="regular-text" dir="ltr" name="<?php echo esc_attr( Kivun_Board_Settings::OPTION . '[' . $key . ']' ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>">
				<button type="button" class="button kb-pick-image" data-target="<?php echo esc_attr( $id ); ?>">בחירה מהספרייה</button>
			</td>
		</tr>
		<?php
	}
}
