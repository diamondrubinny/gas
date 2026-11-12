<?php
/**
 * Two-factor authentication: TOTP (RFC 6238) + one-time backup codes.
 *
 * - Works with any authenticator app (SHA-1, 6 digits, 30 s — the defaults
 *   every app supports). No external service, no SMS.
 * - Enforced in the core `authenticate` filter AFTER the password check, so
 *   it covers every password login path (app login, wp-login.php, XML-RPC).
 *   Application passwords are switched off for users who enabled 2FA.
 * - The TOTP secret is stored encrypted (AES-256-GCM, key derived from the
 *   WordPress auth salt). Backup codes are stored hashed.
 * - A code (time step) is accepted once; 5 wrong codes lock the second step
 *   for 15 minutes.
 * - Only standard primitives are used (hash_hmac, openssl, random_bytes,
 *   hash_equals); the TOTP maths is checked against the RFC test vectors.
 *
 * Emergency switch (locked-out site owner): define( 'GSM_2FA_DISABLE', true )
 * in wp-config.php skips the second step for everyone.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_2FA
 */
class GSM_2FA {

	const META_SECRET  = 'gsm_2fa_secret';
	const META_PENDING = 'gsm_2fa_pending';
	const META_BACKUP  = 'gsm_2fa_backup';
	const META_LAST    = 'gsm_2fa_last_step';
	const META_SINCE   = 'gsm_2fa_enabled_at';
	const POLICY       = 'gsm_2fa_policy';
	const PERIOD       = 30;
	const DIGITS       = 6;
	const MAX_FAILS    = 5;
	const LOCK_SECONDS = 900;
	const BACKUP_COUNT = 8;
	const ALPHABET     = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

	/**
	 * Code supplied by the REST login for the current request.
	 *
	 * @var string
	 */
	public static $code = '';

	// ------------------------------------------------------------------
	// State
	// ------------------------------------------------------------------

	/**
	 * Emergency switch.
	 *
	 * @return bool
	 */
	public static function disabled() {
		return defined( 'GSM_2FA_DISABLE' ) && GSM_2FA_DISABLE;
	}

	/**
	 * Can secrets be encrypted on this server?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'openssl_encrypt' ) && in_array( 'aes-256-gcm', openssl_get_cipher_methods(), true );
	}

	/**
	 * Has the user enabled 2FA?
	 *
	 * @param int $uid User id.
	 * @return bool
	 */
	public static function enabled( $uid ) {
		return '' !== (string) get_user_meta( (int) $uid, self::META_SECRET, true );
	}

	/**
	 * Policy: is 2FA mandatory for managers?
	 *
	 * @return bool
	 */
	public static function required_for_managers() {
		$p = get_option( self::POLICY, array() );
		return is_array( $p ) && ! empty( $p['require_managers'] );
	}

	/**
	 * Must this user set up 2FA before doing manager work?
	 *
	 * @param WP_User|null $user User (default: current).
	 * @return bool
	 */
	public static function setup_required( $user = null ) {
		$user = $user ? $user : wp_get_current_user();
		if ( ! $user || ! $user->ID || self::disabled() || ! self::required_for_managers() ) {
			return false;
		}
		if ( ! user_can( $user, 'gsm_manage_all' ) || self::enabled( $user->ID ) ) {
			return false;
		}
		// Signed in through the organization's SSO: the second factor is the
		// identity provider's job (its MFA policy), not a TOTP code here.
		if ( class_exists( 'GSM_SSO' ) && get_current_user_id() === (int) $user->ID && GSM_SSO::session_is_sso( $user->ID ) ) {
			return false;
		}
		return true;
	}

	// ------------------------------------------------------------------
	// Base32 + TOTP
	// ------------------------------------------------------------------

	/**
	 * Base32 encode (RFC 4648, no padding).
	 *
	 * @param string $bin Binary.
	 * @return string
	 */
	public static function b32_encode( $bin ) {
		$bits = '';
		$len  = strlen( $bin );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( ord( $bin[ $i ] ) ), 8, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 5 ) as $chunk ) {
			$out .= self::ALPHABET[ bindec( str_pad( $chunk, 5, '0' ) ) ];
		}
		return $out;
	}

	/**
	 * Base32 decode. Returns '' on invalid input.
	 *
	 * @param string $b32 Base32.
	 * @return string
	 */
	public static function b32_decode( $b32 ) {
		$b32 = strtoupper( preg_replace( '/[\s=-]+/', '', (string) $b32 ) );
		if ( '' === $b32 || strspn( $b32, self::ALPHABET ) !== strlen( $b32 ) ) {
			return '';
		}
		$bits = '';
		$len  = strlen( $b32 );
		for ( $i = 0; $i < $len; $i++ ) {
			$bits .= str_pad( decbin( strpos( self::ALPHABET, $b32[ $i ] ) ), 5, '0', STR_PAD_LEFT );
		}
		$out = '';
		foreach ( str_split( $bits, 8 ) as $byte ) {
			if ( 8 === strlen( $byte ) ) {
				$out .= chr( bindec( $byte ) );
			}
		}
		return $out;
	}

	/**
	 * HOTP value for a counter (RFC 4226).
	 *
	 * @param string $key     Binary key.
	 * @param int    $counter Counter (time step).
	 * @return string Zero-padded code.
	 */
	public static function hotp( $key, $counter ) {
		$bin  = pack( 'N*', 0, (int) $counter ); // 64-bit big-endian (steps fit in 32 bits until 5391).
		$hash = hash_hmac( 'sha1', $bin, $key, true );
		$off  = ord( $hash[19] ) & 0x0f;
		$val  = ( ( ord( $hash[ $off ] ) & 0x7f ) << 24 )
			| ( ord( $hash[ $off + 1 ] ) << 16 )
			| ( ord( $hash[ $off + 2 ] ) << 8 )
			| ord( $hash[ $off + 3 ] );
		return str_pad( (string) ( $val % ( 10 ** self::DIGITS ) ), self::DIGITS, '0', STR_PAD_LEFT );
	}

	/**
	 * Which time step (within ±1) does this code match? 0 when none.
	 *
	 * @param string   $key  Binary key.
	 * @param string   $code 6 digits.
	 * @param int|null $now  Unix time (tests).
	 * @return int Matching step, or 0.
	 */
	public static function match_step( $key, $code, $now = null ) {
		$code = preg_replace( '/\s+/', '', (string) $code );
		if ( ! preg_match( '/^\d{6}$/', $code ) || '' === $key ) {
			return 0;
		}
		$step  = (int) floor( ( null === $now ? time() : (int) $now ) / self::PERIOD );
		$found = 0;
		foreach ( array( 0, -1, 1 ) as $d ) {
			// No early exit: compare every candidate in constant time.
			if ( hash_equals( self::hotp( $key, $step + $d ), $code ) && ! $found ) {
				$found = $step + $d;
			}
		}
		return $found;
	}

	// ------------------------------------------------------------------
	// Secret storage
	// ------------------------------------------------------------------

	/**
	 * Encryption key (never stored).
	 *
	 * @return string 32 bytes.
	 */
	private static function key() {
		return hash_hmac( 'sha256', 'gsm-2fa-secret-v1', wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypt a secret.
	 *
	 * @param string $plain Binary secret.
	 * @return string '' on failure.
	 */
	private static function seal( $plain ) {
		if ( ! self::available() ) {
			return '';
		}
		$iv  = random_bytes( 12 );
		$tag = '';
		$ct  = openssl_encrypt( $plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $ct ? '' : 'v1:' . base64_encode( $iv . $tag . $ct ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	/**
	 * Decrypt a stored secret.
	 *
	 * @param string $stored Stored value.
	 * @return string '' when it cannot be decrypted.
	 */
	private static function open( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, 'v1:' ) || ! self::available() ) {
			return '';
		}
		$raw = base64_decode( substr( $stored, 3 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$pt = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $pt ? '' : $pt;
	}

	// ------------------------------------------------------------------
	// Attempts
	// ------------------------------------------------------------------

	/**
	 * Is the second step locked for this user?
	 *
	 * @param int $uid User id.
	 * @return bool
	 */
	public static function locked( $uid ) {
		return (int) get_transient( 'gsm_2fa_fail_' . (int) $uid ) >= self::MAX_FAILS;
	}

	/**
	 * Count a wrong code.
	 *
	 * @param int $uid User id.
	 */
	private static function fail( $uid ) {
		$k = 'gsm_2fa_fail_' . (int) $uid;
		$n = (int) get_transient( $k ) + 1;
		set_transient( $k, $n, self::LOCK_SECONDS );
		if ( self::MAX_FAILS === $n && class_exists( 'GSM_Audit' ) ) {
			$u = get_userdata( (int) $uid );
			GSM_Audit::log( 'security', 'user', (int) $uid, $u ? $u->display_name : '', 'אימות דו-שלבי ננעל ל-15 דקות אחרי 5 קודים שגויים' );
		}
	}

	// ------------------------------------------------------------------
	// Verification
	// ------------------------------------------------------------------

	/**
	 * Verify a TOTP or backup code for an enrolled user. Consumes it.
	 *
	 * @param int    $uid  User id.
	 * @param string $code Code as typed.
	 * @return bool
	 */
	public static function verify( $uid, $code ) {
		$uid  = (int) $uid;
		$code = trim( (string) $code );
		if ( self::locked( $uid ) || '' === $code ) {
			return false;
		}
		$digits = preg_replace( '/\s+/', '', $code );
		if ( preg_match( '/^\d{6}$/', $digits ) ) {
			$key  = self::open( get_user_meta( $uid, self::META_SECRET, true ) );
			$step = self::match_step( $key, $digits );
			// Each time step is accepted once (replay protection).
			if ( $step && $step > (int) get_user_meta( $uid, self::META_LAST, true ) ) {
				update_user_meta( $uid, self::META_LAST, $step );
				delete_transient( 'gsm_2fa_fail_' . $uid );
				return true;
			}
			self::fail( $uid );
			return false;
		}
		// Backup code: XXXXX-XXXXX.
		$norm = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', $code ) );
		if ( 10 === strlen( $norm ) ) {
			$hashes = get_user_meta( $uid, self::META_BACKUP, true );
			$hashes = is_array( $hashes ) ? $hashes : array();
			foreach ( $hashes as $i => $h ) {
				if ( wp_check_password( $norm, $h ) ) {
					unset( $hashes[ $i ] );
					update_user_meta( $uid, self::META_BACKUP, array_values( $hashes ) );
					delete_transient( 'gsm_2fa_fail_' . $uid );
					if ( class_exists( 'GSM_Audit' ) ) {
						$u = get_userdata( $uid );
						GSM_Audit::log( 'security', 'user', $uid, $u ? $u->display_name : '', 'נעשה שימוש בקוד גיבוי לאימות דו-שלבי (נותרו ' . count( $hashes ) . ')' );
					}
					return true;
				}
			}
		}
		self::fail( $uid );
		return false;
	}

	/**
	 * `authenticate` filter (priority 40, after the password + active checks).
	 *
	 * @param WP_User|WP_Error|null $user     Result so far.
	 * @param string                $username Username.
	 * @param string                $password Password.
	 * @return WP_User|WP_Error|null
	 */
	public static function check_login( $user, $username = '', $password = '' ) {
		if ( ! ( $user instanceof WP_User ) || self::disabled() || ! self::enabled( $user->ID ) ) {
			return $user;
		}
		// No password in this call: the user was recognized by an existing
		// auth cookie (already passed the second step when it was issued).
		if ( '' === (string) $password ) {
			return $user;
		}
		$code = self::$code;
		if ( '' === $code && isset( $_POST['gsm_otp'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- login form, pre-auth.
			$code = sanitize_text_field( wp_unslash( $_POST['gsm_otp'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( self::locked( $user->ID ) ) {
			return new WP_Error( 'gsm_2fa_locked', __( 'יותר מדי קודים שגויים. נסו שוב בעוד 15 דקות.', 'gas-safety-manager' ) );
		}
		if ( '' === trim( (string) $code ) ) {
			return new WP_Error( 'gsm_2fa_required', __( 'נדרש קוד אימות מאפליקציית האימות.', 'gas-safety-manager' ) );
		}
		if ( self::verify( $user->ID, $code ) ) {
			return $user;
		}
		return new WP_Error( 'gsm_2fa_invalid', __( 'קוד האימות שגוי או שכבר נעשה בו שימוש.', 'gas-safety-manager' ) );
	}

	/**
	 * wp-login.php: optional code field.
	 */
	public static function login_field() {
		if ( self::disabled() ) {
			return;
		}
		echo '<p><label for="gsm_otp">' . esc_html__( 'קוד אימות (אם הפעלתם אימות דו-שלבי)', 'gas-safety-manager' ) . '</label>';
		echo '<input type="text" name="gsm_otp" id="gsm_otp" class="input" value="" size="20" inputmode="numeric" autocomplete="one-time-code" dir="ltr"></p>';
	}

	/**
	 * Application passwords skip the second step, so they are not offered
	 * to users who enabled 2FA.
	 *
	 * @param bool    $available Available.
	 * @param WP_User $user      User.
	 * @return bool
	 */
	public static function app_passwords( $available, $user ) {
		if ( $available && $user instanceof WP_User && ! self::disabled() && self::enabled( $user->ID ) ) {
			return false;
		}
		return $available;
	}

	// ------------------------------------------------------------------
	// Enrollment
	// ------------------------------------------------------------------

	/**
	 * Start enrollment: a new pending secret (valid 15 minutes).
	 *
	 * @param WP_User $user User.
	 * @return array|WP_Error { secret, uri }
	 */
	public static function begin( $user ) {
		if ( ! self::available() ) {
			return new WP_Error( 'gsm_2fa_unavailable', __( 'השרת אינו תומך בהצפנה הנדרשת (OpenSSL AES-256-GCM).', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}
		if ( self::enabled( $user->ID ) ) {
			return new WP_Error( 'gsm_2fa_on', __( 'אימות דו-שלבי כבר פעיל.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$secret = random_bytes( 20 );
		$sealed = self::seal( $secret );
		if ( '' === $sealed ) {
			return new WP_Error( 'gsm_2fa_unavailable', __( 'לא ניתן היה להצפין את המפתח.', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}
		update_user_meta( $user->ID, self::META_PENDING, array( 's' => $sealed, 't' => time() ) );
		$b32    = self::b32_encode( $secret );
		$issuer = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$issuer = '' !== trim( $issuer ) ? $issuer : 'Gas Safety';
		$label  = rawurlencode( $issuer . ':' . $user->user_login );
		return array(
			'secret' => $b32,
			'uri'    => 'otpauth://totp/' . $label . '?secret=' . $b32 . '&issuer=' . rawurlencode( $issuer ) . '&algorithm=SHA1&digits=' . self::DIGITS . '&period=' . self::PERIOD,
		);
	}

	/**
	 * Finish enrollment with a code from the app.
	 *
	 * @param WP_User $user User.
	 * @param string  $code Code.
	 * @return array|WP_Error { backup_codes }
	 */
	public static function confirm( $user, $code ) {
		$uid = (int) $user->ID;
		if ( self::locked( $uid ) ) {
			return new WP_Error( 'gsm_2fa_locked', __( 'יותר מדי קודים שגויים. נסו שוב בעוד 15 דקות.', 'gas-safety-manager' ), array( 'status' => 429 ) );
		}
		$p = get_user_meta( $uid, self::META_PENDING, true );
		if ( ! is_array( $p ) || empty( $p['s'] ) || time() - (int) $p['t'] > 900 ) {
			delete_user_meta( $uid, self::META_PENDING );
			return new WP_Error( 'gsm_2fa_expired', __( 'ההגדרה פגה. התחילו מחדש.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$step = self::match_step( self::open( $p['s'] ), $code );
		if ( ! $step ) {
			self::fail( $uid );
			return new WP_Error( 'gsm_2fa_invalid', __( 'הקוד שגוי. ודאו שהשעון בטלפון מדויק ונסו שוב.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		update_user_meta( $uid, self::META_SECRET, $p['s'] );
		update_user_meta( $uid, self::META_LAST, $step );
		update_user_meta( $uid, self::META_SINCE, current_time( 'mysql' ) );
		delete_user_meta( $uid, self::META_PENDING );
		delete_transient( 'gsm_2fa_fail_' . $uid );
		$codes = self::new_backup_codes( $uid );
		// Other sessions were opened without the second step: end them.
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $uid )->destroy_others( wp_get_session_token() );
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'security', 'user', $uid, $user->display_name, 'הופעל אימות דו-שלבי' );
		}
		return array( 'backup_codes' => $codes );
	}

	/**
	 * Replace the backup codes; returns the plain codes (shown once).
	 *
	 * @param int $uid User id.
	 * @return string[]
	 */
	public static function new_backup_codes( $uid ) {
		$plain  = array();
		$hashes = array();
		for ( $i = 0; $i < self::BACKUP_COUNT; $i++ ) {
			$c = '';
			for ( $j = 0; $j < 10; $j++ ) {
				$c .= self::ALPHABET[ random_int( 0, 31 ) ];
			}
			$plain[]  = substr( $c, 0, 5 ) . '-' . substr( $c, 5 );
			$hashes[] = wp_hash_password( $c );
		}
		update_user_meta( (int) $uid, self::META_BACKUP, $hashes );
		return $plain;
	}

	/**
	 * Backup codes left.
	 *
	 * @param int $uid User id.
	 * @return int
	 */
	public static function backup_left( $uid ) {
		$h = get_user_meta( (int) $uid, self::META_BACKUP, true );
		return is_array( $h ) ? count( $h ) : 0;
	}

	/**
	 * Remove 2FA from a user.
	 *
	 * @param int $uid User id.
	 */
	public static function clear( $uid ) {
		foreach ( array( self::META_SECRET, self::META_PENDING, self::META_BACKUP, self::META_LAST, self::META_SINCE ) as $m ) {
			delete_user_meta( (int) $uid, $m );
		}
		delete_transient( 'gsm_2fa_fail_' . (int) $uid );
	}

	/**
	 * Status for the security screen.
	 *
	 * @param WP_User $user User.
	 * @return array
	 */
	public static function status( $user ) {
		return array(
			'enabled'          => self::enabled( $user->ID ),
			'since'            => (string) get_user_meta( $user->ID, self::META_SINCE, true ),
			'backup_left'      => self::backup_left( $user->ID ),
			'available'        => self::available(),
			'require_managers' => self::required_for_managers(),
			'setup_required'   => self::setup_required( $user ),
			'is_manager'       => user_can( $user, 'gsm_manage_all' ),
			'bypassed'         => self::disabled(),
		);
	}
}
