<?php
/**
 * Client PDF report. Uses dompdf when installed (composer install in the
 * plugin folder); otherwise the HTML version can be printed to PDF from the
 * Reports page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Digifix_TC_PDF {

	public static function available() {
		return class_exists( '\Dompdf\Dompdf' );
	}

	/** Rules that use the brand colour chosen in the settings. */
	private static function brand_css() {
		$brand = sanitize_hex_color( (string) Digifix_TC_Settings::get( 'brand_color' ) );
		if ( ! $brand ) {
			return '';
		}
		return '.header{background:' . $brand . ';}h2{border-bottom-color:' . $brand . ';}.kpi-value{color:' . $brand . ';}';
	}

	/**
	 * @param bool $browser True for the printable page shown in the browser:
	 *                      the stylesheet and print button script are then
	 *                      registered here and printed by the template.
	 */
	public static function render_html( array $report, $browser = false ) {
		if ( $browser ) {
			wp_register_style( 'digifix-tc-report', DIGIFIX_TC_URL . 'templates/report.css', array(), DIGIFIX_TC_VERSION );
			wp_add_inline_style( 'digifix-tc-report', self::brand_css() );
			Digifix_TC_Admin::register_script();
		}
		ob_start();
		include DIGIFIX_TC_DIR . 'templates/report.php';
		return (string) ob_get_clean();
	}

	/**
	 * @return string|false Absolute path to the generated PDF.
	 */
	public static function generate( array $report ) {
		if ( ! self::available() ) {
			return false;
		}
		Digifix_TC_Storage::ensure_dirs();
		$file = Digifix_TC_Storage::data_dir() . '/reports/care-report-' . sanitize_file_name( $report['period']['start'] . '-to-' . $report['period']['end'] ) . '.pdf';

		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( self::render_html( $report ) );
		// The same stylesheet as the printable page, read from the plugin folder.
		$css = (string) file_get_contents( DIGIFIX_TC_DIR . 'templates/report.css' ) . self::brand_css(); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$dompdf->getCss()->load_css( $css );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		return false !== file_put_contents( $file, $dompdf->output() ) ? $file : false;
	}
}
