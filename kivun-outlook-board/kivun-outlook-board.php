<?php
/**
 * Plugin Name:       Kivun Outlook Board – לוח כיתות מאאוטלוק
 * Description:       מסנכרן יומן Outlook (קישור ICS מפורסם או Microsoft Graph) ללוח כיתות יומי מעוצב בדף פומבי, כולל מצב תצוגה מלאה להקרנה על מסך בכניסה.
 * Version:           2.0.1
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * Author:            Kivun
 * License:           GPL-2.0-or-later
 * Text Domain:       kivun-board
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'KIVUN_BOARD_VERSION', '2.0.1' );
define( 'KIVUN_BOARD_FILE', __FILE__ );
define( 'KIVUN_BOARD_DIR', plugin_dir_path( __FILE__ ) );
define( 'KIVUN_BOARD_URL', plugin_dir_url( __FILE__ ) );

require_once KIVUN_BOARD_DIR . 'includes/class-settings.php';
require_once KIVUN_BOARD_DIR . 'includes/class-ics-parser.php';
require_once KIVUN_BOARD_DIR . 'includes/class-graph-client.php';
require_once KIVUN_BOARD_DIR . 'includes/class-sync.php';
require_once KIVUN_BOARD_DIR . 'includes/class-board.php';
require_once KIVUN_BOARD_DIR . 'includes/class-admin.php';

Kivun_Board_Sync::init();
Kivun_Board::init();
if ( is_admin() ) {
	Kivun_Board_Admin::init();
}

register_activation_hook( __FILE__, 'kivun_board_activate' );
register_deactivation_hook( __FILE__, 'kivun_board_deactivate' );

/**
 * Activation: register the screen URL and schedule the background sync.
 */
function kivun_board_activate() {
	Kivun_Board::add_rewrite_rules();
	flush_rewrite_rules();
	Kivun_Board_Sync::schedule( true );
}

/**
 * Deactivation: stop the background sync.
 */
function kivun_board_deactivate() {
	wp_clear_scheduled_hook( Kivun_Board_Sync::CRON_HOOK );
	flush_rewrite_rules();
}
