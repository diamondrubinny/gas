<?php
/**
 * REST controller: single sign-on (Microsoft Entra ID).
 *
 *   GET  /gas/v1/sso            (manager) settings (secret never returned)
 *   POST /gas/v1/sso            (manager) save
 *   POST /gas/v1/sso/test       (manager) check that the tenant exists
 *   GET  /gas/v1/sso/start      (public)  redirect the browser to Microsoft
 *   GET  /gas/v1/sso/callback   (public)  Microsoft sends the browser back here
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_SSO
 */
class GSM_REST_SSO {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$mgr = array( 'GSM_Auth', 'require_manage' );
		register_rest_route(
			self::NS,
			'/sso',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => $mgr,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $mgr,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/sso/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $mgr,
			)
		);
		register_rest_route(
			self::NS,
			'/sso/start',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'start' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/sso/callback',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'callback' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /sso
	 *
	 * @return WP_REST_Response
	 */
	public function get() {
		return rest_ensure_response( GSM_SSO::public_config() );
	}

	/**
	 * POST /sso
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( $req ) {
		$b  = (array) $req->get_json_params();
		$in = array();
		foreach ( array( 'tenant', 'client_id', 'secret', 'default_role' ) as $k ) {
			if ( isset( $b[ $k ] ) && is_string( $b[ $k ] ) ) {
				$in[ $k ] = $b[ $k ];
			}
		}
		if ( isset( $b['domains'] ) && ( is_string( $b['domains'] ) || is_array( $b['domains'] ) ) ) {
			$in['domains'] = $b['domains'];
		}
		foreach ( array( 'enabled', 'auto_create', 'clear' ) as $k ) {
			if ( isset( $b[ $k ] ) ) {
				$in[ $k ] = (bool) $b[ $k ];
			}
		}
		$out = GSM_SSO::save( $in );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'security', 'settings', 0, 'כניסה ארגונית (SSO)', 'עודכנו הגדרות ה-SSO: ' . ( $out['enabled'] ? 'פעיל' : 'כבוי' ) . ( $out['auto_create'] ? ', יצירת חשבונות אוטומטית' : '' ) );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /sso/test
	 *
	 * @return WP_REST_Response
	 */
	public function test() {
		return rest_ensure_response( GSM_SSO::test() );
	}

	/**
	 * Where the browser lands after the round trip.
	 *
	 * @param string $error Reason slug, or '' on success.
	 * @return string
	 */
	private function landing( $error = '' ) {
		$id  = (int) get_option( 'gsm_app_page_id', 0 );
		$url = $id ? get_permalink( $id ) : home_url( '/' );
		if ( '' !== $error ) {
			$url = add_query_arg( 'sso_error', rawurlencode( $error ), $url );
		}
		return $url . ( '' !== $error ? '#/login' : '#/dashboard' );
	}

	/**
	 * Set / clear the browser-binding cookie.
	 *
	 * @param string $value Value ('' clears).
	 */
	private function cookie( $value ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			GSM_SSO::COOKIE,
			$value,
			array(
				'expires'  => '' === $value ? time() - 3600 : time() + GSM_SSO::STATE_TTL,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => true,
				// Lax: sent on the top-level redirect back from Microsoft.
				'samesite' => 'Lax',
			)
		);
	}

	/**
	 * Redirect and stop.
	 *
	 * @param string $url URL.
	 */
	private function go( $url ) {
		nocache_headers();
		header( 'Referrer-Policy: no-referrer' );
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- fixed Microsoft host built from the validated tenant id, or the site's own page.
		wp_redirect( $url, 302, 'GasSafetyManager' );
		exit;
	}

	/**
	 * GET /sso/start
	 *
	 * @return WP_Error|void
	 */
	public function start() {
		if ( GSM_Rate_Limit::too_many( 'sso-start', 30, 300 ) ) {
			return GSM_Rate_Limit::error();
		}
		$b = GSM_SSO::begin();
		if ( is_wp_error( $b ) ) {
			$this->go( $this->landing( 'off' ) );
		}
		$this->cookie( $b['cookie'] );
		$this->go( $b['url'] );
	}

	/**
	 * GET /sso/callback?code=&state=
	 *
	 * @param WP_REST_Request $req Request.
	 * @return void
	 */
	public function callback( $req ) {
		$cookie = isset( $_COOKIE[ GSM_SSO::COOKIE ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ GSM_SSO::COOKIE ] ) ) : '';
		$this->cookie( '' );
		// Same failure counter as the other sign-in methods.
		$ip_key = 'gsm_login_' . md5( GSM_Auth::client_ip() );
		$fails  = (int) get_transient( $ip_key );
		if ( $fails >= 5 ) {
			$this->go( $this->landing( 'failed' ) );
		}
		// The user cancelled at Microsoft, or Microsoft reported an error.
		if ( null !== $req->get_param( 'error' ) || null === $req->get_param( 'code' ) ) {
			$this->go( $this->landing( 'failed' ) );
		}
		$user = GSM_SSO::complete( (string) $req->get_param( 'code' ), (string) $req->get_param( 'state' ), $cookie );
		if ( is_wp_error( $user ) ) {
			set_transient( $ip_key, $fails + 1, 10 * MINUTE_IN_SECONDS );
			$this->go( $this->landing( substr( $user->get_error_code(), 8 ) ) );
		}
		// Mark the session as opened through SSO.
		add_filter(
			'attach_session_information',
			static function ( $info ) {
				$info['gsm_sso'] = 1;
				return $info;
			}
		);
		wp_set_auth_cookie( $user->ID, false, is_ssl() );
		wp_set_current_user( $user->ID );
		/** This action is documented in wp-includes/user.php */
		do_action( 'wp_login', $user->user_login, $user ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook, fired like wp_signon() does.
		delete_transient( $ip_key );
		update_user_meta( $user->ID, 'gsm_last_login', current_time( 'mysql' ) );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'security', 'user', (int) $user->ID, $user->display_name, 'כניסה ארגונית (SSO)' );
		}
		$this->go( $this->landing() );
	}
}
