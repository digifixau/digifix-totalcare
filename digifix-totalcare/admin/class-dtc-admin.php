<?php
/**
 * Admin pages: Dashboard, Backups, Activity log, Reports, Settings.
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

		foreach ( array( 'run', 'cancel', 'restore', 'save_settings', 'test_email', 'test_webhook', 'report', 'clear_skip', 'pin', 'refresh_backups', 'take_over', 'probe' ) as $action ) {
			add_action( 'admin_post_dtc_' . $action, array( __CLASS__, 'handle_' . $action ) );
		}
		add_filter( 'plugin_action_links_' . DTC_BASENAME, array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_menu_page( 'TotalCare', 'TotalCare', self::CAP, 'dtc-dashboard', array( __CLASS__, 'page_dashboard' ), 'dashicons-shield-alt', 3 );
		add_submenu_page( 'dtc-dashboard', 'TotalCare Dashboard', 'Dashboard', self::CAP, 'dtc-dashboard', array( __CLASS__, 'page_dashboard' ) );
		add_submenu_page( 'dtc-dashboard', 'TotalCare Backups', 'Backups', self::CAP, 'dtc-backups', array( __CLASS__, 'page_backups' ) );
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
			$dtc_url = DTC_Restore::status_url();
			echo '<div class="notice notice-warning"><p><strong>A site restore is in progress.</strong> Scheduled jobs are paused until it finishes.' . ( $dtc_url ? ' <a href="' . esc_url( $dtc_url ) . '" target="_blank">Follow its progress</a> (this page keeps working while the site shows the maintenance page).' : '' ) . '</p></div>';
		}
	}

	private static function back( $page = 'dtc-dashboard', array $args = array() ) {
		wp_safe_redirect( add_query_arg( $args, admin_url( 'admin.php?page=' . $page ) ) );
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
		if ( 'backup' === $type ) {
			$job = DTC_Backup_Service::queue( 'manual', ! empty( $_POST['full'] ) );
			self::flash( 'success', ( ! empty( $_POST['full'] ) ? 'Full backup' : 'Backup' ) . ' queued (job #' . ( $job ? $job->id : '?' ) . '). Progress shows on the dashboard.' );
		} elseif ( isset( $map[ $type ] ) ) {
			$job = call_user_func( array( $map[ $type ][0], 'queue' ), 'manual' );
			self::flash( 'success', $map[ $type ][1] . ' queued (job #' . ( $job ? $job->id : '?' ) . '). Progress shows on this page.' );
		}
		self::back( sanitize_key( $_POST['return'] ?? '' ) === 'dtc-backups' ? 'dtc-backups' : 'dtc-dashboard' );
	}

	public static function handle_cancel() {
		self::guard( 'dtc_cancel' );
		DTC_Jobs::cancel( (int) ( $_POST['job_id'] ?? 0 ) );
		self::flash( 'success', 'Job cancelled. Unfinished uploads are removed; a Wordfence scan or WPvivid task that already started finishes on its own.' );
		self::back();
	}

	public static function handle_restore() {
		self::guard( 'dtc_restore' );
		$backup_id = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );
		$folder    = isset( $_POST['folder'] ) && '' !== $_POST['folder'] ? DTC_Bk_Util::clean_folder( wp_unslash( $_POST['folder'] ) ) : null;

		if ( 'wpvivid' === ( $_POST['engine'] ?? '' ) ) {
			if ( ! DTC_WPvivid::get_backup( $backup_id ) ) {
				self::flash( 'error', 'Backup not found.' );
				self::back();
			}
			DTC_Restore::start_manual( $backup_id, array( 'engine' => 'wpvivid' ) );
			DTC_Jobs::kick();
			self::flash( 'warning', 'Restore of WPvivid backup ' . $backup_id . ' queued. The site will be briefly unavailable while it runs.' );
			self::back();
		}

		if ( ! DTC_Bk_Remote::parse_id( $backup_id ) ) {
			self::flash( 'error', 'Invalid backup id.' );
			self::back( 'dtc-backups' );
		}
		$scope = array_values( array_intersect( DTC_Restore::ALL_SCOPE, array_map( 'sanitize_key', (array) ( $_POST['scope'] ?? array() ) ) ) );
		if ( ! $scope ) {
			self::flash( 'error', 'Choose at least one part of the site to restore.' );
			self::back( 'dtc-backups', $folder ? array( 'folder' => $folder ) : array() );
		}
		$paths = array_filter( array_map( 'trim', preg_split( '/\R/', sanitize_textarea_field( wp_unslash( $_POST['paths'] ?? '' ) ) ) ) );
		DTC_Restore::start_manual(
			$backup_id,
			array(
				'scope'    => $scope,
				'paths'    => array_map(
					function ( $p ) {
						return trim( $p, '/' );
					},
					$paths
				),
				'clean'    => ! empty( $_POST['clean'] ),
				'htaccess' => ! empty( $_POST['htaccess'] ),
				'folder'   => $folder,
			)
		);
		DTC_Jobs::kick();
		self::flash( 'warning', 'Restore of backup ' . $backup_id . ' queued. It starts within a minute; a link to follow its progress appears at the top of this page.' );
		self::back( 'dtc-backups' );
	}

	public static function handle_pin() {
		self::guard( 'dtc_pin' );
		$id     = sanitize_text_field( wp_unslash( $_POST['backup_id'] ?? '' ) );
		$remote = DTC_Bk_Remote::from_settings();
		$res    = is_wp_error( $remote ) ? $remote : ( DTC_Bk_Remote::parse_id( $id ) ? $remote->pin( $id, ! empty( $_POST['pinned'] ) ) : new WP_Error( 'dtc', 'Invalid backup id.' ) );
		self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : ( ! empty( $_POST['pinned'] ) ? 'Backup pinned: it is never deleted by retention.' : 'Backup unpinned.' ) );
		self::clear_catalog_cache();
		self::back( 'dtc-backups' );
	}

	public static function handle_refresh_backups() {
		self::guard( 'dtc_refresh_backups' );
		$folder = isset( $_POST['folder'] ) && '' !== $_POST['folder'] ? DTC_Bk_Util::clean_folder( wp_unslash( $_POST['folder'] ) ) : null;
		$remote = DTC_Bk_Remote::from_settings( $folder );
		$res    = is_wp_error( $remote ) ? $remote : $remote->rebuild_catalog();
		self::clear_catalog_cache();
		self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : 'Backup list rebuilt from storage (' . count( $res ) . ' backups).' );
		self::back( 'dtc-backups', $folder ? array( 'folder' => $folder ) : array() );
	}

	public static function handle_take_over() {
		self::guard( 'dtc_take_over' );
		$remote = DTC_Bk_Remote::from_settings();
		$res    = is_wp_error( $remote ) ? $remote : $remote->claim();
		if ( ! is_wp_error( $res ) ) {
			DTC_Logger::log( 'system', 'warning', 'system.folder_claimed', 'Backup folder "' . $remote->folder . '" taken over by ' . home_url() . '.' );
		}
		self::flash( is_wp_error( $res ) ? 'error' : 'success', is_wp_error( $res ) ? $res->get_error_message() : 'This site now owns the backup folder.' );
		self::back( 'dtc-backups' );
	}

	/** Backup lists are cached for a few minutes; bumping the version drops them. */
	public static function clear_catalog_cache() {
		update_option( 'dtc_catalog_ver', time(), false );
	}

	/**
	 * Backups in a folder, cached for 10 minutes.
	 *
	 * @return array|WP_Error
	 */
	public static function catalog( DTC_Bk_Remote $remote ) {
		$key     = 'dtc_catalog_' . md5( $remote->folder . '|' . get_option( 'dtc_catalog_ver', 0 ) );
		$entries = get_transient( $key );
		if ( ! is_array( $entries ) ) {
			$entries = $remote->catalog();
			if ( is_wp_error( $entries ) ) {
				return $entries;
			}
			set_transient( $key, $entries, 10 * MINUTE_IN_SECONDS );
		}
		return $entries;
	}

	public static function handle_probe() {
		self::guard( 'dtc_probe' );
		$res = DTC_Plugin::probe_background_requests();
		self::flash( $res['ok'] ? 'success' : 'error', $res['message'] );
		self::back( 'dtc-settings' );
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
		foreach ( array( 's3_type', 's3_access', 's3_bucket', 's3_path', 's3_endpoint', 's3_region', 's3_path_style' ) as $key ) {
			$s3_changed = $s3_changed || (string) $before[ $key ] !== (string) $after[ $key ];
		}

		if ( '' !== $secret ) {
			DTC_Storage::secret();
			$stored = DTC_Bk_Util::encrypt( $secret );
			if ( '' === $stored ) {
				self::flash( 'error', 'Settings saved, but the S3 secret could not be stored: PHP has neither OpenSSL nor Sodium.' );
				self::back( 'dtc-settings' );
			}
			update_option( 'dtc_s3_secret', $stored, false );
		}

		$notes = array();
		$level = 'success';
		if ( ( '' !== $secret || $s3_changed ) && $after['s3_access'] && $after['s3_bucket'] ) {
			if ( 'amazons3' === $after['s3_type'] ) {
				$region = DTC_Bk_S3::detect_region( $after['s3_bucket'] );
				if ( $region && $region !== $after['s3_region'] ) {
					$after['s3_region'] = $region;
					update_option( DTC_Settings::OPTION, $after, false );
				}
			}
			$remote = DTC_Bk_Remote::from_settings();
			$test   = is_wp_error( $remote ) ? $remote : $remote->test();
			if ( is_wp_error( $test ) ) {
				$level   = 'error';
				$notes[] = 'Settings saved, but the storage connection does not work yet: ' . $test->get_error_message();
			} else {
				$owner = $remote->check_owner();
				DTC_Logger::log( 'system', 'success', 'system.s3_configured', 'Remote storage tested: bucket ' . $after['s3_bucket'] . ', folder ' . $remote->folder . '.' );
				$notes[] = 'Settings saved. Storage connection tested (upload, download, list, multipart, delete).';
				if ( is_wp_error( $owner ) ) {
					$level   = 'warning';
					$notes[] = $owner->get_error_message();
				}
			}
			if ( '' !== $secret && 'wpvivid' === $after['backup_engine'] && DTC_WPvivid::is_available() ) {
				$res = DTC_WPvivid::configure_remote( $after, $secret );
				if ( is_wp_error( $res ) ) {
					$level   = 'error';
					$notes[] = 'WPvivid was not configured: ' . $res->get_error_message();
				}
			}
		} elseif ( $s3_changed && ! DTC_Bk_Util::s3_secret() ) {
			$level   = 'warning';
			$notes[] = 'Settings saved. Enter the S3 secret key to finish setting up remote storage.';
		} else {
			$notes[] = 'Settings saved.';
		}
		self::flash( $level, implode( ' ', $notes ) );
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

	public static function page_backups() {
		include DTC_DIR . 'admin/views/backups.php';
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
