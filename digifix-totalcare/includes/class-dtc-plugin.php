<?php
/**
 * Wires services, job handlers and hooks together.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class DTC_Plugin {

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		DTC_Jobs::register( 'backup', new DTC_Backup_Service() );
		DTC_Jobs::register( 'update', new DTC_Update_Service() );
		DTC_Jobs::register( 'scan', new DTC_Scan_Service() );
		DTC_Jobs::register( 'restore', new DTC_Restore() );

		DTC_Scheduler::init();
		DTC_WPvivid::init();

		add_action( 'wp_ajax_dtc_kick', array( $this, 'ajax_kick' ) );
		add_action( 'wp_ajax_nopriv_dtc_kick', array( $this, 'ajax_kick' ) );
		add_action( 'admin_init', array( 'DTC_Installer', 'maybe_upgrade' ) );
		add_action( DTC_Scheduler::HOOK_DAILY, array( 'DTC_Installer', 'maybe_upgrade' ), 1 );

		if ( is_admin() ) {
			DTC_Admin::init();
		}
	}

	/** Loopback endpoint that advances the job engine immediately. */
	public function ajax_kick() {
		$ts  = isset( $_POST['ts'] ) ? (int) $_POST['ts'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! DTC_Storage::verify( 'dtc_kick', $ts, $sig ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		DTC_Jobs::tick();
		wp_send_json( array( 'ok' => true ) );
	}
}
