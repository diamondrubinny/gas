<?php
/**
 * Outgoing integrations: signed webhooks to an external endpoint
 * (e.g. Make / Zapier / n8n / an internal listener) on domain events.
 *
 * Config is stored in the gsm_integrations option:
 *   {
 *     "webhookUrl":    "https://…",
 *     "webhookSecret": "…",            // used to sign the payload (HMAC-SHA256)
 *     "webhookEvents": ["fault.created","inspection.created"]
 *   }
 *
 * Firing is best-effort and non-blocking: a slow or dead endpoint never
 * blocks the user's action, and failures are swallowed (not surfaced to the
 * reporter). No secret is ever sent to the browser.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Integrations
 */
class GSM_Integrations {

	const OPTION = 'gsm_integrations';

	/**
	 * Known event keys (for the settings UI).
	 *
	 * @return array
	 */
	public static function events() {
		return array(
			'fault.created'      => 'תקלה חדשה נפתחה',
			'fault.updated'      => 'תקלה עודכנה',
			'inspection.created' => 'ביקורת חדשה נרשמה',
			'equipment.created'  => 'ציוד חדש נוסף',
		);
	}

	/**
	 * Current config with defaults.
	 *
	 * @return array
	 */
	public static function config() {
		$raw = get_option( self::OPTION, array() );
		if ( ! is_array( $raw ) ) {
			$raw = array();
		}
		return array(
			'webhookUrl'    => isset( $raw['webhookUrl'] ) ? (string) $raw['webhookUrl'] : '',
			'webhookSecret' => isset( $raw['webhookSecret'] ) ? (string) $raw['webhookSecret'] : '',
			'webhookEvents' => isset( $raw['webhookEvents'] ) && is_array( $raw['webhookEvents'] ) ? array_values( $raw['webhookEvents'] ) : array(),
		);
	}

	/**
	 * Config safe for the browser (secret masked, never the real value).
	 *
	 * @return array
	 */
	public static function public_config() {
		$c = self::config();
		return array(
			'webhookUrl'    => $c['webhookUrl'],
			'hasSecret'     => '' !== $c['webhookSecret'],
			'webhookEvents' => $c['webhookEvents'],
			'eventLabels'   => self::events(),
		);
	}

	/**
	 * Persist config. Only a non-empty secret replaces the stored one, so the
	 * UI can save without ever re-sending the secret.
	 *
	 * @param array $data Incoming data.
	 * @return array public_config()
	 */
	public static function save( $data ) {
		$cur = self::config();

		$url = isset( $data['webhookUrl'] ) ? esc_url_raw( trim( (string) $data['webhookUrl'] ) ) : '';
		// Only allow http(s).
		if ( '' !== $url && ! preg_match( '#^https?://#i', $url ) ) {
			$url = '';
		}

		$events = array();
		if ( isset( $data['webhookEvents'] ) && is_array( $data['webhookEvents'] ) ) {
			$known = array_keys( self::events() );
			foreach ( $data['webhookEvents'] as $e ) {
				$e = (string) $e;
				if ( in_array( $e, $known, true ) ) {
					$events[] = $e;
				}
			}
		}

		$secret = $cur['webhookSecret'];
		if ( isset( $data['webhookSecret'] ) && '' !== trim( (string) $data['webhookSecret'] ) ) {
			$secret = trim( (string) $data['webhookSecret'] );
		}
		// Explicit clear.
		if ( ! empty( $data['clearSecret'] ) ) {
			$secret = '';
		}
		// The signing secret does not follow the URL to a different host unless
		// it is re-entered (otherwise a new endpoint could collect signatures).
		$new_secret = isset( $data['webhookSecret'] ) && '' !== trim( (string) $data['webhookSecret'] );
		if ( ! $new_secret && '' !== $secret && GSM_Security::url_host( $url ) !== GSM_Security::url_host( $cur['webhookUrl'] ) ) {
			$secret = '';
		}

		update_option(
			self::OPTION,
			array(
				'webhookUrl'    => $url,
				'webhookSecret' => $secret,
				'webhookEvents' => $events,
			)
		);
		return self::public_config();
	}

	/**
	 * Fire a webhook for an event (best-effort, non-blocking).
	 *
	 * @param string $event   Event key.
	 * @param array  $payload Payload data.
	 * @return void
	 */
	public static function fire_webhook( $event, $payload ) {
		$c = self::config();
		if ( '' === $c['webhookUrl'] ) {
			return;
		}
		if ( ! empty( $c['webhookEvents'] ) && ! in_array( $event, $c['webhookEvents'], true ) ) {
			return;
		}

		$body = wp_json_encode(
			array(
				'event'     => $event,
				'timestamp' => current_time( 'mysql' ),
				'site'      => home_url( '/' ),
				'data'      => $payload,
			),
			JSON_UNESCAPED_UNICODE
		);

		$headers = array( 'Content-Type' => 'application/json; charset=utf-8' );
		if ( '' !== $c['webhookSecret'] ) {
			$headers['X-Gas-Signature'] = 'sha256=' . hash_hmac( 'sha256', (string) $body, $c['webhookSecret'] );
		}

		// Non-blocking: don't delay the user's request on the remote endpoint.
		GSM_Security::outbound_post(
			$c['webhookUrl'],
			array(
				'timeout'     => 5,
				'blocking'    => false,
				'headers'     => $headers,
				'body'        => $body,
				'redirection' => 0,
			)
		);
	}
}
