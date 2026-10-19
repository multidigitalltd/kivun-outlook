<?php
/**
 * Stand-alone full-screen board page (for the lobby screen). No theme is loaded.
 *
 * @var string $html Board markup from Kivun_Board::render().
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?><!DOCTYPE html>
<html dir="rtl" lang="he">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="robots" content="noindex">
	<title><?php echo esc_html( wp_strip_all_tags( str_replace( array( "\r", "\n" ), ' ', (string) Kivun_Board_Settings::get( 'title' ) ) ) ); ?></title>
	<link rel="preconnect" href="https://fonts.googleapis.com">
	<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
	<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Rubik:wght@500;700;800;900&amp;family=Heebo:wght@400;500;700;800&amp;display=swap">
	<link rel="stylesheet" href="<?php echo esc_url( KIVUN_BOARD_URL . 'assets/board.css?ver=' . KIVUN_BOARD_VERSION ); ?>">
</head>
<body class="kb-screen-body">
	<?php echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- built from escaped parts. ?>
	<script src="<?php echo esc_url( KIVUN_BOARD_URL . 'assets/board.js?ver=' . KIVUN_BOARD_VERSION ); ?>"></script>
</body>
</html>
