<?php
/**
 * SSO (Microsoft Entra ID): a simulated identity provider (real RSA key,
 * signed ID tokens, JWKS) runs the whole sign-in and every rejection path.
 * Run: wp eval-file tests/integration/sso-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
define( 'GSM_ALLOW_INTERNAL_WEBHOOKS', true ); // Skip the DNS check; HTTP is mocked below.
add_filter( 'send_email_change_email', '__return_false' );
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
$b64    = array( 'GSM_Passkeys', 'b64u' );
$TENANT = '11111111-2222-4333-8444-555555555555';
$CLIENT = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';
$SECRET = 'sso-test-secret-' . wp_generate_password( 20, false );
$ISS    = 'https://login.microsoftonline.com/' . $TENANT . '/v2.0';

// --- The simulated identity provider -----------------------------------------
$idp_key   = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
$rogue_key = openssl_pkey_new( array( 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048 ) );
$det       = openssl_pkey_get_details( $idp_key )['rsa'];
$idp       = array( 'token' => null, 'calls' => array(), 'jwks_hits' => 0, 'kid' => 'kid-1' );
$sign      = function ( $claims, $o = array() ) use ( $b64, $idp_key, $rogue_key ) {
	$head = array( 'typ' => 'JWT', 'alg' => isset( $o['alg'] ) ? $o['alg'] : 'RS256', 'kid' => isset( $o['kid'] ) ? $o['kid'] : 'kid-1' );
	$in   = $b64( wp_json_encode( $head ) ) . '.' . $b64( wp_json_encode( $claims ) );
	if ( 'none' === $head['alg'] ) {
		return $in . '.' . $b64( 'x' );
	}
	if ( 'HS256' === $head['alg'] ) {
		// Algorithm-confusion attempt: HMAC keyed with the PUBLIC key.
		return $in . '.' . $b64( hash_hmac( 'sha256', $in, openssl_pkey_get_details( $idp_key )['key'], true ) );
	}
	openssl_sign( $in, $sig, ! empty( $o['rogue'] ) ? $rogue_key : $idp_key, OPENSSL_ALGO_SHA256 );
	return $in . '.' . $b64( $sig );
};
add_filter(
	'pre_http_request',
	function ( $pre, $args, $url ) use ( &$idp, $det, $b64, $TENANT, $ISS ) {
		if ( 0 !== strpos( $url, 'https://login.microsoftonline.com/' ) ) {
			return $pre;
		}
		$ok = function ( $body ) {
			return array( 'response' => array( 'code' => 200, 'message' => 'OK' ), 'body' => wp_json_encode( $body ), 'headers' => array(), 'cookies' => array() );
		};
		if ( false !== strpos( $url, '/' . $TENANT . '/discovery/v2.0/keys' ) ) {
			$idp['jwks_hits']++;
			return $ok( array( 'keys' => array( array( 'kty' => 'RSA', 'use' => 'sig', 'kid' => $idp['kid'], 'n' => $b64( $det['n'] ), 'e' => $b64( $det['e'] ) ) ) ) );
		}
		if ( false !== strpos( $url, '/' . $TENANT . '/v2.0/.well-known/openid-configuration' ) ) {
			return $ok( array( 'issuer' => $ISS ) );
		}
		if ( false !== strpos( $url, '/' . $TENANT . '/oauth2/v2.0/token' ) ) {
			$idp['calls'][] = $args['body'];
			if ( null === $idp['token'] ) {
				return array( 'response' => array( 'code' => 400, 'message' => 'Bad Request' ), 'body' => '{"error":"invalid_grant"}', 'headers' => array(), 'cookies' => array() );
			}
			return $ok( array( 'token_type' => 'Bearer', 'id_token' => $idp['token'] ) );
		}
		return array( 'response' => array( 'code' => 404, 'message' => 'Not Found' ), 'body' => '{}', 'headers' => array(), 'cookies' => array() );
	},
	10,
	3
);

$tag    = strtolower( wp_generate_password( 6, false ) );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];
$before = get_option( GSM_SSO::OPTION, null );
delete_option( GSM_SSO::OPTION );
delete_transient( 'gsm_sso_jwks' );
$EMAIL = 'sso-' . $tag . '@corp.example.test';
$OID   = '99999999-aaaa-4bbb-8ccc-' . substr( md5( $tag ), 0, 12 );
$uid   = wp_insert_user( array( 'user_login' => 'sso-' . $tag, 'user_email' => $EMAIL, 'user_pass' => wp_generate_password(), 'role' => 'gsm_manager', 'display_name' => 'SSO ' . $tag ) );
$made  = array( $uid );

/** One full round trip; returns WP_User|WP_Error. */
$round = function ( $claims_fn, $o = array() ) use ( &$idp, $sign, $TENANT, $CLIENT, $ISS, $EMAIL, $OID ) {
	$b = GSM_SSO::begin();
	parse_str( (string) wp_parse_url( $b['url'], PHP_URL_QUERY ), $q );
	$GLOBALS['gsm_sso_last_q'] = $q;
	$claims = array( 'iss' => $ISS, 'aud' => $CLIENT, 'tid' => $TENANT, 'oid' => $OID, 'preferred_username' => $EMAIL, 'name' => 'SSO User', 'nonce' => $q['nonce'], 'iat' => time(), 'nbf' => time(), 'exp' => time() + 3600 );
	$claims = $claims_fn ? $claims_fn( $claims ) : $claims;
	$idp['token'] = array_key_exists( 'raw', $o ) ? $o['raw'] : $sign( $claims, $o );
	return GSM_SSO::complete( 'auth-code-123', isset( $o['state'] ) ? $o['state'] : $q['state'], isset( $o['cookie'] ) ? $o['cookie'] : $b['cookie'] );
};
$code = function ( $r ) {
	return is_wp_error( $r ) ? $r->get_error_code() : 'ok';
};

try {
	// --- Settings ------------------------------------------------------------
	$lab = wp_insert_user( array( 'user_login' => 'ssol-' . $tag, 'user_email' => 'ssol-' . $tag . '@corp.example.test', 'user_pass' => wp_generate_password(), 'role' => 'gsm_lab_manager' ) );
	$made[] = $lab;
	wp_set_current_user( $lab );
	$t( 'non-manager: settings refused (403)', 403 === $rest( 'GET', '/gas/v1/sso' )->get_status() && 403 === $rest( 'POST', '/gas/v1/sso', array( 'enabled' => true ) )->get_status() );
	wp_set_current_user( $admin );
	$t( 'off by default; begin() refuses', ! GSM_SSO::active() && is_wp_error( GSM_SSO::begin() ) );
	$t( 'tenant must be a GUID ("common" refused)', 400 === $rest( 'POST', '/gas/v1/sso', array( 'tenant' => 'common' ) )->get_status() && 400 === $rest( 'POST', '/gas/v1/sso', array( 'tenant' => 'evil.com/x' ) )->get_status() );
	$t( 'cannot enable when incomplete', 400 === $rest( 'POST', '/gas/v1/sso', array( 'tenant' => $TENANT, 'enabled' => true ) )->get_status() );
	$t( 'auto-create requires allowed domains', 400 === $rest( 'POST', '/gas/v1/sso', array( 'tenant' => $TENANT, 'client_id' => $CLIENT, 'secret' => $SECRET, 'enabled' => true, 'auto_create' => true, 'domains' => '' ) )->get_status() );
	$t( 'bad domain refused', 400 === $rest( 'POST', '/gas/v1/sso', array( 'domains' => 'not a domain' ) )->get_status() );
	$r = $rest( 'POST', '/gas/v1/sso', array( 'tenant' => strtoupper( $TENANT ), 'client_id' => $CLIENT, 'secret' => $SECRET, 'enabled' => true, 'domains' => '@Corp.Example.Test, other.example.test', 'default_role' => 'manager' ) );
	$d = $r->get_data();
	$t( 'saved: secret never returned, domains normalized, default role cannot be manager', 200 === $r->get_status() && $d['enabled'] && $d['has_secret'] && false === strpos( wp_json_encode( $d ), $SECRET ) && array( 'corp.example.test', 'other.example.test' ) === $d['domains'] && 'pi' === $d['default_role'], wp_json_encode( $d ) );
	$t( 'redirect URI shown for registration', false !== strpos( $d['redirect_uri'], 'gas/v1/sso/callback' ) );
	$t( 'connection test finds the tenant', true === $rest( 'POST', '/gas/v1/sso/test' )->get_data()['ok'] );

	// --- Start -------------------------------------------------------------------
	$b = GSM_SSO::begin();
	parse_str( (string) wp_parse_url( $b['url'], PHP_URL_QUERY ), $q );
	$t( 'authorize URL: Microsoft host + tenant, code flow, PKCE S256, state, nonce', 0 === strpos( $b['url'], 'https://login.microsoftonline.com/' . $TENANT . '/oauth2/v2.0/authorize?' ) && 'code' === $q['response_type'] && 'S256' === $q['code_challenge_method'] && 43 === strlen( $q['code_challenge'] ) && 32 === strlen( $q['state'] ) && 32 === strlen( $q['nonce'] ) && $CLIENT === $q['client_id'] && 'openid profile email' === $q['scope'] );
	$t( 'the secret and the PKCE verifier are not in the URL', false === strpos( $b['url'], $SECRET ) && ! isset( $q['code_verifier'] ) );

	// --- Happy path ------------------------------------------------------------------
	wp_set_current_user( 0 );
	$u = $round( null );
	$t( 'sign-in: existing user matched by organizational user name', $u instanceof WP_User && (int) $u->ID === $uid, $code( $u ) );
	$sent = end( $idp['calls'] );
	$t( 'token request: code + secret + PKCE verifier matching the challenge', 'authorization_code' === $sent['grant_type'] && 'auth-code-123' === $sent['code'] && $SECRET === $sent['client_secret'] && hash_equals( $GLOBALS['gsm_sso_last_q']['code_challenge'], GSM_Passkeys::b64u( hash( 'sha256', $sent['code_verifier'], true ) ) ) );
	$t( 'account linked to the directory object id', $OID === get_user_meta( $uid, GSM_SSO::META_OID, true ) );
	$hits = $idp['jwks_hits'];
	$round( null );
	$t( 'signing keys are cached', $idp['jwks_hits'] === $hits );
	wp_update_user( array( 'ID' => $uid, 'user_email' => 'renamed-' . $tag . '@corp.example.test' ) );
	$u = $round( null );
	$t( 'after linking, matched by object id even if the e-mail changed here', $u instanceof WP_User && (int) $u->ID === $uid );
	wp_update_user( array( 'ID' => $uid, 'user_email' => $EMAIL ) );

	// --- Token rejections -----------------------------------------------------------------
	$bad = function ( $name, $fn, $o = array() ) use ( $round, $t, $code ) {
		$r = $round( $fn, $o );
		$t( $name, is_wp_error( $r ), $code( $r ) );
	};
	$bad( 'signed with another key', null, array( 'rogue' => true ) );
	$bad( 'alg "none"', null, array( 'alg' => 'none' ) );
	$bad( 'HS256 with the public key (algorithm confusion)', null, array( 'alg' => 'HS256' ) );
	$bad( 'unknown key id', null, array( 'kid' => 'kid-unknown' ) );
	$bad( 'wrong audience (token for another app)', function ( $c ) { $c['aud'] = 'ffffffff-bbbb-4ccc-8ddd-eeeeeeeeeeee'; return $c; } );
	$bad( 'wrong issuer', function ( $c ) { $c['iss'] = 'https://login.microsoftonline.com/00000000-0000-4000-8000-000000000000/v2.0'; return $c; } );
	$bad( 'another tenant (tid)', function ( $c ) { $c['tid'] = '00000000-0000-4000-8000-000000000000'; return $c; } );
	$bad( 'expired token', function ( $c ) { $c['exp'] = time() - 3600; return $c; } );
	$bad( 'not valid yet', function ( $c ) { $c['nbf'] = time() + 3600; return $c; } );
	$bad( 'wrong nonce (token from another sign-in)', function ( $c ) { $c['nonce'] = bin2hex( random_bytes( 16 ) ); return $c; } );
	$bad( 'missing object id', function ( $c ) { unset( $c['oid'] ); return $c; } );
	$bad( 'garbage instead of a token', null, array( 'raw' => 'not.a.jwt' ) );
	$bad( 'token endpoint error', null, array( 'raw' => null ) );

	// --- State / browser binding ----------------------------------------------------------------
	$bad( 'unknown state', null, array( 'state' => bin2hex( random_bytes( 16 ) ) ) );
	$bad( 'state from another browser (cookie mismatch)', null, array( 'cookie' => bin2hex( random_bytes( 16 ) ) ) );
	$bad( 'no binding cookie', null, array( 'cookie' => '' ) );
	$b  = GSM_SSO::begin();
	parse_str( (string) wp_parse_url( $b['url'], PHP_URL_QUERY ), $q );
	$idp['token'] = $sign( array( 'iss' => $ISS, 'aud' => $CLIENT, 'tid' => $TENANT, 'oid' => $OID, 'preferred_username' => $EMAIL, 'nonce' => $q['nonce'], 'exp' => time() + 600 ) );
	$first  = GSM_SSO::complete( 'c', $q['state'], $b['cookie'] );
	$second = GSM_SSO::complete( 'c', $q['state'], $b['cookie'] );
	$t( 'a state works once; the replay is refused', $first instanceof WP_User && is_wp_error( $second ) );

	// --- Who may enter ---------------------------------------------------------------------------
	$r = $round( function ( $c ) { $c['preferred_username'] = 'someone@outside.example.org'; $c['oid'] = '12345678-aaaa-4bbb-8ccc-000000000001'; return $c; } );
	$t( 'domain not on the list', 'gsm_sso_domain' === $code( $r ) );
	$r = $round( function ( $c ) use ( $tag ) { $c['preferred_username'] = 'new-' . $tag . '@corp.example.test'; $c['oid'] = '12345678-aaaa-4bbb-8ccc-000000000002'; return $c; } );
	$t( 'no matching user and auto-create off: refused, nothing created', 'gsm_sso_nouser' === $code( $r ) && ! email_exists( 'new-' . $tag . '@corp.example.test' ) );
	$r = $round( function ( $c ) { $c['oid'] = '12345678-aaaa-4bbb-8ccc-000000000003'; return $c; } );
	$t( 'same address but a different directory object: refused (linked account)', 'gsm_sso_mismatch' === $code( $r ) );
	$r = $round( function ( $c ) use ( $admin ) { $c['email'] = get_userdata( $admin )->user_email; return $c; } );
	$t( 'the unverified "email" claim is not used to pick the user', $r instanceof WP_User && (int) $r->ID === $uid );
	update_user_meta( $uid, 'gsm_active', '0' );
	$t( 'deactivated account refused', 'gsm_sso_blocked' === $code( $round( null ) ) );
	update_user_meta( $uid, 'gsm_active', '1' );
	$sub = wp_insert_user( array( 'user_login' => 'ssos-' . $tag, 'user_email' => 'ssos-' . $tag . '@corp.example.test', 'user_pass' => wp_generate_password(), 'role' => 'subscriber' ) );
	$made[] = $sub;
	$r = $round( function ( $c ) use ( $tag ) { $c['preferred_username'] = 'ssos-' . $tag . '@corp.example.test'; $c['oid'] = '12345678-aaaa-4bbb-8ccc-000000000004'; return $c; } );
	$t( 'site account without a gas role refused', 'gsm_sso_norole' === $code( $r ) );

	// --- Auto-create -----------------------------------------------------------------------------
	wp_set_current_user( $admin );
	$rest( 'POST', '/gas/v1/sso', array( 'auto_create' => true, 'default_role' => 'lab_manager' ) );
	wp_set_current_user( 0 );
	$r = $round( function ( $c ) use ( $tag ) { $c['preferred_username'] = 'New-' . $tag . '@corp.example.test'; $c['oid'] = '12345678-aaaa-4bbb-8ccc-000000000005'; $c['name'] = 'חדשה <b>מאוד</b>'; return $c; } );
	if ( $r instanceof WP_User ) {
		$made[] = $r->ID;
	}
	$t( 'auto-create: new user with the default role, name sanitized, active, linked', $r instanceof WP_User && in_array( 'gsm_lab_manager', $r->roles, true ) && ! in_array( 'gsm_manager', $r->roles, true ) && 'חדשה מאוד' === $r->display_name && GSM_Auth::is_active( $r ) && '' !== get_user_meta( $r->ID, GSM_SSO::META_OID, true ), $code( $r ) );
	$r = $round( function ( $c ) { $c['preferred_username'] = 'x@outside.example.org'; $c['oid'] = '12345678-aaaa-4bbb-8ccc-000000000006'; return $c; } );
	$t( 'auto-create still respects the domain list', 'gsm_sso_domain' === $code( $r ) && ! email_exists( 'x@outside.example.org' ) );

	// --- Messages + switch off ---------------------------------------------------------------------
	$t( 'failure messages never contain token details', false === strpos( GSM_SSO::message( 'failed' ), 'nonce' ) && GSM_SSO::message( 'anything-else' ) === GSM_SSO::message( 'failed' ) );
	wp_set_current_user( $admin );
	$d = $rest( 'POST', '/gas/v1/sso', array( 'clear' => true ) )->get_data();
	$t( 'clear removes the secret and switches off', ! $d['has_secret'] && ! $d['enabled'] && ! GSM_SSO::active() );
	$t( 'when off, a callback cannot complete', is_wp_error( GSM_SSO::complete( 'c', bin2hex( random_bytes( 16 ) ), 'x' ) ) );
} finally {
	wp_set_current_user( $admin );
	if ( null === $before ) {
		delete_option( GSM_SSO::OPTION );
	} else {
		update_option( GSM_SSO::OPTION, $before, false );
	}
	delete_transient( 'gsm_sso_jwks' );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( array_unique( array_filter( $made ) ) as $id ) {
		if ( ! is_wp_error( $id ) ) {
			wp_delete_user( (int) $id );
		}
	}
	$wpdb->query( "DELETE FROM {$wpdb->prefix}gas_audit WHERE item_name = 'כניסה ארגונית (SSO)' OR summary LIKE '%SSO%'" );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
