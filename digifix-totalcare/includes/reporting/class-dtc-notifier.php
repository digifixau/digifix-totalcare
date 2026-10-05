<?php
/**
 * Routes events to the webhook and to email recipients.
 *
 * Events: backup.completed, backup.failed, update.completed, update.failed,
 * update.rolled_back, update.restored, restore.completed, restore.failed,
 * scan.completed, scan.failed, scan.issues_found, report.monthly, test.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Notifier {

	const ALERTS = array( 'backup.failed', 'update.failed', 'update.rolled_back', 'update.restored', 'restore.completed', 'restore.failed', 'scan.failed', 'scan.issues_found' );

	public static function notify( $event, array $data = array(), $job_id = 0 ) {
		$s = DTC_Settings::all();

		if ( $s['webhook_url'] ) {
			$payload = $data;
			if ( 'report.monthly' === $event ) {
				$payload = array( 'report' => array_diff_key( $data['report'], array( 'events' => 1 ) ) );
			}
			DTC_Webhook::send( $event, $payload, $job_id );
		}

		$to = self::recipients( $event, $data );
		if ( ! $to ) {
			return;
		}

		$mail        = self::compose( $event, $data );
		$attachments = array();
		if ( 'report.monthly' === $event && $s['monthly_attach_pdf'] ) {
			$pdf = DTC_PDF::generate( $data['report'] );
			if ( $pdf ) {
				$attachments[] = $pdf;
			}
		}

		$sent = DTC_Email::send( $to, $mail['subject'], $mail, $attachments );
		if ( ! $sent ) {
			DTC_Logger::log( 'report', 'warning', 'report.email_failed', 'Could not send "' . $mail['subject'] . '" email (wp_mail returned false).', array( 'to' => $to ), $job_id );
		} elseif ( 'report.monthly' === $event ) {
			DTC_Logger::log( 'report', 'success', 'report.monthly_sent', 'Monthly report sent to ' . implode( ', ', $to ) . '.', array( 'period' => $data['report']['period']['label'] ), $job_id );
		}
	}

	private static function recipients( $event, array $data ) {
		$s        = DTC_Settings::all();
		$internal = DTC_Settings::emails( 'internal_emails' );
		$client   = DTC_Settings::emails( 'client_emails' );

		if ( in_array( $event, self::ALERTS, true ) ) {
			return $s['email_failures'] ? $internal : array();
		}
		switch ( $event ) {
			case 'update.completed':
				$changed = array_sum( array_intersect_key( $data['counts'] ?? array(), array_flip( array( 'updated', 'rolled_back', 'failed' ) ) ) );
				if ( ! $s['email_update_summary'] || ! $changed ) {
					return array();
				}
				return array_unique( array_merge( $internal, $s['client_gets_summaries'] ? $client : array() ) );
			case 'scan.completed':
				if ( ! $s['email_scan_summary'] ) {
					return array();
				}
				return array_unique( array_merge( $internal, $s['client_gets_summaries'] ? $client : array() ) );
			case 'report.monthly':
				return $s['monthly_report'] ? array_unique( array_merge( $internal, $client ) ) : array();
			case 'test':
				return array_unique( array_merge( $internal, $client ) );
		}
		return array();
	}

	/**
	 * Build the email model: subject, title, level, intro, sections.
	 * A section is ['heading' => ..., 'rows' => [[label, value]...]] or
	 * ['heading' => ..., 'table' => ['head' => [...], 'rows' => [[...]]]].
	 */
	public static function compose( $event, array $data ) {
		$site  = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$items = function ( array $list ) {
			return array_map(
				function ( $i ) {
					return array( $i['name'], ucfirst( $i['kind'] ), $i['from'] . ' → ' . $i['to'], ucwords( str_replace( '_', ' ', $i['status'] ) ) . ( ! empty( $i['reason'] ) ? ': ' . $i['reason'] : '' ) );
				},
				$list
			);
		};
		$update_table = function () use ( $data, $items ) {
			return array(
				'heading' => 'Updates',
				'table'   => array(
					'head' => array( 'Item', 'Type', 'Version', 'Result' ),
					'rows' => $items( $data['items'] ?? array() ),
				),
			);
		};

		switch ( $event ) {
			case 'backup.failed':
				return self::mail( "[$site] Backup failed", 'Backup failed', 'error', 'The scheduled backup did not complete.', array( array( 'heading' => 'Details', 'rows' => array( array( 'Error', $data['error'] ?? '' ) ) ) ) );

			case 'update.failed':
				return self::mail( "[$site] Update run needs attention", 'Update run failed', 'error', $data['error'] ?? 'The update run stopped.', array( $update_table() ) );

			case 'update.rolled_back':
				return self::mail(
					"[$site] Update rolled back: " . $data['name'],
					'Update rolled back',
					'warning',
					sprintf( '%s %s broke the site, so it was automatically rolled back to %s. Other updates continue.', $data['name'], $data['to'], $data['from'] ),
					array( array( 'heading' => 'Why', 'rows' => array( array( 'Health check', $data['reason'] ?? '' ) ) ) )
				);

			case 'update.restored':
				return self::mail(
					"[$site] Site restored from pre-update backup",
					'Site restored from backup',
					$data['health_passed'] ? 'warning' : 'error',
					$data['health_passed'] ? 'The site failed health checks after updating, so the full pre-update backup was restored. The site is healthy again.' : 'The site failed health checks after updating and was restored from backup, but it is STILL failing. Manual attention is required.',
					array( $update_table(), array( 'heading' => 'Health check', 'rows' => array_map( function ( $m ) { return array( 'Problem', $m ); }, (array) ( $data['health'] ?? array() ) ) ) )
				);

			case 'restore.completed':
				return self::mail( "[$site] Restore completed", 'Restore completed', 'warning', 'Backup ' . ( $data['backup_id'] ?? '' ) . ' was restored.', array( array( 'heading' => 'Details', 'rows' => array( array( 'Reason', $data['reason'] ?? '' ), array( 'Duration', human_time_diff( 0, (int) ( $data['duration'] ?? 0 ) ) ) ) ) ) );

			case 'restore.failed':
				return self::mail( "[$site] URGENT: restore failed", 'Restore failed', 'error', 'An automatic restore failed. The site may be broken and needs manual attention now.', array( array( 'heading' => 'Details', 'rows' => array( array( 'Error', $data['error'] ?? '' ), array( 'Backup', $data['backup_id'] ?? '' ) ) ) ) );

			case 'scan.failed':
				return self::mail( "[$site] Malware scan failed", 'Malware scan failed', 'error', 'The scheduled Wordfence scan did not complete.', array( array( 'heading' => 'Details', 'rows' => array( array( 'Error', $data['error'] ?? '' ) ) ) ) );

			case 'scan.issues_found':
			case 'scan.completed':
				$alert  = 'scan.issues_found' === $event;
				$rows   = array_map(
					function ( $i ) {
						return array( $i['label'], $i['message'] );
					},
					array_slice( (array) ( $data['issues'] ?? array() ), 0, 25 )
				);
				$counts = array();
				foreach ( (array) ( $data['by_severity'] ?? array() ) as $label => $count ) {
					$counts[] = array( $label, (string) $count );
				}
				return self::mail(
					$alert ? "[$site] Security issues found" : "[$site] Malware scan completed",
					$alert ? 'Security issues found' : 'Malware scan completed',
					$alert ? 'error' : ( empty( $data['counts']['new'] ) ? 'success' : 'warning' ),
					empty( $data['counts']['new'] ) ? 'Wordfence found no issues.' : sprintf( 'Wordfence reports %d open issue(s). Review them in Wordfence → Scan.', (int) $data['counts']['new'] ),
					array_filter(
						array(
							array( 'heading' => 'Open issues by severity', 'rows' => $counts ),
							$rows ? array( 'heading' => $alert ? 'Issues at or above alert level' : 'Issues', 'table' => array( 'head' => array( 'Severity', 'Issue' ), 'rows' => $rows ) ) : null,
						)
					),
					array( 'Open Wordfence scan', $data['scan_url'] ?? admin_url() )
				);

			case 'update.completed':
				$c = $data['counts'];
				return self::mail(
					"[$site] Weekly updates: {$c['updated']} updated" . ( $c['rolled_back'] ? ", {$c['rolled_back']} rolled back" : '' ),
					'Weekly update report',
					$c['rolled_back'] || $c['failed'] ? 'warning' : 'success',
					sprintf( 'A backup was taken, then %d update(s) were applied one at a time with a health check after each.', $c['updated'] + $c['rolled_back'] ),
					array( $update_table() )
				);

			case 'report.monthly':
				$r = $data['report'];
				return self::mail(
					sprintf( '[%s] Website care report — %s', $site, $r['period']['label'] ),
					'Website care report',
					'info',
					sprintf( 'Here is the maintenance summary for %s for %s.', $site, $r['period']['label'] ),
					DTC_Report_Builder::email_sections( $r )
				);

			case 'test':
				return self::mail( "[$site] TotalCare test email", 'Test email', 'success', 'Email delivery from Digifix TotalCare works.', array() );
		}

		return self::mail( "[$site] TotalCare: $event", $event, 'info', '', array( array( 'heading' => 'Data', 'rows' => array( array( 'JSON', wp_json_encode( $data ) ) ) ) ) );
	}

	private static function mail( $subject, $title, $level, $intro, array $sections, $button = null ) {
		return compact( 'subject', 'title', 'level', 'intro', 'sections', 'button' );
	}
}
