<?php
/**
 * Settings view. Included from Digifix_TC_Admin::page_settings() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$digifix_tc_s       = Digifix_TC_Settings::all();
$digifix_tc_secret  = Digifix_TC_Bk_Util::s3_secret();
$digifix_tc_stored  = (bool) get_option( 'digifix_tc_s3_secret' ) || ( defined( 'DIGIFIX_TC_S3_SECRET' ) && DIGIFIX_TC_S3_SECRET );
$digifix_tc_remote  = $digifix_tc_s['s3_access'] && $digifix_tc_s['s3_bucket'] && $digifix_tc_secret;
$digifix_tc_vivid   = Digifix_TC_WPvivid::is_available();
$digifix_tc_plugins = get_plugins();
$digifix_tc_themes  = wp_get_themes();
$digifix_tc_days    = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );

$digifix_tc_select = function ( $name, array $options, $current ) {
	echo '<select name="digifix_tc[' . esc_attr( $name ) . ']">';
	foreach ( $options as $value => $label ) {
		echo '<option value="' . esc_attr( $value ) . '"' . selected( (string) $current, (string) $value, false ) . '>' . esc_html( $label ) . '</option>';
	}
	echo '</select>';
};
$digifix_tc_days_opt = array_combine( $digifix_tc_days, array_map( 'ucfirst', $digifix_tc_days ) );
$digifix_tc_check    = function ( $name, $label ) use ( $digifix_tc_s ) {
	echo '<label><input type="checkbox" name="digifix_tc[' . esc_attr( $name ) . ']" value="1"' . checked( ! empty( $digifix_tc_s[ $name ] ), true, false ) . '> ' . esc_html( $label ) . '</label>';
};
$digifix_tc_text     = function ( $name, $type = 'text', $placeholder = '', $class = 'regular-text' ) use ( $digifix_tc_s ) {
	echo '<input type="' . esc_attr( $type ) . '" class="' . esc_attr( $class ) . '" name="digifix_tc[' . esc_attr( $name ) . ']" value="' . esc_attr( (string) $digifix_tc_s[ $name ] ) . '" placeholder="' . esc_attr( $placeholder ) . '">';
};
?>
<div class="wrap digifix-tc-wrap">
	<h1>TotalCare Settings</h1>
	<p class="description">Times use the site timezone (<?php echo esc_html( wp_timezone_string() ); ?>).</p>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="digifix_tc_save_settings">
		<?php wp_nonce_field( 'digifix_tc_save_settings' ); ?>

		<div class="digifix-tc-card">
			<h2>Backups</h2>
			<table class="form-table" role="presentation">
				<tr><th>Scheduled backups</th><td><?php $digifix_tc_check( 'backup_enabled', 'Enabled' ); ?></td></tr>
				<tr><th>Frequency</th><td>
					<?php $digifix_tc_select( 'backup_frequency', array( 'twicedaily' => 'Twice daily', 'daily' => 'Daily', 'weekly' => 'Weekly' ), $digifix_tc_s['backup_frequency'] ); ?>
					on <?php $digifix_tc_select( 'backup_day', $digifix_tc_days_opt, $digifix_tc_s['backup_day'] ); ?> <span class="description">(weekly only)</span>
					at <?php $digifix_tc_text( 'backup_time', 'time', '', 'small-text' ); ?>
				</td></tr>
				<tr><th>Full backup every</th><td><?php $digifix_tc_text( 'backup_full_days', 'number', '', 'small-text' ); ?> days <p class="description">In between, backups only contain files changed since the previous backup (the database is always saved in full). 1 = always a full backup.</p></td></tr>
				<?php if ( $digifix_tc_vivid || 'wpvivid' === $digifix_tc_s['backup_engine'] ) : ?>
					<tr><th>Backup engine</th><td><?php $digifix_tc_select( 'backup_engine', array( 'totalcare' => 'TotalCare built-in (recommended)', 'wpvivid' => 'WPvivid (legacy)' ), $digifix_tc_s['backup_engine'] ); ?><p class="description">Backups made by WPvivid before the switch stay available in WPvivid. You can remove WPvivid once enough built-in backups exist.</p></td></tr>
				<?php endif; ?>
			</table>

			<h3>Keep backups</h3>
			<table class="form-table" role="presentation">
				<tr><th>Always keep the newest</th><td><?php $digifix_tc_text( 'retention_min', 'number', '', 'small-text' ); ?> backups</td></tr>
				<tr><th>Keep every backup from the last</th><td><?php $digifix_tc_text( 'retention_all_days', 'number', '', 'small-text' ); ?> days</td></tr>
				<tr><th>Then keep one per day for</th><td><?php $digifix_tc_text( 'retention_daily', 'number', '', 'small-text' ); ?> days</td></tr>
				<tr><th>One per week for</th><td><?php $digifix_tc_text( 'retention_weekly', 'number', '', 'small-text' ); ?> weeks</td></tr>
				<tr><th>One per month for</th><td><?php $digifix_tc_text( 'retention_monthly', 'number', '', 'small-text' ); ?> months</td></tr>
				<tr><th>Backups taken before updates</th><td>keep for <?php $digifix_tc_text( 'retention_update_days', 'number', '', 'small-text' ); ?> days
					<p class="description">Older backups are deleted from storage after each backup. Weekly and monthly copies prefer full backups. Backups that newer ones still need are always kept, and so are pinned backups (Backups page).</p></td></tr>
			</table>

			<h3>What to back up</h3>
			<table class="form-table" role="presentation">
				<tr><th>Exclude files</th><td>
					<textarea name="digifix_tc[backup_excludes]" rows="7" class="large-text code"><?php echo esc_textarea( $digifix_tc_s['backup_excludes'] ); ?></textarea>
					<p class="description">One per line. A name without "/" matches any file or folder with that name (wildcards allowed, e.g. <code>*.log</code>). A path starting with <code>wp-content/…</code> matches that folder. Changing this starts a new full backup.</p>
				</td></tr>
				<tr><th>Database</th><td>
					<?php $digifix_tc_select( 'backup_db_scope', array( 'prefix' => 'Tables of this site only (' . $GLOBALS['wpdb']->prefix . '*)', 'all' => 'Every table in the database' ), $digifix_tc_s['backup_db_scope'] ); ?>
					<p class="description digifix-tc-mt">Skip these tables (one per line), e.g. large log tables:</p>
					<textarea name="digifix_tc[backup_db_tables]" rows="3" class="large-text code"><?php echo esc_textarea( $digifix_tc_s['backup_db_tables'] ); ?></textarea>
				</td></tr>
			</table>

			<h3>Server load</h3>
			<table class="form-table" role="presentation">
				<tr><th>Work per request</th><td><?php $digifix_tc_text( 'request_budget', 'number', '', 'small-text' ); ?> seconds <p class="description">Backups, restores and updates run in short requests. Lower this if the host stops long PHP requests (25 suits most shared hosting).</p></td></tr>
				<tr><th>Low impact</th><td><?php $digifix_tc_check( 'low_impact', 'Run background work in short bursts with pauses (slower, gentler on small servers)' ); ?></td></tr>
				<tr><th>Give up after</th><td><?php $digifix_tc_text( 'backup_timeout_hours', 'number', '', 'small-text' ); ?> hours</td></tr>
			</table>

			<h3>Remote storage</h3>
			<p>
				<?php if ( $digifix_tc_remote ) : ?>
					<?php echo self::badge( 'success', 'Configured' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> Backups go to <strong><?php echo esc_html( $digifix_tc_s['s3_bucket'] . '/' . ( $digifix_tc_s['s3_path'] ? $digifix_tc_s['s3_path'] : Digifix_TC_Bk_Util::default_folder() ) ); ?></strong>. Leave the secret blank to keep the current one.
				<?php elseif ( $digifix_tc_stored && ! $digifix_tc_secret ) : ?>
					<?php echo self::badge( 'error', 'Secret unreadable' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> The stored secret key can no longer be decrypted (wp-content/digifix-tc-data/secret.php changed). Enter it again.
				<?php else : ?>
					<?php echo self::badge( 'error', 'Not configured' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> Enter the bucket details. Saving tests the connection with a small file.
				<?php endif; ?>
			</p>
			<table class="form-table" role="presentation">
				<tr><th>Provider</th><td><?php $digifix_tc_select( 's3_type', array( 'amazons3' => 'Amazon S3', 'r2' => 'Cloudflare R2', 's3compat' => 'Other S3-compatible (Wasabi, Backblaze B2, DigitalOcean Spaces, MinIO)' ), $digifix_tc_s['s3_type'] ); ?></td></tr>
				<tr><th>Access key</th><td><?php $digifix_tc_text( 's3_access' ); ?></td></tr>
				<tr><th>Secret key</th><td><input type="password" class="regular-text" name="digifix_tc[s3_secret]" value="" autocomplete="new-password" placeholder="<?php echo $digifix_tc_stored ? '•••••••• (unchanged)' : ''; ?>"><p class="description">Stored encrypted. The key needs permission to upload, download, list and delete objects in the bucket.</p></td></tr>
				<tr><th>Bucket</th><td><?php $digifix_tc_text( 's3_bucket' ); ?></td></tr>
				<tr><th>Folder</th><td><?php $digifix_tc_text( 's3_path', 'text', Digifix_TC_Bk_Util::default_folder() ); ?><p class="description">Folder inside the bucket. Defaults to the site's domain. Every site needs its own folder: a staging copy must use a different one.</p></td></tr>
				<tr><th>Endpoint</th><td><?php $digifix_tc_text( 's3_endpoint', 'text', 's3.ap-southeast-2.wasabisys.com' ); ?><p class="description">Not needed for Amazon S3. Cloudflare R2: your account ID or <code>https://&lt;account-id&gt;.r2.cloudflarestorage.com</code>. Others: e.g. <code>syd1.digitaloceanspaces.com</code> (add <code>http://</code> for a local MinIO).</p></td></tr>
				<tr><th>Region</th><td><?php $digifix_tc_text( 's3_region', 'text', 'us-east-1', 'small-text' ); ?><p class="description">Other S3-compatible services only (Amazon S3 is detected automatically, R2 needs none).</p></td></tr>
				<tr><th>Addressing</th><td><?php $digifix_tc_check( 's3_path_style', 'Path-style URLs (other S3-compatible services; needed for MinIO)' ); ?></td></tr>
			</table>
		</div>

		<div class="digifix-tc-card">
			<h2>Safe updates</h2>
			<table class="form-table" role="presentation">
				<tr><th>Weekly updates</th><td><?php $digifix_tc_check( 'updates_enabled', 'Enabled' ); ?></td></tr>
				<tr><th>When</th><td>Every <?php $digifix_tc_select( 'update_day', $digifix_tc_days_opt, $digifix_tc_s['update_day'] ); ?> at <?php $digifix_tc_text( 'update_time', 'time', '', 'small-text' ); ?></td></tr>
				<tr><th>Update</th><td>
					<?php $digifix_tc_check( 'update_plugins', 'Plugins' ); ?><br>
					<?php $digifix_tc_check( 'update_themes', 'Themes' ); ?><br>
					WordPress core: <?php $digifix_tc_select( 'update_core', array( 'none' => 'Never', 'minor' => 'Minor releases only (e.g. 6.6.1 → 6.6.2)', 'major' => 'Minor and major releases' ), $digifix_tc_s['update_core'] ); ?>
				</td></tr>
				<tr><th>Never update these plugins</th><td class="digifix-tc-checklist">
					<?php foreach ( $digifix_tc_plugins as $digifix_tc_file => $digifix_tc_data ) : ?>
						<?php if ( DIGIFIX_TC_BASENAME === $digifix_tc_file ) { continue; } ?>
						<label><input type="checkbox" name="digifix_tc[excluded_plugins][]" value="<?php echo esc_attr( $digifix_tc_file ); ?>" <?php checked( in_array( $digifix_tc_file, (array) $digifix_tc_s['excluded_plugins'], true ) ); ?>> <?php echo esc_html( $digifix_tc_data['Name'] ); ?></label>
					<?php endforeach; ?>
				</td></tr>
				<tr><th>Never update these themes</th><td class="digifix-tc-checklist">
					<?php foreach ( $digifix_tc_themes as $digifix_tc_slug => $digifix_tc_theme ) : ?>
						<label><input type="checkbox" name="digifix_tc[excluded_themes][]" value="<?php echo esc_attr( $digifix_tc_slug ); ?>" <?php checked( in_array( $digifix_tc_slug, (array) $digifix_tc_s['excluded_themes'], true ) ); ?>> <?php echo esc_html( $digifix_tc_theme->get( 'Name' ) ); ?></label>
					<?php endforeach; ?>
				</td></tr>
				<tr><th>Extra pages to check</th><td>
					<textarea name="digifix_tc[health_urls]" rows="4" class="large-text code" placeholder="<?php echo esc_attr( home_url( '/contact/' ) ); ?>"><?php echo esc_textarea( $digifix_tc_s['health_urls'] ); ?></textarea>
					<p class="description">One URL per line on this site (e.g. shop, checkout, contact form). The home page and login page are always checked.</p>
				</td></tr>
				<tr><th>Strict checks</th><td><?php $digifix_tc_check( 'health_strict', 'Also roll back when a page title changes or a page size changes by more than 50%' ); ?></td></tr>
				<tr><th>Keep rollback copies for</th><td><?php $digifix_tc_text( 'snapshot_keep_days', 'number', '', 'small-text' ); ?> days</td></tr>
			</table>
		</div>

		<div class="digifix-tc-card">
			<h2>Malware scans</h2>
			<table class="form-table" role="presentation">
				<tr><th>Scheduled scans</th><td><?php $digifix_tc_check( 'scan_enabled', 'Enabled' ); ?></td></tr>
				<tr><th>When</th><td>
					<?php $digifix_tc_select( 'scan_frequency', array( 'daily' => 'Daily', 'weekly' => 'Weekly' ), $digifix_tc_s['scan_frequency'] ); ?>
					on <?php $digifix_tc_select( 'scan_day', $digifix_tc_days_opt, $digifix_tc_s['scan_day'] ); ?> <span class="description">(weekly only)</span>
					at <?php $digifix_tc_text( 'scan_time', 'time', '', 'small-text' ); ?>
				</td></tr>
				<tr><th>Scan type</th><td><?php $digifix_tc_select( 'scan_type', array( 'standard' => 'Standard (full malware scan)', 'quick' => 'Quick' ), $digifix_tc_s['scan_type'] ); ?></td></tr>
				<tr><th>Alert immediately at</th><td><?php $digifix_tc_select( 'scan_alert_severity', array( '100' => 'Critical issues', '75' => 'High and above', '50' => 'Medium and above', '25' => 'Low and above' ), $digifix_tc_s['scan_alert_severity'] ); ?></td></tr>
			</table>
		</div>

		<div class="digifix-tc-card">
			<h2>Reporting</h2>
			<table class="form-table" role="presentation">
				<tr><th>Client name</th><td><?php $digifix_tc_text( 'client_name' ); ?></td></tr>
				<tr><th>Digifix recipients</th><td><textarea name="digifix_tc[internal_emails]" rows="2" class="large-text"><?php echo esc_textarea( $digifix_tc_s['internal_emails'] ); ?></textarea><p class="description">Get every alert and summary. Separate addresses with commas.</p></td></tr>
				<tr><th>Client recipients</th><td><textarea name="digifix_tc[client_emails]" rows="2" class="large-text"><?php echo esc_textarea( $digifix_tc_s['client_emails'] ); ?></textarea><p class="description">Get the monthly report (and summaries if enabled below).</p></td></tr>
				<tr><th>Emails</th><td>
					<?php $digifix_tc_check( 'email_failures', 'Alert Digifix immediately on failures, rollbacks, restores and security issues' ); ?><br>
					<?php $digifix_tc_check( 'email_update_summary', 'Send a summary after each update run that changed something' ); ?><br>
					<?php $digifix_tc_check( 'email_scan_summary', 'Send a summary after every scan' ); ?><br>
					<?php $digifix_tc_check( 'client_gets_summaries', 'Also send update and scan summaries to the client' ); ?><br>
					<?php $digifix_tc_check( 'monthly_report', 'Send the monthly report on the 1st' ); ?><br>
					<?php $digifix_tc_check( 'monthly_attach_pdf', 'Attach the PDF to the monthly report' ); ?><?php echo Digifix_TC_PDF::available() ? '' : ' <span class="description">(needs dompdf: run composer install)</span>'; ?>
				</td></tr>
				<tr><th>Brand colour</th><td><?php $digifix_tc_text( 'brand_color', 'color', '', '' ); ?></td></tr>
				<tr><th>Webhook URL</th><td><?php $digifix_tc_text( 'webhook_url', 'url', 'https://hooks.slack.com/services/… or https://n8n.example.com/webhook/…', 'large-text' ); ?><p class="description">Every event is POSTed as JSON. Slack incoming webhooks get a readable message.</p></td></tr>
				<tr><th>Webhook secret</th><td><input type="password" class="regular-text" name="digifix_tc[webhook_secret]" value="" autocomplete="new-password" placeholder="<?php echo $digifix_tc_s['webhook_secret'] ? '•••••••• (unchanged)' : 'optional'; ?>"><p class="description">Signs each request: header <code>X-Digifix-TC-Signature: sha256=HMAC(body, secret)</code>.</p></td></tr>
			</table>
		</div>

		<?php submit_button( 'Save settings' ); ?>
	</form>

	<div class="digifix-tc-card">
		<h2>Tests</h2>
		<?php self::form( 'digifix_tc_test_email', 'Send test email' ); ?>
		<?php self::form( 'digifix_tc_test_webhook', 'Send test webhook' ); ?>
		<?php self::form( 'digifix_tc_probe', 'Test background requests' ); ?>
		<p class="description">Checks that the server lets TotalCare's background requests finish (takes about 10 seconds).</p>
	</div>
</div>
