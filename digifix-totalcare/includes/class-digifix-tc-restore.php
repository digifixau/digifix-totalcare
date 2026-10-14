<?php
/**
 * Full or partial site restore coordinator.
 *
 * The restore itself is driven by the guardian mu-plugin, so it keeps going
 * even if a restored plugin is broken. This class prepares the request
 * (dtc-data/restore-request.json), hands it to the guardian, and reconciles
 * the result once TotalCare is running again.
 *
 * With TotalCare's engine the guardian loads a pinned copy of the engine
 * from dtc-data/engine/<version>/, so a broken TotalCare update cannot break
 * a restore. With the legacy WPvivid engine it calls WPvivid's restore.
 *
 * Also acts as the handler for manual "restore" jobs started from the admin.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Restore {

	const REQUEST = 'restore-request';
	const LAST    = 'restore-last';

	const ALL_SCOPE = array( 'db', 'core', 'plugins', 'themes', 'muplugins', 'content', 'uploads' );

	public static function request() {
		return DTC_Storage::read( self::REQUEST );
	}

	public static function in_progress() {
		$req = self::request();
		return $req && in_array( $req['status'] ?? '', array( 'pending', 'running', 'finishing', 'failing' ), true );
	}

	/** Copy the engine next to the guardian's data so it survives plugin changes. */
	private static function pin_engine() {
		$dir = DTC_Storage::data_dir() . '/engine/' . preg_replace( '/[^0-9A-Za-z.-]/', '', DTC_VERSION );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			return new WP_Error( 'dtc_restore', 'Could not create ' . $dir . '.' );
		}
		foreach ( glob( DTC_DIR . 'includes/backup/*.php' ) as $file ) {
			$target = $dir . '/' . basename( $file );
			if ( ! file_exists( $target ) || md5_file( $target ) !== md5_file( $file ) ) {
				if ( ! @copy( $file, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return new WP_Error( 'dtc_restore', 'Could not copy the restore engine to ' . $dir . '.' );
				}
			}
		}
		// Older pinned versions are no longer needed.
		foreach ( (array) glob( DTC_Storage::data_dir() . '/engine/*', GLOB_ONLYDIR ) as $old ) {
			if ( $old !== $dir ) {
				DTC_Bk_Util::rrmdir( $old );
			}
		}
		return $dir;
	}

	/**
	 * Hand a restore to the guardian.
	 *
	 * @param array $opts engine (totalcare|wpvivid), scope (parts), paths
	 *                    (logical path prefixes), clean, htaccess, folder
	 *                    (another site's backup folder).
	 * @return true|WP_Error
	 */
	public static function begin( $backup_id, $job_id, $reason, array $job_state = array(), array $opts = array() ) {
		if ( self::in_progress() ) {
			return new WP_Error( 'dtc_restore', 'A restore is already in progress.' );
		}
		if ( ! DTC_Installer::guardian_installed() ) {
			return new WP_Error( 'dtc_restore', 'The guardian mu-plugin is not installed, so a restore cannot be run safely.' );
		}
		$engine = 'wpvivid' === ( $opts['engine'] ?? '' ) ? 'wpvivid' : 'totalcare';

		$extra = array();
		if ( 'wpvivid' === $engine ) {
			if ( ! DTC_WPvivid::is_available() ) {
				return new WP_Error( 'dtc_restore', 'WPvivid Backup is not active.' );
			}
			$local = DTC_WPvivid::ensure_local_files( $backup_id );
			if ( is_wp_error( $local ) ) {
				return $local;
			}
		} else {
			$dir = self::pin_engine();
			if ( is_wp_error( $dir ) ) {
				return $dir;
			}
			$scope = array_values( array_intersect( self::ALL_SCOPE, (array) ( $opts['scope'] ?? self::ALL_SCOPE ) ) );
			if ( ! $scope ) {
				return new WP_Error( 'dtc_restore', 'Choose at least one part of the site to restore.' );
			}
			$extra = array(
				'engine_dir'  => $dir,
				'folder'      => $opts['folder'] ?? null,
				'scope'       => $scope,
				'paths'       => array_values( array_filter( array_map( 'trim', (array) ( $opts['paths'] ?? array() ) ) ) ),
				'clean'       => ! empty( $opts['clean'] ),
				'htaccess'    => ! empty( $opts['htaccess'] ),
				'token'       => bin2hex( random_bytes( 16 ) ),
				'maintenance' => false,
			);
		}

		DTC_Storage::write(
			self::REQUEST,
			array_merge(
				array(
					'engine'          => $engine,
					'backup_id'       => $backup_id,
					'job_id'          => (int) $job_id,
					'reason'          => $reason,
					'status'          => 'pending',
					'created'         => time(),
					'updated'         => time(),
					'error'           => '',
					'log'             => array(),
					'job_state'       => $job_state,
					'plugin_basename' => DTC_BASENAME,
				),
				$extra
			)
		);

		DTC_Logger::log( 'restore', 'warning', 'restore.started', 'Restore of backup ' . $backup_id . ' started: ' . $reason, array( 'backup_id' => $backup_id, 'scope' => $extra['scope'] ?? array( 'all' ) ), $job_id );
		DTC_Storage::loopback( 'dtc_guardian_restore' );
		return true;
	}

	/** Link to the guardian's progress page (works during maintenance). */
	public static function status_url() {
		$req = self::request();
		return ! empty( $req['token'] ) ? add_query_arg( 'dtc_restore_status', $req['token'], home_url( '/' ) ) : '';
	}

	/**
	 * Called on every tick and admin load. Picks up a restore the guardian has
	 * finished and resumes the owning job.
	 */
	public static function reconcile() {
		$req = self::request();
		if ( ! $req ) {
			return;
		}

		// A restore with no progress for 3 hours will not finish on its own;
		// release the job engine and alert.
		if ( self::in_progress() && time() - (int) ( $req['updated'] ?? 0 ) > 3 * HOUR_IN_SECONDS ) {
			$req['error']  = 'Restore stalled (no progress for 3 hours, last status "' . $req['status'] . '"). The site may need manual attention.';
			$req['status'] = 'failed';
			DTC_Storage::write( self::REQUEST, $req );
		}
		if ( ! in_array( $req['status'] ?? '', array( 'done', 'failed' ), true ) ) {
			return;
		}

		// Claim the request atomically so concurrent callers run this once.
		if ( ! @rename( DTC_Storage::path( self::REQUEST ), DTC_Storage::path( self::LAST ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}

		// Requests that were already running may have refilled the object
		// cache with old data after the guardian's flush.
		wp_cache_flush();
		self::purge_page_caches();

		$ok     = 'done' === $req['status'];
		$job_id = (int) $req['job_id'];
		$detail = array(
			'backup_id' => $req['backup_id'],
			'reason'    => $req['reason'],
			'duration'  => (int) $req['updated'] - (int) $req['created'],
			'error'     => $req['error'],
			'scope'     => $req['scope'] ?? array(),
			'stats'     => $req['stats'] ?? array(),
		);

		if ( $ok ) {
			DTC_Logger::log( 'restore', 'success', 'restore.completed', 'Backup ' . $req['backup_id'] . ' restored successfully.', $detail, $job_id );
		} else {
			DTC_Logger::log( 'restore', 'error', 'restore.failed', 'Restore of backup ' . $req['backup_id'] . ' failed: ' . $req['error'], $detail, $job_id );
		}

		$job = $job_id ? DTC_Jobs::find( $job_id ) : null;
		if ( ! $job || 'cancelled' === $job->status ) {
			DTC_Notifier::notify( $ok ? 'restore.completed' : 'restore.failed', $detail );
			return;
		}

		// Job tables are excluded from backups, but merge the saved state in
		// case the row was rewound (e.g. older backups that included it).
		$job->state  = array_merge( (array) $req['job_state'], $job->state );
		$job->status = 'running';
		$job->set( 'restore_result', $detail + array( 'ok' => $ok ) );
		$job->go( 'post_restore', 30, $ok ? 'Restore finished, verifying site.' : 'Restore failed.' );
	}

	public static function purge_page_caches() {
		foreach ( array( 'litespeed_purge_all', 'rocket_clean_domain', 'w3tc_flush_all', 'wp_cache_clear_cache', 'sg_cachepress_purge_cache' ) as $hook ) {
			if ( 'litespeed_purge_all' === $hook ) {
				do_action( 'litespeed_purge_all' );
			} elseif ( function_exists( $hook ) ) {
				call_user_func( $hook );
			}
		}
	}

	/**
	 * After the restored site passed its health check, the replaced tables
	 * are no longer needed (they are otherwise dropped after a day).
	 */
	public static function drop_rollback_tables() {
		try {
			DTC_Bk_Db_Import::drop_old( 0 );
		} catch ( Throwable $e ) {
			DTC_Logger::log( 'restore', 'warning', 'restore.cleanup_failed', 'Could not drop old pre-restore tables: ' . $e->getMessage() );
		}
	}

	/* ---------------------------------------------------------------------
	 * Manual restore job handler
	 * ------------------------------------------------------------------ */

	public function max_age() {
		return 8 * HOUR_IN_SECONDS;
	}

	/**
	 * @param array $opts See begin().
	 */
	public static function start_manual( $backup_id, array $opts = array() ) {
		return DTC_Jobs::create(
			'restore',
			array(
				'backup_id' => $backup_id,
				'opts'      => $opts,
			)
		);
	}

	public function run( DTC_Job $job ) {
		switch ( $job->step ) {
			case 'start':
				$opts = (array) $job->get( 'opts', array() );
				$res  = self::begin( $job->get( 'backup_id' ), $job->id, 'Manual restore requested from the dashboard.', $job->state, $opts );
				if ( is_wp_error( $res ) ) {
					$job->fail( $res->get_error_message() );
					DTC_Logger::log( 'restore', 'error', 'restore.failed', $res->get_error_message(), array(), $job->id );
					DTC_Notifier::notify( 'restore.failed', array( 'error' => $res->get_error_message() ), $job->id );
					return;
				}
				$job->go( 'restoring', 60, 'Guardian is restoring the backup.' );
				return;

			case 'restoring':
				// reconcile() moves the job on. Ticks are paused while the
				// restore runs, so reaching here means the request vanished.
				if ( ! self::request() ) {
					$job->fail( 'The restore request disappeared before the guardian reported a result.' );
					DTC_Notifier::notify( 'restore.failed', array( 'error' => $job->message ), $job->id );
					return;
				}
				$job->wait( 60 );
				return;

			case 'post_restore':
				$result = (array) $job->get( 'restore_result' );
				if ( empty( $result['ok'] ) ) {
					$job->fail( 'Restore failed: ' . ( $result['error'] ?? '' ) );
					DTC_Notifier::notify( 'restore.failed', $result, $job->id );
					return;
				}
				$check = DTC_Health_Check::check( DTC_Health_Check::baseline(), time() );
				if ( $check['passed'] ) {
					self::drop_rollback_tables();
				}
				$job->complete( $check['passed'] ? 'Restore completed and site is healthy.' : 'Restore completed but the health check reported problems. The previous database is kept for 24 hours.' );
				DTC_Notifier::notify( 'restore.completed', $result + array( 'health' => $check ), $job->id );
				return;
		}
		$job->fail( 'Unknown step ' . $job->step );
	}
}
