<?php
/**
 * REST controller: data-retention settings (manager only).
 *
 *   GET  /gas/v1/retention       config + defaults + preview + last run
 *   POST /gas/v1/retention       save periods (months, 0 = keep forever)
 *   POST /gas/v1/retention/run   run now (ignores the grace period)
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
