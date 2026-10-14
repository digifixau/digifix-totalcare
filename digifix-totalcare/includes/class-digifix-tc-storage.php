<?php
/**
 * File-based storage shared with the guardian mu-plugin.
 *
 * Anything that must survive a WPvivid database restore (restore requests, the
 * in-flight update marker, fatal error records, the guardian secret) lives in
 * wp-content/dtc-data/ rather than in the database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Storage {

	public static function data_dir() {
		return WP_CONTENT_DIR . '/dtc-data';
	}

	public static function rollback_dir() {
		return WP_CONTENT_DIR . '/dtc-rollback';
	}

	public static function ensure_dirs() {
		foreach ( array( self::data_dir(), self::rollback_dir(), self::data_dir() . '/reports' ) as $dir ) {
			if ( ! is_dir( $dir ) ) {
				wp_mkdir_p( $dir );
			}
			if ( ! file_exists( $dir . '/index.php' ) ) {
				@file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" );
			}
			if ( ! file_exists( $dir . '/.htaccess' ) ) {
				@file_put_contents( $dir . '/.htaccess', "Require all denied\nDeny from all\n" );
			}
		}
	}

	public static function path( $name ) {
		return self::data_dir() . '/' . $name . '.json';
	}

	public static function read( $name, $default = null ) {
		$file = self::path( $name );
		if ( ! file_exists( $file ) ) {
			return $default;
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return null === $data ? $default : $data;
	}

	public static function write( $name, $data ) {
		self::ensure_dirs();
		$file = self::path( $name );
		$tmp  = $file . '.' . wp_generate_password( 6, false ) . '.tmp';
		file_put_contents( $tmp, wp_json_encode( $data, JSON_PRETTY_PRINT ) );
		return rename( $tmp, $file );
	}

	public static function delete( $name ) {
		$file = self::path( $name );
		if ( file_exists( $file ) ) {
			@unlink( $file );
		}
	}

	/**
	 * Shared HMAC secret for loopback requests. Stored as a PHP file so it is
	 * never served and survives a database restore.
	 */
	public static function secret() {
		$file = self::data_dir() . '/secret.php';
		if ( file_exists( $file ) ) {
			$secret = include $file;
			if ( is_string( $secret ) && strlen( $secret ) >= 32 ) {
				return $secret;
			}
		}
		self::ensure_dirs();
		$secret = wp_generate_password( 64, false, false );
		file_put_contents( $file, "<?php\nreturn " . var_export( $secret, true ) . ";\n" );
		return $secret;
	}

	public static function sign( $action, $ts ) {
		return hash_hmac( 'sha256', $action . '|' . $ts, self::secret() );
	}

	public static function verify( $action, $ts, $sig ) {
		if ( abs( time() - (int) $ts ) > 300 ) {
			return false;
		}
		return hash_equals( self::sign( $action, $ts ), (string) $sig );
	}

	/**
	 * Fire-and-forget POST to admin-ajax.php signed with the shared secret.
	 */
	public static function loopback( $action, $extra = array() ) {
		$ts   = time();
		$body = array_merge(
			$extra,
			array(
				'action' => $action,
				'ts'     => $ts,
				'sig'    => self::sign( $action, $ts ),
			)
		);
		return wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 1,
				'blocking'  => false,
				'sslverify' => false,
				'body'      => $body,
			)
		);
	}
}
