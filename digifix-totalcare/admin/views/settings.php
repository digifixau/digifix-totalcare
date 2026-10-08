<?php
/**
 * Settings view. Included from DTC_Admin::page_settings() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$dtc_s       = DTC_Settings::all();
$dtc_secret  = DTC_Bk_Util::s3_secret();
$dtc_stored  = (bool) get_option( 'dtc_s3_secret' ) || ( defined( 'DTC_S3_SECRET' ) && DTC_S3_SECRET );
$dtc_remote  = $dtc_s['s3_access'] && $dtc_s['s3_bucket'] && $dtc_secret;
$dtc_vivid   = DTC_WPvivid::is_available();
$dtc_plugins = get_plugins();
$dtc_themes  = wp_get_themes();
$dtc_days    = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

$dtc_select = function ( $name, array $options, $current ) {
	echo '<select name="dtc[' . esc_attr( $name ) . ']">';
	foreach ( $options as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
};
$dtc_days_opt = array_combine( $dtc_days, array_map( 'ucfirst', $dtc_days ) );
$dtc_check    = function ( $name, $label ) use ( $dtc_s ) {
	echo '<label><input type="checkbox" name="dtc[' . esc_attr( $name ) . ']" value="1"' . checked( ! empty( $dtc_s[ $name ] ), true, false ) . '> ' . esc_html( $label ) . '</label>';
};
$dtc_text     = function ( $name, $type = 'text', $placeholder = '', $class = 'regular-text' ) use ( $dtc_s ) {
	echo '<input type="' . esc_attr( $type ) . '" class="' . esc_attr( $class ) . '" name="dtc[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $dtc_s[ $name ] ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
};
?>
<div class="wrap dtc-wrap">
	<h1>TotalCare Settings</h1>
	<p class="description">Times use the site timezone (<?php echo esc_html( wp_timezone_string() ); ?>).</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="dtc_save_settings">
		<?php wp_nonce_field( 'dtc_save_settings' ); ?>

		<div class="dtc-card">
			<h2>Backups</h2>
			<table class="form-table" role="presentation">
				<tr><th>Scheduled backups</th><td><?php $dtc_check( 'backup_enabled', 'Enabled' ); ?></td></tr>
				<tr><th>Frequency</th><td>
					<?php $dtc_select( 'backup_frequency', array( 'twicedaily' => 'Twice daily', 'daily' => 'Daily', 'weekly' => 'Weekly' ), $dtc_s['backup_frequency'] ); ?>
					on <?php $dtc_select( 'backup_day', $dtc_days_opt, $dtc_s['backup_day'] ); ?> <span class="description">(weekly only)</span>
					at <?php $dtc_text( 'backup_time', 'time', '', 'small-text' ); ?>
				</td></tr>
				<tr><th>Full backup every</th><td><?php $dtc_text( 'backup_full_days', 'number', '', 'small-text' ); ?> days <p class="description">In between, backups only contain files changed since the previous backup (the database is always saved in full). 1 = always a full backup.</p></td></tr>
				<?php if ( $dtc_vivid || 'wpvivid' === $dtc_s['backup_engine'] ) : ?>
					<tr><th>Backup engine</th><td><?php $dtc_select( 'backup_engine', array( 'totalcare' => 'TotalCare built-in (recommended)', 'wpvivid' => 'WPvivid (legacy)' ), $dtc_s['backup_engine'] ); ?><p class="description">Backups made by WPvivid before the switch stay available in WPvivid. You can remove WPvivid once enough built-in backups exist.</p></td></tr>
				<?php endif; ?>
			</table>

			<h3>Keep backups</h3>
			<table class="form-table" role="presentation">
				<tr><th>Always keep the newest</th><td><?php $dtc_text( 'retention_min', 'number', '', 'small-text' ); ?> backups</td></tr>
				<tr><th>Keep every backup from the last</th><td><?php $dtc_text( 'retention_all_days', 'number', '', 'small-text' ); ?> days</td></tr>
				<tr><th>Then keep one per day for</th><td><?php $dtc_text( 'retention_daily', 'number', '', 'small-text' ); ?> days</td></tr>
				<tr><th>One per week for</th><td><?php $dtc_text( 'retention_weekly', 'number', '', 'small-text' ); ?> weeks</td></tr>
				<tr><th>One per month for</th><td><?php $dtc_text( 'retention_monthly', 'number', '', 'small-text' ); ?> months</td></tr>
				<tr><th>Backups taken before updates</th><td>keep for <?php $dtc_text( 'retention_update_days', 'number', '', 'small-text' ); ?> days
					<p class="description">Older backups are deleted from storage after each backup. Weekly and monthly copies prefer full backups. Backups that newer ones still need are always kept, and so are pinned backups (Backups page).</p></td></tr>
			</table>

			<h3>What to back up</h3>
			<table class="form-table" role="presentation">
				<tr><th>Exclude files</th><td>
					<textarea name="dtc[backup_excludes]" rows="7" class="large-text code"><?php echo esc_textarea( $dtc_s['backup_excludes'] ); ?></textarea>
					<p class="description">One per line. A name without "/" matches any file or folder with that name (wildcards allowed, e.g. <code>*.log</code>). A path starting with <code>wp-content/…</code> matches that folder. Changing this starts a new full backup.</p>
				</td></tr>
				<tr><th>Database</th><td>
					<?php $dtc_select( 'backup_db_scope', array( 'prefix' => 'Tables of this site only (' . $GLOBALS['wpdb']->prefix . '*)', 'all' => 'Every table in the database' ), $dtc_s['backup_db_scope'] ); ?>
					<p class="description dtc-mt">Skip these tables (one per line), e.g. large log tables:</p>
					<textarea name="dtc[backup_db_tables]" rows="3" class="large-text code"><?php echo esc_textarea( $dtc_s['backup_db_tables'] ); ?></textarea>
				</td></tr>
			</table>

			<h3>Server load</h3>
			<table class="form-table" role="presentation">
				<tr><th>Work per request</th><td><?php $dtc_text( 'request_budget', 'number', '', 'small-text' ); ?> seconds <p class="description">Backups, restores and updates run in short requests. Lower this if the host stops long PHP requests (25 suits most shared hosting).</p></td></tr>
				<tr><th>Low impact</th><td><?php $dtc_check( 'low_impact', 'Run background work in short bursts with pauses (slower, gentler on small servers)' ); ?></td></tr>
				<tr><th>Give up after</th><td><?php $dtc_text( 'backup_timeout_hours', 'number', '', 'small-text' ); ?> hours</td></tr>
			</table>

			<h3>Remote storage</h3>
			<p>
				<?php if ( $dtc_remote ) : ?>
					<?php echo self::badge( 'success', 'Configured' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> Backups go to <strong><?php echo esc_html( $dtc_s['s3_bucket'] . '/' . ( $dtc_s['s3_path'] ? $dtc_s['s3_path'] : DTC_Bk_Util::default_folder() ) ); ?></strong>. Leave the secret blank to keep the current one.
				<?php elseif ( $dtc_stored && ! $dtc_secret ) : ?>
					<?php echo self::badge( 'error', 'Secret unreadable' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> The stored secret key can no longer be decrypted (wp-content/dtc-data/secret.php changed). Enter it again.
				<?php else : ?>
					<?php echo self::badge( 'error', 'Not configured' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> Enter the bucket details. Saving tests the connection with a small file.
				<?php endif; ?>
			</p>
			<table class="form-table" role="presentation">
				<tr><th>Provider</th><td><?php $dtc_select( 's3_type', array( 'amazons3' => 'Amazon S3', 'r2' => 'Cloudflare R2', 's3compat' => 'Other S3-compatible (Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO)' ), $dtc_s['s3_type'] ); ?></td></tr>
				<tr><th>Access key</th><td><?php $dtc_text( 's3_access' ); ?></td></tr>
				<tr><th>Secret key</th><td><input type="password" class="regular-text" name="dtc[s3_secret]" value="" autocomplete="new-password" placeholder="<?php echo $dtc_stored ? '•••••••• (unchanged)' : ''; ?>"><p class="description">Stored encrypted. The key needs permission to upload, download, list and delete objects in the bucket.</p></td></tr>
				<tr><th>Bucket</th><td><?php $dtc_text( 's3_bucket' ); ?></td></tr>
				<tr><th>Folder</th><td><?php $dtc_text( 's3_path', 'text', DTC_Bk_Util::default_folder() ); ?><p class="description">Folder inside the bucket. Defaults to the site's domain. Every site needs its own folder: a staging copy must use a different one.</p></td></tr>
				<tr><th>Endpoint</th><td><?php $dtc_text( 's3_endpoint', 'text', 's3.ap-southeast-2.wasabisys.com' ); ?><p class="description">Not needed for Amazon S3. Cloudflare R2: your account ID or <code>https://&lt;account-id&gt;.r2.cloudflarestorage.com</code>. Others: e.g. <code>syd1.digitaloceanspaces.com</code> (add <code>http://</code> for a local MinIO).</p></td></tr>
				<tr><th>Region</th><td><?php $dtc_text( 's3_region', 'text', 'us-east-1', 'small-text' ); ?><p class="description">Other S3-compatible services only (Amazon S3 is detected automatically, R2 needs none).</p></td></tr>
				<tr><th>Addressing</th><td><?php $dtc_check( 's3_path_style', 'Path-style URLs (other S3-compatible services; needed for MinIO)' ); ?></td></tr>
			</table>
		</div>

		<div class="dtc-card">
			<h2>Safe updates</h2>
			<table class="form-table" role="presentation">
				<tr><th>Weekly updates</th><td><?php $dtc_check( 'updates_enabled', 'Enabled' ); ?></td></tr>
				<tr><th>When</th><td>Every <?php $dtc_select( 'update_day', $dtc_days_opt, $dtc_s['update_day'] ); ?> at <?php $dtc_text( 'update_time', 'time', '', 'small-text' ); ?></td></tr>
				<tr><th>Update</th><td>
					<?php $dtc_check( 'update_plugins', 'Plugins' ); ?><br>
					<?php $dtc_check( 'update_themes', 'Themes' ); ?><br>
					WordPress core: <?php $dtc_select( 'update_core', array( 'none' => 'Never', 'minor' => 'Minor releases only (e.g. 6.6.1 → 6.6.2)', 'major' => 'Minor and major releases' ), $dtc_s['update_core'] ); ?>
				</td></tr>
				<tr><th>Never update these plugins</th><td class="dtc-checklist">
					<?php foreach ( $dtc_plugins as $dtc_file => $dtc_data ) : ?>
						<?php if ( DTC_BASENAME === $dtc_file ) { continue; } ?>
						<label><input type="checkbox" name="dtc[excluded_plugins][]" value="<?php echo esc_attr( $dtc_file ); ?>" <?php checked( in_array( $dtc_file, (array) $dtc_s['excluded_plugins'], true ) ); ?>> <?php echo esc_html( $dtc_data['Name'] ); ?></label>
					<?php endforeach; ?>
				</td></tr>
				<tr><th>Never update these themes</th><td class="dtc-checklist">
					<?php foreach ( $dtc_themes as $dtc_slug => $dtc_theme ) : ?>
						<label><input type="checkbox" name="dtc[excluded_themes][]" value="<?php echo esc_attr( $dtc_slug ); ?>" <?php checked( in_array( $dtc_slug, (array) $dtc_s['excluded_themes'], true ) ); ?>> <?php echo esc_html( $dtc_theme->get( 'Name' ) ); ?></label>
					<?php endforeach; ?>
				</td></tr>
				<tr><th>Extra pages to check</th><td>
					<textarea name="dtc[health_urls]" rows="4" class="large-text code" placeholder="<?php echo esc_attr( home_url( '/contact/' ) ); ?>"><?php echo esc_textarea( $dtc_s['health_urls'] ); ?></textarea>
					<p class="description">One URL per line on this site (e.g. shop, checkout, contact form). The home page and login page are always checked.</p>
				</td></tr>
				<tr><th>Strict checks</th><td><?php $dtc_check( 'health_strict', 'Also roll back when a page title changes or a page size changes by more than 50%' ); ?></td></tr>
				<tr><th>TotalCare itself</th><td><?php $dtc_check( 'self_update', 'Install new TotalCare releases from GitHub automatically' ); ?><p class="description">Installed version <?php echo esc_html( DTC_VERSION ); ?>. Updates are never applied while a TotalCare job or restore is running.</p></td></tr>
				<tr><th>Keep rollback copies for</th><td><?php $dtc_text( 'snapshot_keep_days', 'number', '', 'small-text' ); ?> days</td></tr>
			</table>
		</div>

		<div class="dtc-card">
			<h2>Malware scans</h2>
			<table class="form-table" role="presentation">
				<tr><th>Scheduled scans</th><td><?php $dtc_check( 'scan_enabled', 'Enabled' ); ?></td></tr>
				<tr><th>When</th><td>
					<?php $dtc_select( 'scan_frequency', array( 'daily' => 'Daily', 'weekly' => 'Weekly' ), $dtc_s['scan_frequency'] ); ?>
					on <?php $dtc_select( 'scan_day', $dtc_days_opt, $dtc_s['scan_day'] ); ?> <span class="description">(weekly only)</span>
					at <?php $dtc_text( 'scan_time', 'time', '', 'small-text' ); ?>
				</td></tr>
				<tr><th>Scan type</th><td><?php $dtc_select( 'scan_type', array( 'standard' => 'Standard (full malware scan)', 'quick' => 'Quick' ), $dtc_s['scan_type'] ); ?></td></tr>
				<tr><th>Alert immediately at</th><td><?php $dtc_select( 'scan_alert_severity', array( '100' => 'Critical issues', '75' => 'High and above', '50' => 'Medium and above', '25' => 'Low and above' ), $dtc_s['scan_alert_severity'] ); ?></td></tr>
			</table>
		</div>

		<div class="dtc-card">
			<h2>Reporting</h2>
			<table class="form-table" role="presentation">
				<tr><th>Client name</th><td><?php $dtc_text( 'client_name' ); ?></td></tr>
				<tr><th>Digifix recipients</th><td><textarea name="dtc[internal_emails]" rows="2" class="large-text"><?php echo esc_textarea( $dtc_s['internal_emails'] ); ?></textarea><p class="description">Get every alert and summary. Separate addresses with commas.</p></td></tr>
				<tr><th>Client recipients</th><td><textarea name="dtc[client_emails]" rows="2" class="large-text"><?php echo esc_textarea( $dtc_s['client_emails'] ); ?></textarea><p class="description">Get the monthly report (and summaries if enabled below).</p></td></tr>
				<tr><th>Emails</th><td>
					<?php $dtc_check( 'email_failures', 'Alert Digifix immediately on failures, rollbacks, restores and security issues' ); ?><br>
					<?php $dtc_check( 'email_update_summary', 'Send a summary after each update run that changed something' ); ?><br>
					<?php $dtc_check( 'email_scan_summary', 'Send a summary after every scan' ); ?><br>
					<?php $dtc_check( 'client_gets_summaries', 'Also send update and scan summaries to the client' ); ?><br>
					<?php $dtc_check( 'monthly_report', 'Send the monthly report on the 1st' ); ?><br>
					<?php $dtc_check( 'monthly_attach_pdf', 'Attach the PDF to the monthly report' ); ?><?php echo DTC_PDF::available() ? '' : ' <span class="description">(needs dompdf: run composer install)</span>'; ?>
				</td></tr>
				<tr><th>Brand colour</th><td><?php $dtc_text( 'brand_color', 'color', '', '' ); ?></td></tr>
				<tr><th>Webhook URL</th><td><?php $dtc_text( 'webhook_url', 'url', 'https://hooks.slack.com/services/… or https://n8n.example.com/webhook/…', 'large-text' ); ?><p class="description">Every event is POSTed as JSON. Slack incoming webhooks get a readable message.</p></td></tr>
				<tr><th>Webhook secret</th><td><input type="password" class="regular-text" name="dtc[webhook_secret]" value="" autocomplete="new-password" placeholder="<?php echo $dtc_s['webhook_secret'] ? '•••••••• (unchanged)' : 'optional'; ?>"><p class="description">Signs each request: header <code>X-DTC-Signature: sha256=HMAC(body, secret)</code>.</p></td></tr>
			</table>
		</div>

		<?php submit_button( 'Save settings' ); ?>
	</form>

	<div class="dtc-card">
		<h2>Tests</h2>
		<?php self::form( 'dtc_test_email', 'Send test email' ); ?>
		<?php self::form( 'dtc_test_webhook', 'Send test webhook' ); ?>
		<?php self::form( 'dtc_probe', 'Test background requests' ); ?>
		<p class="description">Checks that the server lets TotalCare's background requests finish (takes about 10 seconds).</p>
	</div>
</div>
