<?php
/**
 * REST: communication settings, broadcast, log (manager only).
 *
 *   GET  /gas/v1/comm                — public config (secrets masked) + recent log
 *   POST /gas/v1/comm                — save config
 *   POST /gas/v1/comm/broadcast      — send a message to an audience
 *   POST /gas/v1/comm/test           — send a test email to the current manager
 *
 * @package GasSafetyManager
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Comm
 */
class GSM_REST_Comm {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/comm',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_config' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/comm/broadcast',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'broadcast' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/comm/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
	}

	/**
	 * GET /comm
	 *
	 * @return WP_REST_Response
	 */
	public function get_config() {
		return rest_ensure_response(
			array(
				'config' => GSM_Comm::public_config(),
				'log'    => GSM_Comm::recent_log( 50 ),
			)
		);
	}

	/**
	 * POST /comm
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function save_config( $req ) {
		$body = $req->get_json_params();
		return rest_ensure_response( array( 'config' => GSM_Comm::save( is_array( $body ) ? $body : array() ) ) );
	}

	/**
	 * POST /comm/broadcast
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function broadcast( $req ) {
		if ( GSM_Rate_Limit::too_many( 'comm-broadcast', 10, 600 ) ) {
			return GSM_Rate_Limit::error();
		}
		$body     = $req->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$audience = isset( $body['audience'] ) ? sanitize_key( (string) $body['audience'] ) : 'all';
		$subject  = isset( $body['subject'] ) ? sanitize_text_field( (string) $body['subject'] ) : '';
		$message  = isset( $body['message'] ) ? sanitize_textarea_field( (string) $body['message'] ) : '';
		if ( '' === $subject || '' === $message ) {
			return new WP_Error( 'gsm_comm_empty', __( 'יש למלא נושא ותוכן.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$res = GSM_Comm::broadcast( $audience, $subject, $message );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'system', 0, '', 'שידור הודעה (' . $audience . '): ' . $subject );
		}
		return rest_ensure_response( $res );
	}

	/**
	 * POST /comm/test — email the current manager to verify delivery.
	 *
	 * @return WP_REST_Response
	 */
	public function test() {
		$u  = wp_get_current_user();
		$to = ( $u && $u->user_email ) ? $u->user_email : get_bloginfo( 'admin_email' );
		$ok = GSM_Comm::send_email( $to, '[בטיחות גזים] בדיקת דוא״ל', "הודעת בדיקה מהמערכת.\nאם קיבלת מייל זה — שליחת הדוא״ל פעילה." );
		GSM_Comm::log( 'alert', 'email', $to, 'בדיקת דוא״ל', $ok ? 'sent' : 'failed' );
		return rest_ensure_response( array( 'sent' => $ok, 'to' => $to ) );
	}
}
