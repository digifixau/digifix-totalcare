<?php
/**
 * Activity log view. Included from DTC_Admin::page_log() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$dtc_type  = sanitize_key( $_GET['type'] ?? '' );
$dtc_level = sanitize_key( $_GET['level'] ?? '' );
$dtc_page  = max( 1, (int) ( $_GET['paged'] ?? 1 ) );
// phpcs:enable

$dtc_res   = DTC_Logger::query(
	array(
		'type'     => $dtc_type,
		'level'    => $dtc_level,
		'per_page' => 50,
		'page'     => $dtc_page,
	)
);
$dtc_pages = (int) ceil( $dtc_res['total'] / 50 );
?>
<div class="wrap dtc-wrap">
	<h1>Activity Log</h1>

	<form method="get" class="dtc-filters">
		<input type="hidden" name="page" value="dtc-log">
		<select name="type">
			<option value="">All types</option>
			<?php foreach ( array( 'backup', 'update', 'scan', 'restore', 'report', 'system' ) as $dtc_t ) : ?>
				<option value="<?php echo esc_attr( $dtc_t ); ?>" <?php selected( $dtc_type, $dtc_t ); ?>><?php echo esc_html( ucfirst( $dtc_t ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="level">
			<option value="">All levels</option>
			<?php foreach ( array( 'success', 'info', 'warning', 'error' ) as $dtc_l ) : ?>
				<option value="<?php echo esc_attr( $dtc_l ); ?>" <?php selected( $dtc_level, $dtc_l ); ?>><?php echo esc_html( ucfirst( $dtc_l ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button">Filter</button>
		<span class="description"><?php echo (int) $dtc_res['total']; ?> events</span>
	</form>

	<table class="widefat striped dtc-events">
		<thead><tr><th>Time</th><th>Type</th><th>Event</th><th>Message</th><th>Job</th><th>Details</th></tr></thead>
		<tbody>
		<?php foreach ( $dtc_res['rows'] as $dtc_e ) : ?>
			<tr>
				<td class="dtc-nowrap"><?php echo esc_html( get_date_from_gmt( $dtc_e['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
				<td><?php echo self::badge( $dtc_e['level'], $dtc_e['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				<td><code><?php echo esc_html( $dtc_e['code'] ); ?></code></td>
				<td><?php echo esc_html( $dtc_e['message'] ); ?></td>
				<td><?php echo $dtc_e['job_id'] ? '#' . (int) $dtc_e['job_id'] : ''; ?></td>
				<td>
					<?php if ( $dtc_e['context'] ) : ?>
						<details><summary>View</summary><pre class="dtc-json"><?php echo esc_html( wp_json_encode( $dtc_e['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></details>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $dtc_res['rows'] ) : ?>
			<tr><td colspan="6">No events.</td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $dtc_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $dtc_page,
						'total'   => $dtc_pages,
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
