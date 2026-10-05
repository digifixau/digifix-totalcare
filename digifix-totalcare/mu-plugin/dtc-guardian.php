<?php
/**
 * Plugin Name: Digifix TotalCare Guardian
 * Description: Safety net for Digifix TotalCare. Records PHP fatal errors, rolls back a plugin/theme update that crashes the site, and drives full WPvivid restores while TotalCare is deactivated. Installed and removed automatically by Digifix TotalCare.
 * Version:     1.0.0
 * Author:      Digifix
 *
 * This file must stay self-contained: it runs when TotalCare is deactivated
 * (during a WPvivid restore) and when a broken plugin crashes every request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Can be loaded from mu-plugins or from the fatal-error-handler.php drop-in.
if ( defined( 'DTC_GUARDIAN_VERSION' ) ) {
	return;
}

define( 'DTC_GUARDIAN_VERSION', '1.0.0' );

final class DTC_Guardian {

	const FATAL_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );

	private static $handled = false;
	private static $lock    = null;

	public static function boot() {
		// WordPress's fatal error handler dies after rendering, so hook into
		// its template filter (runs first) and keep a shutdown fallback for
		// sites where the handler is disabled.
		add_filter( 'wp_php_error_message', array( __CLASS__, 'on_wp_fatal' ), 1, 2 );
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );

		add_action( 'wp_ajax_dtc_guardian_health', array( __CLASS__, 'ajax_health' ) );
		add_action( 'wp_ajax_nopriv_dtc_guardian_health', array( __CLASS__, 'ajax_health' ) );
		add_action( 'wp_ajax_dtc_guardian_restore', array( __CLASS__, 'ajax_restore' ) );
		add_action( 'wp_ajax_nopriv_dtc_guardian_restore', array( __CLASS__, 'ajax_restore' ) );

		add_filter( 'wpvivid_enable_plugins_list', array( __CLASS__, 'keep_totalcare_active' ) );
		add_action( 'init', array( __CLASS__, 'watchdog' ), 1 );
	}

	/* ---------------------------------------------------------------------
	 * File storage (mirrors DTC_Storage)
	 * ------------------------------------------------------------------ */

	private static function dir() {
		return WP_CONTENT_DIR . '/dtc-data';
	}

	private static function read( $name ) {
		$file = self::dir() . '/' . $name . '.json';
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$data = json_decode( (string) @file_get_contents( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $data ) ? $data : null;
	}

	private static function write( $name, $data ) {
		if ( ! is_dir( self::dir() ) ) {
			@mkdir( self::dir(), 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$file = self::dir() . '/' . $name . '.json';
		$tmp  = $file . '.' . getmypid() . '.' . mt_rand() . '.tmp';
		if ( false !== @file_put_contents( $tmp, json_encode( $data, JSON_PRETTY_PRINT ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.json_encode_json_encode
			@rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private static function secret() {
		$file = self::dir() . '/secret.php';
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$secret = include $file;
		return is_string( $secret ) && strlen( $secret ) >= 32 ? $secret : null;
	}

	private static function sign( $action, $ts ) {
		$secret = self::secret();
		return $secret ? hash_hmac( 'sha256', $action . '|' . $ts, $secret ) : '';
	}

	private static function verify_request( $action ) {
		$ts  = isset( $_POST['ts'] ) ? (int) $_POST['ts'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$sig = isset( $_POST['sig'] ) ? (string) wp_unslash( $_POST['sig'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$exp = self::sign( $action, $ts );
		return $exp && abs( time() - $ts ) <= 300 && hash_equals( $exp, $sig );
	}

	private static function loopback( $action, $extra = array() ) {
		$ts = time();
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 1,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => array_merge(
					$extra,
					array(
						'action' => $action,
						'ts'     => $ts,
						'sig'    => self::sign( $action, $ts ),
					)
				),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Fatal error capture and crash rollback
	 * ------------------------------------------------------------------ */

	public static function on_wp_fatal( $message, $error ) {
		self::handle_fatal( $error );
		return $message;
	}

	public static function on_shutdown() {
		$error = error_get_last();
		if ( $error ) {
			self::handle_fatal( $error );
		}
		if ( self::$lock ) {
			// A restore request died mid-chunk: release the lock so the
			// watchdog can retry.
			@flock( self::$lock, LOCK_UN ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private static function handle_fatal( $error ) {
		if ( self::$handled || ! is_array( $error ) || ! in_array( $error['type'] ?? 0, self::FATAL_TYPES, true ) ) {
			return;
		}
		self::$handled = true;

		$entry = array(
			'time'    => time(),
			'message' => substr( (string) $error['message'], 0, 500 ),
			'file'    => (string) $error['file'],
			'line'    => (int) $error['line'],
			'url'     => isset( $_SERVER['REQUEST_URI'] ) ? substr( (string) $_SERVER['REQUEST_URI'], 0, 200 ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		);

		$fatals   = (array) self::read( 'fatals' );
		$fatals[] = $entry;
		self::write( 'fatals', array_slice( $fatals, -50 ) );

		self::maybe_rollback_inflight( $entry );

		$req = self::read( 'restore-request' );
		if ( $req && in_array( $req['status'] ?? '', array( 'pending', 'running', 'finishing' ), true ) && defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_POST['action'] ) && 'dtc_guardian_restore' === $_POST['action'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$req['errors'] = (int) ( $req['errors'] ?? 0 ) + 1;
			$req['error']  = 'PHP fatal during restore: ' . $entry['message'];
			if ( $req['errors'] >= 3 ) {
				$req['status'] = 'failing';
			}
			$req['updated'] = time();
			self::write( 'restore-request', $req );
			// Continue (or fail) in a fresh request; this one is about to die.
			self::unlock();
			self::loopback( 'dtc_guardian_restore', array( 'wpvivid_restore' => 1 ) );
		}
	}

	/**
	 * While a plugin/theme update is in flight, any PHP fatal means the new
	 * version is broken: swap the snapshot back immediately so the site
	 * recovers even if no further PHP request can complete.
	 */
	private static function maybe_rollback_inflight( array $fatal ) {
		$inflight = self::read( 'inflight' );
		if ( ! $inflight || 'updating' !== ( $inflight['status'] ?? '' ) ) {
			return;
		}
		if ( time() - (int) $inflight['created'] > HOUR_IN_SECONDS ) {
			return;
		}
		$source   = (string) $inflight['source'];
		$snapshot = (string) $inflight['snapshot'];
		if ( ! $source || ! file_exists( $snapshot ) ) {
			return;
		}

		$failed = $source . '.dtc-failed-' . time();
		if ( file_exists( $source ) && ! @rename( $source, $failed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}
		$ok = is_dir( $snapshot ) ? self::copy_dir( $snapshot, $source ) : @copy( $snapshot, $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $ok ) {
			@rename( $failed, $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$inflight['status']    = 'rolled_back';
		$inflight['fatal']     = $fatal;
		$inflight['rolled_at'] = time();
		$inflight['failed_copy'] = $failed;
		self::write( 'inflight', $inflight );
	}

	private static function copy_dir( $src, $dst ) {
		if ( ! is_dir( $dst ) && ! @mkdir( $dst, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $item ) {
			$target = $dst . '/' . substr( $item->getPathname(), strlen( $src ) + 1 );
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) && ! @mkdir( $target, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return false;
				}
			} elseif ( ! @copy( $item->getPathname(), $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return false;
			}
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Health endpoint
	 * ------------------------------------------------------------------ */

	public static function ajax_health() {
		if ( ! self::verify_request( 'dtc_guardian_health' ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		$since  = isset( $_POST['since'] ) ? (int) $_POST['since'] : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$fatals = array_values(
			array_filter(
				(array) self::read( 'fatals' ),
				function ( $f ) use ( $since ) {
					return (int) ( $f['time'] ?? 0 ) >= $since;
				}
			)
		);
		wp_send_json(
			array(
				'ok'       => true,
				'version'  => DTC_GUARDIAN_VERSION,
				'fatals'   => $fatals,
				'inflight' => self::read( 'inflight' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Full restore driver
	 * ------------------------------------------------------------------ */

	public static function keep_totalcare_active( $plugins ) {
		$req      = self::read( 'restore-request' );
		$basename = $req['plugin_basename'] ?? '';
		if ( ! $basename && file_exists( self::dir() . '/plugin-basename.txt' ) ) {
			$basename = trim( (string) file_get_contents( self::dir() . '/plugin-basename.txt' ) );
		}
		if ( $basename && ! in_array( $basename, (array) $plugins, true ) ) {
			$plugins[] = $basename;
		}
		return $plugins;
	}

	/** Restart a stalled restore chain from any normal page load. */
	public static function watchdog() {
		if ( ! file_exists( self::dir() . '/restore-request.json' ) ) {
			return;
		}
		$req = self::read( 'restore-request' );
		if ( ! $req || ! in_array( $req['status'] ?? '', array( 'pending', 'running', 'finishing', 'failing' ), true ) ) {
			return;
		}
		if ( time() - (int) ( $req['updated'] ?? 0 ) > 90 && get_transient( 'dtc_guardian_watchdog' ) === false ) {
			set_transient( 'dtc_guardian_watchdog', 1, 60 );
			self::loopback( 'dtc_guardian_restore', array( 'wpvivid_restore' => 1 ) );
		}
	}

	public static function ajax_restore() {
		if ( ! self::verify_request( 'dtc_guardian_restore' ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}

		@mkdir( self::dir(), 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		self::$lock = fopen( self::dir() . '/restore.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! self::$lock || ! flock( self::$lock, LOCK_EX | LOCK_NB ) ) {
			wp_send_json( array( 'ok' => true, 'busy' => true ) );
		}

		$req = self::read( 'restore-request' );
		if ( ! $req || ! in_array( $req['status'] ?? '', array( 'pending', 'running', 'finishing', 'failing' ), true ) ) {
			self::unlock();
			wp_send_json( array( 'ok' => true, 'idle' => true ) );
		}

		ignore_user_abort( true );
		if ( 'failing' !== $req['status'] && time() - (int) ( $req['created'] ?? time() ) > 6 * HOUR_IN_SECONDS ) {
			$req['status'] = 'failing';
			$req['error']  = 'Restore did not finish within 6 hours.';
		}
		$req['updated'] = time();
		self::write( 'restore-request', $req );

		try {
			if ( ! class_exists( 'WPvivid_Restore_2' ) || ! class_exists( 'WPvivid_Backuplist' ) ) {
				throw new Exception( 'WPvivid Backup is not active.' );
			}
			switch ( $req['status'] ) {
				case 'pending':
					$req = self::phase_init( $req );
					break;
				case 'running':
					$req = self::phase_chunk( $req );
					break;
				case 'finishing':
					$req = self::phase_finish( $req );
					break;
				case 'failing':
					$req = self::phase_failed( $req, $req['error'] ?? 'Restore failed.' );
					break;
			}
		} catch ( Throwable $e ) {
			$req = self::phase_failed( $req, get_class( $e ) . ': ' . $e->getMessage() );
		}

		$req['updated'] = time();
		self::write( 'restore-request', $req );
		self::unlock();

		if ( in_array( $req['status'], array( 'running', 'finishing', 'failing' ), true ) ) {
			self::loopback( 'dtc_guardian_restore', array( 'wpvivid_restore' => 1 ) );
		} else {
			// TotalCare is active again; let it reconcile right away.
			self::loopback( 'dtc_kick' );
		}
		wp_send_json( array( 'ok' => true, 'status' => $req['status'] ) );
	}

	private static function unlock() {
		if ( self::$lock ) {
			flock( self::$lock, LOCK_UN );
			fclose( self::$lock ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			self::$lock = null;
		}
	}

	private static function note( array $req, $msg ) {
		$req['log']   = array_slice( array_merge( (array) ( $req['log'] ?? array() ), array( gmdate( 'H:i:s' ) . ' ' . $msg ) ), -40 );
		return $req;
	}

	private static function phase_init( array $req ) {
		$restore = new WPvivid_Restore_2();
		$ret     = $restore->create_restore_task( $req['backup_id'], array( 'restore_detail_options' => array() ), 0 );
		if ( empty( $ret['result'] ) || 'success' !== $ret['result'] ) {
			throw new Exception( 'Could not create the WPvivid restore task: ' . ( $ret['error'] ?? 'unknown error' ) );
		}

		// Same preparation as WPvivid's own init_restore_task().
		$restore->write_litespeed_rule();
		$restore->deactivate_plugins();
		$restore->deactivate_theme();
		$wpvivid_mu = WPMU_PLUGIN_DIR . '/a-wpvivid-restore-mu-plugin-check.php';
		if ( ! file_exists( $wpvivid_mu ) && defined( 'WPVIVID_PLUGIN_DIR' ) ) {
			@copy( WPVIVID_PLUGIN_DIR . '/includes/mu-plugins/a-wpvivid-restore-mu-plugin-check.php', $wpvivid_mu ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$req['prepared'] = true;
		$req['status']   = 'running';
		$req['chunks']   = 0;
		return self::note( $req, 'Restore task created with ' . count( $ret['task']['sub_tasks'] ?? array() ) . ' sub-tasks.' );
	}

	private static function phase_chunk( array $req ) {
		$restore = new WPvivid_Restore_2();
		if ( ! $restore->check_restore_task() ) {
			throw new Exception( 'The WPvivid restore task is missing or invalid.' );
		}

		$restore->_enable_maintenance_mode();
		$restore->set_restore_environment();
		$ret = $restore->_do_restore();
		$restore->_disable_maintenance_mode();

		$task          = get_option( 'wpvivid_restore_task', array() );
		$req['chunks'] = (int) ( $req['chunks'] ?? 0 ) + 1;

		if ( empty( $ret['result'] ) || 'success' !== $ret['result'] || 'error' === ( $task['status'] ?? '' ) ) {
			throw new Exception( 'WPvivid restore error: ' . ( $ret['error'] ?? $task['error'] ?? 'unknown error' ) );
		}

		$current = $task['sub_tasks'][ $task['do_sub_task'] ?? 0 ]['type'] ?? '';
		$req     = self::note( $req, 'Chunk ' . $req['chunks'] . ' done (' . $current . ').' );
		if ( $restore->check_task_finished() ) {
			$req['status'] = 'finishing';
		}
		return $req;
	}

	/** Mirrors WPvivid_Restore_2::finish_restore() without its nonce/echo/die. */
	private static function phase_finish( array $req ) {
		$restore = new WPvivid_Restore_2();
		$restore->_disable_maintenance_mode();
		$restore->write_litespeed_rule( false );
		@unlink( WPMU_PLUGIN_DIR . '/a-wpvivid-restore-mu-plugin-check.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		$plugins = get_option( 'wpvivid_save_active_plugins', array() );
		$ret     = $restore->check_restore_db();

		try {
			$restore->delete_temp_files();
		} catch ( Throwable $e ) {
			$req = self::note( $req, 'Temp file cleanup skipped: ' . $e->getMessage() );
		}
		delete_transient( 'wp_core_block_css_files' );

		// keep_totalcare_active() adds TotalCare through wpvivid_enable_plugins_list.
		if ( ! empty( $ret['has_db'] ) ) {
			$restore->active_plugins();
		} else {
			$restore->active_plugins( $plugins );
			$restore->check_active_theme();
		}

		delete_option( 'wpvivid_restore_task' );
		wp_cache_flush();

		if ( empty( $ret['result'] ) || 'success' !== $ret['result'] ) {
			$req['status'] = 'failed';
			$req['error']  = 'Database swap failed: ' . ( $ret['error'] ?? 'unknown error' );
			return self::note( $req, $req['error'] );
		}
		$req['status'] = 'done';
		return self::note( $req, 'Restore finished.' );
	}

	/** Mirrors WPvivid_Restore_2::restore_failed(), then restores the theme. */
	private static function phase_failed( array $req, $error ) {
		try {
			if ( class_exists( 'WPvivid_Restore_2' ) ) {
				$restore = new WPvivid_Restore_2();
				$restore->_disable_maintenance_mode();
				if ( ! empty( $req['prepared'] ) ) {
					$restore->write_litespeed_rule( false );
					@unlink( WPMU_PLUGIN_DIR . '/a-wpvivid-restore-mu-plugin-check.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					$plugins = get_option( 'wpvivid_save_active_plugins', array() );
					if ( get_option( 'wpvivid_restore_task' ) ) {
						$restore->delete_temp_tables();
						$restore->delete_temp_files();
					}
					$restore->active_plugins( $plugins );
					$restore->check_active_theme();
				}
				delete_option( 'wpvivid_restore_task' );
				wp_cache_flush();
			}
		} catch ( Throwable $e ) {
			$error .= ' Cleanup also failed: ' . $e->getMessage();
		}
		$req['status'] = 'failed';
		$req['error']  = $error;
		return self::note( $req, 'FAILED: ' . $error );
	}
}

DTC_Guardian::boot();
