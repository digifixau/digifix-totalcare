<?php
/**
 * Plugin Name:       Digifix TotalCare
 * Description:       Automated site care: incremental backups to S3 or Cloudflare R2 with one-click restore, weekly safe updates with health checks and automatic rollback, scheduled Wordfence scans and client reporting.
 * Version:           1.2.1
 * Requires at least: 6.2
 * Requires PHP:      7.4
 * Author:            Digifix
 * Author URI:        https://digifix.com.au/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       digifix-totalcare
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIGIFIX_TC_VERSION', '1.2.1' );
define( 'DIGIFIX_TC_DB_VERSION', '1' );
define( 'DIGIFIX_TC_FILE', __FILE__ );
define( 'DIGIFIX_TC_DIR', plugin_dir_path( __FILE__ ) );
define( 'DIGIFIX_TC_URL', plugin_dir_url( __FILE__ ) );
define( 'DIGIFIX_TC_BASENAME', plugin_basename( __FILE__ ) );

if ( file_exists( DIGIFIX_TC_DIR . 'vendor/autoload.php' ) ) {
	require_once DIGIFIX_TC_DIR . 'vendor/autoload.php';
}

require_once DIGIFIX_TC_DIR . 'includes/backup/loader.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-storage.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-settings.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-logger.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-job.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-jobs.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-scheduler.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-installer.php';
require_once DIGIFIX_TC_DIR . 'includes/integrations/class-digifix-tc-wpvivid.php';
require_once DIGIFIX_TC_DIR . 'includes/integrations/class-digifix-tc-wordfence.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-health-check.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-snapshot.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-restore.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-backup-runner.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-backup-service.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-update-service.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-scan-service.php';
require_once DIGIFIX_TC_DIR . 'includes/reporting/class-digifix-tc-report-builder.php';
require_once DIGIFIX_TC_DIR . 'includes/reporting/class-digifix-tc-email.php';
require_once DIGIFIX_TC_DIR . 'includes/reporting/class-digifix-tc-webhook.php';
require_once DIGIFIX_TC_DIR . 'includes/reporting/class-digifix-tc-pdf.php';
require_once DIGIFIX_TC_DIR . 'includes/reporting/class-digifix-tc-notifier.php';
require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-plugin.php';

if ( is_admin() ) {
	require_once DIGIFIX_TC_DIR . 'admin/class-digifix-tc-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DIGIFIX_TC_DIR . 'includes/class-digifix-tc-cli.php';
}

register_activation_hook( __FILE__, array( 'Digifix_TC_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Digifix_TC_Installer', 'deactivate' ) );

Digifix_TC_Plugin::instance()->boot();
