<?php
/**
 * REST controller: chat widget settings (manager only).
 *
 *   GET  /gas/v1/chat   settings (key shown masked)
 *   POST /gas/v1/chat   save: { snippet?, enabled?, label?, where{bridge,app,site}?, clear? }
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Chat
 */
class GSM_REST_Chat {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/chat',
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
	}

	/**
	 * Settings for the admin screen. The key is a public site key (it is in
	 * every page that shows the chat), but it is still masked here.
	 *
	 * @return array
	 */
	private function payload() {
		$c   = GSM_Chat::config();
		$key = $c['key'];
		return array(
			'enabled'    => $c['enabled'],
			'origin'     => $c['origin'],
			'key_masked' => '' !== $key ? substr( $key, 0, 6 ) . '…' . substr( $key, -4 ) : '',
			'configured' => '' !== $c['origin'] && '' !== $key,
			'label'      => $c['label'],
			'where'      => $c['where'],
			'placements' => GSM_Chat::placements(),
		);
	}

	/**
	 * GET /chat
	 *
	 * @return WP_REST_Response
	 */
	public function get() {
		return rest_ensure_response( $this->payload() );
	}

	/**
	 * POST /chat
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( $req ) {
		$body = $req->get_json_params();
		$res  = GSM_Chat::save( is_array( $body ) ? $body : array() );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'system', 0, '', 'עודכנו הגדרות הצ׳אט: ' . ( $res['enabled'] ? 'פעיל' : 'כבוי' ) . ( '' !== $res['origin'] ? ' (' . $res['origin'] . ')' : '' ) );
		}
		return rest_ensure_response( $this->payload() );
	}
}
