<?php
/**
 * reCAPTCHA: settings rules, verification logic (Google's answers are
 * simulated), monitor vs enforce, fail-open, both protected endpoints.
 * Run: wp eval-file tests/integration/captcha-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
define( 'GSM_ALLOW_INTERNAL_WEBHOOKS', true ); // Skip the DNS check; HTTP is mocked below.
add_filter( 'send_auth_cookies', '__return_false' );
global $wpdb;
$fail = 0;
$t    = function ( $name, $ok, $extra = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $extra ? '' : ' — ' . $extra ) . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$rest = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $r );
};
$host   = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
$google = array( 'answer' => null, 'calls' => array() );
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$google ) {
		if ( 0 !== strpos( $url, 'https://www.google.com/recaptcha/api/siteverify' ) ) {
			return $pre;
		}
		$google['calls'][] = $args['body'];
		if ( 'down' === $google['answer'] ) {
			return new WP_Error( 'http_request_failed', 'timeout' );
		}
		return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $google['answer'] ), 'headers' => array(), 'cookies' => array() );
	},
	10,
	3
);
$good = function ( $action, $score = 0.9 ) use ( $host ) {
	return array( 'success' => true, 'score' => $score, 'action' => $action, 'hostname' => $host );
};
$SITE = str_repeat( 'S', 40 );
$SEC  = str_repeat( 'K', 40 );
$tag  = strtolower( wp_generate_password( 6, false ) );
$pass = 'Pw-' . wp_generate_password( 14, false ) . '-1a';
$uid  = wp_insert_user( array( 'user_login' => 'cap-' . $tag, 'user_email' => 'cap-' . $tag . '@example.test', 'user_pass' => $pass, 'role' => 'gsm_lab_manager' ) );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];
$before = get_option( GSM_Captcha::OPTION, null );
delete_option( GSM_Captcha::OPTION );
$clear  = function () {
	delete_transient( 'gsm_login_' . md5( GSM_Auth::client_ip() ) );
	delete_transient( 'gsm_captcha_logged' );
};
$login = function ( $extra = array() ) use ( $rest, $tag, $pass, $clear ) {
	$clear();
	wp_set_current_user( 0 );
	return $rest( 'POST', '/gas/v1/auth/login', array_merge( array( 'user' => 'cap-' . $tag, 'password' => $pass ), $extra ) );
};

try {
	// --- Settings ------------------------------------------------------------
	wp_set_current_user( $uid );
	$t( 'non-manager: settings refused (403)', 403 === $rest( 'GET', '/gas/v1/captcha' )->get_status() && 403 === $rest( 'POST', '/gas/v1/captcha', array( 'enabled' => true ) )->get_status() );
	wp_set_current_user( $admin );
	$t( 'off by default', false === $rest( 'GET', '/gas/v1/captcha' )->get_data()['enabled'] && ! GSM_Captcha::active( 'login' ) );
	$t( 'cannot enable without keys', 400 === $rest( 'POST', '/gas/v1/captcha', array( 'enabled' => true ) )->get_status() );
	$t( 'malformed key rejected', 400 === $rest( 'POST', '/gas/v1/captcha', array( 'site_key' => 'bad key!' ) )->get_status() );
	$r = $rest( 'POST', '/gas/v1/captcha', array( 'site_key' => $SITE, 'secret' => $SEC, 'enabled' => true ) );
	$d = $r->get_data();
	$t( 'saved; the secret is never returned', 200 === $r->get_status() && $d['enabled'] && $d['has_secret'] && false === strpos( wp_json_encode( $d ), $SEC ) );
	$t( 'enforce refused before a successful test', 400 === $rest( 'POST', '/gas/v1/captcha', array( 'enforce' => true ) )->get_status() && ! GSM_Captcha::config()['enforce'] );

	// --- Monitor mode: never blocks ---------------------------------------------
	$google['answer'] = array( 'success' => false, 'error-codes' => array( 'invalid-input-response' ) );
	$r = $login( array( 'captcha' => 'tok' ) );
	$t( 'monitor mode: a failed check does not block the login', 200 === $r->get_status() && ! empty( $r->get_data()['logged_in'] ) );
	$sent = end( $google['calls'] );
	$t( 'Google gets the secret + token only (no visitor IP)', array( 'secret', 'response' ) === array_keys( $sent ) && $SEC === $sent['secret'] && 'tok' === $sent['response'] );

	// --- Test + enforce ------------------------------------------------------------
	wp_set_current_user( $admin );
	$google['answer'] = array( 'success' => false, 'error-codes' => array( 'invalid-input-secret' ) );
	$r = $rest( 'POST', '/gas/v1/captcha/test', array( 'token' => 'tok' ) )->get_data();
	$t( 'test with a bad secret: reported, enforce stays locked', false === $r['works'] && 'invalid-input-secret' === $r['reason'] && '' === GSM_Captcha::config()['tested_at'] );
	$google['answer'] = $good( 'login' );
	$t( 'test token with the wrong action does not count', false === $rest( 'POST', '/gas/v1/captcha/test', array( 'token' => 'tok' ) )->get_data()['works'] );
	$google['answer'] = $good( 'test' );
	$r = $rest( 'POST', '/gas/v1/captcha/test', array( 'token' => 'tok' ) )->get_data();
	$t( 'successful test recorded', true === $r['works'] && '' !== $r['config']['tested_at'] );
	$t( 'now enforce can be switched on', 200 === $rest( 'POST', '/gas/v1/captcha', array( 'enforce' => true ) )->get_status() && GSM_Captcha::config()['enforce'] );

	// --- Enforce: login ---------------------------------------------------------------
	$google['answer'] = $good( 'login' );
	$t( 'enforce: good token -> login works', 200 === $login( array( 'captcha' => 'tok' ) )->get_status() );
	$n = count( $google['calls'] );
	$r = $login();
	$t( 'enforce: missing token -> 403, Google not even called', 403 === $r->get_status() && 'gsm_captcha' === $r->get_data()['code'] && count( $google['calls'] ) === $n && 0 === get_current_user_id() );
	$google['answer'] = $good( 'login', 0.1 );
	$t( 'enforce: low score -> 403', 403 === $login( array( 'captcha' => 'tok' ) )->get_status() );
	$google['answer'] = $good( 'report_fault' );
	$t( 'enforce: token made for another action -> 403', 403 === $login( array( 'captcha' => 'tok' ) )->get_status() );
	$google['answer'] = array_merge( $good( 'login' ), array( 'hostname' => 'evil.example.test' ) );
	$t( 'enforce: token from another site -> 403', 403 === $login( array( 'captcha' => 'tok' ) )->get_status() );
	$google['answer'] = array( 'success' => false, 'error-codes' => array( 'timeout-or-duplicate' ) );
	$t( 'enforce: reused / expired token -> 403', 403 === $login( array( 'captcha' => 'tok' ) )->get_status() );
	$google['answer'] = $good( 'login' );
	$clear();
	wp_set_current_user( 0 );
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'cap-' . $tag, 'password' => 'wrong', 'captcha' => 'tok' ) );
	$t( 'a good token does not replace the password', 401 === $r->get_status() && 'gsm_login_failed' === $r->get_data()['code'] );
	$google['answer'] = 'down';
	$t( 'Google unreachable: fail-open (login still works)', 200 === $login( array( 'captcha' => 'tok' ) )->get_status() );
	$t( 'a block is written to the audit log (without the token)', (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}gas_audit WHERE item_name = 'reCAPTCHA' AND summary LIKE '%נחסמה%'" ) >= 1 );

	// --- Enforce: public fault report ---------------------------------------------------
	wp_set_current_user( $admin );
	$eq  = (int) GSM_Equipment_Service::create( array( 'name' => 'Cap ' . $tag ) );
	$tok = GSM_Equipment_Repo::get( $eq )['qr_token'];
	wp_set_current_user( 0 );
	$report = function ( $extra = array() ) use ( $rest, $tok, $wpdb ) {
		$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient%gsm_rf_%'" );
		wp_cache_flush();
		return $rest( 'POST', '/gas/v1/report-fault', array_merge( array( 'token' => $tok, 'description' => 'בדיקת captcha', 'priority' => 'low', 'ts' => (int) round( microtime( true ) * 1000 ) - 5000 ), $extra ) );
	};
	$google['answer'] = $good( 'report_fault' );
	$r = $report( array( 'captcha' => 'tok' ) );
	$t( 'fault report with a good token is accepted', in_array( $r->get_status(), array( 200, 201 ), true ), (string) $r->get_status() . ' ' . wp_json_encode( $r->get_data() ) );
	$t( 'fault report without a token is blocked (403)', 403 === $report()->get_status() );
	wp_set_current_user( $admin );
	$rest( 'POST', '/gas/v1/captcha', array( 'bridge' => false ) );
	wp_set_current_user( 0 );
	$t( 'scope off: fault report is not gated', in_array( $report()->get_status(), array( 200, 201 ), true ) );

	// --- Passkey sign-in is not gated; new keys reset enforce ---------------------------
	$n = count( $google['calls'] );
	$rest( 'POST', '/gas/v1/auth/passkey', array( 'token' => 'x' ) );
	$t( 'passkey sign-in does not call Google', count( $google['calls'] ) === $n );
	wp_set_current_user( $admin );
	$d = $rest( 'POST', '/gas/v1/captcha', array( 'site_key' => str_repeat( 'N', 40 ) ) )->get_data();
	$t( 'changing a key drops enforce and the test stamp', false === $d['enforce'] && '' === $d['tested_at'] );
	$d = $rest( 'POST', '/gas/v1/captcha', array( 'clear' => true ) )->get_data();
	$t( 'clear removes keys and switches off', '' === $d['site_key'] && ! $d['has_secret'] && ! $d['enabled'] && ! GSM_Captcha::active( 'login' ) );
	$t( 'when off, login works without any token', 200 === $login()->get_status() );
} finally {
	wp_set_current_user( $admin );
	if ( null === $before ) {
		delete_option( GSM_Captcha::OPTION );
	} else {
		update_option( GSM_Captcha::OPTION, $before, false );
	}
	if ( ! empty( $eq ) ) {
		$wpdb->delete( $wpdb->prefix . 'gas_faults', array( 'equipment_id' => $eq ) );
		$wpdb->delete( $wpdb->prefix . 'gas_equipment', array( 'id' => $eq ) );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $uid );
	$clear();
	$wpdb->query( "DELETE FROM {$wpdb->prefix}gas_audit WHERE item_name = 'reCAPTCHA'" );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
