<?php
/**
 * Retention: which backups to keep, and pruning the rest.
 *
 * Keep set = newest N + everything from the last A days + pre-update
 * backups from the last P days + one per day for D days + one per week for
 * W weeks + one per month for M months + pinned + the newest full backup,
 * then every backup those depend on (incrementals need older files).
 * Weekly and monthly picks prefer the period's full backup, which keeps the
 * chains short.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Retention {

	public static function defaults() {
		return array(
			'retention_min'         => 3,
			'retention_all_days'    => 3,
			'retention_daily'       => 14,
			'retention_weekly'      => 8,
			'retention_monthly'     => 12,
			'retention_update_days' => 14,
		);
	}

	/**
	 * @param array<string,array> $backups id => [time, full, trigger, depends_on[], pinned]
	 * @return array{keep:string[],delete:string[]}
	 */
	public static function plan( array $backups, array $policy, $now, DateTimeZone $tz ) {
		$p = array_merge( self::defaults(), $policy );
		krsort( $backups, SORT_STRING );
		$ids  = array_keys( $backups );
		$keep = array();

		foreach ( array_slice( $ids, 0, max( 1, (int) $p['retention_min'] ) ) as $id ) {
			$keep[ $id ] = 'newest';
		}

		$buckets = array(
			'day'   => array(),
			'week'  => array(),
			'month' => array(),
		);
		$limits  = array(
			'day'   => self::bucket_keys( $now, $tz, 'day', (int) $p['retention_daily'] ),
			'week'  => self::bucket_keys( $now, $tz, 'week', (int) $p['retention_weekly'] ),
			'month' => self::bucket_keys( $now, $tz, 'month', (int) $p['retention_monthly'] ),
		);

		$newest_full = null;
		foreach ( $backups as $id => $b ) {
			$t = (int) $b['time'];
			if ( ! empty( $b['pinned'] ) ) {
				$keep[ $id ] = 'pinned';
			}
			if ( $t >= $now - (int) $p['retention_all_days'] * DAY_IN_SECONDS ) {
				$keep[ $id ] = 'recent';
			}
			if ( 'update' === $b['trigger'] && $t >= $now - (int) $p['retention_update_days'] * DAY_IN_SECONDS ) {
				$keep[ $id ] = 'pre-update';
			}
			if ( ! empty( $b['full'] ) && null === $newest_full ) {
				$newest_full = $id;
			}

			$dt = ( new DateTimeImmutable( '@' . $t ) )->setTimezone( $tz );
			foreach ( array(
				'day'   => $dt->format( 'Y-m-d' ),
				'week'  => $dt->format( 'o-W' ),
				'month' => $dt->format( 'Y-m' ),
			) as $kind => $key ) {
				if ( ! isset( $limits[ $kind ][ $key ] ) ) {
					continue;
				}
				$current = $buckets[ $kind ][ $key ] ?? null;
				// Ids are visited newest first: the first one wins, except that a
				// full backup replaces an incremental for weeks and months.
				if ( null === $current || ( 'day' !== $kind && ! empty( $b['full'] ) && empty( $backups[ $current ]['full'] ) ) ) {
					$buckets[ $kind ][ $key ] = $id;
				}
			}
		}
		foreach ( $buckets as $kind => $picked ) {
			foreach ( $picked as $id ) {
				$keep[ $id ] = $kind;
			}
		}
		if ( $newest_full ) {
			$keep[ $newest_full ] = 'newest full';
		}

		// Dependencies (only of backups we actually have).
		$queue = array_keys( $keep );
		while ( $queue ) {
			$id = array_pop( $queue );
			foreach ( (array) ( $backups[ $id ]['depends_on'] ?? array() ) as $dep ) {
				if ( isset( $backups[ $dep ] ) && ! isset( $keep[ $dep ] ) ) {
					$keep[ $dep ] = 'dependency';
					$queue[]      = $dep;
				}
			}
		}

		return array(
			'keep'   => $keep,
			'delete' => array_values( array_diff( $ids, array_keys( $keep ) ) ),
		);
	}

	/** The last $count period keys ending with the current one. */
	private static function bucket_keys( $now, DateTimeZone $tz, $kind, $count ) {
		$keys = array();
		$dt   = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $tz );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( 'day' === $kind ) {
				$keys[ $dt->modify( '-' . $i . ' days' )->format( 'Y-m-d' ) ] = true;
			} elseif ( 'week' === $kind ) {
				$keys[ $dt->modify( '-' . $i . ' weeks' )->format( 'o-W' ) ] = true;
			} else {
				$keys[ $dt->modify( 'first day of this month' )->modify( '-' . $i . ' months' )->format( 'Y-m' ) ] = true;
			}
		}
		return $keys;
	}

	/**
	 * Prune the folder. Call only after the folder ownership check passed.
	 *
	 * @param string $running Backup id currently being written (never touched).
	 * @return array{deleted:string[],orphans:string[],kept:int}|WP_Error
	 */
	public static function run( DTC_Bk_Remote $remote, array $policy, $running = '' ) {
		$scan = $remote->scan();
		if ( is_wp_error( $scan ) ) {
			return $scan;
		}
		$catalog = $remote->catalog();
		if ( is_wp_error( $catalog ) ) {
			return $catalog;
		}

		$backups  = array();
		$entries  = array();
		$orphans  = array();
		$complete = 0;
		foreach ( $scan as $id => $info ) {
			if ( $id === $running ) {
				continue;
			}
			$parsed = DTC_Bk_Remote::parse_id( $id );
			if ( ! $info['complete'] ) {
				// Failed or cancelled backups leave folders without a manifest.
				if ( $parsed && $info['modified'] < time() - 2 * DAY_IN_SECONDS ) {
					$orphans[] = $id;
				}
				continue;
			}
			$entry = $catalog[ $id ] ?? null;
			if ( ! $entry ) {
				$m = $remote->manifest( $id );
				if ( ! is_array( $m ) ) {
					continue; // Unreadable: leave it alone.
				}
				$entry = DTC_Bk_Remote::catalog_entry( $m );
			}
			$entry['pinned'] = $info['pinned'];
			$entries[ $id ]  = $entry;
			$backups[ $id ]  = array(
				'time'       => $parsed ? $parsed['time'] : (int) $entry['created'],
				'full'       => 'full' === $entry['type'],
				'trigger'    => $entry['trigger'],
				'depends_on' => $entry['depends_on'],
				'pinned'     => $info['pinned'],
			);
			++$complete;
		}
		if ( ! $complete ) {
			return array(
				'deleted' => array(),
				'orphans' => array(),
				'kept'    => 0,
			);
		}

		$plan = self::plan( $backups, $policy, time(), wp_timezone() );
		$del  = array_merge( $plan['delete'], $orphans );
		if ( $del ) {
			$res = $remote->delete_backups( $del, $scan );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
		}
		foreach ( $plan['delete'] as $id ) {
			unset( $entries[ $id ] );
		}
		$remote->save_catalog( $entries );

		// Abandoned multipart uploads still cost storage on some providers.
		$uploads = $remote->s3->multipart_list( $remote->key( 'backups/' ) );
		if ( is_array( $uploads ) ) {
			foreach ( $uploads as $u ) {
				if ( $u['initiated'] && $u['initiated'] < time() - 2 * DAY_IN_SECONDS ) {
					$remote->s3->multipart_abort( $u['key'], $u['upload_id'] );
				}
			}
		}

		return array(
			'deleted' => $plan['delete'],
			'orphans' => $orphans,
			'kept'    => count( $plan['keep'] ),
		);
	}
}
