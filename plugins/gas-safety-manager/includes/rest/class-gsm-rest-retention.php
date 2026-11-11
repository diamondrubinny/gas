<?php
/**
 * REST controller: data-retention settings (manager only).
 *
 *   GET  /gas/v1/retention       config + defaults + preview + last run
 *   POST /gas/v1/retention       save periods (months, 0 = keep forever)
 *   POST /gas/v1/retention/run   run now (ignores the grace period)
 *   POST /gas/v1/retention/reset-scans   { confirm: "RESET" } delete all scan statistics
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Retention
 */
class GSM_REST_Retention {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/retention',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/retention/reset-scans',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_scans' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/retention/run',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'run' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
	}

	/**
	 * Payload for the settings screen.
	 *
	 * @return array
	 */
	private function payload() {
		$labels = array();
		foreach ( GSM_Retention::targets() as $k => $t ) {
			$labels[ $k ] = $t[2];
		}
		$from = GSM_Retention::active_from();
		return array(
			'config'      => GSM_Retention::config(),
			'defaults'    => GSM_Retention::defaults(),
			'labels'      => $labels,
			'preview'     => GSM_Retention::preview(),
			'last'        => get_option( GSM_Retention::LAST, null ),
			'active_from' => wp_date( 'd/m/Y H:i', $from ),
			'active'      => time() >= $from,
		);
	}

	/**
	 * GET /retention
	 *
	 * @return WP_REST_Response
	 */
	public function get() {
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * POST /retention
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function save( $req ) {
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : array();
		$cfg  = GSM_Retention::save( isset( $body['config'] ) && is_array( $body['config'] ) ? $body['config'] : $body );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'system', 0, '', 'עודכנה מדיניות שמירה: ' . wp_json_encode( $cfg ) );
		}
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * POST /retention/reset-scans — delete every scan record (dashboard
	 * coverage and charts start from zero). Equipment and QR issuance are
	 * not touched.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_scans( $req ) {
		global $wpdb;
		$b = (array) $req->get_json_params();
		// An explicit confirmation word: the action cannot be undone.
		if ( ! isset( $b['confirm'] ) || 'RESET' !== $b['confirm'] ) {
			return new WP_Error( 'gsm_confirm', __( 'נדרש אישור מפורש לאיפוס.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$t = $wpdb->prefix . 'gas_scans';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = (int) $wpdb->query( "DELETE FROM {$t}" );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'delete', 'system', 0, 'סטטיסטיקת סריקות', 'אופסה סטטיסטיקת הסריקות (' . $n . ' רשומות נמחקו)' );
		}
		return rest_ensure_response( array( 'deleted' => $n ) );
	}

	/**
	 * POST /retention/run
	 *
	 * @return WP_REST_Response
	 */
	public function run() {
		$res = GSM_Retention::run( 'manual' );
		$out = $this->payload();
		$out['result'] = $res;
		return rest_ensure_response( $out );
	}
}
