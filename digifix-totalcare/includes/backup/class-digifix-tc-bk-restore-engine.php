<?php
/**
 * Restore engine. Driven by the guardian mu-plugin, one short request at a
 * time, before regular plugins and the theme load.
 *
 * Phases:
 *   preflight  checks, migration map (site stays up)
 *   plan       read the backup's file index, keep only files that differ
 *   db         import into dtcr_* temp tables (site stays up)
 *   files      uploads first (site stays up), then code behind the
 *              maintenance page
 *   clean      remove code files that are not in the backup
 *   swap       keep a few current options, atomic RENAME TABLE
 *   finish     flush caches
 *
 * Only the byte ranges of the archives that contain needed files are
 * downloaded, one gzip member (at most 16 MB) at a time.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Restore_Engine {

	const DL_CHUNK = 4194304;

	/** Code areas that "clean" may prune. */
	const CLEANABLE = array( 'core', 'plugins', 'themes' );

	/** Restore order: uploads while the site is up, mu-plugins last. */
	const FILE_ORDER = array( 'uploads', 'core', 'plugins', 'themes', 'content', 'muplugins' );

	private $req;
	private $e;
	private $deadline;

	/** @var DTC_Bk_Remote */
	private $remote;
	private $dir;
	private $members = array();

	// Extraction (files phase).
	private $row;
	private $row_fh;
	private $out_fh;
	private $stop_member = false;
	private $cur_member  = 0;

	/**
	 * Advance the restore until the deadline.
	 *
	 * @return array The updated request.
	 */
	public static function run( array $req, $deadline ) {
		$engine = new self( $req, $deadline );
		return $engine->step();
	}

	private function __construct( array $req, $deadline ) {
		$this->req      = $req;
		$this->e        = $req['es'] ?? array( 'phase' => 'preflight' );
		$this->deadline = $deadline;
		$this->dir      = DTC_Bk_Util::run_dir( 'restore-' . preg_replace( '/[^a-z0-9-]/', '', (string) $req['backup_id'] ) );
	}

	private function note( $msg ) {
		$this->req['log'] = array_slice( array_merge( (array) ( $this->req['log'] ?? array() ), array( gmdate( 'H:i:s' ) . ' ' . $msg ) ), -60 );
	}

	private function progress( $msg ) {
		$this->req['progress'] = $msg;
	}

	private function out() {
		$this->req['es'] = $this->e;
		return $this->req;
	}

	private function time_left() {
		return microtime( true ) < $this->deadline;
	}

	private function remote() {
		if ( ! $this->remote ) {
			$remote = DTC_Bk_Remote::from_settings( $this->req['folder'] ?? null );
			if ( is_wp_error( $remote ) ) {
				throw new DTC_Bk_Exception( $remote->get_error_message() );
			}
			$this->remote = $remote;
		}
		return $this->remote;
	}

	private function step() {
		DTC_Bk_Util::extend_time_limit();
		while ( $this->time_left() && 'running' === ( $this->req['status'] ?? '' ) ) {
			$phase = $this->e['phase'];
			$this->{'phase_' . $phase}();
			if ( ! empty( $this->e['commit'] ) ) {
				// Something must be persisted before going on (e.g. the
				// maintenance page has to be visible to other requests).
				unset( $this->e['commit'] );
				break;
			}
		}
		return $this->out();
	}

	/* ---------------------------------------------------------------------
	 * Phases
	 * ------------------------------------------------------------------ */

	private function phase_preflight() {
		// Files from an earlier, abandoned restore of the same backup (plan,
		// database checkpoint) must not be reused.
		DTC_Bk_Util::rrmdir( $this->dir );
		$this->dir = DTC_Bk_Util::run_dir( basename( $this->dir ) );

		$remote = $this->remote();
		$m      = $remote->manifest( $this->req['backup_id'] );
		if ( is_wp_error( $m ) ) {
			throw new DTC_Bk_Exception( $m->get_error_message() );
		}
		if ( ! $m ) {
			throw new DTC_Bk_Exception( 'Backup ' . $this->req['backup_id'] . ' was not found in "' . $remote->folder . '" (or is incomplete).' );
		}
		if ( (int) ( $m['format'] ?? 0 ) > DTC_Bk_Util::FORMAT ) {
			throw new DTC_Bk_Exception( 'This backup was made by a newer TotalCare version. Update TotalCare first.' );
		}

		$scope = (array) ( $this->req['scope'] ?? array() );
		$areas = array_values( array_intersect( self::FILE_ORDER, $scope ) );
		$db    = in_array( 'db', $scope, true ) && isset( $m['objects']['db.sql.gz'] );
		if ( ! $areas && ! $db ) {
			throw new DTC_Bk_Exception( 'Nothing to restore: the selected parts are not in this backup.' );
		}

		$same = ( $m['site']['site_key'] ?? '' ) === DTC_Bk_Util::site_key();
		if ( $db ) {
			$problems = DTC_Bk_Db_Import::preflight( (int) ( $m['stats']['max_stmt'] ?? 0 ) );
			if ( $problems ) {
				throw new DTC_Bk_Exception( implode( ' ', $problems ) );
			}
			// A new restore replaces any rollback copies of the previous one.
			DTC_Bk_Db_Import::drop_old( 0 );
			DTC_Bk_Db_Import::drop_temp();
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $this->dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $free && $free < 64 * 1048576 ) {
			throw new DTC_Bk_Exception( 'Less than 64 MB of free disk space.' );
		}

		$from = array(
			'home'        => $m['site']['home'] ?? '',
			'siteurl'     => $m['site']['siteurl'] ?? '',
			'abspath'     => $m['site']['abspath'] ?? '',
			'content_dir' => $m['site']['content_dir'] ?? '',
		);
		$to   = array(
			'home'        => get_option( 'home' ),
			'siteurl'     => get_option( 'siteurl' ),
			'abspath'     => ABSPATH,
			'content_dir' => WP_CONTENT_DIR,
		);
		$map  = DTC_Bk_Replace::pairs( $from, $to );

		$this->e = array(
			'phase'    => $areas ? 'plan' : 'db',
			'same'     => $same,
			'areas'    => $areas,
			'db'       => $db,
			'map'      => $map,
			'needles'  => DTC_Bk_Replace::needles( $map ),
			'excludes' => (string) ( $m['excludes'] ?? '' ),
			'site'     => $m['site'],
			'plan'     => array(
				'gzpos' => 0,
				'files' => array(),
				'idx'   => array(),
				'rows'  => 0,
				'skip'  => 0,
			),
			'stats'    => array(
				'files' => 0,
				'bytes' => 0,
			),
		);
		$this->note( 'Restoring backup ' . $this->req['backup_id'] . ' (' . ( $m['type'] ?? '' ) . ', ' . gmdate( 'Y-m-d H:i', (int) $m['created'] ) . ' UTC) — ' . implode( ', ', array_merge( $db ? array( 'database' ) : array(), $areas ) ) . ( $same ? '' : '; moving from ' . $from['home'] . ' to ' . $to['home'] ) . '.' );
	}

	/** Paths that are never written by a restore. */
	private function protected_path( $area, $path ) {
		$basename = (string) ( $this->req['plugin_basename'] ?? '' );
		$tc       = false !== strpos( $basename, '/' ) ? substr( $basename, 0, strpos( $basename, '/' ) ) : '';
		$first    = false !== strpos( $path, '/' ) ? substr( $path, 0, strpos( $path, '/' ) ) : $path;
		switch ( $area ) {
			case 'core':
				if ( 'wp-config.php' === $path || ( '.htaccess' === $path && empty( $this->req['htaccess'] ) ) ) {
					return true;
				}
				if ( ! $this->e['same'] && in_array( $path, array( '.user.ini', 'php.ini', 'wordfence-waf.php' ), true ) ) {
					return true;
				}
				break;
			case 'content':
				if ( in_array( $first, array( 'dtc-data', 'dtc-rollback' ), true ) || 'fatal-error-handler.php' === $path ) {
					return true;
				}
				if ( ! $this->e['same'] && ( in_array( $path, array( 'object-cache.php', 'advanced-cache.php', 'db.php' ), true ) || 'wflogs' === $first ) ) {
					return true;
				}
				break;
			case 'plugins':
				if ( '' !== $tc && $first === $tc ) {
					return true;
				}
				break;
			case 'muplugins':
				if ( 'dtc-guardian.php' === $path ) {
					return true;
				}
				break;
		}
		return false;
	}

	private function in_paths( $area, $path ) {
		$paths = (array) ( $this->req['paths'] ?? array() );
		if ( ! $paths ) {
			return true;
		}
		$logical = DTC_Bk_Areas::logical( $area, $path );
		foreach ( $paths as $p ) {
			$p = trim( $p, '/' );
			if ( $logical === $p || 0 === strpos( $logical, $p . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	private function phase_plan() {
		$index = $this->dir . '/index.tsv.gz';
		if ( ! file_exists( $index ) || empty( $this->e['plan']['index_ok'] ) ) {
			$this->progress( 'Downloading the file index.' );
			$res = $this->remote()->s3->get_to_file( $this->remote()->backup_key( $this->req['backup_id'], 'index.tsv.gz' ), $index );
			if ( is_wp_error( $res ) ) {
				throw new DTC_Bk_Exception( 'Could not download the file index: ' . $res->get_error_message() );
			}
			$this->e['plan']['index_ok'] = true;
			return;
		}

		$plan  = &$this->e['plan'];
		$roots = DTC_Bk_Areas::roots();
		$clean = ! empty( $this->req['clean'] );

		// Cut plan files back to what was committed; files created by a
		// request that died before saving start empty again.
		$known = array();
		foreach ( $plan['files'] as $f ) {
			DTC_Bk_Util::truncate( $this->dir . '/' . $f['file'], $f['len'] );
			$known[ $f['file'] ] = true;
		}
		foreach ( $plan['idx'] as $area => $len ) {
			DTC_Bk_Util::truncate( $this->dir . '/idx-' . $area . '.tsv', $len );
			$known[ 'idx-' . $area . '.tsv' ] = true;
		}
		foreach ( array_merge( (array) glob( $this->dir . '/plan-*.tsv' ), (array) glob( $this->dir . '/idx-*.tsv' ) ) as $stale ) {
			if ( ! isset( $known[ basename( $stale ) ] ) ) {
				@unlink( $stale ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}

		$gz = gzopen( $index, 'rb' );
		if ( ! $gz ) {
			throw new DTC_Bk_Exception( 'Cannot read the downloaded file index.' );
		}
		if ( $plan['gzpos'] ) {
			gzseek( $gz, (int) $plan['gzpos'] );
		}
		$handles = array();
		$n       = 0;
		$eof     = false;
		$this->progress( 'Comparing ' . $plan['rows'] . ' files with the backup.' );

		while ( true ) {
			if ( 0 === ( ++$n % 500 ) && ! $this->time_left() ) {
				break;
			}
			$line = gzgets( $gz );
			if ( false === $line ) {
				$eof = gzeof( $gz );
				if ( ! $eof ) {
					throw new DTC_Bk_Exception( 'The file index is corrupt.' );
				}
				break;
			}
			$plan['gzpos'] = gztell( $gz );
			$r             = DTC_Bk_Util::tsv_parse( $line );
			if ( count( $r ) < 9 || ! in_array( $r[0], $this->e['areas'], true ) ) {
				continue;
			}
			list( $area, $path, $type, $size, $mtime, $mode, $src, $m1, $m2 ) = $r;
			$link = $r[9] ?? '';
			if ( ! $this->in_paths( $area, $path ) || false !== strpos( "/$path/", '/../' ) || '' === $roots[ $area ] ) {
				continue;
			}
			++$plan['rows'];
			if ( $clean && in_array( $area, self::CLEANABLE, true ) ) {
				$this->append( $handles, 'idx-' . $area . '.tsv', DTC_Bk_Util::tsv_row( array( $path ) ) );
			}
			if ( $this->protected_path( $area, $path ) ) {
				continue;
			}
			$target = $roots[ $area ] . '/' . $path;
			$st     = @lstat( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( $st ) {
				$fmt = $st['mode'] & 0170000;
				if ( 'f' === $type && 0100000 === $fmt && (int) $st['size'] === (int) $size && (int) $st['mtime'] === (int) $mtime && (int) $mtime > 0 ) {
					++$plan['skip'];
					continue;
				}
				if ( 'l' === $type && 0120000 === $fmt && (string) @readlink( $target ) === $link ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					++$plan['skip'];
					continue;
				}
			}
			$key = $area . '|' . $src;
			if ( ! isset( $plan['files'][ $key ] ) ) {
				$plan['files'][ $key ] = array(
					'area' => $area,
					'src'  => $src,
					'file' => 'plan-' . count( $plan['files'] ) . '.tsv',
					'len'  => 0,
					'rows' => 0,
				);
			}
			$plan['files'][ $key ]['rows']++;
			$this->append( $handles, $plan['files'][ $key ]['file'], DTC_Bk_Util::tsv_row( array( $path, $type, $size, $mtime, $mode, $m1, $m2, $link ) ) );
		}
		gzclose( $gz );
		foreach ( $handles as $name => $fh ) {
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			clearstatcache( true, $this->dir . '/' . $name );
			$len = filesize( $this->dir . '/' . $name );
			if ( 0 === strpos( $name, 'idx-' ) ) {
				$plan['idx'][ substr( $name, 4, -4 ) ] = $len;
			} else {
				foreach ( $plan['files'] as $k => $f ) {
					if ( $f['file'] === $name ) {
						$plan['files'][ $k ]['len'] = $len;
					}
				}
			}
		}

		if ( $eof ) {
			// Order: area (uploads first), then older backups first.
			$files = array_values( $plan['files'] );
			usort(
				$files,
				function ( $a, $b ) {
					$x = array_search( $a['area'], self::FILE_ORDER, true ) - array_search( $b['area'], self::FILE_ORDER, true );
					return $x ? $x : strcmp( $a['src'], $b['src'] );
				}
			);
			$total = 0;
			foreach ( $files as $f ) {
				$total += $f['rows'];
			}
			$this->e['files'] = $files;
			$this->e['fi']    = 0;
			$this->e['f']     = null;
			$this->note( $total . ' file(s) to restore; ' . $plan['skip'] . ' already identical on this site.' );
			$this->e['phase'] = $this->e['db'] ? 'db' : 'files';
			@unlink( $index ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	private function append( array &$handles, $name, $data ) {
		if ( ! isset( $handles[ $name ] ) ) {
			$handles[ $name ] = fopen( $this->dir . '/' . $name, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $handles[ $name ] ) {
				throw new DTC_Bk_Exception( 'Cannot write to the restore work folder.' );
			}
		}
		fwrite( $handles[ $name ], $data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
	}

	/* ---------------------------------------------------------------------
	 * Members: download and inflate
	 * ------------------------------------------------------------------ */

	/** Member rows of one object in one backup. */
	private function members( $src, $object ) {
		$key = $src . '|' . $object;
		if ( isset( $this->members[ $key ] ) ) {
			return $this->members[ $key ];
		}
		$file = $this->dir . '/members-' . $src . '.tsv.gz';
		if ( ! file_exists( $file ) ) {
			$tmp = $file . '.part';
			$res = $this->remote()->s3->get_to_file( $this->remote()->backup_key( $src, 'members.tsv.gz' ), $tmp );
			if ( is_wp_error( $res ) ) {
				throw new DTC_Bk_Exception( 'Could not download the archive index of backup ' . $src . ': ' . $res->get_error_message() );
			}
			rename( $tmp, $file );
		}
		$rows = array();
		$gz   = gzopen( $file, 'rb' );
		while ( $gz && false !== ( $line = gzgets( $gz ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$r = DTC_Bk_Util::tsv_parse( $line );
			if ( ( $r[0] ?? '' ) === $object ) {
				$rows[ (int) $r[1] ] = array(
					'off'       => (int) $r[2],
					'len'       => (int) $r[3],
					'sha'       => $r[4],
					'cont_path' => $r[7] ?? '',
					'cont_size' => (int) ( $r[8] ?? 0 ),
					'cont_done' => (int) ( $r[9] ?? 0 ),
				);
			}
		}
		if ( $gz ) {
			gzclose( $gz );
		}
		if ( ! $rows ) {
			throw new DTC_Bk_Exception( 'Backup ' . $src . ' has no archive index for ' . $object . '.' );
		}
		$this->members[ $key ] = $rows;
		return $rows;
	}

	/**
	 * Download one member in ranged chunks (resumable).
	 *
	 * @return string|null Local file when complete, null if the deadline hit.
	 */
	private function download_member( $src, $object, array $m, array &$dl ) {
		$id   = $src . '|' . $object . '|' . $m['off'];
		$file = $this->dir . '/member-' . md5( $id ) . '.bin';
		if ( ( $dl['id'] ?? '' ) !== $id ) {
			foreach ( (array) glob( $this->dir . '/member-*.bin' ) as $old ) {
				@unlink( $old ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$dl = array(
				'id'   => $id,
				'have' => 0,
			);
		}
		// A request that died may have left more (or, after cleanup, less)
		// than the saved count: start over from what is really there.
		clearstatcache( true, $file );
		if ( ! DTC_Bk_Util::truncate( $file, $dl['have'] ) ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$dl['have'] = 0;
			touch( $file );
		}
		$key = $this->remote()->backup_key( $src, $object );
		while ( $dl['have'] < $m['len'] ) {
			if ( ! $this->time_left() ) {
				return null;
			}
			$start = $m['off'] + $dl['have'];
			$end   = min( $m['off'] + $m['len'], $start + self::DL_CHUNK ) - 1;
			$part  = $file . '.part';
			$res   = $this->remote()->s3->get_to_file( $key, $part, $start, $end );
			if ( is_wp_error( $res ) ) {
				throw new DTC_Bk_Exception( 'Download failed: ' . $res->get_error_message() );
			}
			$in  = fopen( $part, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$out = fopen( $file, 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			stream_copy_to_stream( $in, $out );
			fclose( $in ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			@unlink( $part ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$dl['have'] += $end - $start + 1;
		}
		if ( hash_file( 'sha256', $file ) !== $m['sha'] ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( empty( $dl['retried'] ) ) {
				// Download it once more from scratch before giving up.
				$dl = array(
					'id'      => $id,
					'have'    => 0,
					'retried' => true,
				);
				return null;
			}
			throw new DTC_Bk_Exception( 'Checksum mismatch in ' . $object . ' of backup ' . $src . ' (the archive in storage is damaged).' );
		}
		return $file;
	}

	/** Inflate a whole member file, passing raw bytes to $sink. */
	private function inflate( $file, callable $sink ) {
		$ctx = inflate_init( ZLIB_ENCODING_GZIP );
		$fh  = fopen( $file, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		while ( ! feof( $fh ) && ! $this->stop_member ) {
			$chunk = fread( $fh, 262144 );
			if ( false === $chunk || '' === $chunk ) {
				break;
			}
			$raw = inflate_add( $ctx, $chunk, ZLIB_SYNC_FLUSH );
			if ( false === $raw ) {
				fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				throw new DTC_Bk_Exception( 'Corrupt compressed data in the backup.' );
			}
			if ( '' !== $raw ) {
				call_user_func( $sink, $raw );
			}
		}
		fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		if ( ! $this->stop_member && ZLIB_STREAM_END !== inflate_get_status( $ctx ) ) {
			throw new DTC_Bk_Exception( 'A backup archive member is truncated.' );
		}
	}

	/* ---------------------------------------------------------------------
	 * Database
	 * ------------------------------------------------------------------ */

	private function checkpoint_file() {
		return $this->dir . '/db-checkpoint.txt';
	}

	private function phase_db() {
		global $wpdb;
		if ( empty( $this->e['dbs'] ) ) {
			$this->e['dbs'] = DTC_Bk_Db_Import::init_state(
				array_merge(
					array(
						'old_prefix' => (string) ( $this->e['site']['prefix'] ?? $wpdb->prefix ),
						'new_prefix' => $wpdb->prefix,
						'charset'    => (string) ( $this->e['site']['charset'] ?? 'utf8mb4' ),
						'map'        => $this->e['map'],
						'needles'    => $this->e['needles'],
					),
					DTC_Bk_Db_Import::server_fixes()
				)
			);
			if ( $this->e['dbs']['old_prefix'] !== $wpdb->prefix ) {
				$this->note( 'Table prefix changes from ' . $this->e['dbs']['old_prefix'] . ' to ' . $wpdb->prefix . '.' );
			}
		}
		$dbs = $this->e['dbs'];

		// The checkpoint file is written after every statement, so it is
		// ahead of the request state when a request was killed mid-member.
		$cp = DTC_Bk_Util::read_json( $this->checkpoint_file() );
		if ( $cp && ( $cp['member'] > $dbs['member'] || ( $cp['member'] === $dbs['member'] && $cp['lines'] > $dbs['lines'] ) ) ) {
			$dbs['member'] = (int) $cp['member'];
			$dbs['lines']  = (int) $cp['lines'];
			$dbs['tables'] = (array) $cp['tables'];
			$dbs['ended']  = ! empty( $cp['ended'] );
		}
		$imp = new DTC_Bk_Db_Import( $dbs );
		$imp->session();
		$rows = $this->members( $this->req['backup_id'], 'db.sql.gz' );

		$dl = $this->e['dl'] ?? array();
		while ( isset( $rows[ $dbs['member'] ] ) && ! $dbs['ended'] ) {
			$this->progress( 'Importing the database (' . ( $dbs['member'] + 1 ) . ' of ' . count( $rows ) . ').' );
			$file          = $this->download_member( $this->req['backup_id'], 'db.sql.gz', $rows[ $dbs['member'] ], $dl );
			$this->e['dl'] = $dl;
			if ( null === $file ) {
				$this->e['dbs'] = $dbs;
				return;
			}
			$skip  = (int) $dbs['lines'];
			$count = 0;
			$buf   = '';
			$cpf   = $this->checkpoint_file();
			$mem   = (int) $dbs['member'];
			$this->inflate(
				$file,
				function ( $raw ) use ( &$buf, &$count, $skip, $imp, $cpf, $mem ) {
					$buf  .= $raw;
					$start = 0;
					while ( false !== ( $nl = strpos( $buf, "\n", $start ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
						$line  = substr( $buf, $start, $nl - $start );
						$start = $nl + 1;
						++$count;
						if ( $count <= $skip ) {
							continue;
						}
						$imp->line( $line );
						$st = $imp->state();
						DTC_Bk_Util::write_json(
							$cpf,
							array(
								'member' => $mem,
								'lines'  => $count,
								'tables' => $st['tables'],
								'ended'  => $st['ended'],
							)
						);
					}
					$buf = (string) substr( $buf, $start );
				}
			);
			if ( '' !== $buf ) {
				throw new DTC_Bk_Exception( 'A database statement is split across archive members.' );
			}
			$dbs            = array_merge( $imp->state(), array( 'member' => $mem + 1, 'lines' => 0 ) );
			$this->e['dbs'] = $dbs;
			$this->e['dl']  = array();
			$dl             = array();
			$imp            = new DTC_Bk_Db_Import( $dbs );
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $this->time_left() ) {
				return;
			}
		}
		if ( ! $dbs['ended'] ) {
			throw new DTC_Bk_Exception( 'The database backup is incomplete (no end marker).' );
		}
		// Not idempotent (a new prefix can start with the old one), so it is
		// recorded in the checkpoint that survives a killed request.
		$cp = DTC_Bk_Util::read_json( $this->checkpoint_file() );
		if ( empty( $cp['prefix_fixed'] ) ) {
			$imp->fix_prefix();
			$cp                 = is_array( $cp ) ? $cp : array();
			$cp['prefix_fixed'] = true;
			DTC_Bk_Util::write_json( $this->checkpoint_file(), $cp );
		}
		$this->e['dbs'] = $imp->state();
		$this->note( 'Database imported into temporary tables (' . count( array_unique( $this->e['dbs']['tables'] ) ) . ' tables).' );
		$this->e['phase'] = $this->e['areas'] ? 'files' : 'swap';
	}

	/* ---------------------------------------------------------------------
	 * Files
	 * ------------------------------------------------------------------ */

	private function maintenance_on() {
		if ( empty( $this->req['maintenance'] ) ) {
			$this->req['maintenance'] = true;
			$this->e['commit']        = true;
			$this->note( 'Maintenance page on.' );
			return true;
		}
		return false;
	}

	private function read_row() {
		$this->row = null;
		$f         = &$this->e['f'];
		fseek( $this->row_fh, (int) $f['off'] );
		$line = fgets( $this->row_fh );
		if ( false === $line ) {
			return null;
		}
		$r         = DTC_Bk_Util::tsv_parse( $line );
		$this->row = array(
			'path'  => $r[0],
			'type'  => $r[1],
			'size'  => (int) $r[2],
			'mtime' => (int) $r[3],
			'mode'  => (int) $r[4],
			'm1'    => (int) $r[5],
			'm2'    => (int) $r[6],
			'link'  => $r[7] ?? '',
			'next'  => ftell( $this->row_fh ),
		);
		return $this->row;
	}

	private function next_row() {
		$this->e['f']['off'] = $this->row['next'];
		return $this->read_row();
	}

	private function phase_files() {
		$roots = DTC_Bk_Areas::roots();
		while ( $this->time_left() ) {
			if ( $this->e['fi'] >= count( $this->e['files'] ) ) {
				$this->note( 'Files restored: ' . $this->e['stats']['files'] . ' (' . DTC_Bk_Util::size( $this->e['stats']['bytes'] ) . ').' );
				$this->e['phase'] = ! empty( $this->req['clean'] ) && array_intersect( self::CLEANABLE, $this->e['areas'] ) ? 'clean' : ( $this->e['db'] ? 'swap' : 'finish' );
				return;
			}
			$pf   = $this->e['files'][ $this->e['fi'] ];
			$area = $pf['area'];
			if ( 'uploads' !== $area && $this->maintenance_on() ) {
				return;
			}
			if ( null === $this->e['f'] ) {
				$this->e['f'] = array(
					'off'    => 0,
					'member' => null,
					'parser' => null,
					'out'    => null,
				);
			}
			$this->row_fh = fopen( $this->dir . '/' . $pf['file'], 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			$done         = $this->restore_plan_file( $pf, $roots[ $area ] );
			fclose( $this->row_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( ! $done ) {
				return;
			}
			$this->e['fi']++;
			$this->e['f'] = null;
		}
	}

	/** @return bool True when every row of the plan file is restored. */
	private function restore_plan_file( array $pf, $root ) {
		$object  = $pf['area'] . '.tar.gz';
		$members = $this->members( $pf['src'], $object );
		$f       = &$this->e['f'];

		while ( $this->time_left() ) {
			if ( ! $this->read_row() && null === $f['out'] ) {
				return true;
			}
			if ( null === $f['member'] ) {
				$f['member'] = $this->row['m1'];
			}
			$n = (int) $f['member'];
			if ( ! isset( $members[ $n ] ) ) {
				throw new DTC_Bk_Exception( 'Archive member ' . $n . ' of ' . $object . ' in backup ' . $pf['src'] . ' is missing.' );
			}
			// Members never start inside a header or padding, so the stored
			// continuation (file, size, bytes done) is the full parser state.
			$m     = $members[ $n ];
			$start = '' !== $m['cont_path'] ? DTC_Bk_Tar_Parser::continuation( $m['cont_path'], $m['cont_size'], $m['cont_done'] ) : array();

			$this->progress( 'Restoring ' . DTC_Bk_Areas::LABELS[ $pf['area'] ] . ' (' . $this->e['stats']['files'] . ' files so far).' );
			$dl            = $this->e['dl'] ?? array();
			$file          = $this->download_member( $pf['src'], $object, $m, $dl );
			$this->e['dl'] = $dl;
			if ( null === $file ) {
				return false;
			}

			// Resume a file that spans members: cut it back to the committed
			// size. If the temp file is gone (a request finished the file but
			// died before saving), extract that file again from its start.
			if ( $f['out'] && empty( $f['out']['link'] ) && ! DTC_Bk_Util::truncate( $f['out']['tmp'], $f['out']['written'] ) ) {
				$f['out']    = null;
				$f['member'] = $this->row['m1'];
				@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$this->e['dl'] = array();
				continue;
			}
			$this->cur_member  = $n;
			$this->stop_member = false;
			$parser            = new DTC_Bk_Tar_Parser(
				$start,
				function ( $hdr ) use ( $root ) {
					$this->on_entry( $hdr, $root );
				},
				function ( $data ) {
					$this->on_data( $data );
				},
				function ( $hdr ) use ( $root ) {
					$this->on_end( $hdr, $root );
				}
			);
			$this->inflate(
				$file,
				function ( $raw ) use ( $parser ) {
					$parser->feed( $raw );
				}
			);
			if ( $this->out_fh ) {
				fclose( $this->out_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$this->out_fh = null;
			}
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$this->e['dl'] = array();

			if ( ! $this->row && ! $f['out'] ) {
				return true;
			}
			if ( ! $f['out'] && $this->row['m1'] <= $n ) {
				throw new DTC_Bk_Exception( 'File ' . $this->row['path'] . ' was not found in its archive member.' );
			}
			// A file that spans members continues in the next one; otherwise
			// jump straight to the member holding the next needed file.
			$f['member'] = $f['out'] ? $n + 1 : $this->row['m1'];
		}
		return false;
	}

	private function on_entry( array $hdr, $root ) {
		$f = &$this->e['f'];
		if ( ! empty( $hdr['cont'] ) ) {
			// Continuation of a file started in an earlier member.
			return;
		}
		while ( $this->row ) {
			$c = DTC_Bk_Util::path_cmp( $this->row['path'], $hdr['path'] );
			if ( $c > 0 ) {
				return; // Not needed.
			}
			if ( $c < 0 ) {
				throw new DTC_Bk_Exception( 'File ' . $this->row['path'] . ' is missing from the backup archive.' );
			}
			break;
		}
		if ( ! $this->row ) {
			$this->stop_member = true;
			return;
		}
		$target = $root . '/' . $this->row['path'];
		if ( '2' === $hdr['type'] ) {
			$this->ensure_dir( dirname( $target ) );
			if ( is_link( $target ) || is_file( $target ) ) {
				@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			@symlink( $hdr['link'], $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$f['out'] = array(
				'link'    => true,
				'path'    => $this->row['path'],
				'tmp'     => '',
				'written' => 0,
			);
			return;
		}
		$this->ensure_dir( dirname( $target ) );
		$tmp      = $target . '.dtctmp';
		$f['out'] = array(
			'path'    => $this->row['path'],
			'tmp'     => $tmp,
			'written' => 0,
		);
		$this->out_fh = fopen( $tmp, 'wb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $this->out_fh ) {
			throw new DTC_Bk_Exception( 'Cannot write ' . $target . ' (permissions or disk space).' );
		}
	}

	private function on_data( $data ) {
		$f = &$this->e['f'];
		if ( ! $f['out'] || ! empty( $f['out']['link'] ) ) {
			return;
		}
		if ( ! $this->out_fh ) {
			$this->out_fh = fopen( $f['out']['tmp'], 'ab' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $this->out_fh ) {
				throw new DTC_Bk_Exception( 'Cannot write ' . $f['out']['tmp'] );
			}
		}
		if ( fwrite( $this->out_fh, $data ) !== strlen( $data ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
			throw new DTC_Bk_Exception( 'Write failed for ' . $f['out']['tmp'] . ' (disk full?).' );
		}
		$f['out']['written'] += strlen( $data );
	}

	private function on_end( array $hdr, $root ) {
		$f = &$this->e['f'];
		if ( ! $f['out'] || $f['out']['path'] !== $hdr['path'] ) {
			return;
		}
		$target = $root . '/' . $f['out']['path'];
		if ( empty( $f['out']['link'] ) ) {
			if ( $this->out_fh ) {
				fclose( $this->out_fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
				$this->out_fh = null;
			}
			$tmp = $f['out']['tmp'];
			if ( ! file_exists( $tmp ) ) {
				touch( $tmp );
			}
			@touch( $tmp, $this->row['mtime'] ? $this->row['mtime'] : time() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@chmod( $tmp, $this->e['same'] ? ( $this->row['mode'] & 0777 ) | 0600 : 0644 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_dir( $target ) && ! is_link( $target ) ) {
				DTC_Bk_Util::rrmdir( $target );
			}
			if ( ! @rename( $tmp, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				throw new DTC_Bk_Exception( 'Cannot replace ' . $target . '.' );
			}
			if ( '.php' === substr( $target, -4 ) && function_exists( 'opcache_invalidate' ) ) {
				@opcache_invalidate( $target, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$this->e['stats']['bytes'] += (int) $f['out']['written'];
		}
		$this->e['stats']['files']++;
		$f['out'] = null;
		$next     = $this->next_row();
		// Nothing more needed from this member: stop inflating it.
		if ( ! $next || $next['m1'] > $this->cur_member ) {
			$this->stop_member = true;
		}
	}

	private function ensure_dir( $dir ) {
		if ( ! is_dir( $dir ) ) {
			if ( file_exists( $dir ) || is_link( $dir ) ) {
				@unlink( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			if ( ! @mkdir( $dir, $this->e['same'] ? 0755 : 0755, true ) && ! is_dir( $dir ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				throw new DTC_Bk_Exception( 'Cannot create folder ' . $dir . '.' );
			}
		}
	}

	/* ---------------------------------------------------------------------
	 * Clean (remove code files that are not in the backup)
	 * ------------------------------------------------------------------ */

	private function phase_clean() {
		if ( $this->maintenance_on() ) {
			return;
		}
		$areas = array_values( array_intersect( self::CLEANABLE, $this->e['areas'] ) );
		$c     = &$this->e['clean'];
		if ( null === $c ) {
			$c = array(
				'ai'      => 0,
				'stack'   => null,
				'idx_off' => 0,
				'deleted' => 0,
			);
		}
		$roots    = DTC_Bk_Areas::roots();
		$patterns = array_merge(
			DTC_Bk_Areas::patterns( $this->e['excludes'] ),
			DTC_Bk_Areas::patterns( DTC_Bk_Util::settings()['backup_excludes'] ?: DTC_Bk_Areas::default_excludes() )
		);
		$skip     = array();
		foreach ( array_merge( array_values( $roots ), DTC_Bk_Areas::protected_dirs() ) as $d ) {
			if ( '' !== $d ) {
				$skip[ $d ] = true;
			}
		}

		while ( $c['ai'] < count( $areas ) ) {
			$area = $areas[ $c['ai'] ];
			$root = $roots[ $area ];
			if ( '' === $root ) {
				++$c['ai'];
				continue;
			}
			if ( null === $c['stack'] ) {
				$c['stack']   = array(
					array(
						'd'   => '',
						'l'   => '',
						'del' => false,
					),
				);
				$c['idx_off'] = 0;
			}
			$idx = @fopen( $this->dir . '/idx-' . $area . '.tsv', 'rb' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $idx ) {
				// Index has no files of this area: never wipe a whole area.
				$c['stack'] = null;
				++$c['ai'];
				continue;
			}
			fseek( $idx, (int) $c['idx_off'] );
			$cur = $this->idx_next( $idx );

			while ( $c['stack'] ) {
				if ( ! $this->time_left() ) {
					$c['idx_off'] = $cur ? $cur['off'] : ftell( $idx );
					fclose( $idx ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
					return;
				}
				$top   = count( $c['stack'] ) - 1;
				$frame = $c['stack'][ $top ];
				$abs   = '' === $frame['d'] ? $root : $root . '/' . $frame['d'];
				$names = @scandir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				$names = false === $names ? array() : array_values( array_diff( $names, array( '.', '..' ) ) );
				sort( $names, SORT_STRING );
				$next = null;
				foreach ( $names as $name ) {
					if ( '' === $frame['l'] || strcmp( $name, $frame['l'] ) > 0 ) {
						$next = $name;
						break;
					}
				}
				if ( null === $next ) {
					array_pop( $c['stack'] );
					if ( $frame['del'] && '' !== $frame['d'] ) {
						@rmdir( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					}
					if ( $c['stack'] ) {
						$slash                              = strrpos( $frame['d'], '/' );
						$c['stack'][ $top - 1 ]['l']        = false === $slash ? $frame['d'] : substr( $frame['d'], $slash + 1 );
						$c['stack'][ $top - 1 ]['del']      = $c['stack'][ $top - 1 ]['del'] || $frame['del'];
					}
					continue;
				}
				$c['stack'][ $top ]['l'] = $next;
				$rel                     = '' === $frame['d'] ? $next : $frame['d'] . '/' . $next;
				$path                    = $abs . '/' . $next;
				$logical                 = DTC_Bk_Areas::logical( $area, $rel );
				if ( DTC_Bk_Areas::is_excluded( $patterns, $logical, $next ) || isset( $skip[ $path ] ) || $this->protected_path( $area, $rel ) || ! $this->in_paths( $area, $rel ) && ! $this->path_is_parent( $area, $rel ) ) {
					continue;
				}
				if ( is_link( $path ) ) {
					continue;
				}
				if ( is_dir( $path ) ) {
					if ( 'core' === $area && '' === $frame['d'] && ! in_array( $next, array( 'wp-admin', 'wp-includes' ), true ) ) {
						continue;
					}
					$c['stack'][] = array(
						'd'   => $rel,
						'l'   => '',
						'del' => false,
					);
					continue;
				}
				// Advance the index to this path.
				while ( $cur && DTC_Bk_Util::path_cmp( $cur['path'], $rel ) < 0 ) {
					$cur = $this->idx_next( $idx );
				}
				if ( $cur && $cur['path'] === $rel ) {
					continue;
				}
				if ( $this->in_paths( $area, $rel ) && is_writable( $path ) && @unlink( $path ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					++$c['deleted'];
					$c['stack'][ $top ]['del'] = true;
				}
			}
			fclose( $idx ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			$c['stack'] = null;
			++$c['ai'];
		}
		$this->note( 'Removed ' . $c['deleted'] . ' file(s) that were not in the backup.' );
		$this->e['phase'] = $this->e['db'] ? 'swap' : 'finish';
	}

	/** A folder above one of the selected paths (must be walked). */
	private function path_is_parent( $area, $rel ) {
		$logical = DTC_Bk_Areas::logical( $area, $rel );
		foreach ( (array) ( $this->req['paths'] ?? array() ) as $p ) {
			if ( 0 === strpos( trim( $p, '/' ) . '/', $logical . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	private function idx_next( $fh ) {
		$off  = ftell( $fh );
		$line = fgets( $fh );
		if ( false === $line ) {
			return null;
		}
		$r = DTC_Bk_Util::tsv_parse( $line );
		return array(
			'path' => $r[0] ?? '',
			'off'  => $off,
		);
	}

	/* ---------------------------------------------------------------------
	 * Swap and finish
	 * ------------------------------------------------------------------ */

	private function phase_swap() {
		if ( $this->maintenance_on() ) {
			return;
		}
		$imp = new DTC_Bk_Db_Import( $this->e['dbs'] );
		if ( DTC_Bk_Db_Import::swapped( $this->e['dbs']['tables'] ) ) {
			// A request swapped the tables but died before saving.
			$this->e['phase'] = 'finish';
			return;
		}

		// Keep this site's address and TotalCare connection settings.
		foreach ( array( 'siteurl', 'home', 'dtc_s3_secret' ) as $name ) {
			$live = $imp->live_option( $name );
			if ( null !== $live ) {
				$imp->set_temp_option( $name, $live, 'dtc_s3_secret' === $name ? 'no' : 'yes' );
			}
		}
		$live_settings = $imp->live_option( 'dtc_settings' );
		if ( null !== $live_settings ) {
			$settings = $live_settings;
			if ( ! $this->e['same'] ) {
				// Another site's backup: keep its TotalCare settings, but this
				// site's storage connection.
				$theirs = maybe_unserialize( (string) $imp->temp_option( 'dtc_settings' ) );
				$ours   = maybe_unserialize( $live_settings );
				if ( is_array( $theirs ) && is_array( $ours ) ) {
					foreach ( $ours as $k => $v ) {
						if ( 0 === strpos( $k, 's3_' ) || 'backup_engine' === $k ) {
							$theirs[ $k ] = $v;
						}
					}
					$settings = serialize( $theirs ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
				}
			}
			$imp->set_temp_option( 'dtc_settings', $settings );
		}
		$basename = (string) ( $this->req['plugin_basename'] ?? '' );
		if ( '' !== $basename ) {
			$active = @unserialize( (string) $imp->temp_option( 'active_plugins' ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
			$active = is_array( $active ) ? $active : array();
			if ( ! in_array( $basename, $active, true ) ) {
				$active[] = $basename;
				$imp->set_temp_option( 'active_plugins', serialize( array_values( $active ) ), 'yes' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize
			}
		}

		$this->progress( 'Switching to the restored database.' );
		$imp->swap();
		$this->e['dbs']   = $imp->state();
		$this->e['phase'] = 'finish';
		$this->note( 'Restored database is live.' );
	}

	private function phase_finish() {
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		DTC_Bk_Util::rrmdir( $this->dir );
		$this->req['maintenance'] = false;
		$this->req['status']      = 'done';
		$this->req['stats']       = $this->e['stats'];
		$this->note( 'Restore finished.' );
		$this->progress( 'Restore finished.' );
	}

	/**
	 * Clean up after a failure: undo the database swap if it happened,
	 * drop temp tables, lift the maintenance page.
	 */
	public static function fail( array $req, $error ) {
		$extra = '';
		try {
			if ( ! empty( $req['es']['db'] ) ) {
				DTC_Bk_Db_Import::rollback();
			}
		} catch ( Throwable $e ) {
			$extra = ' Database rollback also failed: ' . $e->getMessage();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		DTC_Bk_Util::rrmdir( DTC_Bk_Util::run_dir( 'restore-' . preg_replace( '/[^a-z0-9-]/', '', (string) ( $req['backup_id'] ?? '' ) ) ) );
		$req['maintenance'] = false;
		$req['status']      = 'failed';
		$req['error']       = $error . $extra;
		$req['log']         = array_slice( array_merge( (array) ( $req['log'] ?? array() ), array( gmdate( 'H:i:s' ) . ' FAILED: ' . $req['error'] ) ), -60 );
		return $req;
	}
}
