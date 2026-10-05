<?php
/**
 * A persistent, resumable job. Services implement each step; the job engine
 * advances one step at a time from cron or a loopback request.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Job {

	public $id;
	public $type;
	public $status;
	public $step;
	public $state;
	public $message;
	public $attempts;
	public $created_at;
	public $updated_at;
	public $next_run_at;
	public $finished_at;

	public function __construct( array $row ) {
		$this->id          = (int) $row['id'];
		$this->type        = $row['type'];
		$this->status      = $row['status'];
		$this->step        = $row['step'];
		$this->state       = json_decode( (string) $row['state'], true ) ?: array();
		$this->message     = (string) $row['message'];
		$this->attempts    = (int) $row['attempts'];
		$this->created_at  = $row['created_at'];
		$this->updated_at  = $row['updated_at'];
		$this->next_run_at = (int) $row['next_run_at'];
		$this->finished_at = $row['finished_at'];
	}

	public function get( $key, $default = null ) {
		return array_key_exists( $key, $this->state ) ? $this->state[ $key ] : $default;
	}

	public function set( $key, $value ) {
		$this->state[ $key ] = $value;
		return $this;
	}

	/** Move to another step, optionally after a delay in seconds. */
	public function go( $step, $delay = 0, $message = null ) {
		$this->status      = 'running';
		$this->step        = $step;
		$this->attempts    = 0;
		$this->next_run_at = time() + (int) $delay;
		if ( null !== $message ) {
			$this->message = $message;
		}
		return $this->save();
	}

	/** Re-run the current step later (polling). */
	public function wait( $delay, $message = null ) {
		$this->attempts    = 0;
		$this->next_run_at = time() + (int) $delay;
		if ( null !== $message ) {
			$this->message = $message;
		}
		return $this->save();
	}

	public function complete( $message = '' ) {
		return $this->finish( 'completed', $message );
	}

	public function fail( $message ) {
		return $this->finish( 'failed', $message );
	}

	public function finish( $status, $message ) {
		$this->status      = $status;
		$this->message     = $message;
		$this->finished_at = current_time( 'mysql', true );
		return $this->save();
	}

	public function is_active() {
		return in_array( $this->status, array( 'pending', 'running' ), true );
	}

	/** Seconds since the job was created. */
	public function age() {
		return time() - strtotime( $this->created_at . ' UTC' );
	}

	public function save() {
		global $wpdb;
		$this->updated_at = current_time( 'mysql', true );
		return false !== $wpdb->update(
			DTC_Jobs::table(),
			array(
				'status'      => $this->status,
				'step'        => $this->step,
				'state'       => wp_json_encode( $this->state ),
				'message'     => $this->message,
				'attempts'    => $this->attempts,
				'updated_at'  => $this->updated_at,
				'next_run_at' => $this->next_run_at,
				'finished_at' => $this->finished_at,
			),
			array( 'id' => $this->id )
		);
	}
}
