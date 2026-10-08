<?php
/**
 * Loads the backup engine. Used by TotalCare and, for restores, by the
 * guardian from its pinned copy in wp-content/dtc-data/engine/<version>/.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'DTC_Bk_Util', false ) ) {
	$dtc_bk_dir = __DIR__;
	foreach ( array( 'util', 's3', 'gz-writer', 'tar', 'areas', 'remote', 'db-dump', 'db-import', 'replace', 'file-backup', 'retention', 'restore-engine' ) as $dtc_bk_file ) {
		require_once $dtc_bk_dir . '/class-dtc-bk-' . $dtc_bk_file . '.php';
	}
	unset( $dtc_bk_dir, $dtc_bk_file );
}
