<?php
/**
 * Minimal S3 client (Signature V4) on top of WP_Http.
 *
 * Supports Amazon S3, Cloudflare R2 and other S3-compatible services with
 * just the operations the backup engine needs. No SDK: keeps memory low and
 * works on PHP 7.4.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_S3 {

	const PART_SIZE = 8388608; // 8 MiB. R2 needs equal part sizes (except the last).

	/** @var array */
	private $cfg;

	/** @var int Seconds to add to local time (corrects server clock skew). */
	private static $skew = 0;

	/**
	 * @param array $cfg type (amazons3|r2|s3compat), access, secret, bucket,
	 *                   region, endpoint, path_style.
	 */
	public function __construct( array $cfg ) {
		$this->cfg = array_merge(
			array(
				'type'       => 'amazons3',
				'access'     => '',
				'secret'     => '',
				'bucket'     => '',
				'region'     => '',
				'endpoint'   => '',
				'path_style' => 1,
			),
			$cfg
		);
	}

	public function bucket() {
		return $this->cfg['bucket'];
	}

	/* ---------------------------------------------------------------------
	 * Endpoint
	 * ------------------------------------------------------------------ */

	/**
	 * Accepts an R2 account ID, "<id>.r2.cloudflarestorage.com", or the full
	 * S3 API URL from the Cloudflare dashboard and returns the bare host.
	 */
	public static function normalize_r2_endpoint( $endpoint ) {
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

	public function region() {
		if ( 'r2' === $this->cfg['type'] ) {
			return 'auto';
		}
		return $this->cfg['region'] ? $this->cfg['region'] : 'us-east-1';
	}

	/** @return array{scheme:string,host:string,path_style:bool} */
	private function endpoint() {
		$bucket = $this->cfg['bucket'];
		if ( 'r2' === $this->cfg['type'] ) {
			return array(
				'scheme'     => 'https',
				'host'       => self::normalize_r2_endpoint( $this->cfg['endpoint'] ),
				'path_style' => true,
			);
		}
		if ( 's3compat' === $this->cfg['type'] ) {
			$ep     = trim( (string) $this->cfg['endpoint'] );
			$scheme = 'https';
			if ( preg_match( '#^(https?)://#i', $ep, $m ) ) {
				$scheme = strtolower( $m[1] );
				$ep     = substr( $ep, strlen( $m[0] ) );
			}
			$ep         = rtrim( preg_replace( '#/.*$#', '', $ep ), '/' );
			$path_style = ! empty( $this->cfg['path_style'] ) || false !== strpos( $bucket, '.' );
			return array(
				'scheme'     => $scheme,
				'host'       => $path_style ? $ep : $bucket . '.' . $ep,
				'path_style' => $path_style,
			);
		}
		$region     = $this->region();
		$path_style = false !== strpos( $bucket, '.' );
		$base       = 's3.' . $region . '.amazonaws.com';
		return array(
			'scheme'     => 'https',
			'host'       => $path_style ? $base : $bucket . '.' . $base,
			'path_style' => $path_style,
		);
	}

	private static function encode_key( $key ) {
		return implode( '/', array_map( 'rawurlencode', explode( '/', (string) $key ) ) );
	}

	private function canonical_uri( $key ) {
		$ep  = $this->endpoint();
		$uri = '/';
		if ( $ep['path_style'] ) {
			$uri .= rawurlencode( $this->cfg['bucket'] ) . ( '' !== $key ? '/' : '' );
		}
		return $uri . self::encode_key( $key );
	}

	private static function canonical_query( array $query ) {
		$pairs = array();
		foreach ( $query as $k => $v ) {
			$pairs[ rawurlencode( (string) $k ) ] = rawurlencode( (string) $v );
		}
		ksort( $pairs, SORT_STRING );
		$out = array();
		foreach ( $pairs as $k => $v ) {
			$out[] = $k . '=' . $v;
		}
		return implode( '&', $out );
	}

	/* ---------------------------------------------------------------------
	 * Signing
	 * ------------------------------------------------------------------ */

	private function signing_key( $date ) {
		$k = hash_hmac( 'sha256', $date, 'AWS4' . $this->cfg['secret'], true );
		$k = hash_hmac( 'sha256', $this->region(), $k, true );
		$k = hash_hmac( 'sha256', 's3', $k, true );
		return hash_hmac( 'sha256', 'aws4_request', $k, true );
	}

	/**
	 * Build signed request headers.
	 *
	 * @param array $headers Extra headers to sign (lowercase names).
	 */
	private function sign( $method, $key, array $query, array $headers, $payload_hash ) {
		$ep      = $this->endpoint();
		$time    = time() + self::$skew;
		$amzdate = gmdate( 'Ymd\THis\Z', $time );
		$date    = gmdate( 'Ymd', $time );

		$headers['host']                 = $ep['host'];
		$headers['x-amz-date']           = $amzdate;
		$headers['x-amz-content-sha256'] = $payload_hash;
		ksort( $headers, SORT_STRING );

		$canon_headers = '';
		foreach ( $headers as $name => $value ) {
			$canon_headers .= $name . ':' . trim( (string) $value ) . "\n";
		}
		$signed  = implode( ';', array_keys( $headers ) );
		$canon   = $method . "\n" . $this->canonical_uri( $key ) . "\n" . self::canonical_query( $query ) . "\n" . $canon_headers . "\n" . $signed . "\n" . $payload_hash;
		$scope   = $date . '/' . $this->region() . '/s3/aws4_request';
		$to_sign = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $scope . "\n" . hash( 'sha256', $canon );
		$sig     = hash_hmac( 'sha256', $to_sign, $this->signing_key( $date ) );

		$headers['authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $this->cfg['access'] . '/' . $scope . ', SignedHeaders=' . $signed . ', Signature=' . $sig;
		unset( $headers['host'] ); // WP_Http sets it from the URL.
		return $headers;
	}

	private function url( $key, array $query ) {
		$ep = $this->endpoint();
		$qs = self::canonical_query( $query );
		return $ep['scheme'] . '://' . $ep['host'] . $this->canonical_uri( $key ) . ( '' !== $qs ? '?' . $qs : '' );
	}

	/** Presigned GET URL, e.g. for a download link in the admin. */
	public function presign( $key, $expires = 3600 ) {
		$time    = time() + self::$skew;
		$amzdate = gmdate( 'Ymd\THis\Z', $time );
		$date    = gmdate( 'Ymd', $time );
		$scope   = $date . '/' . $this->region() . '/s3/aws4_request';
		$query   = array(
			'X-Amz-Algorithm'     => 'AWS4-HMAC-SHA256',
			'X-Amz-Credential'    => $this->cfg['access'] . '/' . $scope,
			'X-Amz-Date'          => $amzdate,
			'X-Amz-Expires'       => (int) $expires,
			'X-Amz-SignedHeaders' => 'host',
		);
		$ep      = $this->endpoint();
		$canon   = "GET\n" . $this->canonical_uri( $key ) . "\n" . self::canonical_query( $query ) . "\nhost:" . $ep['host'] . "\n\nhost\nUNSIGNED-PAYLOAD";
		$to_sign = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $scope . "\n" . hash( 'sha256', $canon );

		$query['X-Amz-Signature'] = hash_hmac( 'sha256', $to_sign, $this->signing_key( $date ) );
		return $this->url( $key, $query );
	}

	/* ---------------------------------------------------------------------
	 * Transport
	 * ------------------------------------------------------------------ */

	/**
	 * @param array $opts body (string), headers (lowercase => value, signed),
	 *                    timeout, filename (stream response to file),
	 *                    limit (max response bytes), ok (accepted codes).
	 * @return array{code:int,headers:array,body:string}|WP_Error
	 */
	public function request( $method, $key, array $query = array(), array $opts = array() ) {
		$body    = isset( $opts['body'] ) ? (string) $opts['body'] : '';
		$ok      = $opts['ok'] ?? array( 200, 204, 206 );
		$attempt = 0;

		while ( true ) {
			++$attempt;
			$headers = $this->sign( $method, $key, $query, $opts['headers'] ?? array(), hash( 'sha256', $body ) );
			if ( ! empty( $opts['content_type'] ) ) {
				$headers['content-type'] = $opts['content_type'];
			}
			if ( isset( $opts['range'] ) ) {
				$headers['range'] = $opts['range'];
			}
			$args = array(
				'method'      => $method,
				'headers'     => $headers,
				'body'        => '' === $body ? null : $body,
				'timeout'     => $opts['timeout'] ?? 60,
				'redirection' => 0,
				'decompress'  => false,
				'user-agent'  => 'DigifixTotalCare/' . ( defined( 'DTC_VERSION' ) ? DTC_VERSION : '1' ),
			);
			if ( ! empty( $opts['filename'] ) ) {
				$args['stream']   = true;
				$args['filename'] = $opts['filename'];
			}
			if ( ! empty( $opts['limit'] ) ) {
				$args['limit_response_size'] = (int) $opts['limit'];
			}

			$res = wp_remote_request( $this->url( $key, $query ), $args );

			if ( is_wp_error( $res ) ) {
				if ( $attempt < 3 ) {
					sleep( $attempt );
					continue;
				}
				return new WP_Error( 'dtc_s3', 'S3 request failed: ' . $res->get_error_message() );
			}

			$code     = (int) wp_remote_retrieve_response_code( $res );
			$resp_hdr = wp_remote_retrieve_headers( $res );
			$resp_hdr = array_change_key_case( is_object( $resp_hdr ) && method_exists( $resp_hdr, 'getAll' ) ? $resp_hdr->getAll() : (array) $resp_hdr, CASE_LOWER );
			$resp     = (string) wp_remote_retrieve_body( $res );
			if ( ! empty( $opts['filename'] ) && ! in_array( $code, $ok, true ) && file_exists( $opts['filename'] ) ) {
				$resp = (string) file_get_contents( $opts['filename'], false, null, 0, 65536 );
			}

			if ( in_array( $code, $ok, true ) ) {
				// CompleteMultipartUpload can fail with a 200 response.
				if ( 'POST' === $method && false !== strpos( $resp, '<Error>' ) ) {
					return $this->error( $code, $resp );
				}
				return array(
					'code'    => $code,
					'headers' => $resp_hdr,
					'body'    => $resp,
				);
			}

			if ( 403 === $code && false !== strpos( $resp, 'RequestTimeTooSkewed' ) && $attempt < 3 && ! empty( $resp_hdr['date'] ) ) {
				$server = strtotime( is_array( $resp_hdr['date'] ) ? $resp_hdr['date'][0] : $resp_hdr['date'] );
				if ( $server ) {
					self::$skew = $server - time();
					continue;
				}
			}
			if ( ( $code >= 500 || 429 === $code ) && $attempt < 3 ) {
				sleep( $attempt * 2 );
				continue;
			}
			return $this->error( $code, $resp );
		}
	}

	private function error( $code, $body ) {
		$err = self::xml_value( $body, 'Code' );
		$msg = self::xml_value( $body, 'Message' );
		if ( 301 === $code && 'amazons3' === $this->cfg['type'] ) {
			$msg = 'The bucket is in a different region. Save the settings again to detect it. ' . $msg;
		}
		return new WP_Error( 'dtc_s3', trim( 'S3 error ' . $code . ( $err ? ' ' . $err : '' ) . ( $msg ? ': ' . $msg : '' ) ), array( 'code' => $code, 's3_code' => $err ) );
	}

	private static function xml_value( $xml, $tag ) {
		return preg_match( '#<' . $tag . '>(.*?)</' . $tag . '>#s', (string) $xml, $m ) ? html_entity_decode( $m[1], ENT_QUOTES | ENT_XML1, 'UTF-8' ) : '';
	}

	private static function xml_blocks( $xml, $tag ) {
		preg_match_all( '#<' . $tag . '>(.*?)</' . $tag . '>#s', (string) $xml, $m );
		return $m[1];
	}

	private static function xml_escape( $s ) {
		return htmlspecialchars( (string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	private static function header( array $headers, $name ) {
		$v = $headers[ $name ] ?? '';
		return is_array( $v ) ? (string) end( $v ) : (string) $v;
	}

	/* ---------------------------------------------------------------------
	 * Operations
	 * ------------------------------------------------------------------ */

	/**
	 * Find the region of an Amazon S3 bucket (unsigned request; S3 returns the
	 * x-amz-bucket-region header even for 301/403 responses).
	 */
	public static function detect_region( $bucket ) {
		$url = false !== strpos( $bucket, '.' ) ? 'https://s3.amazonaws.com/' . rawurlencode( $bucket ) : 'https://' . $bucket . '.s3.amazonaws.com/';
		$res = wp_remote_head( $url, array( 'timeout' => 15, 'redirection' => 0 ) );
		if ( is_wp_error( $res ) ) {
			return '';
		}
		$region = wp_remote_retrieve_header( $res, 'x-amz-bucket-region' );
		return is_array( $region ) ? (string) end( $region ) : (string) $region;
	}

	public function put( $key, $body, $content_type = 'application/octet-stream' ) {
		$res = $this->request( 'PUT', $key, array(), array( 'body' => $body, 'content_type' => $content_type, 'timeout' => 90 ) );
		return is_wp_error( $res ) ? $res : trim( self::header( $res['headers'], 'etag' ), '"' );
	}

	/** @return string|WP_Error|null Body, or null if the object does not exist. */
	public function get( $key, $limit = 0 ) {
		$res = $this->request( 'GET', $key, array(), array( 'ok' => array( 200, 404 ), 'limit' => $limit, 'timeout' => 60 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return 404 === $res['code'] ? null : $res['body'];
	}

	/** Download an object (or a byte range of it) into a local file. */
	public function get_to_file( $key, $file, $start = null, $end = null ) {
		$opts = array(
			'filename' => $file,
			'timeout'  => 120,
		);
		if ( null !== $start ) {
			$opts['range'] = 'bytes=' . (int) $start . '-' . (int) $end;
			$opts['limit'] = (int) $end - (int) $start + 1;
		}
		$res = $this->request( 'GET', $key, array(), $opts );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		clearstatcache( true, $file );
		if ( null !== $start ) {
			$want = (int) $end - (int) $start + 1;
			if ( 206 !== $res['code'] && ! ( 200 === $res['code'] && 0 === (int) $start ) ) {
				return new WP_Error( 'dtc_s3', 'The storage service ignored the byte range request (HTTP ' . $res['code'] . ').' );
			}
			if ( filesize( $file ) !== $want ) {
				return new WP_Error( 'dtc_s3', 'Short download for ' . $key . ': got ' . filesize( $file ) . ' of ' . $want . ' bytes.' );
			}
		}
		return true;
	}

	/** @return array{size:int,etag:string}|null|WP_Error */
	public function head( $key ) {
		$res = $this->request( 'HEAD', $key, array(), array( 'ok' => array( 200, 404 ), 'timeout' => 30 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( 404 === $res['code'] ) {
			return null;
		}
		return array(
			'size' => (int) self::header( $res['headers'], 'content-length' ),
			'etag' => trim( self::header( $res['headers'], 'etag' ), '"' ),
		);
	}

	/**
	 * ListObjectsV2, one page.
	 *
	 * @return array{objects:array,prefixes:array,next:string}|WP_Error
	 */
	public function list_page( $prefix, $delimiter = '', $token = '', $max = 1000 ) {
		$query = array(
			'list-type' => '2',
			'max-keys'  => (int) $max,
			'prefix'    => $prefix,
		);
		if ( '' !== $delimiter ) {
			$query['delimiter'] = $delimiter;
		}
		if ( '' !== $token ) {
			$query['continuation-token'] = $token;
		}
		$res = $this->request( 'GET', '', $query, array( 'timeout' => 60 ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$objects = array();
		foreach ( self::xml_blocks( $res['body'], 'Contents' ) as $block ) {
			$objects[] = array(
				'key'      => self::xml_value( $block, 'Key' ),
				'size'     => (int) self::xml_value( $block, 'Size' ),
				'modified' => (int) strtotime( self::xml_value( $block, 'LastModified' ) ),
			);
		}
		$prefixes = array();
		foreach ( self::xml_blocks( $res['body'], 'CommonPrefixes' ) as $block ) {
			$prefixes[] = self::xml_value( $block, 'Prefix' );
		}
		$truncated = 'true' === self::xml_value( $res['body'], 'IsTruncated' );
		return array(
			'objects'  => $objects,
			'prefixes' => $prefixes,
			'next'     => $truncated ? self::xml_value( $res['body'], 'NextContinuationToken' ) : '',
		);
	}

	/** All objects (and common prefixes) under a prefix. */
	public function list_all( $prefix, $delimiter = '' ) {
		$all   = array(
			'objects'  => array(),
			'prefixes' => array(),
		);
		$token = '';
		do {
			$page = $this->list_page( $prefix, $delimiter, $token );
			if ( is_wp_error( $page ) ) {
				return $page;
			}
			$all['objects']  = array_merge( $all['objects'], $page['objects'] );
			$all['prefixes'] = array_merge( $all['prefixes'], $page['prefixes'] );
			$token           = $page['next'];
		} while ( '' !== $token );
		return $all;
	}

	/** Delete objects in batches of 1000; falls back to single deletes. */
	public function delete( array $keys ) {
		foreach ( array_chunk( array_values( $keys ), 1000 ) as $chunk ) {
			$xml = '<?xml version="1.0" encoding="UTF-8"?><Delete><Quiet>true</Quiet>';
			foreach ( $chunk as $k ) {
				$xml .= '<Object><Key>' . self::xml_escape( $k ) . '</Key></Object>';
			}
			$xml .= '</Delete>';
			$res  = $this->request(
				'POST',
				'',
				array( 'delete' => '' ),
				array(
					'body'         => $xml,
					'content_type' => 'application/xml',
					'headers'      => array( 'content-md5' => base64_encode( md5( $xml, true ) ) ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				)
			);
			if ( is_wp_error( $res ) ) {
				$data = $res->get_error_data();
				if ( ! in_array( (int) ( $data['code'] ?? 0 ), array( 400, 405, 501 ), true ) ) {
					return $res;
				}
				foreach ( $chunk as $k ) {
					$one = $this->request( 'DELETE', $k, array(), array( 'ok' => array( 200, 204, 404 ) ) );
					if ( is_wp_error( $one ) ) {
						return $one;
					}
				}
				continue;
			}
			if ( false !== strpos( $res['body'], '<Error>' ) ) {
				return new WP_Error( 'dtc_s3', 'Some objects could not be deleted: ' . self::xml_value( $res['body'], 'Message' ) );
			}
		}
		return true;
	}

	/* ---------------------------------------------------------------------
	 * Multipart upload
	 * ------------------------------------------------------------------ */

	public function multipart_create( $key, $content_type = 'application/octet-stream' ) {
		$res = $this->request( 'POST', $key, array( 'uploads' => '' ), array( 'content_type' => $content_type ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id = self::xml_value( $res['body'], 'UploadId' );
		return '' !== $id ? $id : new WP_Error( 'dtc_s3', 'No UploadId in the multipart response.' );
	}

	/** Upload one part from a local file. @return string|WP_Error ETag. */
	public function multipart_part( $key, $upload_id, $number, $file ) {
		$body = (string) file_get_contents( $file );
		$res  = $this->request(
			'PUT',
			$key,
			array(
				'partNumber' => (int) $number,
				'uploadId'   => $upload_id,
			),
			array(
				'body'    => $body,
				'timeout' => 180,
			)
		);
		unset( $body );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$etag = self::header( $res['headers'], 'etag' );
		return '' !== $etag ? $etag : new WP_Error( 'dtc_s3', 'No ETag for part ' . $number . '.' );
	}

	/** @param array $etags part number => ETag. */
	public function multipart_complete( $key, $upload_id, array $etags ) {
		ksort( $etags, SORT_NUMERIC );
		$xml = '<?xml version="1.0" encoding="UTF-8"?><CompleteMultipartUpload>';
		foreach ( $etags as $n => $etag ) {
			$xml .= '<Part><PartNumber>' . (int) $n . '</PartNumber><ETag>' . self::xml_escape( $etag ) . '</ETag></Part>';
		}
		$xml .= '</CompleteMultipartUpload>';
		$res  = $this->request(
			'POST',
			$key,
			array( 'uploadId' => $upload_id ),
			array(
				'body'         => $xml,
				'content_type' => 'application/xml',
				'timeout'      => 120,
			)
		);
		return is_wp_error( $res ) ? $res : true;
	}

	public function multipart_abort( $key, $upload_id ) {
		$res = $this->request( 'DELETE', $key, array( 'uploadId' => $upload_id ), array( 'ok' => array( 200, 204, 404 ) ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/** @return array<int,array{key:string,upload_id:string,initiated:int}>|WP_Error */
	public function multipart_list( $prefix ) {
		$res = $this->request(
			'GET',
			'',
			array(
				'uploads' => '',
				'prefix'  => $prefix,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = array();
		foreach ( self::xml_blocks( $res['body'], 'Upload' ) as $block ) {
			$out[] = array(
				'key'       => self::xml_value( $block, 'Key' ),
				'upload_id' => self::xml_value( $block, 'UploadId' ),
				'initiated' => (int) strtotime( self::xml_value( $block, 'Initiated' ) ),
			);
		}
		return $out;
	}
}
