<?php
/**
 * HTML email rendering through templates/email.php.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Digifix_TC_Email {

	public static function render( array $mail ) {
		$brand  = Digifix_TC_Settings::get( 'brand_color' );
		$client = Digifix_TC_Settings::get( 'client_name' );
		ob_start();
		include DIGIFIX_TC_DIR . 'templates/email.php';
		return (string) ob_get_clean();
	}

	public static function send( array $to, $subject, array $mail, array $attachments = array() ) {
		$html = self::render( $mail );
		return wp_mail( $to, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ), $attachments );
	}
}
