<?php
/**
 * HTML email layout. Inline styles for email client compatibility.
 *
 * @var array  $mail   subject, title, level, intro, sections, button.
 * @var string $brand  Brand colour.
 * @var string $client Client name.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$dtc_levels = array(
	'success' => array( '#e7f6ec', '#1e7e34', 'All good' ),
	'warning' => array( '#fff6e0', '#a06200', 'Attention' ),
	'error'   => array( '#fdecea', '#b3261e', 'Action needed' ),
	'info'    => array( '#e8f0fb', '#0b5cab', 'Report' ),
);
$dtc_level  = $dtc_levels[ $mail['level'] ] ?? $dtc_levels['info'];
$dtc_site   = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
?>
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width"><title><?php echo esc_html( $mail['subject'] ); ?></title></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;color:#1f2937;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:24px 12px;">
<tr><td align="center">
	<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:640px;background:#ffffff;border-radius:8px;overflow:hidden;">
		<tr><td style="background:<?php echo esc_attr( $brand ); ?>;padding:20px 28px;color:#ffffff;">
			<div style="font-size:13px;opacity:.85;">Digifix TotalCare<?php echo $client ? ' · ' . esc_html( $client ) : ''; ?></div>
			<div style="font-size:22px;font-weight:600;margin-top:4px;"><?php echo esc_html( $mail['title'] ); ?></div>
			<div style="font-size:14px;opacity:.9;margin-top:2px;"><?php echo esc_html( $dtc_site ); ?> — <?php echo esc_html( preg_replace( '#^https?://#', '', home_url() ) ); ?></div>
		</td></tr>
		<tr><td style="padding:24px 28px 8px;">
			<div style="display:inline-block;background:<?php echo esc_attr( $dtc_level[0] ); ?>;color:<?php echo esc_attr( $dtc_level[1] ); ?>;font-size:12px;font-weight:600;padding:4px 10px;border-radius:999px;text-transform:uppercase;letter-spacing:.04em;"><?php echo esc_html( $dtc_level[2] ); ?></div>
			<?php if ( $mail['intro'] ) : ?>
				<p style="font-size:15px;line-height:1.55;margin:14px 0 0;"><?php echo esc_html( $mail['intro'] ); ?></p>
			<?php endif; ?>
		</td></tr>
		<?php foreach ( $mail['sections'] as $dtc_section ) : ?>
			<tr><td style="padding:16px 28px 0;">
				<h3 style="font-size:14px;margin:8px 0 8px;color:#374151;text-transform:uppercase;letter-spacing:.04em;"><?php echo esc_html( $dtc_section['heading'] ); ?></h3>
				<?php if ( ! empty( $dtc_section['rows'] ) ) : ?>
					<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:14px;border-collapse:collapse;">
						<?php foreach ( $dtc_section['rows'] as $dtc_row ) : ?>
							<tr>
								<td style="padding:7px 0;border-bottom:1px solid #e5e7eb;color:#6b7280;width:40%;vertical-align:top;"><?php echo esc_html( $dtc_row[0] ); ?></td>
								<td style="padding:7px 0;border-bottom:1px solid #e5e7eb;vertical-align:top;word-break:break-word;"><?php echo esc_html( $dtc_row[1] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php elseif ( ! empty( $dtc_section['table'] ) ) : ?>
					<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="font-size:13px;border-collapse:collapse;">
						<tr>
							<?php foreach ( $dtc_section['table']['head'] as $dtc_head ) : ?>
								<th align="left" style="padding:7px 6px;background:#f9fafb;border-bottom:1px solid #e5e7eb;color:#6b7280;font-weight:600;"><?php echo esc_html( $dtc_head ); ?></th>
							<?php endforeach; ?>
						</tr>
						<?php foreach ( $dtc_section['table']['rows'] as $dtc_row ) : ?>
							<tr>
								<?php foreach ( $dtc_row as $dtc_cell ) : ?>
									<td style="padding:7px 6px;border-bottom:1px solid #e5e7eb;vertical-align:top;word-break:break-word;"><?php echo esc_html( $dtc_cell ); ?></td>
								<?php endforeach; ?>
							</tr>
						<?php endforeach; ?>
					</table>
				<?php else : ?>
					<p style="font-size:14px;color:#6b7280;margin:0;">Nothing to report.</p>
				<?php endif; ?>
			</td></tr>
		<?php endforeach; ?>
		<tr><td style="padding:24px 28px;">
			<?php
			$dtc_button = $mail['button'] ?: array( 'Open TotalCare dashboard', admin_url( 'admin.php?page=dtc-dashboard' ) );
			?>
			<a href="<?php echo esc_url( $dtc_button[1] ); ?>" style="display:inline-block;background:<?php echo esc_attr( $brand ); ?>;color:#ffffff;text-decoration:none;font-size:14px;font-weight:600;padding:10px 18px;border-radius:6px;"><?php echo esc_html( $dtc_button[0] ); ?></a>
		</td></tr>
		<tr><td style="padding:14px 28px;background:#f9fafb;color:#9ca3af;font-size:12px;">
			Sent by Digifix TotalCare on <?php echo esc_html( wp_date( 'j M Y, g:i a' ) ); ?>.
		</td></tr>
	</table>
</td></tr>
</table>
</body>
</html>
