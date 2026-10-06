<?php
/**
 * Weekly safe-update pipeline.
 *
 *  preflight -> backup_start -> backup_wait -> baseline
 *  -> next_item <-> item_check   (one plugin/theme at a time, snapshot rollback)
 *  -> core_update -> core_check
 *  -> verify -> finish
 *  Any unrecoverable failure -> full_restore -> restoring -> post_restore
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Update_Service {

	const SKIP_OPTION = 'dtc_update_skip';

	public static function schedule_run() {
		self::queue( 'schedule' );
	}

	public static function queue( $trigger = 'manual' ) {
		$job = DTC_Jobs::create( 'update', array( 'trigger' => $trigger ), 'preflight' );
		DTC_Jobs::kick();
		return $job;
	}

	public function max_age() {
		return 12 * HOUR_IN_SECONDS;
	}

	public function run( DTC_Job $job ) {
		$method = 'step_' . $job->step;
		if ( ! method_exists( $this, $method ) ) {
			$job->fail( 'Unknown step ' . $job->step );
			return;
		}
		$this->$method( $job );
	}

	/* ---------------------------------------------------------------------
	 * Steps
	 * ------------------------------------------------------------------ */

	private function step_preflight( DTC_Job $job ) {
		self::load_wp_admin();

		$problems = array();
		if ( ! DTC_WPvivid::is_available() || ! DTC_WPvivid::get_remote() ) {
			$problems[] = 'WPvivid with an S3 remote is required so a backup can be taken first.';
		}
		if ( ! DTC_Installer::guardian_installed() ) {
			$problems[] = 'The guardian mu-plugin is not installed.';
		}
		if ( 'direct' !== get_filesystem_method() ) {
			$problems[] = 'WordPress cannot write files directly (filesystem method is "' . get_filesystem_method() . '").';
		}
		if ( ! wp_is_file_mod_allowed( 'automatic_updater' ) ) {
			$problems[] = 'File modifications are disabled (DISALLOW_FILE_MODS).';
		}
		$free = function_exists( 'disk_free_space' ) ? @disk_free_space( WP_CONTENT_DIR ) : false; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( false !== $free && $free < apply_filters( 'dtc_min_free_disk', 500 * MB_IN_BYTES ) ) {
			$problems[] = 'Less than ' . size_format( apply_filters( 'dtc_min_free_disk', 500 * MB_IN_BYTES ) ) . ' of free disk space.';
		}
		if ( $problems ) {
			return $this->abort( $job, 'Updates skipped: ' . implode( ' ', $problems ) );
		}

		$queue   = $this->build_queue();
		$skipped = array_values( array_filter( $queue, function ( $i ) {
			return 'skipped' === $i['status'];
		} ) );
		$pending = array_values( array_filter( $queue, function ( $i ) {
			return 'pending' === $i['status'];
		} ) );
		$job->set( 'queue', $queue );

		if ( ! $pending ) {
			$msg = 'No updates available' . ( $skipped ? ' (' . count( $skipped ) . ' skipped)' : '' ) . '.';
			DTC_Logger::log( 'update', 'info', 'update.none', $msg, array( 'queue' => $queue ), $job->id );
			$job->complete( $msg );
			DTC_Notifier::notify( 'update.completed', $this->summary( $job ), $job->id );
			return;
		}

		DTC_Logger::log( 'update', 'info', 'update.started', count( $pending ) . ' update(s) found; taking a backup first.', array( 'queue' => $queue ), $job->id );
		$job->go( 'backup_start', 0, count( $pending ) . ' update(s) queued.' );
	}

	private function step_backup_start( DTC_Job $job ) {
		$task_id = DTC_WPvivid::start_backup( true );
		if ( is_wp_error( $task_id ) ) {
			if ( 'dtc_wpvivid_busy' === $task_id->get_error_code() && $job->age() < 2 * HOUR_IN_SECONDS ) {
				$job->wait( 5 * MINUTE_IN_SECONDS, 'Waiting for a running WPvivid backup to finish.' );
				return;
			}
			return $this->abort( $job, 'Updates skipped because the pre-update backup could not start: ' . $task_id->get_error_message() );
		}
		$job->set( 'backup_id', $task_id )->set( 'backup_started', time() );
		$job->go( 'backup_wait', 60, 'Pre-update backup running.' );
	}

	private function step_backup_wait( DTC_Job $job ) {
		$status = DTC_WPvivid::backup_status( $job->get( 'backup_id' ) );
		if ( 'failed' === $status['status'] ) {
			return $this->abort( $job, 'Updates skipped because the pre-update backup failed: ' . $status['error'] );
		}
		if ( 'completed' !== $status['status'] ) {
			$timeout = (int) DTC_Settings::get( 'backup_timeout_hours' ) * HOUR_IN_SECONDS;
			if ( time() - (int) $job->get( 'backup_started' ) > $timeout ) {
				return $this->abort( $job, 'Updates skipped because the pre-update backup did not finish within ' . DTC_Settings::get( 'backup_timeout_hours' ) . ' hours. Last WPvivid status: ' . ( $status['progress'] ?: $status['status'] ) . '. See WPvivid → Logs for details.' );
			}
			$job->wait( 60, 'Pre-update backup: ' . ( $status['progress'] ?: $status['status'] ) );
			return;
		}

		$summary = DTC_WPvivid::summarize( $job->get( 'backup_id' ) );
		DTC_Logger::log( 'backup', 'success', 'backup.completed', 'Pre-update backup completed (' . ( $summary['size_human'] ?? '?' ) . ').', $summary + array( 'pre_update' => true ), $job->id );
		$job->go( 'baseline' );
	}

	private function step_baseline( DTC_Job $job ) {
		$baseline = DTC_Health_Check::baseline();
		$problems = DTC_Health_Check::baseline_problems( $baseline );
		if ( $problems ) {
			return $this->abort( $job, 'Updates skipped because the site is already failing health checks: ' . implode( '; ', $problems ) );
		}
		$job->set( 'baseline', $baseline );
		$job->go( 'next_item', 0, 'Applying updates.' );
	}

	private function step_next_item( DTC_Job $job ) {
		self::load_wp_admin();
		$queue = (array) $job->get( 'queue' );

		// Resume an item interrupted mid-update (e.g. the request died).
		foreach ( $queue as $idx => $item ) {
			if ( 'updating' === $item['status'] ) {
				$job->set( 'current', $idx );
				$job->go( 'item_check' );
				return;
			}
		}

		$idx = null;
		foreach ( $queue as $i => $item ) {
			if ( 'pending' === $item['status'] && 'core' !== $item['kind'] ) {
				$idx = $i;
				break;
			}
		}
		if ( null === $idx ) {
			foreach ( $queue as $i => $item ) {
				if ( 'pending' === $item['status'] && 'core' === $item['kind'] ) {
					$job->set( 'current', $i );
					$job->go( 'core_update' );
					return;
				}
			}
			$job->go( 'verify', 5 );
			return;
		}

		$item = $queue[ $idx ];
		$snap = DTC_Snapshot::create( $job->id, $item['kind'], $item['id'], $item['from'] );
		if ( is_wp_error( $snap ) ) {
			$queue[ $idx ]['status'] = 'failed';
			$queue[ $idx ]['reason'] = 'Not updated: snapshot failed (' . $snap->get_error_message() . ').';
			$job->set( 'queue', $queue )->go( 'next_item' );
			DTC_Logger::log( 'update', 'warning', 'update.item_failed', $item['name'] . ': ' . $queue[ $idx ]['reason'], $queue[ $idx ], $job->id );
			return;
		}

		$queue[ $idx ]['snapshot'] = $snap;
		$queue[ $idx ]['started']  = time();
		$queue[ $idx ]['status']   = 'updating';
		$job->set( 'queue', $queue )->set( 'current', $idx );
		$job->message = 'Updating ' . $item['name'] . '.';
		$job->save();
		DTC_Snapshot::set_inflight( $snap, $job->id );

		$result = $this->upgrade_item( $item );

		if ( is_wp_error( $result ) ) {
			// The upgrader failed before or during install; make sure the old
			// version is in place.
			$restored = DTC_Snapshot::restore( $snap );
			DTC_Snapshot::clear_inflight();
			$this->ensure_active_state( $item );
			$job->set( 'fatal_since', time() );
			$queue[ $idx ]['status'] = 'failed';
			$queue[ $idx ]['reason'] = $result->get_error_message() . ( is_wp_error( $restored ) ? ' Snapshot restore also failed: ' . $restored->get_error_message() : '' );
			$job->set( 'queue', $queue );
			DTC_Logger::log( 'update', 'warning', 'update.item_failed', $item['name'] . ' could not be updated: ' . $queue[ $idx ]['reason'], $queue[ $idx ], $job->id );
			$job->go( is_wp_error( $restored ) ? 'item_check' : 'next_item', 5 );
			return;
		}

		$job->go( 'item_check', 5, 'Checking site after updating ' . $item['name'] . '.' );
	}

	private function step_item_check( DTC_Job $job ) {
		self::load_wp_admin();
		$queue = (array) $job->get( 'queue' );
		$idx   = $job->get( 'current' );
		$item  = $queue[ $idx ];

		$inflight        = DTC_Snapshot::get_inflight();
		$guardian_rolled = $inflight && 'rolled_back' === ( $inflight['status'] ?? '' );
		$this->ensure_active_state( $item );
		$check     = DTC_Health_Check::check( $job->get( 'baseline' ), (int) $item['started'] - 1 );
		$installed = $this->installed_version( $item );
		if ( 'failed' !== $item['status'] && ( '' === $installed || version_compare( $installed, $item['from'], '<=' ) ) ) {
			// Interrupted or partial install: files missing or still the old version.
			$check['passed'] = false;
			$check['hard'][] = sprintf( 'Installed version is "%s", expected %s (incomplete update).', $installed, $item['to'] );
		}

		if ( $check['passed'] && ! $guardian_rolled && 'failed' !== $item['status'] ) {
			DTC_Snapshot::clear_inflight();
			$job->set( 'fatal_since', time() );
			$queue[ $idx ]['status']   = 'updated';
			$queue[ $idx ]['to']       = $installed;
			$queue[ $idx ]['warnings'] = $check['soft'];
			$job->set( 'queue', $queue );
			DTC_Logger::log( 'update', $check['soft'] ? 'warning' : 'success', 'update.item_updated', sprintf( '%s updated %s → %s.', $item['name'], $item['from'], $queue[ $idx ]['to'] ), self::public_item( $queue[ $idx ] ), $job->id );
			$job->go( 'next_item' );
			return;
		}

		// Roll back this item.
		$reasons = $check['hard'];
		if ( $guardian_rolled ) {
			array_unshift( $reasons, 'Guardian rolled back after a PHP fatal error: ' . ( $inflight['fatal']['message'] ?? '' ) );
		}
		// Restore even after a guardian rollback: the upgrader may have kept
		// writing into the folder after the guardian swapped it.
		if ( file_exists( $item['snapshot']['snapshot'] ) ) {
			$restored = DTC_Snapshot::restore( $item['snapshot'] );
			if ( is_wp_error( $restored ) ) {
				$reasons[] = 'Snapshot restore failed: ' . $restored->get_error_message();
			}
		}
		if ( $guardian_rolled && ! empty( $inflight['failed_copy'] ) ) {
			DTC_Snapshot::rrmdir( $inflight['failed_copy'] );
		}
		DTC_Snapshot::clear_inflight();
		$this->ensure_active_state( $item );
		$job->set( 'fatal_since', time() );

		$queue[ $idx ]['status'] = 'rolled_back';
		$queue[ $idx ]['reason'] = implode( ' ', array_slice( $reasons, 0, 5 ) );
		$job->set( 'queue', $queue );
		$this->add_skip( $item );

		DTC_Logger::log( 'update', 'error', 'update.rolled_back', sprintf( '%s %s broke the site and was rolled back to %s.', $item['name'], $item['to'], $item['from'] ), self::public_item( $queue[ $idx ] ), $job->id );
		DTC_Notifier::notify( 'update.rolled_back', self::public_item( $queue[ $idx ] ), $job->id );

		$recheck = DTC_Health_Check::check( $job->get( 'baseline' ), time() );
		if ( ! $recheck['passed'] ) {
			$job->set( 'restore_reason', 'Site still failing after rolling back ' . $item['name'] . ': ' . implode( '; ', array_slice( $recheck['hard'], 0, 3 ) ) );
			$job->go( 'full_restore' );
			return;
		}
		$job->go( 'next_item' );
	}

	private function step_core_update( DTC_Job $job ) {
		// Do not require update-core.php here: Core_Upgrader loads the NEW
		// version's copy from that path and require_once would skip it.
		self::load_wp_admin();
		$queue = (array) $job->get( 'queue' );
		$idx   = $job->get( 'current' );
		$item  = $queue[ $idx ];

		$offer = $this->find_core_offer( $item['to'] );
		if ( ! $offer ) {
			$queue[ $idx ]['status'] = 'failed';
			$queue[ $idx ]['reason'] = 'The core update offer is no longer available.';
			$job->set( 'queue', $queue )->go( 'verify', 5 );
			return;
		}

		$queue[ $idx ]['status']  = 'updating';
		$queue[ $idx ]['started'] = time();
		$job->set( 'queue', $queue );
		$job->message = 'Updating WordPress core to ' . $item['to'] . '.';
		$job->save();

		$upgrader = new Core_Upgrader( new Automatic_Upgrader_Skin() );
		$result   = $upgrader->upgrade( $offer, array( 'attempt_rollback' => true ) );

		if ( is_wp_error( $result ) || ! $result ) {
			$queue[ $idx ]['status'] = 'failed';
			$queue[ $idx ]['reason'] = is_wp_error( $result ) ? $result->get_error_message() : 'Core upgrader returned no result.';
			$job->set( 'queue', $queue );
			DTC_Logger::log( 'update', 'warning', 'update.item_failed', 'WordPress core update failed: ' . $queue[ $idx ]['reason'], self::public_item( $queue[ $idx ] ), $job->id );
		}
		$job->go( 'core_check', 10, 'Checking site after core update.' );
	}

	private function step_core_check( DTC_Job $job ) {
		$queue = (array) $job->get( 'queue' );
		$idx   = $job->get( 'current' );
		$check = DTC_Health_Check::check( $job->get( 'baseline' ), (int) $queue[ $idx ]['started'] - 1 );

		if ( 'updating' === $queue[ $idx ]['status'] ) {
			$queue[ $idx ]['status']   = $check['passed'] ? 'updated' : 'rolled_back';
			$queue[ $idx ]['to']       = $this->fresh_wp_version() ?: $queue[ $idx ]['to'];
			$queue[ $idx ]['warnings'] = $check['soft'];
			$job->set( 'queue', $queue );
		}

		$job->set( 'fatal_since', time() );
		if ( ! $check['passed'] ) {
			$queue[ $idx ]['reason'] = implode( ' ', array_slice( $check['hard'], 0, 5 ) );
			$job->set( 'queue', $queue );
			$this->add_skip( $queue[ $idx ] );
			$job->set( 'restore_reason', 'Site failing after WordPress core update: ' . implode( '; ', array_slice( $check['hard'], 0, 3 ) ) );
			$job->go( 'full_restore' );
			return;
		}
		if ( 'updated' === $queue[ $idx ]['status'] ) {
			DTC_Logger::log( 'update', 'success', 'update.item_updated', sprintf( 'WordPress core updated %s → %s.', $queue[ $idx ]['from'], $queue[ $idx ]['to'] ), self::public_item( $queue[ $idx ] ), $job->id );
		}
		$job->go( 'verify', 5 );
	}

	private function step_verify( DTC_Job $job ) {
		// Only fatals after the last item was resolved count here; fatals that
		// caused a per-item rollback were already handled.
		$check = DTC_Health_Check::check( $job->get( 'baseline' ), (int) $job->get( 'fatal_since', time() ) );
		$job->set( 'final_check', array( 'passed' => $check['passed'], 'hard' => $check['hard'], 'soft' => $check['soft'] ) );
		if ( ! $check['passed'] ) {
			$job->set( 'restore_reason', 'Final health check failed: ' . implode( '; ', array_slice( $check['hard'], 0, 3 ) ) );
			$job->go( 'full_restore' );
			return;
		}
		$job->go( 'finish' );
	}

	private function step_full_restore( DTC_Job $job ) {
		$reason = (string) $job->get( 'restore_reason', 'Health check failed.' );
		DTC_Logger::log( 'update', 'error', 'update.restore_needed', $reason . ' Restoring the pre-update backup.', array(), $job->id );

		$res = DTC_Restore::begin( $job->get( 'backup_id' ), $job->id, $reason, $job->state );
		if ( is_wp_error( $res ) ) {
			$msg = $reason . ' Automatic restore could not start: ' . $res->get_error_message();
			$job->fail( $msg );
			DTC_Logger::log( 'restore', 'error', 'restore.failed', $msg, array(), $job->id );
			DTC_Notifier::notify( 'update.failed', array_merge( $this->summary( $job ), array( 'error' => $msg ) ), $job->id );
			return;
		}
		$job->go( 'restoring', 60, 'Restoring pre-update backup.' );
	}

	private function step_restoring( DTC_Job $job ) {
		if ( ! DTC_Restore::request() ) {
			$job->fail( 'The restore request disappeared before the guardian reported a result.' );
			DTC_Notifier::notify( 'update.failed', array_merge( $this->summary( $job ), array( 'error' => $job->message ) ), $job->id );
			return;
		}
		$job->wait( 60 );
	}

	private function step_post_restore( DTC_Job $job ) {
		$result = (array) $job->get( 'restore_result' );
		$queue  = (array) $job->get( 'queue' );

		// Options were rewound by the restore: re-add skips for everything
		// this run changed so next week's run does not repeat the failure.
		foreach ( $queue as $i => $item ) {
			if ( in_array( $item['status'], array( 'updated', 'rolled_back' ), true ) ) {
				$this->add_skip( $item );
				if ( 'updated' === $item['status'] ) {
					$queue[ $i ]['status'] = 'reverted';
				}
			}
		}
		$job->set( 'queue', $queue );

		if ( empty( $result['ok'] ) ) {
			$msg = 'Full restore failed: ' . ( $result['error'] ?? 'unknown error' ) . '. Manual attention required.';
			$job->fail( $msg );
			DTC_Notifier::notify( 'restore.failed', array_merge( $this->summary( $job ), $result, array( 'error' => $msg ) ), $job->id );
			return;
		}

		$check = DTC_Health_Check::check( $job->get( 'baseline' ), time() );
		$job->set( 'final_check', array( 'passed' => $check['passed'], 'hard' => $check['hard'], 'soft' => $check['soft'] ) );
		$msg = 'Updates were reverted by restoring the pre-update backup. ' . ( $check['passed'] ? 'Site is healthy again.' : 'Site is STILL failing health checks.' );
		DTC_Logger::log( 'update', $check['passed'] ? 'warning' : 'error', 'update.restored', $msg, $this->summary( $job ), $job->id );
		$job->finish( 'failed', $msg );
		DTC_Notifier::notify( 'update.restored', array_merge( $this->summary( $job ), array( 'restore' => $result, 'health_passed' => $check['passed'], 'health' => $check['hard'] ) ), $job->id );
	}

	private function step_finish( DTC_Job $job ) {
		DTC_Snapshot::cleanup( (int) DTC_Settings::get( 'snapshot_keep_days' ) );
		$summary = $this->summary( $job );
		$msg     = sprintf( 'Update run finished: %d updated, %d rolled back, %d failed, %d skipped.', $summary['counts']['updated'], $summary['counts']['rolled_back'], $summary['counts']['failed'], $summary['counts']['skipped'] );
		DTC_Logger::log( 'update', $summary['counts']['rolled_back'] || $summary['counts']['failed'] ? 'warning' : 'success', 'update.completed', $msg, $summary, $job->id );
		$job->complete( $msg );
		DTC_Notifier::notify( 'update.completed', $summary, $job->id );
	}

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------ */

	private function abort( DTC_Job $job, $message ) {
		$job->fail( $message );
		DTC_Logger::log( 'update', 'error', 'update.failed', $message, array(), $job->id );
		DTC_Notifier::notify( 'update.failed', array_merge( $this->summary( $job ), array( 'error' => $message ) ), $job->id );
	}

	public static function load_wp_admin() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/misc.php';
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/theme.php';
		require_once ABSPATH . 'wp-admin/includes/update.php';
		require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
	}

	private function build_queue() {
		$s     = DTC_Settings::all();
		$skip  = get_option( self::SKIP_OPTION, array() );
		$queue = array();

		if ( $s['update_plugins'] ) {
			delete_site_transient( 'update_plugins' );
			wp_update_plugins();
			$updates = get_site_transient( 'update_plugins' );
			$plugins = get_plugins();
			foreach ( (array) ( $updates->response ?? array() ) as $file => $info ) {
				$item = array(
					'kind'       => 'plugin',
					'id'         => $file,
					'name'       => $plugins[ $file ]['Name'] ?? $file,
					'from'       => $plugins[ $file ]['Version'] ?? '',
					'to'         => $info->new_version ?? '',
					'status'     => 'pending',
					'was_active' => is_plugin_active( $file ),
				);
				$queue[] = $this->apply_skip_rules( $item, in_array( $file, (array) $s['excluded_plugins'], true ) || DTC_BASENAME === $file, empty( $info->package ), $skip );
			}
		}

		if ( $s['update_themes'] ) {
			delete_site_transient( 'update_themes' );
			wp_update_themes();
			$updates = get_site_transient( 'update_themes' );
			foreach ( (array) ( $updates->response ?? array() ) as $stylesheet => $info ) {
				$theme   = wp_get_theme( $stylesheet );
				$item    = array(
					'kind'   => 'theme',
					'id'     => $stylesheet,
					'name'   => $theme->get( 'Name' ) ?: $stylesheet,
					'from'   => $theme->get( 'Version' ),
					'to'     => $info['new_version'] ?? '',
					'status' => 'pending',
				);
				$queue[] = $this->apply_skip_rules( $item, in_array( $stylesheet, (array) $s['excluded_themes'], true ), empty( $info['package'] ), $skip );
			}
		}

		if ( 'none' !== $s['update_core'] ) {
			wp_version_check( array(), true );
			$offer = $this->find_core_offer( null, 'minor' === $s['update_core'] );
			if ( ! $offer && 'minor' === $s['update_core'] ) {
				$offer = $this->find_core_offer(); // Only to report the skipped major.
			}
			if ( $offer ) {
				$current = get_bloginfo( 'version' );
				$major   = self::branch( $offer->current ) !== self::branch( $current );
				$item    = array(
					'kind'   => 'core',
					'id'     => 'wordpress',
					'name'   => 'WordPress core',
					'from'   => $current,
					'to'     => $offer->current,
					'status' => 'pending',
				);
				$item    = $this->apply_skip_rules( $item, false, false, $skip );
				if ( $major && 'minor' === $s['update_core'] && 'pending' === $item['status'] ) {
					$item['status'] = 'skipped';
					$item['reason'] = 'Major version; core updates are limited to minor releases.';
				}
				$queue[] = $item;
			}
		}
		return $queue;
	}

	private function apply_skip_rules( array $item, $excluded, $no_package, array $skip ) {
		$key = $item['kind'] . ':' . $item['id'];
		if ( $excluded ) {
			$item['status'] = 'skipped';
			$item['reason'] = 'Excluded in TotalCare settings.';
		} elseif ( $no_package ) {
			$item['status'] = 'skipped';
			$item['reason'] = 'No download package (licence may be missing).';
		} elseif ( isset( $skip[ $key ] ) && $skip[ $key ] === $item['to'] ) {
			$item['status'] = 'skipped';
			$item['reason'] = 'Version ' . $item['to'] . ' previously broke the site; waiting for a newer release.';
		}
		return $item;
	}

	private function add_skip( array $item ) {
		$skip = get_option( self::SKIP_OPTION, array() );
		$skip[ $item['kind'] . ':' . $item['id'] ] = $item['to'];
		update_option( self::SKIP_OPTION, $skip, false );
	}

	public static function branch( $version ) {
		return implode( '.', array_slice( explode( '.', (string) $version ), 0, 2 ) );
	}

	/**
	 * @param string|null $version     Exact version wanted, or null for the best offer.
	 * @param bool        $same_branch Only offers on the installed branch (minor releases).
	 */
	private function find_core_offer( $version = null, $same_branch = false ) {
		$offers  = get_core_updates( array( 'dismissed' => true ) );
		$current = get_bloginfo( 'version' );
		$best    = null;
		foreach ( (array) $offers as $offer ) {
			if ( ! is_object( $offer ) || ! in_array( $offer->response ?? '', array( 'upgrade', 'autoupdate' ), true ) ) {
				continue;
			}
			if ( null !== $version ) {
				if ( $offer->current === $version ) {
					return $offer;
				}
				continue;
			}
			if ( $same_branch && self::branch( $offer->current ) !== self::branch( $current ) ) {
				continue;
			}
			if ( ! $best || version_compare( $offer->current, $best->current, '>' ) ) {
				$best = $offer;
			}
		}
		return $best;
	}

	/** Read the version from disk since the running process has the old one loaded. */
	private function fresh_wp_version() {
		$wp_version = '';
		include ABSPATH . WPINC . '/version.php';
		return $wp_version;
	}

	/**
	 * @return true|WP_Error
	 */
	private function upgrade_item( array $item ) {
		$skin = new Automatic_Upgrader_Skin();

		if ( 'plugin' === $item['kind'] ) {
			$this->ensure_update_offer( 'plugin', $item['id'] );
			$upgrader = new Plugin_Upgrader( $skin );
			$result   = $upgrader->upgrade( $item['id'], array( 'clear_update_cache' => false ) );
		} else {
			$this->ensure_update_offer( 'theme', $item['id'] );
			$upgrader = new Theme_Upgrader( $skin );
			$result   = $upgrader->upgrade( $item['id'], array( 'clear_update_cache' => false ) );
		}

		// Interactive (non-cron) upgrades deactivate the plugin and rely on the
		// browser to reactivate it. Restore the active state without loading
		// the new code into this process.
		$this->ensure_active_state( $item );

		if ( true === $result ) {
			return true;
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$messages = array_filter( array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() ) );
		$errors   = method_exists( $skin, 'get_errors' ) ? $skin->get_errors() : null;
		if ( is_wp_error( $errors ) && $errors->has_errors() ) {
			return $errors;
		}
		return new WP_Error( 'dtc_upgrade', 'Upgrader did not complete. ' . implode( ' ', array_slice( $messages, -3 ) ) );
	}

	/** Re-fetch update data if the transient no longer offers this item. */
	private function ensure_update_offer( $kind, $id ) {
		if ( 'plugin' === $kind ) {
			$current = get_site_transient( 'update_plugins' );
			if ( ! isset( $current->response[ $id ] ) ) {
				wp_update_plugins();
			}
			return;
		}
		$current = get_site_transient( 'update_themes' );
		if ( ! isset( $current->response[ $id ] ) ) {
			wp_update_themes();
		}
	}

	private function ensure_active_state( array $item ) {
		if ( 'plugin' !== $item['kind'] || empty( $item['was_active'] ) ) {
			return;
		}
		$active = (array) get_option( 'active_plugins', array() );
		if ( ! in_array( $item['id'], $active, true ) && file_exists( WP_PLUGIN_DIR . '/' . $item['id'] ) ) {
			$active[] = $item['id'];
			sort( $active );
			update_option( 'active_plugins', $active );
		}
	}

	private function installed_version( array $item ) {
		if ( 'plugin' === $item['kind'] ) {
			wp_clean_plugins_cache( false );
			$file = WP_PLUGIN_DIR . '/' . $item['id'];
			if ( file_exists( $file ) ) {
				$data = get_plugin_data( $file, false, false );
				return $data['Version'] ?? '';
			}
			return '';
		}
		$theme = wp_get_theme( $item['id'] );
		$theme->cache_delete();
		$data = get_file_data( $theme->get_stylesheet_directory() . '/style.css', array( 'Version' => 'Version' ) );
		return $data['Version'] ?? '';
	}

	/** Item without internal fields, for logs and notifications. */
	public static function public_item( array $item ) {
		return array_intersect_key( $item, array_flip( array( 'kind', 'id', 'name', 'from', 'to', 'status', 'reason', 'warnings' ) ) );
	}

	public function summary( DTC_Job $job ) {
		$queue  = array_map( array( __CLASS__, 'public_item' ), (array) $job->get( 'queue', array() ) );
		$counts = array(
			'updated'     => 0,
			'rolled_back' => 0,
			'failed'      => 0,
			'skipped'     => 0,
			'reverted'    => 0,
			'pending'     => 0,
		);
		foreach ( $queue as $item ) {
			$key = isset( $counts[ $item['status'] ] ) ? $item['status'] : 'pending';
			++$counts[ $key ];
		}
		return array(
			'items'     => $queue,
			'counts'    => $counts,
			'backup_id' => $job->get( 'backup_id' ),
			'trigger'   => $job->get( 'trigger' ),
		);
	}
}
