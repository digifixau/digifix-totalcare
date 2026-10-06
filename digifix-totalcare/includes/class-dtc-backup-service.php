<?php
/**
 * Scheduled backup job: WPvivid files+db backup uploaded to S3.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Backup_Service {

	public static function schedule_run() {
		self::queue( 'schedule' );
	}

	public static function queue( $trigger = 'manual' ) {
		$job = DTC_Jobs::create( 'backup', array( 'trigger' => $trigger ) );
		DTC_Jobs::kick();
		return $job;
	}

	public function max_age() {
		return ( (int) DTC_Settings::get( 'backup_timeout_hours' ) + 1 ) * HOUR_IN_SECONDS;
	}

	public function run( DTC_Job $job ) {
		switch ( $job->step ) {
			case 'start':
				$task_id = DTC_WPvivid::start_backup( false );
				if ( is_wp_error( $task_id ) ) {
					if ( 'dtc_wpvivid_busy' === $task_id->get_error_code() && $job->age() < HOUR_IN_SECONDS ) {
						$job->wait( 5 * MINUTE_IN_SECONDS, 'Another WPvivid backup is running; waiting.' );
						return;
					}
					return $this->failed( $job, $task_id->get_error_message() );
				}
				$job->set( 'task_id', $task_id )->set( 'started', time() );
				DTC_Logger::log( 'backup', 'info', 'backup.started', 'Backup started (' . $job->get( 'trigger' ) . ').', array( 'backup_id' => $task_id ), $job->id );
				$job->go( 'wait', 60, 'Backup running.' );
				return;

			case 'wait':
				$status = DTC_WPvivid::backup_status( $job->get( 'task_id' ) );
				if ( 'completed' === $status['status'] ) {
					$job->go( 'record' );
					return;
				}
				if ( 'failed' === $status['status'] ) {
					return $this->failed( $job, $status['error'] );
				}
				$timeout = (int) DTC_Settings::get( 'backup_timeout_hours' ) * HOUR_IN_SECONDS;
				if ( time() - (int) $job->get( 'started' ) > $timeout ) {
					return $this->failed( $job, 'Backup did not finish within ' . DTC_Settings::get( 'backup_timeout_hours' ) . ' hours. Last WPvivid status: ' . ( $status['progress'] ?: $status['status'] ) . '. See WPvivid → Logs for details.' );
				}
				$job->wait( 60, 'Backup running: ' . ( $status['progress'] ?: $status['status'] ) );
				return;

			case 'record':
				$summary             = DTC_WPvivid::summarize( $job->get( 'task_id' ) );
				$summary['duration'] = time() - (int) $job->get( 'started' );
				$job->set( 'summary', $summary );
				DTC_Logger::log( 'backup', 'success', 'backup.completed', sprintf( 'Backup completed (%s) and uploaded to %s.', $summary['size_human'] ?? '?', implode( ', ', $summary['remote'] ?? array() ) ?: 'remote storage' ), $summary, $job->id );
				$job->complete( 'Backup completed.' );
				DTC_Notifier::notify( 'backup.completed', $summary, $job->id );
				return;
		}
		$job->fail( 'Unknown step ' . $job->step );
	}

	private function failed( DTC_Job $job, $error ) {
		$job->fail( $error );
		DTC_Logger::log( 'backup', 'error', 'backup.failed', 'Backup failed: ' . $error, array( 'backup_id' => $job->get( 'task_id' ) ), $job->id );
		DTC_Notifier::notify( 'backup.failed', array( 'error' => $error ), $job->id );
	}
}
