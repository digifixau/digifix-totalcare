<?php
/**
 * Remove TotalCare data. Backups in remote storage (and WPvivid's) are left untouched.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Removes TotalCare's own tables; there is nothing to cache.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

global $wpdb;

$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}digifix_tc_jobs" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}digifix_tc_events" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange

// Temporary and pre-restore copies of tables left by a restore.
foreach ( array_merge( (array) $wpdb->get_col( "SHOW TABLES LIKE 'dtcr\\_%'" ), (array) $wpdb->get_col( "SHOW TABLES LIKE 'dtcold\\_%'" ) ) as $digifix_tc_table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $digifix_tc_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange
}

foreach ( array( 'digifix_tc_settings', 'digifix_tc_db_version', 'digifix_tc_last_tick', 'digifix_tc_guardian_error', 'digifix_tc_update_skip', 'digifix_tc_wpvivid_results', 'digifix_tc_s3_secret', 'digifix_tc_site_uuid', 'digifix_tc_installed_version' ) as $digifix_tc_option ) {
	delete_option( $digifix_tc_option );
}

// LiteSpeed noabort rule added by the installer.
$digifix_tc_htaccess = ABSPATH . '.htaccess';
if ( file_exists( $digifix_tc_htaccess ) && is_writable( $digifix_tc_htaccess ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_is_writable
	require_once ABSPATH . 'wp-admin/includes/misc.php';
	insert_with_markers( $digifix_tc_htaccess, 'Digifix TotalCare', array() );
}

$digifix_tc_guardian = WPMU_PLUGIN_DIR . '/digifix-tc-guardian.php';
if ( file_exists( $digifix_tc_guardian ) ) {
	wp_delete_file( $digifix_tc_guardian );
}
$digifix_tc_dropin = WP_CONTENT_DIR . '/fatal-error-handler.php';
if ( file_exists( $digifix_tc_dropin ) && false !== strpos( (string) file_get_contents( $digifix_tc_dropin ), 'Plugin Name: Digifix TotalCare Guardian' ) ) {
	wp_delete_file( $digifix_tc_dropin );
}

foreach ( array( WP_CONTENT_DIR . '/digifix-tc-data', WP_CONTENT_DIR . '/digifix-tc-rollback' ) as $digifix_tc_dir ) {
	if ( ! is_dir( $digifix_tc_dir ) ) {
		continue;
	}
	$digifix_tc_it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $digifix_tc_dir, FilesystemIterator::SKIP_DOTS ), RecursiveIteratorIterator::CHILD_FIRST );
	foreach ( $digifix_tc_it as $digifix_tc_item ) {
		$digifix_tc_item->isDir() ? @rmdir( $digifix_tc_item->getPathname() ) : @unlink( $digifix_tc_item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink
	}
	@rmdir( $digifix_tc_dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}
