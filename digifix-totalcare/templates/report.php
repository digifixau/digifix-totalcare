<?php
/**
 * Client care report (PDF via dompdf, or printable HTML).
 * Keep CSS simple: dompdf supports CSS 2.1 and tables, not flexbox/grid.
 *
 * @var array  $report From DTC_Report_Builder::build().
 * @var string $brand  Brand colour.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dtc_r     = $report;
$dtc_issue = $dtc_r['scans']['latest'];
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?php echo esc_html( $dtc_r['site']['name'] . ' — Website care report — ' . $dtc_r['period']['label'] ); ?></title>
<style>
	@page { margin: 28px 34px; }
	body { font-family: "DejaVu Sans", Helvetica, Arial, sans-serif; font-size: 11px; color: #1f2937; margin: 0; }
	.header { background: <?php echo esc_attr( $brand ); ?>; color: #fff; padding: 18px 22px; }
	.header .small { font-size: 10px; opacity: .85; }
	.header h1 { font-size: 20px; margin: 4px 0 2px; }
	h2 { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: #374151; border-bottom: 2px solid <?php echo esc_attr( $brand ); ?>; padding-bottom: 4px; margin: 22px 0 8px; }
	table { width: 100%; border-collapse: collapse; }
	th { text-align: left; background: #f3f4f6; color: #4b5563; font-weight: bold; padding: 6px; border-bottom: 1px solid #e5e7eb; }
	td { padding: 6px; border-bottom: 1px solid #e5e7eb; vertical-align: top; }
	.kpis td { width: 25%; text-align: center; border: 1px solid #e5e7eb; padding: 12px 6px; }
	.kpi-value { font-size: 22px; font-weight: bold; color: <?php echo esc_attr( $brand ); ?>; }
	.kpi-label { font-size: 10px; color: #6b7280; text-transform: uppercase; }
	.ok { color: #1e7e34; font-weight: bold; }
	.bad { color: #b3261e; font-weight: bold; }
	.muted { color: #6b7280; }
	.footer { margin-top: 26px; font-size: 9px; color: #9ca3af; }
	@media print { .noprint { display: none; } }
</style>
</head>
<body>
	<div class="header">
		<div class="small">Digifix TotalCare<?php echo $dtc_r['site']['client'] ? ' · ' . esc_html( $dtc_r['site']['client'] ) : ''; ?></div>
		<h1>Website care report</h1>
		<div><?php echo esc_html( $dtc_r['site']['name'] ); ?> — <?php echo esc_html( preg_replace( '#^https?://#', '', $dtc_r['site']['url'] ) ); ?> · <?php echo esc_html( $dtc_r['period']['label'] ); ?></div>
	</div>

	<h2>At a glance</h2>
	<table class="kpis">
		<tr>
			<td><div class="kpi-value"><?php echo (int) $dtc_r['backups']['completed']; ?></div><div class="kpi-label">Backups</div></td>
			<td><div class="kpi-value"><?php echo count( $dtc_r['updates']['updated'] ); ?></div><div class="kpi-label">Updates applied</div></td>
			<td><div class="kpi-value"><?php echo (int) $dtc_r['scans']['completed']; ?></div><div class="kpi-label">Malware scans</div></td>
			<td><div class="kpi-value"><?php echo (int) $dtc_r['incidents']; ?></div><div class="kpi-label">Incidents handled</div></td>
		</tr>
	</table>

	<h2>Backups</h2>
	<?php if ( $dtc_r['backups']['list'] ) : ?>
		<p class="muted"><?php echo (int) $dtc_r['backups']['completed']; ?> backup(s) stored off-site in S3<?php echo $dtc_r['backups']['failed'] ? ', <span class="bad">' . (int) $dtc_r['backups']['failed'] . ' failed</span>' : ''; ?>.</p>
		<table>
			<tr><th>Date</th><th>Size</th><th>Type</th></tr>
			<?php foreach ( array_slice( array_reverse( $dtc_r['backups']['list'] ), 0, 40 ) as $dtc_b ) : ?>
				<tr><td><?php echo esc_html( $dtc_b['date'] ); ?></td><td><?php echo esc_html( $dtc_b['size'] ); ?></td><td><?php echo $dtc_b['pre_update'] ? 'Before updates' : 'Scheduled'; ?></td></tr>
			<?php endforeach; ?>
		</table>
	<?php else : ?>
		<p class="bad">No completed backups were recorded in this period.</p>
	<?php endif; ?>

	<h2>Updates</h2>
	<?php if ( $dtc_r['updates']['updated'] ) : ?>
		<table>
			<tr><th>Date</th><th>Item</th><th>Type</th><th>Version</th></tr>
			<?php foreach ( $dtc_r['updates']['updated'] as $dtc_u ) : ?>
				<tr><td><?php echo esc_html( $dtc_u['date'] ); ?></td><td><?php echo esc_html( $dtc_u['name'] ); ?></td><td><?php echo esc_html( ucfirst( $dtc_u['kind'] ) ); ?></td><td><?php echo esc_html( $dtc_u['from'] . ' → ' . $dtc_u['to'] ); ?></td></tr>
			<?php endforeach; ?>
		</table>
	<?php else : ?>
		<p class="muted">No updates were needed in this period.</p>
	<?php endif; ?>

	<?php if ( $dtc_r['updates']['rolled_back'] || $dtc_r['restores'] ) : ?>
		<h2>Incidents handled automatically</h2>
		<table>
			<tr><th>Date</th><th>What happened</th><th>Detail</th></tr>
			<?php foreach ( $dtc_r['updates']['rolled_back'] as $dtc_u ) : ?>
				<tr><td><?php echo esc_html( $dtc_u['date'] ); ?></td><td><?php echo esc_html( $dtc_u['name'] . ' ' . $dtc_u['to'] ); ?> rolled back to <?php echo esc_html( $dtc_u['from'] ); ?></td><td class="muted"><?php echo esc_html( wp_trim_words( $dtc_u['reason'], 30 ) ); ?></td></tr>
			<?php endforeach; ?>
			<?php foreach ( $dtc_r['restores'] as $dtc_x ) : ?>
				<tr><td><?php echo esc_html( $dtc_x['date'] ); ?></td><td class="<?php echo $dtc_x['ok'] ? 'ok' : 'bad'; ?>"><?php echo $dtc_x['ok'] ? 'Site restored from backup' : 'Restore failed'; ?></td><td class="muted"><?php echo esc_html( wp_trim_words( $dtc_x['reason'], 30 ) ); ?></td></tr>
			<?php endforeach; ?>
		</table>
	<?php endif; ?>

	<h2>Security scans</h2>
	<?php if ( $dtc_r['scans']['list'] ) : ?>
		<table>
			<tr><th>Date</th><th>Scan</th><th>Open issues</th><th>By severity</th></tr>
			<?php foreach ( $dtc_r['scans']['list'] as $dtc_s ) : ?>
				<tr>
					<td><?php echo esc_html( $dtc_s['date'] ); ?></td>
					<td><?php echo esc_html( ucfirst( $dtc_s['type'] ) ); ?></td>
					<td class="<?php echo $dtc_s['new'] ? 'bad' : 'ok'; ?>"><?php echo (int) $dtc_s['new']; ?></td>
					<td><?php echo esc_html( DTC_Scan_Service::severity_summary( (array) $dtc_s['by_severity'] ) ?: '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
		</table>
		<?php if ( $dtc_issue && $dtc_issue['issues'] ) : ?>
			<p><strong>Open issues from the latest scan</strong></p>
			<table>
				<tr><th>Severity</th><th>Issue</th></tr>
				<?php foreach ( $dtc_issue['issues'] as $dtc_i ) : ?>
					<tr><td><?php echo esc_html( $dtc_i['label'] ); ?></td><td><?php echo esc_html( $dtc_i['message'] ); ?></td></tr>
				<?php endforeach; ?>
			</table>
		<?php endif; ?>
	<?php else : ?>
		<p class="muted">No scans were recorded in this period<?php echo $dtc_r['scans']['failed'] ? ' (' . (int) $dtc_r['scans']['failed'] . ' failed)' : ''; ?>.</p>
	<?php endif; ?>

	<h2>Site details</h2>
	<table>
		<tr><td class="muted" style="width:40%">WordPress version</td><td><?php echo esc_html( $dtc_r['site']['wp_version'] ); ?></td></tr>
		<tr><td class="muted">PHP version</td><td><?php echo esc_html( $dtc_r['site']['php'] ); ?></td></tr>
		<tr><td class="muted">Report period</td><td><?php echo esc_html( $dtc_r['period']['start'] . ' to ' . $dtc_r['period']['end'] ); ?></td></tr>
	</table>

	<div class="footer">Generated by Digifix TotalCare on <?php echo esc_html( $dtc_r['generated'] ); ?>.</div>
</body>
</html>
