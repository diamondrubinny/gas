<?php
/**
 * Single sign-on with Microsoft Entra ID (OpenID Connect).
 *
 * Authorization Code flow with PKCE, state and nonce. The ID token is
 * verified on the server: RS256 signature against the tenant's published
 * keys, issuer, audience, tenant id, lifetime and nonce.
 *
 * Single tenant only: the Directory (tenant) ID is part of every endpoint
 * and of the expected issuer, so an account from another organization is
 * never accepted. All endpoints are derived from that id on
 * login.microsoftonline.com — no URL is taken from settings or the request.
 *
 * Identity: the stable object id (oid) once linked. On the first sign-in the
 * account is matched by the organizational user name (UPN) against the
 * user's e-mail, then linked to the oid. A linked account never accepts a
 * different oid.
 *
 * The client secret stays on the server (never returned, never logged).
 * Emergency switch: define( 'GSM_SSO_DISABLE', true ) in wp-config.php.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_SSO
 */
class GSM_SSO {

	const OPTION    = 'gsm_sso';
	const META_OID  = 'gsm_sso_oid';
	const HOST      = 'https://login.microsoftonline.com/';
	const STATE_TTL = 600;
	const COOKIE    = 'gsm_sso_state';

	// ------------------------------------------------------------------
	// Settings
	// ------------------------------------------------------------------

	/**
	 * Stored settings.
	 *
	 * @return array
	 */
	public static function config() {
		$c = get_option( self::OPTION, array() );
		$c = is_array( $c ) ? $c : array();
		return array(
			'enabled'      => ! empty( $c['enabled'] ),
			'tenant'       => isset( $c['tenant'] ) ? (string) $c['tenant'] : '',
			'client_id'    => isset( $c['client_id'] ) ? (string) $c['client_id'] : '',
			'secret'       => isset( $c['secret'] ) ? (string) $c['secret'] : '',
			'domains'      => isset( $c['domains'] ) && is_array( $c['domains'] ) ? array_values( $c['domains'] ) : array(),
			'auto_create'  => ! empty( $c['auto_create'] ),
			'default_role' => isset( $c['default_role'] ) && in_array( $c['default_role'], array( 'lab_manager', 'pi', 'ahmat' ), true ) ? $c['default_role'] : 'pi',
		);
	}

	/**
	 * Settings for the admin screen (secret masked) + the redirect URI to register.
	 *
	 * @return array
	 */
	public static function public_config() {
		$c = self::config();
		return array(
			'enabled'      => $c['enabled'],
			'tenant'       => $c['tenant'],
			'client_id'    => $c['client_id'],
			'has_secret'   => '' !== $c['secret'],
			'domains'      => $c['domains'],
			'auto_create'  => $c['auto_create'],
			'default_role' => $c['default_role'],
			'redirect_uri' => self::redirect_uri(),
			'https'        => 'https' === strtolower( (string) wp_parse_url( home_url(), PHP_URL_SCHEME ) ),
			'bypassed'     => self::disabled(),
		);
	}

	/**
	 * Emergency switch.
	 *
	 * @return bool
	 */
	public static function disabled() {
		return defined( 'GSM_SSO_DISABLE' ) && GSM_SSO_DISABLE;
	}

	/**
	 * Is SSO usable right now?
	 *
	 * @return bool
	 */
	public static function active() {
		$c = self::config();
		return ! self::disabled() && $c['enabled'] && '' !== $c['tenant'] && '' !== $c['client_id'] && '' !== $c['secret'];
	}

	/**
	 * A GUID, lower-cased ('' when malformed).
	 *
	 * @param string $v Value.
	 * @return string
	 */
	private static function guid( $v ) {
		$v = strtolower( trim( (string) $v ) );
		return preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $v ) ? $v : '';
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Input.
	 * @return array|WP_Error Public config.
	 */
	public static function save( $in ) {
		$c = self::config();
		foreach ( array( 'tenant' => 'Directory (tenant) ID', 'client_id' => 'Application (client) ID' ) as $k => $label ) {
			if ( isset( $in[ $k ] ) && '' !== trim( (string) $in[ $k ] ) ) {
				$g = self::guid( $in[ $k ] );
				if ( '' === $g ) {
					/* translators: %s: field name */
					return new WP_Error( 'gsm_sso_guid', sprintf( __( '%s אינו בפורמט תקין (מזהה בצורת xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx).', 'gas-safety-manager' ), $label ), array( 'status' => 400 ) );
				}
				if ( $g !== $c[ $k ] ) {
					delete_transient( 'gsm_sso_jwks' );
				}
				$c[ $k ] = $g;
			}
		}
		if ( isset( $in['secret'] ) && '' !== trim( (string) $in['secret'] ) ) {
			$s = trim( (string) $in['secret'] );
			if ( strlen( $s ) < 16 || strlen( $s ) > 256 || preg_match( '/\s/', $s ) ) {
				return new WP_Error( 'gsm_sso_secret', __( 'ה-client secret אינו בפורמט תקין.', 'gas-safety-manager' ), array( 'status' => 400 ) );
			}
			$c['secret'] = $s;
		}
		if ( isset( $in['domains'] ) ) {
			$list = is_array( $in['domains'] ) ? $in['domains'] : preg_split( '/[\s,;]+/', (string) $in['domains'] );
			$out  = array();
			foreach ( (array) $list as $d ) {
				$d = strtolower( trim( (string) $d, " \t\n\r\0\x0B@." ) );
				if ( '' === $d ) {
					continue;
				}
				if ( ! preg_match( '/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $d ) ) {
					/* translators: %s: domain */
					return new WP_Error( 'gsm_sso_domain', sprintf( __( 'דומיין לא תקין: %s', 'gas-safety-manager' ), sanitize_text_field( $d ) ), array( 'status' => 400 ) );
				}
				$out[ $d ] = 1;
			}
			$c['domains'] = array_slice( array_keys( $out ), 0, 20 );
		}
		if ( isset( $in['auto_create'] ) ) {
			$c['auto_create'] = (bool) $in['auto_create'];
		}
		if ( isset( $in['default_role'] ) && in_array( $in['default_role'], array( 'lab_manager', 'pi', 'ahmat' ), true ) ) {
			$c['default_role'] = $in['default_role']; // Never "manager".
		}
		if ( ! empty( $in['clear'] ) ) {
			$c['secret']  = '';
			$c['enabled'] = false;
		}
		if ( isset( $in['enabled'] ) ) {
			$c['enabled'] = (bool) $in['enabled'];
		}
		if ( $c['enabled'] && ( '' === $c['tenant'] || '' === $c['client_id'] || '' === $c['secret'] ) ) {
			return new WP_Error( 'gsm_sso_incomplete', __( 'כדי להפעיל נדרשים Directory ID, Application ID ו-client secret.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( $c['enabled'] && $c['auto_create'] && empty( $c['domains'] ) ) {
			return new WP_Error( 'gsm_sso_domains', __( 'יצירת חשבונות אוטומטית מחייבת רשימת דומיינים מותרים.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		update_option( self::OPTION, $c, false );
		return self::public_config();
	}

	// ------------------------------------------------------------------
	// Endpoints (all derived from the tenant id)
	// ------------------------------------------------------------------

	/**
	 * Redirect URI to register in Entra.
	 *
	 * @return string
	 */
	public static function redirect_uri() {
		return rest_url( 'gas/v1/sso/callback' );
	}

	/**
	 * Tenant base URL.
	 *
	 * @return string
	 */
	private static function base() {
		return self::HOST . self::config()['tenant'];
	}

	/**
	 * Expected issuer of ID tokens.
	 *
	 * @return string
	 */
	public static function issuer() {
		return self::base() . '/v2.0';
	}

	// ------------------------------------------------------------------
	// Start
	// ------------------------------------------------------------------

	/**
	 * Begin a sign-in.
	 *
	 * @return array|WP_Error { url, cookie } — redirect target + value for the browser-binding cookie.
	 */
	public static function begin() {
		if ( ! self::active() ) {
			return new WP_Error( 'gsm_sso_off', __( 'כניסה ארגונית אינה פעילה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$c        = self::config();
		$state    = bin2hex( random_bytes( 16 ) );
		$nonce    = bin2hex( random_bytes( 16 ) );
		$verifier = GSM_Passkeys::b64u( random_bytes( 48 ) );
		$binding  = bin2hex( random_bytes( 16 ) );
		set_transient(
			'gsm_sso_' . $state,
			array( 'n' => $nonce, 'v' => $verifier, 'b' => hash( 'sha256', $binding ) ),
			self::STATE_TTL
		);
		$url = self::base() . '/oauth2/v2.0/authorize?' . http_build_query(
			array(
				'client_id'             => $c['client_id'],
				'response_type'         => 'code',
				'response_mode'         => 'query',
				'redirect_uri'          => self::redirect_uri(),
				'scope'                 => 'openid profile email',
				'state'                 => $state,
				'nonce'                 => $nonce,
				'code_challenge'        => GSM_Passkeys::b64u( hash( 'sha256', $verifier, true ) ),
				'code_challenge_method' => 'S256',
				'prompt'                => 'select_account',
			),
			'',
			'&',
			PHP_QUERY_RFC3986
		);
		return array( 'url' => $url, 'cookie' => $binding );
	}

	// ------------------------------------------------------------------
	// Token verification
	// ------------------------------------------------------------------

	/**
	 * The tenant's signing keys (kid => PEM), cached.
	 *
	 * @param bool $refresh Ignore the cache.
	 * @return array
	 */
	private static function keys( $refresh = false ) {
		$cached = $refresh ? false : get_transient( 'gsm_sso_jwks' );
		if ( is_array( $cached ) && $cached ) {
			return $cached;
		}
		$res = GSM_Security::outbound_get( self::base() . '/discovery/v2.0/keys', array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array();
		}
		$d    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$keys = array();
		foreach ( isset( $d['keys'] ) && is_array( $d['keys'] ) ? $d['keys'] : array() as $k ) {
			if ( ! is_array( $k ) || ! isset( $k['kty'], $k['kid'], $k['n'], $k['e'] ) || 'RSA' !== $k['kty'] || ( isset( $k['use'] ) && 'sig' !== $k['use'] ) ) {
				continue;
			}
			$pem = GSM_Passkeys::rsa_pem( GSM_Passkeys::b64u_dec( $k['n'] ), GSM_Passkeys::b64u_dec( $k['e'] ) );
			if ( '' !== $pem ) {
				$keys[ (string) $k['kid'] ] = $pem;
			}
		}
		if ( $keys ) {
			set_transient( 'gsm_sso_jwks', $keys, 12 * HOUR_IN_SECONDS );
		}
		return $keys;
	}

	/**
	 * Verify an ID token and return its claims.
	 *
	 * @param string $jwt   ID token.
	 * @param string $nonce Expected nonce.
	 * @return array|null Claims, or null when anything is wrong.
	 */
	public static function verify_id_token( $jwt, $nonce ) {
		$c     = self::config();
		$parts = is_string( $jwt ) ? explode( '.', $jwt ) : array();
		if ( 3 !== count( $parts ) || strlen( $jwt ) > 16000 ) {
			return null;
		}
		$head   = json_decode( GSM_Passkeys::b64u_dec( $parts[0] ), true );
		$claims = json_decode( GSM_Passkeys::b64u_dec( $parts[1] ), true );
		$sig    = GSM_Passkeys::b64u_dec( $parts[2] );
		// Only RS256: no "none", no HMAC with the public key as the secret.
		if ( ! is_array( $head ) || ! is_array( $claims ) || '' === $sig || ! isset( $head['alg'], $head['kid'] ) || 'RS256' !== $head['alg'] || ! is_string( $head['kid'] ) ) {
			return null;
		}
		$keys = self::keys();
		if ( ! isset( $keys[ $head['kid'] ] ) ) {
			$keys = self::keys( true ); // Key rollover: fetch once more.
		}
		if ( ! isset( $keys[ $head['kid'] ] ) || 1 !== openssl_verify( $parts[0] . '.' . $parts[1], $sig, $keys[ $head['kid'] ], OPENSSL_ALGO_SHA256 ) ) {
			return null;
		}
		$now = time();
		$aud = isset( $claims['aud'] ) ? $claims['aud'] : '';
		if ( ! isset( $claims['iss'], $claims['tid'], $claims['exp'], $claims['nonce'], $claims['oid'] )
			|| ! is_string( $claims['iss'] ) || ! hash_equals( self::issuer(), $claims['iss'] )
			|| ! is_string( $aud ) || ! hash_equals( $c['client_id'], strtolower( $aud ) )
			|| ! is_string( $claims['tid'] ) || ! hash_equals( $c['tenant'], strtolower( $claims['tid'] ) )
			|| (int) $claims['exp'] < $now - 60
			|| ( isset( $claims['nbf'] ) && (int) $claims['nbf'] > $now + 300 )
			|| ! is_string( $claims['nonce'] ) || '' === $nonce || ! hash_equals( $nonce, $claims['nonce'] )
			|| '' === self::guid( $claims['oid'] ) ) {
			return null;
		}
		return $claims;
	}

	// ------------------------------------------------------------------
	// Finish
	// ------------------------------------------------------------------

	/**
	 * Generic failure.
	 *
	 * @param string $code Reason slug (shown to the user as a message).
	 * @return WP_Error
	 */
	private static function fail( $code ) {
		return new WP_Error( 'gsm_sso_' . $code, self::message( $code ), array( 'status' => 401 ) );
	}

	/**
	 * User-facing message for a reason slug.
	 *
	 * @param string $code Slug.
	 * @return string
	 */
	public static function message( $code ) {
		$m = array(
			'failed'   => __( 'הכניסה הארגונית נכשלה. נסו שוב.', 'gas-safety-manager' ),
			'domain'   => __( 'החשבון הארגוני הזה אינו מורשה להיכנס למערכת.', 'gas-safety-manager' ),
			'nouser'   => __( 'אין לחשבון הזה משתמש במערכת. פנו למנהל המערכת.', 'gas-safety-manager' ),
			'blocked'  => __( 'החשבון שלך הושבת. פנה למנהל.', 'gas-safety-manager' ),
			'norole'   => __( 'אין לך הרשאה לגשת למערכת. פנה למנהל.', 'gas-safety-manager' ),
			'mismatch' => __( 'המשתמש במערכת מקושר לחשבון ארגוני אחר. פנו למנהל המערכת.', 'gas-safety-manager' ),
			'off'      => __( 'כניסה ארגונית אינה פעילה.', 'gas-safety-manager' ),
		);
		return isset( $m[ $code ] ) ? $m[ $code ] : $m['failed'];
	}

	/**
	 * Finish a sign-in: state + browser binding, code exchange, token checks, user.
	 *
	 * @param string $code   Authorization code.
	 * @param string $state  State.
	 * @param string $cookie Browser-binding cookie value.
	 * @return WP_User|WP_Error
	 */
	public static function complete( $code, $state, $cookie ) {
		if ( ! self::active() ) {
			return self::fail( 'off' );
		}
		if ( ! is_string( $state ) || ! preg_match( '/^[a-f0-9]{32}$/', $state ) || ! is_string( $code ) || '' === $code || strlen( $code ) > 4096 ) {
			return self::fail( 'failed' );
		}
		$k  = 'gsm_sso_' . $state;
		$st = get_transient( $k );
		delete_transient( $k ); // Single use.
		// The state must belong to THIS browser (login-CSRF protection).
		if ( ! is_array( $st ) || ! is_string( $cookie ) || '' === $cookie || ! hash_equals( (string) $st['b'], hash( 'sha256', $cookie ) ) ) {
			return self::fail( 'failed' );
		}
		$c   = self::config();
		$res = GSM_Security::outbound_post(
			self::base() . '/oauth2/v2.0/token',
			array(
				'timeout' => 15,
				'body'    => array(
					'client_id'     => $c['client_id'],
					'client_secret' => $c['secret'],
					'grant_type'    => 'authorization_code',
					'code'          => $code,
					'redirect_uri'  => self::redirect_uri(),
					'code_verifier' => (string) $st['v'],
				),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return self::fail( 'failed' );
		}
		$tok    = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		$claims = is_array( $tok ) && isset( $tok['id_token'] ) ? self::verify_id_token( $tok['id_token'], (string) $st['n'] ) : null;
		if ( ! $claims ) {
			return self::fail( 'failed' );
		}
		return self::user_for( $claims );
	}

	/**
	 * Find (or create) the user for verified claims.
	 *
	 * @param array $claims Verified ID-token claims.
	 * @return WP_User|WP_Error
	 */
	private static function user_for( $claims ) {
		$c   = self::config();
		$oid = self::guid( $claims['oid'] );
		// The organizational user name; the optional "email" claim is not used
		// for matching (it can be unverified).
		$upn = isset( $claims['preferred_username'] ) && is_string( $claims['preferred_username'] ) ? strtolower( trim( $claims['preferred_username'] ) ) : '';
		if ( ! is_email( $upn ) ) {
			return self::fail( 'failed' );
		}
		if ( $c['domains'] && ! in_array( substr( strrchr( $upn, '@' ), 1 ), $c['domains'], true ) ) {
			return self::fail( 'domain' );
		}
		$found = get_users( array( 'meta_key' => self::META_OID, 'meta_value' => $oid, 'number' => 2 ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key, WordPress.DB.SlowDBQuery.slow_db_query_meta_value
		$user  = 1 === count( $found ) ? $found[0] : null;
		if ( ! $user ) {
			$user = get_user_by( 'email', $upn );
			if ( $user ) {
				$linked = (string) get_user_meta( $user->ID, self::META_OID, true );
				if ( '' !== $linked && ! hash_equals( $linked, $oid ) ) {
					return self::fail( 'mismatch' ); // Same address, different directory object.
				}
				update_user_meta( $user->ID, self::META_OID, $oid );
			}
		}
		if ( ! $user ) {
			if ( ! $c['auto_create'] || empty( $c['domains'] ) ) {
				return self::fail( 'nouser' );
			}
			$name = isset( $claims['name'] ) && is_string( $claims['name'] ) ? sanitize_text_field( $claims['name'] ) : '';
			$uid  = wp_insert_user(
				array(
					'user_login'   => $upn,
					'user_email'   => $upn,
					'display_name' => '' !== $name ? $name : strstr( $upn, '@', true ),
					'user_pass'    => wp_generate_password( 32, true, true ),
					'role'         => GSM_REST_Users::role_map()[ $c['default_role'] ],
				)
			);
			if ( is_wp_error( $uid ) ) {
				return self::fail( 'failed' );
			}
			update_user_meta( $uid, 'gsm_active', '1' );
			update_user_meta( $uid, self::META_OID, $oid );
			$user = get_user_by( 'id', $uid );
			if ( class_exists( 'GSM_Audit' ) ) {
				GSM_Audit::log( 'create', 'user', (int) $uid, $user->display_name, 'נוצר משתמש אוטומטית בכניסה ארגונית (SSO)' );
			}
		}
		if ( ! GSM_Auth::is_active( $user ) ) {
			return self::fail( 'blocked' );
		}
		if ( ! ( user_can( $user, 'gsm_report' ) || user_can( $user, 'gsm_edit_equipment' ) || user_can( $user, 'gsm_manage_all' ) ) ) {
			return self::fail( 'norole' );
		}
		return $user;
	}

	/**
	 * Was the current session opened through SSO?
	 *
	 * @param int $uid User id.
	 * @return bool
	 */
	public static function session_is_sso( $uid ) {
		if ( ! function_exists( 'wp_get_session_token' ) || ! class_exists( 'WP_Session_Tokens' ) ) {
			return false;
		}
		$token = wp_get_session_token();
		if ( '' === (string) $token ) {
			return false;
		}
		$s = WP_Session_Tokens::get_instance( (int) $uid )->get( $token );
		return is_array( $s ) && ! empty( $s['gsm_sso'] );
	}

	/**
	 * Check that the tenant exists (settings screen).
	 *
	 * @return array { ok, reason }
	 */
	public static function test() {
		$c = self::config();
		if ( '' === $c['tenant'] ) {
			return array( 'ok' => false, 'reason' => 'no-tenant' );
		}
		$res = GSM_Security::outbound_get( self::base() . '/v2.0/.well-known/openid-configuration', array( 'timeout' => 10 ) );
		if ( is_wp_error( $res ) ) {
			return array( 'ok' => false, 'reason' => 'unreachable' );
		}
		$d = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( 200 !== (int) wp_remote_retrieve_response_code( $res ) || ! is_array( $d ) || ! isset( $d['issuer'] ) || self::issuer() !== $d['issuer'] ) {
			return array( 'ok' => false, 'reason' => 'unknown-tenant' );
		}
		return array( 'ok' => true, 'reason' => '', 'keys' => count( self::keys( true ) ) );
	}
}
