<?php
/**
 * WPvivid Backup (free) integration. WPvivid has no public API, so this class
 * wraps the internal classes verified against WPvivid 0.9.136:
 *  - WPvivid_Backup_2::pre_new_backup() + cron hook wpvivid_backup_2_schedule_event
 *  - actions wpvivid_handle_backup_2_succeed / wpvivid_handle_backup_2_failed
 *  - WPvivid_Remote_collection::add_remote() / update_remote()
 *  - WPvivid_Backuplist, WPvivid_taskmanager, WPvivid_downloader
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_WPvivid {

	const RESULTS_OPTION = 'dtc_wpvivid_results';
	const REMOTE_NAME    = 'Digifix TotalCare S3';
	const TESTED_UP_TO   = '0.9.136';

	public static function init() {
		add_action( 'wpvivid_handle_backup_2_succeed', array( __CLASS__, 'on_succeed' ), 20 );
		add_action( 'wpvivid_handle_backup_2_failed', array( __CLASS__, 'on_failed' ), 20 );
		add_filter( 'wpvivid_remote_register', array( __CLASS__, 'register_r2' ), 20 );
		add_filter( 'wpvivid_storage_provider_tran', array( __CLASS__, 'r2_label' ), 20 );
	}

	/** Runs inside WPvivid_Remote_collection, after Wpvivid_S3Compat is defined. */
	public static function register_r2( $collection ) {
		if ( ! class_exists( 'Wpvivid_S3Compat' ) ) {
			return $collection;
		}
		require_once DTC_DIR . 'includes/integrations/class-dtc-wpvivid-r2.php';
		return DTC_WPvivid_R2::register( $collection );
	}

	public static function r2_label( $type ) {
		return 'dtc_r2' === $type ? 'Cloudflare R2' : $type;
	}

	public static function is_available() {
		global $wpvivid_plugin;
		return class_exists( 'WPvivid_Backup_2' )
			&& class_exists( 'WPvivid_Backuplist' )
			&& class_exists( 'WPvivid_taskmanager' )
			&& is_object( $wpvivid_plugin )
			&& isset( $wpvivid_plugin->backup2 )
			&& method_exists( $wpvivid_plugin->backup2, 'pre_new_backup' );
	}

	public static function version() {
		return defined( 'WPVIVID_PLUGIN_VERSION' ) ? WPVIVID_PLUGIN_VERSION : null;
	}

	public static function status() {
		if ( ! self::is_available() ) {
			return array(
				'ok'      => false,
				'message' => 'WPvivid Backup is not active or its internal API has changed.',
			);
		}
		$remote = self::get_remote();
		return array(
			'ok'      => (bool) $remote,
			'message' => $remote ? 'WPvivid ' . self::version() . ', S3 remote "' . $remote['name'] . '" configured.' : 'WPvivid ' . self::version() . ' active, but no S3 remote configured.',
			'warning' => version_compare( (string) self::version(), self::TESTED_UP_TO, '>' ) ? 'WPvivid is newer than the tested version (' . self::TESTED_UP_TO . ').' : '',
		);
	}

	/* ---------------------------------------------------------------------
	 * Remote storage
	 * ------------------------------------------------------------------ */

	public static function get_remote() {
		if ( ! class_exists( 'WPvivid_Setting' ) ) {
			return null;
		}
		$id      = DTC_Settings::get( 's3_remote_id' );
		$remotes = WPvivid_Setting::get_all_remote_options();
		if ( $id && isset( $remotes[ $id ] ) ) {
			return array_merge( $remotes[ $id ], array( 'id' => $id ) );
		}
		foreach ( (array) $remotes as $rid => $remote ) {
			if ( is_array( $remote ) && isset( $remote['name'] ) && self::REMOTE_NAME === $remote['name'] ) {
				return array_merge( $remote, array( 'id' => $rid ) );
			}
		}
		return null;
	}

	/**
	 * Create or update the WPvivid S3 remote and make it the default.
	 * WPvivid runs a test upload before saving.
	 *
	 * @return true|WP_Error
	 */
	public static function configure_remote( array $settings, $secret ) {
		global $wpvivid_plugin;
		if ( ! self::is_available() || empty( $wpvivid_plugin->remote_collection ) ) {
			return new WP_Error( 'dtc_wpvivid', 'WPvivid Backup is not active.' );
		}

		$cfg = array(
			'type'    => 'r2' === $settings['s3_type'] ? 'dtc_r2' : $settings['s3_type'],
			'name'    => self::REMOTE_NAME,
			'access'  => $settings['s3_access'],
			'secret'  => $secret,
			'bucket'  => $settings['s3_bucket'],
			'path'    => $settings['s3_path'] ? $settings['s3_path'] : sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ),
			'default' => 1,
		);
		if ( 'amazons3' === $settings['s3_type'] ) {
			$cfg['classMode'] = 0;
			$cfg['sse']       = 0;
		} elseif ( 'r2' === $settings['s3_type'] ) {
			if ( ! class_exists( 'DTC_WPvivid_R2' ) ) {
				return new WP_Error( 'dtc_wpvivid', 'Cloudflare R2 support could not be loaded into WPvivid.' );
			}
			$cfg['endpoint'] = DTC_WPvivid_R2::normalize_endpoint( $settings['s3_endpoint'] );
			if ( ! preg_match( '/\.r2\.cloudflarestorage\.com$/', $cfg['endpoint'] ) ) {
				return new WP_Error( 'dtc_wpvivid', 'Enter your Cloudflare account ID or the R2 S3 API URL (https://<account-id>.r2.cloudflarestorage.com).' );
			}
		} else {
			if ( empty( $settings['s3_endpoint'] ) ) {
				return new WP_Error( 'dtc_wpvivid', 'An endpoint is required for S3-compatible storage.' );
			}
			$cfg['endpoint'] = $settings['s3_endpoint'];
		}

		$existing = self::get_remote();
		if ( $existing ) {
			$ret = $wpvivid_plugin->remote_collection->update_remote( $existing['id'], $cfg );
			$id  = $existing['id'];
		} else {
			$ret = $wpvivid_plugin->remote_collection->add_remote( $cfg );
			$id  = null;
		}

		if ( empty( $ret['result'] ) || 'success' !== $ret['result'] ) {
			return new WP_Error( 'dtc_wpvivid', 'WPvivid rejected the S3 settings: ' . ( $ret['error'] ?? 'unknown error' ) );
		}

		if ( ! $id ) {
			DTC_Settings::update( array( 's3_remote_id' => '' ) );
			$remote = self::get_remote();
			$id     = $remote ? $remote['id'] : '';
		}
		WPvivid_Setting::update_user_history( 'remote_selected', array( $id ) );
		DTC_Settings::update( array( 's3_remote_id' => $id ) );

		self::disable_own_schedule();
		return true;
	}

	/** TotalCare owns the schedule; stop WPvivid running a second backup. */
	public static function disable_own_schedule() {
		$schedule = get_option( 'wpvivid_schedule_setting', array() );
		if ( is_array( $schedule ) && ! empty( $schedule['enable'] ) ) {
			$schedule['enable'] = 0;
			update_option( 'wpvivid_schedule_setting', $schedule, 'no' );
		}
		if ( defined( 'WPVIVID_MAIN_SCHEDULE_EVENT' ) ) {
			wp_clear_scheduled_hook( WPVIVID_MAIN_SCHEDULE_EVENT );
		}
	}

	/* ---------------------------------------------------------------------
	 * Backups
	 * ------------------------------------------------------------------ */

	/**
	 * Start a files+db backup sent to the configured S3 remote.
	 *
	 * @param bool $keep_local Keep a local copy too (used before updates so a
	 *                         restore does not need to download from S3).
	 * @return string|WP_Error WPvivid task id (= backup id).
	 */
	public static function start_backup( $keep_local = false ) {
		global $wpvivid_plugin, $wpdb;
		if ( ! self::is_available() ) {
			return new WP_Error( 'dtc_wpvivid', 'WPvivid Backup is not active.' );
		}
		$remote = self::get_remote();
		if ( ! $remote ) {
			return new WP_Error( 'dtc_wpvivid', 'No S3 remote configured in TotalCare settings.' );
		}
		$remote_id = $remote['id'];
		unset( $remote['id'] );

		$ret = $wpvivid_plugin->backup2->pre_new_backup(
			array(
				'backup_files'   => 'files+db',
				'local'          => $keep_local ? '1' : '0',
				'remote'         => '1',
				'remote_options' => array( $remote_id => $remote ),
				'type'           => 'Cron',
				'lock'           => 0,
				// Keep TotalCare's own log/job tables out of the backup so a
				// restore does not rewind the running update job.
				'exclude-tables' => array( $wpdb->prefix . 'dtc_jobs', $wpdb->prefix . 'dtc_events' ),
				// Guardian state and rollback copies must not be overwritten by a
				// restore (and the copies would bloat every backup).
				'exclude_files'  => array(
					array( 'type' => 'folder', 'path' => WP_CONTENT_DIR . '/dtc-data' ),
					array( 'type' => 'folder', 'path' => WP_CONTENT_DIR . '/dtc-rollback' ),
				),
			)
		);

		if ( empty( $ret['result'] ) || 'success' !== $ret['result'] ) {
			$code = self::is_backup_running() ? 'dtc_wpvivid_busy' : 'dtc_wpvivid';
			return new WP_Error( $code, $ret['error'] ?? 'WPvivid could not create the backup task.' );
		}

		$task_id = $ret['task_id'];
		wp_schedule_single_event( time(), 'wpvivid_backup_2_schedule_event', array( $task_id ) );
		if ( ! wp_doing_cron() ) {
			spawn_cron();
		}
		return $task_id;
	}

	public static function is_backup_running() {
		global $wpvivid_plugin;
		return self::is_available() && method_exists( $wpvivid_plugin->backup2, 'is_tasks_backup_running' ) && $wpvivid_plugin->backup2->is_tasks_backup_running();
	}

	public static function on_succeed( $task_id ) {
		self::store_result( $task_id, 'completed', '' );
	}

	public static function on_failed( $task_id ) {
		$task  = WPvivid_taskmanager::get_task( $task_id );
		$error = is_array( $task ) && ! empty( $task['status']['error'] ) ? $task['status']['error'] : 'Backup failed.';
		self::store_result( $task_id, 'failed', $error );
	}

	private static function store_result( $task_id, $status, $error ) {
		$results             = get_option( self::RESULTS_OPTION, array() );
		$results[ $task_id ] = array(
			'status' => $status,
			'error'  => $error,
			'time'   => time(),
		);
		update_option( self::RESULTS_OPTION, array_slice( $results, -20, null, true ), false );
	}

	/**
	 * @return array{status:string,error:string,progress:string}
	 *               status: running|completed|failed|unknown
	 */
	public static function backup_status( $task_id ) {
		$results = get_option( self::RESULTS_OPTION, array() );
		if ( isset( $results[ $task_id ] ) ) {
			return array(
				'status'   => $results[ $task_id ]['status'],
				'error'    => $results[ $task_id ]['error'],
				'progress' => '',
			);
		}

		$task = class_exists( 'WPvivid_taskmanager' ) ? WPvivid_taskmanager::get_task( $task_id ) : false;
		if ( is_array( $task ) && isset( $task['status']['str'] ) ) {
			$str = $task['status']['str'];
			if ( 'completed' === $str ) {
				return array( 'status' => 'completed', 'error' => '', 'progress' => '100%' );
			}
			if ( in_array( $str, array( 'error', 'cancel' ), true ) ) {
				return array( 'status' => 'failed', 'error' => $task['status']['error'] ?? $str, 'progress' => '' );
			}
			$progress = '';
			if ( class_exists( 'WPvivid_Backup_Task_2' ) ) {
				$info     = ( new WPvivid_Backup_Task_2( $task_id ) )->get_backup_task_info();
				$progress = is_array( $info ) && isset( $info['task_info']['descript'] ) ? wp_strip_all_tags( $info['task_info']['descript'] ) : '';
			}
			return array( 'status' => 'running', 'error' => '', 'progress' => $progress ?: $str );
		}

		// Task record was cleaned up; fall back to the backup list.
		if ( self::get_backup( $task_id ) ) {
			return array( 'status' => 'completed', 'error' => '', 'progress' => '' );
		}
		return array( 'status' => 'unknown', 'error' => '', 'progress' => '' );
	}

	public static function get_backup( $backup_id ) {
		return class_exists( 'WPvivid_Backuplist' ) ? WPvivid_Backuplist::get_backup_by_id( $backup_id ) : false;
	}

	public static function latest_backup() {
		if ( ! class_exists( 'WPvivid_Backuplist' ) ) {
			return null;
		}
		$list = WPvivid_Backuplist::get_backuplist();
		if ( empty( $list ) ) {
			return null;
		}
		$id = array_key_first( $list );
		return array_merge( $list[ $id ], array( 'id' => $id ) );
	}

	/** Compact description of a backup for logs and reports. */
	public static function summarize( $backup_id ) {
		$backup = self::get_backup( $backup_id );
		if ( ! $backup ) {
			return array( 'backup_id' => $backup_id );
		}
		$files = isset( $backup['backup']['files'] ) ? (array) $backup['backup']['files'] : array();
		$size  = array_sum( wp_list_pluck( $files, 'size' ) );
		$names = array();
		foreach ( (array) ( $backup['remote'] ?? array() ) as $remote ) {
			$names[] = ( $remote['name'] ?? '' ) . ' (' . ( $remote['type'] ?? '' ) . ( isset( $remote['bucket'] ) ? ': ' . $remote['bucket'] : '' ) . ')';
		}
		return array(
			'backup_id'  => $backup_id,
			'created'    => (int) ( $backup['create_time'] ?? 0 ),
			'size'       => (int) $size,
			'size_human' => size_format( $size, 1 ),
			'files'      => wp_list_pluck( $files, 'file_name' ),
			'remote'     => $names,
			'local'      => ! empty( $backup['save_local'] ),
		);
	}

	/**
	 * Make sure every archive of a backup exists locally, downloading from the
	 * remote when needed. Blocking; call from a job step.
	 *
	 * @return true|WP_Error
	 */
	public static function ensure_local_files( $backup_id ) {
		$backup = self::get_backup( $backup_id );
		if ( ! $backup ) {
			return new WP_Error( 'dtc_wpvivid', 'Backup ' . $backup_id . ' is not in the WPvivid backup list.' );
		}
		if ( ! class_exists( 'WPvivid_Backup_Item' ) ) {
			return new WP_Error( 'dtc_wpvivid', 'WPvivid backup classes not loaded.' );
		}
		$item  = new WPvivid_Backup_Item( $backup );
		$files = isset( $backup['backup']['files'] ) ? (array) $backup['backup']['files'] : array();

		foreach ( $files as $file ) {
			$path = $item->get_local_path() . $file['file_name'];
			if ( file_exists( $path ) && filesize( $path ) == $file['size'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
				continue;
			}
			if ( ! class_exists( 'WPvivid_downloader' ) ) {
				include_once WPVIVID_PLUGIN_DIR . '/includes/class-wpvivid-downloader.php';
			}
			$downloader = new WPvivid_downloader();
			$downloader->ready_download(
				array(
					'backup_id' => $backup_id,
					'file_name' => $file['file_name'],
				)
			);
			clearstatcache();
			if ( ! file_exists( $path ) || filesize( $path ) != $file['size'] ) { // phpcs:ignore Universal.Operators.StrictComparisons.LooseNotEqual
				return new WP_Error( 'dtc_wpvivid', 'Could not download ' . $file['file_name'] . ' from remote storage.' );
			}
		}
		return true;
	}
}
