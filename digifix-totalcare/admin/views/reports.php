<?php
/**
 * Reports view. Included from Digifix_TC_Admin::page_reports() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$digifix_tc_months = array();
$digifix_tc_cursor = new DateTimeImmutable( 'first day of this month', wp_timezone() );
for ( $digifix_tc_i = 0; $digifix_tc_i < 12; $digifix_tc_i++ ) {
	$digifix_tc_months[ $digifix_tc_cursor->format( 'Y-m' ) ] = $digifix_tc_cursor->format( 'F Y' ) . ( 0 === $digifix_tc_i ? ' (so far)' : '' );
	$digifix_tc_cursor                                 = $digifix_tc_cursor->modify( '-1 month' );
}
$digifix_tc_default = ( new DateTimeImmutable( 'first day of last month', wp_timezone() ) )->format( 'Y-m' );
?>
<div class="wrap digifix-tc-wrap">
	<h1>Reports</h1>

	<div class="digifix-tc-card">
		<h2>Monthly care report</h2>
		<p>A client-facing summary of backups, updates, incidents handled and security scans for a month.
			<?php if ( Digifix_TC_Settings::get( 'monthly_report' ) ) : ?>
				It is emailed automatically on the 1st of each month (next: <?php echo esc_html( self::when( wp_next_scheduled( Digifix_TC_Scheduler::HOOK_MONTHLY ) ) ); ?>).
			<?php endif; ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" target="_blank" class="digifix-tc-report-form">
			<input type="hidden" name="action" value="digifix_tc_report">
			<?php wp_nonce_field( 'digifix_tc_report' ); ?>
			<select name="month">
				<?php foreach ( $digifix_tc_months as $digifix_tc_key => $digifix_tc_label ) : ?>
					<option value="<?php echo esc_attr( $digifix_tc_key ); ?>" <?php selected( $digifix_tc_key, $digifix_tc_default ); ?>><?php echo esc_html( $digifix_tc_label ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="button" name="mode" value="html" onclick="this.form.target='_blank'">View</button>
			<button class="button button-primary" name="mode" value="pdf" onclick="this.form.target='_blank'"><?php echo Digifix_TC_PDF::available() ? 'Download PDF' : 'Printable version'; ?></button>
			<button class="button" name="mode" value="email" onclick="this.form.target='_self'; return confirm('Email this report to the internal and client recipients?');">Email report</button>
		</form>
		<?php if ( ! Digifix_TC_PDF::available() ) : ?>
			<p class="description digifix-tc-mt">PDF files need dompdf. Run <code>composer install</code> in the plugin folder (or use a release build that includes <code>vendor/</code>). Until then, use the printable version and your browser's "Save as PDF".</p>
		<?php endif; ?>
	</div>
</div>
