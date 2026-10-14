<?php
/**
 * Folder snapshots of a single plugin or theme, used for fast per-item
 * rollback without restoring the full WPvivid backup.
 *
 * Before each item is updated an "in-flight" marker is written to
 * digifix-tc-data/inflight.json. If the new code fatals on every request (so the job
 * engine never runs again) the guardian mu-plugin uses this marker to swap the
 * snapshot back in from WordPress's fatal error handler.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Digifix_TC_Snapshot {

	/**
	 * @param string $kind plugin|theme.
	 * @param string $id   Plugin file (folder/file.php) or theme stylesheet.
	 */
	public static function source_path( $kind, $id ) {
		if ( 'theme' === $kind ) {
			return get_theme_root( $id ) . '/' . $id;
		}
		$dir = dirname( $id );
		return '.' === $dir ? WP_PLUGIN_DIR . '/' . $id : WP_PLUGIN_DIR . '/' . $dir;
	}

	/**
	 * Copy the item's files to digifix-tc-rollback/<job>/<kind>-<slug>.
	 *
	 * @return array|WP_Error Snapshot descriptor.
	 */
	public static function create( $job_id, $kind, $id, $version ) {
		Digifix_TC_Storage::ensure_dirs();
		$source = self::source_path( $kind, $id );
		if ( ! file_exists( $source ) ) {
			return new WP_Error( 'digifix_tc_snapshot', 'Source not found: ' . $source );
		}
		$slug = sanitize_file_name( $kind . '-' . str_replace( '/', '_', $id ) . '-' . $version );
		$dest = Digifix_TC_Storage::rollback_dir() . '/' . (int) $job_id . '/' . $slug;
		if ( file_exists( $dest ) ) {
			self::rrmdir( $dest );
		}
		wp_mkdir_p( dirname( $dest ) );

		$ok = is_dir( $source ) ? self::copy_dir( $source, $dest ) : @copy( $source, $dest ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $ok ) {
			return new WP_Error( 'digifix_tc_snapshot', 'Could not copy ' . $source . ' to ' . $dest );
		}
		return array(
			'kind'     => $kind,
			'id'       => $id,
			'version'  => $version,
			'source'   => $source,
			'snapshot' => $dest,
			'created'  => time(),
		);
	}

	/**
	 * Put the snapshot back in place of the current files.
	 *
	 * @return true|WP_Error
	 */
	public static function restore( array $snap ) {
		if ( ! file_exists( $snap['snapshot'] ) ) {
			return new WP_Error( 'digifix_tc_snapshot', 'Snapshot missing: ' . $snap['snapshot'] );
		}
		$source = $snap['source'];
		$broken = $source . '.digifix-tc-failed-' . time();
		if ( file_exists( $source ) && ! @rename( $source, $broken ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return new WP_Error( 'digifix_tc_snapshot', 'Could not move the failed version aside: ' . $source );
		}
		$ok = is_dir( $snap['snapshot'] ) ? self::copy_dir( $snap['snapshot'], $source ) : @copy( $snap['snapshot'], $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( ! $ok ) {
			@rename( $broken, $source ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.rename_rename
			return new WP_Error( 'digifix_tc_snapshot', 'Could not copy the snapshot back to ' . $source );
		}
		self::rrmdir( $broken );
		if ( function_exists( 'opcache_reset' ) ) {
			@opcache_reset(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		wp_clean_plugins_cache( false );
		return true;
	}

	public static function set_inflight( array $snap, $job_id ) {
		$snap['job_id'] = (int) $job_id;
		$snap['status'] = 'updating';
		Digifix_TC_Storage::write( 'inflight', $snap );
	}

	public static function get_inflight() {
		return Digifix_TC_Storage::read( 'inflight' );
	}

	public static function clear_inflight() {
		Digifix_TC_Storage::delete( 'inflight' );
	}

	public static function cleanup( $keep_days ) {
		$root = Digifix_TC_Storage::rollback_dir();
		if ( ! is_dir( $root ) ) {
			return;
		}
		$active_job = self::get_inflight();
		foreach ( glob( $root . '/*', GLOB_ONLYDIR ) ?: array() as $dir ) {
			if ( $active_job && (int) basename( $dir ) === (int) $active_job['job_id'] ) {
				continue;
			}
			if ( filemtime( $dir ) < time() - $keep_days * DAY_IN_SECONDS ) {
				self::rrmdir( $dir );
			}
		}
	}

	public static function copy_dir( $src, $dst ) {
		if ( ! wp_mkdir_p( $dst ) ) {
			return false;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $src, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::SELF_FIRST
		);
		foreach ( $it as $item ) {
			$target = $dst . '/' . substr( $item->getPathname(), strlen( $src ) + 1 );
			if ( $item->isDir() ) {
				if ( ! is_dir( $target ) && ! mkdir( $target, 0755, true ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_mkdir
					return false;
				}
			} elseif ( ! copy( $item->getPathname(), $target ) ) {
				return false;
			}
		}
		return true;
	}

	public static function rrmdir( $path ) {
		if ( is_file( $path ) || is_link( $path ) ) {
			return @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		if ( ! is_dir( $path ) ) {
			return true;
		}
		$it = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $it as $item ) {
			$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.WP.AlternativeFunctions.unlink_unlink
		}
		return @rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
	}
}
