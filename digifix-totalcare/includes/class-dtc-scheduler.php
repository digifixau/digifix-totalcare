<?php
/**
 * WP-Cron schedules. All times are interpreted in the site's timezone.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Scheduler {

	const HOOK_TICK    = 'dtc_tick';
	const HOOK_BACKUP  = 'dtc_run_backup';
	const HOOK_UPDATES = 'dtc_run_updates';
	const HOOK_SCAN    = 'dtc_run_scan';
	const HOOK_MONTHLY = 'dtc_monthly_report';
	const HOOK_DAILY   = 'dtc_daily_maintenance';

	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'intervals' ) );

		add_action( self::HOOK_TICK, array( 'DTC_Jobs', 'tick' ) );
		add_action( self::HOOK_BACKUP, array( 'DTC_Backup_Service', 'schedule_run' ) );
		add_action( self::HOOK_UPDATES, array( 'DTC_Update_Service', 'schedule_run' ) );
		add_action( self::HOOK_SCAN, array( 'DTC_Scan_Service', 'schedule_run' ) );
		add_action( self::HOOK_MONTHLY, array( __CLASS__, 'monthly_report' ) );
		add_action( self::HOOK_DAILY, array( __CLASS__, 'daily_maintenance' ) );

		add_action( 'init', array( __CLASS__, 'ensure' ) );
	}

	public static function intervals( $schedules ) {
		$schedules['dtc_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => 'Every minute (TotalCare)',
		);
		if ( ! isset( $schedules['weekly'] ) ) {
			$schedules['weekly'] = array(
				'interval' => WEEK_IN_SECONDS,
				'display'  => 'Once weekly',
			);
		}
		return $schedules;
	}

	/** Make sure the always-on events exist (cheap check on every load). */
	public static function ensure() {
		if ( ! wp_next_scheduled( self::HOOK_TICK ) ) {
			wp_schedule_event( time() + 60, 'dtc_minute', self::HOOK_TICK );
		}
		if ( ! wp_next_scheduled( self::HOOK_DAILY ) ) {
			wp_schedule_event( self::next_time( '01:30' ), 'daily', self::HOOK_DAILY );
		}
		if ( ! wp_next_scheduled( self::HOOK_MONTHLY ) && DTC_Settings::get( 'monthly_report' ) ) {
			wp_schedule_single_event( self::next_month_start(), self::HOOK_MONTHLY );
		}

		// Self-heal if the cron option lost an enabled schedule (e.g. a
		// restore of an older database).
		static $healing = false;
		$s              = DTC_Settings::all();
		$missing        = ( $s['backup_enabled'] && ! wp_next_scheduled( self::HOOK_BACKUP ) )
			|| ( $s['updates_enabled'] && ! wp_next_scheduled( self::HOOK_UPDATES ) )
			|| ( $s['scan_enabled'] && ! wp_next_scheduled( self::HOOK_SCAN ) );
		if ( $missing && ! $healing ) {
			$healing = true;
			self::reschedule();
		}
	}

	/** Rebuild the configurable schedules from settings. */
	public static function reschedule() {
		$s = DTC_Settings::all();

		foreach ( array( self::HOOK_BACKUP, self::HOOK_UPDATES, self::HOOK_SCAN, self::HOOK_MONTHLY ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}

		if ( $s['backup_enabled'] ) {
			$day = 'weekly' === $s['backup_frequency'] ? $s['backup_day'] : null;
			wp_schedule_event( self::next_time( $s['backup_time'], $day ), $s['backup_frequency'], self::HOOK_BACKUP );
		}
		if ( $s['updates_enabled'] ) {
			wp_schedule_event( self::next_time( $s['update_time'], $s['update_day'] ), 'weekly', self::HOOK_UPDATES );
		}
		if ( $s['scan_enabled'] ) {
			$day = 'weekly' === $s['scan_frequency'] ? $s['scan_day'] : null;
			wp_schedule_event( self::next_time( $s['scan_time'], $day ), $s['scan_frequency'], self::HOOK_SCAN );
		}
		if ( $s['monthly_report'] ) {
			wp_schedule_single_event( self::next_month_start(), self::HOOK_MONTHLY );
		}
		self::ensure();
	}

	public static function clear_all() {
		foreach ( array( self::HOOK_TICK, self::HOOK_BACKUP, self::HOOK_UPDATES, self::HOOK_SCAN, self::HOOK_MONTHLY, self::HOOK_DAILY ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	/**
	 * Next UTC timestamp for "HH:MM" in site time, optionally on a weekday.
	 */
	public static function next_time( $hhmm, $weekday = null ) {
		$tz   = wp_timezone();
		$now  = new DateTimeImmutable( 'now', $tz );
		list( $h, $m ) = array_map( 'intval', explode( ':', $hhmm ) );

		$candidate = $now->setTime( $h, $m );
		if ( $weekday ) {
			if ( strtolower( $now->format( 'l' ) ) !== $weekday || $candidate <= $now ) {
				$candidate = $now->modify( 'next ' . $weekday )->setTime( $h, $m );
			}
		} elseif ( $candidate <= $now ) {
			$candidate = $candidate->modify( '+1 day' );
		}
		return $candidate->getTimestamp();
	}

	public static function next_month_start() {
		$tz = wp_timezone();
		return ( new DateTimeImmutable( 'first day of next month', $tz ) )->setTime( 8, 0 )->getTimestamp();
	}

	public static function monthly_report() {
		$tz    = wp_timezone();
		$start = ( new DateTimeImmutable( 'first day of last month', $tz ) )->setTime( 0, 0 );
		$end   = ( new DateTimeImmutable( 'first day of this month', $tz ) )->setTime( 0, 0 );

		$report = DTC_Report_Builder::build( $start, $end );
		DTC_Notifier::notify( 'report.monthly', array( 'report' => $report ) );

		if ( DTC_Settings::get( 'monthly_report' ) ) {
			wp_schedule_single_event( self::next_month_start(), self::HOOK_MONTHLY );
		}
	}

	public static function daily_maintenance() {
		DTC_Snapshot::cleanup( (int) DTC_Settings::get( 'snapshot_keep_days' ) );
		DTC_Logger::prune();
		DTC_Jobs::prune();
	}

	public static function next_runs() {
		return array(
			'backup'  => wp_next_scheduled( self::HOOK_BACKUP ),
			'updates' => wp_next_scheduled( self::HOOK_UPDATES ),
			'scan'    => wp_next_scheduled( self::HOOK_SCAN ),
			'monthly' => wp_next_scheduled( self::HOOK_MONTHLY ),
		);
	}
}
