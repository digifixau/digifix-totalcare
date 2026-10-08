<?php
/**
 * Shared helpers for the TotalCare backup engine.
 *
 * Everything in includes/backup/ must run in two places: inside TotalCare
 * (backups) and inside the guardian mu-plugin before regular plugins load
 * (restores). So these classes only use WordPress core functions that exist
 * at that point (no pluggable functions, no TotalCare classes).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Util {

	const FORMAT = 1;

	/* ---------------------------------------------------------------------
	 * Paths and local work files
	 * ------------------------------------------------------------------ */

	public static function data_dir() {
		return WP_CONTENT_DIR . '/dtc-data';
	}

	/**
	 * Random-named work folder inside dtc-data. Nginx ignores .htaccess, and
	 * spool files contain database bytes, so the name must not be guessable.
	 */
	public static function work_dir() {
		$base = self::data_dir();
		$file = $base . '/work-dir.txt';
		$name = file_exists( $file ) ? trim( (string) file_get_contents( $file ) ) : '';
		if ( ! preg_match( '/^bk-[a-f0-9]{24}$/', $name ) ) {
			$name = 'bk-' . self::random_hex( 12 );
			if ( ! is_dir( $base ) ) {
				@mkdir( $base, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			file_put_contents( $file, $name );
		}
		$dir = $base . '/' . $name;
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $dir;
	}

	/** Sub-folder of the work dir for one backup or restore run. */
	public static function run_dir( $name ) {
		$dir = self::work_dir() . '/' . preg_replace( '/[^A-Za-z0-9_-]/', '', $name );
		if ( ! is_dir( $dir ) ) {
			@mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $dir;
	}

	public static function rrmdir( $dir ) {
		if ( ! is_dir( $dir ) || is_link( $dir ) ) {
			return is_link( $dir ) ? @unlink( $dir ) : true; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( (array) @scandir( $dir ) as $item ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( '.' === $item || '..' === $item || '' === $item ) {
				continue;
			}
			$path = $dir . '/' . $item;
			if ( is_dir( $path ) && ! is_link( $path ) ) {
				self::rrmdir( $path );
			} else {
				@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
		return @rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/** Truncate an append-only file back to its last committed length. */
	public static function truncate( $file, $length ) {
		$length = (int) $length;
		if ( ! file_exists( $file ) ) {
			if ( 0 === $length ) {
				touch( $file );
				return true;
			}
			return false;
		}
		clearstatcache( true, $file );
		if ( filesize( $file ) === $length ) {
			return true;
		}
		if ( filesize( $file ) < $length ) {
			return false;
		}
		$fh = fopen( $file, 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$ok = $fh && ftruncate( $fh, $length );
		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		clearstatcache( true, $file );
		return $ok;
	}

	public static function random_hex( $bytes ) {
		return bin2hex( random_bytes( (int) $bytes ) );
	}

	/* ---------------------------------------------------------------------
	 * JSON state files (shared with the guardian)
	 * ------------------------------------------------------------------ */

	public static function json_encode( $data ) {
		$flags = JSON_UNESCAPED_SLASHES | ( defined( 'JSON_INVALID_UTF8_SUBSTITUTE' ) ? JSON_INVALID_UTF8_SUBSTITUTE : 0 );
		return json_encode( $data, $flags ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}

	public static function read_json( $file ) {
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$data = json_decode( (string) @file_get_contents( $file ), true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return is_array( $data ) ? $data : null;
	}

	/** Atomic write; never replaces the file when encoding fails. */
	public static function write_json( $file, $data ) {
		$json = self::json_encode( $data );
		if ( false === $json ) {
			return false;
		}
		$tmp = $file . '.' . self::random_hex( 4 ) . '.tmp';
		if ( false === @file_put_contents( $tmp, $json ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return false;
		}
		return @rename( $tmp, $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}

	/* ---------------------------------------------------------------------
	 * TSV rows (paths can contain any byte except NUL and "/" in names)
	 * ------------------------------------------------------------------ */

	public static function tsv_row( array $fields ) {
		$out = array();
		foreach ( $fields as $f ) {
			$out[] = strtr( (string) $f, array( '\\' => '\\\\', "\t" => '\\t', "\n" => '\\n', "\r" => '\\r' ) );
		}
		return implode( "\t", $out ) . "\n";
	}

	public static function tsv_parse( $line ) {
		$line = rtrim( $line, "\n" );
		if ( '' === $line ) {
			return array();
		}
		$fields = explode( "\t", $line );
		foreach ( $fields as $i => $f ) {
			if ( false !== strpos( $f, '\\' ) ) {
				$fields[ $i ] = strtr( $f, array( '\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\r' => "\r" ) );
			}
		}
		return $fields;
	}

	/**
	 * Order used by the file walk: per directory entries sorted by strcmp,
	 * depth first. Treating "/" as the lowest byte makes a plain string
	 * comparison of full relative paths agree with that walk order.
	 */
	public static function path_cmp( $a, $b ) {
		return strcmp( str_replace( '/', "\0", $a ), str_replace( '/', "\0", $b ) );
	}

	/* ---------------------------------------------------------------------
	 * Settings and credentials
	 * ------------------------------------------------------------------ */

	/** Raw TotalCare settings with the engine's own defaults. */
	public static function settings() {
		$s = get_option( 'dtc_settings', array() );
		$s = is_array( $s ) ? $s : array();
		return array_merge(
			array(
				's3_type'          => 'amazons3',
				's3_access'        => '',
				's3_bucket'        => '',
				's3_path'          => '',
				's3_endpoint'      => '',
				's3_region'        => '',
				's3_path_style'    => 1,
				'backup_full_days' => 7,
				'backup_excludes'  => '',
				'backup_db_tables' => '',
				'backup_db_scope'  => 'prefix',
				'request_budget'   => 25,
				'low_impact'       => 0,
			),
			$s
		);
	}

	/** Seconds of work per request, respecting a non-overridable PHP limit. */
	public static function budget() {
		$s      = self::settings();
		$budget = max( 10, min( 120, (int) $s['request_budget'] ) );
		if ( ! empty( $s['low_impact'] ) ) {
			$budget = min( $budget, 12 );
		}
		$max = (int) ini_get( 'max_execution_time' );
		if ( $max > 0 && ! self::can_set_time_limit() ) {
			$budget = min( $budget, max( 5, $max - 8 ) );
		}
		return $budget;
	}

	public static function can_set_time_limit() {
		if ( ! function_exists( 'set_time_limit' ) ) {
			return false;
		}
		$disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
		return ! in_array( 'set_time_limit', $disabled, true );
	}

	public static function extend_time_limit() {
		if ( self::can_set_time_limit() ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		ignore_user_abort( true );
	}

	/** Secret shared with the guardian; read-only here. */
	public static function site_secret() {
		$file = self::data_dir() . '/secret.php';
		if ( ! file_exists( $file ) ) {
			return null;
		}
		$secret = include $file;
		return is_string( $secret ) && strlen( $secret ) >= 32 ? $secret : null;
	}

	private static function cipher_key() {
		$secret = self::site_secret();
		return $secret ? hash_hmac( 'sha256', 'dtc-s3', $secret, true ) : null;
	}

	/**
	 * Encrypt the S3 secret for storage in the database. The key lives in
	 * dtc-data/secret.php, so a leaked database dump or export does not
	 * expose the bucket credentials (a compromised server still would).
	 */
	public static function encrypt( $plain ) {
		$key = self::cipher_key();
		if ( ! $key ) {
			return '';
		}
		if ( function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true ) ) {
			$iv  = random_bytes( 12 );
			$tag = '';
			$ct  = openssl_encrypt( $plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			if ( false !== $ct ) {
				return 'g1:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}
		if ( function_exists( 'sodium_crypto_secretbox' ) ) {
			$nonce = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			return 's1:' . base64_encode( $nonce . sodium_crypto_secretbox( $plain, $nonce, $key ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		return '';
	}

	/** @return string|null Null when the value cannot be decrypted. */
	public static function decrypt( $stored ) {
		$key = self::cipher_key();
		if ( ! $key || ! is_string( $stored ) || strlen( $stored ) < 4 ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw ) {
			return null;
		}
		if ( 0 === strpos( $stored, 'g1:' ) && function_exists( 'openssl_decrypt' ) && strlen( $raw ) > 28 ) {
			$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
			return false === $plain ? null : $plain;
		}
		if ( 0 === strpos( $stored, 's1:' ) && function_exists( 'sodium_crypto_secretbox_open' ) ) {
			$n     = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
			$plain = sodium_crypto_secretbox_open( substr( $raw, $n ), substr( $raw, 0, $n ), $key );
			return false === $plain ? null : $plain;
		}
		return null;
	}

	/** @return string|null */
	public static function s3_secret() {
		if ( defined( 'DTC_S3_SECRET' ) && DTC_S3_SECRET ) {
			return (string) DTC_S3_SECRET;
		}
		$stored = get_option( 'dtc_s3_secret', '' );
		return $stored ? self::decrypt( $stored ) : null;
	}

	/** Home URL without scheme or trailing slash, used to identify a site. */
	public static function site_key( $url = null ) {
		$url = null === $url ? get_option( 'home' ) : $url;
		return strtolower( untrailingslashit( preg_replace( '#^[a-z]+://#i', '', (string) $url ) ) );
	}

	public static function default_folder() {
		$url  = wp_parse_url( get_option( 'home' ) );
		$path = ( $url['host'] ?? 'site' ) . ( isset( $url['path'] ) ? '-' . trim( $url['path'], '/' ) : '' );
		return sanitize_title( $path );
	}

	/** Clean an S3 folder name so it cannot break request signing. */
	public static function clean_folder( $path ) {
		$path = preg_replace( '#[^A-Za-z0-9._/-]#', '-', (string) $path );
		$path = preg_replace( '#/+#', '/', $path );
		return trim( $path, '/.' );
	}

	public static function size( $bytes ) {
		$units = array( 'B', 'KB', 'MB', 'GB', 'TB' );
		$i     = 0;
		$bytes = (float) $bytes;
		while ( $bytes >= 1024 && $i < 4 ) {
			$bytes /= 1024;
			++$i;
		}
		return ( $i ? number_format( $bytes, 1 ) : (int) $bytes ) . ' ' . $units[ $i ];
	}
}
