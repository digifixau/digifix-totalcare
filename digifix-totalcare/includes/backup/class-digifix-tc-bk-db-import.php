<?php
/**
 * Database restore: import into temporary tables while the site keeps
 * running, then swap them in with one atomic RENAME TABLE.
 *
 * Temp tables are named dtcr_<hash> (short, so long plugin table names do not
 * exceed MySQL's 64-character limit). The replaced live tables become
 * dtcold_<hash> and are kept until the restored site passes its health check,
 * which allows an instant database rollback.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Db_Import {

	const OLD_FILE = 'restore-old-tables.json';

	/** @var mysqli */
	private $db;
	private $st;

	public static function temp_name( $final ) {
		return 'dtcr_' . substr( md5( $final ), 0, 12 );
	}

	public static function old_name( $final ) {
		return 'dtcold_' . substr( md5( $final ), 0, 12 );
	}

	/**
	 * @param array $opts old_prefix, new_prefix, charset, map (replacements),
	 *                    fix_collation, fix_utf8mb3.
	 */
	public static function init_state( array $opts ) {
		return array_merge(
			array(
				'old_prefix'    => '',
				'new_prefix'    => '',
				'charset'       => 'utf8mb4',
				'map'           => array(),
				'fix_collation' => '',
				'fix_utf8mb3'   => false,
				'tables'        => array(),
				'member'        => 0,
				'lines'         => 0,
				'dl'            => 0,
				'ended'         => false,
				'swapped'       => false,
			),
			$opts
		);
	}

	public function __construct( array $state ) {
		$this->db = DTC_Bk_Db_Dump::connection();
		$this->st = $state;
	}

	public function state() {
		return $this->st;
	}

	private function query( $sql ) {
		$res = $this->db->query( $sql );
		if ( false === $res ) {
			throw new DTC_Bk_Exception( 'Database error during restore: ' . $this->db->error . ' [' . substr( $sql, 0, 160 ) . ']' );
		}
		if ( $res instanceof mysqli_result ) {
			$res->free();
		}
		return true;
	}

	/** Server checks before anything is changed. @return string[] problems */
	public static function preflight( $max_stmt ) {
		$db       = DTC_Bk_Db_Dump::connection();
		$problems = array();
		$res      = $db->query( 'SELECT @@max_allowed_packet' );
		$packet   = $res ? (int) $res->fetch_row()[0] : 0;
		if ( $res ) {
			$res->free();
		}
		if ( $packet && $packet < (int) $max_stmt + 4096 ) {
			$problems[] = 'MySQL max_allowed_packet (' . $packet . ' bytes) is smaller than the largest statement in the backup (' . $max_stmt . ' bytes).';
		}
		$t = 'dtcr_privcheck';
		if ( ! $db->query( 'DROP TABLE IF EXISTS `' . $t . '`' ) || ! $db->query( 'CREATE TABLE `' . $t . '` (id int NOT NULL, PRIMARY KEY (id))' ) || ! $db->query( 'ALTER TABLE `' . $t . '` ADD COLUMN x int NULL' ) || ! $db->query( 'DROP TABLE `' . $t . '`' ) ) {
			$problems[] = 'The database user cannot create, alter or drop tables: ' . $db->error;
		}
		return $problems;
	}

	/** Collation/charset rewrites needed on this server. */
	public static function server_fixes() {
		$db  = DTC_Bk_Db_Dump::connection();
		$has = function ( $sql ) use ( $db ) {
			$res = $db->query( $sql );
			$ok  = $res && $res->num_rows > 0;
			if ( $res instanceof mysqli_result ) {
				$res->free();
			}
			return $ok;
		};
		$fix = '';
		if ( ! $has( "SHOW COLLATION LIKE 'utf8mb4\\_0900\\_ai\\_ci'" ) ) {
			$fix = $has( "SHOW COLLATION LIKE 'utf8mb4\\_unicode\\_520\\_ci'" ) ? 'utf8mb4_unicode_520_ci' : 'utf8mb4_unicode_ci';
		}
		return array(
			'fix_collation' => $fix,
			'fix_utf8mb3'   => ! $has( "SHOW CHARACTER SET LIKE 'utf8mb3'" ),
		);
	}

	/** Drop temp tables left by an earlier failed restore. */
	public static function drop_temp() {
		$db  = DTC_Bk_Db_Dump::connection();
		$res = $db->query( "SHOW TABLES LIKE 'dtcr\\_%'" );
		$all = array();
		if ( $res ) {
			while ( $row = $res->fetch_row() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$all[] = $row[0];
			}
			$res->free();
		}
		$db->query( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( $all as $t ) {
			$db->query( 'DROP TABLE IF EXISTS ' . DTC_Bk_Db_Dump::q( $t ) );
		}
	}

	public function session() {
		$this->query( "SET SESSION sql_mode='NO_AUTO_VALUE_ON_ZERO'" );
		$this->query( 'SET FOREIGN_KEY_CHECKS=0' );
		$this->query( 'SET UNIQUE_CHECKS=0' );
		if ( $this->st['charset'] ) {
			$this->db->set_charset( $this->st['charset'] );
		}
	}

	private function final_name( $source ) {
		$old = $this->st['old_prefix'];
		$new = $this->st['new_prefix'];
		if ( $old !== $new && '' !== $old && 0 === strpos( $source, $old ) ) {
			return $new . substr( $source, strlen( $old ) );
		}
		return $source;
	}

	/**
	 * Execute one line of the dump.
	 *
	 * @return bool False when the end marker was reached.
	 */
	public function line( $line ) {
		$line = rtrim( $line, "\r\n" );
		if ( '' === $line ) {
			return true;
		}
		if ( 0 === strpos( $line, '--' ) ) {
			if ( 0 === strpos( $line, '-- DTC-SQL-END' ) ) {
				$this->st['ended'] = true;
				return false;
			}
			return true;
		}
		if ( 0 === strpos( $line, 'CREATE TABLE `' ) ) {
			$this->create( $line );
			return true;
		}
		if ( 0 === strpos( $line, 'INSERT INTO `' ) ) {
			$this->insert( $line );
			return true;
		}
		throw new DTC_Bk_Exception( 'Unexpected statement in the database backup: ' . substr( $line, 0, 80 ) );
	}

	private static function name_after( $line, $offset ) {
		$end = strpos( $line, '` ', $offset );
		while ( false !== $end && '`' === ( $line[ $end - 1 ] ?? '' ) && '`' === ( $line[ $end - 2 ] ?? '' ) ) {
			$end = strpos( $line, '` ', $end + 1 );
		}
		if ( false === $end ) {
			throw new DTC_Bk_Exception( 'Malformed statement in the database backup.' );
		}
		return array( str_replace( '``', '`', substr( $line, $offset, $end - $offset ) ), $end + 1 );
	}

	private function create( $line ) {
		list( $source, $end ) = self::name_after( $line, 14 );
		$final                = $this->final_name( $source );
		$temp                 = self::temp_name( $final );
		$this->st['tables'][] = $final;

		$sql = 'CREATE TABLE ' . DTC_Bk_Db_Dump::q( $temp ) . substr( $line, $end );
		$sql = rtrim( $sql, ';' );

		// Foreign key names are unique per database and the live table still
		// holds its own: keep the original name when it is free, otherwise
		// use the first free variant. REFERENCES point at the temp tables.
		$sql = preg_replace_callback(
			'/CONSTRAINT `((?:[^`]|``)+)` FOREIGN KEY/',
			function ( $m ) {
				return 'CONSTRAINT ' . DTC_Bk_Db_Dump::q( $this->free_constraint_name( str_replace( '``', '`', $m[1] ) ) ) . ' FOREIGN KEY';
			},
			$sql
		);
		$sql = preg_replace_callback(
			'/REFERENCES `((?:[^`]|``)+)`/',
			function ( $m ) {
				return 'REFERENCES ' . DTC_Bk_Db_Dump::q( self::temp_name( $this->final_name( str_replace( '``', '`', $m[1] ) ) ) );
			},
			$sql
		);
		if ( $this->st['fix_collation'] ) {
			$sql = preg_replace( '/utf8mb4_0900_[a-z_]+/', $this->st['fix_collation'], $sql );
		}
		if ( $this->st['fix_utf8mb3'] ) {
			$sql = str_replace( 'utf8mb3', 'utf8', $sql );
		}

		$this->query( 'DROP TABLE IF EXISTS ' . DTC_Bk_Db_Dump::q( $temp ) );
		$this->query( $sql );
	}

	private function free_constraint_name( $name ) {
		$base = preg_replace( '/_r\d*$/', '', $name );
		for ( $i = 0; $i < 100; $i++ ) {
			$candidate = 0 === $i ? $base : substr( $base, 0, 56 ) . '_r' . ( 1 === $i ? '' : $i );
			$res       = $this->db->query( "SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY' AND CONSTRAINT_NAME = '" . $this->db->real_escape_string( $candidate ) . "' LIMIT 1" );
			$taken     = $res instanceof mysqli_result && $res->num_rows > 0;
			if ( $res instanceof mysqli_result ) {
				$res->free();
			}
			if ( ! $taken ) {
				return $candidate;
			}
		}
		throw new DTC_Bk_Exception( 'No free name for foreign key ' . $name . '.' );
	}

	private function insert( $line ) {
		list( $source, $end ) = self::name_after( $line, 13 );
		$temp                 = self::temp_name( $this->final_name( $source ) );
		$sql                  = 'INSERT INTO ' . DTC_Bk_Db_Dump::q( $temp ) . substr( $line, $end );

		if ( $this->st['map'] ) {
			$sql = $this->migrate( $sql );
		}
		$this->query( rtrim( $sql, ';' ) );
	}

	/** Replace URLs/paths inside the string literals of an INSERT. */
	private function migrate( $sql ) {
		$found = false;
		foreach ( (array) ( $this->st['needles'] ?? array() ) as $needle ) {
			if ( false !== strpos( $sql, $needle ) ) {
				$found = true;
				break;
			}
		}
		if ( ! $found ) {
			return $sql;
		}

		$values = strpos( $sql, ') VALUES (' );
		if ( false === $values ) {
			return $sql;
		}
		$cols = explode( '`,`', trim( substr( $sql, strpos( $sql, ' (' ) + 2, $values - strpos( $sql, ' (' ) - 2 ), '`' ) );
		$skip = array_search( 'guid', $cols, true );

		$out = substr( $sql, 0, $values + 9 );
		$i   = $values + 9;
		$len = strlen( $sql );
		$col = 0;
		$map = $this->st['map'];
		$un  = array(
			'\\0'  => "\0",
			'\\n'  => "\n",
			'\\r'  => "\r",
			'\\\\' => '\\',
			"\\'"  => "'",
			'\\"'  => '"',
			'\\Z'  => "\x1a",
		);

		while ( $i < $len ) {
			$q = strpos( $sql, "'", $i );
			if ( false === $q ) {
				$out .= substr( $sql, $i );
				break;
			}
			$seg  = substr( $sql, $i, $q - $i );
			$out .= $seg;
			$col += substr_count( $seg, ',' );
			if ( false !== ( $p = strrpos( $seg, '(' ) ) ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.Found
				$col = substr_count( substr( $seg, $p ), ',' );
			}
			// Hex literal X'..': copy as is.
			if ( $q > 0 && 'X' === $sql[ $q - 1 ] ) {
				$close = strpos( $sql, "'", $q + 1 );
				$out  .= substr( $sql, $q, $close - $q + 1 );
				$i     = $close + 1;
				continue;
			}
			// Find the closing quote (escaped quotes have an odd number of
			// backslashes before them).
			$j = $q + 1;
			while ( true ) {
				$j = strpos( $sql, "'", $j );
				if ( false === $j ) {
					throw new DTC_Bk_Exception( 'Unterminated string in the database backup.' );
				}
				$bs = 0;
				for ( $k = $j - 1; $k > $q && '\\' === $sql[ $k ]; $k-- ) {
					++$bs;
				}
				if ( 0 === $bs % 2 ) {
					break;
				}
				++$j;
			}
			$raw = substr( $sql, $q + 1, $j - $q - 1 );
			if ( $col !== $skip ) {
				$val = strtr( $raw, $un );
				$new = DTC_Bk_Replace::apply( $val, $map );
				if ( $new !== $val ) {
					$raw = $this->db->real_escape_string( $new );
				}
			}
			$out .= "'" . $raw . "'";
			$i    = $j + 1;
		}
		return $out;
	}

	/** Prefix-dependent keys after a table prefix change. */
	public function fix_prefix() {
		$old = $this->st['old_prefix'];
		$new = $this->st['new_prefix'];
		if ( '' === $old || $old === $new ) {
			return;
		}
		$options  = DTC_Bk_Db_Dump::q( self::temp_name( $new . 'options' ) );
		$usermeta = DTC_Bk_Db_Dump::q( self::temp_name( $new . 'usermeta' ) );
		if ( in_array( $new . 'options', $this->st['tables'], true ) ) {
			$this->query( 'UPDATE ' . $options . " SET option_name = '" . $this->db->real_escape_string( $new . 'user_roles' ) . "' WHERE option_name = '" . $this->db->real_escape_string( $old . 'user_roles' ) . "'" );
		}
		if ( in_array( $new . 'usermeta', $this->st['tables'], true ) ) {
			$like = str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $old ) . '%';
			$this->query( 'UPDATE ' . $usermeta . ' SET meta_key = CONCAT(\'' . $this->db->real_escape_string( $new ) . '\', SUBSTRING(meta_key, ' . ( strlen( $old ) + 1 ) . ")) WHERE meta_key LIKE '" . $this->db->real_escape_string( $like ) . "'" );
		}
	}

	/* ---------------------------------------------------------------------
	 * Options that must survive the swap
	 * ------------------------------------------------------------------ */

	private function temp_options() {
		global $wpdb;
		$final = $wpdb->prefix . 'options';
		return in_array( $final, $this->st['tables'], true ) ? DTC_Bk_Db_Dump::q( self::temp_name( $final ) ) : null;
	}

	public function temp_option( $name ) {
		$t = $this->temp_options();
		if ( ! $t ) {
			return null;
		}
		$res = $this->db->query( 'SELECT option_value FROM ' . $t . " WHERE option_name = '" . $this->db->real_escape_string( $name ) . "' LIMIT 1" );
		$val = null;
		if ( $res instanceof mysqli_result ) {
			$row = $res->fetch_row();
			$val = $row ? $row[0] : null;
			$res->free();
		}
		return $val;
	}

	public function set_temp_option( $name, $value, $autoload = 'no' ) {
		$t = $this->temp_options();
		if ( ! $t ) {
			return;
		}
		$n = $this->db->real_escape_string( $name );
		$v = $this->db->real_escape_string( $value );
		$this->query( 'INSERT INTO ' . $t . " (option_name, option_value, autoload) VALUES ('$n', '$v', '$autoload') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value)" );
	}

	/** Current (live) raw option value, bypassing caches. */
	public function live_option( $name ) {
		global $wpdb;
		$res = $this->db->query( 'SELECT option_value FROM ' . DTC_Bk_Db_Dump::q( $wpdb->prefix . 'options' ) . " WHERE option_name = '" . $this->db->real_escape_string( $name ) . "' LIMIT 1" );
		$val = null;
		if ( $res instanceof mysqli_result ) {
			$row = $res->fetch_row();
			$val = $row ? $row[0] : null;
			$res->free();
		}
		return $val;
	}

	/* ---------------------------------------------------------------------
	 * Swap, rollback, cleanup
	 * ------------------------------------------------------------------ */

	private static function exists( mysqli $db, $table ) {
		$res = $db->query( "SHOW TABLES LIKE '" . $db->real_escape_string( str_replace( array( '\\', '_', '%' ), array( '\\\\', '\\_', '\\%' ), $table ) ) . "'" );
		$ok  = $res && $res->num_rows > 0;
		if ( $res instanceof mysqli_result ) {
			$res->free();
		}
		return $ok;
	}

	public function swap() {
		$renames = array();
		$old     = array();
		$this->query( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( array_unique( $this->st['tables'] ) as $final ) {
			$temp = self::temp_name( $final );
			if ( self::exists( $this->db, $final ) ) {
				$o = self::old_name( $final );
				$this->query( 'DROP TABLE IF EXISTS ' . DTC_Bk_Db_Dump::q( $o ) );
				$renames[]      = DTC_Bk_Db_Dump::q( $final ) . ' TO ' . DTC_Bk_Db_Dump::q( $o );
				$old[ $final ] = $o;
			}
			$renames[] = DTC_Bk_Db_Dump::q( $temp ) . ' TO ' . DTC_Bk_Db_Dump::q( $final );
		}
		if ( ! $renames ) {
			return;
		}
		// Record the plan first so a crash right after the rename can be undone.
		DTC_Bk_Util::write_json(
			DTC_Bk_Util::data_dir() . '/' . self::OLD_FILE,
			array(
				'time'   => time(),
				'tables' => $old,
				'new'    => array_values( array_unique( $this->st['tables'] ) ),
			)
		);
		$this->query( 'SET SESSION lock_wait_timeout = 10' );
		$sql = 'RENAME TABLE ' . implode( ', ', $renames );
		for ( $try = 1; ; $try++ ) {
			if ( false !== $this->db->query( $sql ) ) {
				break;
			}
			if ( $try >= 4 ) {
				throw new DTC_Bk_Exception( 'Could not swap in the restored tables: ' . $this->db->error );
			}
			sleep( 2 );
		}
		$this->st['swapped'] = true;
	}

	/** Whether swap() already ran for these tables (it is atomic). */
	public static function swapped( array $tables ) {
		$tables = array_values( array_unique( $tables ) );
		if ( ! $tables || ! file_exists( DTC_Bk_Util::data_dir() . '/' . self::OLD_FILE ) ) {
			return false;
		}
		$db = DTC_Bk_Db_Dump::connection();
		return ! self::exists( $db, self::temp_name( $tables[0] ) ) && self::exists( $db, $tables[0] );
	}

	/** Undo a swap (restore failed afterwards) or drop unused temp tables. */
	public static function rollback() {
		$db   = DTC_Bk_Db_Dump::connection();
		$file = DTC_Bk_Util::data_dir() . '/' . self::OLD_FILE;
		$info = DTC_Bk_Util::read_json( $file );
		$db->query( 'SET FOREIGN_KEY_CHECKS=0' );
		// The rename is atomic: it happened if the first temp table is gone.
		$swapped = $info && ! empty( $info['new'] ) && ! self::exists( $db, self::temp_name( $info['new'][0] ) ) && self::exists( $db, $info['new'][0] );
		if ( $info && ! $swapped ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( $swapped ) {
			$renames = array();
			foreach ( $info['new'] as $final ) {
				$old  = $info['tables'][ $final ] ?? null;
				$temp = self::temp_name( $final );
				if ( $old ) {
					$renames[] = DTC_Bk_Db_Dump::q( $final ) . ' TO ' . DTC_Bk_Db_Dump::q( $temp ) . ', ' . DTC_Bk_Db_Dump::q( $old ) . ' TO ' . DTC_Bk_Db_Dump::q( $final );
				} else {
					$renames[] = DTC_Bk_Db_Dump::q( $final ) . ' TO ' . DTC_Bk_Db_Dump::q( $temp );
				}
			}
			$res = $db->query( 'RENAME TABLE ' . implode( ', ', $renames ) );
			if ( false === $res ) {
				throw new DTC_Bk_Exception( 'Could not roll back the database swap: ' . $db->error );
			}
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		self::drop_temp();
	}

	/** Drop the replaced tables (after the restored site was checked). */
	public static function drop_old( $min_age = 0 ) {
		$file = DTC_Bk_Util::data_dir() . '/' . self::OLD_FILE;
		$info = DTC_Bk_Util::read_json( $file );
		if ( ! $info || time() - (int) $info['time'] < $min_age ) {
			return false;
		}
		$db = DTC_Bk_Db_Dump::connection();
		$db->query( 'SET FOREIGN_KEY_CHECKS=0' );
		foreach ( (array) $info['tables'] as $old ) {
			$db->query( 'DROP TABLE IF EXISTS ' . DTC_Bk_Db_Dump::q( $old ) );
		}
		@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return true;
	}
}
