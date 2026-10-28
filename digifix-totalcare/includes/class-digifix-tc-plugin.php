<?php
/**
 * Wires services, job handlers and hooks together.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Digifix_TC_Plugin {

	private static $instance;

	public static function instance() {
		if ( ! self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function boot() {
		Digifix_TC_Jobs::register( 'backup', new Digifix_TC_Backup_Service() );
		Digifix_TC_Jobs::register( 'update', new Digifix_TC_Update_Service() );
		Digifix_TC_Jobs::register( 'scan', new Digifix_TC_Scan_Service() );
		Digifix_TC_Jobs::register( 'restore', new Digifix_TC_Restore() );
		Digifix_TC_Jobs::register( 'retention', new Digifix_TC_Retention_Service() );

		Digifix_TC_Scheduler::init();
		Digifix_TC_WPvivid::init();

		add_action( 'wp_ajax_digifix_tc_kick', array( $this, 'ajax_kick' ) );
		add_action( 'wp_ajax_nopriv_digifix_tc_kick', array( $this, 'ajax_kick' ) );
		add_action( 'wp_ajax_digifix_tc_probe', array( $this, 'ajax_probe' ) );
		add_action( 'wp_ajax_nopriv_digifix_tc_probe', array( $this, 'ajax_probe' ) );
		add_action( 'admin_init', array( 'Digifix_TC_Installer', 'maybe_upgrade' ) );
		add_action( Digifix_TC_Scheduler::HOOK_DAILY, array( 'Digifix_TC_Installer', 'maybe_upgrade' ), 1 );
		add_action( 'upgrader_process_complete', array( $this, 'after_self_update' ), 10, 2 );
		add_action( 'digifix_tc_after_self_update', array( 'Digifix_TC_Installer', 'maybe_upgrade' ) );
		add_filter( 'auto_update_plugin', array( $this, 'defer_auto_update' ), 20, 2 );
		add_filter( 'upgrader_pre_install', array( $this, 'block_update_during_restore' ), 10, 2 );

		if ( is_admin() ) {
			Digifix_TC_Admin::init();
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'totalcare', 'Digifix_TC_CLI' );
		}
	}

	/**
	 * The process that ran the update still has the old code loaded, so
	 * refresh the guardian and tables in a fresh request.
	 */
	public function after_self_update( $upgrader, $extra ) {
		if ( 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}
		$plugins = (array) ( $extra['plugins'] ?? ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() ) );
		if ( in_array( DIGIFIX_TC_BASENAME, $plugins, true ) ) {
			wp_schedule_single_event( time(), 'digifix_tc_after_self_update' );
		}
	}

	/**
	 * Hold back a background auto-update of TotalCare while one of its jobs
	 * or a restore is running, so its code is not replaced mid-run. Whether
	 * auto-updates are on at all stays the site owner's choice.
	 */
	public function defer_auto_update( $update, $item ) {
		if ( ( $item->plugin ?? '' ) === DIGIFIX_TC_BASENAME && ( Digifix_TC_Restore::in_progress() || Digifix_TC_Jobs::active() ) ) {
			return false;
		}
		return $update;
	}

	/**
	 * The guardian runs restores with the engine in this plugin's folder, so
	 * TotalCare itself is not replaced while a restore is running.
	 */
	public function block_update_during_restore( $response, $hook_extra ) {
		if ( ( $hook_extra['plugin'] ?? '' ) === DIGIFIX_TC_BASENAME && Digifix_TC_Restore::in_progress() ) {
			return new WP_Error( 'digifix_tc_restore_running', 'Digifix TotalCare cannot be updated while a site restore is running. Try again when the restore has finished.' );
		}
		return $response;
	}

	/**
	 * Loopback endpoint that advances the job engine immediately. Only this
	 * site can call it: see Digifix_TC_Storage::verify_loopback().
	 */
	public function ajax_kick() {
		Digifix_TC_Storage::verify_loopback( 'digifix_tc_kick' );
		Digifix_TC_Jobs::tick();
		wp_send_json( array( 'ok' => true ) );
	}

	/**
	 * Background request probe: keeps running for a few seconds after the
	 * caller disconnected (as every loopback does). Hosts that kill such
	 * requests (LiteSpeed without "noabort") never reach the end.
	 */
	public function ajax_probe() {
		Digifix_TC_Storage::verify_loopback( 'digifix_tc_probe' );
		ignore_user_abort( true );
		$token = sanitize_key( wp_unslash( $_POST['token'] ?? '' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signature checked by verify_loopback() above.
		sleep( 6 );
		update_option( 'digifix_tc_probe_result', array( 'token' => $token, 'time' => time() ), false );
		wp_send_json( array( 'ok' => true ) );
	}

	/** @return array{ok:bool,message:string} */
	public static function probe_background_requests() {
		$token = strtolower( wp_generate_password( 12, false ) );
		delete_option( 'digifix_tc_probe_result' );
		Digifix_TC_Storage::loopback( 'digifix_tc_probe', array( 'token' => $token ) );
		for ( $i = 0; $i < 12; $i++ ) {
			sleep( 1 );
			foreach ( array( 'digifix_tc_probe_result', 'alloptions', 'notoptions' ) as $key ) {
				wp_cache_delete( $key, 'options' );
			}
			$res = get_option( 'digifix_tc_probe_result' );
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
