<?php
/**
 * Backups view. Included from DTC_Admin::page_backups() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dtc_folder_q = isset( $_GET['folder'] ) ? DTC_Bk_Util::clean_folder( sanitize_text_field( wp_unslash( $_GET['folder'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$dtc_remote   = DTC_Bk_Remote::from_settings( '' !== $dtc_folder_q ? $dtc_folder_q : null );
$dtc_own      = is_wp_error( $dtc_remote ) ? '' : DTC_Bk_Remote::from_settings()->folder;
$dtc_foreign  = ! is_wp_error( $dtc_remote ) && $dtc_remote->folder !== $dtc_own;
$dtc_entries  = is_wp_error( $dtc_remote ) ? $dtc_remote : self::catalog( $dtc_remote );
$dtc_owner    = ( is_wp_error( $dtc_remote ) || $dtc_foreign ) ? true : $dtc_remote->check_owner();
$dtc_sites    = null;
if ( ! is_wp_error( $dtc_remote ) && ! empty( $_GET['sites'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$dtc_sites = $dtc_remote->list_sites();
}
$dtc_request = DTC_Restore::request();
$dtc_parts   = array(
	'db'        => 'Database',
	'core'      => DTC_Bk_Areas::LABELS['core'],
	'plugins'   => DTC_Bk_Areas::LABELS['plugins'],
	'themes'    => DTC_Bk_Areas::LABELS['themes'],
	'muplugins' => DTC_Bk_Areas::LABELS['muplugins'],
	'content'   => DTC_Bk_Areas::LABELS['content'],
	'uploads'   => DTC_Bk_Areas::LABELS['uploads'],
);
$dtc_triggers = array(
	'schedule' => 'Scheduled',
	'manual'   => 'Manual',
	'update'   => 'Before updates',
);
?>
<div class="wrap dtc-wrap">
	<h1>Backups</h1>

	<?php if ( is_wp_error( $dtc_remote ) ) : ?>
		<div class="dtc-card"><p><?php echo esc_html( $dtc_remote->get_error_message() ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=dtc-settings' ) ); ?>">Settings →</a></p></div>
	<?php else : ?>

		<?php if ( $dtc_request && in_array( $dtc_request['status'] ?? '', array( 'pending', 'running', 'failing' ), true ) ) : ?>
			<div class="dtc-card dtc-active">
				<h2>Restore in progress</h2>
				<p><?php echo esc_html( $dtc_request['progress'] ?? 'Starting…' ); ?></p>
				<?php if ( DTC_Restore::status_url() ) : ?>
					<p><a class="button button-primary" target="_blank" href="<?php echo esc_url( DTC_Restore::status_url() ); ?>">Follow the restore</a> <span class="description">Opens a page that keeps working while the site shows its maintenance page.</span></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( is_wp_error( $dtc_owner ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $dtc_owner->get_error_message() ); ?></p>
				<p><?php self::form( 'dtc_take_over', 'Take over this folder', array(), 'button', 'Only do this if this site really replaced the one that used the folder (e.g. it moved to a new address). A staging copy must use its own folder instead. Continue?' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="dtc-card">
			<p>
				Storage: <strong><?php echo esc_html( DTC_Settings::get( 's3_bucket' ) . '/' . $dtc_remote->folder ); ?></strong>
				<?php if ( $dtc_foreign ) : ?>
					— <?php echo self::badge( 'warning', 'Another site' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=dtc-backups' ) ); ?>">Back to this site's backups</a>
				<?php endif; ?>
			</p>
			<?php if ( ! $dtc_foreign ) : ?>
				<?php self::form( 'dtc_run', 'Back up now', array( 'type' => 'backup', 'return' => 'dtc-backups' ), 'button button-primary' ); ?>
				<?php self::form( 'dtc_run', 'Full backup now', array( 'type' => 'backup', 'full' => 1, 'return' => 'dtc-backups' ), 'button' ); ?>
			<?php endif; ?>
			<?php self::form( 'dtc_refresh_backups', 'Refresh list', array( 'folder' => $dtc_foreign ? $dtc_remote->folder : '' ), 'button' ); ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'sites', 1 ) ); ?>">Other sites in this bucket</a>
		</div>

		<?php if ( is_array( $dtc_sites ) ) : ?>
			<div class="dtc-card">
				<h2>Sites in this bucket</h2>
				<p class="description">Restore another site's backup here to move or clone that site to this address. Its URLs are rewritten to <?php echo esc_html( home_url() ); ?>.</p>
				<table class="widefat striped">
					<thead><tr><th>Folder</th><th>Site</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $dtc_sites as $dtc_f => $dtc_info ) : ?>
						<tr>
							<td><code><?php echo esc_html( $dtc_f ); ?></code></td>
							<td><?php echo esc_html( $dtc_info['home'] ?? '' ); ?><?php echo $dtc_f === $dtc_own ? ' ' . self::badge( 'info', 'This site' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=dtc-backups&folder=' . rawurlencode( $dtc_f ) ) ); ?>">View backups</a></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $dtc_sites ) : ?>
						<tr><td colspan="3">No other site folders found next to "<?php echo esc_html( $dtc_remote->folder ); ?>".</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		<?php elseif ( is_wp_error( $dtc_sites ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $dtc_sites->get_error_message() ); ?></p></div>
		<?php endif; ?>

		<div class="dtc-card">
			<h2><?php echo $dtc_foreign ? esc_html( 'Backups of ' . $dtc_remote->folder ) : 'Backups'; ?></h2>
			<?php if ( is_wp_error( $dtc_entries ) ) : ?>
				<p><?php echo esc_html( $dtc_entries->get_error_message() ); ?></p>
			<?php elseif ( ! $dtc_entries ) : ?>
				<p>No backups in this folder yet.</p>
			<?php else : ?>
				<table class="widefat striped dtc-backups">
					<thead><tr><th>Date</th><th>Type</th><th>Size</th><th>Files changed</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $dtc_entries as $dtc_b ) : ?>
						<?php $dtc_date = wp_date( 'D j M Y, g:i a', (int) $dtc_b['created'] ); ?>
						<tr>
							<td><strong><?php echo esc_html( $dtc_date ); ?></strong><br><span class="description"><?php echo esc_html( $dtc_triggers[ $dtc_b['trigger'] ] ?? $dtc_b['trigger'] ); ?></span>
								<?php echo $dtc_b['pinned'] ? ' ' . self::badge( 'info', 'Pinned' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( 'full' === $dtc_b['type'] ? 'Full' : 'Incremental' ); ?></td>
							<td><?php echo esc_html( size_format( $dtc_b['size'], 1 ) ); ?><br><span class="description">database <?php echo esc_html( size_format( $dtc_b['db_size'], 1 ) ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( $dtc_b['changed'] ) . ' of ' . number_format_i18n( $dtc_b['files'] ) ); ?></td>
							<td class="dtc-actions">
								<details>
									<summary class="button">Restore…</summary>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="dtc-restore-form" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( ( $dtc_foreign ? 'Replace THIS site with the backup of ' . $dtc_remote->folder : 'Restore the selected parts from the backup of ' . $dtc_date ) . '? Changes made since then will be lost.' ) ); ?>);">
										<input type="hidden" name="action" value="dtc_restore">
										<input type="hidden" name="backup_id" value="<?php echo esc_attr( $dtc_b['id'] ); ?>">
										<input type="hidden" name="folder" value="<?php echo esc_attr( $dtc_foreign ? $dtc_remote->folder : '' ); ?>">
										<?php wp_nonce_field( 'dtc_restore' ); ?>
										<fieldset>
											<?php foreach ( $dtc_parts as $dtc_k => $dtc_label ) : ?>
												<label><input type="checkbox" name="scope[]" value="<?php echo esc_attr( $dtc_k ); ?>" checked> <?php echo esc_html( $dtc_label ); ?></label>
											<?php endforeach; ?>
										</fieldset>
										<p><label>Only these paths (optional, one per line):<br>
											<textarea name="paths" rows="2" class="large-text code" placeholder="wp-content/plugins/woocommerce"></textarea></label></p>
										<p><label><input type="checkbox" name="clean" value="1" checked> Remove core, plugin and theme files that are not in the backup</label><br>
											<label><input type="checkbox" name="htaccess" value="1"> Also restore .htaccess</label></p>
										<?php if ( $dtc_foreign || ( $dtc_b['home'] && DTC_Bk_Util::site_key( $dtc_b['home'] ) !== DTC_Bk_Util::site_key() ) ) : ?>
											<p class="description">This backup is from <strong><?php echo esc_html( $dtc_b['home'] ); ?></strong>. Its URLs and paths are rewritten for this site, and you will log in with that site's users afterwards.</p>
										<?php endif; ?>
										<p class="description">Only files that differ from this site are downloaded. The database is prepared while the site stays online; the site shows a maintenance page only while code files and tables are switched.</p>
										<button type="submit" class="button button-primary dtc-danger">Restore</button>
									</form>
								</details>
								<?php if ( ! $dtc_foreign ) : ?>
									<?php self::form( 'dtc_pin', $dtc_b['pinned'] ? 'Unpin' : 'Pin', array( 'backup_id' => $dtc_b['id'], 'pinned' => $dtc_b['pinned'] ? '' : '1' ), 'button-link' ); ?>
								<?php endif; ?>
								<details class="dtc-downloads">
									<summary class="button-link">Download</summary>
									<p class="description">Links work for one hour. Archives are standard .tar.gz / .sql.gz files; an incremental backup only holds the files that changed.</p>
									<?php foreach ( (array) ( $dtc_b['objects'] ?? array( 'db.sql.gz' ) ) as $dtc_obj ) : ?>
										<a href="<?php echo esc_url( $dtc_remote->s3->presign( $dtc_remote->backup_key( $dtc_b['id'], $dtc_obj ) ) ); ?>"><?php echo esc_html( $dtc_obj ); ?></a>
									<?php endforeach; ?>
								</details>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p class="description">An incremental backup needs the backups it builds on; TotalCare keeps those automatically, so any backup in this list can be restored on its own.</p>
			<?php endif; ?>
		</div>
	<?php endif; ?>
</div>
