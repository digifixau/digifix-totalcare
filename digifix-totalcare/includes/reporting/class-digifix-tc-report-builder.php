<?php
/**
 * Aggregates the event log into a period report (monthly email, PDF, admin).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Report_Builder {

	public static function build( DateTimeImmutable $start, DateTimeImmutable $end ) {
		$utc   = new DateTimeZone( 'UTC' );
		$since = $start->setTimezone( $utc )->format( 'Y-m-d H:i:s' );
		$until = $end->setTimezone( $utc )->format( 'Y-m-d H:i:s' );

		$events = DTC_Logger::query(
			array(
				'since'    => $since,
				'until'    => $until,
				'per_page' => 5000,
			)
		)['rows'];
		$events = array_reverse( $events ); // Oldest first.

		$report = array(
			'period'   => array(
				'start' => $start->format( 'Y-m-d' ),
				'end'   => $end->modify( '-1 second' )->format( 'Y-m-d' ),
				'label' => $start->format( 'F Y' ) === $end->modify( '-1 second' )->format( 'F Y' ) ? $start->format( 'F Y' ) : $start->format( 'j M Y' ) . ' – ' . $end->modify( '-1 second' )->format( 'j M Y' ),
			),
			'site'     => array(
				'name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
				'url'        => home_url(),
				'client'     => DTC_Settings::get( 'client_name' ),
				'wp_version' => get_bloginfo( 'version' ),
				'php'        => PHP_VERSION,
			),
			'backups'  => array(
				'completed' => 0,
				'failed'    => 0,
				'total_size' => 0,
				'list'      => array(),
			),
			'updates'  => array(
				'runs'        => 0,
				'updated'     => array(),
				'rolled_back' => array(),
				'failed'      => array(),
			),
			'restores' => array(),
			'scans'    => array(
				'completed' => 0,
				'failed'    => 0,
				'list'      => array(),
				'latest'    => null,
			),
			'incidents' => 0,
			'generated' => current_time( 'mysql' ),
		);

		foreach ( $events as $e ) {
			$ctx  = $e['context'];
			$date = get_date_from_gmt( $e['created_at'], 'Y-m-d H:i' );
			switch ( $e['code'] ) {
				case 'backup.completed':
					++$report['backups']['completed'];
					$report['backups']['total_size'] += (int) ( $ctx['size'] ?? 0 );
					$report['backups']['list'][]      = array(
						'date'       => $date,
						'size'       => $ctx['size_human'] ?? '',
						'pre_update' => ! empty( $ctx['pre_update'] ),
					);
					break;
				case 'backup.failed':
					++$report['backups']['failed'];
					++$report['incidents'];
					break;
				case 'update.completed':
				case 'update.none':
					++$report['updates']['runs'];
					break;
				case 'update.restored':
					// The full restore reverted everything this run applied.
					++$report['updates']['runs'];
					$job_id                         = (int) $e['job_id'];
					$report['updates']['updated']   = array_values(
						array_filter(
							$report['updates']['updated'],
							function ( $row ) use ( $job_id ) {
								return $row['job'] !== $job_id;
							}
						)
					);
					break;
				case 'update.item_updated':
					$report['updates']['updated'][] = self::item_row( $ctx, $date, (int) $e['job_id'] );
					break;
				case 'update.rolled_back':
					$report['updates']['rolled_back'][] = self::item_row( $ctx, $date, (int) $e['job_id'] ) + array( 'reason' => $ctx['reason'] ?? '' );
					++$report['incidents'];
					break;
				case 'update.item_failed':
					$report['updates']['failed'][] = self::item_row( $ctx, $date, (int) $e['job_id'] ) + array( 'reason' => $ctx['reason'] ?? '' );
					break;
				case 'restore.completed':
				case 'restore.failed':
					$report['restores'][] = array(
						'date'   => $date,
						'ok'     => 'restore.completed' === $e['code'],
						'reason' => $ctx['reason'] ?? $e['message'],
					);
					++$report['incidents'];
					break;
				case 'scan.completed':
					++$report['scans']['completed'];
					$row                        = array(
						'date'        => $date,
						'type'        => $ctx['scan_type'] ?? '',
						'new'         => (int) ( $ctx['counts']['new'] ?? 0 ),
						'by_severity' => $ctx['by_severity'] ?? array(),
					);
					$report['scans']['list'][]  = $row;
					$report['scans']['latest']  = $row + array( 'issues' => array_slice( (array) ( $ctx['issues'] ?? array() ), 0, 20 ) );
					break;
				case 'scan.failed':
					++$report['scans']['failed'];
					break;
			}
		}

		$report['backups']['total_size_human'] = size_format( $report['backups']['total_size'], 1 );
		$report['events']                      = $events;
		return $report;
	}

	private static function item_row( array $ctx, $date, $job_id ) {
		return array(
			'job'  => $job_id,
			'date' => $date,
			'name' => $ctx['name'] ?? '',
			'kind' => $ctx['kind'] ?? '',
			'from' => $ctx['from'] ?? '',
			'to'   => $ctx['to'] ?? '',
		);
	}

	/** Sections for the monthly email (the PDF uses templates/report.php). */
	public static function email_sections( array $r ) {
		$sections   = array();
		$sections[] = array(
			'heading' => 'Summary',
			'rows'    => array(
				array( 'Backups completed', $r['backups']['completed'] . ( $r['backups']['failed'] ? ' (' . $r['backups']['failed'] . ' failed)' : '' ) ),
				array( 'Updates applied', (string) count( $r['updates']['updated'] ) ),
				array( 'Updates rolled back', (string) count( $r['updates']['rolled_back'] ) ),
				array( 'Malware scans', $r['scans']['completed'] . ( $r['scans']['failed'] ? ' (' . $r['scans']['failed'] . ' failed)' : '' ) ),
				array( 'Open security issues', null === $r['scans']['latest'] ? 'No scan this period' : (string) $r['scans']['latest']['new'] ),
				array( 'WordPress version', $r['site']['wp_version'] ),
			),
		);
		if ( $r['updates']['updated'] ) {
			$sections[] = array(
				'heading' => 'Updates applied',
				'table'   => array(
					'head' => array( 'Date', 'Item', 'Version' ),
					'rows' => array_map(
						function ( $i ) {
							return array( $i['date'], $i['name'], $i['from'] . ' → ' . $i['to'] );
						},
						$r['updates']['updated']
					),
				),
			);
		}
		if ( $r['updates']['rolled_back'] || $r['restores'] ) {
			$rows = array();
			foreach ( $r['updates']['rolled_back'] as $i ) {
				$rows[] = array( $i['date'], $i['name'] . ' ' . $i['to'] . ' rolled back' );
			}
			foreach ( $r['restores'] as $x ) {
				$rows[] = array( $x['date'], ( $x['ok'] ? 'Site restored from backup: ' : 'Restore FAILED: ' ) . $x['reason'] );
			}
			$sections[] = array(
				'heading' => 'Incidents handled',
				'table'   => array(
					'head' => array( 'Date', 'Event' ),
					'rows' => $rows,
				),
			);
		}
		return $sections;
	}
}
