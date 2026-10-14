<?php
/**
 * Streams a gzip object to S3 without keeping a local copy.
 *
 * The object is a series of gzip members (a valid .gz file: gzip readers
 * concatenate members). A member is always finished before the request
 * ends, so no deflate state has to survive between requests. Compressed
 * bytes go into 8 MiB segment files that are uploaded as multipart parts as
 * soon as they are full; only the current segment stays on disk.
 *
 * Crash safety: the caller persists state() only between members. On resume
 * the segment and member files are cut back to that committed state, and
 * any work after it is redone (re-uploading a part number replaces it).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Exception extends Exception {}

class DTC_Bk_Gz_Writer {

	const MAX_MEMBER_COMPRESSED = 16777216; // 16 MiB: one member fits one restore request.
	const MAX_MEMBER_RAW        = 67108864; // 64 MiB.

	/** @var DTC_Bk_S3 */
	private $s3;
	private $dir;
	private $name;
	private $state;

	// Open member (this request only).
	private $ctx;
	private $hash;
	private $m_comp  = 0;
	private $m_raw   = 0;
	private $m_level = 6;
	private $m_meta  = array();

	private $seg_fh;
	private $uploaded = array(); // Segment files uploaded this request; deleted after commit.

	public function __construct( DTC_Bk_S3 $s3, $dir, $name, $key, array $state = null ) {
		$this->s3    = $s3;
		$this->dir   = $dir;
		$this->name  = $name;
		$this->state = $state ? $state : array(
			'key'        => $key,
			'upload_id'  => '',
			'etags'      => array(),
			'seg_n'      => 1,
			'seg_len'    => 0,
			'total'      => 0,
			'raw_total'  => 0,
			'members'    => 0,
			'mfile_len'  => 0,
			'finished'   => false,
		);
		$this->resume();
	}

	public function state() {
		return $this->state;
	}

	public function key() {
		return $this->state['key'];
	}

	public function members_file() {
		return $this->dir . '/' . $this->name . '.members.tsv';
	}

	private function seg_file( $n ) {
		return $this->dir . '/' . $this->name . '.seg' . (int) $n;
	}

	/** Cut local files back to the committed state. */
	private function resume() {
		if ( $this->state['finished'] ) {
			return;
		}
		$n = (int) $this->state['seg_n'];
		if ( ! DTC_Bk_Util::truncate( $this->seg_file( $n ), $this->state['seg_len'] ) ) {
			throw new DTC_Bk_Exception( 'Local spool file for ' . $this->name . ' is missing or shorter than expected; the backup has to start again.' );
		}
		for ( $i = $n + 1; file_exists( $this->seg_file( $i ) ); $i++ ) {
			@unlink( $this->seg_file( $i ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( ! DTC_Bk_Util::truncate( $this->members_file(), $this->state['mfile_len'] ) ) {
			throw new DTC_Bk_Exception( 'Local member index for ' . $this->name . ' is missing; the backup has to start again.' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Members
	 * ------------------------------------------------------------------ */

	public function member_open() {
		return null !== $this->ctx;
	}

	public function member_level() {
		return $this->m_level;
	}

	public function member_full() {
		return $this->m_comp >= self::MAX_MEMBER_COMPRESSED || $this->m_raw >= self::MAX_MEMBER_RAW;
	}

	public function member_raw() {
		return $this->m_raw;
	}

	/** Offset of the next uncompressed byte within the whole object. */
	public function raw_offset() {
		return (int) $this->state['raw_total'] + $this->m_raw;
	}

	/**
	 * @param int   $level 0 (stored) to 9.
	 * @param array $meta  Extra fields stored with the member (strings).
	 */
	public function begin_member( $level, array $meta = array() ) {
		if ( $this->ctx ) {
			throw new DTC_Bk_Exception( 'Member already open.' );
		}
		$this->ctx     = deflate_init( ZLIB_ENCODING_GZIP, array( 'level' => (int) $level ) );
		$this->hash    = hash_init( 'sha256' );
		$this->m_comp  = 0;
		$this->m_raw   = 0;
		$this->m_level = (int) $level;
		$this->m_meta  = $meta;
		if ( ! $this->ctx ) {
			throw new DTC_Bk_Exception( 'zlib deflate_init() failed.' );
		}
	}

	public function write( $raw ) {
		if ( ! $this->ctx ) {
			throw new DTC_Bk_Exception( 'No open member.' );
		}
		$len = strlen( $raw );
		if ( ! $len ) {
			return;
		}
		$this->m_raw += $len;
		$out          = deflate_add( $this->ctx, $raw, ZLIB_NO_FLUSH );
		if ( '' !== $out ) {
			$this->emit( $out );
		}
	}

	public function end_member() {
		if ( ! $this->ctx ) {
			return;
		}
		$this->emit( deflate_add( $this->ctx, '', ZLIB_FINISH ) );
		$this->ctx = null;
		if ( $this->seg_fh ) {
			fflush( $this->seg_fh );
		}

		$row = array_merge(
			array(
				(int) $this->state['members'],
				(int) $this->state['total'],
				$this->m_comp,
				hash_final( $this->hash ),
				(int) $this->state['raw_total'],
				$this->m_raw,
			),
			array_values( $this->m_meta )
		);
		$line = DTC_Bk_Util::tsv_row( $row );
		if ( false === file_put_contents( $this->members_file(), $line, FILE_APPEND ) ) {
			throw new DTC_Bk_Exception( 'Could not write ' . $this->members_file() );
		}
		$this->state['mfile_len'] += strlen( $line );
		$this->state['total']     += $this->m_comp;
		$this->state['raw_total'] += $this->m_raw;
		$this->state['members']++;
		$this->m_comp = 0;
		$this->m_raw  = 0;
	}

	/* ---------------------------------------------------------------------
	 * Segments and parts
	 * ------------------------------------------------------------------ */

	private function emit( $bytes ) {
		hash_update( $this->hash, $bytes );
		$this->m_comp += strlen( $bytes );

		while ( '' !== $bytes ) {
			if ( ! $this->seg_fh ) {
				$this->seg_fh = fopen( $this->seg_file( $this->state['seg_n'] ), 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
				if ( ! $this->seg_fh ) {
					throw new DTC_Bk_Exception( 'Cannot write to the backup work folder (disk full or not writable).' );
				}
			}
			$room  = DTC_Bk_S3::PART_SIZE - (int) $this->state['seg_len'];
			$chunk = strlen( $bytes ) > $room ? substr( $bytes, 0, $room ) : $bytes;
			if ( fwrite( $this->seg_fh, $chunk ) !== strlen( $chunk ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
				throw new DTC_Bk_Exception( 'Write to the backup work folder failed (disk full?).' );
			}
			$this->state['seg_len'] += strlen( $chunk );
			$bytes                   = (string) substr( $bytes, strlen( $chunk ) );

			if ( (int) $this->state['seg_len'] >= DTC_Bk_S3::PART_SIZE ) {
				$this->upload_segment();
			}
		}
	}

	private function upload_segment() {
		fclose( $this->seg_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		$this->seg_fh = null;
		$n            = (int) $this->state['seg_n'];

		if ( '' === $this->state['upload_id'] ) {
			$id = $this->s3->multipart_create( $this->state['key'], 'application/gzip' );
			if ( is_wp_error( $id ) ) {
				throw new DTC_Bk_Exception( $id->get_error_message() );
			}
			$this->state['upload_id'] = $id;
		}
		$etag = $this->s3->multipart_part( $this->state['key'], $this->state['upload_id'], $n, $this->seg_file( $n ) );
		if ( is_wp_error( $etag ) ) {
			throw new DTC_Bk_Exception( $etag->get_error_message() );
		}
		$this->state['etags'][ $n ] = $etag;
		$this->uploaded[]           = $this->seg_file( $n );
		$this->state['seg_n']       = $n + 1;
		$this->state['seg_len']     = 0;
	}

	/** Call after the caller has persisted state(). */
	public function after_commit() {
		foreach ( $this->uploaded as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		$this->uploaded = array();
	}

	/**
	 * Upload what is left and complete the object.
	 *
	 * @return array{size:int,members:int,raw:int}
	 */
	public function finish() {
		if ( $this->state['finished'] ) {
			return $this->summary();
		}
		if ( $this->ctx ) {
			throw new DTC_Bk_Exception( 'finish() with an open member.' );
		}
		if ( $this->seg_fh ) {
			fclose( $this->seg_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$this->seg_fh = null;
		}
		$n    = (int) $this->state['seg_n'];
		$file = $this->seg_file( $n );

		if ( '' === $this->state['upload_id'] ) {
			$res = $this->s3->put( $this->state['key'], (string) file_get_contents( $file ), 'application/gzip' );
		} else {
			$res = true;
			if ( (int) $this->state['seg_len'] > 0 ) {
				$res = $this->s3->multipart_part( $this->state['key'], $this->state['upload_id'], $n, $file );
				if ( ! is_wp_error( $res ) ) {
					$this->state['etags'][ $n ] = $res;
				}
			}
			if ( ! is_wp_error( $res ) ) {
				$res = $this->s3->multipart_complete( $this->state['key'], $this->state['upload_id'], $this->state['etags'] );
			}
			// A request that completed the upload but died before saving
			// leaves an upload id that no longer exists: accept the object
			// if it is already there with the expected size.
			if ( is_wp_error( $res ) && 'NoSuchUpload' === ( $res->get_error_data()['s3_code'] ?? '' ) ) {
				$head = $this->s3->head( $this->state['key'] );
				if ( is_array( $head ) && (int) $head['size'] === (int) $this->state['total'] ) {
					$res = true;
				}
			}
		}
		if ( is_wp_error( $res ) ) {
			throw new DTC_Bk_Exception( $res->get_error_message() );
		}

		$head = $this->s3->head( $this->state['key'] );
		if ( is_array( $head ) && (int) $head['size'] !== (int) $this->state['total'] ) {
			throw new DTC_Bk_Exception( 'Uploaded ' . $this->state['key'] . ' has ' . $head['size'] . ' bytes, expected ' . $this->state['total'] . '.' );
		}
		// Deleted only after the caller saved the finished state: if this
		// request dies first, the next one needs the file again.
		$this->uploaded[]        = $file;
		$this->state['finished'] = true;
		$this->state['etags']    = array();
		return $this->summary();
	}

	public function summary() {
		return array(
			'size'    => (int) $this->state['total'],
			'members' => (int) $this->state['members'],
			'raw'     => (int) $this->state['raw_total'],
		);
	}

	/** Abort an unfinished upload and remove local files. */
	public static function abort_state( DTC_Bk_S3 $s3, array $state ) {
		if ( empty( $state['finished'] ) && ! empty( $state['upload_id'] ) ) {
			$s3->multipart_abort( $state['key'], $state['upload_id'] );
		}
	}
}
