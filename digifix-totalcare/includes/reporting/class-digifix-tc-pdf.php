<?php
/**
 * Client PDF report. Uses dompdf when installed (composer install in the
 * plugin folder); otherwise the HTML version can be printed to PDF from the
 * Reports page.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_PDF {

	public static function available() {
		return class_exists( '\Dompdf\Dompdf' );
	}

	public static function render_html( array $report ) {
		$brand = DTC_Settings::get( 'brand_color' );
		ob_start();
		include DTC_DIR . 'templates/report.php';
		return (string) ob_get_clean();
	}

	/**
	 * @return string|false Absolute path to the generated PDF.
	 */
	public static function generate( array $report ) {
		if ( ! self::available() ) {
			return false;
		}
		DTC_Storage::ensure_dirs();
		$file = DTC_Storage::data_dir() . '/reports/care-report-' . sanitize_file_name( $report['period']['start'] . '-to-' . $report['period']['end'] ) . '.pdf';

		$options = new \Dompdf\Options();
		$options->set( 'isRemoteEnabled', false );
		$options->set( 'defaultFont', 'DejaVu Sans' );
		$dompdf = new \Dompdf\Dompdf( $options );
		$dompdf->loadHtml( self::render_html( $report ) );
		$dompdf->setPaper( 'A4', 'portrait' );
		$dompdf->render();

		return false !== file_put_contents( $file, $dompdf->output() ) ? $file : false;
	}
}
