<?php
/**
 * Self-updates from GitHub Releases.
 *
 * The release workflow (.github/workflows/release.yml) attaches
 * digifix-totalcare.zip to a release tagged vX.Y.Z. This class offers that
 * release to WordPress's normal update system, so it appears under
 * Dashboard → Updates and is installed by WordPress's background updater.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Updater {

	const REPO       = 'digifixau/digifix-totalcare';
	const ASSET      = 'digifix-totalcare.zip';
	const SLUG       = 'digifix-totalcare';
	const CACHE_KEY  = 'dtc_github_release';
	const AFTER_HOOK = 'dtc_after_self_update';

	public static function init() {
		add_filter( 'pre_set_site_transient_update_plugins', array( __CLASS__, 'inject_update' ) );
		add_filter( 'plugins_api', array( __CLASS__, 'plugin_info' ), 20, 3 );
		add_filter( 'auto_update_plugin', array( __CLASS__, 'auto_update' ), 20, 2 );
		add_filter( 'upgrader_source_selection', array( __CLASS__, 'fix_folder_name' ), 10, 4 );
		add_action( 'upgrader_process_complete', array( __CLASS__, 'after_update' ), 10, 2 );
		add_action( self::AFTER_HOOK, array( 'DTC_Installer', 'maybe_upgrade' ) );
		// "Check again" on Dashboard → Updates also refreshes the GitHub data.
		add_action( 'load-update-core.php', array( __CLASS__, 'maybe_clear_cache' ) );
	}

	public static function repo() {
		return apply_filters( 'dtc_update_repo', self::REPO );
	}

	/**
	 * Latest release, cached for 6 hours (1 hour after an error) to stay well
	 * inside GitHub's unauthenticated limit of 60 requests/hour per IP, which
	 * is shared by every site on a shared hosting server.
	 *
	 * @return array|null version, package, url, notes, published.
	 */
	public static function latest_release() {
		$cached = get_site_transient( self::CACHE_KEY );
		if ( is_array( $cached ) ) {
			return $cached['release'];
		}

		$release = null;
		$resp    = wp_remote_get(
			'https://api.github.com/repos/' . self::repo() . '/releases/latest',
			array(
				'timeout' => 15,
				'headers' => array( 'Accept' => 'application/vnd.github+json' ),
			)
		);
		if ( ! is_wp_error( $resp ) && 200 === (int) wp_remote_retrieve_response_code( $resp ) ) {
			$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
			foreach ( (array) ( $data['assets'] ?? array() ) as $asset ) {
				if ( self::ASSET === ( $asset['name'] ?? '' ) ) {
					$release = array(
						'version'   => ltrim( (string) $data['tag_name'], 'vV' ),
						'package'   => $asset['browser_download_url'],
						'url'       => $data['html_url'],
						'notes'     => (string) ( $data['body'] ?? '' ),
						'published' => (string) ( $data['published_at'] ?? '' ),
					);
					break;
				}
			}
		}

		set_site_transient( self::CACHE_KEY, array( 'release' => $release ), $release ? 6 * HOUR_IN_SECONDS : HOUR_IN_SECONDS );
		return $release;
	}

	public static function maybe_clear_cache() {
		if ( isset( $_GET['force-check'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			delete_site_transient( self::CACHE_KEY );
		}
	}

	public static function inject_update( $transient ) {
		if ( ! is_object( $transient ) ) {
			return $transient;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $transient;
		}

		$item = (object) array(
			'id'           => 'github.com/' . self::repo(),
			'slug'         => self::SLUG,
			'plugin'       => DTC_BASENAME,
			'new_version'  => $release['version'],
			'url'          => $release['url'],
			'package'      => $release['package'],
			'requires'     => '6.0',
			'requires_php' => '7.4',
			'icons'        => array(),
			'banners'      => array(),
		);

		if ( version_compare( $release['version'], DTC_VERSION, '>' ) ) {
			$transient->response[ DTC_BASENAME ] = $item;
			unset( $transient->no_update[ DTC_BASENAME ] );
		} else {
			// Listing it under no_update enables the auto-update toggle.
			$transient->no_update[ DTC_BASENAME ] = $item;
		}
		return $transient;
	}

	/** Details shown in the "View details" popup. */
	public static function plugin_info( $result, $action, $args ) {
		if ( 'plugin_information' !== $action || self::SLUG !== ( $args->slug ?? '' ) ) {
			return $result;
		}
		$release = self::latest_release();
		if ( ! $release ) {
			return $result;
		}
		return (object) array(
			'name'          => 'Digifix TotalCare',
			'slug'          => self::SLUG,
			'version'       => $release['version'],
			'author'        => '<a href="https://digifix.com.au/">Digifix</a>',
			'homepage'      => 'https://github.com/' . self::repo(),
			'requires'      => '6.0',
			'requires_php'  => '7.4',
			'last_updated'  => $release['published'],
			'download_link' => $release['package'],
			'sections'      => array(
				'changelog' => $release['notes'] ? wpautop( esc_html( $release['notes'] ) ) : 'See the release on GitHub.',
			),
		);
	}

	/**
	 * Let WordPress install TotalCare updates in the background, except while
	 * a TotalCare job or restore is running.
	 */
	public static function auto_update( $update, $item ) {
		if ( ( $item->plugin ?? '' ) !== DTC_BASENAME ) {
			return $update;
		}
		if ( ! DTC_Settings::get( 'self_update' ) ) {
			return $update;
		}
		if ( DTC_Restore::in_progress() || DTC_Jobs::active() ) {
			return false;
		}
		return true;
	}

	/** Keep the installed folder name even if the zip's top folder differs. */
	public static function fix_folder_name( $source, $remote_source, $upgrader, $hook_extra = array() ) {
		if ( ( $hook_extra['plugin'] ?? '' ) !== DTC_BASENAME ) {
			return $source;
		}
		$wanted = trailingslashit( $remote_source ) . dirname( DTC_BASENAME ) . '/';
		if ( untrailingslashit( $source ) === untrailingslashit( $wanted ) ) {
			return $source;
		}
		global $wp_filesystem;
		if ( $wp_filesystem && $wp_filesystem->move( $source, $wanted, true ) ) {
			return $wanted;
		}
		return new WP_Error( 'dtc_update_folder', 'Could not rename the update folder to ' . dirname( DTC_BASENAME ) . '.' );
	}

	/**
	 * The running process still has the old code loaded, so refresh the
	 * guardian and tables in a fresh request.
	 */
	public static function after_update( $upgrader, $extra ) {
		if ( 'plugin' !== ( $extra['type'] ?? '' ) ) {
			return;
		}
		$plugins = (array) ( $extra['plugins'] ?? ( isset( $extra['plugin'] ) ? array( $extra['plugin'] ) : array() ) );
		if ( in_array( DTC_BASENAME, $plugins, true ) ) {
			delete_site_transient( self::CACHE_KEY );
			wp_schedule_single_event( time(), self::AFTER_HOOK );
			DTC_Logger::log( 'system', 'info', 'system.self_updated', 'TotalCare was updated from GitHub.' );
		}
	}
}
