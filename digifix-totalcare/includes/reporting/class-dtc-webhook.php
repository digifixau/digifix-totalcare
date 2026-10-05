<?php
/**
 * JSON webhook (Slack incoming webhook, n8n, Zapier...).
 *
 * Body: {"event","site","site_url","time","data"}.
 * Header X-DTC-Signature: sha256=HMAC_SHA256(body, webhook secret).
 * Slack incoming-webhook URLs get a "text" summary added automatically.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class DTC_Webhook {

	public static function send( $event, array $data, $job_id = 0 ) {
		$url = DTC_Settings::get( 'webhook_url' );
		if ( ! $url ) {
			return false;
		}

		$body = array(
			'event'    => $event,
			'site'     => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'site_url' => home_url(),
			'client'   => DTC_Settings::get( 'client_name' ),
			'time'     => gmdate( 'c' ),
			'job_id'   => (int) $job_id,
			'data'     => $data,
		);
		if ( false !== strpos( $url, 'hooks.slack.com' ) ) {
			$mail         = DTC_Notifier::compose( $event, 'report.monthly' === $event ? array( 'report' => $data['report'] + array( 'events' => array() ) ) : $data );
			$body['text'] = sprintf( '*%s* — %s%s', $mail['title'], $body['site'], $mail['intro'] ? "\n" . $mail['intro'] : '' );
		}

		$json    = wp_json_encode( $body );
		$headers = array(
			'Content-Type' => 'application/json',
			'X-DTC-Event'  => $event,
		);
		$secret  = DTC_Settings::get( 'webhook_secret' );
		if ( $secret ) {
			$headers['X-DTC-Signature'] = 'sha256=' . hash_hmac( 'sha256', $json, $secret );
		}

		$resp = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => $headers,
				'body'    => $json,
			)
		);
		$code = is_wp_error( $resp ) ? 0 : (int) wp_remote_retrieve_response_code( $resp );
		if ( $code < 200 || $code >= 300 ) {
			DTC_Logger::log( 'report', 'warning', 'report.webhook_failed', 'Webhook "' . $event . '" failed: ' . ( is_wp_error( $resp ) ? $resp->get_error_message() : 'HTTP ' . $code ), array( 'url' => $url ), $job_id );
			return false;
		}
		return true;
	}
}
