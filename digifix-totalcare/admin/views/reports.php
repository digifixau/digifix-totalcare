<?php
/**
 * Reports view. Included from DTC_Admin::page_reports() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dtc_months = array();
$dtc_cursor = new DateTimeImmutable( 'first day of this month', wp_timezone() );
for ( $dtc_i = 0; $dtc_i < 12; $dtc_i++ ) {
	$dtc_months[ $dtc_cursor->format( 'Y-m' ) ] = $dtc_cursor->format( 'F Y' ) . ( 0 === $dtc_i ? ' (so far)' : '' );
	$dtc_cursor                                 = $dtc_cursor->modify( '-1 month' );
}
$dtc_default = ( new DateTimeImmutable( 'first day of last month', wp_timezone() ) )->format( 'Y-m' );
?>
<div class="wrap dtc-wrap">
	<h1>Reports</h1>

	<div class="dtc-card">
		<h2>Monthly care report</h2>
		<p>A client-facing summary of backups, updates, incidents handled and security scans for a month.
			<?php if ( DTC_Settings::get( 'monthly_report' ) ) : ?>
				It is emailed automatically on the 1st of each month (next: <?php echo esc_html( self::when( wp_next_scheduled( DTC_Scheduler::HOOK_MONTHLY ) ) ); ?>).
			<?php endif; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" class="dtc-report-form">
			<input type="hidden" name="action" value="dtc_report">
			<?php wp_nonce_field( 'dtc_report' ); ?>
			<select name="month">
				<?php foreach ( $dtc_months as $dtc_key => $dtc_label ) : ?>
					<option value="<?php echo esc_attr( $dtc_key ); ?>" <?php selected( $dtc_key, $dtc_default ); ?>><?php echo esc_html( $dtc_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button" name="mode" value="html" onclick="this.form.target='_blank'">View</button>
			<button class="button button-primary" name="mode" value="pdf" onclick="this.form.target='_blank'"><?php echo DTC_PDF::available() ? 'Download PDF' : 'Printable version'; ?></button>
			<button class="button" name="mode" value="email" onclick="this.form.target='_self'; return confirm('Email this report to the internal and client recipients?');">Email report</button>
		</form>
		<?php if ( ! DTC_PDF::available() ) : ?>
			<p class="description dtc-mt">PDF files need dompdf. Run <code>composer install</code> in the plugin folder (or use a release build that includes <code>vendor/</code>). Until then, use the printable version and your browser's "Save as PDF".</p>
		<?php endif; ?>
	</div>
</div>
