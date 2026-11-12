<?php
/**
 * Passkeys (WebAuthn): a software authenticator built here (real P-256 and
 * RSA keys, real CBOR + signatures) runs the full registration and sign-in
 * ceremonies against the server, plus every rejection path.
 * Run: wp eval-file tests/integration/passkeys-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
add_filter( 'send_auth_cookies', '__return_false' );
// WebAuthn needs a domain + https: present the site as one for this run.
add_filter(
	'home_url',
	function ( $url, $path ) {
		return 'https://gas.example.test' . ( $path ? '/' . ltrim( $path, '/' ) : '' );
	},
	10,
	2
);
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
$b64     = array( 'GSM_Passkeys', 'b64u' );
$ORIGIN  = 'https://gas.example.test';
$RP      = 'gas.example.test';
$clear   = function () {
	delete_transient( 'gsm_login_' . md5( GSM_Auth::client_ip() ) );
	delete_transient( 'gsm_rl_' . md5( 'passkey-options|' . GSM_Rate_Limit::client_ip() ) );
};

/** A software authenticator. */
$new_auth = function ( $alg ) {
	if ( 'rsa' === $alg ) {
		$key  = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
		$d    = openssl_pkey_get_details( $key )['rsa'];
		$cose = "\xA4\x01\x03\x03\x39\x01\x00\x20\x59\x01\x00" . str_pad( ltrim( $d['n'], "\0" ), 256, "\0", STR_PAD_LEFT ) . "\x21\x43" . $d['e'];
	} else {
		$key  = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1' ) );
		$d    = openssl_pkey_get_details( $key )['ec'];
		$cose = "\xA5\x01\x02\x03\x26\x20\x01\x21\x58\x20" . str_pad( $d['x'], 32, "\0", STR_PAD_LEFT ) . "\x22\x58\x20" . str_pad( $d['y'], 32, "\0", STR_PAD_LEFT );
	}
	return array( 'key' => $key, 'cose' => $cose, 'id' => random_bytes( 32 ), 'count' => 0 );
};
$cdj = function ( $type, $challenge, $origin ) {
	return wp_json_encode( array( 'type' => $type, 'challenge' => $challenge, 'origin' => $origin, 'crossOrigin' => false ), JSON_UNESCAPED_SLASHES );
};
$attest = function ( $a, $challenge, $o = array() ) use ( $cdj, $b64, $ORIGIN, $RP ) {
	$flags = isset( $o['flags'] ) ? $o['flags'] : 0x45; // UP + UV + AT.
	$ad    = hash( 'sha256', isset( $o['rp'] ) ? $o['rp'] : $RP, true ) . chr( $flags ) . pack( 'N', 0 ) . str_repeat( "\0", 16 ) . pack( 'n', strlen( $a['id'] ) ) . $a['id'] . $a['cose'];
	$obj   = "\xA3\x63fmt\x64none\x67attStmt\xA0\x68authData\x59" . pack( 'n', strlen( $ad ) ) . $ad;
	return array(
		'clientDataJSON'    => $b64( $cdj( isset( $o['type'] ) ? $o['type'] : 'webauthn.create', $challenge, isset( $o['origin'] ) ? $o['origin'] : $ORIGIN ) ),
		'attestationObject' => $b64( $obj ),
	);
};
$assert = function ( &$a, $challenge, $o = array() ) use ( $cdj, $b64, $ORIGIN, $RP ) {
	if ( ! isset( $o['count'] ) ) {
		$a['count']++;
	}
	$count = isset( $o['count'] ) ? $o['count'] : $a['count'];
	$flags = isset( $o['flags'] ) ? $o['flags'] : 0x05;
	$ad    = hash( 'sha256', isset( $o['rp'] ) ? $o['rp'] : $RP, true ) . chr( $flags ) . pack( 'N', $count );
	$c     = $cdj( isset( $o['type'] ) ? $o['type'] : 'webauthn.get', $challenge, isset( $o['origin'] ) ? $o['origin'] : $ORIGIN );
	openssl_sign( $ad . hash( 'sha256', $c, true ), $sig, $a['key'], OPENSSL_ALGO_SHA256 );
	if ( ! empty( $o['tamper'] ) ) {
		$sig[ strlen( $sig ) - 1 ] = chr( ord( $sig[ strlen( $sig ) - 1 ] ) ^ 0x01 );
	}
	return array( 'id' => $b64( $a['id'] ), 'clientDataJSON' => $b64( $c ), 'authenticatorData' => $b64( $ad ), 'signature' => $b64( $sig ) );
};

$tag    = strtolower( wp_generate_password( 6, false ) );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];
$uid    = wp_insert_user( array( 'user_login' => 'pk-' . $tag, 'user_email' => 'pk-' . $tag . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'gsm_manager', 'display_name' => 'PK ' . $tag ) );
$uid2   = wp_insert_user( array( 'user_login' => 'pk2-' . $tag, 'user_email' => 'pk2-' . $tag . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'gsm_manager' ) );

$clear();
try {
	$t( 'site presented as https domain: passkeys available', GSM_Passkeys::available() && $RP === GSM_Passkeys::rp_id() && $ORIGIN === GSM_Passkeys::origin() );

	wp_set_current_user( 0 );
	$t( 'registration needs a signed-in user (401)', 401 === $rest( 'POST', '/gas/v1/passkeys/register/options' )->get_status() );

	// --- Registration -------------------------------------------------------
	wp_set_current_user( $uid );
	$opt = $rest( 'POST', '/gas/v1/passkeys/register/options' )->get_data();
	$pk  = $opt['publicKey'];
	$t( 'options: UV required, resident key, attestation none, opaque user handle', 'required' === $pk['authenticatorSelection']['userVerification'] && 'required' === $pk['authenticatorSelection']['residentKey'] && 'none' === $pk['attestation'] && $RP === $pk['rp']['id'] && false === strpos( GSM_Passkeys::b64u_dec( $pk['user']['id'] ), '@' ) );

	$ec = $new_auth( 'ec' );
	$r  = $rest( 'POST', '/gas/v1/passkeys/register', array_merge( $attest( $ec, $pk['challenge'], array( 'origin' => 'https://evil.example.test' ) ), array( 'token' => $opt['token'] ) ) );
	$t( 'wrong origin rejected', 400 === $r->get_status() );
	$r = $rest( 'POST', '/gas/v1/passkeys/register', array_merge( $attest( $ec, $pk['challenge'] ), array( 'token' => $opt['token'] ) ) );
	$t( 'challenge is single use (the failed attempt burned it)', 400 === $r->get_status() && 0 === count( GSM_Passkeys::list_for( $uid ) ) );

	$reg = function ( $a, $o = array(), $name = 'מחשב נייד' ) use ( $rest, $attest ) {
		$opt = $rest( 'POST', '/gas/v1/passkeys/register/options' )->get_data();
		return $rest( 'POST', '/gas/v1/passkeys/register', array_merge( $attest( $a, isset( $o['challenge'] ) ? $o['challenge'] : $opt['publicKey']['challenge'], $o ), array( 'token' => $opt['token'], 'name' => $name ) ) );
	};
	$t( 'wrong challenge rejected', 400 === $reg( $ec, array( 'challenge' => GSM_Passkeys::b64u( random_bytes( 32 ) ) ) )->get_status() );
	$t( 'wrong ceremony type rejected', 400 === $reg( $ec, array( 'type' => 'webauthn.get' ) )->get_status() );
	$t( 'wrong RP id hash rejected', 400 === $reg( $ec, array( 'rp' => 'other.example.test' ) )->get_status() );
	$t( 'no user verification rejected', 400 === $reg( $ec, array( 'flags' => 0x41 ) )->get_status() );
	$t( 'nothing stored by the rejected attempts', 0 === count( GSM_Passkeys::list_for( $uid ) ) );

	$r = $reg( $ec, array(), 'טלפון <b>אישי</b>' );
	$t( 'ES256 passkey registered, name sanitized', 200 === $r->get_status() && 'טלפון אישי' === $r->get_data()['name'] && 1 === count( GSM_Passkeys::list_for( $uid ) ), wp_json_encode( $r->get_data() ) );
	$rsa = $new_auth( 'rsa' );
	$t( 'RS256 passkey registered', 200 === $reg( $rsa, array(), 'Windows Hello' )->get_status() && 2 === count( GSM_Passkeys::list_for( $uid ) ) );
	$t( 'same credential cannot be registered twice (409)', 409 === $reg( $ec )->get_status() );
	wp_set_current_user( $uid2 );
	$t( 'nor by another user (409)', 409 === $reg( $ec )->get_status() );
	wp_set_current_user( $uid );
	$list = $rest( 'GET', '/gas/v1/passkeys' )->get_data();
	$t( 'list shows public info only (no key material)', 2 === count( $list['items'] ) && false === strpos( wp_json_encode( $list ), 'BEGIN PUBLIC KEY' ) && ! isset( $list['items'][0]['id'] ) );
	$opt2 = $rest( 'POST', '/gas/v1/passkeys/register/options' )->get_data();
	$t( 'existing credentials are excluded on the next registration', 2 === count( $opt2['publicKey']['excludeCredentials'] ) );
	$stored = wp_json_encode( get_user_meta( $uid ) );
	$t( 'only a public key is stored', false !== strpos( $stored, 'BEGIN PUBLIC KEY' ) && false === strpos( $stored, 'PRIVATE' ) );

	// --- Sign-in ---------------------------------------------------------------
	wp_set_current_user( 0 );
	$login = function ( &$a, $o = array() ) use ( $rest, $assert, $clear ) {
		$clear();
		wp_set_current_user( 0 );
		$opt = $rest( 'POST', '/gas/v1/auth/passkey/options' )->get_data();
		return $rest( 'POST', '/gas/v1/auth/passkey', array_merge( $assert( $a, isset( $o['challenge'] ) ? $o['challenge'] : $opt['publicKey']['challenge'], $o ), array( 'token' => $opt['token'] ) ) );
	};
	$o = $rest( 'POST', '/gas/v1/auth/passkey/options' )->get_data();
	$t( 'sign-in options: UV required, usernameless', 'required' === $o['publicKey']['userVerification'] && array() === $o['publicKey']['allowCredentials'] && $RP === $o['publicKey']['rpId'] );

	$r = $login( $ec );
	$t( 'ES256 sign-in: logged in as the right user', 200 === $r->get_status() && ! empty( $r->get_data()['logged_in'] ) && (int) $r->get_data()['id'] === $uid && get_current_user_id() === $uid, wp_json_encode( $r->get_data() ) );
	$r = $login( $rsa );
	$t( 'RS256 sign-in works', 200 === $r->get_status() && (int) $r->get_data()['id'] === $uid );
	$t( 'last use recorded', '' !== GSM_Passkeys::list_for( $uid )[0]['last_used'] );

	$t( 'tampered signature rejected (401)', 401 === $login( $ec, array( 'tamper' => true ) )->get_status() && 0 === get_current_user_id() );
	$t( 'wrong origin rejected', 401 === $login( $ec, array( 'origin' => 'https://gas.example.test.evil.test' ) )->get_status() );
	$t( 'wrong challenge rejected', 401 === $login( $ec, array( 'challenge' => GSM_Passkeys::b64u( random_bytes( 32 ) ) ) )->get_status() );
	$t( 'registration-type client data rejected', 401 === $login( $ec, array( 'type' => 'webauthn.create' ) )->get_status() );
	$t( 'user-presence only (no verification) rejected', 401 === $login( $ec, array( 'flags' => 0x01 ) )->get_status() );
	$t( 'wrong RP id rejected', 401 === $login( $ec, array( 'rp' => 'evil.example.test' ) )->get_status() );
	$t( 'counter going backwards rejected (cloned key)', 401 === $login( $ec, array( 'count' => 1 ) )->get_status() );
	$t( 'still works with a growing counter', 200 === $login( $ec )->get_status() );
	$stranger = $new_auth( 'ec' );
	$r        = $login( $stranger );
	$t( 'unknown credential: same generic failure', 401 === $r->get_status() && 'gsm_passkey_failed' === $r->get_data()['code'] );

	// Replay of a whole captured request.
	$clear();
	wp_set_current_user( 0 );
	$opt  = $rest( 'POST', '/gas/v1/auth/passkey/options' )->get_data();
	$body = array_merge( $assert( $ec, $opt['publicKey']['challenge'] ), array( 'token' => $opt['token'] ) );
	$t( 'a request works once…', 200 === $rest( 'POST', '/gas/v1/auth/passkey', $body )->get_status() );
	wp_set_current_user( 0 );
	$t( '…and the replay is refused', 401 === $rest( 'POST', '/gas/v1/auth/passkey', $body )->get_status() && 0 === get_current_user_id() );

	// Failure counter shared with the password login.
	$clear();
	for ( $i = 0; $i < 5; $i++ ) {
		wp_set_current_user( 0 );
		$opt = $rest( 'POST', '/gas/v1/auth/passkey/options' )->get_data();
		$rest( 'POST', '/gas/v1/auth/passkey', array_merge( $assert( $stranger, $opt['publicKey']['challenge'] ), array( 'token' => $opt['token'] ) ) );
	}
	$opt = $rest( 'POST', '/gas/v1/auth/passkey/options' )->get_data();
	$t( '5 failures lock sign-in for this address (429)', 429 === $rest( 'POST', '/gas/v1/auth/passkey', array_merge( $assert( $ec, $opt['publicKey']['challenge'] ), array( 'token' => $opt['token'] ) ) )->get_status() );

	// Deactivated account.
	update_user_meta( $uid, 'gsm_active', '0' );
	$t( 'deactivated account cannot sign in with a passkey (403)', 403 === $login( $ec )->get_status() && 0 === get_current_user_id() );
	update_user_meta( $uid, 'gsm_active', '1' );

	// With TOTP enabled the passkey (device + biometric) is accepted on its own.
	update_user_meta( $uid, GSM_2FA::META_SECRET, 'v1:placeholder' );
	$t( 'passkey sign-in does not ask for the TOTP code', 200 === $login( $ec )->get_status() );
	delete_user_meta( $uid, GSM_2FA::META_SECRET );

	// --- Remove / reset ------------------------------------------------------------
	wp_set_current_user( $uid2 );
	$key = GSM_Passkeys::list_for( $uid )[0]['key'];
	$t( "cannot delete another user's passkey (404)", 404 === $rest( 'DELETE', '/gas/v1/passkeys/' . $key )->get_status() && 2 === count( GSM_Passkeys::list_for( $uid ) ) );
	wp_set_current_user( $uid );
	$t( 'owner deletes a passkey', 200 === $rest( 'DELETE', '/gas/v1/passkeys/' . $key )->get_status() && 1 === count( GSM_Passkeys::list_for( $uid ) ) );
	wp_set_current_user( $uid2 );
	$t( 'manager reset removes all passkeys of the user', 200 === $rest( 'POST', '/gas/v1/users/' . $uid . '/2fa-reset' )->get_status() && 0 === count( GSM_Passkeys::list_for( $uid ) ) );
	$t( 'after the reset the old passkey no longer signs in', 401 === $login( $rsa )->get_status() );

	// Malformed input never causes a server error.
	$clear();
	$opt = $rest( 'POST', '/gas/v1/auth/passkey/options' )->get_data();
	$r   = $rest( 'POST', '/gas/v1/auth/passkey', array( 'token' => $opt['token'], 'id' => '!!!', 'clientDataJSON' => array( 'x' ), 'authenticatorData' => 'AA', 'signature' => '' ) );
	$t( 'malformed input: clean 401', 401 === $r->get_status() );
	wp_set_current_user( $uid );
	$opt = $rest( 'POST', '/gas/v1/passkeys/register/options' )->get_data();
	$r   = $rest( 'POST', '/gas/v1/passkeys/register', array( 'token' => $opt['token'], 'clientDataJSON' => GSM_Passkeys::b64u( $cdj( 'webauthn.create', $opt['publicKey']['challenge'], $ORIGIN ) ), 'attestationObject' => GSM_Passkeys::b64u( "\xBF\xFF\x9F garbage" ) ) );
	$t( 'malformed CBOR: clean 400', 400 === $r->get_status() );
} finally {
	wp_set_current_user( $admin );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $uid );
	wp_delete_user( $uid2 );
	$clear();
	$wpdb->query( "DELETE FROM {$wpdb->prefix}gas_audit WHERE action = 'security' AND ( item_name LIKE 'PK %' OR summary LIKE '%Passkey%' )" );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
