<?php
/**
 * Remove TotalCare data. Backups in remote storage (and WPvivid's) are left untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dtc_jobs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}dtc_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

// Temporary and pre-restore copies of tables left by a restore.
foreach ( array_merge( (array) $wpdb->get_col( "SHOW TABLES LIKE 'dtcr\\_%'" ), (array) $wpdb->get_col( "SHOW TABLES LIKE 'dtcold\\_%'" ) ) as $dtc_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( 'DROP TABLE IF EXISTS `' . str_replace( '`', '``', $dtc_table ) . '`' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.NotPrepared
}

foreach ( array( 'dtc_settings', 'dtc_db_version', 'dtc_last_tick', 'dtc_guardian_error', 'dtc_update_skip', 'dtc_wpvivid_results', 'dtc_s3_secret', 'dtc_site_uuid', 'dtc_installed_version' ) as $dtc_option ) {
	delete_option( $dtc_option );
}

// LiteSpeed noabort rule added by the installer.
$dtc_htaccess = ABSPATH . '.htaccess';
if ( file_exists( $dtc_htaccess ) && is_writable( $dtc_htaccess ) ) {
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	insert_with_markers( $dtc_htaccess, 'Digifix TotalCare', array() );
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
