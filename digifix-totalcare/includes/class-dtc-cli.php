<?php
/**
 * WP-CLI commands: wp totalcare <command>
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Backups and restores from the command line.
 */
class DTC_CLI {

	private function wait_for_job( DTC_Job $job ) {
		$last = '';
		while ( true ) {
			DTC_Jobs::tick();
			$job = DTC_Jobs::find( $job->id );
			if ( ! $job ) {
				WP_CLI::error( 'Job disappeared.' );
			}
			if ( $job->message !== $last ) {
				WP_CLI::log( '[' . $job->step . '] ' . $job->message );
				$last = $job->message;
			}
			if ( ! $job->is_active() ) {
				return $job;
			}
			if ( $job->next_run_at > time() ) {
				sleep( min( 5, max( 1, $job->next_run_at - time() ) ) );
			}
		}
	}

	/**
	 * Take a backup now and wait for it to finish.
	 *
	 * ## OPTIONS
	 *
	 * [--full]
	 * : Take a full backup even if an incremental one is due.
	 *
	 * @when after_wp_load
	 */
	public function backup( $args, $assoc ) {
		$job = DTC_Backup_Service::queue( 'manual', ! empty( $assoc['full'] ) );
		if ( ! $job ) {
			WP_CLI::error( 'Could not queue the backup.' );
		}
		WP_CLI::log( 'Backup job #' . $job->id . ' queued.' );
		$job = $this->wait_for_job( $job );
		'completed' === $job->status ? WP_CLI::success( $job->message ) : WP_CLI::error( $job->status . ': ' . $job->message );
	}

	/**
	 * List backups in remote storage.
	 *
	 * ## OPTIONS
	 *
	 * [--folder=<folder>]
	 * : Another site's folder in the same bucket.
	 *
	 * [--rebuild]
	 * : Rebuild the catalog from the manifests.
	 *
	 * [--format=<format>]
	 * : table, json or csv. Default table.
	 *
	 * @when after_wp_load
	 */
	public function list( $args, $assoc ) {
		$remote = DTC_Bk_Remote::from_settings( $assoc['folder'] ?? null );
		if ( is_wp_error( $remote ) ) {
			WP_CLI::error( $remote->get_error_message() );
		}
		$entries = $remote->catalog( ! empty( $assoc['rebuild'] ) );
		if ( is_wp_error( $entries ) ) {
			WP_CLI::error( $entries->get_error_message() );
		}
		$rows = array();
		foreach ( $entries as $e ) {
			$rows[] = array(
				'id'      => $e['id'],
				'date'    => wp_date( 'Y-m-d H:i', $e['created'] ),
				'type'    => $e['type'],
				'trigger' => $e['trigger'],
				'size'    => size_format( $e['size'], 1 ),
				'changed' => $e['changed'] . '/' . $e['files'],
				'pinned'  => $e['pinned'] ? 'yes' : '',
			);
		}
		WP_CLI\Utils\format_items( $assoc['format'] ?? 'table', $rows, array( 'id', 'date', 'type', 'trigger', 'size', 'changed', 'pinned' ) );
	}

	/**
	 * Restore a backup (whole site or parts of it).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id (see wp totalcare list).
	 *
	 * [--scope=<parts>]
	 * : Comma-separated: db,core,plugins,themes,muplugins,content,uploads. Default: all.
	 *
	 * [--paths=<paths>]
	 * : Comma-separated paths to restore only, e.g. wp-content/plugins/woocommerce.
	 *
	 * [--clean]
	 * : Remove core, plugin and theme files that are not in the backup.
	 *
	 * [--htaccess]
	 * : Also restore .htaccess.
	 *
	 * [--folder=<folder>]
	 * : Restore a backup from another site's folder (migration).
	 *
	 * [--yes]
	 * : Do not ask for confirmation.
	 *
	 * @when after_wp_load
	 */
	public function restore( $args, $assoc ) {
		$scope = ! empty( $assoc['scope'] ) ? array_map( 'trim', explode( ',', $assoc['scope'] ) ) : DTC_Restore::ALL_SCOPE;
		WP_CLI::confirm( 'Restore ' . implode( ', ', $scope ) . ' from backup ' . $args[0] . '? Changes made since then will be lost.', $assoc );
		$res = DTC_Restore::begin(
			$args[0],
			0,
			'Restore started from WP-CLI.',
			array(),
			array(
				'scope'    => $scope,
				'paths'    => ! empty( $assoc['paths'] ) ? explode( ',', $assoc['paths'] ) : array(),
				'clean'    => ! empty( $assoc['clean'] ),
				'htaccess' => ! empty( $assoc['htaccess'] ),
				'folder'   => $assoc['folder'] ?? null,
			)
		);
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		$seen = 0;
		while ( true ) {
			sleep( 3 );
			$req = DTC_Restore::request();
			if ( ! $req ) {
				$req = DTC_Storage::read( DTC_Restore::LAST );
			}
			foreach ( array_slice( (array) ( $req['log'] ?? array() ), $seen ) as $line ) {
				WP_CLI::log( $line );
			}
			$seen = count( (array) ( $req['log'] ?? array() ) );
			if ( ! in_array( $req['status'] ?? '', array( 'pending', 'running', 'failing' ), true ) ) {
				break;
			}
			// Loopbacks blocked (e.g. basic auth on staging): drive it from here.
			if ( time() - (int) $req['updated'] > 60 && class_exists( 'DTC_Guardian' ) ) {
				DTC_Guardian::step_now();
			}
		}
		DTC_Restore::reconcile();
		'done' === ( $req['status'] ?? '' ) ? WP_CLI::success( 'Restore finished.' ) : WP_CLI::error( 'Restore failed: ' . ( $req['error'] ?? 'unknown error' ) );
	}

	/**
	 * Download a backup's archives and check every checksum and gzip member.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : Backup id.
	 *
	 * [--folder=<folder>]
	 * : Another site's folder in the same bucket.
	 *
	 * @when after_wp_load
	 */
	public function verify( $args, $assoc ) {
		$remote = DTC_Bk_Remote::from_settings( $assoc['folder'] ?? null );
		if ( is_wp_error( $remote ) ) {
			WP_CLI::error( $remote->get_error_message() );
		}
		$m = $remote->manifest( $args[0] );
		if ( ! is_array( $m ) ) {
			WP_CLI::error( 'Manifest not found.' );
		}
		$tmp     = wp_tempnam( 'dtc-verify' );
		$members = $remote->s3->get( $remote->backup_key( $args[0], 'members.tsv.gz' ) );
		if ( ! is_string( $members ) ) {
			WP_CLI::error( 'members.tsv.gz not found.' );
		}
		$errors = 0;
		$count  = 0;
		foreach ( explode( "\n", gzdecode( $members ) ) as $line ) {
			$r = DTC_Bk_Util::tsv_parse( $line );
			if ( count( $r ) < 7 ) {
				continue;
			}
			list( $object, $n, $off, $len, $sha ) = $r;
			$res = $remote->s3->get_to_file( $remote->backup_key( $args[0], $object ), $tmp, (int) $off, (int) $off + (int) $len - 1 );
			if ( is_wp_error( $res ) || hash_file( 'sha256', $tmp ) !== $sha ) {
				WP_CLI::warning( "$object member $n: checksum mismatch" . ( is_wp_error( $res ) ? ' (' . $res->get_error_message() . ')' : '' ) );
				++$errors;
				continue;
			}
			$raw = @gzdecode( (string) file_get_contents( $tmp ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $raw || strlen( $raw ) !== (int) $r[6] ) {
				WP_CLI::warning( "$object member $n: does not decompress to the recorded size" );
				++$errors;
			}
			++$count;
		}
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$errors ? WP_CLI::error( "$errors problem(s) in $count member(s)." ) : WP_CLI::success( "All $count archive members verified." );
	}

	/**
	 * Delete old backups according to the retention settings.
	 *
	 * @when after_wp_load
	 */
	public function prune( $args, $assoc ) {
		$remote = DTC_Bk_Remote::from_settings();
		if ( is_wp_error( $remote ) ) {
			WP_CLI::error( $remote->get_error_message() );
		}
		$owner = $remote->check_owner();
		if ( is_wp_error( $owner ) ) {
			WP_CLI::error( $owner->get_error_message() );
		}
		$res = DTC_Bk_Retention::run( $remote, array_intersect_key( DTC_Settings::all(), DTC_Bk_Retention::defaults() ) );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( $res->get_error_message() );
		}
		WP_CLI::success( sprintf( '%d kept, %d deleted, %d incomplete removed.', $res['kept'], count( $res['deleted'] ), count( $res['orphans'] ) ) );
	}
}
