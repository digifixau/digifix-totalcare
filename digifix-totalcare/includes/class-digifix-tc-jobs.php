<?php
/**
 * Job engine. Only one job runs at a time; others wait in the queue so a
 * backup, an update run and a scan never overlap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Jobs {

	/** A step that is killed this many times in a row without progress fails. */
	const MAX_KILLED = 5;

	/** @var array<string,object> type => handler with run(DTC_Job) and max_age(). */
	private static $handlers = array();

	/** @var float Absolute time the current tick must stop working. */
	private static $deadline = 0;

	/**
	 * Deadline for long-running steps. Steps check it and yield before it,
	 * so a host that kills PHP after ~30 s does not interrupt them.
	 */
	public static function deadline() {
		return self::$deadline ? self::$deadline : microtime( true ) + DTC_Bk_Util::budget();
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'dtc_jobs';
	}

	public static function register( $type, $handler ) {
		self::$handlers[ $type ] = $handler;
	}

	/**
	 * Queue a job. Returns the existing job if one of the same type is active.
	 *
	 * @return DTC_Job|null
	 */
	public static function create( $type, array $state = array(), $step = 'start' ) {
		global $wpdb;
		foreach ( self::active() as $job ) {
			if ( $job->type === $type ) {
				return $job;
			}
		}
		$now = current_time( 'mysql', true );
		$wpdb->insert(
			self::table(),
			array(
				'type'        => $type,
				'status'      => 'pending',
				'step'        => $step,
				'state'       => wp_json_encode( $state ),
				'message'     => 'Queued',
				'attempts'    => 0,
				'created_at'  => $now,
				'updated_at'  => $now,
				'next_run_at' => time(),
			)
		);
		return self::find( (int) $wpdb->insert_id );
	}

	/** @return DTC_Job|null */
	public static function find( $id ) {
		global $wpdb;
		$table = self::table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table WHERE id = %d", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $row ? new DTC_Job( $row ) : null;
	}

	/** @return DTC_Job[] */
	public static function active() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM $table WHERE status IN ('pending','running') ORDER BY id ASC", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			function ( $row ) {
				return new DTC_Job( $row );
			},
			$rows ?: array()
		);
	}

	/** @return DTC_Job[] */
	public static function recent( $type = '', $limit = 10 ) {
		global $wpdb;
		$table = self::table();
		$sql   = $type
			? $wpdb->prepare( "SELECT * FROM $table WHERE type = %s ORDER BY id DESC LIMIT %d", $type, $limit ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: $wpdb->prepare( "SELECT * FROM $table ORDER BY id DESC LIMIT %d", $limit ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			function ( $row ) {
				return new DTC_Job( $row );
			},
			$wpdb->get_results( $sql, ARRAY_A ) ?: array() // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		);
	}

	public static function cancel( $id ) {
		$job = self::find( $id );
		if ( $job && $job->is_active() ) {
			$job->finish( 'cancelled', 'Cancelled by ' . ( wp_get_current_user()->user_login ?: 'system' ) );
			DTC_Logger::log( $job->type, 'warning', $job->type . '.cancelled', 'Job #' . $job->id . ' cancelled.', array(), $job->id );
			self::cleanup( $job );
		}
	}

	/** Let the handler release what a stopped job left behind (uploads, files). */
	private static function cleanup( DTC_Job $job ) {
		$handler = self::$handlers[ $job->type ] ?? null;
		if ( $handler && method_exists( $handler, 'cleanup' ) ) {
			try {
				$handler->cleanup( $job );
			} catch ( Throwable $e ) {
				DTC_Logger::log( $job->type, 'warning', $job->type . '.cleanup_failed', 'Cleanup after job #' . $job->id . ' failed: ' . $e->getMessage(), array(), $job->id );
			}
		}
	}

	/** Kick the engine immediately through a loopback request. */
	public static function kick() {
		DTC_Storage::loopback( 'dtc_kick' );
	}

	/**
	 * MySQL named lock: atomic, and released automatically when the request
	 * ends, even on a fatal error mid-step.
	 */
	private static function lock_name() {
		global $wpdb;
		return 'dtc_tick_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 16 );
	}

	private static function acquire_lock() {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', self::lock_name() ) );
	}

	private static function release_lock() {
		global $wpdb;
		$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', self::lock_name() ) );
	}

	/**
	 * Advance the head-of-queue job until the request budget is used up.
	 */
	public static function tick() {
		update_option( 'dtc_last_tick', time(), false );

		// A guardian restore finished while TotalCare was deactivated.
		DTC_Restore::reconcile();

		if ( DTC_Restore::in_progress() ) {
			return;
		}
		if ( ! self::acquire_lock() ) {
			return;
		}

		DTC_Bk_Util::extend_time_limit();
		$budget         = DTC_Bk_Util::budget();
		self::$deadline = microtime( true ) + $budget;
		$more           = false;
		try {
			// Only start a step when a useful part of the budget is left.
			while ( microtime( true ) < self::$deadline - min( 8, $budget / 3 ) ) {
				$active = self::active();
				if ( ! $active ) {
					break;
				}
				$job = $active[0];
				if ( $job->next_run_at > time() ) {
					break;
				}
				self::run_step( $job );
				if ( DTC_Restore::in_progress() ) {
					break;
				}
			}
			$active = DTC_Restore::in_progress() ? array() : self::active();
			$more   = $active && $active[0]->next_run_at <= time();
		} finally {
			self::release_lock();
			self::$deadline = 0;
		}

		// Work is waiting: continue in a fresh request instead of waiting
		// for the next cron minute. Low impact mode leaves that pause in.
		if ( $more && empty( DTC_Settings::get( 'low_impact' ) ) ) {
			self::kick();
		}
	}

	private static function run_step( DTC_Job $job ) {
		$handler = self::$handlers[ $job->type ] ?? null;
		if ( ! $handler ) {
			$job->fail( 'No handler registered for job type ' . $job->type );
			return;
		}

		if ( $job->age() > $handler->max_age() ) {
			$job->fail( 'Job exceeded its maximum run time at step "' . $job->step . '".' );
			DTC_Logger::log( $job->type, 'error', $job->type . '.timeout', $job->message, array( 'step' => $job->step ), $job->id );
			DTC_Notifier::notify( $job->type . '.failed', array( 'error' => $job->message ), $job->id );
			self::cleanup( $job );
			return;
		}

		// Count entries into this step. A request killed by the host never
		// returns here to reset the counter.
		$entries = (int) $job->get( '_entries', 0 ) + 1;
		if ( $entries > self::MAX_KILLED ) {
			$msg = 'Step "' . $job->step . '" was stopped by the server ' . self::MAX_KILLED . ' times in a row (PHP time or memory limit). Try a smaller "Work per request" setting.';
			$job->fail( $msg );
			DTC_Logger::log( $job->type, 'error', $job->type . '.failed', $msg, array( 'step' => $job->step ), $job->id );
			DTC_Notifier::notify( $job->type . '.failed', array( 'error' => $msg ), $job->id );
			self::cleanup( $job );
			return;
		}
		$job->set( '_entries', $entries );
		if ( 'pending' === $job->status ) {
			$job->status = 'running';
		}
		if ( ! $job->save() ) {
			return;
		}

		$before = array( $job->step, $job->next_run_at, $job->status );
		try {
			$handler->run( $job );
		} catch ( Throwable $e ) {
			++$job->attempts;
			$msg = sprintf( 'Step "%s" threw %s: %s', $job->step, get_class( $e ), $e->getMessage() );
			$job->set( '_entries', 0 );
			if ( $job->attempts >= 3 ) {
				$job->fail( $msg );
				DTC_Logger::log( $job->type, 'error', $job->type . '.failed', $msg, array( 'step' => $job->step ), $job->id );
				DTC_Notifier::notify( $job->type . '.failed', array( 'error' => $msg ), $job->id );
				self::cleanup( $job );
			} else {
				$job->next_run_at = time() + 60;
				$job->message     = $msg . ' (will retry)';
				$job->save();
			}
			return;
		}

		$job->set( '_entries', 0 );
		// Guard against a handler that neither advanced nor rescheduled.
		if ( $job->is_active() && array( $job->step, $job->next_run_at, $job->status ) === $before ) {
			$job->wait( 60 );
		} elseif ( $job->is_active() ) {
			$job->save();
		}
	}

	public static function prune( $days = 120 ) {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM $table WHERE status NOT IN ('pending','running') AND created_at < %s", gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
