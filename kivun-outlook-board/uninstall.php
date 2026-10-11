<?php
/**
 * Removes all plugin data when the plugin is deleted.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'kivun_board_settings' );
delete_option( 'kivun_board_data' );
delete_option( 'kivun_board_flush_rewrites' );
delete_transient( 'kivun_board_sync_lock' );
wp_clear_scheduled_hook( 'kivun_board_sync' );
