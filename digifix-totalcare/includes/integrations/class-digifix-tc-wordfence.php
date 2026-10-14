<?php
/**
 * Wordfence (free) integration, verified against Wordfence 9.0.2.
 *
 * Wordfence has no public scan API and fires no completion hook, so scans are
 * started through wfScanEngine::startScan() and completion is detected by
 * polling wfConfig ('scanTime', 'lastScanCompleted').
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Wordfence {

	const TESTED_UP_TO = '9.0.2';

	public static function is_available() {
		return class_exists( 'wfScanEngine' ) && class_exists( 'wfScanner' ) && class_exists( 'wfIssues' ) && class_exists( 'wfConfig' )
			&& method_exists( 'wfScanEngine', 'startScan' );
	}

	public static function version() {
		return defined( 'WORDFENCE_VERSION' ) ? WORDFENCE_VERSION : null;
	}

	public static function status() {
		if ( ! self::is_available() ) {
			return array(
				'ok'      => false,
				'message' => 'Wordfence is not active or its internal API has changed.',
			);
		}
		return array(
			'ok'      => true,
			'message' => 'Wordfence ' . self::version() . ' active.',
			'warning' => version_compare( (string) self::version(), self::TESTED_UP_TO, '>' ) ? 'Wordfence is newer than the tested version (' . self::TESTED_UP_TO . ').' : '',
		);
	}

	public static function is_running() {
		return self::is_available() && wfScanner::shared()->isRunning();
	}

	/**
	 * @param string $type quick|standard.
	 * @return true|WP_Error
	 */
	public static function start( $type ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'dtc_wordfence', 'Wordfence is not active.' );
		}
		$mode = 'quick' === $type ? wfScanner::SCAN_TYPE_QUICK : wfScanner::SCAN_TYPE_STANDARD;
		try {
			$result = wfScanEngine::startScan( false, $mode );
			if ( is_string( $result ) && '' !== $result ) {
				return new WP_Error( 'dtc_wordfence', wp_strip_all_tags( $result ) );
			}
		} catch ( Exception $e ) {
			// Mirror Wordfence's own error handling so its UI stays consistent.
			if ( class_exists( 'wfScanEngineTestCallbackFailedException' ) && $e instanceof wfScanEngineTestCallbackFailedException ) {
				wfConfig::set( 'lastScanCompleted', $e->getMessage() );
				wfConfig::set( 'lastScanFailureType', wfIssues::SCAN_FAILED_CALLBACK_TEST_FAILED );
				if ( class_exists( 'wfUtils' ) ) {
					wfUtils::clearScanLock();
				}
			}
			return new WP_Error( 'dtc_wordfence', 'Wordfence could not start the scan: ' . $e->getMessage() );
		}
		return true;
	}

	/**
	 * @param float $started_at Unix time when start() was called.
	 * @return array{status:string,error:string} status: running|completed|failed|starting
	 */
	public static function scan_status( $started_at ) {
		$running    = self::is_running();
		$scan_time  = (float) wfConfig::get( 'scanTime', 0, false );
		$last       = wfConfig::get( 'lastScanCompleted', false, false );
		$finished   = $scan_time >= $started_at;

		if ( $running ) {
			// Stalled stages are resumed by Wordfence's own scan monitor; the
			// job's overall timeout covers scans that never finish.
			return array( 'status' => 'running', 'error' => '' );
		}
		if ( $finished ) {
			if ( 'ok' === $last ) {
				return array( 'status' => 'completed', 'error' => '' );
			}
			return array( 'status' => 'failed', 'error' => is_string( $last ) && $last ? wp_strip_all_tags( $last ) : 'Wordfence scan failed (' . (string) wfConfig::get( 'lastScanFailureType', '', false ) . ').' );
		}

		// Failed after running: Wordfence records scanTime only on success,
		// but updates its status time while the scan runs.
		if ( (float) wfConfig::get( 'wf_scanLastStatusTime', 0, false ) >= $started_at && 'ok' !== $last && is_string( $last ) && '' !== $last ) {
			return array( 'status' => 'failed', 'error' => wp_strip_all_tags( $last ) );
		}

		// Not running and not finished: still starting, or failed to start.
		// lastScanFailureType can be stale from an earlier scan, so only trust
		// the start timeout (based on this scan's scanStartAttempt).
		if ( wfIssues::SCAN_FAILED_START_TIMEOUT === wfIssues::hasScanFailed() ) {
			return array( 'status' => 'failed', 'error' => 'Wordfence scan did not start (the scan worker request never ran; check loopback requests to admin-ajax.php).' );
		}
		if ( time() - $started_at > 15 * MINUTE_IN_SECONDS ) {
			return array( 'status' => 'failed', 'error' => 'Wordfence scan did not start within 15 minutes.' );
		}
		return array( 'status' => 'starting', 'error' => '' );
	}

	public static function severity_label( $severity ) {
		$severity = (int) $severity;
		if ( $severity >= 100 ) {
			return 'Critical';
		}
		if ( $severity >= 75 ) {
			return 'High';
		}
		if ( $severity >= 50 ) {
			return 'Medium';
		}
		if ( $severity >= 25 ) {
			return 'Low';
		}
		return 'Info';
	}

	/** Snapshot of the current (new, unignored) issues. */
	public static function results( $limit = 200 ) {
		$issues_api = wfIssues::shared();
		$counts     = array_merge(
			array(
				'new'     => 0,
				'ignoreP' => 0,
				'ignoreC' => 0,
			),
			(array) $issues_api->getIssueCounts()
		);
		$raw        = $issues_api->getIssues( 0, $limit, 0, 0 );
		$issues     = array();
		$by_sev     = array(
			'Critical' => 0,
			'High'     => 0,
			'Medium'   => 0,
			'Low'      => 0,
			'Info'     => 0,
		);
		foreach ( (array) ( $raw['new'] ?? array() ) as $issue ) {
			$label = self::severity_label( $issue['severity'] ?? 0 );
			++$by_sev[ $label ];
			$issues[] = array(
				'id'       => (int) $issue['id'],
				'type'     => $issue['type'],
				'severity' => (int) $issue['severity'],
				'label'    => $label,
				'message'  => wp_strip_all_tags( (string) $issue['shortMsg'] ),
			);
		}
		return array(
			'counts'      => $counts,
			'by_severity' => $by_sev,
			'issues'      => $issues,
			'scan_url'    => admin_url( 'admin.php?page=WordfenceScan' ),
		);
	}
}
