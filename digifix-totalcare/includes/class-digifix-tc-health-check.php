<?php
/**
 * Site health checks run over HTTP, so each check loads the freshly updated
 * code in a new PHP process.
 *
 * Hard failures (always trigger rollback):
 *  - HTTP status >= 500, or a 200 page that now returns an error status
 *  - PHP fatal/parse error or the WordPress "critical error" screen
 *  - database connection error, stuck maintenance mode
 *  - closing </html> missing where the baseline had it
 *  - the guardian recorded a PHP fatal error since the update started
 * Soft failures (logged as warnings; hard when "strict" is enabled):
 *  - <title> changed
 *  - page size changed by more than 50%
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Health_Check {

	const ERROR_MARKERS = array(
		'There has been a critical error',
		'Fatal error</b>',
		'Parse error</b>',
		'Fatal error: ',
		'Parse error: ',
		'Error establishing a database connection',
		'Briefly unavailable for scheduled maintenance',
	);

	public static function urls() {
		$urls  = array( home_url( '/' ), wp_login_url() );
		$extra = preg_split( '/\R/', (string) DTC_Settings::get( 'health_urls' ), -1, PREG_SPLIT_NO_EMPTY );
		foreach ( $extra as $url ) {
			$url = trim( $url );
			if ( $url && 0 === strpos( $url, home_url() ) ) {
				$urls[] = $url;
			}
		}
		return array_values( array_unique( $urls ) );
	}

	public static function probe( $url ) {
		$started = microtime( true );
		$resp    = wp_remote_get(
			add_query_arg( 'dtc_hc', wp_generate_password( 8, false ), $url ),
			array(
				'timeout'     => 30,
				'redirection' => 3,
				'sslverify'   => false,
				'user-agent'  => 'Digifix-TotalCare-HealthCheck/' . DTC_VERSION,
				'headers'     => array(
					'Cache-Control' => 'no-cache',
					'Pragma'        => 'no-cache',
				),
			)
		);
		$result  = array(
			'url'      => $url,
			'code'     => 0,
			'title'    => '',
			'size'     => 0,
			'html_end' => false,
			'markers'  => array(),
			'error'    => '',
			'ms'       => (int) round( ( microtime( true ) - $started ) * 1000 ),
		);
		if ( is_wp_error( $resp ) ) {
			$result['error'] = $resp->get_error_message();
			return $result;
		}
		$body               = (string) wp_remote_retrieve_body( $resp );
		$result['code']     = (int) wp_remote_retrieve_response_code( $resp );
		$result['size']     = strlen( $body );
		$result['html_end'] = false !== stripos( $body, '</html>' );
		if ( preg_match( '#<title[^>]*>(.*?)</title>#is', $body, $m ) ) {
			$result['title'] = trim( html_entity_decode( wp_strip_all_tags( $m[1] ) ) );
		}
		foreach ( self::ERROR_MARKERS as $marker ) {
			if ( false !== stripos( $body, $marker ) ) {
				$result['markers'][] = $marker;
			}
		}
		return $result;
	}

	/** Recorded PHP fatals from the guardian since a timestamp, or null if unreachable. */
	public static function guardian_fatals( $since ) {
		$ts   = time();
		$resp = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			array(
				'timeout'   => 20,
				'sslverify' => false,
				'body'      => array(
					'action' => 'dtc_guardian_health',
					'since'  => (int) $since,
					'ts'     => $ts,
					'sig'    => DTC_Storage::sign( 'dtc_guardian_health', $ts ),
				),
			)
		);
		if ( is_wp_error( $resp ) || 200 !== (int) wp_remote_retrieve_response_code( $resp ) ) {
			return null;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $resp ), true );
		return is_array( $data ) && isset( $data['fatals'] ) ? (array) $data['fatals'] : null;
	}

	public static function baseline() {
		$baseline = array(
			'time'     => time(),
			'pages'    => array(),
			'guardian' => null !== self::guardian_fatals( time() ),
		);
		foreach ( self::urls() as $url ) {
			$baseline['pages'][ $url ] = self::probe( $url );
		}
		return $baseline;
	}

	/** Problems with the baseline itself (site already broken before updates). */
	public static function baseline_problems( array $baseline ) {
		$problems = array();
		foreach ( $baseline['pages'] as $url => $page ) {
			if ( $page['error'] || $page['code'] >= 500 || $page['markers'] ) {
				$problems[] = $url . ': ' . ( $page['error'] ?: 'HTTP ' . $page['code'] . ( $page['markers'] ? ', ' . implode( ', ', $page['markers'] ) : '' ) );
			}
		}
		return $problems;
	}

	/**
	 * Compare the site against a baseline.
	 *
	 * @param array $baseline From baseline().
	 * @param int   $since    Unix time the change started (for fatal records).
	 * @return array{passed:bool,hard:string[],soft:string[],pages:array}
	 */
	public static function check( array $baseline, $since ) {
		$strict = (bool) DTC_Settings::get( 'health_strict' );
		$hard   = array();
		$soft   = array();
		$pages  = array();

		foreach ( $baseline['pages'] as $url => $base ) {
			$attempt = 0;
			do {
				if ( $attempt > 0 ) {
					sleep( 5 );
				}
				$page   = self::probe( $url );
				$result = self::compare( $base, $page );
				++$attempt;
			} while ( $result['hard'] && $attempt < 3 );

			$pages[ $url ] = $page;
			foreach ( $result['hard'] as $msg ) {
				$hard[] = $url . ': ' . $msg;
			}
			foreach ( $result['soft'] as $msg ) {
				$soft[] = $url . ': ' . $msg;
			}
		}

		if ( ! empty( $baseline['guardian'] ) ) {
			$fatals = self::guardian_fatals( $since );
			if ( null === $fatals ) {
				$hard[] = 'Guardian health endpoint (admin-ajax.php) is not responding.';
			} else {
				foreach ( array_slice( $fatals, 0, 5 ) as $fatal ) {
					$hard[] = sprintf( 'PHP fatal error: %s in %s:%d', $fatal['message'] ?? '', $fatal['file'] ?? '', (int) ( $fatal['line'] ?? 0 ) );
				}
			}
		}

		if ( $strict ) {
			$hard = array_merge( $hard, $soft );
			$soft = array();
		}

		return array(
			'passed' => empty( $hard ),
			'hard'   => $hard,
			'soft'   => $soft,
			'pages'  => $pages,
		);
	}

	public static function compare( array $base, array $page ) {
		$hard = array();
		$soft = array();

		if ( $page['error'] ) {
			$hard[] = 'request failed: ' . $page['error'];
			return compact( 'hard', 'soft' );
		}
		if ( $page['code'] >= 500 ) {
			$hard[] = 'HTTP ' . $page['code'];
		} elseif ( $base['code'] >= 200 && $base['code'] < 400 && $page['code'] >= 400 ) {
			$hard[] = 'HTTP ' . $page['code'] . ' (was ' . $base['code'] . ')';
		}
		$new_markers = array_diff( $page['markers'], $base['markers'] );
		if ( $new_markers ) {
			$hard[] = 'error text on page: ' . implode( ', ', $new_markers );
		}
		if ( $base['html_end'] && ! $page['html_end'] ) {
			$hard[] = 'page output is truncated (no closing </html>)';
		}
		if ( $base['title'] !== '' && $page['title'] !== $base['title'] ) {
			$soft[] = sprintf( 'title changed from "%s" to "%s"', $base['title'], $page['title'] );
		}
		if ( $base['size'] > 0 && abs( $page['size'] - $base['size'] ) / $base['size'] > 0.5 ) {
			$soft[] = sprintf( 'page size changed from %s to %s', size_format( $base['size'] ), size_format( $page['size'] ) );
		}
		return compact( 'hard', 'soft' );
	}
}
