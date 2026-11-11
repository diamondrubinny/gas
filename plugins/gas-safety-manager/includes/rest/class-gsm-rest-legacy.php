<?php
/**
 * REST controller: pull data from the old system (manager only).
 *
 *   GET  /gas/v1/legacy         settings (token masked) + last run
 *   POST /gas/v1/legacy         save { base?, token?, clear_token?, auto? }
 *   POST /gas/v1/legacy/check   dry run { sets? } — fetch + recognize, no writes
 *   POST /gas/v1/legacy/pull    pull    { sets? } — restore point, then upsert
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Legacy
 */
class GSM_REST_Legacy {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$perm = array( 'GSM_Auth', 'require_manage' );
		register_rest_route(
			self::NS,
			'/legacy',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $perm,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/legacy/check',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'check' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/legacy/pull',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'pull' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * GET /legacy
	 *
	 * @return WP_REST_Response
	 */
	public function get() {
		return rest_ensure_response( GSM_Legacy::public_config() );
	}

	/**
	 * POST /legacy
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( $req ) {
		$b  = (array) $req->get_json_params();
		$in = array();
		foreach ( array( 'base', 'token' ) as $k ) {
			if ( isset( $b[ $k ] ) && is_string( $b[ $k ] ) ) {
				$in[ $k ] = $b[ $k ];
			}
		}
		if ( isset( $b['auto'] ) ) {
			$in['auto'] = (bool) $b['auto'];
		}
		if ( ! empty( $b['clear_token'] ) ) {
			$in['clear_token'] = true;
		}
		$out = GSM_Legacy::save( $in );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'settings', 0, 'משיכה מהאתר הישן', 'עודכנו הגדרות המשיכה' . ( $out['auto'] ? ' (סנכרון יומי פעיל)' : '' ) );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Sets from the request body.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	private function sets( $req ) {
		$b = (array) $req->get_json_params();
		if ( isset( $b['sets'] ) && is_array( $b['sets'] ) ) {
			return array_values( array_intersect( GSM_Legacy::SETS, array_map( 'strval', $b['sets'] ) ) );
		}
		return GSM_Legacy::SETS;
	}

	/**
	 * POST /legacy/check
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function check( $req ) {
		$out = GSM_Legacy::run( false, $this->sets( $req ), 'manual' );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}

	/**
	 * POST /legacy/pull
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function pull( $req ) {
		$out = GSM_Legacy::run( true, $this->sets( $req ), 'manual' );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}
}
