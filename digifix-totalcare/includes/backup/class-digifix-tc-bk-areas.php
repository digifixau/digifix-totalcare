<?php
/**
 * The parts of a site that are backed up as separate archives, and the
 * exclusion rules.
 *
 * Paths inside an archive are relative to the area root, so a site whose
 * plugins or uploads live somewhere else can still be restored. Exclusion
 * patterns use "logical" paths (wp-content/plugins/... regardless of where
 * the folder really is).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Areas {

	const ORDER = array( 'core', 'content', 'plugins', 'themes', 'muplugins', 'uploads' );

	const LABELS = array(
		'core'      => 'WordPress core',
		'content'   => 'Other wp-content files',
		'plugins'   => 'Plugins',
		'themes'    => 'Themes',
		'muplugins' => 'Must-use plugins',
		'uploads'   => 'Uploads (media)',
	);

	const LOGICAL = array(
		'core'      => '',
		'content'   => 'wp-content',
		'plugins'   => 'wp-content/plugins',
		'themes'    => 'wp-content/themes',
		'muplugins' => 'wp-content/mu-plugins',
		'uploads'   => 'wp-content/uploads',
	);

	/** Code areas: restored behind the maintenance page, cleanable. */
	const CODE = array( 'core', 'plugins', 'themes', 'muplugins', 'content' );

	/** Already-compressed formats are stored, not deflated again. */
	const STORED_EXT = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif', 'mp4', 'm4v', 'mov', 'webm', 'mkv', 'avi', 'mp3', 'm4a', 'aac', 'ogg', 'oga', 'opus', 'flac', 'zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'zst', 'br', 'woff', 'woff2', 'pdf', 'docx', 'xlsx', 'pptx', 'odt', 'ods', 'jar', 'epub', 'mpg', 'mpeg', 'wmv', 'flv' );

	/** @return array<string,string> area => absolute root ('' if not available). */
	public static function roots() {
		$uploads = wp_upload_dir( null, false );
		$roots   = array(
			'core'      => untrailingslashit( ABSPATH ),
			'content'   => untrailingslashit( WP_CONTENT_DIR ),
			'plugins'   => untrailingslashit( WP_PLUGIN_DIR ),
			'themes'    => untrailingslashit( get_theme_root() ),
			'muplugins' => untrailingslashit( WPMU_PLUGIN_DIR ),
			'uploads'   => untrailingslashit( (string) ( $uploads['basedir'] ?? '' ) ),
		);
		foreach ( $roots as $area => $dir ) {
			// A symlinked mu-plugins folder is host-managed (e.g. Hostinger).
			if ( '' === $dir || ! is_dir( $dir ) || ( 'muplugins' === $area && is_link( $dir ) ) ) {
				$roots[ $area ] = '';
			}
		}
		return $roots;
	}

	public static function logical( $area, $rel ) {
		$prefix = self::LOGICAL[ $area ] ?? $area;
		return '' === $prefix ? $rel : ( '' === $rel ? $prefix : $prefix . '/' . $rel );
	}

	public static function default_excludes() {
		return implode(
			"\n",
			array(
				'wp-content/cache',
				'wp-content/upgrade',
				'wp-content/upgrade-temp-backup',
				'wp-content/litespeed',
				'wp-content/et-cache',
				'wp-content/wflogs',
				'wp-content/wpvividbackups',
				'wp-content/wpvivid*',
				'wp-content/updraft',
				'wp-content/ai1wm-backups',
				'wp-content/backups-dup-*',
				'wp-content/backup-db',
				'wp-content/uploads/backwpup-*',
				'node_modules',
				'.git',
				'*.log',
				'error_log',
				'.DS_Store',
			)
		);
	}

	/** Physical folders never backed up or restored. */
	public static function protected_dirs() {
		return array(
			untrailingslashit( WP_CONTENT_DIR ) . '/dtc-data',
			untrailingslashit( WP_CONTENT_DIR ) . '/dtc-rollback',
		);
	}

	/** @return string[] */
	public static function patterns( $text ) {
		$out = array();
		foreach ( preg_split( '/\R/', (string) $text ) as $line ) {
			$line = trim( trim( $line ), '/' );
			if ( '' !== $line && '#' !== $line[0] ) {
				$out[] = $line;
			}
		}
		return $out;
	}

	public static function is_excluded( array $patterns, $logical, $name ) {
		foreach ( $patterns as $p ) {
			if ( false === strpos( $p, '/' ) ) {
				if ( $p === $name || fnmatch( $p, $name ) ) {
					return true;
				}
			} elseif ( $logical === $p || 0 === strpos( $logical, $p . '/' ) || fnmatch( $p, $logical ) ) {
				return true;
			}
		}
		return false;
	}

	public static function compressible( $name ) {
		$dot = strrpos( $name, '.' );
		return false === $dot || ! in_array( strtolower( substr( $name, $dot + 1 ) ), self::STORED_EXT, true );
	}
}
