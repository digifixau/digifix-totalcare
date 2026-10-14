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
		DTC_Jobs::register( 'retention', new DTC_Retention_Service() );

		DTC_Scheduler::init();
		DTC_WPvivid::init();
		DTC_Updater::init();

		add_action( 'wp_ajax_dtc_kick', array( $this, 'ajax_kick' ) );
		add_action( 'wp_ajax_nopriv_dtc_kick', array( $this, 'ajax_kick' ) );
		add_action( 'wp_ajax_dtc_probe', array( $this, 'ajax_probe' ) );
		add_action( 'wp_ajax_nopriv_dtc_probe', array( $this, 'ajax_probe' ) );
		add_action( 'admin_init', array( 'DTC_Installer', 'maybe_upgrade' ) );
		add_action( DTC_Scheduler::HOOK_DAILY, array( 'DTC_Installer', 'maybe_upgrade' ), 1 );

		if ( is_admin() ) {
			DTC_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'totalcare', 'DTC_CLI' );
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

	/**
	 * Background request probe: keeps running for a few seconds after the
	 * caller disconnected (as every loopback does). Hosts that kill such
	 * requests (LiteSpeed without "noabort") never reach the end.
	 */
	public function ajax_probe() {
		$ts  = isset( $_POST['ts'] ) ? (int) $_POST['ts'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sig = isset( $_POST['sig'] ) ? sanitize_text_field( wp_unslash( $_POST['sig'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! DTC_Storage::verify( 'dtc_probe', $ts, $sig ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		ignore_user_abort( true );
		$token = sanitize_key( wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		sleep( 6 );
		update_option( 'dtc_probe_result', array( 'token' => $token, 'time' => time() ), false );
		wp_send_json( array( 'ok' => true ) );
	}

	/** @return array{ok:bool,message:string} */
	public static function probe_background_requests() {
		$token = strtolower( wp_generate_password( 12, false ) );
		delete_option( 'dtc_probe_result' );
		DTC_Storage::loopback( 'dtc_probe', array( 'token' => $token ) );
		for ( $i = 0; $i < 12; $i++ ) {
			sleep( 1 );
			foreach ( array( 'dtc_probe_result', 'alloptions', 'notoptions' ) as $key ) {
				wp_cache_delete( $key, 'options' );
			}
			$res = get_option( 'dtc_probe_result' );
			if ( is_array( $res ) && $res['token'] === $token ) {
				return array(
					'ok'      => true,
					'message' => 'Background requests work: a loopback request kept running after it was disconnected.',
				);
			}
		}
		$server = isset( $_SERVER['SERVER_SOFTWARE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['SERVER_SOFTWARE'] ) ) : '';
		return array(
			'ok'      => false,
			'message' => 'Background requests are cut off or blocked (' . ( $server ? $server : 'unknown server' ) . '). Backups and restores will only advance once a minute through cron. On LiteSpeed, check that the "Digifix TotalCare" block is in .htaccess; behind basic auth or a firewall, allow requests from the server to itself.',
		);
	}
}
