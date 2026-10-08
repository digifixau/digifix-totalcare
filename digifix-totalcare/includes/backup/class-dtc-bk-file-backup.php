<?php
/**
 * File part of a backup: walks every area, compares each file with the
 * previous backup's index (size + mtime), adds changed files to the area's
 * tar.gz and writes the new full index.
 *
 * The walk is depth first with entries sorted by strcmp, so it can resume
 * from a small stack of (folder, last finished name) pairs, and the previous
 * index (written in the same order) is merge-joined in a single pass.
 *
 * Index row: area, path, type (f|l), size, mtime, mode, src backup id,
 * first member, last member, link target. mtime 0 forces the file into
 * the next backup (it changed while it was being read).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_File_Backup {

	const CHUNK = 1048576;
	const LEVEL = 3;

	/** @var DTC_Bk_Remote */
	private $remote;
	private $id;
	private $dir;
	private $st;
	private $full;
	private $patterns;
	private $roots;
	private $skip_dirs = array();

	/** @var DTC_Bk_Gz_Writer[] */
	private $writers = array();
	private $dirs    = array();

	private $prev_fh;
	private $prev_row;
	private $prev_row_off;
	private $prev_done = false;

	private $index_fh;

	public static function init_state() {
		return array(
			'area_i'    => 0,
			'stack'     => null,
			'cur'       => null,
			'prev_off'  => 0,
			'index_len' => 0,
			'writers'   => array(),
			'objects'   => array(),
			'deps'      => array(),
			'stats'     => array(
				'files'   => 0,
				'changed' => 0,
				'deleted' => 0,
				'bytes'   => 0,
				'skipped' => 0,
			),
			'warnings'  => array(),
			'done'      => false,
		);
	}

	/**
	 * @param string $prev_index Previous backup's plain index file, or '' for a full backup.
	 */
	public function __construct( DTC_Bk_Remote $remote, $id, $dir, array $state, $prev_index, array $patterns ) {
		$this->remote   = $remote;
		$this->id       = $id;
		$this->dir      = $dir;
		$this->st       = $state;
		$this->full     = '' === $prev_index;
		$this->patterns = $patterns;
		$this->roots    = DTC_Bk_Areas::roots();

		foreach ( $this->roots as $root ) {
			if ( '' !== $root ) {
				$this->skip_dirs[ $root ] = true;
			}
		}
		foreach ( DTC_Bk_Areas::protected_dirs() as $d ) {
			$this->skip_dirs[ $d ] = true;
		}

		if ( ! DTC_Bk_Util::truncate( $this->index_file(), $this->st['index_len'] ) ) {
			throw new DTC_Bk_Exception( 'The local file index is missing; the backup has to start again.' );
		}
		if ( ! $this->full ) {
			$this->prev_fh = fopen( $prev_index, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $this->prev_fh ) {
				throw new DTC_Bk_Exception( 'The previous file index could not be opened.' );
			}
			fseek( $this->prev_fh, (int) $this->st['prev_off'] );
			$this->prev_load();
		}
		foreach ( $this->st['writers'] as $area => $ws ) {
			$this->writers[ $area ] = new DTC_Bk_Gz_Writer( $this->remote->s3, $this->dir, $area, $ws['key'], $ws );
		}
	}

	public function index_file() {
		return $this->dir . '/index.tsv';
	}

	/** Persistable state. Only valid between members (after run()). */
	public function state() {
		foreach ( $this->writers as $area => $w ) {
			$this->st['writers'][ $area ] = $w->state();
		}
		return $this->st;
	}

	public function after_commit() {
		foreach ( $this->writers as $w ) {
			$w->after_commit();
		}
	}

	/** Abort unfinished multipart uploads (cancel / failure). */
	public static function abort( DTC_Bk_S3 $s3, array $state ) {
		foreach ( (array) ( $state['writers'] ?? array() ) as $ws ) {
			DTC_Bk_Gz_Writer::abort_state( $s3, $ws );
		}
	}

	/* ---------------------------------------------------------------------
	 * Previous index (merge-join)
	 * ------------------------------------------------------------------ */

	private function prev_load() {
		$this->prev_row = null;
		if ( ! $this->prev_fh ) {
			return;
		}
		while ( true ) {
			$this->prev_row_off = ftell( $this->prev_fh );
			$line               = fgets( $this->prev_fh );
			if ( false === $line ) {
				$this->prev_done = true;
				return;
			}
			$row = DTC_Bk_Util::tsv_parse( $line );
			if ( count( $row ) >= 9 ) {
				$ai = array_search( $row[0], DTC_Bk_Areas::ORDER, true );
				if ( false !== $ai ) {
					$row['ai']      = $ai;
					$this->prev_row = $row;
					return;
				}
			}
		}
	}

	private function prev_cmp( $ai, $path ) {
		if ( $this->prev_row['ai'] !== $ai ) {
			return $this->prev_row['ai'] < $ai ? -1 : 1;
		}
		return DTC_Bk_Util::path_cmp( $this->prev_row[1], $path );
	}

	/** Consume previous rows up to (ai, path); return the matching row. */
	private function prev_match( $ai, $path ) {
		while ( $this->prev_row ) {
			$c = $this->prev_cmp( $ai, $path );
			if ( $c < 0 ) {
				$this->st['stats']['deleted']++;
				$this->prev_load();
				continue;
			}
			if ( 0 === $c ) {
				$row = $this->prev_row;
				$this->prev_load();
				return $row;
			}
			break;
		}
		return null;
	}

	/** Rows of an area that no longer exist on disk. */
	private function prev_finish_area( $ai ) {
		while ( $this->prev_row && $this->prev_row['ai'] <= $ai ) {
			$this->st['stats']['deleted']++;
			$this->prev_load();
		}
	}

	private function sync_prev_off() {
		if ( $this->prev_fh ) {
			$this->st['prev_off'] = $this->prev_row ? $this->prev_row_off : ftell( $this->prev_fh );
		}
	}

	/* ---------------------------------------------------------------------
	 * Walk
	 * ------------------------------------------------------------------ */

	private function list_dir( $abs ) {
		if ( ! isset( $this->dirs[ $abs ] ) ) {
			$names = @scandir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $names ) {
				$this->warn( 'Folder not readable, skipped: ' . $abs );
				$names = array();
			}
			$names = array_values( array_diff( $names, array( '.', '..' ) ) );
			sort( $names, SORT_STRING );
			$this->dirs[ $abs ] = $names;
		}
		return $this->dirs[ $abs ];
	}

	/** First index in the sorted list with name > $after. */
	private static function after( array $names, $after ) {
		if ( '' === $after ) {
			return 0;
		}
		$lo = 0;
		$hi = count( $names );
		while ( $lo < $hi ) {
			$mid = ( $lo + $hi ) >> 1;
			if ( strcmp( $names[ $mid ], $after ) <= 0 ) {
				$lo = $mid + 1;
			} else {
				$hi = $mid;
			}
		}
		return $lo;
	}

	/** @return array|null Next file or link in the area, or null at the end. */
	private function next_entry( $area, $root ) {
		while ( $this->st['stack'] ) {
			$top   = count( $this->st['stack'] ) - 1;
			$frame = $this->st['stack'][ $top ];
			$abs   = '' === $frame['d'] ? $root : $root . '/' . $frame['d'];
			$names = $this->list_dir( $abs );
			$i     = self::after( $names, $frame['l'] );

			if ( $i >= count( $names ) ) {
				unset( $this->dirs[ $abs ] );
				array_pop( $this->st['stack'] );
				if ( $this->st['stack'] ) {
					$slash = strrpos( $frame['d'], '/' );
					$this->st['stack'][ $top - 1 ]['l'] = false === $slash ? $frame['d'] : substr( $frame['d'], $slash + 1 );
				}
				continue;
			}

			$name = $names[ $i ];
			$rel  = '' === $frame['d'] ? $name : $frame['d'] . '/' . $name;
			$path = $abs . '/' . $name;
			$st   = @lstat( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->st['stack'][ $top ]['l'] = $name;
			if ( ! $st ) {
				continue;
			}
			$fmt = $st['mode'] & 0170000;

			if ( 0040000 === $fmt ) {
				if ( isset( $this->skip_dirs[ $path ] )
					|| ( 'core' === $area && '' === $frame['d'] && ! in_array( $name, array( 'wp-admin', 'wp-includes' ), true ) )
					|| DTC_Bk_Areas::is_excluded( $this->patterns, DTC_Bk_Areas::logical( $area, $rel ), $name ) ) {
					continue;
				}
				// The parent is only listed again after this folder is popped,
				// so marking it finished now is safe.
				$this->st['stack'][] = array(
					'd' => $rel,
					'l' => '',
				);
				continue;
			}
			if ( 0100000 !== $fmt && 0120000 !== $fmt ) {
				continue;
			}
			if ( DTC_Bk_Areas::is_excluded( $this->patterns, DTC_Bk_Areas::logical( $area, $rel ), $name ) ) {
				continue;
			}
			// Not finished yet: the caller marks it when the file is done.
			$this->st['stack'][ $top ]['l'] = $frame['l'];
			return array(
				'rel'  => $rel,
				'name' => $name,
				'abs'  => $path,
				'type' => 0120000 === $fmt ? 'l' : 'f',
				'size' => 0120000 === $fmt ? 0 : (int) $st['size'],
				'mtime' => (int) $st['mtime'],
				'mode' => $st['mode'] & 07777,
			);
		}
		return null;
	}

	private function mark_done( $name ) {
		$top = count( $this->st['stack'] ) - 1;
		if ( $top >= 0 ) {
			$this->st['stack'][ $top ]['l'] = $name;
		}
	}

	/* ---------------------------------------------------------------------
	 * Run
	 * ------------------------------------------------------------------ */

	/** @return bool True when all areas are done. */
	public function run( $deadline ) {
		$this->index_fh = fopen( $this->index_file(), 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->index_fh ) {
			throw new DTC_Bk_Exception( 'Cannot write the file index in the backup work folder.' );
		}
		try {
			$done = $this->loop( $deadline );
		} finally {
			foreach ( $this->writers as $w ) {
				$w->end_member();
			}
			fclose( $this->index_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			clearstatcache( true, $this->index_file() );
			$this->st['index_len'] = filesize( $this->index_file() );
			$this->sync_prev_off();
		}
		return $done;
	}

	private function loop( $deadline ) {
		$order = DTC_Bk_Areas::ORDER;
		while ( $this->st['area_i'] < count( $order ) ) {
			$ai   = $this->st['area_i'];
			$area = $order[ $ai ];
			$root = $this->roots[ $area ];

			if ( '' === $root ) {
				$this->prev_finish_area( $ai );
				$this->st['area_i']++;
				continue;
			}
			if ( null === $this->st['stack'] ) {
				$this->st['stack'] = array(
					array(
						'd' => '',
						'l' => '',
					),
				);
			}

			while ( true ) {
				if ( microtime( true ) >= $deadline ) {
					return false;
				}
				if ( $this->st['cur'] ) {
					if ( ! $this->continue_file( $area, $root, $deadline ) ) {
						return false;
					}
					continue;
				}
				$e = $this->next_entry( $area, $root );
				if ( null === $e ) {
					break;
				}
				$this->start_entry( $ai, $area, $e );
			}

			// Area finished.
			if ( isset( $this->writers[ $area ] ) ) {
				$w = $this->writers[ $area ];
				if ( ! $w->member_open() ) {
					$w->begin_member( self::LEVEL, array( '', 0, 0 ) );
				}
				$w->write( DTC_Bk_Tar::eof() );
				$w->end_member();
				$this->st['objects'][ $area . '.tar.gz' ] = $w->finish() + array( 'area' => $area );
			}
			$this->prev_finish_area( $ai );
			$this->st['stack'] = null;
			$this->st['area_i']++;
		}
		$this->st['done'] = true;
		return true;
	}

	private function warn( $msg ) {
		if ( count( $this->st['warnings'] ) < 50 ) {
			$this->st['warnings'][] = $msg;
		}
	}

	private function index_row( array $row ) {
		fwrite( $this->index_fh, DTC_Bk_Util::tsv_row( $row ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	private function start_entry( $ai, $area, array $e ) {
		$link  = 'l' === $e['type'] ? (string) @readlink( $e['abs'] ) : ''; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$match = $this->prev_fh ? $this->prev_match( $ai, $e['rel'] ) : null;
		$this->st['stats']['files']++;

		$changed = $this->full || ! $match
			|| $match[2] !== $e['type']
			|| (int) $match[3] !== $e['size']
			|| (int) $match[4] !== $e['mtime']
			|| 0 === (int) $match[4]
			|| (string) ( $match[9] ?? '' ) !== $link
			|| '' === (string) $match[6];

		if ( ! $changed ) {
			$this->index_row( array( $area, $e['rel'], $e['type'], $e['size'], $e['mtime'], $e['mode'], $match[6], $match[7], $match[8], $link ) );
			$this->st['deps'][ $match[6] ] = 1;
			$this->mark_done( $e['name'] );
			return;
		}

		$this->st['cur'] = array(
			'rel'    => $e['rel'],
			'name'   => $e['name'],
			'type'   => $e['type'],
			'size'   => $e['size'],
			'mtime'  => $e['mtime'],
			'mode'   => $e['mode'],
			'link'   => $link,
			'done'   => 0,
			'hdr'    => false,
			'm1'     => 0,
			'forced' => false,
		);
	}

	private function writer( $area ) {
		if ( ! isset( $this->writers[ $area ] ) ) {
			$this->writers[ $area ] = new DTC_Bk_Gz_Writer( $this->remote->s3, $this->dir, $area, $this->remote->backup_key( $this->id, $area . '.tar.gz' ) );
		}
		return $this->writers[ $area ];
	}

	/** @return bool False when the deadline stopped it mid-file. */
	private function continue_file( $area, $root, $deadline ) {
		$c   = $this->st['cur'];
		$abs = $root . '/' . $c['rel'];
		$w   = $this->writer( $area );
		$fh  = null;

		if ( 'f' === $c['type'] ) {
			$fh = @fopen( $abs, 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh ) {
				if ( ! $c['hdr'] ) {
					$this->warn( 'Not readable, skipped: ' . DTC_Bk_Areas::logical( $area, $c['rel'] ) );
					$this->st['stats']['skipped']++;
					$this->st['cur'] = null;
					$this->mark_done( $c['name'] );
					return true;
				}
				$c['forced'] = true; // Header already written: pad with zeros below.
			}
		}

		if ( ! $c['hdr'] ) {
			$level = DTC_Bk_Areas::compressible( $c['name'] ) ? self::LEVEL : 0;
			if ( $w->member_open() && ( $w->member_full() || ( $w->member_level() !== $level && ( $c['size'] >= 262144 || $w->member_raw() >= 4194304 ) ) ) ) {
				$w->end_member();
			}
			if ( ! $w->member_open() ) {
				$w->begin_member( $level, array( '', 0, 0 ) );
			}
			$c['m1'] = (int) $w->state()['members'];
			$w->write( DTC_Bk_Tar::header( $c['rel'], $c['size'], $c['mtime'], $c['mode'], 'l' === $c['type'] ? '2' : '0', $c['link'] ) );
			$c['hdr'] = true;
		}

		if ( $fh && $c['done'] > 0 ) {
			fseek( $fh, $c['done'] );
		}
		while ( $c['done'] < $c['size'] ) {
			if ( microtime( true ) >= $deadline ) {
				if ( $fh ) {
					fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				}
				$this->st['cur'] = $c;
				// Members end here; the next one starts inside this file.
				return false;
			}
			if ( ! $w->member_open() || $w->member_full() ) {
				$level = $w->member_open() ? $w->member_level() : ( DTC_Bk_Areas::compressible( $c['name'] ) ? self::LEVEL : 0 );
				$w->end_member();
				$w->begin_member( $level, array( $c['rel'], $c['size'], $c['done'] ) );
			}
			$want  = (int) min( self::CHUNK, $c['size'] - $c['done'] );
			$chunk = $fh ? fread( $fh, $want ) : '';
			if ( false === $chunk || '' === $chunk ) {
				$chunk       = str_repeat( "\0", $want );
				$c['forced'] = true;
			}
			$w->write( $chunk );
			$c['done'] += strlen( $chunk );
		}
		$w->write( str_repeat( "\0", DTC_Bk_Tar::pad( $c['size'] ) ) );

		if ( $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			clearstatcache( true, $abs );
			if ( @filemtime( $abs ) !== $c['mtime'] || @filesize( $abs ) !== $c['size'] ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$c['forced'] = true;
			}
		}

		$this->index_row( array( $area, $c['rel'], $c['type'], $c['size'], $c['forced'] ? 0 : $c['mtime'], $c['mode'], $this->id, $c['m1'], (int) $w->state()['members'], $c['link'] ) );
		$this->st['deps'][ $this->id ] = 1;
		$this->st['stats']['changed']++;
		$this->st['stats']['bytes'] += $c['size'];
		$this->st['cur']             = null;
		$this->mark_done( $c['name'] );
		return true;
	}
}
