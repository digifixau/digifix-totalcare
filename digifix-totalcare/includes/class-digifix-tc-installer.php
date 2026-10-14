<?php
/**
 * Activation, deactivation, database tables and guardian installation.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Installer {

	const GUARDIAN_FILE = 'dtc-guardian.php';

	public static function activate() {
		self::create_tables();
		DTC_Storage::ensure_dirs();
		DTC_Storage::secret();
		self::install_guardian();
		self::litespeed_rule( true );
		self::import_wpvivid_credentials();
		DTC_Scheduler::reschedule();
		DTC_Logger::log( 'system', 'info', 'system.activated', 'Digifix TotalCare activated (v' . DTC_VERSION . ').' );
	}

	public static function deactivate() {
		DTC_Scheduler::clear_all();
		// Keep the guardian while a restore is running; it needs to finish it.
		if ( ! DTC_Restore::in_progress() ) {
			self::remove_guardian();
			self::litespeed_rule( false );
		}
	}

	public static function maybe_upgrade() {
		if ( get_option( 'dtc_db_version' ) !== DTC_DB_VERSION ) {
			self::create_tables();
		}
		$source = DTC_DIR . 'mu-plugin/' . self::GUARDIAN_FILE;
		$mode   = self::guardian_mode();
		$target = 'mu' === $mode ? self::mu_path() : ( 'dropin' === $mode ? self::dropin_guardian_path() : '' );
		if ( ! $target || ! file_exists( $target ) || md5_file( $target ) !== md5_file( $source ) ) {
			self::install_guardian();
		}
		if ( get_option( 'dtc_installed_version' ) !== DTC_VERSION ) {
			self::litespeed_rule( true );
			self::import_wpvivid_credentials();
			update_option( 'dtc_installed_version', DTC_VERSION, false );
		}
	}

	const HTACCESS_MARKER = 'Digifix TotalCare';

	/**
	 * LiteSpeed kills a PHP request as soon as the client disconnects unless
	 * "noabort" is set. TotalCare's background work runs in fire-and-forget
	 * loopback requests, so set it for admin-ajax.php and wp-cron.php.
	 * Harmless elsewhere: the rule only applies under LiteSpeed.
	 */
	public static function litespeed_rule( $add ) {
		$file = ABSPATH . '.htaccess';
		if ( ! file_exists( $file ) || ! is_writable( $file ) ) {
			return false;
		}
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		$lines = $add ? array(
			'<IfModule LiteSpeed>',
			'RewriteEngine On',
			'RewriteRule ^(wp-admin/admin-ajax\.php|wp-cron\.php)$ - [E=noabort:1,E=noconntimeout:1]',
			'</IfModule>',
		) : array();
		return insert_with_markers( $file, self::HTACCESS_MARKER, $lines );
	}

	/**
	 * Before 1.1, WPvivid held the S3 secret. Copy it once so the built-in
	 * engine works without re-entering credentials.
	 */
	public static function import_wpvivid_credentials() {
		if ( get_option( 'dtc_s3_secret' ) || ! class_exists( 'WPvivid_Setting' ) ) {
			return;
		}
		$remote = DTC_WPvivid::get_remote();
		if ( ! $remote || empty( $remote['secret'] ) ) {
			return;
		}
		DTC_Storage::secret(); // The encryption key is derived from it.
		$secret = ! empty( $remote['is_encrypt'] ) ? base64_decode( $remote['secret'], true ) : $remote['secret']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$stored = is_string( $secret ) && '' !== $secret ? DTC_Bk_Util::encrypt( $secret ) : '';
		if ( '' !== $stored ) {
			update_option( 'dtc_s3_secret', $stored, false );
			DTC_Logger::log( 'system', 'info', 'system.s3_imported', 'S3 credentials copied from WPvivid; backups now use TotalCare\'s built-in engine.' );
		}
	}

	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}dtc_jobs (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				type varchar(32) NOT NULL,
				status varchar(20) NOT NULL,
				step varchar(64) NOT NULL,
				state longtext NOT NULL,
				message text NOT NULL,
				attempts smallint(5) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				next_run_at bigint(20) unsigned NOT NULL DEFAULT 0,
				finished_at datetime DEFAULT NULL,
				PRIMARY KEY  (id),
				KEY status (status),
				KEY type (type)
			) $charset;"
		);

		dbDelta(
			"CREATE TABLE {$wpdb->prefix}dtc_events (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				created_at datetime NOT NULL,
				type varchar(20) NOT NULL,
				level varchar(10) NOT NULL,
				code varchar(64) NOT NULL,
				message text NOT NULL,
				context longtext NOT NULL,
				job_id bigint(20) unsigned NOT NULL DEFAULT 0,
				PRIMARY KEY  (id),
				KEY type (type),
				KEY code (code),
				KEY created_at (created_at)
			) $charset;"
		);

		update_option( 'dtc_db_version', DTC_DB_VERSION, false );
	}

	const DROPIN_MARKER = 'DTC-GUARDIAN-LOADER';

	public static function mu_path() {
		return untrailingslashit( WPMU_PLUGIN_DIR ) . '/' . self::GUARDIAN_FILE;
	}

	public static function dropin_path() {
		return WP_CONTENT_DIR . '/fatal-error-handler.php';
	}

	public static function dropin_guardian_path() {
		return DTC_Storage::data_dir() . '/' . self::GUARDIAN_FILE;
	}

	private static function dropin_is_ours() {
		$file = self::dropin_path();
		return file_exists( $file ) && false !== strpos( (string) file_get_contents( $file ), self::DROPIN_MARKER );
	}

	/** @return string mu|dropin|'' */
	public static function guardian_mode() {
		if ( file_exists( self::mu_path() ) ) {
			return 'mu';
		}
		return self::dropin_is_ours() ? 'dropin' : '';
	}

	/**
	 * Install the guardian as a must-use plugin, or, where mu-plugins is
	 * read-only (e.g. Hostinger links it to a shared folder), load it from
	 * the fatal-error-handler.php drop-in, which WordPress includes on every
	 * request before plugins and mu-plugins.
	 */
	public static function install_guardian() {
		$source = DTC_DIR . 'mu-plugin/' . self::GUARDIAN_FILE;

		// Plugin basename so the guardian can reactivate TotalCare after a restore.
		DTC_Storage::ensure_dirs();
		@file_put_contents( DTC_Storage::data_dir() . '/plugin-basename.txt', DTC_BASENAME ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( ! file_exists( $source ) ) {
			return self::guardian_failed( 'The plugin package is incomplete: ' . $source . ' is missing. Re-upload the full plugin zip.' );
		}

		$mu_reason = self::try_mu( $source );
		if ( '' === $mu_reason ) {
			if ( self::dropin_is_ours() ) {
				@unlink( self::dropin_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			return self::guardian_ok();
		}

		$dropin_reason = self::try_dropin( $source );
		if ( '' === $dropin_reason ) {
			return self::guardian_ok();
		}
		return self::guardian_failed( 'mu-plugins: ' . $mu_reason . ' Drop-in fallback: ' . $dropin_reason );
	}

	private static function try_mu( $source ) {
		$dir    = untrailingslashit( WPMU_PLUGIN_DIR );
		$target = self::mu_path();
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return 'the folder ' . $dir . ' does not exist and could not be created.';
		}
		if ( file_exists( $target ) ? ! is_writable( $target ) : ! is_writable( $dir ) ) {
			return 'the folder ' . $dir . ' is read-only for PHP' . ( is_link( $dir ) ? ' (it links to ' . readlink( $dir ) . ', a host-managed folder)' : '' ) . '.';
		}
		if ( ! @copy( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$err = error_get_last();
			return 'copy() failed' . ( $err ? ': ' . $err['message'] : '.' );
		}
		return '';
	}

	private static function try_dropin( $source ) {
		$dropin = self::dropin_path();
		if ( file_exists( $dropin ) && ! self::dropin_is_ours() ) {
			return $dropin . ' already exists and belongs to another plugin.';
		}
		if ( ! @copy( $source, self::dropin_guardian_path() ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return 'could not copy the guardian to ' . self::dropin_guardian_path() . '.';
		}
		$loader = "<?php\n"
			. "/**\n"
			. " * Digifix TotalCare guardian loader (" . self::DROPIN_MARKER . ").\n"
			. " *\n"
			. " * WordPress fatal-error-handler drop-in, used because mu-plugins is not\n"
			. " * writable on this host. Loads the TotalCare guardian on every request and\n"
			. " * returns null so WordPress keeps its default fatal error handler.\n"
			. " * Managed by Digifix TotalCare; removed when the plugin is deactivated.\n"
			. " */\n"
			. "if ( defined( 'ABSPATH' ) && ! defined( 'DTC_GUARDIAN_VERSION' ) && is_readable( WP_CONTENT_DIR . '/dtc-data/" . self::GUARDIAN_FILE . "' ) ) {\n"
			. "\tinclude_once WP_CONTENT_DIR . '/dtc-data/" . self::GUARDIAN_FILE . "';\n"
			. "}\n"
			. "return null;\n";
		if ( false === @file_put_contents( $dropin, $loader ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return 'could not write ' . $dropin . ' (wp-content is not writable).';
		}
		return '';
	}

	private static function guardian_ok() {
		delete_option( 'dtc_guardian_error' );
		delete_transient( 'dtc_guardian_error' );
		return true;
	}

	private static function guardian_failed( $reason ) {
		update_option( 'dtc_guardian_error', $reason, false );
		// Log at most once a day; maybe_upgrade() retries on every admin load.
		if ( ! get_transient( 'dtc_guardian_error' ) ) {
			set_transient( 'dtc_guardian_error', 1, DAY_IN_SECONDS );
			DTC_Logger::log( 'system', 'error', 'system.guardian_install_failed', 'Could not install the guardian: ' . $reason . ' Rollback and restore are disabled until it is installed.' );
		}
		return false;
	}

	private static function owner( $path ) {
		$uid = null === $path ? ( function_exists( 'posix_geteuid' ) ? posix_geteuid() : getmyuid() ) : @fileowner( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( function_exists( 'posix_getpwuid' ) && false !== $uid ) {
			$info = posix_getpwuid( $uid );
			if ( $info ) {
				return $info['name'];
			}
		}
		return 'uid ' . $uid;
	}

	public static function remove_guardian() {
		foreach ( array( self::mu_path(), self::dropin_guardian_path() ) as $file ) {
			if ( file_exists( $file ) ) {
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		if ( self::dropin_is_ours() ) {
			@unlink( self::dropin_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	public static function guardian_installed() {
		return defined( 'DTC_GUARDIAN_VERSION' ) && '' !== self::guardian_mode();
	}

	public static function guardian_error() {
		return '' !== self::guardian_mode() ? '' : (string) get_option( 'dtc_guardian_error', '' );
	}
}
