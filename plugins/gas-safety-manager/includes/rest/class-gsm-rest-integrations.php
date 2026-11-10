<?php
/**
 * REST controller: integrations (outgoing webhook config).
 *
 *   GET  /gas/v1/integrations   (manage) — current config (secret masked)
 *   POST /gas/v1/integrations   (manage) — save config
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Integrations
 */
class GSM_REST_Integrations {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/integrations',
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
	}

	/**
	 * GET /integrations
	 *
	 * @return WP_REST_Response
	 */
	public function get_config() {
		return rest_ensure_response( GSM_Integrations::public_config() );
	}

	/**
	 * POST /integrations
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function save_config( $req ) {
		$data = $req->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $req->get_params();
		}
		return rest_ensure_response( GSM_Integrations::save( (array) $data ) );
	}
}
