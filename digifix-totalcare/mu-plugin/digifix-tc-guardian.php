<?php
/**
 * Plugin Name: Digifix TotalCare Guardian
 * Description: Safety net for Digifix TotalCare. Records PHP fatal errors, rolls back a plugin/theme update that crashes the site, and drives site restores before other plugins load. Installed and removed automatically by Digifix TotalCare.
 * Version:     1.2.2
 * Author:      Digifix
 *
 * This file must stay self-contained: it runs when a broken plugin crashes
 * every request, and drives restores before regular plugins and the theme
 * load (TotalCare's restore engine is loaded from the plugin's own folder,
 * which a restore never overwrites).
 *
 * Where mu-plugins is read-only, this same file is installed as the
 * wp-content/fatal-error-handler.php drop-in instead. It returns nothing
 * there, so WordPress keeps its default fatal error handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Can be loaded from mu-plugins or from the fatal-error-handler.php drop-in.
if ( defined( 'DIGIFIX_TC_GUARDIAN_VERSION' ) ) {
	return;
}

define( 'DIGIFIX_TC_GUARDIAN_VERSION', '1.2.2' );

final class Digifix_TC_Guardian {

	const FATAL_TYPES = array( E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR );
	const ACTIVE      = array( 'pending', 'running', 'finishing', 'failing' );

	private static $handled = false;
	private static $lock    = null;
	private static $driving = false;

	public static function boot() {
		// WordPress's fatal error handler dies after rendering, so hook into
		// its template filter (runs first) and keep a shutdown fallback for
		// sites where the handler is disabled.
		add_filter( 'wp_php_error_message', array( __CLASS__, 'on_wp_fatal' ), 1, 2 );
		register_shutdown_function( array( __CLASS__, 'on_shutdown' ) );

		add_action( 'wp_ajax_digifix_tc_guardian_health', array( __CLASS__, 'ajax_health' ) );
		add_action( 'wp_ajax_nopriv_digifix_tc_guardian_health', array( __CLASS__, 'ajax_health' ) );
		add_action( 'wp_ajax_digifix_tc_guardian_restore', array( __CLASS__, 'ajax_restore' ) );
		add_action( 'wp_ajax_nopriv_digifix_tc_guardian_restore', array( __CLASS__, 'ajax_restore' ) );

		add_filter( 'wpvivid_enable_plugins_list', array( __CLASS__, 'keep_totalcare_active' ) );
		add_action( 'init', array( __CLASS__, 'watchdog' ), 1 );

		// Restores with TotalCare's engine run before regular plugins load.
		// As an mu-plugin the database is ready now, so dispatch at once
		// (other mu-plugins restored by the backup cannot break it); from the
		// drop-in it is too early, so wait for muplugins_loaded.
		if ( isset( $GLOBALS['wpdb'] ) && is_object( $GLOBALS['wpdb'] ) && did_action( 'muplugins_loaded' ) === 0 && function_exists( 'get_option' ) ) {
			self::early();
		} else {
			add_action( 'muplugins_loaded', array( __CLASS__, 'early' ), 0 );
		}
	}

	/* ---------------------------------------------------------------------
	 * File storage (mirrors Digifix_TC_Storage)
	 * ------------------------------------------------------------------ */

	/** Folder the plugin used before 1.2.2 (inside wp-content). Read as a fallback until the plugin has moved it. */
	private static function legacy_dir() {
		return WP_CONTENT_DIR . '/digifix-tc-data';
	}

	/**
	 * Base of the uploads folder, found the same way as
	 * Digifix_TC_Bk_Util::uploads_base() (keep the two in step). It is worked
	 * out once, as early as possible, so that filters added by plugins loaded
	 * later cannot make the guardian and the plugin disagree. On multisite
	 * the main site's uploads folder is used for every site.
	 *
	 * @return string '' while WordPress is not ready (no database yet).
	 */
	private static function uploads_base() {
		static $base = null;
		if ( null !== $base ) {
			return $base;
		}
		if ( ! function_exists( 'wp_upload_dir' ) || ! isset( $GLOBALS['wpdb'] ) || ! is_object( $GLOBALS['wpdb'] ) ) {
			return '';
		}
		$upload   = array();
		$switched = false;
		try {
			if ( function_exists( 'is_multisite' ) && is_multisite() && function_exists( 'get_main_site_id' ) && function_exists( 'switch_to_blog' ) ) {
				$switched = switch_to_blog( get_main_site_id() );
			}
			$upload = wp_upload_dir( null, false );
		} catch ( Throwable $e ) {
			$upload = array();
		}
		if ( $switched && function_exists( 'restore_current_blog' ) ) {
			restore_current_blog();
		}
		$dir = is_array( $upload ) && ! empty( $upload['basedir'] ) && is_string( $upload['basedir'] ) ? rtrim( $upload['basedir'], '/\\' ) : '';
		if ( '' !== $dir ) {
			$base = $dir;
		}
		return $dir;
	}

	/**
	 * Folder for the guardian's state files, in the uploads folder. Until the
	 * plugin has moved a pre-1.2.2 folder there, that one is used.
	 *
	 * @return string '' when WordPress is not ready and there is no old folder.
	 */
	public static function data_dir() {
		$base   = self::uploads_base();
		$legacy = self::legacy_dir();
		if ( '' === $base ) {
			return is_dir( $legacy ) ? $legacy : '';
		}
		$dir = $base . '/digifix-tc-data';
		return ! is_dir( $dir ) && is_dir( $legacy ) ? $legacy : $dir;
	}

	/** Path of a file in the state folder, or '' when there is no folder yet. */
	private static function path( $file ) {
		$dir = self::data_dir();
		return '' === $dir ? '' : $dir . '/' . $file;
	}

	private static function read( $name ) {
		$file = self::path( $name . '.json' );
		if ( '' === $file || ! file_exists( $file ) ) {
			return null;
		}
		$data = json_decode( (string) @file_get_contents( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $data ) ? $data : null;
	}

	private static function write( $name, $data ) {
		$dir = self::data_dir();
		if ( '' === $dir ) {
			return;
		}
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		}
		$file = $dir . '/' . $name . '.json';
		$json = json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
		if ( false === $json ) {
			return; // Never replace the file with an empty one.
		}
		$tmp = $file . '.' . getmypid() . '.' . mt_rand() . '.tmp'; // phpcs:ignore WordPress.WP.AlternativeFunctions.rand_mt_rand
		if ( false !== @file_put_contents( $tmp, $json ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
		}
	}

	private static function secret() {
		$file = self::path( 'secret.php' );
		if ( '' === $file || ! file_exists( $file ) ) {
			return null;
		}
		// Read as text, never executed: the file sits in the uploads folder.
		$source = (string) @file_get_contents( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return preg_match( "/return '([A-Za-z0-9]{32,})';/", $source, $m ) ? $m[1] : null;
	}

	private static function sign( $action, $ts ) {
		$secret = self::secret();
		return $secret ? hash_hmac( 'sha256', $action . '|' . $ts, $secret ) : '';
	}

	/**
	 * Authenticate a background (loopback) request. They are sent by this
	 * site to itself with no logged-in user, so a nonce cannot be used; each
	 * one carries an HMAC-SHA256 signature of the action and a timestamp,
	 * made with a secret stored only on this server (valid for 5 minutes).
	 */
	private static function verify_request( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- this IS the request verification (HMAC signature instead of a nonce, see above).
		$ts  = isset( $_POST['ts'] ) ? absint( $_POST['ts'] ) : 0;
		$sig = isset( $_POST['sig'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_POST['sig'] ) ) ) : '';
		// phpcs:enable
		$exp = $ts ? self::sign( $action, $ts ) : '';
		return $exp && abs( time() - $ts ) <= 300 && hash_equals( $exp, $sig );
	}

	private static function loopback( $action, $extra = array() ) {
		$ts = time();
		wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 1,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to this site itself.
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

	/**
	 * The requested URL for the fatal error record, cleaned and cut to 200
	 * characters. esc_url_raw() keeps %xx escapes (sanitize_text_field() would
	 * strip them). Empty if the fatal error happened before WordPress's
	 * formatting functions were loaded.
	 */
	private static function request_uri() {
		if ( ! isset( $_SERVER['REQUEST_URI'] ) || ! function_exists( 'esc_url_raw' ) || ! function_exists( 'wp_unslash' ) ) {
			return '';
		}
		return substr( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ), 0, 200 );
	}

	/** The posted AJAX action, cleaned (empty in the same early-fatal case). */
	private static function posted_action() {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- only compared with a fixed name to tell whether this request was a restore step; nothing is processed.
		if ( ! isset( $_POST['action'] ) || ! function_exists( 'sanitize_key' ) || ! function_exists( 'wp_unslash' ) ) {
			return '';
		}
		return sanitize_key( wp_unslash( $_POST['action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
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
			'url'     => self::request_uri(),
		);

		$fatals   = (array) self::read( 'fatals' );
		$fatals[] = $entry;
		self::write( 'fatals', array_slice( $fatals, -50 ) );

		self::maybe_rollback_inflight( $entry );

		$req     = self::read( 'restore-request' );
		$legacy  = 'totalcare' !== ( $req['engine'] ?? 'wpvivid' );
		$restore = $legacy ? ( defined( 'DOING_AJAX' ) && DOING_AJAX && 'digifix_tc_guardian_restore' === self::posted_action() ) : self::$driving;
		if ( $req && in_array( $req['status'] ?? '', array( 'pending', 'running', 'finishing' ), true ) && $restore ) {
			$req['errors'] = (int) ( $req['errors'] ?? 0 ) + 1;
			$req['error']  = 'PHP fatal during restore: ' . $entry['message'] . ' in ' . $entry['file'] . ':' . $entry['line'];
			if ( $req['errors'] >= 3 ) {
				$req['status'] = 'failing';
			}
			$req['updated'] = time();
			self::write( 'restore-request', $req );
			// Continue (or fail) in a fresh request; this one is about to die.
			self::unlock();
			self::loopback( 'digifix_tc_guardian_restore', $legacy ? array( 'wpvivid_restore' => 1 ) : array() );
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

		$failed = $source . '.digifix-tc-failed-' . time();
		if ( file_exists( $source ) && ! @rename( $source, $failed ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return;
		}
		$ok = is_dir( $snapshot ) ? self::copy_dir( $snapshot, $source ) : @copy( $snapshot, $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $ok ) {
			@rename( $failed, $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
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
		if ( ! is_dir( $dst ) && ! @mkdir( $dst, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
			return false;
		}
		$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::SELF_FIRST );
		foreach ( $it as $item ) {
			$target = $dst . '/' . substr( $item->getPathname(), strlen( $src ) + 1 );
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) && ! @mkdir( $target, 0755, true ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
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
		if ( ! self::verify_request( 'digifix_tc_guardian_health' ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		$since  = isset( $_POST['since'] ) ? absint( $_POST['since'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signature checked by verify_request() above.
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
				'version'  => DIGIFIX_TC_GUARDIAN_VERSION,
				'fatals'   => $fatals,
				'inflight' => self::read( 'inflight' ),
			)
		);
	}

	/* ---------------------------------------------------------------------
	 * Restore driver (TotalCare engine)
	 * ------------------------------------------------------------------ */

	/**
	 * Runs on every request before regular plugins load, but only does
	 * anything while a restore with TotalCare's engine is in progress.
	 */
	public static function early() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done  = true;
		// Read-only progress page; the 128-bit random token in the link is the credential.
		$token = isset( $_GET['digifix_tc_restore_status'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_GET['digifix_tc_restore_status'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! file_exists( self::path( 'restore-request.json' ) ) ) {
			// Progress page of a restore that has already been reconciled.
			$last = '' !== $token ? self::read( 'restore-last' ) : null;
			if ( $last && ! empty( $last['token'] ) && hash_equals( (string) $last['token'], $token ) ) {
				self::render_status( $last );
			}
			return;
		}
		$req = self::read( 'restore-request' );
		if ( ! $req || 'totalcare' !== ( $req['engine'] ?? '' ) ) {
			return;
		}
		$token_ok = '' !== $token && ! empty( $req['token'] ) && hash_equals( (string) $req['token'], $token );
		if ( ! in_array( $req['status'] ?? '', self::ACTIVE, true ) ) {
			if ( $token_ok ) {
				self::render_status( $req );
			}
			return;
		}

		// Background updates must not change files mid-restore.
		add_filter( 'automatic_updater_disabled', '__return_true', 99 );

		// 1. The restore chain itself.
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && 'digifix_tc_guardian_restore' === sanitize_key( wp_unslash( $_POST['action'] ?? '' ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- signed loopback; verify_request() checks the HMAC.
			if ( ! self::verify_request( 'digifix_tc_guardian_restore' ) ) {
				self::json( array( 'ok' => false ), 403 );
			}
			self::json( self::drive() );
		}

		// 2. Progress page (works while the site shows the maintenance page).
		if ( $token_ok ) {
			self::watchdog_new( $req, true );
			self::render_status( self::read( 'restore-request' ) );
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		// 3. Every other request: keep the chain alive, and show the
		//    maintenance page while code and tables are being replaced.
		self::watchdog_new( $req, ! empty( $req['maintenance'] ) );
		if ( ! empty( $req['maintenance'] ) ) {
			self::render_maintenance();
		}
	}

	/**
	 * Restart a stalled restore chain. If loopback requests keep failing
	 * (firewall, basic auth), run one step inline instead.
	 */
	private static function watchdog_new( array $req, $may_run_inline ) {
		$stalled = time() - (int) ( $req['updated'] ?? 0 );
		if ( $stalled < 90 ) {
			return;
		}
		$marker = self::path( 'restore-watchdog.txt' );
		if ( '' === $marker ) {
			return;
		}
		if ( file_exists( $marker ) && time() - filemtime( $marker ) < 60 ) {
			return;
		}
		@touch( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_touch
		if ( $may_run_inline && $stalled > 300 ) {
			self::drive();
			return;
		}
		self::loopback( 'digifix_tc_guardian_restore' );
	}

	/** Run one restore step in this request (WP-CLI fallback). */
	public static function step_now() {
		return self::drive();
	}

	/** Take the restore lock without waiting. @return bool False if another request holds it. */
	private static function take_lock() {
		$dir = self::data_dir();
		if ( '' === $dir ) {
			return false;
		}
		@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
		self::$lock = fopen( $dir . '/restore.lock', 'c' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		return self::$lock && flock( self::$lock, LOCK_EX | LOCK_NB );
	}

	/** One locked step of the restore. @return array Response data. */
	private static function drive() {
		if ( ! self::take_lock() ) {
			return array(
				'ok'   => true,
				'busy' => true,
			);
		}
		$req = self::read( 'restore-request' );
		if ( ! $req || ! in_array( $req['status'] ?? '', self::ACTIVE, true ) ) {
			self::unlock();
			return array(
				'ok'   => true,
				'idle' => true,
			);
		}

		ignore_user_abort( true );
		$started = microtime( true );
		if ( 'failing' !== $req['status'] && time() - (int) ( $req['created'] ?? time() ) > 6 * HOUR_IN_SECONDS ) {
			$req['status'] = 'failing';
			$req['error']  = 'Restore did not finish within 6 hours.';
		}
		if ( 'pending' === $req['status'] ) {
			$req['status'] = 'running';
		}
		$req['updated'] = time();
		self::write( 'restore-request', $req );

		self::$driving = true;
		$loader        = self::engine_loader( $req );
		try {
			if ( ! is_readable( $loader ) ) {
				throw new Exception( esc_html( 'The restore engine is missing (' . $loader . ').' ) );
			}
			require_once $loader;
			Digifix_TC_Bk_Util::extend_time_limit();
			$deadline = $started + Digifix_TC_Bk_Util::budget();
			if ( 'failing' === $req['status'] ) {
				$req = Digifix_TC_Bk_Restore_Engine::fail( $req, $req['error'] ? $req['error'] : 'Restore failed.' );
			} else {
				$req = Digifix_TC_Bk_Restore_Engine::run( $req, $deadline );
			}
		} catch ( Throwable $e ) {
			$msg = wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) . ( $e instanceof Digifix_TC_Bk_Exception ? '' : ' (' . basename( $e->getFile() ) . ':' . $e->getLine() . ')' );
			if ( class_exists( 'Digifix_TC_Bk_Restore_Engine', false ) ) {
				$req = Digifix_TC_Bk_Restore_Engine::fail( $req, $msg );
			} else {
				$req['status']      = 'failed';
				$req['maintenance'] = false;
				$req['error']       = $msg;
			}
		}
		self::$driving = false;

		$req['updated'] = time();
		self::write( 'restore-request', $req );
		self::unlock();

		if ( in_array( $req['status'], array( 'running', 'failing' ), true ) ) {
			self::loopback( 'digifix_tc_guardian_restore' );
		} else {
			// Let TotalCare reconcile right away (plugins load normally again).
			self::loopback( 'digifix_tc_kick' );
		}
		if ( ! empty( $req['maintenance'] ) || 'done' === $req['status'] ) {
			header( 'X-LiteSpeed-Purge: *' );
		}
		return array(
			'ok'     => true,
			'status' => $req['status'],
		);
	}

	/**
	 * The restore engine lives in TotalCare's own plugin folder. The plugin
	 * stores its path (worked out from the plugin's own file location) in the
	 * restore request together with an HMAC signature made with the site
	 * secret, so a file written into the state folder by anything else is never
	 * loaded. Only a file named includes/backup/loader.php is accepted.
	 *
	 * @return string '' when the path is missing or not signed.
	 */
	private static function engine_loader( array $req ) {
		$loader = str_replace( '\\', '/', (string) ( $req['engine_loader'] ?? '' ) );
		$secret = self::secret();
		if ( ! $secret || '/includes/backup/loader.php' !== substr( $loader, -27 ) ) {
			return '';
		}
		return hash_equals( hash_hmac( 'sha256', 'engine_loader|' . $loader, $secret ), (string) ( $req['engine_sig'] ?? '' ) ) ? $loader : '';
	}

	private static function json( array $data, $code = 200 ) {
		if ( ! headers_sent() ) {
			http_response_code( $code );
			header( 'Content-Type: application/json; charset=utf-8' );
			header( 'Cache-Control: no-store' );
		}
		echo json_encode( $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode, WordPress.Security.EscapeOutput.OutputNotEscaped
		exit;
	}

	private static function no_cache_headers() {
		if ( headers_sent() ) {
			return;
		}
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		header( 'X-LiteSpeed-Cache-Control: no-cache' );
		header( 'Content-Type: text/html; charset=utf-8' );
	}

	const PAGE_CSS = 'body{font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif;background:#f6f7f7;color:#1d2327;margin:0;padding:24px 16px}'
		. 'main{max-width:720px;margin:0 auto;background:#fff;border:1px solid #dcdcde;border-radius:6px;padding:20px}'
		. 'main.digifix-tc-maint{max-width:460px;margin-top:20vh;text-align:center}h1{font-size:22px}'
		. 'pre{white-space:pre-wrap;word-break:break-word;background:#f6f7f7;padding:12px;font-size:12px;max-height:50vh;overflow:auto}'
		. '.digifix-tc-status{font-weight:600}.digifix-tc-error{color:#b32d2e}';

	/**
	 * Print the stylesheet of the maintenance and progress pages through the
	 * WordPress styles API. These pages are rendered before plugins and the
	 * theme load (no wp_head()), so the style is registered and printed here.
	 */
	private static function print_page_styles() {
		if ( ! function_exists( 'wp_register_style' ) || ! function_exists( 'wp_add_inline_style' ) || ! function_exists( 'wp_print_styles' ) ) {
			return;
		}
		try {
			wp_register_style( 'digifix-tc-guardian', false, array(), DIGIFIX_TC_GUARDIAN_VERSION );
			wp_add_inline_style( 'digifix-tc-guardian', self::PAGE_CSS );
			wp_print_styles( 'digifix-tc-guardian' );
		} catch ( Throwable $e ) {
			return; // The page is still readable without styles.
		}
	}

	private static function render_maintenance() {
		self::no_cache_headers();
		if ( ! headers_sent() ) {
			http_response_code( 503 );
			header( 'Retry-After: 120' );
		}
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="refresh" content="60"><title>Maintenance</title>';
		self::print_page_styles();
		echo '</head><body><main class="digifix-tc-maint"><h1>Scheduled maintenance</h1><p>This site is being updated and will be back in a few minutes.</p></main></body></html>';
		exit;
	}

	private static function render_status( $req ) {
		self::no_cache_headers();
		$req    = is_array( $req ) ? $req : array();
		$status = (string) ( $req['status'] ?? 'unknown' );
		$active = in_array( $status, self::ACTIVE, true );
		$labels = array(
			'pending' => 'Starting',
			'running' => 'Restoring',
			'failing' => 'Cleaning up after an error',
			'done'    => 'Finished',
			'failed'  => 'Failed',
		);
		echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
			. ( $active ? '<meta http-equiv="refresh" content="10">' : '' )
			. '<title>Restore progress</title>';
		self::print_page_styles();
		echo '</head><body><main>'
			. '<h1>Site restore</h1>'
			. '<p class="digifix-tc-status">' . esc_html( $labels[ $status ] ?? ucfirst( $status ) ) . '</p>'
			. '<p>' . esc_html( (string) ( $req['progress'] ?? '' ) ) . '</p>'
			. ( ! empty( $req['error'] ) ? '<p class="digifix-tc-error">' . esc_html( (string) $req['error'] ) . '</p>' : '' )
			. '<pre>' . esc_html( implode( "\n", (array) ( $req['log'] ?? array() ) ) ) . '</pre>'
			. ( $active ? '<p>This page refreshes every 10 seconds. You can close it; the restore continues on its own.</p>' : '<p><a href="' . esc_url( admin_url( 'admin.php?page=digifix-tc-backups' ) ) . '">Back to TotalCare</a></p>' )
			. '</main></body></html>';
		exit;
	}

	/* ---------------------------------------------------------------------
	 * Legacy restore driver (WPvivid engine)
	 * ------------------------------------------------------------------ */

	public static function keep_totalcare_active( $plugins ) {
		$req      = self::read( 'restore-request' );
		$basename = $req['plugin_basename'] ?? '';
		$saved    = self::path( 'plugin-basename.txt' );
		if ( ! $basename && '' !== $saved && file_exists( $saved ) ) {
			$basename = trim( (string) file_get_contents( $saved ) );
		}
		if ( $basename && ! in_array( $basename, (array) $plugins, true ) ) {
			$plugins[] = $basename;
		}
		return $plugins;
	}

	/** Restart a stalled restore chain from any normal page load. */
	public static function watchdog() {
		if ( ! file_exists( self::path( 'restore-request.json' ) ) ) {
			return;
		}
		$req = self::read( 'restore-request' );
		if ( ! $req || 'totalcare' === ( $req['engine'] ?? '' ) || ! in_array( $req['status'] ?? '', self::ACTIVE, true ) ) {
			return;
		}
		if ( time() - (int) ( $req['updated'] ?? 0 ) > 90 && get_transient( 'digifix_tc_guardian_watchdog' ) === false ) {
			set_transient( 'digifix_tc_guardian_watchdog', 1, 60 );
			self::loopback( 'digifix_tc_guardian_restore', array( 'wpvivid_restore' => 1 ) );
		}
	}

	public static function ajax_restore() {
		if ( ! self::verify_request( 'digifix_tc_guardian_restore' ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
		$current = self::read( 'restore-request' );
		if ( $current && 'totalcare' === ( $current['engine'] ?? '' ) ) {
			// Handled before plugins load; reaching here means it already ran.
			wp_send_json( array( 'ok' => true, 'idle' => true ) );
		}

		if ( ! self::take_lock() ) {
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
			$req = self::phase_failed( $req, get_class( $e ) . ': ' . wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
		}

		$req['updated'] = time();
		self::write( 'restore-request', $req );
		self::unlock();

		if ( in_array( $req['status'], array( 'running', 'finishing', 'failing' ), true ) ) {
			self::loopback( 'digifix_tc_guardian_restore', array( 'wpvivid_restore' => 1 ) );
		} else {
			// TotalCare is active again; let it reconcile right away.
			self::loopback( 'digifix_tc_kick' );
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
			throw new Exception( esc_html( 'Could not create the WPvivid restore task: ' . ( $ret['error'] ?? 'unknown error' ) ) );
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
			throw new Exception( esc_html( 'WPvivid restore error: ' . ( $ret['error'] ?? $task['error'] ?? 'unknown error' ) ) );
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
		@unlink( WPMU_PLUGIN_DIR . '/a-wpvivid-restore-mu-plugin-check.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink

		$plugins = get_option( 'wpvivid_save_active_plugins', array() );
		$ret     = $restore->check_restore_db();

		try {
			$restore->delete_temp_files();
		} catch ( Throwable $e ) {
			$req = self::note( $req, 'Temp file cleanup skipped: ' . wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
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
					@unlink( WPMU_PLUGIN_DIR . '/a-wpvivid-restore-mu-plugin-check.php' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
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
			$error .= ' Cleanup also failed: ' . wp_specialchars_decode( $e->getMessage(), ENT_QUOTES );
		}
		$req['status'] = 'failed';
		$req['error']  = $error;
		return self::note( $req, 'FAILED: ' . $error );
	}
}

Digifix_TC_Guardian::boot();
