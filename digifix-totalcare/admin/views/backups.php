<?php
/**
 * Backups view. Included from Digifix_TC_Admin::page_backups() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$digifix_tc_folder_q = isset( $_GET['folder'] ) ? Digifix_TC_Bk_Util::clean_folder( sanitize_text_field( wp_unslash( $_GET['folder'] ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$digifix_tc_remote   = Digifix_TC_Bk_Remote::from_settings( '' !== $digifix_tc_folder_q ? $digifix_tc_folder_q : null );
$digifix_tc_own      = is_wp_error( $digifix_tc_remote ) ? '' : Digifix_TC_Bk_Remote::from_settings()->folder;
$digifix_tc_foreign  = ! is_wp_error( $digifix_tc_remote ) && $digifix_tc_remote->folder !== $digifix_tc_own;
$digifix_tc_entries  = is_wp_error( $digifix_tc_remote ) ? $digifix_tc_remote : self::catalog( $digifix_tc_remote );
$digifix_tc_owner    = ( is_wp_error( $digifix_tc_remote ) || $digifix_tc_foreign ) ? true : $digifix_tc_remote->check_owner();
$digifix_tc_sites    = null;
if ( ! is_wp_error( $digifix_tc_remote ) && ! empty( $_GET['sites'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$digifix_tc_sites = $digifix_tc_remote->list_sites();
}
$digifix_tc_request = Digifix_TC_Restore::request();
$digifix_tc_parts   = array(
	'db'        => 'Database',
	'core'      => Digifix_TC_Bk_Areas::LABELS['core'],
	'plugins'   => Digifix_TC_Bk_Areas::LABELS['plugins'],
	'themes'    => Digifix_TC_Bk_Areas::LABELS['themes'],
	'muplugins' => Digifix_TC_Bk_Areas::LABELS['muplugins'],
	'content'   => Digifix_TC_Bk_Areas::LABELS['content'],
	'uploads'   => Digifix_TC_Bk_Areas::LABELS['uploads'],
);
$digifix_tc_triggers = array(
	'schedule' => 'Scheduled',
	'manual'   => 'Manual',
	'update'   => 'Before updates',
);
?>
<div class="wrap digifix-tc-wrap">
	<h1>Backups</h1>

	<?php if ( is_wp_error( $digifix_tc_remote ) ) : ?>
		<div class="digifix-tc-card"><p><?php echo esc_html( $digifix_tc_remote->get_error_message() ); ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=digifix-tc-settings' ) ); ?>">Settings →</a></p></div>
	<?php else : ?>

		<?php if ( $digifix_tc_request && in_array( $digifix_tc_request['status'] ?? '', array( 'pending', 'running', 'failing' ), true ) ) : ?>
			<div class="digifix-tc-card digifix-tc-active">
				<h2>Restore in progress</h2>
				<p><?php echo esc_html( $digifix_tc_request['progress'] ?? 'Starting…' ); ?></p>
				<?php if ( Digifix_TC_Restore::status_url() ) : ?>
					<p><a class="button button-primary" target="_blank" href="<?php echo esc_url( Digifix_TC_Restore::status_url() ); ?>">Follow the restore</a> <span class="description">Opens a page that keeps working while the site shows its maintenance page.</span></p>
				<?php endif; ?>
			</div>
		<?php endif; ?>

		<?php if ( is_wp_error( $digifix_tc_owner ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $digifix_tc_owner->get_error_message() ); ?></p>
				<p><?php self::form( 'digifix_tc_take_over', 'Take over this folder', array(), 'button', 'Only do this if this site really replaced the one that used the folder (e.g. it moved to a new address). A staging copy must use its own folder instead. Continue?' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="digifix-tc-card">
			<p>
				Storage: <strong><?php echo esc_html( Digifix_TC_Settings::get( 's3_bucket' ) . '/' . $digifix_tc_remote->folder ); ?></strong>
				<?php if ( $digifix_tc_foreign ) : ?>
					— <?php echo self::badge( 'warning', 'Another site' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> <a href="<?php echo esc_url( admin_url( 'admin.php?page=digifix-tc-backups' ) ); ?>">Back to this site's backups</a>
				<?php endif; ?>
			</p>
			<?php if ( ! $digifix_tc_foreign ) : ?>
				<?php self::form( 'digifix_tc_run', 'Back up now', array( 'type' => 'backup', 'return' => 'digifix-tc-backups' ), 'button button-primary' ); ?>
				<?php self::form( 'digifix_tc_run', 'Full backup now', array( 'type' => 'backup', 'full' => 1, 'return' => 'digifix-tc-backups' ), 'button' ); ?>
			<?php endif; ?>
			<?php self::form( 'digifix_tc_refresh_backups', 'Refresh list', array( 'folder' => $digifix_tc_foreign ? $digifix_tc_remote->folder : '' ), 'button' ); ?>
			<a class="button" href="<?php echo esc_url( add_query_arg( 'sites', 1 ) ); ?>">Other sites in this bucket</a>
		</div>

		<?php if ( is_array( $digifix_tc_sites ) ) : ?>
			<div class="digifix-tc-card">
				<h2>Sites in this bucket</h2>
				<p class="description">Restore another site's backup here to move or clone that site to this address. Its URLs are rewritten to <?php echo esc_html( home_url() ); ?>.</p>
				<table class="widefat striped">
					<thead><tr><th>Folder</th><th>Site</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $digifix_tc_sites as $digifix_tc_f => $digifix_tc_info ) : ?>
						<tr>
							<td><code><?php echo esc_html( $digifix_tc_f ); ?></code></td>
							<td><?php echo esc_html( $digifix_tc_info['home'] ?? '' ); ?><?php echo $digifix_tc_f === $digifix_tc_own ? ' ' . self::badge( 'info', 'This site' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><a href="<?php echo esc_url( admin_url( 'admin.php?page=digifix-tc-backups&folder=' . rawurlencode( $digifix_tc_f ) ) ); ?>">View backups</a></td>
						</tr>
					<?php endforeach; ?>
					<?php if ( ! $digifix_tc_sites ) : ?>
						<tr><td colspan="3">No other site folders found next to "<?php echo esc_html( $digifix_tc_remote->folder ); ?>".</td></tr>
					<?php endif; ?>
					</tbody>
				</table>
			</div>
		<?php elseif ( is_wp_error( $digifix_tc_sites ) ) : ?>
			<div class="notice notice-error inline"><p><?php echo esc_html( $digifix_tc_sites->get_error_message() ); ?></p></div>
		<?php endif; ?>

		<div class="digifix-tc-card">
			<h2><?php echo $digifix_tc_foreign ? esc_html( 'Backups of ' . $digifix_tc_remote->folder ) : 'Backups'; ?></h2>
			<?php if ( is_wp_error( $digifix_tc_entries ) ) : ?>
				<p><?php echo esc_html( $digifix_tc_entries->get_error_message() ); ?></p>
			<?php elseif ( ! $digifix_tc_entries ) : ?>
				<p>No backups in this folder yet.</p>
			<?php else : ?>
				<table class="widefat striped digifix-tc-backups">
					<thead><tr><th>Date</th><th>Type</th><th>Size</th><th>Files changed</th><th></th></tr></thead>
					<tbody>
					<?php foreach ( $digifix_tc_entries as $digifix_tc_b ) : ?>
						<?php $digifix_tc_date = wp_date( 'D j M Y, g:i a', (int) $digifix_tc_b['created'] ); ?>
						<tr>
							<td><strong><?php echo esc_html( $digifix_tc_date ); ?></strong><br><span class="description"><?php echo esc_html( $digifix_tc_triggers[ $digifix_tc_b['trigger'] ] ?? $digifix_tc_b['trigger'] ); ?></span>
								<?php echo $digifix_tc_b['pinned'] ? ' ' . self::badge( 'info', 'Pinned' ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
							<td><?php echo esc_html( 'full' === $digifix_tc_b['type'] ? 'Full' : 'Incremental' ); ?></td>
							<td><?php echo esc_html( size_format( $digifix_tc_b['size'], 1 ) ); ?><br><span class="description">database <?php echo esc_html( size_format( $digifix_tc_b['db_size'], 1 ) ); ?></span></td>
							<td><?php echo esc_html( number_format_i18n( $digifix_tc_b['changed'] ) . ' of ' . number_format_i18n( $digifix_tc_b['files'] ) ); ?></td>
							<td class="digifix-tc-actions">
								<details>
									<summary class="button">Restore…</summary>
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="digifix-tc-restore-form" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( ( $digifix_tc_foreign ? 'Replace THIS site with the backup of ' . $digifix_tc_remote->folder : 'Restore the selected parts from the backup of ' . $digifix_tc_date ) . '? Changes made since then will be lost.' ) ); ?>);">
										<input type="hidden" name="action" value="digifix_tc_restore">
										<input type="hidden" name="backup_id" value="<?php echo esc_attr( $digifix_tc_b['id'] ); ?>">
										<input type="hidden" name="folder" value="<?php echo esc_attr( $digifix_tc_foreign ? $digifix_tc_remote->folder : '' ); ?>">
										<?php wp_nonce_field( 'digifix_tc_restore' ); ?>
										<fieldset>
											<?php foreach ( $digifix_tc_parts as $digifix_tc_k => $digifix_tc_label ) : ?>
												<label><input type="checkbox" name="scope[]" value="<?php echo esc_attr( $digifix_tc_k ); ?>" checked> <?php echo esc_html( $digifix_tc_label ); ?></label>
											<?php endforeach; ?>
										</fieldset>
										<p><label>Only these paths (optional, one per line):<br>
											<textarea name="paths" rows="2" class="large-text code" placeholder="wp-content/plugins/woocommerce"></textarea></label></p>
										<p><label><input type="checkbox" name="clean" value="1" checked> Remove core, plugin and theme files that are not in the backup</label><br>
											<label><input type="checkbox" name="htaccess" value="1"> Also restore .htaccess</label></p>
										<?php if ( $digifix_tc_foreign || ( $digifix_tc_b['home'] && Digifix_TC_Bk_Util::site_key( $digifix_tc_b['home'] ) !== Digifix_TC_Bk_Util::site_key() ) ) : ?>
											<p class="description">This backup is from <strong><?php echo esc_html( $digifix_tc_b['home'] ); ?></strong>. Its URLs and paths are rewritten for this site, and you will log in with that site's users afterwards.</p>
										<?php endif; ?>
										<p class="description">Only files that differ from this site are downloaded. The database is prepared while the site stays online; the site shows a maintenance page only while code files and tables are switched.</p>
										<button type="submit" class="button button-primary digifix-tc-danger">Restore</button>
									</form>
								</details>
								<?php if ( ! $digifix_tc_foreign ) : ?>
									<?php self::form( 'digifix_tc_pin', $digifix_tc_b['pinned'] ? 'Unpin' : 'Pin', array( 'backup_id' => $digifix_tc_b['id'], 'pinned' => $digifix_tc_b['pinned'] ? '' : '1' ), 'button-link' ); ?>
								<?php endif; ?>
								<details class="digifix-tc-downloads">
									<summary class="button-link">Download</summary>
									<p class="description">Links work for one hour. Archives are standard .tar.gz / .sql.gz files; an incremental backup only holds the files that changed.</p>
									<?php foreach ( (array) ( $digifix_tc_b['objects'] ?? array( 'db.sql.gz' ) ) as $digifix_tc_obj ) : ?>
										<a href="<?php echo esc_url( $digifix_tc_remote->s3->presign( $digifix_tc_remote->backup_key( $digifix_tc_b['id'], $digifix_tc_obj ) ) ); ?>"><?php echo esc_html( $digifix_tc_obj ); ?></a>
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
