<?php
/**
 * Loads the backup engine. Used by TotalCare and, for restores, by the
 * guardian before regular plugins load.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'Digifix_TC_Bk_Util', false ) ) {
	$digifix_tc_bk_dir = __DIR__;
	foreach ( array( 'util', 's3', 'gz-writer', 'tar', 'areas', 'remote', 'db-dump', 'db-import', 'replace', 'file-backup', 'retention', 'restore-engine' ) as $digifix_tc_bk_file ) {
		require_once $digifix_tc_bk_dir . '/class-digifix-tc-bk-' . $digifix_tc_bk_file . '.php';
	}
	unset( $digifix_tc_bk_dir, $digifix_tc_bk_file );
}
