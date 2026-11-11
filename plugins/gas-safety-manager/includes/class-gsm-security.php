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
			'style-src'       => "'self' 'unsafe-inline'",
			// Rubik is bundled locally — no third-party font hosts.
			'font-src'        => "'self' data:",
			// https: for an optional external header logo (Branding) / avatars.
			'img-src'         => "'self' data: blob: https:",
			'connect-src'     => "'self'",
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
	 * template_redirect: on the app / bridge pages, turn off WordPress core's
	 * emoji replacement. It swaps emoji in the UI for images from s.w.org
	 * (a third-party host: visitors' IP addresses leave the site, and the
	 * images break on networks that block external hosts). Every current
	 * browser renders emoji natively. The rest of the site is untouched.
	 */
	public static function app_page_privacy() {
		if ( ! self::is_app_request() ) {
			return;
		}
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'wp_enqueue_scripts', 'wp_enqueue_emoji_styles' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		// No Gravatar on these pages: the admin bar of a logged-in WordPress user
		// would otherwise load their avatar from gravatar.com (third party: email
		// hash + IP). The admin bar itself stays.
		add_filter( 'pre_option_show_avatars', '__return_zero' );
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
	 * Lower-cased host of a URL ('' when none).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function url_host( $url ) {
		$h = wp_parse_url( (string) $url, PHP_URL_HOST );
		return is_string( $h ) ? strtolower( $h ) : '';
	}

	/**
	 * Outbound POST to an admin-configured URL (webhook / WhatsApp gateway).
	 * Uses wp_safe_remote_post, which refuses loopback, private and link-local
	 * addresses (SSRF: 127.0.0.1, 10.x, 169.254.169.254 metadata, …) and odd
	 * ports. A site that really posts to an internal listener can opt in with
	 * define( 'GSM_ALLOW_INTERNAL_WEBHOOKS', true ) in wp-config.php.
	 *
	 * @param string $url  URL.
	 * @param array  $args wp_remote_post args.
	 * @return array|WP_Error
	 */
	public static function outbound_post( $url, $args ) {
		$args['redirection'] = 0; // A redirect could bounce to an internal host.
		if ( defined( 'GSM_ALLOW_INTERNAL_WEBHOOKS' ) && GSM_ALLOW_INTERNAL_WEBHOOKS ) {
			return wp_remote_post( $url, $args );
		}
		// Own check first: older WordPress (6.4) does not treat 169.254.0.0/16
		// (cloud metadata) as unsafe, and wp_safe_remote_post allows the
		// site's own host even when it is a loopback address.
		if ( ! self::is_public_destination( $url ) ) {
			return new WP_Error( 'gsm_unsafe_url', __( 'כתובת היעד אינה מותרת (רשת פנימית או כתובת שמורה).', 'gas-safety-manager' ) );
		}
		return wp_safe_remote_post( $url, $args );
	}

	/**
	 * GET to an outside URL with the same internal-network guard as
	 * outbound_post(): no redirects, public destinations only.
	 *
	 * @param string $url  URL.
	 * @param array  $args wp_remote_get args.
	 * @return array|WP_Error
	 */
	public static function outbound_get( $url, $args ) {
		$args['redirection'] = 0;
		if ( defined( 'GSM_ALLOW_INTERNAL_WEBHOOKS' ) && GSM_ALLOW_INTERNAL_WEBHOOKS ) {
			return wp_remote_get( $url, $args );
		}
		if ( ! self::is_public_destination( $url ) ) {
			return new WP_Error( 'gsm_unsafe_url', __( 'כתובת היעד אינה מותרת (רשת פנימית או כתובת שמורה).', 'gas-safety-manager' ) );
		}
		return wp_safe_remote_get( $url, $args );
	}

	/**
	 * Does the URL's host resolve only to public IP addresses?
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_public_destination( $url ) {
		$host = self::url_host( $url );
		if ( '' === $host ) {
			return false;
		}
		$host = trim( $host, '[]' );
		$ips  = array();
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			$ips[] = $host;
		} else {
			$v4 = gethostbynamel( $host );
			if ( is_array( $v4 ) ) {
				$ips = array_merge( $ips, $v4 );
			}
			if ( function_exists( 'dns_get_record' ) ) {
				$v6 = @dns_get_record( $host, DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				foreach ( (array) $v6 as $rec ) {
					if ( ! empty( $rec['ipv6'] ) ) {
						$ips[] = $rec['ipv6'];
					}
				}
			}
		}
		if ( empty( $ips ) ) {
			return false; // Unresolvable: refuse rather than guess.
		}
		foreach ( $ips as $ip ) {
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}
		return true;
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
