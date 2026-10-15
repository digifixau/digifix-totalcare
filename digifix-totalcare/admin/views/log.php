<?php
/**
 * Activity log view. Included from Digifix_TC_Admin::page_log() (class scope).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
$digifix_tc_type  = sanitize_key( $_GET['type'] ?? '' );
$digifix_tc_level = sanitize_key( $_GET['level'] ?? '' );
$digifix_tc_page  = max( 1, absint( $_GET['paged'] ?? 1 ) );
// phpcs:enable

$digifix_tc_res   = Digifix_TC_Logger::query(
	array(
		'type'     => $digifix_tc_type,
		'level'    => $digifix_tc_level,
		'per_page' => 50,
		'page'     => $digifix_tc_page,
	)
);
$digifix_tc_pages = (int) ceil( $digifix_tc_res['total'] / 50 );
?>
<div class="wrap digifix-tc-wrap">
	<h1>Activity Log</h1>

	<form method="get" class="digifix-tc-filters">
		<input type="hidden" name="page" value="digifix-tc-log">
		<select name="type">
			<option value="">All types</option>
			<?php foreach ( array( 'backup', 'update', 'scan', 'restore', 'report', 'system' ) as $digifix_tc_t ) : ?>
				<option value="<?php echo esc_attr( $digifix_tc_t ); ?>" <?php selected( $digifix_tc_type, $digifix_tc_t ); ?>><?php echo esc_html( ucfirst( $digifix_tc_t ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<select name="level">
			<option value="">All levels</option>
			<?php foreach ( array( 'success', 'info', 'warning', 'error' ) as $digifix_tc_l ) : ?>
				<option value="<?php echo esc_attr( $digifix_tc_l ); ?>" <?php selected( $digifix_tc_level, $digifix_tc_l ); ?>><?php echo esc_html( ucfirst( $digifix_tc_l ) ); ?></option>
			<?php endforeach; ?>
		</select>
		<button class="button">Filter</button>
		<span class="description"><?php echo (int) $digifix_tc_res['total']; ?> events</span>
	</form>

	<table class="widefat striped digifix-tc-events">
		<thead><tr><th>Time</th><th>Type</th><th>Event</th><th>Message</th><th>Job</th><th>Details</th></tr></thead>
		<tbody>
		<?php foreach ( $digifix_tc_res['rows'] as $digifix_tc_e ) : ?>
			<tr>
				<td class="digifix-tc-nowrap"><?php echo esc_html( get_date_from_gmt( $digifix_tc_e['created_at'], 'Y-m-d H:i:s' ) ); ?></td>
				<td><?php echo self::badge( $digifix_tc_e['level'], $digifix_tc_e['type'] ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></td>
				<td><code><?php echo esc_html( $digifix_tc_e['code'] ); ?></code></td>
				<td><?php echo esc_html( $digifix_tc_e['message'] ); ?></td>
				<td><?php echo $digifix_tc_e['job_id'] ? '#' . (int) $digifix_tc_e['job_id'] : ''; ?></td>
				<td>
					<?php if ( $digifix_tc_e['context'] ) : ?>
						<details><summary>View</summary><pre class="digifix-tc-json"><?php echo esc_html( wp_json_encode( $digifix_tc_e['context'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) ); ?></pre></details>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
		<?php if ( ! $digifix_tc_res['rows'] ) : ?>
			<tr><td colspan="6">No events.</td></tr>
		<?php endif; ?>
		</tbody>
	</table>

	<?php if ( $digifix_tc_pages > 1 ) : ?>
		<div class="tablenav"><div class="tablenav-pages">
			<?php
			echo wp_kses_post(
				paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => $digifix_tc_page,
						'total'   => $digifix_tc_pages,
					)
				)
			);
			?>
		</div></div>
	<?php endif; ?>
</div>
