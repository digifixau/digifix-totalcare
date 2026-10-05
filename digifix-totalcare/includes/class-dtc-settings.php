<?php
/**
 * Plugin settings stored in a single option.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Settings {

	const OPTION = 'dtc_settings';

	public static function defaults() {
		return array(
			// Backups.
			'backup_enabled'        => 1,
			'backup_frequency'      => 'daily', // twicedaily|daily|weekly.
			'backup_day'            => 'sunday',
			'backup_time'           => '02:00',
			'backup_timeout_hours'  => 3,

			// S3 (secret is pushed to WPvivid, never kept here).
			's3_type'               => 'amazons3', // amazons3|r2|s3compat.
			's3_access'             => '',
			's3_bucket'             => '',
			's3_path'               => '',
			's3_endpoint'           => '',
			's3_remote_id'          => '',

			// Updates.
			'updates_enabled'       => 1,
			'update_day'            => 'tuesday',
			'update_time'           => '03:00',
			'update_plugins'        => 1,
			'update_themes'         => 1,
			'update_core'           => 'minor', // none|minor|major.
			'excluded_plugins'      => array(),
			'excluded_themes'       => array(),
			'health_urls'           => '',
			'health_strict'         => 0,
			'snapshot_keep_days'    => 14,

			// Scans.
			'scan_enabled'          => 1,
			'scan_frequency'        => 'weekly', // daily|weekly.
			'scan_day'              => 'wednesday',
			'scan_time'             => '04:00',
			'scan_type'             => 'standard', // quick|standard.
			'scan_alert_severity'   => 75,

			// Reporting.
			'client_name'           => '',
			'internal_emails'       => get_option( 'admin_email' ),
			'client_emails'         => '',
			'email_failures'        => 1,
			'email_update_summary'  => 1,
			'email_scan_summary'    => 0,
			'client_gets_summaries' => 0,
			'monthly_report'        => 1,
			'monthly_attach_pdf'    => 1,
			'brand_color'           => '#0b5cab',
			'webhook_url'           => '',
			'webhook_secret'        => '',
		);
	}

	public static function all() {
		$saved = get_option( self::OPTION, array() );
		return wp_parse_args( is_array( $saved ) ? $saved : array(), self::defaults() );
	}

	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	public static function update( array $values ) {
		$all = array_merge( self::all(), $values );
		update_option( self::OPTION, $all, false );
	}

	public static function emails( $key ) {
		$list = preg_split( '/[\s,;]+/', (string) self::get( $key ), -1, PREG_SPLIT_NO_EMPTY );
		return array_values( array_filter( array_map( 'sanitize_email', $list ), 'is_email' ) );
	}

	public static function sanitize( array $input ) {
		$d   = self::defaults();
		$out = self::all();

		$days = array( 'monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday' );
		$time = function ( $v, $fallback ) {
			return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $v ) ? $v : $fallback;
		};
		$pick = function ( $v, array $allowed, $fallback ) {
			return in_array( $v, $allowed, true ) ? $v : $fallback;
		};

		foreach ( array( 'backup_enabled', 'updates_enabled', 'update_plugins', 'update_themes', 'health_strict', 'scan_enabled', 'email_failures', 'email_update_summary', 'email_scan_summary', 'client_gets_summaries', 'monthly_report', 'monthly_attach_pdf' ) as $flag ) {
			$out[ $flag ] = empty( $input[ $flag ] ) ? 0 : 1;
		}

		$out['backup_frequency']     = $pick( $input['backup_frequency'] ?? '', array( 'twicedaily', 'daily', 'weekly' ), $d['backup_frequency'] );
		$out['backup_day']           = $pick( $input['backup_day'] ?? '', $days, $d['backup_day'] );
		$out['backup_time']          = $time( $input['backup_time'] ?? '', $d['backup_time'] );
		$out['backup_timeout_hours'] = max( 1, min( 12, (int) ( $input['backup_timeout_hours'] ?? 3 ) ) );

		$out['s3_type']     = $pick( $input['s3_type'] ?? '', array( 'amazons3', 'r2', 's3compat' ), $d['s3_type'] );
		$out['s3_access']   = sanitize_text_field( $input['s3_access'] ?? '' );
		$out['s3_bucket']   = sanitize_text_field( $input['s3_bucket'] ?? '' );
		$out['s3_path']     = trim( sanitize_text_field( $input['s3_path'] ?? '' ), '/' );
		$out['s3_endpoint'] = sanitize_text_field( $input['s3_endpoint'] ?? '' );

		$out['update_day']         = $pick( $input['update_day'] ?? '', $days, $d['update_day'] );
		$out['update_time']        = $time( $input['update_time'] ?? '', $d['update_time'] );
		$out['update_core']        = $pick( $input['update_core'] ?? '', array( 'none', 'minor', 'major' ), $d['update_core'] );
		$out['excluded_plugins']   = array_values( array_map( 'sanitize_text_field', (array) ( $input['excluded_plugins'] ?? array() ) ) );
		$out['excluded_themes']    = array_values( array_map( 'sanitize_text_field', (array) ( $input['excluded_themes'] ?? array() ) ) );
		$out['health_urls']        = implode( "\n", array_filter( array_map( 'esc_url_raw', preg_split( '/\R/', (string) ( $input['health_urls'] ?? '' ) ) ) ) );
		$out['snapshot_keep_days'] = max( 1, min( 90, (int) ( $input['snapshot_keep_days'] ?? 14 ) ) );

		$out['scan_frequency']      = $pick( $input['scan_frequency'] ?? '', array( 'daily', 'weekly' ), $d['scan_frequency'] );
		$out['scan_day']            = $pick( $input['scan_day'] ?? '', $days, $d['scan_day'] );
		$out['scan_time']           = $time( $input['scan_time'] ?? '', $d['scan_time'] );
		$out['scan_type']           = $pick( $input['scan_type'] ?? '', array( 'quick', 'standard' ), $d['scan_type'] );
		$out['scan_alert_severity'] = (int) $pick( (string) ( $input['scan_alert_severity'] ?? '' ), array( '25', '50', '75', '100' ), (string) $d['scan_alert_severity'] );

		$out['client_name']     = sanitize_text_field( $input['client_name'] ?? '' );
		$out['internal_emails'] = sanitize_textarea_field( $input['internal_emails'] ?? '' );
		$out['client_emails']   = sanitize_textarea_field( $input['client_emails'] ?? '' );
		$out['brand_color']     = sanitize_hex_color( $input['brand_color'] ?? '' ) ?: $d['brand_color'];
		$out['webhook_url']     = esc_url_raw( $input['webhook_url'] ?? '' );
		if ( isset( $input['webhook_secret'] ) && '' !== $input['webhook_secret'] ) {
			$out['webhook_secret'] = sanitize_text_field( $input['webhook_secret'] );
		}

		return $out;
	}
}
