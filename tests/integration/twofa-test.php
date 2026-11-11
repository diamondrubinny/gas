<?php
/**
 * Two-factor authentication: RFC vectors, enrollment, every login path,
 * replay, lockout, backup codes, policy, manager reset.
 * Run: wp eval-file tests/integration/twofa-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
// CLI: no cookies can be sent after output started.
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
$code_now = function ( $b32, $offset = 0 ) {
	return GSM_2FA::hotp( GSM_2FA::b32_decode( $b32 ), (int) floor( time() / 30 ) + $offset );
};
$clear_ip = function () {
	delete_transient( 'gsm_login_' . md5( GSM_Auth::client_ip() ) );
};

// --- RFC 4226 / 6238 vectors (SHA-1, key "12345678901234567890") -------
$key = '12345678901234567890';
$t( 'RFC 4226 HOTP vectors', '755224' === GSM_2FA::hotp( $key, 0 ) && '287082' === GSM_2FA::hotp( $key, 1 ) && '520489' === GSM_2FA::hotp( $key, 9 ) );
// RFC 6238 gives 8 digits; the last 6 are our 6-digit code.
$t( 'RFC 6238 TOTP vectors', '287082' === GSM_2FA::hotp( $key, intdiv( 59, 30 ) ) && '081804' === GSM_2FA::hotp( $key, intdiv( 1111111109, 30 ) ) && '005924' === GSM_2FA::hotp( $key, intdiv( 1234567890, 30 ) ) && '279037' === GSM_2FA::hotp( $key, intdiv( 2000000000, 30 ) ) );
$t( 'base32 round trip + RFC 4648 vector', 'MZXW6YTBOI' === GSM_2FA::b32_encode( 'foobar' ) && 'foobar' === GSM_2FA::b32_decode( 'mzxw 6ytb-oi' ) && '' === GSM_2FA::b32_decode( 'not!base32' ) );
$t( 'window is ±1 step only', GSM_2FA::match_step( $key, GSM_2FA::hotp( $key, 100 ), 100 * 30 + 5 ) === 100 && GSM_2FA::match_step( $key, GSM_2FA::hotp( $key, 99 ), 100 * 30 ) === 99 && 0 === GSM_2FA::match_step( $key, GSM_2FA::hotp( $key, 97 ), 100 * 30 ) );

$tag  = strtolower( wp_generate_password( 6, false ) );
$pass = 'Pw-' . wp_generate_password( 14, false ) . '-1a';
$uid  = wp_insert_user( array( 'user_login' => 'tf-' . $tag, 'user_email' => 'tf-' . $tag . '@example.test', 'user_pass' => $pass, 'role' => 'gsm_manager', 'display_name' => 'TF ' . $tag ) );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];
$policy_before = get_option( GSM_2FA::POLICY, null );
delete_option( GSM_2FA::POLICY );

try {
	// --- Before enrollment: login works without a code -------------------
	$clear_ip();
	wp_set_current_user( 0 );
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass ) );
	$t( 'no 2FA: password login works', 200 === $r->get_status() && ! empty( $r->get_data()['logged_in'] ) && false === $r->get_data()['twofa'] );

	// --- Enrollment --------------------------------------------------------
	wp_set_current_user( $uid );
	$s = $rest( 'POST', '/gas/v1/2fa/setup' )->get_data();
	$t( 'setup: secret + otpauth URI', preg_match( '/^[A-Z2-7]{32}$/', $s['secret'] ) && 0 === strpos( $s['uri'], 'otpauth://totp/' ) && false !== strpos( $s['uri'], 'secret=' . $s['secret'] ) );
	$t( 'not enabled until confirmed', ! GSM_2FA::enabled( $uid ) );
	$r = $rest( 'POST', '/gas/v1/2fa/enable', array( 'code' => '000000' === $code_now( $s['secret'] ) ? '111111' : '000000' ) );
	$t( 'enable: wrong code rejected', 400 === $r->get_status() && ! GSM_2FA::enabled( $uid ) );
	$r = $rest( 'POST', '/gas/v1/2fa/enable', array( 'code' => $code_now( $s['secret'] ) ) );
	$d = $r->get_data();
	$t( 'enable: right code -> enabled + 8 backup codes', 200 === $r->get_status() && GSM_2FA::enabled( $uid ) && 8 === count( $d['backup_codes'] ) && preg_match( '/^[A-Z2-7]{5}-[A-Z2-7]{5}$/', $d['backup_codes'][0] ) );
	$backup = $d['backup_codes'];
	$raw    = (string) get_user_meta( $uid, GSM_2FA::META_SECRET, true );
	$t( 'secret stored encrypted (not the base32 / raw secret)', 0 === strpos( $raw, 'v1:' ) && false === strpos( $raw, $s['secret'] ) && false === strpos( $raw, GSM_2FA::b32_decode( $s['secret'] ) ) );
	$hashes = get_user_meta( $uid, GSM_2FA::META_BACKUP, true );
	$t( 'backup codes stored hashed', is_array( $hashes ) && 8 === count( $hashes ) && false === strpos( wp_json_encode( $hashes ), str_replace( '-', '', $backup[0] ) ) );
	$t( 'second setup refused while enabled', 400 === $rest( 'POST', '/gas/v1/2fa/setup' )->get_status() );
	$t( 'application passwords off for this user', false === wp_is_application_passwords_available_for_user( $uid ) );
	$st = $rest( 'GET', '/gas/v1/2fa' )->get_data();
	$t( 'status never exposes the secret', $st['enabled'] && 8 === $st['backup_left'] && false === strpos( wp_json_encode( $st ), $s['secret'] ) );

	// --- Login paths -------------------------------------------------------
	wp_set_current_user( 0 );
	$clear_ip();
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass ) );
	$t( 'REST login: password only -> requires_2fa, not logged in', 200 === $r->get_status() && ! empty( $r->get_data()['requires_2fa'] ) && empty( $r->get_data()['logged_in'] ) && 0 === get_current_user_id() );
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => 'wrong-' . $pass, 'code' => $code_now( $s['secret'], 1 ) ) );
	$t( 'wrong password + valid code -> generic failure (code not consumed)', 401 === $r->get_status() && 'gsm_login_failed' === $r->get_data()['code'] );
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass, 'code' => '12345' ) );
	$t( 'malformed code -> 401', 401 === $r->get_status() && 'gsm_2fa_invalid' === $r->get_data()['code'] );
	// The enrollment consumed the current step; the next step is still fresh.
	$next = $code_now( $s['secret'], 1 );
	$r    = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass, 'code' => $next ) );
	$t( 'REST login: password + code -> logged in', 200 === $r->get_status() && ! empty( $r->get_data()['logged_in'] ) && true === $r->get_data()['twofa'], wp_json_encode( $r->get_data() ) );
	wp_set_current_user( 0 );
	$clear_ip();
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass, 'code' => $next ) );
	$t( 'replay: the same code is refused', 401 === $r->get_status() );
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass, 'code' => $code_now( $s['secret'], 0 ) ) );
	$t( 'an older step than the last used one is refused', 401 === $r->get_status() );
	delete_transient( 'gsm_2fa_fail_' . $uid );
	$clear_ip();

	// Core path (wp-login.php / XML-RPC use wp_authenticate).
	update_user_meta( $uid, GSM_2FA::META_LAST, 0 );
	$e = wp_authenticate( 'tf-' . $tag, $pass );
	$t( 'wp_authenticate without a code is refused', is_wp_error( $e ) && 'gsm_2fa_required' === $e->get_error_code() );
	$_POST['gsm_otp'] = $code_now( $s['secret'] );
	$e = wp_authenticate( 'tf-' . $tag, $pass );
	unset( $_POST['gsm_otp'] );
	$t( 'wp_authenticate with the wp-login code field works', $e instanceof WP_User && (int) $e->ID === $uid );
	$e = wp_authenticate( 'tf-' . $tag, 'wrong' );
	$t( 'wrong password still says wrong password (no 2FA hint)', is_wp_error( $e ) && 'incorrect_password' === $e->get_error_code() );

	// Backup code: once.
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass, 'code' => strtolower( $backup[0] ) ) );
	$t( 'backup code logs in (case-insensitive)', 200 === $r->get_status() && ! empty( $r->get_data()['logged_in'] ) );
	wp_set_current_user( 0 );
	$clear_ip();
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass, 'code' => $backup[0] ) );
	$t( 'backup code works once only', 401 === $r->get_status() && 7 === GSM_2FA::backup_left( $uid ) );

	// Lockout.
	delete_transient( 'gsm_2fa_fail_' . $uid );
	for ( $i = 0; $i < 5; $i++ ) {
		$clear_ip();
		$rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass, 'code' => '00000' . $i ) );
	}
	$clear_ip();
	update_user_meta( $uid, GSM_2FA::META_LAST, 0 );
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass, 'code' => $code_now( $s['secret'] ) ) );
	$t( '5 wrong codes lock the second step (even a right code, 429)', 429 === $r->get_status() && 'gsm_2fa_locked' === $r->get_data()['code'] );
	$t( 'lockout written to the audit log', (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}gas_audit WHERE action = 'security' AND entity_id = %d AND summary LIKE %s", $uid, '%ננעל%' ) ) >= 1 );
	delete_transient( 'gsm_2fa_fail_' . $uid );
	$clear_ip();

	// Emergency constant is honoured by disabled() (cannot define here; check the code path).
	$t( 'not bypassed by default', false === GSM_2FA::disabled() );

	// --- Policy --------------------------------------------------------------
	$uid2 = wp_insert_user( array( 'user_login' => 'tf2-' . $tag, 'user_email' => 'tf2-' . $tag . '@example.test', 'user_pass' => $pass, 'role' => 'gsm_manager' ) );
	wp_set_current_user( $uid2 );
	$t( 'policy: cannot make it mandatory before enabling it yourself', 400 === $rest( 'POST', '/gas/v1/2fa/policy', array( 'require_managers' => true ) )->get_status() );
	wp_set_current_user( $uid );
	$t( 'policy: an enrolled manager can turn it on', 200 === $rest( 'POST', '/gas/v1/2fa/policy', array( 'require_managers' => true ) )->get_status() && GSM_2FA::required_for_managers() );
	wp_set_current_user( $uid2 );
	$t( 'un-enrolled manager: manager API blocked (403)', 403 === $rest( 'GET', '/gas/v1/users' )->get_status() );
	$t( 'un-enrolled manager: cannot switch the policy off', 403 === $rest( 'POST', '/gas/v1/2fa/policy', array( 'require_managers' => false ) )->get_status() && GSM_2FA::required_for_managers() );
	$t( 'un-enrolled manager: can still reach 2FA setup', 200 === $rest( 'POST', '/gas/v1/2fa/setup' )->get_status() );
	$me = $rest( 'GET', '/gas/v1/auth/me' )->get_data();
	$t( '/auth/me reports must_2fa', true === $me['must_2fa'] );
	$uid3 = wp_insert_user( array( 'user_login' => 'tf3-' . $tag, 'user_email' => 'tf3-' . $tag . '@example.test', 'user_pass' => $pass, 'role' => 'gsm_lab_manager' ) );
	wp_set_current_user( $uid3 );
	$t( 'non-manager is not forced', false === $rest( 'GET', '/gas/v1/auth/me' )->get_data()['must_2fa'] && 200 === $rest( 'GET', '/gas/v1/equipment' )->get_status() );
	$t( 'non-manager cannot change the policy or reset others', 403 === $rest( 'POST', '/gas/v1/2fa/policy', array( 'require_managers' => false ) )->get_status() && 403 === $rest( 'POST', '/gas/v1/users/' . $uid . '/2fa-reset' )->get_status() );

	// --- Disable / reset -------------------------------------------------------
	wp_set_current_user( $uid );
	update_user_meta( $uid, GSM_2FA::META_LAST, 0 );
	$t( 'disable refused while mandatory', 403 === $rest( 'POST', '/gas/v1/2fa/disable', array( 'password' => $pass, 'code' => $code_now( $s['secret'] ) ) )->get_status() && GSM_2FA::enabled( $uid ) );
	$rest( 'POST', '/gas/v1/2fa/policy', array( 'require_managers' => false ) );
	$t( 'disable needs the password', 400 === $rest( 'POST', '/gas/v1/2fa/disable', array( 'password' => 'nope', 'code' => $code_now( $s['secret'] ) ) )->get_status() && GSM_2FA::enabled( $uid ) );
	$t( 'disable needs a valid code', 400 === $rest( 'POST', '/gas/v1/2fa/disable', array( 'password' => $pass, 'code' => '000000' === $code_now( $s['secret'] ) ? '111111' : '000000' ) )->get_status() && GSM_2FA::enabled( $uid ) );
	delete_transient( 'gsm_2fa_fail_' . $uid );
	$r = $rest( 'POST', '/gas/v1/2fa/backup-codes', array( 'code' => $code_now( $s['secret'] ) ) );
	$t( 'new backup codes replace the old ones', 200 === $r->get_status() && 8 === GSM_2FA::backup_left( $uid ) && ! in_array( $backup[1], $r->get_data()['backup_codes'], true ) );

	wp_set_current_user( $uid2 );
	$t( 'manager cannot reset own 2FA through the user route', 400 === $rest( 'POST', '/gas/v1/users/' . $uid2 . '/2fa-reset' )->get_status() );
	$r = $rest( 'POST', '/gas/v1/users/' . $uid . '/2fa-reset' );
	$t( 'another manager resets it: cleared, sessions ended', 200 === $r->get_status() && ! GSM_2FA::enabled( $uid ) && '' === (string) get_user_meta( $uid, GSM_2FA::META_BACKUP, true ) && empty( WP_Session_Tokens::get_instance( $uid )->get_all() ) );
	$list = $rest( 'GET', '/gas/v1/users' )->get_data();
	$row  = array_values( array_filter( $list, function ( $u ) use ( $uid ) { return (int) $u['id'] === $uid; } ) );
	$t( 'users list shows the 2FA flag', $row && false === $row[0]['twofa'] );
	wp_set_current_user( 0 );
	$clear_ip();
	$r = $rest( 'POST', '/gas/v1/auth/login', array( 'user' => 'tf-' . $tag, 'password' => $pass ) );
	$t( 'after reset: password login works again', 200 === $r->get_status() && ! empty( $r->get_data()['logged_in'] ) );
} finally {
	wp_set_current_user( $admin );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array( $uid, isset( $uid2 ) ? $uid2 : 0, isset( $uid3 ) ? $uid3 : 0 ) as $u ) {
		if ( $u && ! is_wp_error( $u ) ) {
			delete_transient( 'gsm_2fa_fail_' . $u );
			wp_delete_user( $u );
		}
	}
	if ( null === $policy_before ) {
		delete_option( GSM_2FA::POLICY );
	} else {
		update_option( GSM_2FA::POLICY, $policy_before, false );
	}
	$clear_ip();
	$wpdb->query( "DELETE FROM {$wpdb->prefix}gas_audit WHERE action = 'security' AND item_name LIKE 'TF %'" );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
