<?php
/**
 * Database dump, resumable between requests.
 *
 * Output format (db.sql.gz), one statement per line:
 *   -- DTC-SQL 1 charset=utf8mb4
 *   CREATE TABLE `wp_posts` (...);
 *   INSERT INTO `wp_posts` (`ID`,`post_author`,...) VALUES (...),(...);
 *   -- DTC-SQL-END
 *
 * Rows are read in buffered batches sized by bytes (about 2 MB) and paged by
 * primary key (keyset), so memory stays flat and large tables do not slow
 * down like LIMIT/OFFSET. The result is freed before anything else touches
 * the connection.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Bk_Db_Dump {

	const BATCH_BYTES = 2097152;
	const STMT_BYTES  = 524288;
	const LEVEL       = 3;

	const NUMERIC = array( 0, 1, 2, 3, 4, 5, 8, 9, 13, 246 );
	const BLOBISH = array( 249, 250, 251, 252, 253, 254, 255 );

	/** @var mysqli */
	private $db;

	/** @var DTC_Bk_Gz_Writer */
	private $w;

	private $st;

	public static function connection() {
		global $wpdb;
		$dbh = $wpdb->dbh;
		if ( ! ( $dbh instanceof mysqli ) ) {
			throw new DTC_Bk_Exception( 'The database connection is not MySQL/MariaDB via mysqli (SQLite and custom database drop-ins are not supported).' );
		}
		return $dbh;
	}

	public static function q( $name ) {
		return '`' . str_replace( '`', '``', $name ) . '`';
	}

	/**
	 * @param string $scope    prefix|all
	 * @param array  $excluded Table names never dumped.
	 */
	public static function init_state( $scope, array $excluded ) {
		global $wpdb;
		$db       = self::connection();
		$prefix   = $wpdb->prefix;
		$excluded = array_merge( $excluded, array( $prefix . 'dtc_jobs', $prefix . 'dtc_events' ) );

		$info = array();
		$res  = $db->query( 'SELECT TABLE_NAME, AVG_ROW_LENGTH, TABLE_ROWS, DATA_LENGTH FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()' );
		if ( $res ) {
			while ( $row = $res->fetch_row() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
				$info[ $row[0] ] = array( (int) $row[1], (int) $row[2], (int) $row[3] );
			}
			$res->free();
		}

		$tables   = array();
		$warnings = array();
		$res      = $db->query( 'SHOW FULL TABLES' );
		if ( ! $res ) {
			throw new DTC_Bk_Exception( 'Could not list database tables: ' . $db->error );
		}
		while ( $row = $res->fetch_row() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$name = $row[0];
			if ( 'prefix' === $scope && 0 !== strpos( $name, $prefix ) ) {
				continue;
			}
			if ( in_array( $name, $excluded, true ) || preg_match( '/^dtc(r|old)_/', $name ) ) {
				continue;
			}
			if ( 'BASE TABLE' !== strtoupper( (string) $row[1] ) ) {
				$warnings[] = 'View ' . $name . ' is not backed up.';
				continue;
			}
			$tables[] = array( $name, $info[ $name ][0] ?? 0, $info[ $name ][1] ?? 0, $info[ $name ][2] ?? 0 );
		}
		$res->free();

		$res = $db->query( 'SHOW TRIGGERS' );
		if ( $res ) {
			if ( $res->num_rows ) {
				$warnings[] = $res->num_rows . ' database trigger(s) are not backed up.';
			}
			$res->free();
		}

		return array(
			'tables'   => $tables,
			'i'        => 0,
			'cur'      => null,
			'header'   => false,
			'done'     => false,
			'rows'     => 0,
			'max_stmt' => 0,
			'charset'  => $wpdb->charset,
			'warnings' => $warnings,
		);
	}

	public function __construct( DTC_Bk_Gz_Writer $writer, array $state ) {
		$this->db = self::connection();
		$this->w  = $writer;
		$this->st = $state;
	}

	public function state() {
		return $this->st;
	}

	/** Dump until done or the deadline. @return bool True when finished. */
	public function run( $deadline ) {
		if ( ! $this->st['header'] ) {
			$this->emit( '-- DTC-SQL 1 charset=' . $this->st['charset'] . "\n" );
			$this->st['header'] = true;
		}
		while ( microtime( true ) < $deadline ) {
			if ( $this->st['i'] >= count( $this->st['tables'] ) ) {
				$this->emit( "-- DTC-SQL-END\n" );
				$this->st['done'] = true;
				return true;
			}
			$this->table_step();
		}
		return false;
	}

	private function emit( $line ) {
		if ( $this->w->member_open() && $this->w->member_full() ) {
			$this->w->end_member();
		}
		if ( ! $this->w->member_open() ) {
			$this->w->begin_member( self::LEVEL );
		}
		$this->w->write( $line );
		$this->st['max_stmt'] = max( $this->st['max_stmt'], strlen( $line ) );
	}

	private function query( $sql ) {
		$res = $this->db->query( $sql );
		if ( false === $res ) {
			throw new DTC_Bk_Exception( 'Database error during backup: ' . $this->db->error . ' [' . substr( $sql, 0, 200 ) . ']' );
		}
		return $res;
	}

	private function table_step() {
		$t    = $this->st['tables'][ $this->st['i'] ];
		$name = $t[0];

		if ( null === $this->st['cur'] ) {
			$this->st['cur'] = $this->start_table( $name, (int) $t[1] );
			return;
		}
		$cur = $this->st['cur'];

		$where = array();
		if ( 'offset' !== $cur['mode'] && null !== $cur['last'] ) {
			$where[] = $this->key_condition( $cur );
		}
		if ( $cur['options'] ) {
			$where[] = 'option_name NOT LIKE \'\\\\_transient\\\\_%\' AND option_name NOT LIKE \'\\\\_site\\\\_transient\\\\_%\'';
		}
		$sql = 'SELECT ' . implode( ',', array_map( array( __CLASS__, 'q' ), $cur['cols'] ) ) . ' FROM ' . self::q( $name )
			. ( $where ? ' WHERE ' . implode( ' AND ', $where ) : '' )
			. ( 'offset' !== $cur['mode'] ? ' ORDER BY ' . implode( ',', array_map( array( __CLASS__, 'q' ), $cur['key'] ) ) : '' )
			. ' LIMIT ' . ( 'offset' === $cur['mode'] ? (int) $cur['offset'] . ',' : '' ) . (int) $cur['batch'];

		$res    = $this->query( $sql );
		$fields = $res->fetch_fields();
		$kind   = array();
		foreach ( $fields as $f ) {
			if ( in_array( (int) $f->type, self::NUMERIC, true ) ) {
				$kind[] = 'n';
			} elseif ( 16 === (int) $f->type || ( 63 === (int) $f->charsetnr && in_array( (int) $f->type, self::BLOBISH, true ) ) ) {
				$kind[] = 'b';
			} else {
				$kind[] = 's';
			}
		}

		$head  = 'INSERT INTO ' . self::q( $name ) . ' (' . implode( ',', array_map( array( __CLASS__, 'q' ), $cur['cols'] ) ) . ') VALUES ';
		$buf   = '';
		$count = 0;
		$bytes = 0;
		$last  = null;
		while ( $row = $res->fetch_row() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			$vals = array();
			foreach ( $row as $i => $v ) {
				if ( null === $v ) {
					$vals[] = 'NULL';
				} elseif ( 'n' === $kind[ $i ] ) {
					$vals[] = '' === $v ? "''" : $v;
				} elseif ( 'b' === $kind[ $i ] ) {
					$vals[] = '' === $v ? "''" : 'X\'' . bin2hex( $v ) . '\'';
				} else {
					$vals[] = "'" . $this->db->real_escape_string( $v ) . "'";
				}
			}
			$tuple  = '(' . implode( ',', $vals ) . ')';
			$bytes += strlen( $tuple );
			if ( '' !== $buf && strlen( $buf ) + strlen( $tuple ) > self::STMT_BYTES ) {
				$this->emit( $head . $buf . ";\n" );
				$buf = '';
			}
			$buf .= ( '' === $buf ? '' : ',' ) . $tuple;
			++$count;
			$last = $row;
		}
		$res->free();
		if ( '' !== $buf ) {
			$this->emit( $head . $buf . ";\n" );
		}

		$this->st['rows'] += $count;
		if ( $count < $cur['batch'] ) {
			$this->st['cur'] = null;
			$this->st['i']++;
			return;
		}
		if ( 'offset' === $cur['mode'] ) {
			$cur['offset'] += $count;
		} else {
			$cur['last'] = array();
			foreach ( $cur['key_pos'] as $p ) {
				$cur['last'][] = bin2hex( (string) $last[ $p ] );
			}
		}
		// Rows bigger than the table average: shrink the next batch.
		if ( $bytes > 4 * self::BATCH_BYTES ) {
			$cur['batch'] = max( 1, (int) ( $cur['batch'] / 2 ) );
		}
		$this->st['cur'] = $cur;
	}

	private function key_condition( array $cur ) {
		$vals = array();
		foreach ( $cur['last'] as $i => $hex ) {
			$v = (string) hex2bin( $hex );
			if ( $cur['key_num'][ $i ] && preg_match( '/^-?[0-9]+(\.[0-9]+)?$/', $v ) ) {
				$vals[] = $v;
			} else {
				$vals[] = "'" . $this->db->real_escape_string( $v ) . "'";
			}
		}
		$cols = array_map( array( __CLASS__, 'q' ), $cur['key'] );
		if ( 1 === count( $cols ) ) {
			return $cols[0] . ' > ' . $vals[0];
		}
		return '(' . implode( ',', $cols ) . ') > (' . implode( ',', $vals ) . ')';
	}

	private function start_table( $name, $avg ) {
		$res = $this->query( 'SHOW CREATE TABLE ' . self::q( $name ) );
		$row = $res->fetch_row();
		$res->free();
		$create = str_replace( array( "\r\n", "\n", "\r" ), ' ', (string) $row[1] );
		$this->emit( $create . ";\n" );

		$cols     = array();
		$numeric  = array();
		$nullable = array();
		$res      = $this->query( 'SHOW FULL COLUMNS FROM ' . self::q( $name ) );
		while ( $c = $res->fetch_assoc() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( preg_match( '/GENERATED|PERSISTENT|VIRTUAL/i', (string) $c['Extra'] ) ) {
				continue;
			}
			$cols[]                  = $c['Field'];
			$numeric[ $c['Field'] ]  = (bool) preg_match( '/^(tinyint|smallint|mediumint|int|integer|bigint|decimal|numeric|float|double|real|year)/i', (string) $c['Type'] );
			$nullable[ $c['Field'] ] = 'YES' === $c['Null'];
		}
		$res->free();

		// Primary key, else a unique key on NOT NULL columns, else OFFSET.
		$indexes = array();
		$res     = $this->query( 'SHOW INDEX FROM ' . self::q( $name ) );
		while ( $ix = $res->fetch_assoc() ) { // phpcs:ignore WordPress.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( '0' === (string) $ix['Non_unique'] ) {
				$indexes[ $ix['Key_name'] ][ (int) $ix['Seq_in_index'] ] = $ix['Column_name'];
			}
		}
		$res->free();
		$key = null;
		if ( isset( $indexes['PRIMARY'] ) ) {
			$key = $indexes['PRIMARY'];
		} else {
			foreach ( $indexes as $cols_ix ) {
				$ok = true;
				foreach ( $cols_ix as $col ) {
					$ok = $ok && isset( $nullable[ $col ] ) && ! $nullable[ $col ];
				}
				if ( $ok ) {
					$key = $cols_ix;
					break;
				}
			}
		}
		$mode    = 'offset';
		$key_pos = array();
		$key_num = array();
		if ( $key ) {
			ksort( $key );
			$key  = array_values( $key );
			$mode = 'key';
			foreach ( $key as $col ) {
				$pos = array_search( $col, $cols, true );
				if ( false === $pos ) {
					$mode = 'offset';
					break;
				}
				$key_pos[] = $pos;
				$key_num[] = $numeric[ $col ];
			}
		}

		global $wpdb;
		return array(
			'cols'    => $cols,
			'mode'    => $mode,
			'key'     => 'key' === $mode ? $key : array(),
			'key_pos' => $key_pos,
			'key_num' => $key_num,
			'last'    => null,
			'offset'  => 0,
			'batch'   => max( 1, min( 2000, (int) ( self::BATCH_BYTES / max( 64, $avg ) ) ) ),
			'options' => $name === $wpdb->prefix . 'options',
		);
	}
}
