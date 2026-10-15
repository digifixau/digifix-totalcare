<?php
/**
 * Dashboard view. Included from Digifix_TC_Admin::page_dashboard() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$digifix_tc_backups   = Digifix_TC_Backup_Service::status();
$digifix_tc_wordfence = Digifix_TC_Wordfence::status();
$digifix_tc_last_tick = (int) get_option( 'digifix_tc_last_tick' );
$digifix_tc_next      = Digifix_TC_Scheduler::next_runs();
$digifix_tc_active    = Digifix_TC_Jobs::active();

// Most recent finished backup (completed or failed).
$digifix_tc_last_backup = current(
	array_filter(
		Digifix_TC_Logger::query( array( 'type' => 'backup', 'per_page' => 20 ) )['rows'],
		function ( $e ) {
			return in_array( $e['code'], array( 'backup.completed', 'backup.failed' ), true );
		}
	)
);
$digifix_tc_last_update = current( Digifix_TC_Jobs::recent( 'update', 1 ) );
$digifix_tc_last_scan   = Digifix_TC_Logger::latest( 'scan.completed' );
$digifix_tc_skip        = (array) get_option( Digifix_TC_Update_Service::SKIP_OPTION, array() );
$digifix_tc_engine      = Digifix_TC_Backup_Service::engine();
$digifix_tc_latest      = 'wpvivid' === $digifix_tc_engine ? Digifix_TC_WPvivid::latest_backup() : null;
$digifix_tc_recent      = Digifix_TC_Logger::query( array( 'per_page' => 12 ) )['rows'];

$digifix_tc_checks = array(
	array( $digifix_tc_backups['ok'], 'wpvivid' === $digifix_tc_engine ? 'Backups (WPvivid + S3)' : 'Backups', $digifix_tc_backups['message'] . ( ! empty( $digifix_tc_backups['warning'] ) ? ' ' . $digifix_tc_backups['warning'] : '' ) ),
	array( $digifix_tc_wordfence['ok'], 'Malware scans (Wordfence)', $digifix_tc_wordfence['message'] . ( ! empty( $digifix_tc_wordfence['warning'] ) ? ' ' . $digifix_tc_wordfence['warning'] : '' ) ),
	array( Digifix_TC_Installer::guardian_installed(), 'Guardian (rollback safety net)', Digifix_TC_Installer::guardian_installed() ? ( 'mu' === Digifix_TC_Installer::guardian_mode() ? 'Installed in mu-plugins.' : 'Installed via wp-content/fatal-error-handler.php (mu-plugins is read-only on this host).' ) : 'Missing: rollback and restore are disabled. ' . ( Digifix_TC_Installer::guardian_error() ?: 'Reload this page to retry the install.' ) ),
	array( $digifix_tc_last_tick > time() - 10 * MINUTE_IN_SECONDS, 'Scheduler', $digifix_tc_last_tick ? 'Last tick ' . human_time_diff( $digifix_tc_last_tick ) . ' ago.' . ( $digifix_tc_last_tick < time() - 10 * MINUTE_IN_SECONDS ? ' WP-Cron is not running often enough. Add a server cron job for wp-cron.php every minute.' : '' ) : 'WP-Cron has not run yet.' ),
);
?>
<div class="wrap digifix-tc-wrap">
	<h1>Digifix TotalCare</h1>

	<div class="digifix-tc-grid digifix-tc-grid-4">
		<?php foreach ( $digifix_tc_checks as $digifix_tc_c ) : ?>
			<div class="digifix-tc-card digifix-tc-check <?php echo $digifix_tc_c[0] ? 'is-ok' : 'is-bad'; ?>">
				<div class="digifix-tc-check-title"><span class="dashicons <?php echo $digifix_tc_c[0] ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span> <?php echo esc_html( $digifix_tc_c[1] ); ?></div>
				<p><?php echo esc_html( $digifix_tc_c[2] ); ?></p>
			</div>
		<?php endforeach; ?>
	</div>

	<?php if ( $digifix_tc_active ) : ?>
		<div class="digifix-tc-card digifix-tc-active">
			<h2>Running now</h2>
			<table class="widefat striped">
				<thead><tr><th>Job</th><th>Status</th><th>Step</th><th>Message</th><th>Started</th><th></th></tr></thead>
				<tbody>
				<?php foreach ( $digifix_tc_active as $digifix_tc_job ) : ?>
					<tr>
						<td>#<?php echo (int) $digifix_tc_job->id; ?> <?php echo esc_html( ucfirst( $digifix_tc_job->type ) ); ?></td>
						<td><?php echo self::badge( 'running' === $digifix_tc_job->status ? 'info' : 'muted', $digifix_tc_job->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><code><?php echo esc_html( $digifix_tc_job->step ); ?></code></td>
						<td>
							<?php
							$digifix_tc_msg = $digifix_tc_job->message;
							if ( 'pending' === $digifix_tc_job->status && $digifix_tc_active[0]->id !== $digifix_tc_job->id ) {
								// Jobs run one at a time so a backup, an update run and a scan never overlap.
								$digifix_tc_msg = sprintf( 'Waiting for #%d %s to finish (jobs run one at a time).', $digifix_tc_active[0]->id, $digifix_tc_active[0]->type );
							}
							echo esc_html( $digifix_tc_msg );
							?>
						</td>
						<td><?php echo esc_html( self::when( $digifix_tc_job->created_at ) ); ?></td>
						<td><?php self::form( 'digifix_tc_cancel', 'Cancel', array( 'job_id' => $digifix_tc_job->id ), 'button-link-delete', 'Cancel this job?' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<p class="description">Refresh the page to see progress. Jobs advance every minute via WP-Cron.</p>
		</div>
	<?php endif; ?>

	<div class="digifix-tc-grid digifix-tc-grid-3">
		<div class="digifix-tc-card">
			<h2><span class="dashicons dashicons-backup"></span> Backups</h2>
			<p><strong>Last:</strong>
				<?php if ( $digifix_tc_last_backup ) : ?>
					<?php echo self::badge( $digifix_tc_last_backup['level'], ucfirst( substr( $digifix_tc_last_backup['code'], 7 ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( self::when( $digifix_tc_last_backup['created_at'] ) ); ?><br>
					<span class="description"><?php echo esc_html( $digifix_tc_last_backup['message'] ); ?></span>
				<?php else : ?>
					None yet.
				<?php endif; ?>
			</p>
			<p><strong>Next:</strong> <?php echo esc_html( self::when( $digifix_tc_next['backup'] ) ); ?></p>
			<?php self::form( 'digifix_tc_run', 'Back up now', array( 'type' => 'backup' ), 'button button-primary' ); ?>
			<?php if ( 'wpvivid' !== $digifix_tc_engine ) : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=digifix-tc-backups' ) ); ?>">All backups &amp; restore</a>
			<?php endif; ?>
		</div>

		<div class="digifix-tc-card">
			<h2><span class="dashicons dashicons-update"></span> Safe updates</h2>
			<p><strong>Last run:</strong>
				<?php if ( $digifix_tc_last_update ) : ?>
					<?php echo self::badge( 'completed' === $digifix_tc_last_update->status ? 'success' : ( $digifix_tc_last_update->is_active() ? 'info' : 'error' ), $digifix_tc_last_update->status ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( self::when( $digifix_tc_last_update->created_at ) ); ?><br>
					<span class="description"><?php echo esc_html( $digifix_tc_last_update->message ); ?></span>
				<?php else : ?>
					None yet.
				<?php endif; ?>
			</p>
			<p><strong>Next:</strong> <?php echo esc_html( self::when( $digifix_tc_next['updates'] ) ); ?></p>
			<?php self::form( 'digifix_tc_run', 'Run updates now', array( 'type' => 'update' ), 'button button-primary', 'This takes a backup, then updates plugins, themes and core one at a time. Continue?' ); ?>
			<?php if ( $digifix_tc_skip ) : ?>
				<p class="description digifix-tc-mt">Blocked versions (broke the site before):
					<?php echo esc_html( implode( ', ', array_map( function ( $k, $v ) {
						return preg_replace( '/^[a-z]+:/', '', $k ) . ' ' . $v;
					}, array_keys( $digifix_tc_skip ), $digifix_tc_skip ) ) ); ?>
				</p>
				<?php self::form( 'digifix_tc_clear_skip', 'Clear blocked versions', array(), 'button-link' ); ?>
			<?php endif; ?>
		</div>

		<div class="digifix-tc-card">
			<h2><span class="dashicons dashicons-shield"></span> Malware scans</h2>
			<p><strong>Last:</strong>
				<?php if ( $digifix_tc_last_scan ) : ?>
					<?php echo self::badge( $digifix_tc_last_scan['level'], (int) ( $digifix_tc_last_scan['context']['counts']['new'] ?? 0 ) . ' open issues' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
					<?php echo esc_html( self::when( $digifix_tc_last_scan['created_at'] ) ); ?><br>
					<span class="description"><?php echo esc_html( $digifix_tc_last_scan['message'] ); ?></span>
				<?php else : ?>
					None yet.
				<?php endif; ?>
			</p>
			<p><strong>Next:</strong> <?php echo esc_html( self::when( $digifix_tc_next['scan'] ) ); ?></p>
			<?php self::form( 'digifix_tc_run', 'Scan now', array( 'type' => 'scan' ), 'button button-primary' ); ?>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=WordfenceScan' ) ); ?>">Wordfence results</a>
		</div>
	</div>

	<div class="digifix-tc-grid digifix-tc-grid-2">
		<div class="digifix-tc-card">
			<h2>Recent activity</h2>
			<table class="widefat striped digifix-tc-events">
				<tbody>
				<?php foreach ( $digifix_tc_recent as $digifix_tc_e ) : ?>
					<tr>
						<td class="digifix-tc-nowrap"><?php echo esc_html( get_date_from_gmt( $digifix_tc_e['created_at'], 'j M H:i' ) ); ?></td>
						<td><?php echo self::badge( $digifix_tc_e['level'], $digifix_tc_e['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
						<td><?php echo esc_html( $digifix_tc_e['message'] ); ?></td>
					</tr>
				<?php endforeach; ?>
				<?php if ( ! $digifix_tc_recent ) : ?>
					<tr><td>No activity yet.</td></tr>
				<?php endif; ?>
				</tbody>
			</table>
			<p><a href="<?php echo esc_url( admin_url( 'admin.php?page=digifix-tc-log' ) ); ?>">Full activity log →</a></p>
		</div>

		<div class="digifix-tc-card">
			<h2>Restore</h2>
			<?php if ( 'wpvivid' !== $digifix_tc_engine ) : ?>
				<p>Restore the whole site or only parts of it (database, plugins, themes, uploads, or single folders) from any backup on the <a href="<?php echo esc_url( admin_url( 'admin.php?page=digifix-tc-backups' ) ); ?>">Backups page</a>.</p>
				<p class="description">Only files that differ are downloaded. The database is prepared while the site stays online, and the replaced tables are kept until the restored site passes its health check.</p>
				<?php $digifix_tc_last_restore = Digifix_TC_Logger::latest( 'restore.completed' ); ?>
				<?php if ( $digifix_tc_last_restore ) : ?>
					<p><strong>Last restore:</strong> <?php echo esc_html( self::when( $digifix_tc_last_restore['created_at'] ) ); ?></p>
				<?php endif; ?>
			<?php elseif ( $digifix_tc_latest ) : ?>
				<?php $digifix_tc_sum = Digifix_TC_WPvivid::summarize( $digifix_tc_latest['id'] ); ?>
				<p>Latest backup: <strong><?php echo esc_html( wp_date( 'D j M Y, g:i a', (int) $digifix_tc_sum['created'] ) ); ?></strong> (<?php echo esc_html( $digifix_tc_sum['size_human'] ); ?>, <?php echo $digifix_tc_sum['local'] ? 'local + S3' : 'S3 only; will be downloaded first'; ?>).</p>
				<p class="description">Restoring replaces all files and the database with this backup. TotalCare's own log is kept. The site is unavailable for a few minutes while it runs.</p>
				<?php self::form( 'digifix_tc_restore', 'Restore this backup', array( 'backup_id' => $digifix_tc_latest['id'], 'engine' => 'wpvivid' ), 'button button-secondary digifix-tc-danger', 'Restore the whole site from the backup taken ' . wp_date( 'j M Y g:i a', (int) $digifix_tc_sum['created'] ) . '? All changes since then will be lost.' ); ?>
				<p class="description digifix-tc-mt">Older backups can be restored from <a href="<?php echo esc_url( admin_url( 'admin.php?page=WPvivid' ) ); ?>">WPvivid → Backup &amp; Restore</a>.</p>
			<?php else : ?>
				<p>No backups found in WPvivid yet.</p>
			<?php endif; ?>
		</div>
	</div>
</div>
