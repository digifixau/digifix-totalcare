<?php
/**
 * Settings view. Included from DTC_Admin::page_settings() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$dtc_s       = DTC_Settings::all();
$dtc_remote  = DTC_WPvivid::get_remote();
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
				<tr><th>Give up after</th><td><?php $dtc_text( 'backup_timeout_hours', 'number', '', 'small-text' ); ?> hours</td></tr>
				<tr><th>Retention</th><td><span class="description">Number of backups kept is set in <a href="<?php echo esc_url( admin_url( 'admin.php?page=WPvivid' ) ); ?>">WPvivid → Settings</a> ("Retained backups").</span></td></tr>
			</table>

			<h3>S3 storage</h3>
			<p>
				<?php if ( $dtc_remote ) : ?>
					<?php echo self::badge( 'success', 'Configured' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> Backups go to <strong><?php echo esc_html( $dtc_remote['bucket'] ?? '' ); ?></strong>. Leave the secret blank to keep the current credentials.
				<?php else : ?>
					<?php echo self::badge( 'error', 'Not configured' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?> Enter your S3 details. WPvivid uploads a test file before saving them.
				<?php endif; ?>
			</p>
			<table class="form-table" role="presentation">
				<tr><th>Provider</th><td><?php $dtc_select( 's3_type', array( 'amazons3' => 'Amazon S3', 'r2' => 'Cloudflare R2', 's3compat' => 'Other S3-compatible (DigitalOcean Spaces, Wasabi)' ), $dtc_s['s3_type'] ); ?></td></tr>
				<tr><th>Access key</th><td><?php $dtc_text( 's3_access' ); ?></td></tr>
				<tr><th>Secret key</th><td><input type="password" class="regular-text" name="dtc[s3_secret]" value="" autocomplete="new-password" placeholder="<?php echo $dtc_remote ? '•••••••• (unchanged)' : ''; ?>"><p class="description">Stored by WPvivid only; TotalCare does not keep a copy.</p></td></tr>
				<tr><th>Bucket</th><td><?php $dtc_text( 's3_bucket' ); ?></td></tr>
				<tr><th>Folder</th><td><?php $dtc_text( 's3_path', 'text', sanitize_title( wp_parse_url( home_url(), PHP_URL_HOST ) ) ); ?><p class="description">Folder inside the bucket. Defaults to the site's domain.</p></td></tr>
				<tr><th>Endpoint</th><td><?php $dtc_text( 's3_endpoint', 'text', 's3.ap-southeast-2.wasabisys.com' ); ?><p class="description">Not needed for Amazon S3. Cloudflare R2: your account ID or <code>https://&lt;account-id&gt;.r2.cloudflarestorage.com</code>. Others: e.g. <code>syd1.digitaloceanspaces.com</code>.</p></td></tr>
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
		<h2>Test delivery</h2>
		<?php self::form( 'dtc_test_email', 'Send test email' ); ?>
		<?php self::form( 'dtc_test_webhook', 'Send test webhook' ); ?>
	</div>
</div>
