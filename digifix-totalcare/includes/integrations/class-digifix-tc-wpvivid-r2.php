<?php
/**
 * Cloudflare R2 storage type for WPvivid.
 *
 * WPvivid's free "S3-compatible" remote (Wpvivid_S3Compat, AWS SDK 2.8) signs
 * requests with legacy Signature V2 and takes the region from the first label
 * of the endpoint host. R2 only accepts Signature V4 with region "auto", so
 * this subclass reuses all of WPvivid's upload/download/delete logic and only
 * replaces the client it builds.
 *
 * Loaded from the wpvivid_remote_register filter, after WPvivid has defined
 * Wpvivid_S3Compat.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Aws\S3\S3Client;

class DTC_WPvivid_R2 extends Wpvivid_S3Compat {

	const TYPE = 'dtc_r2';

	/** @var array Copy of the options; the parent keeps its own private copy. */
	private $dtc_options = array();

	public function __construct( $options = array() ) {
		// WPvivid instantiates every registered type without options to let it
		// add UI hooks; skip that so the S3-compatible tab is not duplicated.
		if ( empty( $options ) ) {
			return;
		}
		$this->dtc_options = $options;
		parent::__construct( $options );
	}

	public function getClient() {
		// Let the parent validate and set its private bucket/path state.
		$parent = parent::getClient();
		if ( is_array( $parent ) ) {
			return $parent;
		}

		$o      = $this->dtc_options;
		$secret = ! empty( $o['is_encrypt'] ) ? base64_decode( $o['secret'] ) : $o['secret']; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		return S3Client::factory(
			array(
				'credentials'    => array(
					'key'    => $o['access'],
					'secret' => $secret,
				),
				'region'         => 'auto',
				'signature'      => 'v4',
				'endpoint'       => 'https://' . self::normalize_endpoint( $o['endpoint'] ),
				'command.params' => array( 'PathStyle' => true ),
			)
		);
	}

	/**
	 * Accepts an account ID, "<id>.r2.cloudflarestorage.com", or the full
	 * "S3 API" URL from the Cloudflare dashboard (with or without the bucket
	 * path) and returns the bare host.
	 */
	public static function normalize_endpoint( $endpoint ) {
		$endpoint = trim( (string) $endpoint );
		if ( preg_match( '/^[a-f0-9]{32}$/i', $endpoint ) ) {
			return strtolower( $endpoint ) . '.r2.cloudflarestorage.com';
		}
		if ( false === strpos( $endpoint, '://' ) ) {
			$endpoint = 'https://' . $endpoint;
		}
		$host = wp_parse_url( $endpoint, PHP_URL_HOST );
		return $host ? strtolower( $host ) : '';
	}

	public static function register( $collection ) {
		$collection[ self::TYPE ] = __CLASS__;
		return $collection;
	}

	public static function provider_label( $type ) {
		return self::TYPE === $type ? 'Cloudflare R2' : $type;
	}
}
