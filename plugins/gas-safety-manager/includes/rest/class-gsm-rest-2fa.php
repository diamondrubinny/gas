<?php
/**
 * REST controller: two-factor authentication.
 *
 *   GET  /gas/v1/2fa                 (logged-in app user) status
 *   POST /gas/v1/2fa/setup           start enrollment -> { secret, uri }
 *   POST /gas/v1/2fa/enable          { code } -> { backup_codes }
 *   POST /gas/v1/2fa/backup-codes    { code } -> new { backup_codes }
 *   POST /gas/v1/2fa/disable         { password, code }
 *   POST /gas/v1/2fa/policy          (manager) { require_managers }
 *   POST /gas/v1/users/{id}/2fa-reset (manager) remove another user's 2FA
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_2FA
 */
class GSM_REST_2FA {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$self = array( 'GSM_Auth', 'require_report' );
		$mine = array(
			'/2fa'              => array( WP_REST_Server::READABLE, 'status' ),
			'/2fa/setup'        => array( WP_REST_Server::CREATABLE, 'setup' ),
			'/2fa/enable'       => array( WP_REST_Server::CREATABLE, 'enable' ),
			'/2fa/backup-codes' => array( WP_REST_Server::CREATABLE, 'backup_codes' ),
			'/2fa/disable'      => array( WP_REST_Server::CREATABLE, 'disable' ),
		);
		foreach ( $mine as $route => $def ) {
			register_rest_route(
				self::NS,
				$route,
				array(
					'methods'             => $def[0],
					'callback'            => array( $this, $def[1] ),
					'permission_callback' => $self,
				)
			);
		}
		register_rest_route(
			self::NS,
			'/2fa/policy',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'policy' ),
				// Capability only: a manager who still has to enroll must not be
				// able to switch the requirement off instead.
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)/2fa-reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_user' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
	}

	/**
	 * GET /2fa
	 *
	 * @return WP_REST_Response
	 */
	public function status() {
		return rest_ensure_response( GSM_2FA::status( wp_get_current_user() ) );
	}

	/**
	 * POST /2fa/setup
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function setup() {
		$out = GSM_2FA::begin( wp_get_current_user() );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * POST /2fa/enable
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function enable( $req ) {
		$b   = (array) $req->get_json_params();
		$out = GSM_2FA::confirm( wp_get_current_user(), isset( $b['code'] ) ? (string) $b['code'] : '' );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * A current code is required for sensitive changes.
	 *
	 * @param array $b Body.
	 * @return true|WP_Error
	 */
	private function need_code( $b ) {
		$uid = get_current_user_id();
		if ( ! GSM_2FA::enabled( $uid ) ) {
			return new WP_Error( 'gsm_2fa_off', __( 'אימות דו-שלבי אינו פעיל.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( GSM_2FA::locked( $uid ) ) {
			return new WP_Error( 'gsm_2fa_locked', __( 'יותר מדי קודים שגויים. נסו שוב בעוד 15 דקות.', 'gas-safety-manager' ), array( 'status' => 429 ) );
		}
		if ( ! GSM_2FA::verify( $uid, isset( $b['code'] ) ? (string) $b['code'] : '' ) ) {
			return new WP_Error( 'gsm_2fa_invalid', __( 'קוד האימות שגוי או שכבר נעשה בו שימוש.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * POST /2fa/backup-codes
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function backup_codes( $req ) {
		$ok = $this->need_code( (array) $req->get_json_params() );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$user = wp_get_current_user();
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'security', 'user', (int) $user->ID, $user->display_name, 'הופקו קודי גיבוי חדשים לאימות דו-שלבי' );
		}
		return rest_ensure_response( array( 'backup_codes' => GSM_2FA::new_backup_codes( $user->ID ) ) );
	}

	/**
	 * POST /2fa/disable
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function disable( $req ) {
		$b    = (array) $req->get_json_params();
		$user = wp_get_current_user();
		if ( GSM_2FA::required_for_managers() && user_can( $user, 'gsm_manage_all' ) ) {
			return new WP_Error( 'gsm_2fa_mandatory', __( 'אימות דו-שלבי הוא חובה למנהלים ולא ניתן לבטל אותו.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}
		if ( ! isset( $b['password'] ) || ! wp_check_password( (string) $b['password'], $user->user_pass, $user->ID ) ) {
			return new WP_Error( 'gsm_2fa_password', __( 'הסיסמה שגויה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$ok = $this->need_code( $b );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		GSM_2FA::clear( $user->ID );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'security', 'user', (int) $user->ID, $user->display_name, 'בוטל אימות דו-שלבי' );
		}
		return rest_ensure_response( GSM_2FA::status( $user ) );
	}

	/**
	 * POST /2fa/policy
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function policy( $req ) {
		$b    = (array) $req->get_json_params();
		$on   = ! empty( $b['require_managers'] );
		$user = wp_get_current_user();
		// Anti-lockout: whoever makes it mandatory must already use it.
		if ( $on && ! GSM_2FA::enabled( $user->ID ) ) {
			return new WP_Error( 'gsm_2fa_first', __( 'הפעילו קודם אימות דו-שלבי בחשבון שלכם, ורק אז הפכו אותו לחובה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		update_option( GSM_2FA::POLICY, array( 'require_managers' => $on ), false );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'security', 'settings', 0, 'אימות דו-שלבי', $on ? 'אימות דו-שלבי הוגדר כחובה למנהלים' : 'בוטלה חובת אימות דו-שלבי למנהלים' );
		}
		return rest_ensure_response( GSM_2FA::status( $user ) );
	}

	/**
	 * POST /users/{id}/2fa-reset
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_user( $req ) {
		$id   = (int) $req['id'];
		$user = get_userdata( $id );
		if ( ! $user || ! GSM_REST_Users::is_app_user( $user ) ) {
			return new WP_Error( 'gsm_not_found', __( 'משתמש לא נמצא.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		if ( get_current_user_id() === $id ) {
			return new WP_Error( 'gsm_2fa_self', __( 'את האימות הדו-שלבי שלכם מבטלים במסך "אבטחה".', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		GSM_2FA::clear( $id );
		// The lost device may still hold a session.
		if ( class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $id )->destroy_all();
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'security', 'user', $id, $user->display_name, 'מנהל איפס את האימות הדו-שלבי של המשתמש' );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
