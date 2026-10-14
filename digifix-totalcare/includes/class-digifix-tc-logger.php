<?php
/**
 * Event log stored in {prefix}digifix_tc_events. Feeds the dashboard, the activity
 * log and the reports.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// TotalCare's own job and activity-log tables. Job state changes between requests, so these
// queries must not be served from the object cache.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

class Digifix_TC_Logger {

	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'digifix_tc_events';
	}

	/**
	 * @param string $type    backup|update|scan|health|restore|report|system.
	 * @param string $level   info|success|warning|error.
	 * @param string $code    Machine readable event code, e.g. update.item_updated.
	 * @param string $message Human readable message.
	 * @param array  $context Structured data used by reports.
	 * @param int    $job_id  Related job.
	 */
	public static function log( $type, $level, $code, $message, array $context = array(), $job_id = 0 ) {
		global $wpdb;
		$wpdb->insert(
			self::table(),
			array(
				'created_at' => current_time( 'mysql', true ),
				'type'       => $type,
				'level'      => $level,
				'code'       => $code,
				'message'    => $message,
				'context'    => wp_json_encode( $context ),
				'job_id'     => (int) $job_id,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%d' )
		);
		return (int) $wpdb->insert_id;
	}

	public static function query( array $args = array() ) {
		global $wpdb;
		$args = wp_parse_args(
			$args,
			array(
				'type'     => '',
				'level'    => '',
				'code'     => '',
				'since'    => '',
				'until'    => '',
				'per_page' => 50,
				'page'     => 1,
			)
		);

		$where  = array( '1=1' );
		$params = array();
		foreach ( array( 'type', 'level' ) as $col ) {
			if ( '' !== $args[ $col ] ) {
				$where[]  = "$col = %s";
				$params[] = $args[ $col ];
			}
		}
		if ( '' !== $args['code'] ) {
			$where[]  = 'code LIKE %s';
			$params[] = $wpdb->esc_like( $args['code'] ) . '%';
		}
		if ( '' !== $args['since'] ) {
			$where[]  = 'created_at >= %s';
			$params[] = $args['since'];
		}
		if ( '' !== $args['until'] ) {
			$where[]  = 'created_at < %s';
			$params[] = $args['until'];
		}

		$table  = self::table();
		$sql    = "SELECT * FROM $table WHERE " . implode( ' AND ', $where ) . ' ORDER BY id DESC';
		$count  = "SELECT COUNT(*) FROM $table WHERE " . implode( ' AND ', $where );
		$limit  = max( 1, (int) $args['per_page'] );
		$offset = ( max( 1, (int) $args['page'] ) - 1 ) * $limit;
		$sql   .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $limit, $offset );

		// The WHERE clause is built only from the fixed fragments above; every value is bound through prepare().
		// phpcs:disable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows  = $params ? $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ) : $wpdb->get_results( $sql, ARRAY_A );
		$total = (int) ( $params ? $wpdb->get_var( $wpdb->prepare( $count, $params ) ) : $wpdb->get_var( $count ) );
		// phpcs:enable WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

		foreach ( $rows as &$row ) {
			$row['context'] = json_decode( (string) $row['context'], true ) ?: array();
		}
		return array(
			'rows'  => $rows,
			'total' => $total,
		);
	}

	public static function latest( $code ) {
		$res = self::query(
			array(
				'code'     => $code,
				'per_page' => 1,
			)
		);
		return $res['rows'] ? $res['rows'][0] : null;
	}

	public static function prune( $days = 400 ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE created_at < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}
}
