<?php
/**
 * Job engine. Only one job runs at a time; others wait in the queue so a
 * backup, an update run and a scan never overlap.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// TotalCare's own job and activity-log tables. Job state changes between requests, so these
// queries must not be served from the object cache.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Digifix_TC_Jobs {

	/** A step that is killed this many times in a row without progress fails. */
	const MAX_KILLED = 5;

	/** @var array<string,object> type => handler with run(Digifix_TC_Job) and max_age(). */
	private static $handlers = array();

	/** @var float Absolute time the current tick must stop working. */
	private static $deadline = 0;

	/**
	 * Deadline for long-running steps. Steps check it and yield before it,
	 * so a host that kills PHP after ~30 s does not interrupt them.
	 */
	public static function deadline() {
		return self::$deadline ? self::$deadline : microtime( true ) + Digifix_TC_Bk_Util::budget();
	}

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'digifix_tc_jobs';
	}

	public static function register( $type, $handler ) {
		self::$handlers[ $type ] = $handler;
	}

	/**
	 * Queue a job. Returns the existing job if one of the same type is active.
	 *
	 * @return Digifix_TC_Job|null
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

	/** @return Digifix_TC_Job|null */
	public static function find( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return $row ? new Digifix_TC_Job( $row ) : null;
	}

	/** @return Digifix_TC_Job[] */
	public static function active() {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE status IN ('pending','running') ORDER BY id ASC", self::table() ), ARRAY_A );
		return array_map(
			function ( $row ) {
				return new Digifix_TC_Job( $row );
			},
			$rows ?: array()
		);
	}

	/** @return Digifix_TC_Job[] */
	public static function recent( $type = '', $limit = 10 ) {
		global $wpdb;
		$rows = $type
			? $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE type = %s ORDER BY id DESC LIMIT %d', self::table(), $type, $limit ), ARRAY_A )
			: $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', self::table(), $limit ), ARRAY_A );
		return array_map(
			function ( $row ) {
				return new Digifix_TC_Job( $row );
			},
			$rows ?: array()
		);
	}

	public static function cancel( $id ) {
		$job = self::find( $id );
		if ( $job && $job->is_active() ) {
			$job->finish( 'cancelled', 'Cancelled by ' . ( wp_get_current_user()->user_login ?: 'system' ) );
			Digifix_TC_Logger::log( $job->type, 'warning', $job->type . '.cancelled', 'Job #' . $job->id . ' cancelled.', array(), $job->id );
			self::cleanup( $job );
		}
	}

	/** Let the handler release what a stopped job left behind (uploads, files). */
	private static function cleanup( Digifix_TC_Job $job ) {
		$handler = self::$handlers[ $job->type ] ?? null;
		if ( $handler && method_exists( $handler, 'cleanup' ) ) {
			try {
				$handler->cleanup( $job );
			} catch ( Throwable $e ) {
				Digifix_TC_Logger::log( $job->type, 'warning', $job->type . '.cleanup_failed', 'Cleanup after job #' . $job->id . ' failed: ' . wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ), array(), $job->id );
			}
		}
	}

	/** Kick the engine immediately through a loopback request. */
	public static function kick() {
		Digifix_TC_Storage::loopback( 'digifix_tc_kick' );
	}

	/**
	 * MySQL named lock: atomic, and released automatically when the request
	 * ends, even on a fatal error mid-step.
	 */
	private static function lock_name() {
		global $wpdb;
		return 'digifix_tc_tick_' . substr( md5( DB_NAME . $wpdb->prefix ), 0, 16 );
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
		update_option( 'digifix_tc_last_tick', time(), false );

		// A guardian restore finished while TotalCare was deactivated.
		Digifix_TC_Restore::reconcile();

		if ( Digifix_TC_Restore::in_progress() ) {
			return;
		}
		if ( ! self::acquire_lock() ) {
			return;
		}

		Digifix_TC_Bk_Util::extend_time_limit();
		$budget         = Digifix_TC_Bk_Util::budget();
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
				if ( Digifix_TC_Restore::in_progress() ) {
					break;
				}
			}
			$active = Digifix_TC_Restore::in_progress() ? array() : self::active();
			$more   = $active && $active[0]->next_run_at <= time();
		} finally {
			self::release_lock();
			self::$deadline = 0;
		}

		// Work is waiting: continue in a fresh request instead of waiting
		// for the next cron minute. Low impact mode leaves that pause in.
		if ( $more && empty( Digifix_TC_Settings::get( 'low_impact' ) ) ) {
			self::kick();
		}
	}

	private static function run_step( Digifix_TC_Job $job ) {
		$handler = self::$handlers[ $job->type ] ?? null;
		if ( ! $handler ) {
			$job->fail( 'No handler registered for job type ' . $job->type );
			return;
		}

		if ( $job->age() > $handler->max_age() ) {
			$job->fail( 'Job exceeded its maximum run time at step "' . $job->step . '".' );
			Digifix_TC_Logger::log( $job->type, 'error', $job->type . '.timeout', $job->message, array( 'step' => $job->step ), $job->id );
			Digifix_TC_Notifier::notify( $job->type . '.failed', array( 'error' => $job->message ), $job->id );
			self::cleanup( $job );
			return;
		}

		// Count entries into this step. A request killed by the host never
		// returns here to reset the counter.
		$entries = (int) $job->get( '_entries', 0 ) + 1;
		if ( $entries > self::MAX_KILLED ) {
			$msg = 'Step "' . $job->step . '" was stopped by the server ' . self::MAX_KILLED . ' times in a row (PHP time or memory limit). Try a smaller "Work per request" setting.';
			$job->fail( $msg );
			Digifix_TC_Logger::log( $job->type, 'error', $job->type . '.failed', $msg, array( 'step' => $job->step ), $job->id );
			Digifix_TC_Notifier::notify( $job->type . '.failed', array( 'error' => $msg ), $job->id );
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
			$msg = sprintf( 'Step "%s" threw %s: %s', $job->step, get_class( $e ), wp_specialchars_decode( $e->getMessage(), ENT_QUOTES ) );
			$job->set( '_entries', 0 );
			if ( $job->attempts >= 3 ) {
				$job->fail( $msg );
				Digifix_TC_Logger::log( $job->type, 'error', $job->type . '.failed', $msg, array( 'step' => $job->step ), $job->id );
				Digifix_TC_Notifier::notify( $job->type . '.failed', array( 'error' => $msg ), $job->id );
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
		$wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE status NOT IN ('pending','running') AND created_at < %s", self::table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
