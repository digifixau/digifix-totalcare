<?php
/**
 * Scheduled Wordfence malware scan job.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Scan_Service {

	const SCAN_TIMEOUT = 4 * HOUR_IN_SECONDS;

	public static function schedule_run() {
		self::queue( 'schedule' );
	}

	public static function queue( $trigger = 'manual' ) {
		$job = DTC_Jobs::create( 'scan', array( 'trigger' => $trigger ) );
		DTC_Jobs::kick();
		return $job;
	}

	public function max_age() {
		return self::SCAN_TIMEOUT + 2 * HOUR_IN_SECONDS;
	}

	public function run( DTC_Job $job ) {
		switch ( $job->step ) {
			case 'start':
				if ( ! DTC_Wordfence::is_available() ) {
					return $this->failed( $job, 'Wordfence is not active.' );
				}
				if ( DTC_Wordfence::is_running() ) {
					if ( $job->age() > HOUR_IN_SECONDS ) {
						return $this->failed( $job, 'A Wordfence scan has been running for over an hour; skipped.' );
					}
					$job->wait( 5 * MINUTE_IN_SECONDS, 'Another Wordfence scan is running; waiting.' );
					return;
				}
				$type    = DTC_Settings::get( 'scan_type' );
				$started = time();
				$res     = DTC_Wordfence::start( $type );
				if ( is_wp_error( $res ) ) {
					return $this->failed( $job, $res->get_error_message() );
				}
				$job->set( 'started', $started )->set( 'scan_type', $type );
				DTC_Logger::log( 'scan', 'info', 'scan.started', ucfirst( $type ) . ' Wordfence scan started.', array( 'scan_type' => $type ), $job->id );
				$job->go( 'poll', 90, 'Scan running.' );
				return;

			case 'poll':
				$status = DTC_Wordfence::scan_status( (float) $job->get( 'started' ) );
				if ( 'completed' === $status['status'] ) {
					$job->go( 'collect' );
					return;
				}
				if ( 'failed' === $status['status'] ) {
					return $this->failed( $job, $status['error'] );
				}
				if ( time() - (int) $job->get( 'started' ) > self::SCAN_TIMEOUT ) {
					return $this->failed( $job, 'Wordfence scan did not finish within 4 hours.' );
				}
				$job->wait( 2 * MINUTE_IN_SECONDS, 'Scan ' . $status['status'] . '.' );
				return;

			case 'collect':
				$results              = DTC_Wordfence::results();
				$results['scan_type'] = $job->get( 'scan_type' );
				$results['duration']  = time() - (int) $job->get( 'started' );
				$threshold            = (int) DTC_Settings::get( 'scan_alert_severity' );
				$alerts               = array_values(
					array_filter(
						$results['issues'],
						function ( $issue ) use ( $threshold ) {
							return $issue['severity'] >= $threshold;
						}
					)
				);
				$results['alerts']    = count( $alerts );
				$job->set( 'results', array_diff_key( $results, array( 'issues' => 1 ) ) );

				$new   = (int) $results['counts']['new'];
				$level = $alerts ? 'error' : ( $new ? 'warning' : 'success' );
				$msg   = $new
					? sprintf( 'Scan completed: %d open issue(s) (%s).', $new, self::severity_summary( $results['by_severity'] ) )
					: 'Scan completed: no issues found.';
				DTC_Logger::log( 'scan', $level, 'scan.completed', $msg, $results, $job->id );
				$job->complete( $msg );

				if ( $alerts ) {
					DTC_Notifier::notify( 'scan.issues_found', array_merge( $results, array( 'issues' => $alerts ) ), $job->id );
				}
				DTC_Notifier::notify( 'scan.completed', $results, $job->id );
				return;
		}
		$job->fail( 'Unknown step ' . $job->step );
	}

	public static function severity_summary( array $by_severity ) {
		$parts = array();
		foreach ( $by_severity as $label => $count ) {
			if ( $count ) {
				$parts[] = $count . ' ' . strtolower( $label );
			}
		}
		return implode( ', ', $parts );
	}

	private function failed( DTC_Job $job, $error ) {
		$job->fail( $error );
		DTC_Logger::log( 'scan', 'error', 'scan.failed', 'Scan failed: ' . $error, array(), $job->id );
		DTC_Notifier::notify( 'scan.failed', array( 'error' => $error ), $job->id );
	}
}
