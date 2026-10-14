<?php
/**
 * Layout of backups in the bucket:
 *
 *   <folder>/site.json                    owner of the folder (home URL, UUID)
 *   <folder>/catalog.json                 cache of all backups (rebuildable)
 *   <folder>/backups/<id>/manifest.json   written last: a backup without it is incomplete
 *   <folder>/backups/<id>/db.sql.gz, core.tar.gz, …, index.tsv.gz, members.tsv.gz
 *   <folder>/backups/<id>/pinned          marker: never deleted by retention
 *
 * Backup IDs sort by time: 20261008-020000-fs-a1b2
 * (f full / i incremental; s scheduled, m manual, u before updates).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Remote {

	const ID_RE = '/^(\d{8})-(\d{6})-([fi])([smu])-([a-f0-9]{4})$/';

	/** @var DTC_Bk_S3 */
	public $s3;

	/** @var string Folder inside the bucket, no trailing slash. */
	public $folder;

	private $manifests = array();

	public function __construct( DTC_Bk_S3 $s3, $folder ) {
		$this->s3     = $s3;
		$this->folder = DTC_Bk_Util::clean_folder( $folder );
	}

	/**
	 * Client for the configured bucket.
	 *
	 * @param string|null $folder Another site's folder (cross-site restore).
	 * @return DTC_Bk_Remote|WP_Error
	 */
	public static function from_settings( $folder = null ) {
		$s      = DTC_Bk_Util::settings();
		$secret = DTC_Bk_Util::s3_secret();
		if ( ! $s['s3_access'] || ! $s['s3_bucket'] ) {
			return new WP_Error( 'dtc_remote', 'Remote storage is not configured (TotalCare → Settings → S3 storage).' );
		}
		if ( null === $secret || '' === $secret ) {
			return new WP_Error( 'dtc_remote', 'The S3 secret key is missing or can no longer be decrypted. Enter it again in TotalCare → Settings.' );
		}
		$s3 = new DTC_Bk_S3(
			array(
				'type'       => $s['s3_type'],
				'access'     => $s['s3_access'],
				'secret'     => $secret,
				'bucket'     => $s['s3_bucket'],
				'region'     => $s['s3_region'],
				'endpoint'   => $s['s3_endpoint'],
				'path_style' => $s['s3_path_style'],
			)
		);
		$folder = null !== $folder ? $folder : ( $s['s3_path'] ? $s['s3_path'] : DTC_Bk_Util::default_folder() );
		return new self( $s3, $folder );
	}

	/**
	 * Check every permission backups need. Scoped API tokens often allow
	 * uploads but not listing or deleting, which only shows up weeks later.
	 *
	 * @return true|WP_Error
	 */
	public function test() {
		$key  = $this->key( '.dtc-test-' . DTC_Bk_Util::random_hex( 4 ) . '.txt' );
		$body = 'TotalCare connection test ' . gmdate( 'c' );
		$steps = array(
			'upload'          => function () use ( $key, $body ) {
				return $this->s3->put( $key, $body, 'text/plain' );
			},
			'download'        => function () use ( $key, $body ) {
				$got = $this->s3->get( $key );
				return is_wp_error( $got ) ? $got : ( $got === $body ? true : new WP_Error( 'dtc_s3', 'the downloaded test file did not match.' ) );
			},
			'list'            => function () {
				return $this->s3->list_page( $this->key( '' ), '', '', 5 );
			},
			'multipart upload' => function () use ( $key ) {
				$id = $this->s3->multipart_create( $key . '.mp' );
				return is_wp_error( $id ) ? $id : $this->s3->multipart_abort( $key . '.mp', $id );
			},
			'delete'          => function () use ( $key ) {
				return $this->s3->delete( array( $key ) );
			},
		);
		foreach ( $steps as $name => $fn ) {
			$res = $fn();
			if ( is_wp_error( $res ) ) {
				if ( 'upload' !== $name ) {
					$this->s3->delete( array( $key ) );
				}
				return new WP_Error( 'dtc_s3', 'Storage test failed at "' . $name . '": ' . $res->get_error_message() );
			}
		}
		return true;
	}

	public function key( $rel ) {
		return ( '' !== $this->folder ? $this->folder . '/' : '' ) . ltrim( $rel, '/' );
	}

	public function backup_key( $id, $name ) {
		return $this->key( 'backups/' . $id . '/' . $name );
	}

	/* ---------------------------------------------------------------------
	 * IDs
	 * ------------------------------------------------------------------ */

	public static function new_id( $full, $trigger ) {
		$t = array(
			'schedule' => 's',
			'manual'   => 'm',
			'update'   => 'u',
		);
		return gmdate( 'Ymd-His' ) . '-' . ( $full ? 'f' : 'i' ) . ( $t[ $trigger ] ?? 'm' ) . '-' . DTC_Bk_Util::random_hex( 2 );
	}

	/** @return array{time:int,full:bool,trigger:string}|null */
	public static function parse_id( $id ) {
		if ( ! preg_match( self::ID_RE, (string) $id, $m ) ) {
			return null;
		}
		$t = array(
			's' => 'schedule',
			'm' => 'manual',
			'u' => 'update',
		);
		return array(
			'time'    => (int) gmmktime( (int) substr( $m[2], 0, 2 ), (int) substr( $m[2], 2, 2 ), (int) substr( $m[2], 4, 2 ), (int) substr( $m[1], 4, 2 ), (int) substr( $m[1], 6, 2 ), (int) substr( $m[1], 0, 4 ) ),
			'full'    => 'f' === $m[3],
			'trigger' => $t[ $m[4] ],
		);
	}

	/* ---------------------------------------------------------------------
	 * Folder ownership
	 * ------------------------------------------------------------------ */

	public static function site_uuid() {
		$uuid = get_option( 'dtc_site_uuid' );
		if ( ! is_string( $uuid ) || strlen( $uuid ) !== 32 ) {
			$uuid = DTC_Bk_Util::random_hex( 16 );
			update_option( 'dtc_site_uuid', $uuid, false );
		}
		return $uuid;
	}

	/** @return array|null|WP_Error */
	public function site_info() {
		$body = $this->s3->get( $this->key( 'site.json' ), 65536 );
		if ( is_wp_error( $body ) || null === $body ) {
			return $body;
		}
		$data = json_decode( $body, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * A staging copy keeps the live site's settings. Refuse to write into a
	 * folder that belongs to another home URL so it cannot overwrite or prune
	 * the live site's backups.
	 *
	 * @return true|WP_Error
	 */
	public function check_owner() {
		$info = $this->site_info();
		if ( is_wp_error( $info ) ) {
			return $info;
		}
		if ( null === $info ) {
			return $this->claim();
		}
		if ( ( $info['site_key'] ?? '' ) !== DTC_Bk_Util::site_key() ) {
			return new WP_Error( 'dtc_owner', 'The backup folder "' . $this->folder . '" belongs to ' . ( $info['home'] ?? 'another site' ) . '. If this is a staging copy, choose a different folder in TotalCare → Settings. If the site moved to this address, use "Take over this folder" on the Backups page.' );
		}
		return true;
	}

	public function claim() {
		$res = $this->s3->put(
			$this->key( 'site.json' ),
			DTC_Bk_Util::json_encode(
				array(
					'home'     => get_option( 'home' ),
					'site_key' => DTC_Bk_Util::site_key(),
					'uuid'     => self::site_uuid(),
					'claimed'  => time(),
				)
			),
			'application/json'
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/** Other site folders in the same bucket (for restoring onto a new install). */
	public function list_sites() {
		$parent = false !== strpos( $this->folder, '/' ) ? substr( $this->folder, 0, strrpos( $this->folder, '/' ) + 1 ) : '';
		$list   = $this->s3->list_all( $parent, '/' );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$sites = array();
		foreach ( array_slice( $list['prefixes'], 0, 200 ) as $prefix ) {
			$folder = rtrim( $prefix, '/' );
			$other  = new self( $this->s3, $folder );
			$info   = $other->site_info();
			if ( is_array( $info ) ) {
				$sites[ $folder ] = $info;
			}
		}
		return $sites;
	}

	/* ---------------------------------------------------------------------
	 * Manifests and listing
	 * ------------------------------------------------------------------ */

	/** @return array|null|WP_Error */
	public function manifest( $id ) {
		if ( isset( $this->manifests[ $id ] ) ) {
			return $this->manifests[ $id ];
		}
		$body = $this->s3->get( $this->backup_key( $id, 'manifest.json' ) );
		if ( is_wp_error( $body ) || null === $body ) {
			return $body;
		}
		$m = json_decode( $body, true );
		if ( ! is_array( $m ) || ( $m['id'] ?? '' ) !== $id ) {
			return new WP_Error( 'dtc_remote', 'Manifest of backup ' . $id . ' is unreadable.' );
		}
		$this->manifests[ $id ] = $m;
		return $m;
	}

	public function put_manifest( array $m ) {
		$this->manifests[ $m['id'] ] = $m;
		$res                         = $this->s3->put( $this->backup_key( $m['id'], 'manifest.json' ), DTC_Bk_Util::json_encode( $m ), 'application/json' );
		return is_wp_error( $res ) ? $res : true;
	}

	/**
	 * Every backup folder in the bucket, complete or not.
	 *
	 * @return array<string,array>|WP_Error id => objects (name => size),
	 *                                      complete, pinned, modified.
	 */
	public function scan() {
		$base = $this->key( 'backups/' );
		$list = $this->s3->list_all( $base );
		if ( is_wp_error( $list ) ) {
			return $list;
		}
		$out = array();
		foreach ( $list['objects'] as $o ) {
			$rest = substr( $o['key'], strlen( $base ) );
			$pos  = strpos( $rest, '/' );
			if ( false === $pos ) {
				continue;
			}
			$id   = substr( $rest, 0, $pos );
			$name = substr( $rest, $pos + 1 );
			if ( ! isset( $out[ $id ] ) ) {
				$out[ $id ] = array(
					'objects'  => array(),
					'complete' => false,
					'pinned'   => false,
					'modified' => 0,
				);
			}
			$out[ $id ]['objects'][ $name ] = $o['size'];
			$out[ $id ]['modified']         = max( $out[ $id ]['modified'], $o['modified'] );
			if ( 'manifest.json' === $name ) {
				$out[ $id ]['complete'] = true;
			} elseif ( 'pinned' === $name ) {
				$out[ $id ]['pinned'] = true;
			}
		}
		krsort( $out, SORT_STRING );
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Catalog (a cache; the manifests are the source of truth)
	 * ------------------------------------------------------------------ */

	public static function catalog_entry( array $m, $pinned = false ) {
		return array(
			'id'         => $m['id'],
			'created'    => (int) $m['created'],
			'type'       => $m['type'],
			'trigger'    => $m['trigger'],
			'size'       => (int) ( $m['stats']['size'] ?? 0 ),
			'files'      => (int) ( $m['stats']['files'] ?? 0 ),
			'changed'    => (int) ( $m['stats']['changed'] ?? 0 ),
			'db_size'    => (int) ( $m['objects']['db.sql.gz']['size'] ?? 0 ),
			'depends_on' => array_values( (array) ( $m['depends_on'] ?? array() ) ),
			'home'       => $m['site']['home'] ?? '',
			'wp'         => $m['site']['wp'] ?? '',
			'objects'    => array_values( array_diff( array_keys( (array) ( $m['objects'] ?? array() ) ), array( 'index.tsv.gz', 'members.tsv.gz' ) ) ),
			'pinned'     => (bool) $pinned,
		);
	}

	/** @return array|WP_Error Entries keyed by id, newest first. */
	public function catalog( $rebuild = false ) {
		if ( ! $rebuild ) {
			$body = $this->s3->get( $this->key( 'catalog.json' ) );
			if ( is_wp_error( $body ) ) {
				return $body;
			}
			$data = is_string( $body ) ? json_decode( $body, true ) : null;
			if ( is_array( $data ) && isset( $data['backups'] ) && is_array( $data['backups'] ) ) {
				return $data['backups'];
			}
		}
		return $this->rebuild_catalog();
	}

	public function rebuild_catalog() {
		$scan = $this->scan();
		if ( is_wp_error( $scan ) ) {
			return $scan;
		}
		$entries = array();
		foreach ( $scan as $id => $info ) {
			if ( ! $info['complete'] ) {
				continue;
			}
			$m = $this->manifest( $id );
			if ( is_array( $m ) ) {
				$entries[ $id ] = self::catalog_entry( $m, $info['pinned'] );
			}
		}
		$res = $this->save_catalog( $entries );
		return is_wp_error( $res ) ? $res : $entries;
	}

	public function save_catalog( array $entries ) {
		krsort( $entries, SORT_STRING );
		$res = $this->s3->put(
			$this->key( 'catalog.json' ),
			DTC_Bk_Util::json_encode(
				array(
					'updated' => time(),
					'backups' => $entries,
				)
			),
			'application/json'
		);
		return is_wp_error( $res ) ? $res : true;
	}

	/** Add or replace one entry; rebuilds if the cache is unreadable. */
	public function catalog_put( array $entry ) {
		$entries = $this->catalog();
		if ( is_wp_error( $entries ) ) {
			return $entries;
		}
		$entries[ $entry['id'] ] = $entry;
		return $this->save_catalog( $entries );
	}

	public function pin( $id, $pinned ) {
		$key = $this->backup_key( $id, 'pinned' );
		$res = $pinned ? $this->s3->put( $key, (string) time(), 'text/plain' ) : $this->s3->delete( array( $key ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$entries = $this->catalog();
		if ( is_array( $entries ) && isset( $entries[ $id ] ) ) {
			$entries[ $id ]['pinned'] = (bool) $pinned;
			$this->save_catalog( $entries );
		}
		return true;
	}

	/** Delete every object of the given backups. */
	public function delete_backups( array $ids, array $scan ) {
		$manifests = array();
		$keys      = array();
		foreach ( $ids as $id ) {
			foreach ( array_keys( $scan[ $id ]['objects'] ?? array() ) as $name ) {
				if ( 'manifest.json' === $name ) {
					$manifests[] = $this->backup_key( $id, $name );
				} else {
					$keys[] = $this->backup_key( $id, $name );
				}
			}
		}
		// Manifests go first, so a half-deleted backup is never mistaken
		// for a complete one.
		$res = $manifests ? $this->s3->delete( $manifests ) : true;
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return $keys ? $this->s3->delete( $keys ) : true;
	}
}
