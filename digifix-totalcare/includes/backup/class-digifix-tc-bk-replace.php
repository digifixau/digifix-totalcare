<?php
/**
 * URL and path replacement for restores onto another address.
 *
 * Serialized PHP values are rewritten token by token so string lengths stay
 * correct; values are never unserialized (objects of classes that are not
 * loaded would otherwise be damaged).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Replace {

	/**
	 * Replacement map for a move from one address/path to another.
	 *
	 * @param array $from home, siteurl, abspath, content_dir
	 * @param array $to   same keys for this site
	 * @return array<string,string> Old => new, for strtr().
	 */
	public static function pairs( array $from, array $to ) {
		$map = array();
		foreach ( array( 'home', 'siteurl' ) as $k ) {
			$old = untrailingslashit( (string) ( $from[ $k ] ?? '' ) );
			$new = untrailingslashit( (string) ( $to[ $k ] ?? '' ) );
			if ( '' === $old || '' === $new || $old === $new ) {
				continue;
			}
			$old_hp = preg_replace( '#^[a-z]+://#i', '', $old );
			$new_hp = preg_replace( '#^[a-z]+://#i', '', $new );
			$hosts  = array( $old_hp );
			if ( 0 === strpos( $old_hp, 'www.' ) ) {
				$hosts[] = substr( $old_hp, 4 );
			} else {
				$hosts[] = 'www.' . $old_hp;
			}
			foreach ( $hosts as $h ) {
				foreach ( array( 'https://' . $h, 'http://' . $h ) as $variant ) {
					$map[ $variant ]                          = $new;
					$map[ str_replace( '/', '\\/', $variant ) ] = str_replace( '/', '\\/', $new );
					$map[ rawurlencode( $variant ) ]          = rawurlencode( $new );
				}
				$map[ '//' . $h ]                          = '//' . $new_hp;
				$map[ str_replace( '/', '\\/', '//' . $h ) ] = str_replace( '/', '\\/', '//' . $new_hp );
			}
		}
		foreach ( array( 'abspath', 'content_dir' ) as $k ) {
			$old = untrailingslashit( (string) ( $from[ $k ] ?? '' ) );
			$new = untrailingslashit( (string) ( $to[ $k ] ?? '' ) );
			if ( strlen( $old ) > 3 && '' !== $new && $old !== $new ) {
				$map[ $old ]                          = $new;
				$map[ str_replace( '/', '\\/', $old ) ] = str_replace( '/', '\\/', $new );
			}
		}
		return $map;
	}

	/** Short substrings that must be present for any replacement to apply. */
	public static function needles( array $map ) {
		$needles = array();
		foreach ( array_keys( $map ) as $k ) {
			$k = (string) $k;
			if ( preg_match( '#(?://|\\\\/\\\\/|%2F%2F)([^/\\\\%]+)#', $k, $m ) && strlen( $m[1] ) > 3 ) {
				$needles[ $m[1] ] = true; // Host name.
			} else {
				// A filesystem path, possibly JSON-escaped: use its longest
				// folder name, which survives any escaping.
				$parts = preg_split( '#[\\\\/]+#', $k, -1, PREG_SPLIT_NO_EMPTY );
				usort(
					$parts,
					function ( $a, $b ) {
						return strlen( $b ) - strlen( $a );
					}
				);
				if ( $parts && strlen( $parts[0] ) > 3 ) {
					$needles[ $parts[0] ] = true;
				}
			}
		}
		return array_keys( $needles );
	}

	public static function apply( $value, array $map ) {
		if ( ! $map || '' === $value ) {
			return $value;
		}
		if ( is_serialized( $value, false ) ) {
			$fixed = self::serialized( $value, $map );
			if ( null !== $fixed ) {
				return $fixed;
			}
		}
		return strtr( $value, $map );
	}

	/**
	 * Replace inside every s:N:"..."; token, fixing N.
	 *
	 * @return string|null Null if the value is not well-formed.
	 */
	private static function serialized( $s, array $map ) {
		$out = '';
		$len = strlen( $s );
		$i   = 0;
		while ( $i < $len ) {
			$c = $s[ $i ];
			if ( 's' === $c && $i + 1 < $len && ':' === $s[ $i + 1 ] && ( 0 === $i || false !== strpos( '{;}', $s[ $i - 1 ] ) ) ) {
				$colon = strpos( $s, ':', $i + 2 );
				if ( false === $colon ) {
					return null;
				}
				$n = substr( $s, $i + 2, $colon - $i - 2 );
				if ( ! ctype_digit( $n ) || '"' !== ( $s[ $colon + 1 ] ?? '' ) ) {
					return null;
				}
				$start = $colon + 2;
				$str   = substr( $s, $start, (int) $n );
				if ( strlen( $str ) !== (int) $n || '";' !== substr( $s, $start + (int) $n, 2 ) ) {
					return null;
				}
				$new  = self::apply( $str, $map );
				$out .= 's:' . strlen( $new ) . ':"' . $new . '";';
				$i    = $start + (int) $n + 2;
				continue;
			}
			// Copy everything up to the next token boundary in one go.
			$next = strcspn( $s, ';{}', $i );
			$out .= substr( $s, $i, $next + 1 );
			$i   += $next + 1;
		}
		return $out;
	}
}
