<?php
/**
 * Scheduled backup job.
 *
 * Uses TotalCare's own engine (Digifix_TC_Backup_Runner). The WPvivid engine stays
 * available through the "backup_engine" setting during the switch-over.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Digifix_TC_Backup_Service {

	public static function engine() {
		return 'wpvivid' === Digifix_TC_Settings::get( 'backup_engine' ) && Digifix_TC_WPvivid::is_available() ? 'wpvivid' : 'totalcare';
	}

	/** Dashboard status, without network calls. */
	public static function status() {
		if ( 'wpvivid' === self::engine() ) {
			return Digifix_TC_WPvivid::status();
		}
		$s       = Digifix_TC_Settings::all();
		$missing = array();
		foreach ( array( 'zlib', 'mysqli', 'hash', 'json' ) as $ext ) {
			if ( ! extension_loaded( $ext ) ) {
				$missing[] = $ext;
			}
		}
		if ( ! function_exists( 'openssl_encrypt' ) && ! function_exists( 'sodium_crypto_secretbox' ) ) {
			$missing[] = 'openssl or sodium';
		}
		if ( $missing ) {
			return array(
				'ok'      => false,
				'message' => 'PHP is missing: ' . implode( ', ', $missing ) . '.',
			);
		}
		if ( ! $s['s3_access'] || ! $s['s3_bucket'] ) {
			return array(
				'ok'      => false,
				'message' => 'Remote storage is not configured yet (Settings → Remote storage).',
			);
		}
		if ( ! Digifix_TC_Bk_Util::s3_secret() ) {
			return array(
				'ok'      => false,
				'message' => 'The S3 secret key is missing or unreadable. Enter it again in Settings.',
			);
		}
		$labels = array(
			'amazons3' => 'Amazon S3',
			'r2'       => 'Cloudflare R2',
			's3compat' => 'S3-compatible',
		);
		return array(
			'ok'      => true,
			'message' => 'Built-in engine, ' . ( $labels[ $s['s3_type'] ] ?? $s['s3_type'] ) . ': ' . $s['s3_bucket'] . '/' . ( $s['s3_path'] ? $s['s3_path'] : Digifix_TC_Bk_Util::default_folder() ) . '. Full backup every ' . (int) $s['backup_full_days'] . ' day(s), incremental in between.',
		);
	}

	public static function schedule_run() {
		self::queue( 'schedule' );
	}

	public static function queue( $trigger = 'manual', $full = false ) {
		$job = Digifix_TC_Jobs::create(
			'backup',
			array(
				'trigger' => $trigger,
				'full'    => (bool) $full,
				'engine'  => self::engine(),
			)
		);
		Digifix_TC_Jobs::kick();
		return $job;
	}

	public function max_age() {
		return ( (int) Digifix_TC_Settings::get( 'backup_timeout_hours' ) + 1 ) * HOUR_IN_SECONDS;
	}

	public function run( Digifix_TC_Job $job ) {
		if ( 'wpvivid' === $job->get( 'engine' ) ) {
			return $this->run_wpvivid( $job );
		}

		switch ( $job->step ) {
			case 'start':
				Digifix_TC_Backup_Runner::start( $job, $job->get( 'trigger' ) === 'schedule' ? 'schedule' : 'manual', (bool) $job->get( 'full' ) );
				$job->set( 'started', time() );
				Digifix_TC_Logger::log( 'backup', 'info', 'backup.started', 'Backup started (' . $job->get( 'trigger' ) . ').', array(), $job->id );
				$job->go( 'run', 0, 'Backup starting.' );
				return;

			case 'run':
				$res = Digifix_TC_Backup_Runner::step( $job );
				if ( is_wp_error( $res ) ) {
					return $this->failed( $job, $res->get_error_message() );
				}
				if ( 'done' === $res ) {
					$job->go( 'record' );
					return;
				}
				$timeout = (int) Digifix_TC_Settings::get( 'backup_timeout_hours' ) * HOUR_IN_SECONDS;
				if ( time() - (int) $job->get( 'started' ) > $timeout ) {
					return $this->failed( $job, 'Backup did not finish within ' . Digifix_TC_Settings::get( 'backup_timeout_hours' ) . ' hours. Last status: ' . $job->message );
				}
				$job->go( 'run', 0 );
				return;

			case 'record':
				$bk      = (array) $job->get( Digifix_TC_Backup_Runner::KEY );
				$summary = (array) ( $bk['summary'] ?? array() );
				Digifix_TC_Logger::log(
					'backup',
					'success',
					'backup.completed',
					sprintf(
						'%s backup completed: %s uploaded, %s of %s files changed%s.',
						ucfirst( $summary['type'] ?? 'Full' ),
						$summary['size_human'] ?? '?',
						number_format_i18n( $summary['changed_files'] ?? 0 ),
						number_format_i18n( $summary['total_files'] ?? 0 ),
						! empty( $summary['reason'] ) ? ' (' . $summary['reason'] . ')' : ''
					),
					$summary,
					$job->id
				);
				$job->complete( 'Backup completed.' );
				Digifix_TC_Notifier::notify( 'backup.completed', $summary, $job->id );
				return;
		}
		$job->fail( 'Unknown step ' . $job->step );
	}

	public function cleanup( Digifix_TC_Job $job ) {
		if ( 'wpvivid' !== $job->get( 'engine' ) ) {
			Digifix_TC_Backup_Runner::cleanup( $job );
		}
	}

	private function failed( Digifix_TC_Job $job, $error ) {
		$job->fail( $error );
		$bk = (array) $job->get( Digifix_TC_Backup_Runner::KEY );
		Digifix_TC_Logger::log( 'backup', 'error', 'backup.failed', 'Backup failed: ' . $error, array( 'backup_id' => $bk['id'] ?? $job->get( 'task_id' ) ), $job->id );
		Digifix_TC_Notifier::notify( 'backup.failed', array( 'error' => $error ), $job->id );
		$this->cleanup( $job );
	}

	/* ---------------------------------------------------------------------
	 * Legacy: WPvivid engine
	 * ------------------------------------------------------------------ */

	private function run_wpvivid( Digifix_TC_Job $job ) {
		switch ( $job->step ) {
			case 'start':
				$task_id = Digifix_TC_WPvivid::start_backup( false );
				if ( is_wp_error( $task_id ) ) {
					if ( 'digifix_tc_wpvivid_busy' === $task_id->get_error_code() && $job->age() < HOUR_IN_SECONDS ) {
						$job->wait( 5 * MINUTE_IN_SECONDS, 'Another WPvivid backup is running; waiting.' );
						return;
					}
					return $this->failed( $job, $task_id->get_error_message() );
				}
				$job->set( 'task_id', $task_id )->set( 'started', time() );
				Digifix_TC_Logger::log( 'backup', 'info', 'backup.started', 'Backup started with WPvivid (' . $job->get( 'trigger' ) . ').', array( 'backup_id' => $task_id ), $job->id );
				$job->go( 'wait', 60, 'Backup running.' );
				return;

			case 'wait':
				$status = Digifix_TC_WPvivid::backup_status( $job->get( 'task_id' ) );
				if ( 'completed' === $status['status'] ) {
					$job->go( 'record' );
					return;
				}
				if ( 'failed' === $status['status'] ) {
					return $this->failed( $job, $status['error'] );
				}
				$timeout = (int) Digifix_TC_Settings::get( 'backup_timeout_hours' ) * HOUR_IN_SECONDS;
				if ( time() - (int) $job->get( 'started' ) > $timeout ) {
					return $this->failed( $job, 'Backup did not finish within ' . Digifix_TC_Settings::get( 'backup_timeout_hours' ) . ' hours. Last WPvivid status: ' . ( $status['progress'] ?: $status['status'] ) . '. See WPvivid → Logs for details.' );
				}
				$job->wait( 60, 'Backup running: ' . ( $status['progress'] ?: $status['status'] ) );
				return;

			case 'record':
				$summary             = Digifix_TC_WPvivid::summarize( $job->get( 'task_id' ) );
				$summary['duration'] = time() - (int) $job->get( 'started' );
				$job->set( 'summary', $summary );
				Digifix_TC_Logger::log( 'backup', 'success', 'backup.completed', sprintf( 'Backup completed (%s) and uploaded to %s.', $summary['size_human'] ?? '?', implode( ', ', $summary['remote'] ?? array() ) ?: 'remote storage' ), $summary, $job->id );
				$job->complete( 'Backup completed.' );
				Digifix_TC_Notifier::notify( 'backup.completed', $summary, $job->id );
				return;
		}
		$job->fail( 'Unknown step ' . $job->step );
	}
}

/**
 * Daily housekeeping job: prune old backups by the retention policy and
 * drop database tables kept for undoing a restore.
 */
class Digifix_TC_Retention_Service {

	public static function queue() {
		if ( 'totalcare' !== Digifix_TC_Backup_Service::engine() ) {
			return null;
		}
		return Digifix_TC_Jobs::create( 'retention' );
	}

	public function max_age() {
		return HOUR_IN_SECONDS;
	}

	public function run( Digifix_TC_Job $job ) {
		// Database copies kept to undo the last restore expire after a day.
		try {
			Digifix_TC_Bk_Db_Import::drop_old( DAY_IN_SECONDS );
		} catch ( Throwable $e ) {
			Digifix_TC_Logger::log( 'restore', 'warning', 'restore.cleanup_failed', 'Could not drop old pre-restore tables: ' . wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), array(), $job->id );
		}

		$remote = Digifix_TC_Bk_Remote::from_settings();
		if ( is_wp_error( $remote ) ) {
			$job->complete( 'Skipped: ' . $remote->get_error_message() );
			return;
		}
		$owner = $remote->check_owner();
		if ( is_wp_error( $owner ) ) {
			$job->complete( 'Skipped: ' . $owner->get_error_message() );
			return;
		}
		$s   = Digifix_TC_Settings::all();
		$res = Digifix_TC_Bk_Retention::run( $remote, array_intersect_key( $s, Digifix_TC_Bk_Retention::defaults() ) );
		if ( is_wp_error( $res ) ) {
			throw new Exception( esc_html( $res->get_error_message() ) );
		}
		if ( $res['deleted'] || $res['orphans'] ) {
			Digifix_TC_Logger::log( 'backup', 'info', 'backup.pruned', sprintf( 'Retention: deleted %d old backup(s) and %d incomplete one(s); %d kept.', count( $res['deleted'] ), count( $res['orphans'] ), $res['kept'] ), $res, $job->id );
		}
		$job->complete( sprintf( '%d backup(s) kept, %d deleted.', $res['kept'], count( $res['deleted'] ) ) );
	}
}
