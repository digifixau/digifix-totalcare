<?php
/**
 * Dashboard view. Included from DTC_Admin::page_dashboard() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dtc_wpvivid   = DTC_WPvivid::status();
$dtc_wordfence = DTC_Wordfence::status();
$dtc_last_tick = (int) get_option( 'dtc_last_tick' );
$dtc_next      = DTC_Scheduler::next_runs();
$dtc_active    = DTC_Jobs::active();

// Most recent finished backup (completed or failed).
$dtc_last_backup = current(
	array_filter(
		DTC_Logger::query( array( 'type' => 'backup', 'per_page' => 20 ) )['rows'],
		function ( $e ) {
			return in_array( $e['code'], array( 'backup.completed', 'backup.failed' ), true );
		}
	)
);
$dtc_last_update = current( DTC_Jobs::recent( 'update', 1 ) );
$dtc_last_scan   = DTC_Logger::latest( 'scan.completed' );
$dtc_skip        = (array) get_option( DTC_Update_Service::SKIP_OPTION, array() );
$dtc_latest      = DTC_WPvivid::latest_backup();
$dtc_recent      = DTC_Logger::query( array( 'per_page' => 12 ) )['rows'];

$dtc_checks = array(
	array( $dtc_wpvivid['ok'], 'Backups (WPvivid + S3)', $dtc_wpvivid['message'] . ( ! empty( $dtc_wpvivid['warning'] ) ? ' ' . $dtc_wpvivid['warning'] : '' ) ),
	array( $dtc_wordfence['ok'], 'Malware scans (Wordfence)', $dtc_wordfence['message'] . ( ! empty( $dtc_wordfence['warning'] ) ? ' ' . $dtc_wordfence['warning'] : '' ) ),
	array( DTC_Installer::guardian_installed(), 'Guardian (rollback safety net)', DTC_Installer::guardian_installed() ? ( 'mu' === DTC_Installer::guardian_mode() ? 'Installed in mu-plugins.' : 'Installed via wp-content/fatal-error-handler.php (mu-plugins is read-only on this host).' ) : 'Missing: rollback and restore are disabled. ' . ( DTC_Installer::guardian_error() ?: 'Reload this page to retry the install.' ) ),
	array( $dtc_last_tick > time() - 10 * MINUTE_IN_SECONDS, 'Scheduler', $dtc_last_tick ? 'Last tick ' . human_time_diff( $dtc_last_tick ) . ' ago.' . ( $dtc_last_tick < time() - 10 * MINUTE_IN_SECONDS ? ' WP-Cron is not running often enough. Add a server cron job for wp-cron.php every minute.' : '' ) : 'WP-Cron has not run yet.' ),
);
?>
<div class="wrap dtc-wrap">
	<h1>Digifix TotalCare</h1>

	<div class="dtc-grid dtc-grid-4">
		<?php foreach ( $dtc_checks as $dtc_c ) : ?>
			<div class="dtc-card dtc-check <?php echo $dtc_c[0] ? 'is-ok' : 'is-bad'; ?>">
				<div class="dtc-check-title"><span class="dashicons <?php echo $dtc_c[0] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span> <?php echo esc_html( $dtc_c[1] ); ?></div>
				<p><?php echo esc_html( $dtc_c[2] ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $dtc_active ) : ?>
		<div class="dtc-card dtc-active">
			<h2>Running now</h2>
			<table class="widefat striped">
				<thead><tr><th>Job</th><th>Status</th><th>Step</th><th>Message</th><th>Started</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $dtc_active as $dtc_job ) : ?>
					<tr>
						<td>#<?php echo (int) $dtc_job->id; ?> <?php echo esc_html( ucfirst( $dtc_job->type ) ); ?></td>
						<td><?php echo self::badge( 'running' === $dtc_job->status ? 'info' : 'muted', $dtc_job->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><code><?php echo esc_html( $dtc_job->step ); ?></code></td>
						<td>
							<?php
							$dtc_msg = $dtc_job->message;
							if ( 'pending' === $dtc_job->status && $dtc_active[0]->id !== $dtc_job->id ) {
								// Jobs run one at a time so a backup, an update run and a scan never overlap.
								$dtc_msg = sprintf( 'Waiting for #%d %s to finish (jobs run one at a time).', $dtc_active[0]->id, $dtc_active[0]->type );
							}
							echo esc_html( $dtc_msg );
							?>
						</td>
						<td><?php echo esc_html( self::when( $dtc_job->created_at ) ); ?></td>
						<td><?php self::form( 'dtc_cancel', 'Cancel', array( 'job_id' => $dtc_job->id ), 'button-link-delete', 'Cancel this job?' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Refresh the page to see progress. Jobs advance every minute via WP-Cron.</p>
		</div>
	<?php endif; ?>

	<div class="dtc-grid dtc-grid-3">
		<div class="dtc-card">
			<h2><span class="dashicons dashicons-backup"></span> Backups</h2>
			<p><strong>Last:</strong>
				<?php if ( $dtc_last_backup ) : ?>
					<?php echo self::badge( $dtc_last_backup['level'], ucfirst( substr( $dtc_last_backup['code'], 7 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( self::when( $dtc_last_backup['created_at'] ) ); ?><br>
					<span class="description"><?php echo esc_html( $dtc_last_backup['message'] ); ?></span>
				<?php else : ?>
					None yet.
				<?php endif; ?>
			</p>
			<p><strong>Next:</strong> <?php echo esc_html( self::when( $dtc_next['backup'] ) ); ?></p>
			<?php self::form( 'dtc_run', 'Back up now', array( 'type' => 'backup' ), 'button button-primary' ); ?>
		</div>

		<div class="dtc-card">
			<h2><span class="dashicons dashicons-update"></span> Safe updates</h2>
			<p><strong>Last run:</strong>
				<?php if ( $dtc_last_update ) : ?>
					<?php echo self::badge( 'completed' === $dtc_last_update->status ? 'success' : ( $dtc_last_update->is_active() ? 'info' : 'error' ), $dtc_last_update->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( self::when( $dtc_last_update->created_at ) ); ?><br>
					<span class="description"><?php echo esc_html( $dtc_last_update->message ); ?></span>
				<?php else : ?>
					None yet.
				<?php endif; ?>
			</p>
			<p><strong>Next:</strong> <?php echo esc_html( self::when( $dtc_next['updates'] ) ); ?></p>
			<?php self::form( 'dtc_run', 'Run updates now', array( 'type' => 'update' ), 'button button-primary', 'This takes a backup, then updates plugins, themes and core one at a time. Continue?' ); ?>
			<?php if ( $dtc_skip ) : ?>
				<p class="description dtc-mt">Blocked versions (broke the site before):
					<?php echo esc_html( implode( ', ', array_map( function ( $k, $v ) {
						return preg_replace( '/^[a-z]+:/', '', $k ) . ' ' . $v;
					}, array_keys( $dtc_skip ), $dtc_skip ) ) ); ?>
				</p>
				<?php self::form( 'dtc_clear_skip', 'Clear blocked versions', array(), 'button-link' ); ?>
			<?php endif; ?>
		</div>

		<div class="dtc-card">
			<h2><span class="dashicons dashicons-shield"></span> Malware scans</h2>
			<p><strong>Last:</strong>
				<?php if ( $dtc_last_scan ) : ?>
					<?php echo self::badge( $dtc_last_scan['level'], (int) ( $dtc_last_scan['context']['counts']['new'] ?? 0 ) . ' open issues' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( self::when( $dtc_last_scan['created_at'] ) ); ?><br>
					<span class="description"><?php echo esc_html( $dtc_last_scan['message'] ); ?></span>
				<?php else : ?>
					None yet.
				<?php endif; ?>
			</p>
			<p><strong>Next:</strong> <?php echo esc_html( self::when( $dtc_next['scan'] ) ); ?></p>
			<?php self::form( 'dtc_run', 'Scan now', array( 'type' => 'scan' ), 'button button-primary' ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=WordfenceScan' ) ); ?>">Wordfence results</a>
		</div>
	</div>

	<div class="dtc-grid dtc-grid-2">
		<div class="dtc-card">
			<h2>Recent activity</h2>
			<table class="widefat striped dtc-events">
				<tbody>
				<?php foreach ( $dtc_recent as $dtc_e ) : ?>
					<tr>
						<td class="dtc-nowrap"><?php echo esc_html( get_date_from_gmt( $dtc_e['created_at'], 'j M H:i' ) ); ?></td>
						<td><?php echo self::badge( $dtc_e['level'], $dtc_e['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( $dtc_e['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $dtc_recent ) : ?>
					<tr><td>No activity yet.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=dtc-log' ) ); ?>">Full activity log →</a></p>
		</div>

		<div class="dtc-card">
			<h2>Restore</h2>
			<?php if ( $dtc_latest ) : ?>
				<?php $dtc_sum = DTC_WPvivid::summarize( $dtc_latest['id'] ); ?>
				<p>Latest backup: <strong><?php echo esc_html( wp_date( 'D j M Y, g:i a', (int) $dtc_sum['created'] ) ); ?></strong> (<?php echo esc_html( $dtc_sum['size_human'] ); ?>, <?php echo $dtc_sum['local'] ? 'local + S3' : 'S3 only; will be downloaded first'; ?>).</p>
				<p class="description">Restoring replaces all files and the database with this backup. TotalCare's own log is kept. The site is unavailable for a few minutes while it runs.</p>
				<?php self::form( 'dtc_restore', 'Restore this backup', array( 'backup_id' => $dtc_latest['id'] ), 'button button-secondary dtc-danger', 'Restore the whole site from the backup taken ' . wp_date( 'j M Y g:i a', (int) $dtc_sum['created'] ) . '? All changes since then will be lost.' ); ?>
				<p class="description dtc-mt">Older backups can be restored from <a href="<?php echo esc_url( admin_url( 'admin.php?page=WPvivid' ) ); ?>">WPvivid → Backup &amp; Restore</a>.</p>
			<?php else : ?>
				<p>No backups found in WPvivid yet.</p>
			<?php endif; ?>
		</div>
	</div>
</div>
