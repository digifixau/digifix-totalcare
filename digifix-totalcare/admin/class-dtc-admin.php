<?php
/**
 * Admin pages: Dashboard, Activity log, Reports, Settings.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Admin {

	const CAP = 'manage_options';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_init', array( 'DTC_Restore', 'reconcile' ) );

		foreach ( array( 'run', 'cancel', 'restore', 'save_settings', 'test_email', 'test_webhook', 'report', 'clear_skip' ) as $action ) {
			add_action( 'admin_post_dtc_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . DTC_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( 'TotalCare', 'TotalCare', self::CAP, 'dtc-dashboard', array( __CLASS__, 'page_dashboard' ), 'dashicons-shield-alt', 3 );
		add_submenu_page( 'dtc-dashboard', 'TotalCare Dashboard', 'Dashboard', self::CAP, 'dtc-dashboard', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'dtc-dashboard', 'TotalCare Activity Log', 'Activity Log', self::CAP, 'dtc-log', array( __CLASS__, 'page_log' ) );
		add_submenu_page( 'dtc-dashboard', 'TotalCare Reports', 'Reports', self::CAP, 'dtc-reports', array( __CLASS__, 'page_reports' ) );
		add_submenu_page( 'dtc-dashboard', 'TotalCare Settings', 'Settings', self::CAP, 'dtc-settings', array( __CLASS__, 'page_settings' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=dtc-settings' ) ) . '">Settings</a>' );
		return $links;
	}

	public static function assets( $hook ) {
		if ( false === strpos( (string) $hook, 'dtc-' ) ) {
			return;
		}
		wp_enqueue_style( 'dtc-admin', DTC_URL . 'admin/admin.css', array(), DTC_VERSION );
	}

	/* ---------------------------------------------------------------------
	 * Notices
	 * ------------------------------------------------------------------ */

	private static function flash( $type, $message ) {
		set_transient( 'dtc_notice_' . get_current_user_id(), array( $type, $message ), 60 );
	}

	public static function notices() {
		$notice = get_transient( 'dtc_notice_' . get_current_user_id() );
		if ( $notice ) {
			delete_transient( 'dtc_notice_' . get_current_user_id() );
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $notice[0] ), esc_html( $notice[1] ) );
		}

		$screen = get_current_screen();
		if ( ! $screen || false === strpos( $screen->id, 'dtc-' ) ) {
			return;
		}
		if ( DTC_Restore::in_progress() ) {
			echo '<div class="notice notice-warning"><p><strong>A full site restore is in progress.</strong> Scheduled jobs are paused until it finishes.</p></div>';
		}
	}

	private static function back( $page = 'dtc-dashboard' ) {
		wp_safe_redirect( admin_url( 'admin.php?page=' . $page ) );
		exit;
	}

	private static function guard( $action ) {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( $action );
	}

	/* ---------------------------------------------------------------------
	 * Actions
	 * ------------------------------------------------------------------ */

	public static function handle_run() {
		self::guard( 'dtc_run' );
		$type = sanitize_key( $_POST['type'] ?? '' );
		$map  = array(
			'backup' => array( 'DTC_Backup_Service', 'Backup' ),
			'update' => array( 'DTC_Update_Service', 'Update run' ),
			'scan'   => array( 'DTC_Scan_Service', 'Malware scan' ),
		);
		if ( isset( $map[ $type ] ) ) {
			$job = call_user_func( array( $map[ $type ][0], 'queue' ), 'manual' );
			self::flash( 'success', $map[ $type ][1] . ' queued (job #' . ( $job ? $job->id : '?' ) . '). Progress shows on this page.' );
		}
		self::back();
	}

	public static function handle_cancel() {
		self::guard( 'dtc_cancel' );
		DTC_Jobs::cancel( (int) ( $_POST['job_id'] ?? 0 ) );
		self::flash( 'success', 'Job cancelled. Anything already running inside WPvivid or Wordfence will finish on its own.' );
		self::back();
	}

	public static function handle_restore() {
		self::guard( 'dtc_restore' );
		$backup_id = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );
		if ( ! DTC_WPvivid::get_backup( $backup_id ) ) {
			self::flash( 'error', 'Backup not found.' );
		} else {
			DTC_Restore::start_manual( $backup_id );
			DTC_Jobs::kick();
			self::flash( 'warning', 'Restore of backup ' . $backup_id . ' queued. The site will be briefly unavailable while it runs.' );
		}
		self::back();
	}

	public static function handle_clear_skip() {
		self::guard( 'dtc_clear_skip' );
		delete_option( DTC_Update_Service::SKIP_OPTION );
		self::flash( 'success', 'Blocked versions cleared; they will be retried in the next update run.' );
		self::back();
	}

	public static function handle_test_email() {
		self::guard( 'dtc_test_email' );
		DTC_Notifier::notify( 'test' );
		self::flash( 'success', 'Test email sent to the internal and client recipients. Check the Activity Log if it does not arrive.' );
		self::back( 'dtc-settings' );
	}

	public static function handle_test_webhook() {
		self::guard( 'dtc_test_webhook' );
		$ok = DTC_Webhook::send( 'test', array( 'message' => 'Webhook delivery from Digifix TotalCare works.' ) );
		self::flash( $ok ? 'success' : 'error', $ok ? 'Webhook delivered.' : 'Webhook failed; see the Activity Log.' );
		self::back( 'dtc-settings' );
	}

	public static function handle_save_settings() {
		self::guard( 'dtc_save_settings' );
		$input  = wp_unslash( (array) ( $_POST['dtc'] ?? array() ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in DTC_Settings::sanitize().
		$before = DTC_Settings::all();
		$after  = DTC_Settings::sanitize( $input );
		update_option( DTC_Settings::OPTION, $after, false );
		DTC_Scheduler::reschedule();

		$secret     = isset( $input['s3_secret'] ) ? trim( (string) $input['s3_secret'] ) : '';
		$s3_changed = false;
		foreach ( array( 's3_type', 's3_access', 's3_bucket', 's3_path', 's3_endpoint' ) as $key ) {
			$s3_changed = $s3_changed || $before[ $key ] !== $after[ $key ];
		}

		if ( '' !== $secret ) {
			$res = DTC_WPvivid::configure_remote( $after, $secret );
			if ( is_wp_error( $res ) ) {
				self::flash( 'error', 'Settings saved, but S3 was not configured: ' . $res->get_error_message() );
			} else {
				DTC_Logger::log( 'system', 'success', 'system.s3_configured', 'S3 remote storage configured and tested (bucket ' . $after['s3_bucket'] . ').' );
				self::flash( 'success', 'Settings saved. S3 connection tested and set as the WPvivid backup destination.' );
			}
		} elseif ( $s3_changed && $after['s3_access'] ) {
			self::flash( 'warning', 'Settings saved. Enter the S3 secret key again to apply the changed S3 details.' );
		} else {
			self::flash( 'success', 'Settings saved.' );
		}
		self::back( 'dtc-settings' );
	}

	public static function handle_report() {
		self::guard( 'dtc_report' );
		$month = preg_match( '/^\d{4}-\d{2}$/', $_POST['month'] ?? '' ) ? sanitize_text_field( wp_unslash( $_POST['month'] ) ) : wp_date( 'Y-m' );
		$tz    = wp_timezone();
		$start = new DateTimeImmutable( $month . '-01 00:00:00', $tz );
		$end   = $start->modify( 'first day of next month' );
		$rep   = DTC_Report_Builder::build( $start, $end );
		$mode  = sanitize_key( $_POST['mode'] ?? 'html' );

		if ( 'email' === $mode ) {
			DTC_Notifier::notify( 'report.monthly', array( 'report' => $rep ) );
			self::flash( 'success', 'Report for ' . $rep['period']['label'] . ' emailed.' );
			self::back( 'dtc-reports' );
		}
		if ( 'pdf' === $mode ) {
			$file = DTC_PDF::generate( $rep );
			if ( $file ) {
				nocache_headers();
				header( 'Content-Type: application/pdf' );
				header( 'Content-Disposition: attachment; filename="' . basename( $file ) . '"' );
				header( 'Content-Length: ' . filesize( $file ) );
				readfile( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
				exit;
			}
		}
		nocache_headers();
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template escapes its output.
		echo str_replace( '</body>', '<p class="noprint" style="text-align:center;margin:20px"><button onclick="window.print()">Print / Save as PDF</button></p></body>', DTC_PDF::render_html( $rep ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Pages
	 * ------------------------------------------------------------------ */

	private static function form( $action, $label, array $fields = array(), $class = 'button', $confirm = '' ) {
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="dtc-inline"' . ( $confirm ? ' onsubmit="return confirm(' . esc_attr( wp_json_encode( $confirm ) ) . ');"' : '' ) . '>';
		echo '<input type="hidden" name="action" value="' . esc_attr( $action ) . '">';
		wp_nonce_field( $action );
		foreach ( $fields as $name => $value ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( $value ) . '">';
		}
		echo '<button type="submit" class="' . esc_attr( $class ) . '">' . esc_html( $label ) . '</button></form>';
	}

	private static function when( $mysql_gmt_or_ts ) {
		if ( ! $mysql_gmt_or_ts ) {
			return '—';
		}
		$ts = is_numeric( $mysql_gmt_or_ts ) ? (int) $mysql_gmt_or_ts : strtotime( $mysql_gmt_or_ts . ' UTC' );
		$rel = $ts > time() ? 'in ' . human_time_diff( $ts ) : human_time_diff( $ts ) . ' ago';
		return wp_date( 'D j M, g:i a', $ts ) . ' (' . $rel . ')';
	}

	private static function badge( $level, $text ) {
		return '<span class="dtc-badge dtc-' . esc_attr( $level ) . '">' . esc_html( $text ) . '</span>';
	}

	public static function page_dashboard() {
		include DTC_DIR . 'admin/views/dashboard.php';
	}

	public static function page_log() {
		include DTC_DIR . 'admin/views/log.php';
	}

	public static function page_reports() {
		include DTC_DIR . 'admin/views/reports.php';
	}

	public static function page_settings() {
		include DTC_DIR . 'admin/views/settings.php';
	}
}
