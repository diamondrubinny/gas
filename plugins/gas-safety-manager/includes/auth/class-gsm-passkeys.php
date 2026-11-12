<?php
/**
 * Passkeys (WebAuthn) — sign in with a fingerprint / face / device PIN.
 *
 * - Discoverable credentials with user verification REQUIRED, so a passkey
 *   sign-in is two factors by itself (the device + biometric/PIN) and does
 *   not ask for the TOTP code.
 * - Server side: challenge (random, single use, 5 minutes), origin and RP ID
 *   taken from the site URL (never from the request), flags UP + UV,
 *   signature check with OpenSSL (ES256 and RS256), signature counter.
 * - Attestation is not requested ("none"): the public key is trusted on
 *   first use, registered only by a signed-in user from their own account.
 * - Only the public key is stored; nothing secret.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Passkeys
 */
class GSM_Passkeys {

	const META_PREFIX  = 'gsm_pk_';
	const MAX_PER_USER = 10;
	const CHALLENGE_TTL = 300;

	// ------------------------------------------------------------------
	// Helpers
	// ------------------------------------------------------------------

	/**
	 * Base64url encode.
	 *
	 * @param string $bin Binary.
	 * @return string
	 */
	public static function b64u( $bin ) {
		return rtrim( strtr( base64_encode( $bin ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Base64url decode ('' on bad input).
	 *
	 * @param mixed $s Text.
	 * @return string
	 */
	public static function b64u_dec( $s ) {
		if ( ! is_string( $s ) || '' === $s || ! preg_match( '/^[A-Za-z0-9_-]+=*$/', $s ) || strlen( $s ) > 20000 ) {
			return '';
		}
		$bin = base64_decode( strtr( rtrim( $s, '=' ), '-_', '+/' ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		return false === $bin ? '' : $bin;
	}

	/**
	 * Is WebAuthn usable on this site (https or localhost, OpenSSL present)?
	 *
	 * @return bool
	 */
	public static function available() {
		if ( ! function_exists( 'openssl_verify' ) ) {
			return false;
		}
		$p    = wp_parse_url( home_url() );
		$host = isset( $p['host'] ) ? strtolower( $p['host'] ) : '';
		if ( '' === $host || false !== filter_var( trim( $host, '[]' ), FILTER_VALIDATE_IP ) ) {
			return false; // An RP ID must be a domain name.
		}
		$https = isset( $p['scheme'] ) && 'https' === strtolower( $p['scheme'] );
		return $https || 'localhost' === $host || '.localhost' === substr( $host, -10 );
	}

	/**
	 * Relying-party id: the site's host name (from the configured site URL).
	 *
	 * @return string
	 */
	public static function rp_id() {
		return strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Expected origin: scheme://host[:port] of the site URL.
	 *
	 * @return string
	 */
	public static function origin() {
		$p = wp_parse_url( home_url() );
		$o = strtolower( $p['scheme'] ) . '://' . strtolower( $p['host'] );
		return $o . ( isset( $p['port'] ) ? ':' . (int) $p['port'] : '' );
	}

	// ------------------------------------------------------------------
	// Challenges
	// ------------------------------------------------------------------

	/**
	 * New single-use challenge.
	 *
	 * @param string $purpose create|get.
	 * @param int    $uid     User the challenge belongs to (0 for sign-in).
	 * @return array { token, challenge (binary) }
	 */
	public static function new_challenge( $purpose, $uid = 0 ) {
		$token     = bin2hex( random_bytes( 16 ) );
		$challenge = random_bytes( 32 );
		set_transient( 'gsm_pkc_' . $token, array( 'c' => self::b64u( $challenge ), 'p' => $purpose, 'u' => (int) $uid ), self::CHALLENGE_TTL );
		return array( 'token' => $token, 'challenge' => $challenge );
	}

	/**
	 * Take (and delete) a challenge.
	 *
	 * @param string $token   Token.
	 * @param string $purpose Expected purpose.
	 * @param int    $uid     Expected user.
	 * @return string Base64url challenge, '' when missing / wrong.
	 */
	private static function take_challenge( $token, $purpose, $uid = 0 ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return '';
		}
		$k = 'gsm_pkc_' . $token;
		$c = get_transient( $k );
		delete_transient( $k ); // Single use, whatever happens next.
		if ( ! is_array( $c ) || $purpose !== $c['p'] || (int) $uid !== (int) $c['u'] ) {
			return '';
		}
		return (string) $c['c'];
	}

	// ------------------------------------------------------------------
	// CBOR (the small subset WebAuthn uses)
	// ------------------------------------------------------------------

	/**
	 * Decode one CBOR item.
	 *
	 * @param string $d     Data.
	 * @param int    $o     Offset (by reference; advanced past the item).
	 * @param int    $depth Nesting depth.
	 * @return mixed
	 * @throws RuntimeException On malformed / unsupported input.
	 */
	public static function cbor( $d, &$o, $depth = 0 ) {
		if ( $depth > 8 || $o >= strlen( $d ) ) {
			throw new RuntimeException( 'cbor' );
		}
		$b    = ord( $d[ $o++ ] );
		$type = $b >> 5;
		$info = $b & 0x1f;
		if ( 7 === $type ) {
			if ( 20 === $info ) {
				return false;
			}
			if ( 21 === $info ) {
				return true;
			}
			if ( 22 === $info ) {
				return null;
			}
			throw new RuntimeException( 'cbor' );
		}
		if ( $info < 24 ) {
			$n = $info;
		} elseif ( $info <= 27 ) {
			$len = 1 << ( $info - 24 );
			if ( $o + $len > strlen( $d ) ) {
				throw new RuntimeException( 'cbor' );
			}
			$n = 0;
			for ( $i = 0; $i < $len; $i++ ) {
				$n = ( $n << 8 ) | ord( $d[ $o++ ] );
			}
			if ( $n < 0 ) {
				throw new RuntimeException( 'cbor' );
			}
		} else {
			throw new RuntimeException( 'cbor' ); // Indefinite lengths are not used by WebAuthn.
		}
		switch ( $type ) {
			case 0:
				return $n;
			case 1:
				return -1 - $n;
			case 2:
			case 3:
				if ( $n > strlen( $d ) - $o ) {
					throw new RuntimeException( 'cbor' );
				}
				$s  = substr( $d, $o, $n );
				$o += $n;
				return 2 === $type ? array( 'bytes' => $s ) : $s;
			case 4:
				if ( $n > 64 ) {
					throw new RuntimeException( 'cbor' );
				}
				$a = array();
				for ( $i = 0; $i < $n; $i++ ) {
					$a[] = self::cbor( $d, $o, $depth + 1 );
				}
				return $a;
			case 5:
				if ( $n > 64 ) {
					throw new RuntimeException( 'cbor' );
				}
				$m = array();
				for ( $i = 0; $i < $n; $i++ ) {
					$k = self::cbor( $d, $o, $depth + 1 );
					if ( ! is_int( $k ) && ! is_string( $k ) ) {
						throw new RuntimeException( 'cbor' );
					}
					$m[ $k ] = self::cbor( $d, $o, $depth + 1 );
				}
				return $m;
		}
		throw new RuntimeException( 'cbor' );
	}

	// ------------------------------------------------------------------
	// Keys
	// ------------------------------------------------------------------

	/**
	 * DER length bytes.
	 *
	 * @param int $n Length.
	 * @return string
	 */
	private static function der_len( $n ) {
		if ( $n < 0x80 ) {
			return chr( $n );
		}
		$b = ltrim( pack( 'N', $n ), "\0" );
		return chr( 0x80 | strlen( $b ) ) . $b;
	}

	/**
	 * DER unsigned INTEGER.
	 *
	 * @param string $bin Big-endian bytes.
	 * @return string
	 */
	private static function der_int( $bin ) {
		$bin = ltrim( $bin, "\0" );
		if ( '' === $bin || ( ord( $bin[0] ) & 0x80 ) ) {
			$bin = "\0" . $bin;
		}
		return "\x02" . self::der_len( strlen( $bin ) ) . $bin;
	}

	/**
	 * COSE public key (decoded CBOR map) to a PEM public key.
	 *
	 * @param array $k COSE key.
	 * @return array|null { pem, alg } or null when unsupported.
	 */
	public static function cose_to_pem( $k ) {
		$bytes = function ( $v ) {
			return is_array( $v ) && isset( $v['bytes'] ) ? $v['bytes'] : '';
		};
		$kty = isset( $k[1] ) ? $k[1] : 0;
		$alg = isset( $k[3] ) ? $k[3] : 0;
		$der = '';
		if ( 2 === $kty && -7 === $alg && isset( $k[-1] ) && 1 === $k[-1] ) {
			// EC2, P-256.
			$x = $bytes( isset( $k[-2] ) ? $k[-2] : null );
			$y = $bytes( isset( $k[-3] ) ? $k[-3] : null );
			if ( 32 !== strlen( $x ) || 32 !== strlen( $y ) ) {
				return null;
			}
			$der = hex2bin( '3059301306072a8648ce3d020106082a8648ce3d030107034200' ) . "\x04" . $x . $y;
		} elseif ( 3 === $kty && -257 === $alg ) {
			// RSA.
			$n = $bytes( isset( $k[-1] ) ? $k[-1] : null );
			$e = $bytes( isset( $k[-2] ) ? $k[-2] : null );
			if ( strlen( $n ) < 256 || strlen( $n ) > 1024 || '' === $e || strlen( $e ) > 8 ) {
				return null;
			}
			$rsa = self::der_int( $n ) . self::der_int( $e );
			$rsa = "\x30" . self::der_len( strlen( $rsa ) ) . $rsa;
			$bit = "\x03" . self::der_len( strlen( $rsa ) + 1 ) . "\0" . $rsa;
			$alg_id = hex2bin( '300d06092a864886f70d0101010500' );
			$der = "\x30" . self::der_len( strlen( $alg_id . $bit ) ) . $alg_id . $bit;
		} else {
			return null;
		}
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n"; // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		$key = openssl_pkey_get_public( $pem );
		if ( false === $key ) {
			return null;
		}
		return array( 'pem' => $pem, 'alg' => $alg );
	}

	// ------------------------------------------------------------------
	// Parsing + common checks
	// ------------------------------------------------------------------

	/**
	 * Check clientDataJSON.
	 *
	 * @param string $json      Raw clientDataJSON.
	 * @param string $type      webauthn.create | webauthn.get.
	 * @param string $challenge Expected challenge (base64url).
	 * @return bool
	 */
	private static function client_data_ok( $json, $type, $challenge ) {
		$c = json_decode( $json, true );
		if ( ! is_array( $c ) || ! isset( $c['type'], $c['challenge'], $c['origin'] ) || '' === $challenge ) {
			return false;
		}
		return $type === $c['type']
			&& is_string( $c['challenge'] ) && hash_equals( $challenge, rtrim( $c['challenge'], '=' ) )
			&& is_string( $c['origin'] ) && hash_equals( self::origin(), $c['origin'] )
			&& empty( $c['crossOrigin'] );
	}

	/**
	 * Parse authenticator data.
	 *
	 * @param string $ad Raw authenticatorData.
	 * @return array|null { flags, count, cred_id?, cose? }
	 */
	private static function parse_auth_data( $ad ) {
		if ( strlen( $ad ) < 37 || ! hash_equals( hash( 'sha256', self::rp_id(), true ), substr( $ad, 0, 32 ) ) ) {
			return null;
		}
		$out = array(
			'flags' => ord( $ad[32] ),
			'count' => unpack( 'N', substr( $ad, 33, 4 ) )[1],
		);
		if ( $out['flags'] & 0x40 ) { // Attested credential data.
			if ( strlen( $ad ) < 55 ) {
				return null;
			}
			$len = unpack( 'n', substr( $ad, 53, 2 ) )[1];
			if ( $len < 1 || $len > 1023 || strlen( $ad ) < 55 + $len + 1 ) {
				return null;
			}
			$out['cred_id'] = substr( $ad, 55, $len );
			$o              = 55 + $len;
			try {
				$out['cose'] = self::cbor( $ad, $o );
			} catch ( RuntimeException $e ) {
				return null;
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Storage
	// ------------------------------------------------------------------

	/**
	 * Meta key for a credential id.
	 *
	 * @param string $cred_id Raw credential id.
	 * @return string
	 */
	private static function meta_key( $cred_id ) {
		return self::META_PREFIX . substr( hash( 'sha256', $cred_id ), 0, 40 );
	}

	/**
	 * A user's passkeys (public info only).
	 *
	 * @param int $uid User id.
	 * @return array [ { key, name, created, last_used } ]
	 */
	public static function list_for( $uid ) {
		$out = array();
		foreach ( (array) get_user_meta( (int) $uid ) as $k => $vals ) {
			if ( 0 === strpos( $k, self::META_PREFIX ) ) {
				$v = maybe_unserialize( $vals[0] );
				if ( is_array( $v ) ) {
					$out[] = array(
						'key'       => substr( $k, strlen( self::META_PREFIX ) ),
						'name'      => (string) $v['name'],
						'created'   => (string) $v['created'],
						'last_used' => (string) $v['last_used'],
					);
				}
			}
		}
		return $out;
	}

	/**
	 * Raw credential ids of a user (for excludeCredentials).
	 *
	 * @param int $uid User id.
	 * @return string[] Base64url ids.
	 */
	public static function ids_for( $uid ) {
		$out = array();
		foreach ( (array) get_user_meta( (int) $uid ) as $k => $vals ) {
			if ( 0 === strpos( $k, self::META_PREFIX ) ) {
				$v = maybe_unserialize( $vals[0] );
				if ( is_array( $v ) && ! empty( $v['id'] ) ) {
					$out[] = (string) $v['id'];
				}
			}
		}
		return $out;
	}

	/**
	 * Remove one passkey.
	 *
	 * @param int    $uid User id.
	 * @param string $key Key from list_for().
	 * @return bool
	 */
	public static function remove( $uid, $key ) {
		if ( ! preg_match( '/^[a-f0-9]{40}$/', (string) $key ) ) {
			return false;
		}
		return delete_user_meta( (int) $uid, self::META_PREFIX . $key );
	}

	/**
	 * Remove all passkeys of a user.
	 *
	 * @param int $uid User id.
	 */
	public static function clear( $uid ) {
		foreach ( self::list_for( $uid ) as $p ) {
			self::remove( $uid, $p['key'] );
		}
	}

	// ------------------------------------------------------------------
	// Ceremonies
	// ------------------------------------------------------------------

	/**
	 * Finish registration.
	 *
	 * @param WP_User $user User.
	 * @param array   $in   { token, name, clientDataJSON, attestationObject } (base64url).
	 * @return array|WP_Error Public info of the new passkey.
	 */
	public static function register( $user, $in ) {
		$bad = new WP_Error( 'gsm_passkey_invalid', __( 'רישום ה-Passkey נכשל. נסו שוב.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		if ( ! self::available() ) {
			return new WP_Error( 'gsm_passkey_unavailable', __( 'Passkeys דורשים אתר ב-https.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( count( self::list_for( $user->ID ) ) >= self::MAX_PER_USER ) {
			return new WP_Error( 'gsm_passkey_max', __( 'הגעתם למספר ה-Passkeys המרבי. הסירו אחד לפני הוספת חדש.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$challenge = self::take_challenge( isset( $in['token'] ) ? $in['token'] : '', 'create', $user->ID );
		$cdj       = self::b64u_dec( isset( $in['clientDataJSON'] ) ? $in['clientDataJSON'] : '' );
		$att       = self::b64u_dec( isset( $in['attestationObject'] ) ? $in['attestationObject'] : '' );
		if ( '' === $cdj || '' === $att || ! self::client_data_ok( $cdj, 'webauthn.create', $challenge ) ) {
			return $bad;
		}
		try {
			$o   = 0;
			$obj = self::cbor( $att, $o );
		} catch ( RuntimeException $e ) {
			return $bad;
		}
		$ad_raw = is_array( $obj ) && isset( $obj['authData']['bytes'] ) ? $obj['authData']['bytes'] : '';
		$ad     = self::parse_auth_data( $ad_raw );
		// User present (0x01) and user verified (0x04) are both required.
		if ( ! $ad || 0x05 !== ( $ad['flags'] & 0x05 ) || empty( $ad['cred_id'] ) || empty( $ad['cose'] ) || ! is_array( $ad['cose'] ) ) {
			return $bad;
		}
		$key = self::cose_to_pem( $ad['cose'] );
		if ( ! $key ) {
			return new WP_Error( 'gsm_passkey_alg', __( 'סוג המפתח של המכשיר אינו נתמך.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$mk = self::meta_key( $ad['cred_id'] );
		global $wpdb;
		if ( (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s", $mk ) ) > 0 ) {
			return new WP_Error( 'gsm_passkey_exists', __( 'ה-Passkey הזה כבר רשום.', 'gas-safety-manager' ), array( 'status' => 409 ) );
		}
		$name = isset( $in['name'] ) && is_string( $in['name'] ) ? trim( sanitize_text_field( $in['name'] ) ) : '';
		$name = function_exists( 'mb_substr' ) ? mb_substr( $name, 0, 60 ) : substr( $name, 0, 60 );
		$rec  = array(
			'id'        => self::b64u( $ad['cred_id'] ),
			'pem'       => $key['pem'],
			'alg'       => $key['alg'],
			'count'     => (int) $ad['count'],
			'name'      => '' !== $name ? $name : 'Passkey',
			'created'   => current_time( 'mysql' ),
			'last_used' => '',
		);
		update_user_meta( $user->ID, $mk, $rec );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'security', 'user', (int) $user->ID, $user->display_name, 'נוסף Passkey: ' . $rec['name'] );
		}
		return array( 'key' => substr( $mk, strlen( self::META_PREFIX ) ), 'name' => $rec['name'], 'created' => $rec['created'], 'last_used' => '' );
	}

	/**
	 * Verify a sign-in assertion.
	 *
	 * @param array $in { token, id, clientDataJSON, authenticatorData, signature } (base64url).
	 * @return WP_User|WP_Error
	 */
	public static function verify( $in ) {
		global $wpdb;
		// One generic error: never says which check failed or whether the key exists.
		$bad = new WP_Error( 'gsm_passkey_failed', __( 'הכניסה עם Passkey נכשלה.', 'gas-safety-manager' ), array( 'status' => 401 ) );
		if ( ! self::available() ) {
			return $bad;
		}
		$challenge = self::take_challenge( isset( $in['token'] ) ? $in['token'] : '', 'get', 0 );
		$cred_id   = self::b64u_dec( isset( $in['id'] ) ? $in['id'] : '' );
		$cdj       = self::b64u_dec( isset( $in['clientDataJSON'] ) ? $in['clientDataJSON'] : '' );
		$ad_raw    = self::b64u_dec( isset( $in['authenticatorData'] ) ? $in['authenticatorData'] : '' );
		$sig       = self::b64u_dec( isset( $in['signature'] ) ? $in['signature'] : '' );
		if ( '' === $cred_id || '' === $cdj || '' === $ad_raw || '' === $sig || ! self::client_data_ok( $cdj, 'webauthn.get', $challenge ) ) {
			return $bad;
		}
		$mk  = self::meta_key( $cred_id );
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT user_id, meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", $mk ), ARRAY_A );
		$rec = $row ? maybe_unserialize( $row['meta_value'] ) : null;
		if ( ! is_array( $rec ) || empty( $rec['pem'] ) || ! hash_equals( (string) $rec['id'], self::b64u( $cred_id ) ) ) {
			return $bad;
		}
		$ad = self::parse_auth_data( $ad_raw );
		if ( ! $ad || 0x05 !== ( $ad['flags'] & 0x05 ) ) {
			return $bad;
		}
		$ok = openssl_verify( $ad_raw . hash( 'sha256', $cdj, true ), $sig, $rec['pem'], OPENSSL_ALGO_SHA256 );
		if ( 1 !== $ok ) {
			return $bad;
		}
		// Signature counter: must grow when the authenticator uses one
		// (a lower or equal value suggests a cloned key).
		$uid = (int) $row['user_id'];
		if ( ( $ad['count'] > 0 || (int) $rec['count'] > 0 ) && $ad['count'] <= (int) $rec['count'] ) {
			if ( class_exists( 'GSM_Audit' ) ) {
				GSM_Audit::log( 'security', 'user', $uid, '', 'כניסה עם Passkey נדחתה: מונה החתימות לא עלה (חשד לשכפול מפתח)' );
			}
			return $bad;
		}
		$user = get_user_by( 'id', $uid );
		if ( ! $user ) {
			return $bad;
		}
		$rec['count']     = (int) $ad['count'];
		$rec['last_used'] = current_time( 'mysql' );
		update_user_meta( $uid, $mk, $rec );
		return $user;
	}
}
