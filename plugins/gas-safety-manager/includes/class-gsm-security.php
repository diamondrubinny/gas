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
