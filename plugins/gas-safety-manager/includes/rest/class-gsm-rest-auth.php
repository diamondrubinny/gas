<?php
/**
 * REST controller: Authentication.
 *
 * Login/logout/me for the standalone app. Authentication is delegated to
 * WordPress core (wp_authenticate / wp_signon) — we never roll our own.
 * Login is rate-limited to blunt brute-force attempts.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Auth
 */
class GSM_REST_Auth {

	const NS = 'gas/v1';

	/**
	 * Register auth routes.
	 */
	public function register_routes() {
		// Who am I (used on app boot to decide logged-in vs public).
		register_rest_route(
			self::NS,
			'/auth/me',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'me' ),
				'permission_callback' => '__return_true',
			)
		);

		// Login.
		register_rest_route(
			self::NS,
			'/auth/login',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'login' ),
				'permission_callback' => '__return_true',
			)
		);

		// Logout.
		register_rest_route(
			self::NS,
			'/auth/logout',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'logout' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Build the "me" payload for a user (or a logged-out shape).
	 *
	 * @param WP_User|null $user User or null.
	 * @return array
	 */
	private function me_payload( $user ) {
		if ( ! $user || ! $user->ID ) {
			return array(
				'logged_in' => false,
				'nonce'     => wp_create_nonce( 'wp_rest' ),
			);
		}
		return array(
			'logged_in'  => true,
			'id'         => (int) $user->ID,
			'name'       => $user->display_name,
			'email'      => $user->user_email,
			'nonce'      => wp_create_nonce( 'wp_rest' ),
			'caps'          => array(
				'manage' => user_can( $user, 'gsm_manage_all' ),
				'edit'   => user_can( $user, 'gsm_edit_equipment' ) || user_can( $user, 'gsm_manage_all' ),
				'report' => user_can( $user, 'gsm_report' ) || user_can( $user, 'gsm_edit_equipment' ) || user_can( $user, 'gsm_manage_all' ),
			),
			'role_label'    => $this->role_label( $user ),
			'role_key'      => GSM_Auth::role_key( $user ),
			'inspect_types' => GSM_Auth::inspect_types( $user ),
			'nav_hidden'    => $this->nav_hidden(),
			'nav_allowed'   => $this->nav_allowed( GSM_Auth::role_key( $user ) ),
			'twofa'         => GSM_2FA::enabled( $user->ID ),
			'must_2fa'      => GSM_2FA::setup_required( $user ),
		);
	}

	/**
	 * Globally-hidden nav items.
	 *
	 * @return array
	 */
	private function nav_hidden() {
		$h = get_option( 'gsm_portal_hidden', array() );
		return is_array( $h ) ? array_values( $h ) : array();
	}

	/**
	 * Allowed nav items for a role (null = use capability defaults).
	 *
	 * @param string $role_key Role key.
	 * @return array|null
	 */
	private function nav_allowed( $role_key ) {
		$perms = get_option( 'gsm_role_perms', array() );
		if ( is_array( $perms ) && isset( $perms[ $role_key ] ) && is_array( $perms[ $role_key ] ) ) {
			return array_values( $perms[ $role_key ] );
		}
		return null;
	}

	/**
	 * Human-readable role label (Hebrew).
	 *
	 * @param WP_User $user User.
	 * @return string
	 */
	private function role_label( $user ) {
		if ( user_can( $user, 'gsm_manage_all' ) ) {
			return 'מנהל';
		}
		if ( user_can( $user, 'gsm_inspect_quarterly' ) && ! user_can( $user, 'gsm_inspect_monthly' ) ) {
			return 'אחמ"ת';
		}
		if ( user_can( $user, 'gsm_edit_equipment' ) ) {
			return 'מנהל מעבדה';
		}
		if ( user_can( $user, 'gsm_report' ) ) {
			return 'חוקר ראשי';
		}
		return 'משתמש';
	}

	/**
	 * GET /auth/me
	 *
	 * @return WP_REST_Response
	 */
	public function me() {
		$user = wp_get_current_user();
		// A user deactivated mid-session is treated as logged out by the app.
		if ( $user && $user->ID && ! GSM_Auth::is_active( $user ) ) {
			$user = null;
		}
		return rest_ensure_response( $this->me_payload( $user ) );
	}

	/**
	 * POST /auth/login  { user, password, remember }
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function login( $req ) {
		$body     = $req->get_json_params();
		$login    = isset( $body['user'] ) ? sanitize_text_field( wp_unslash( $body['user'] ) ) : '';
		$password = isset( $body['password'] ) ? (string) $body['password'] : '';
		$remember = ! empty( $body['remember'] );
		// Second step (TOTP or backup code), checked in the authenticate filter.
		GSM_2FA::$code = isset( $body['code'] ) && is_scalar( $body['code'] ) ? substr( sanitize_text_field( (string) $body['code'] ), 0, 20 ) : '';

		if ( '' === $login || '' === $password ) {
			return new WP_Error( 'gsm_login_missing', __( 'יש להזין שם משתמש וסיסמה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}

		// Rate limit: max 5 failed attempts per IP per 10 minutes.
		$ip_key = 'gsm_login_' . md5( GSM_Auth::client_ip() );
		$fails  = (int) get_transient( $ip_key );
		if ( $fails >= 5 ) {
			return new WP_Error( 'gsm_login_locked', __( 'יותר מדי ניסיונות. נסה שוב בעוד מספר דקות.', 'gas-safety-manager' ), array( 'status' => 429 ) );
		}

		// Make a nonce generated later in THIS request valid for the NEW
		// session. wp_signon() -> wp_set_auth_cookie() fires
		// 'set_logged_in_cookie' with the new cookie, but $_COOKIE is only
		// updated on the NEXT request; without this, wp_get_session_token()
		// (used by wp_create_nonce) uses the wrong/empty token, so the client's
		// first REST call right after login fails the cookie/nonce check
		// ("Cookie check failed"). Populating $_COOKIE now fixes that.
		add_action(
			'set_logged_in_cookie',
			static function ( $logged_in_cookie ) {
				$_COOKIE[ LOGGED_IN_COOKIE ] = $logged_in_cookie;
			}
		);

		$user = wp_signon(
			array(
				'user_login'    => $login,
				'user_password' => $password,
				'remember'      => $remember,
			),
			is_ssl()
		);

		if ( is_wp_error( $user ) && 'gsm_inactive' === $user->get_error_code() ) {
			// Correct password, deactivated account (the check runs after the
			// password check, so this is not an enumeration oracle).
			return new WP_Error( 'gsm_login_blocked', __( 'החשבון שלך הושבת. פנה למנהל.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}
		GSM_2FA::$code = '';
		if ( is_wp_error( $user ) && 'gsm_2fa_required' === $user->get_error_code() ) {
			// Password was correct; the client now asks for the code and sends
			// everything again. Not a failure, and the counter is not cleared.
			return rest_ensure_response( array( 'logged_in' => false, 'requires_2fa' => true, 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
		}
		if ( is_wp_error( $user ) && 'gsm_2fa_locked' === $user->get_error_code() ) {
			return new WP_Error( 'gsm_2fa_locked', $user->get_error_message(), array( 'status' => 429 ) );
		}
		if ( is_wp_error( $user ) && 'gsm_2fa_invalid' === $user->get_error_code() ) {
			set_transient( $ip_key, $fails + 1, 10 * MINUTE_IN_SECONDS );
			return new WP_Error( 'gsm_2fa_invalid', $user->get_error_message(), array( 'status' => 401 ) );
		}
		if ( is_wp_error( $user ) ) {
			set_transient( $ip_key, $fails + 1, 10 * MINUTE_IN_SECONDS );
			// Generic message — don't reveal whether the username exists.
			return new WP_Error( 'gsm_login_failed', __( 'שם משתמש או סיסמה שגויים.', 'gas-safety-manager' ), array( 'status' => 401 ) );
		}

		wp_set_current_user( $user->ID );

		// Must have at least the report capability to use the app. Not an app
		// user: sign out again (no lingering cookie) and keep counting, so an
		// ordinary site account cannot be used to reset the lockout counter.
		if ( ! ( user_can( $user, 'gsm_report' ) || user_can( $user, 'gsm_edit_equipment' ) || user_can( $user, 'gsm_manage_all' ) ) ) {
			wp_logout();
			set_transient( $ip_key, $fails + 1, 10 * MINUTE_IN_SECONDS );
			return new WP_Error( 'gsm_login_norole', __( 'אין לך הרשאה לגשת למערכת. פנה למנהל.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}

		// Blocked by a manager? (Also enforced in the authenticate filter.)
		if ( ! GSM_Auth::is_active( $user ) ) {
			wp_logout();
			return new WP_Error( 'gsm_login_blocked', __( 'החשבון שלך הושבת. פנה למנהל.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}

		// Fully successful app login — only now clear the failure counter.
		delete_transient( $ip_key );
		update_user_meta( $user->ID, 'gsm_last_login', current_time( 'mysql' ) );

		return rest_ensure_response( $this->me_payload( $user ) );
	}

	/**
	 * POST /auth/logout
	 *
	 * @return WP_REST_Response
	 */
	public function logout() {
		wp_logout();
		return rest_ensure_response( array( 'logged_in' => false, 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}
}
