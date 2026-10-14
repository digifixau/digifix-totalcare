<?php
/**
 * POSIX tar (ustar + PAX) headers, and a streaming parser.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Tar {

	const BLOCK    = 512;
	const MAX_SIZE = 8589934591; // 8 GiB - 1, the ustar size field limit.

	/**
	 * Header block(s) for one entry.
	 *
	 * @param string $type '0' file, '2' symlink, '5' directory.
	 */
	public static function header( $path, $size, $mtime, $mode, $type = '0', $link = '' ) {
		$pax   = array();
		$split = self::split_name( $path );
		if ( null === $split ) {
			$pax['path'] = $path;
			$split       = array( '', substr( $path, -100 ) );
		}
		if ( $size > self::MAX_SIZE ) {
			$pax['size'] = (string) $size;
		}
		if ( strlen( $link ) > 100 ) {
			$pax['linkpath'] = $link;
		}

		$out = '';
		if ( $pax ) {
			$records = '';
			foreach ( $pax as $k => $v ) {
				$records .= self::pax_record( $k, $v );
			}
			$out .= self::block( 'PaxHeaders/' . substr( basename( $path ), 0, 80 ), '', strlen( $records ), $mtime, 0644, 'x', '' );
			$out .= $records . str_repeat( "\0", self::pad( strlen( $records ) ) );
		}
		$out .= self::block( $split[1], $split[0], $size > self::MAX_SIZE ? 0 : $size, $mtime, $mode, $type, strlen( $link ) > 100 ? '' : $link );
		return $out;
	}

	public static function pad( $size ) {
		$r = $size % self::BLOCK;
		return $r ? self::BLOCK - $r : 0;
	}

	public static function eof() {
		return str_repeat( "\0", self::BLOCK * 2 );
	}

	/** "<len> key=value\n" where len counts the whole record. */
	private static function pax_record( $key, $value ) {
		$body = ' ' . $key . '=' . $value . "\n";
		$len  = strlen( $body ) + 1;
		while ( strlen( (string) $len ) + strlen( $body ) !== $len ) {
			$len = strlen( (string) $len ) + strlen( $body );
		}
		return $len . $body;
	}

	/** @return array{0:string,1:string}|null prefix, name */
	private static function split_name( $path ) {
		if ( strlen( $path ) <= 100 ) {
			return array( '', $path );
		}
		if ( strlen( $path ) > 256 ) {
			return null;
		}
		$pos = strlen( $path );
		while ( false !== ( $pos = strrpos( substr( $path, 0, $pos ), '/' ) ) ) {
			$prefix = substr( $path, 0, $pos );
			$name   = substr( $path, $pos + 1 );
			if ( strlen( $name ) > 100 ) {
				return null;
			}
			if ( strlen( $prefix ) <= 155 && '' !== $name ) {
				return array( $prefix, $name );
			}
		}
		return null;
	}

	private static function block( $name, $prefix, $size, $mtime, $mode, $type, $link ) {
		$h  = str_pad( $name, 100, "\0" );
		$h .= sprintf( '%07o', $mode & 07777 ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%07o', 0 ) . "\0";
		$h .= sprintf( '%011o', $size ) . "\0";
		$h .= sprintf( '%011o', max( 0, (int) $mtime ) ) . "\0";
		$h .= '        ';
		$h .= $type;
		$h .= str_pad( $link, 100, "\0" );
		$h .= "ustar\0" . '00';
		$h .= str_pad( '', 32, "\0" ) . str_pad( '', 32, "\0" );
		$h .= str_pad( '', 8, "\0" ) . str_pad( '', 8, "\0" );
		$h .= str_pad( $prefix, 155, "\0" );
		$h  = str_pad( $h, self::BLOCK, "\0" );

		$sum = 0;
		for ( $i = 0; $i < self::BLOCK; $i++ ) {
			$sum += ord( $h[ $i ] );
		}
		return substr_replace( $h, sprintf( '%06o', $sum ) . "\0 ", 148, 8 );
	}

	/**
	 * Parse one 512-byte header block.
	 *
	 * @return array|null|false Entry, null for an end-of-archive zero block,
	 *                          false for a corrupt block.
	 */
	public static function parse_block( $h ) {
		if ( strlen( $h ) !== self::BLOCK ) {
			return false;
		}
		if ( str_repeat( "\0", self::BLOCK ) === $h ) {
			return null;
		}
		$sum = 0;
		for ( $i = 0; $i < self::BLOCK; $i++ ) {
			$sum += ( $i >= 148 && $i < 156 ) ? 32 : ord( $h[ $i ] );
		}
		if ( octdec( trim( substr( $h, 148, 8 ), "\0 " ) ) !== $sum ) {
			return false;
		}
		$name   = rtrim( substr( $h, 0, 100 ), "\0" );
		$prefix = 'ustar' === substr( $h, 257, 5 ) ? rtrim( substr( $h, 345, 155 ), "\0" ) : '';
		return array(
			'path'  => '' !== $prefix ? $prefix . '/' . $name : $name,
			'mode'  => (int) octdec( trim( substr( $h, 100, 8 ), "\0 " ) ),
			'size'  => (int) octdec( trim( substr( $h, 124, 12 ), "\0 " ) ),
			'mtime' => (int) octdec( trim( substr( $h, 136, 12 ), "\0 " ) ),
			'type'  => "\0" === $h[156] ? '0' : $h[156],
			'link'  => rtrim( substr( $h, 157, 100 ), "\0" ),
		);
	}

	public static function parse_pax( $data ) {
		$out = array();
		$pos = 0;
		$len = strlen( $data );
		while ( $pos < $len ) {
			$sp = strpos( $data, ' ', $pos );
			if ( false === $sp ) {
				break;
			}
			$rec_len = (int) substr( $data, $pos, $sp - $pos );
			if ( $rec_len <= 0 ) {
				break;
			}
			$record = substr( $data, $sp + 1, $rec_len - ( $sp - $pos ) - 2 );
			$eq     = strpos( $record, '=' );
			if ( false !== $eq ) {
				$out[ substr( $record, 0, $eq ) ] = substr( $record, $eq + 1 );
			}
			$pos += $rec_len;
		}
		return $out;
	}
}

/**
 * Incremental tar parser. Feed it uncompressed bytes; it calls back for each
 * entry header, each data chunk and each entry end. The position is fully
 * described by state(), so parsing can resume at a member boundary.
 */
class DTC_Bk_Tar_Parser {

	private $buf = '';

	/** @var array Current position: entry (array|null), remaining, pad, pax. */
	private $st;

	private $on_entry;
	private $on_data;
	private $on_end;

	public function __construct( array $state, callable $on_entry, callable $on_data, callable $on_end ) {
		$this->st       = array_merge(
			array(
				'entry'     => null,
				'remaining' => 0,
				'pad'       => 0,
				'pax'       => array(),
				'eof'       => false,
			),
			$state
		);
		$this->on_entry = $on_entry;
		$this->on_data  = $on_data;
		$this->on_end   = $on_end;
	}

	public function state() {
		return $this->st;
	}

	public function at_boundary() {
		return '' === $this->buf && null === $this->st['entry'] && 0 === $this->st['pad'];
	}

	public function feed( $bytes ) {
		$this->buf .= $bytes;
		$pos        = 0;
		$len        = strlen( $this->buf );

		while ( ! $this->st['eof'] ) {
			if ( $this->st['entry'] ) {
				if ( $this->st['remaining'] > 0 ) {
					if ( $pos >= $len ) {
						break;
					}
					$take = (int) min( $this->st['remaining'], $len - $pos );
					if ( in_array( $this->st['entry']['type'], array( 'x', 'g' ), true ) ) {
						$this->st['entry']['pax_data'] = ( $this->st['entry']['pax_data'] ?? '' ) . substr( $this->buf, $pos, $take );
					} else {
						call_user_func( $this->on_data, substr( $this->buf, $pos, $take ) );
					}
					$pos                   += $take;
					$this->st['remaining'] -= $take;
					continue;
				}
				$entry             = $this->st['entry'];
				$this->st['entry'] = null;
				$this->st['pad']   = in_array( $entry['type'], array( '2', '5' ), true ) ? 0 : DTC_Bk_Tar::pad( $entry['size'] );
				if ( 'x' === $entry['type'] ) {
					$this->st['pax'] = DTC_Bk_Tar::parse_pax( $entry['pax_data'] ?? '' );
				} elseif ( 'g' !== $entry['type'] ) {
					call_user_func( $this->on_end, $entry );
				}
				continue;
			}
			if ( $this->st['pad'] > 0 ) {
				if ( $pos >= $len ) {
					break;
				}
				$take             = (int) min( $this->st['pad'], $len - $pos );
				$pos             += $take;
				$this->st['pad'] -= $take;
				continue;
			}
			if ( $len - $pos < DTC_Bk_Tar::BLOCK ) {
				break;
			}
			$hdr  = DTC_Bk_Tar::parse_block( substr( $this->buf, $pos, DTC_Bk_Tar::BLOCK ) );
			$pos += DTC_Bk_Tar::BLOCK;
			if ( null === $hdr ) {
				$this->st['eof'] = true;
				break;
			}
			if ( false === $hdr ) {
				throw new DTC_Bk_Exception( 'Corrupt tar header in backup archive.' );
			}
			if ( $this->st['pax'] && ! in_array( $hdr['type'], array( 'x', 'g' ), true ) ) {
				if ( isset( $this->st['pax']['path'] ) ) {
					$hdr['path'] = $this->st['pax']['path'];
				}
				if ( isset( $this->st['pax']['size'] ) ) {
					$hdr['size'] = (int) $this->st['pax']['size'];
				}
				if ( isset( $this->st['pax']['linkpath'] ) ) {
					$hdr['link'] = $this->st['pax']['linkpath'];
				}
				$this->st['pax'] = array();
			}
			$size = in_array( $hdr['type'], array( '2', '5' ), true ) ? 0 : $hdr['size'];
			$this->st['entry']     = $hdr;
			$this->st['remaining'] = $size;
			if ( ! in_array( $hdr['type'], array( 'x', 'g' ), true ) ) {
				call_user_func( $this->on_entry, $hdr );
			}
		}
		$this->buf = (string) substr( $this->buf, $pos );
	}

	/** Start in the middle of an entry's data (member continuation). */
	public static function continuation( $path, $size, $done ) {
		return array(
			'entry'     => array(
				'path'  => $path,
				'size'  => (int) $size,
				'type'  => '0',
				'mode'  => 0644,
				'mtime' => 0,
				'link'  => '',
				'cont'  => true,
			),
			'remaining' => (int) $size - (int) $done,
			'pad'       => 0,
			'pax'       => array(),
			'eof'       => false,
		);
	}
}
