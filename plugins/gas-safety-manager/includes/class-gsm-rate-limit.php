<?php
/**
 * Lightweight per-IP rate limiter (transient-based, no third party).
 *
 * Fixed-window approximation: each bucket counts hits from one IP within a
 * window. Used to protect public and abuse-prone endpoints (fault reporting,
 * bridge, attachment download, broadcast).
 *
 * @package GasSafetyManager
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Rate_Limit
 */
class GSM_Rate_Limit {

	/**
	 * Return true when the caller has exceeded the limit for this bucket.
	 *
	 * @param string $bucket      Logical action name (e.g. 'report-fault').
	 * @param int    $max         Max hits allowed per window.
	 * @param int    $window_secs Window length in seconds.
	 * @return bool True = blocked (too many), false = allowed (and counted).
	 */
	public static function too_many( $bucket, $max, $window_secs ) {
		$key = 'gsm_rl_' . md5( (string) $bucket . '|' . self::client_ip() );
		$n   = (int) get_transient( $key );
		if ( $n >= (int) $max ) {
			return true;
		}
		set_transient( $key, $n + 1, max( 1, (int) $window_secs ) );
		return false;
	}

	/**
	 * Best-effort client IP (never trusted for security decisions, only for
	 * coarse rate buckets). Prefers REMOTE_ADDR.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '0.0.0.0';
		$ip = is_string( $ip ) ? trim( $ip ) : '0.0.0.0';
		return filter_var( $ip, FILTER_VALIDATE_IP ) ? $ip : '0.0.0.0';
	}

	/**
	 * Standard WP_Error for a throttled request.
	 *
	 * @return WP_Error
	 */
	public static function error() {
		return new WP_Error( 'gsm_rate_limited', __( 'יותר מדי בקשות. נסו שוב בעוד מספר דקות.', 'gas-safety-manager' ), array( 'status' => 429 ) );
	}
}
