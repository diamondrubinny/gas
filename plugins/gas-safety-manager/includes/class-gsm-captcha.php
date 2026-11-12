<?php
/**
 * reCAPTCHA v3 (Google) — optional bot protection for the password login
 * and the public fault report.
 *
 * Off by default. Design choices:
 * - Privacy: Google's script is NOT loaded with the page. It is loaded only
 *   when the visitor presses the protected button (sign in / send report),
 *   and a notice next to the button says so. Nothing is sent to Google for
 *   visitors who only look at a page.
 * - Anti-lockout: "monitor" mode records the result and never blocks.
 *   "Enforce" can be switched on only after a real verification succeeded
 *   with the saved keys. If Google cannot be reached the request is allowed
 *   (fail-open) — the login and the report keep their own rate limits.
 *   Emergency switch: define( 'GSM_CAPTCHA_DISABLE', true ) in wp-config.php.
 * - The secret key stays on the server (never returned, never logged). The
 *   visitor's IP address is not forwarded to Google by the server.
 * - Passkey sign-in is not gated (it is not guessable by a bot).
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Captcha
 */
class GSM_Captcha {

	const OPTION     = 'gsm_captcha';
	const VERIFY_URL = 'https://www.google.com/recaptcha/api/siteverify';

	/**
	 * Stored settings.
	 *
	 * @return array
	 */
	public static function config() {
		$c = get_option( self::OPTION, array() );
		$c = is_array( $c ) ? $c : array();
		$s = isset( $c['min_score'] ) ? (float) $c['min_score'] : 0.5;
		return array(
			'enabled'   => ! empty( $c['enabled'] ),
			'enforce'   => ! empty( $c['enforce'] ),
			'site_key'  => isset( $c['site_key'] ) ? (string) $c['site_key'] : '',
			'secret'    => isset( $c['secret'] ) ? (string) $c['secret'] : '',
			'min_score' => max( 0.1, min( 0.9, $s ) ),
			'login'     => ! isset( $c['login'] ) || ! empty( $c['login'] ),
			'bridge'    => ! isset( $c['bridge'] ) || ! empty( $c['bridge'] ),
			'tested_at' => isset( $c['tested_at'] ) ? (string) $c['tested_at'] : '',
		);
	}

	/**
	 * Settings for the admin screen (secret masked).
	 *
	 * @return array
	 */
	public static function public_config() {
		$c = self::config();
		return array(
			'enabled'    => $c['enabled'],
			'enforce'    => $c['enforce'],
			'site_key'   => $c['site_key'],
			'has_secret' => '' !== $c['secret'],
			'min_score'  => $c['min_score'],
			'login'      => $c['login'],
			'bridge'     => $c['bridge'],
			'tested_at'  => $c['tested_at'],
			'bypassed'   => self::disabled(),
		);
	}

	/**
	 * Emergency switch.
	 *
	 * @return bool
	 */
	public static function disabled() {
		return defined( 'GSM_CAPTCHA_DISABLE' ) && GSM_CAPTCHA_DISABLE;
	}

	/**
	 * Is the captcha active for a scope?
	 *
	 * @param string $scope login|bridge.
	 * @return bool
	 */
	public static function active( $scope ) {
		$c = self::config();
		return ! self::disabled() && $c['enabled'] && '' !== $c['site_key'] && '' !== $c['secret'] && ! empty( $c[ $scope ] );
	}

	/**
	 * Google keys are 40 URL-safe characters.
	 *
	 * @param string $k Key.
	 * @return string '' when malformed.
	 */
	private static function clean_key( $k ) {
		$k = trim( (string) $k );
		return preg_match( '/^[A-Za-z0-9_-]{30,80}$/', $k ) ? $k : '';
	}

	/**
	 * Save settings.
	 *
	 * @param array $in Input.
	 * @return array|WP_Error Public config.
	 */
	public static function save( $in ) {
		$c    = self::config();
		$keys = false;
		if ( isset( $in['site_key'] ) && trim( (string) $in['site_key'] ) !== $c['site_key'] ) {
			$k = self::clean_key( $in['site_key'] );
			if ( '' === $k && '' !== trim( (string) $in['site_key'] ) ) {
				return new WP_Error( 'gsm_captcha_key', __( 'מפתח האתר (Site Key) אינו בפורמט תקין.', 'gas-safety-manager' ), array( 'status' => 400 ) );
			}
			$c['site_key'] = $k;
			$keys          = true;
		}
		if ( isset( $in['secret'] ) && '' !== trim( (string) $in['secret'] ) ) {
			$k = self::clean_key( $in['secret'] );
			if ( '' === $k ) {
				return new WP_Error( 'gsm_captcha_key', __( 'המפתח הסודי (Secret Key) אינו בפורמט תקין.', 'gas-safety-manager' ), array( 'status' => 400 ) );
			}
			$c['secret'] = $k;
			$keys        = true;
		}
		if ( ! empty( $in['clear'] ) ) {
			$c['site_key'] = '';
			$c['secret']   = '';
			$c['enabled']  = false;
			$keys          = true;
		}
		if ( $keys ) {
			// New keys have not been proven to work: back to monitor mode.
			$c['tested_at'] = '';
			$c['enforce']   = false;
		}
		foreach ( array( 'enabled', 'login', 'bridge' ) as $b ) {
			if ( isset( $in[ $b ] ) ) {
				$c[ $b ] = (bool) $in[ $b ];
			}
		}
		if ( isset( $in['min_score'] ) ) {
			$c['min_score'] = max( 0.1, min( 0.9, round( (float) $in['min_score'], 1 ) ) );
		}
		if ( isset( $in['enforce'] ) ) {
			if ( $in['enforce'] && '' === $c['tested_at'] ) {
				return new WP_Error( 'gsm_captcha_untested', __( 'לפני הפעלת אכיפה יש להריץ "בדיקה" מוצלחת עם המפתחות השמורים, כדי לא לנעול את הכניסה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
			}
			$c['enforce'] = (bool) $in['enforce'];
		}
		if ( $c['enabled'] && ( '' === $c['site_key'] || '' === $c['secret'] ) ) {
			return new WP_Error( 'gsm_captcha_keys', __( 'כדי להפעיל נדרשים מפתח אתר ומפתח סודי.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		update_option( self::OPTION, $c, false );
		return self::public_config();
	}

	/**
	 * Ask Google about a token.
	 *
	 * @param string $token  Token from the browser.
	 * @param string $action Expected action.
	 * @return array { ok: bool|null (null = could not verify), score, reason }
	 */
	public static function verify( $token, $action ) {
		$c = self::config();
		if ( ! is_string( $token ) || '' === $token || strlen( $token ) > 4096 ) {
			return array( 'ok' => false, 'score' => null, 'reason' => 'missing-token' );
		}
		$res = GSM_Security::outbound_post(
			self::VERIFY_URL,
			array(
				'timeout' => 8,
				// No remoteip: the visitor's address is not forwarded.
				'body'    => array( 'secret' => $c['secret'], 'response' => $token ),
			)
		);
		if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
			return array( 'ok' => null, 'score' => null, 'reason' => 'unreachable' );
		}
		$d = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( ! is_array( $d ) ) {
			return array( 'ok' => null, 'score' => null, 'reason' => 'bad-answer' );
		}
		if ( empty( $d['success'] ) ) {
			$codes = isset( $d['error-codes'] ) && is_array( $d['error-codes'] ) ? implode( ',', array_map( 'sanitize_key', $d['error-codes'] ) ) : 'failed';
			return array( 'ok' => false, 'score' => null, 'reason' => $codes );
		}
		$score = isset( $d['score'] ) ? (float) $d['score'] : 0.0;
		$host  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( ! isset( $d['action'] ) || $action !== $d['action'] ) {
			return array( 'ok' => false, 'score' => $score, 'reason' => 'wrong-action' );
		}
		if ( ! isset( $d['hostname'] ) || strtolower( (string) $d['hostname'] ) !== $host ) {
			return array( 'ok' => false, 'score' => $score, 'reason' => 'wrong-hostname' );
		}
		if ( $score < $c['min_score'] ) {
			return array( 'ok' => false, 'score' => $score, 'reason' => 'low-score' );
		}
		return array( 'ok' => true, 'score' => $score, 'reason' => '' );
	}

	/**
	 * Gate a request.
	 *
	 * @param string $scope  login|bridge.
	 * @param string $token  Token from the browser.
	 * @param string $action Expected action.
	 * @return true|WP_Error True when the request may continue.
	 */
	public static function check( $scope, $token, $action ) {
		if ( ! self::active( $scope ) ) {
			return true;
		}
		$r = self::verify( $token, $action );
		if ( true === $r['ok'] ) {
			return true;
		}
		$c = self::config();
		// Could not reach Google, or monitor mode: let it through.
		if ( null === $r['ok'] || ! $c['enforce'] ) {
			return true;
		}
		if ( class_exists( 'GSM_Audit' ) && ! get_transient( 'gsm_captcha_logged' ) ) {
			// At most one audit entry per 10 minutes (a bot run must not flood the log).
			set_transient( 'gsm_captcha_logged', 1, 10 * MINUTE_IN_SECONDS );
			GSM_Audit::log( 'security', 'system', 0, 'reCAPTCHA', 'בקשה נחסמה על ידי reCAPTCHA (' . ( 'login' === $scope ? 'כניסה' : 'דיווח תקלה' ) . ', סיבה: ' . $r['reason'] . ')' );
		}
		return new WP_Error( 'gsm_captcha', __( 'הבקשה נחסמה על ידי בדיקת האבטחה (reCAPTCHA). רעננו את הדף ונסו שוב.', 'gas-safety-manager' ), array( 'status' => 403 ) );
	}

	/**
	 * Run a real verification with the saved keys and remember a success.
	 *
	 * @param string $token Token from the browser (action "test").
	 * @return array|WP_Error
	 */
	public static function test( $token ) {
		$c = self::config();
		if ( '' === $c['site_key'] || '' === $c['secret'] ) {
			return new WP_Error( 'gsm_captcha_keys', __( 'שמרו קודם מפתח אתר ומפתח סודי.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$r = self::verify( $token, 'test' );
		// A low score is still a working setup: keys, action and host all matched.
		$works = true === $r['ok'] || 'low-score' === $r['reason'];
		if ( $works ) {
			$raw              = get_option( self::OPTION, array() );
			$raw              = is_array( $raw ) ? $raw : array();
			$raw['tested_at'] = current_time( 'mysql' );
			update_option( self::OPTION, $raw, false );
		}
		return array( 'works' => $works, 'score' => $r['score'], 'reason' => $r['reason'], 'config' => self::public_config() );
	}

	// ------------------------------------------------------------------
	// Front end
	// ------------------------------------------------------------------

	/**
	 * Is the loader needed on this page?
	 *  - QR page: when the captcha protects the fault report.
	 *  - App page: when it protects the login, or for a signed-in manager
	 *    with saved keys (the settings screen runs a test verification).
	 *
	 * @return bool
	 */
	private static function needed_here() {
		if ( self::disabled() ) {
			return false;
		}
		$where = GSM_Chat::current_placement();
		if ( 'bridge' === $where ) {
			return self::active( 'bridge' );
		}
		if ( 'app' === $where ) {
			$c = self::config();
			return self::active( 'login' ) || ( '' !== $c['site_key'] && '' !== $c['secret'] && current_user_can( 'gsm_manage_all' ) );
		}
		return false;
	}

	/**
	 * Site key to hand to the page ('' when the form on it is not protected).
	 *
	 * @return string
	 */
	private static function page_key() {
		$where = GSM_Chat::current_placement();
		$scope = 'bridge' === $where ? 'bridge' : 'login';
		return self::active( $scope ) ? self::config()['site_key'] : '';
	}

	/**
	 * wp_enqueue_scripts: the small loader + public config (site key only).
	 */
	public static function enqueue() {
		if ( ! self::needed_here() ) {
			return;
		}
		wp_enqueue_script( 'gsm-captcha', GSM_PLUGIN_URL . 'public/assets/js/captcha.js', array(), GSM_VERSION, true );
		wp_add_inline_script( 'gsm-captcha', 'window.GSM_CAPTCHA = ' . wp_json_encode( array( 'siteKey' => self::page_key() ), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * CSP: allow Google's reCAPTCHA hosts only on pages that use it.
	 *
	 * @param array $d Directives.
	 * @return array
	 */
	public static function csp( $d ) {
		if ( ! self::needed_here() ) {
			return $d;
		}
		$add = array(
			'script-src'  => 'https://www.google.com/recaptcha/ https://www.gstatic.com/recaptcha/',
			'frame-src'   => 'https://www.google.com/recaptcha/ https://recaptcha.google.com/recaptcha/',
			'connect-src' => 'https://www.google.com/recaptcha/',
		);
		foreach ( $add as $k => $v ) {
			$d[ $k ] = trim( ( isset( $d[ $k ] ) ? $d[ $k ] : "'self'" ) . ' ' . $v );
		}
		return $d;
	}
}
