<?php
/**
 * File-based storage shared with the guardian mu-plugin.
 *
 * Anything that must survive a WPvivid database restore (restore requests, the
 * in-flight update marker, fatal error records, the guardian secret) lives in
 * wp-content/digifix-tc-data/ rather than in the database.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Digifix_TC_Storage {

	public static function data_dir() {
		return WP_CONTENT_DIR . '/digifix-tc-data';
	}

	public static function rollback_dir() {
		return WP_CONTENT_DIR . '/digifix-tc-rollback';
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
		return rename( $tmp, $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename
	}

	public static function delete( $name ) {
		$file = self::path( $name );
		if ( file_exists( $file ) ) {
			wp_delete_file( $file );
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
		file_put_contents( $file, "<?php\nreturn '" . $secret . "';\n" ); // Alphanumeric only (no special characters), so it is safe inside the quotes.
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
	 * Authenticate a background (loopback) request to admin-ajax.php.
	 *
	 * These requests are sent by this site to itself (see loopback()) from
	 * WP-Cron or another background request, so there is no logged-in user
	 * and a nonce cannot be used. Instead each request carries an HMAC-SHA256
	 * signature of the action and a timestamp, made with a 64-character
	 * secret that is stored only on this server; it is valid for 5 minutes.
	 * Requests without a valid signature are rejected with HTTP 403 before
	 * any other input is read.
	 */
	public static function verify_loopback( $action ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- this IS the request verification (HMAC signature instead of a nonce, see above).
		$ts  = isset( $_POST['ts'] ) ? absint( $_POST['ts'] ) : 0;
		$sig = isset( $_POST['sig'] ) ? preg_replace( '/[^a-f0-9]/', '', sanitize_text_field( wp_unslash( $_POST['sig'] ) ) ) : '';
		// phpcs:enable
		if ( ! $ts || 64 !== strlen( $sig ) || ! self::verify( $action, $ts, $sig ) ) {
			wp_send_json( array( 'ok' => false ), 403 );
		}
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
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core's filter for requests to this site itself.
				'body'      => $body,
			)
		);
	}
}
