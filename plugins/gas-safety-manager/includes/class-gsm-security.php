<?php
/**
 * Security hardening — safe security headers for the front-end.
 *
 * Adds a conservative set of headers that do not break normal WordPress
 * pages. A strict Content-Security-Policy is intentionally NOT enforced here
 * (it can break other plugins/themes); it is recommended at the web-server
 * level after testing. HSTS is likewise recommended at the server/CDN.
 *
 * @package GasSafetyManager
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Security
 */
class GSM_Security {

	/**
	 * Per-request CSP nonce ('' until a CSP is sent on this request).
	 *
	 * @var string
	 */
	private static $nonce = '';

	/**
	 * Hook headers on the front-end only.
	 */
	public static function init() {
		add_action( 'send_headers', array( __CLASS__, 'headers' ) );
	}

	/**
	 * Emit safe, site-wide front-end security headers.
	 */
	public static function headers() {
		if ( is_admin() || headers_sent() ) {
			return;
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Cross-Origin-Opener-Policy: same-origin' );
	}

	/**
	 * CSP mode: 'enforce' (default), 'report-only' or 'off'.
	 *
	 * Override in wp-config.php: define( 'GSM_CSP_MODE', 'report-only' );
	 * or with the 'gsm_csp_mode' filter. Use report-only to diagnose a
	 * third-party plugin whose inline script gets blocked on the app pages.
	 *
	 * @return string
	 */
	public static function csp_mode() {
		$mode = defined( 'GSM_CSP_MODE' ) ? (string) GSM_CSP_MODE : 'enforce';
		$mode = (string) apply_filters( 'gsm_csp_mode', $mode );
		return in_array( $mode, array( 'enforce', 'report-only', 'off' ), true ) ? $mode : 'enforce';
	}

	/**
	 * Is the current request one of the plugin's own front-end pages
	 * (the [gas_app] SPA or the public [gas_bridge] QR page)?
	 *
	 * @return bool
	 */
	public static function is_app_request() {
		if ( is_admin() || ! is_singular() ) {
			return false;
		}
		$id  = (int) get_queried_object_id();
		$ids = array( (int) get_option( 'gsm_app_page_id', 0 ), (int) get_option( 'gsm_bridge_page_id', 0 ) );
		if ( $id && in_array( $id, $ids, true ) ) {
			return true;
		}
		$post = get_post( $id );
		return $post && ( has_shortcode( $post->post_content, 'gas_app' ) || has_shortcode( $post->post_content, 'gas_bridge' ) );
	}

	/**
	 * Build the policy string.
	 *
	 * script-src is strict ('self' + per-request nonce, no 'unsafe-inline',
	 * no 'unsafe-eval'): an injected <script> or on*="" handler will not run.
	 * style-src keeps 'unsafe-inline' because the UI uses style="" attributes;
	 * CSS can not execute script, so this does not weaken the XSS protection.
	 *
	 * @param string $nonce Nonce.
	 * @return string
	 */
	public static function csp_policy( $nonce ) {
		$d = array(
			'default-src'     => "'self'",
			'script-src'      => "'self' 'nonce-" . $nonce . "'",
			'style-src'       => "'self' 'unsafe-inline' https://fonts.googleapis.com",
			'font-src'        => "'self' data: https://fonts.gstatic.com",
			// https: for an optional external header logo (Branding) / avatars.
			'img-src'         => "'self' data: blob: https:",
			'connect-src'     => "'self'",
			// WordPress core's emoji loader runs its feature test in a blob: Worker.
			'worker-src'      => "'self' blob:",
			'frame-src'       => "'self'",
			'object-src'      => "'none'",
			'base-uri'        => "'self'",
			'form-action'     => "'self'",
			'frame-ancestors' => "'self'",
		);
		/**
		 * Filter the CSP directives (directive => sources) for the app pages.
		 *
		 * @param array  $d     Directives.
		 * @param string $nonce The request nonce (for script-src additions).
		 */
		$d   = (array) apply_filters( 'gsm_csp_directives', $d, $nonce );
		$out = array();
		foreach ( $d as $k => $v ) {
			$k = preg_replace( '/[^a-z\-]/', '', strtolower( (string) $k ) );
			$v = str_replace( array( ';', "\r", "\n" ), '', (string) $v );
			if ( '' !== $k ) {
				$out[] = trim( $k . ' ' . $v );
			}
		}
		if ( is_ssl() ) {
			$out[] = 'upgrade-insecure-requests';
		}
		return implode( '; ', $out );
	}

	/**
	 * template_redirect: send the CSP header on the app / bridge pages.
	 */
	public static function csp() {
		$mode = self::csp_mode();
		if ( 'off' === $mode || headers_sent() || ! self::is_app_request() ) {
			return;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- encodes random bytes for a CSP nonce.
		self::$nonce = rtrim( strtr( base64_encode( random_bytes( 18 ) ), '+/', '-_' ), '=' );
		$name        = ( 'report-only' === $mode ) ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
		header( $name . ': ' . self::csp_policy( self::$nonce ) );
	}

	/**
	 * Current request nonce ('' when no CSP was sent).
	 *
	 * @return string
	 */
	public static function nonce() {
		return self::$nonce;
	}

	/**
	 * Add the CSP nonce to <script> tags WordPress prints (inline + external),
	 * e.g. window.GSM_CONFIG and core's own inline scripts.
	 *
	 * @param array $attributes Tag attributes.
	 * @return array
	 */
	public static function script_nonce_attr( $attributes ) {
		if ( '' !== self::$nonce && is_array( $attributes ) && ! isset( $attributes['nonce'] ) ) {
			$attributes['nonce'] = self::$nonce;
		}
		return $attributes;
	}

	/**
	 * Headers for a raw file/stream response (attachments, exports): never let
	 * the browser sniff or frame it.
	 */
	public static function stream_headers() {
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Cache-Control: private, no-store, max-age=0' );
	}

	/**
	 * Neutralize a CSV cell against formula / DDE injection (CWE-1236).
	 *
	 * Spreadsheet apps (Excel, LibreOffice, Google Sheets) evaluate a cell that
	 * starts with = + - @ (or a tab / CR before one) as a formula. Untrusted
	 * text such as a public, anonymous fault report could otherwise run
	 * =HYPERLINK(...) or DDE payloads on the manager's machine when the export
	 * is opened. Such cells get a leading apostrophe, which spreadsheets show
	 * as plain text. Plain numbers (e.g. "-5", "+972...") are left unchanged.
	 *
	 * @param mixed $value Cell value.
	 * @return string Safe, unquoted cell text (caller still applies CSV quoting).
	 */
	public static function csv_cell( $value ) {
		$v = (string) ( null === $value ? '' : $value );
		if ( '' !== $v && preg_match( '/^[=+\-@\t\r]/', $v ) && ! is_numeric( $v ) ) {
			$v = "'" . $v;
		}
		return $v;
	}
}
