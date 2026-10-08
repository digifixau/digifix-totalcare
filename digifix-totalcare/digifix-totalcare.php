<?php
/**
 * Plugin Name:       Digifix TotalCare
 * Plugin URI:        https://digifix.com.au/
 * Description:       Automated site care: incremental backups to S3 or Cloudflare R2 with one-click restore, weekly safe updates with health checks and automatic rollback, scheduled Wordfence scans and client reporting.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Digifix
 * Author URI:        https://digifix.com.au/
 * License:           GPL-2.0-or-later
 * Text Domain:       digifix-totalcare
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DTC_VERSION', '1.1.0' );
define( 'DTC_DB_VERSION', '1' );
define( 'DTC_FILE', __FILE__ );
define( 'DTC_DIR', plugin_dir_path( __FILE__ ) );
define( 'DTC_URL', plugin_dir_url( __FILE__ ) );
define( 'DTC_BASENAME', plugin_basename( __FILE__ ) );

if ( file_exists( DTC_DIR . 'vendor/autoload.php' ) ) {
	require_once DTC_DIR . 'vendor/autoload.php';
}

require_once DTC_DIR . 'includes/backup/loader.php';
require_once DTC_DIR . 'includes/class-dtc-storage.php';
require_once DTC_DIR . 'includes/class-dtc-settings.php';
require_once DTC_DIR . 'includes/class-dtc-logger.php';
require_once DTC_DIR . 'includes/class-dtc-job.php';
require_once DTC_DIR . 'includes/class-dtc-jobs.php';
require_once DTC_DIR . 'includes/class-dtc-scheduler.php';
require_once DTC_DIR . 'includes/class-dtc-installer.php';
require_once DTC_DIR . 'includes/integrations/class-dtc-wpvivid.php';
require_once DTC_DIR . 'includes/integrations/class-dtc-wordfence.php';
require_once DTC_DIR . 'includes/class-dtc-health-check.php';
require_once DTC_DIR . 'includes/class-dtc-snapshot.php';
require_once DTC_DIR . 'includes/class-dtc-restore.php';
require_once DTC_DIR . 'includes/class-dtc-backup-runner.php';
require_once DTC_DIR . 'includes/class-dtc-backup-service.php';
require_once DTC_DIR . 'includes/class-dtc-update-service.php';
require_once DTC_DIR . 'includes/class-dtc-scan-service.php';
require_once DTC_DIR . 'includes/reporting/class-dtc-report-builder.php';
require_once DTC_DIR . 'includes/reporting/class-dtc-email.php';
require_once DTC_DIR . 'includes/reporting/class-dtc-webhook.php';
require_once DTC_DIR . 'includes/reporting/class-dtc-pdf.php';
require_once DTC_DIR . 'includes/reporting/class-dtc-notifier.php';
require_once DTC_DIR . 'includes/class-dtc-updater.php';
require_once DTC_DIR . 'includes/class-dtc-plugin.php';

if ( is_admin() ) {
	require_once DTC_DIR . 'admin/class-dtc-admin.php';
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once DTC_DIR . 'includes/class-dtc-cli.php';
}

register_activation_hook( __FILE__, array( 'DTC_Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DTC_Installer', 'deactivate' ) );

DTC_Plugin::instance()->boot();
