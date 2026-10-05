<?php
/**
 * Remove TotalCare data. WPvivid backups and the S3 remote are left untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dtc_jobs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dtc_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

foreach ( array( 'dtc_settings', 'dtc_db_version', 'dtc_last_tick', 'dtc_guardian_error', 'dtc_update_skip', 'dtc_wpvivid_results' ) as $dtc_option ) {
	delete_option( $dtc_option );
}

$dtc_guardian = WPMU_PLUGIN_DIR . '/dtc-guardian.php';
if ( file_exists( $dtc_guardian ) ) {
	@unlink( $dtc_guardian ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
$dtc_dropin = WP_CONTENT_DIR . '/fatal-error-handler.php';
if ( file_exists( $dtc_dropin ) && false !== strpos( (string) file_get_contents( $dtc_dropin ), 'DTC-GUARDIAN-LOADER' ) ) {
	@unlink( $dtc_dropin ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}

foreach ( array( WP_CONTENT_DIR . '/dtc-data', WP_CONTENT_DIR . '/dtc-rollback' ) as $dtc_dir ) {
	if ( ! is_dir( $dtc_dir ) ) {
		continue;
	}
	$dtc_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dtc_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $dtc_it as $dtc_item ) {
		$dtc_item->isDir() ? @rmdir( $dtc_item->getPathname() ) : @unlink( $dtc_item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
	@rmdir( $dtc_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
}
