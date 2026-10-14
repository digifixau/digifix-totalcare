<?php
/**
 * Runs one backup as a resumable sub-machine inside any job (the backup job,
 * or the update job before it updates anything). State lives in the job
 * under "bk".
 *
 * preflight → db → files → finalize → retention → done
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Backup_Runner {

	const KEY = 'bk';

	public static function start( DTC_Job $job, $trigger, $force_full = false ) {
		$job->set(
			self::KEY,
			array(
				'phase'      => 'preflight',
				'trigger'    => $trigger,
				'force_full' => (bool) $force_full,
				'started'    => time(),
			)
		);
	}

	private static function settings() {
		$s = DTC_Settings::all();
		return array(
			'patterns'   => DTC_Bk_Areas::patterns( '' !== trim( (string) $s['backup_excludes'] ) ? $s['backup_excludes'] : DTC_Bk_Areas::default_excludes() ),
			'excludes'   => '' !== trim( (string) $s['backup_excludes'] ) ? (string) $s['backup_excludes'] : DTC_Bk_Areas::default_excludes(),
			'tables'     => array_values( array_filter( array_map( 'trim', preg_split( '/[\s,]+/', (string) $s['backup_db_tables'] ) ) ) ),
			'scope'      => $s['backup_db_scope'],
			'full_days'  => max( 1, (int) $s['backup_full_days'] ),
			'retention'  => array_intersect_key( $s, DTC_Bk_Retention::defaults() ),
		);
	}

	private static function last_meta_file() {
		return DTC_Bk_Util::work_dir() . '/index-last.json';
	}

	private static function last_index_file() {
		return DTC_Bk_Util::work_dir() . '/index-last.tsv';
	}

	/**
	 * Advance the backup until the job engine's deadline.
	 *
	 * @return string|WP_Error 'continue' or 'done'.
	 */
	public static function step( DTC_Job $job, $deadline = null ) {
		$deadline = $deadline ? $deadline : DTC_Jobs::deadline();
		$bk       = (array) $job->get( self::KEY );
		try {
			while ( microtime( true ) < $deadline ) {
				$phase = $bk['phase'] ?? 'preflight';
				if ( 'done' === $phase ) {
					return 'done';
				}
				$bk = call_user_func( array( __CLASS__, 'phase_' . $phase ), $job, $bk, $deadline );
				if ( is_wp_error( $bk ) ) {
					return $bk;
				}
			}
		} catch ( DTC_Bk_Exception $e ) {
			return new WP_Error( 'dtc_backup', $e->getMessage() );
		}
		return 'done' === ( $bk['phase'] ?? '' ) ? 'done' : 'continue';
	}

	/** Persist, then run post-commit cleanup (uploaded spool segments). */
	private static function commit( DTC_Job $job, array $bk, $after = null ) {
		$job->set( self::KEY, $bk );
		if ( ! $job->save() ) {
			throw new DTC_Bk_Exception( 'The job was cancelled.' );
		}
		if ( $after ) {
			call_user_func( $after );
		}
		return $bk;
	}

	private static function remote( array $bk ) {
		$remote = DTC_Bk_Remote::from_settings( $bk['folder'] ?? null );
		if ( is_wp_error( $remote ) ) {
			throw new DTC_Bk_Exception( $remote->get_error_message() );
		}
		return $remote;
	}

	/* ---------------------------------------------------------------------
	 * Phases
	 * ------------------------------------------------------------------ */

	private static function phase_preflight( DTC_Job $job, array $bk ) {
		$remote = self::remote( array() );
		$owner  = $remote->check_owner();
		if ( is_wp_error( $owner ) ) {
			return $owner;
		}
		DTC_Bk_Db_Dump::connection();

		$s    = self::settings();
		$hash = md5( wp_json_encode( array( $s['patterns'], $s['tables'], $s['scope'], DTC_Bk_Util::FORMAT, DTC_Bk_Areas::roots() ) ) );
		$meta = DTC_Bk_Util::read_json( self::last_meta_file() );

		$full   = ! empty( $bk['force_full'] ) || $s['full_days'] <= 1;
		$reason = $full ? 'full backup requested' : '';
		if ( ! $full ) {
			if ( ! $meta || ! file_exists( self::last_index_file() ) ) {
				$full   = true;
				$reason = 'no earlier backup on this site';
			} elseif ( $meta['hash'] !== $hash || $meta['folder'] !== $remote->folder ) {
				$full   = true;
				$reason = 'backup settings changed';
			} elseif ( time() - (int) $meta['base_time'] >= $s['full_days'] * DAY_IN_SECONDS - HOUR_IN_SECONDS ) {
				$full   = true;
				$reason = 'weekly full backup';
			} else {
				$prev = $remote->manifest( $meta['id'] );
				if ( ! is_array( $prev ) ) {
					$full   = true;
					$reason = 'previous backup is missing from storage';
				} else {
					foreach ( (array) $prev['depends_on'] as $dep ) {
						if ( $dep !== $meta['id'] && ! is_array( $remote->s3->head( $remote->backup_key( $dep, 'manifest.json' ) ) ) ) {
							$full   = true;
							$reason = 'an earlier backup in the chain is missing from storage';
							break;
						}
					}
				}
			}
		}

		// Jobs never overlap, so any other backup work folder is left over
		// from a run that died or was abandoned.
		foreach ( (array) glob( DTC_Bk_Util::work_dir() . '/*', GLOB_ONLYDIR ) as $old ) {
			if ( DTC_Bk_Remote::parse_id( basename( $old ) ) ) {
				DTC_Bk_Util::rrmdir( $old );
			}
		}

		$id  = DTC_Bk_Remote::new_id( $full, $bk['trigger'] );
		$dir = DTC_Bk_Util::run_dir( $id );
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( $dir ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $free && $free < 64 * MB_IN_BYTES ) {
			DTC_Bk_Util::rrmdir( $dir );
			return new WP_Error( 'dtc_backup', 'Less than 64 MB of free disk space for the backup work files.' );
		}

		return self::commit(
			$job,
			array_merge(
				$bk,
				array(
					'phase'     => 'db',
					'id'        => $id,
					'full'      => $full,
					'reason'    => $full ? $reason : '',
					'base'      => $full ? $id : $meta['base'],
					'base_time' => $full ? time() : (int) $meta['base_time'],
					'parent'    => $meta['id'] ?? '',
					'hash'      => $hash,
					'folder'    => $remote->folder,
					'dir'       => $dir,
					'excludes'  => $s['excludes'],
					'tables'    => $s['tables'],
					'scope'     => $s['scope'],
					'db'        => null,
					'dbw'       => null,
					'files'     => null,
					'objects'   => array(),
					'warnings'  => array(),
					'started'   => time(),
				)
			)
		);
	}

	private static function phase_db( DTC_Job $job, array $bk, $deadline ) {
		$remote = self::remote( $bk );
		$writer = new DTC_Bk_Gz_Writer( $remote->s3, $bk['dir'], 'db', $remote->backup_key( $bk['id'], 'db.sql.gz' ), $bk['dbw'] );
		$dump   = new DTC_Bk_Db_Dump( $writer, $bk['db'] ? $bk['db'] : DTC_Bk_Db_Dump::init_state( $bk['scope'], $bk['tables'] ) );
		$done   = $dump->run( $deadline );
		$writer->end_member();
		$bk['db'] = $dump->state();
		if ( $done ) {
			$bk['objects']['db.sql.gz'] = $writer->finish();
			$bk['warnings']             = array_merge( $bk['warnings'], (array) $bk['db']['warnings'] );
			$bk['phase']                = 'files';
		}
		$bk['dbw'] = $writer->state();
		$job->message = $done ? 'Database saved; backing up files.' : 'Backing up the database (' . number_format_i18n( $bk['db']['rows'] ) . ' rows so far).';
		return self::commit( $job, $bk, array( $writer, 'after_commit' ) );
	}

	private static function phase_files( DTC_Job $job, array $bk, $deadline ) {
		$remote = self::remote( $bk );
		$prev   = $bk['full'] ? '' : self::last_index_file();
		$fb     = new DTC_Bk_File_Backup( $remote, $bk['id'], $bk['dir'], $bk['files'] ? $bk['files'] : DTC_Bk_File_Backup::init_state(), $prev, DTC_Bk_Areas::patterns( $bk['excludes'] ) );
		$done   = $fb->run( $deadline );
		$bk['files'] = $fb->state();
		if ( $done ) {
			$bk['objects']  = array_merge( $bk['objects'], $bk['files']['objects'] );
			$bk['warnings'] = array_merge( $bk['warnings'], $bk['files']['warnings'] );
			$bk['phase']    = 'finalize';
		}
		$st           = $bk['files']['stats'];
		$job->message = sprintf( '%s backup: %s files checked, %s changed (%s).', $bk['full'] ? 'Full' : 'Incremental', number_format_i18n( $st['files'] ), number_format_i18n( $st['changed'] ), size_format( $st['bytes'], 1 ) );
		return self::commit( $job, $bk, array( $fb, 'after_commit' ) );
	}

	private static function phase_finalize( DTC_Job $job, array $bk, $deadline ) {
		global $wpdb, $wp_version;
		$remote = self::remote( $bk );
		$dir    = $bk['dir'];

		// 1. The file index (can be large: streamed like the archives).
		if ( empty( $bk['objects']['index.tsv.gz'] ) ) {
			$writer = new DTC_Bk_Gz_Writer( $remote->s3, $dir, 'index', $remote->backup_key( $bk['id'], 'index.tsv.gz' ), $bk['idxw'] ?? null );
			$src    = fopen( $dir . '/index.tsv', 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			fseek( $src, (int) ( $bk['idx_off'] ?? 0 ) );
			$writer->begin_member( 6 );
			while ( ! feof( $src ) && microtime( true ) < $deadline ) {
				$writer->write( (string) fread( $src, 1048576 ) );
				if ( $writer->member_full() ) {
					$writer->end_member();
					$writer->begin_member( 6 );
				}
			}
			$writer->end_member();
			$bk['idx_off'] = ftell( $src );
			$eof           = feof( $src );
			fclose( $src ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			if ( $eof ) {
				$bk['objects']['index.tsv.gz'] = $writer->finish();
			}
			$bk['idxw'] = $writer->state();
			return self::commit( $job, $bk, array( $writer, 'after_commit' ) );
		}

		// 2. Member offsets of every archive, so restores can fetch byte ranges.
		$rows = '';
		foreach ( $bk['objects'] as $name => $info ) {
			if ( 'index.tsv.gz' === $name || 'members.tsv.gz' === $name ) {
				continue;
			}
			$local = $dir . '/' . ( 'db.sql.gz' === $name ? 'db' : $info['area'] ) . '.members.tsv';
			$fh    = fopen( $local, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
			if ( ! $fh ) {
				throw new DTC_Bk_Exception( 'Member index ' . $local . ' is missing.' );
			}
			while ( false !== ( $line = fgets( $fh ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				// Rows are already TSV-escaped; prefix the object name.
				$rows .= $name . "\t" . $line;
			}
			fclose( $fh ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		}
		$gz  = gzencode( $rows, 6 );
		$res = $remote->s3->put( $remote->backup_key( $bk['id'], 'members.tsv.gz' ), $gz, 'application/gzip' );
		if ( is_wp_error( $res ) ) {
			throw new DTC_Bk_Exception( $res->get_error_message() );
		}
		$bk['objects']['members.tsv.gz'] = array(
			'size'    => strlen( $gz ),
			'members' => 1,
			'raw'     => strlen( $rows ),
		);

		// 3. Manifest (its presence marks the backup complete).
		$st   = $bk['files']['stats'];
		$deps = array_keys( (array) $bk['files']['deps'] );
		$deps[] = $bk['id'];
		$deps   = array_values( array_unique( $deps ) );
		sort( $deps );
		$size = 0;
		foreach ( $bk['objects'] as $o ) {
			$size += (int) $o['size'];
		}
		$dbh      = DTC_Bk_Db_Dump::connection();
		$manifest = array(
			'format'     => DTC_Bk_Util::FORMAT,
			'id'         => $bk['id'],
			'type'       => $bk['full'] ? 'full' : 'incremental',
			'trigger'    => $bk['trigger'],
			'created'    => (int) $bk['started'],
			'finished'   => time(),
			'base'       => $bk['base'],
			'parent'     => $bk['parent'],
			'depends_on' => $deps,
			'site'       => array(
				'home'        => get_option( 'home' ),
				'siteurl'     => get_option( 'siteurl' ),
				'site_key'    => DTC_Bk_Util::site_key(),
				'uuid'        => DTC_Bk_Remote::site_uuid(),
				'name'        => get_bloginfo( 'name' ),
				'abspath'     => ABSPATH,
				'content_dir' => WP_CONTENT_DIR,
				'roots'       => DTC_Bk_Areas::roots(),
				'prefix'      => $wpdb->prefix,
				'charset'     => $bk['db']['charset'],
				'collate'     => $wpdb->collate,
				'wp'          => $wp_version,
				'php'         => PHP_VERSION,
				'mysql'       => $dbh->server_info,
				'plugin'      => DTC_VERSION,
			),
			'excludes'   => $bk['excludes'],
			'db_tables_excluded' => $bk['tables'],
			'objects'    => $bk['objects'],
			'stats'      => array(
				'files'     => (int) $st['files'],
				'changed'   => (int) $st['changed'],
				'deleted'   => (int) $st['deleted'],
				'bytes'     => (int) $st['bytes'],
				'skipped'   => (int) $st['skipped'],
				'size'      => $size,
				'db_rows'   => (int) $bk['db']['rows'],
				'db_tables' => count( $bk['db']['tables'] ),
				'max_stmt'  => (int) $bk['db']['max_stmt'],
				'duration'  => time() - (int) $bk['started'],
			),
			'warnings'   => array_slice( $bk['warnings'], 0, 50 ),
		);
		$res = $remote->put_manifest( $manifest );
		if ( is_wp_error( $res ) ) {
			throw new DTC_Bk_Exception( $res->get_error_message() );
		}
		$remote->catalog_put( DTC_Bk_Remote::catalog_entry( $manifest ) );

		$bk['summary'] = self::summary( $bk, $manifest );
		$bk['phase']   = 'local';
		// Drop the bulky per-phase state now that it is no longer needed.
		unset( $bk['db']['tables'], $bk['files']['stack'], $bk['dbw'], $bk['idxw'] );
		$bk['files']['writers'] = array();
		return self::commit( $job, $bk );
	}

	/** Keep this backup's index locally for the next incremental. Idempotent. */
	private static function phase_local( DTC_Job $job, array $bk ) {
		if ( file_exists( $bk['dir'] . '/index.tsv' ) ) {
			rename( $bk['dir'] . '/index.tsv', self::last_index_file() );
		}
		DTC_Bk_Util::write_json(
			self::last_meta_file(),
			array(
				'id'        => $bk['id'],
				'base'      => $bk['base'],
				'base_time' => $bk['base_time'],
				'hash'      => $bk['hash'],
				'folder'    => $bk['folder'],
				'created'   => time(),
			)
		);
		DTC_Bk_Util::rrmdir( $bk['dir'] );
		$bk['phase'] = 'retention';
		return self::commit( $job, $bk );
	}

	private static function phase_retention( DTC_Job $job, array $bk ) {
		$remote = self::remote( $bk );
		$res    = DTC_Bk_Retention::run( $remote, self::settings()['retention'] );
		if ( is_wp_error( $res ) ) {
			$bk['summary']['retention'] = 'Old backups were not pruned: ' . $res->get_error_message();
		} else {
			$bk['summary']['retention'] = array(
				'deleted' => count( $res['deleted'] ),
				'orphans' => count( $res['orphans'] ),
				'kept'    => $res['kept'],
			);
		}
		$bk['phase'] = 'done';
		return self::commit( $job, $bk );
	}

	/* ---------------------------------------------------------------------
	 * Summary and cleanup
	 * ------------------------------------------------------------------ */

	/** Same shape the WPvivid integration produced, plus engine details. */
	public static function summary( array $bk, array $manifest ) {
		$s = DTC_Settings::all();
		return array(
			'backup_id'     => $bk['id'],
			'created'       => (int) $bk['started'],
			'size'          => (int) $manifest['stats']['size'],
			'size_human'    => size_format( (int) $manifest['stats']['size'], 1 ),
			'files'         => array_keys( $bk['objects'] ),
			'remote'        => array( $s['s3_bucket'] . '/' . $bk['folder'] . ' (' . $s['s3_type'] . ')' ),
			'local'         => false,
			'duration'      => (int) $manifest['stats']['duration'],
			'type'          => $manifest['type'],
			'reason'        => $bk['reason'] ?? '',
			'changed_files' => (int) $manifest['stats']['changed'],
			'total_files'   => (int) $manifest['stats']['files'],
			'db_size'       => (int) ( $bk['objects']['db.sql.gz']['size'] ?? 0 ),
			'warnings'      => count( $manifest['warnings'] ),
		);
	}

	/** Abort uploads and remove partial objects after a failure or cancel. */
	public static function cleanup( DTC_Job $job ) {
		$bk = (array) $job->get( self::KEY );
		if ( empty( $bk['id'] ) || in_array( $bk['phase'] ?? '', array( 'local', 'retention', 'done' ), true ) ) {
			return;
		}
		$remote = DTC_Bk_Remote::from_settings( $bk['folder'] ?? null );
		if ( ! is_wp_error( $remote ) ) {
			foreach ( array( $bk['dbw'] ?? null, $bk['idxw'] ?? null ) as $ws ) {
				if ( $ws ) {
					DTC_Bk_Gz_Writer::abort_state( $remote->s3, $ws );
				}
			}
			if ( ! empty( $bk['files'] ) ) {
				DTC_Bk_File_Backup::abort( $remote->s3, $bk['files'] );
			}
			$list = $remote->s3->list_all( $remote->key( 'backups/' . $bk['id'] . '/' ) );
			if ( is_array( $list ) && $list['objects'] ) {
				$remote->s3->delete( wp_list_pluck( $list['objects'], 'key' ) );
			}
		}
		if ( ! empty( $bk['dir'] ) ) {
			DTC_Bk_Util::rrmdir( $bk['dir'] );
		}
	}
}
