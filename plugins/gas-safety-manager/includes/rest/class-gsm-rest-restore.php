<?php
/**
 * REST controller: Restore points (manager only).
 *
 *   GET    /gas/v1/restore-points              — list
 *   POST   /gas/v1/restore-points   { label }  — create a snapshot
 *   DELETE /gas/v1/restore-points/{id}         — delete
 *   POST   /gas/v1/restore-points/{id}/restore — restore (upsert, safe)
 *   GET    /gas/v1/restore-points/{id}/download — download the JSON snapshot
 *
 * @package GasSafetyManager
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Restore
 */
class GSM_REST_Restore {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/restore-points',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_points' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_point' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/restore-points/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_point' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/restore-points/(?P<id>\d+)/restore',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'restore_point' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/restore-points/(?P<id>\d+)/download',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'download_point' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
	}

	/**
	 * GET /restore-points
	 *
	 * @return WP_REST_Response
	 */
	public function list_points() {
		return rest_ensure_response( GSM_Restore_Service::list() );
	}

	/**
	 * POST /restore-points
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function create_point( $req ) {
		$body  = $req->get_json_params();
		$label = ( is_array( $body ) && isset( $body['label'] ) ) ? sanitize_text_field( (string) $body['label'] ) : '';
		if ( '' === trim( $label ) ) {
			$label = 'נקודת שחזור ' . current_time( 'Y-m-d H:i' );
		}
		return rest_ensure_response( GSM_Restore_Service::snapshot( $label, 'manual' ) );
	}

	/**
	 * DELETE /restore-points/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function delete_point( $req ) {
		GSM_Restore_Service::delete( (int) $req['id'] );
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /restore-points/{id}/restore
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function restore_point( $req ) {
		$res = GSM_Restore_Service::restore( (int) $req['id'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( $res );
	}

	/**
	 * GET /restore-points/{id}/download
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function download_point( $req ) {
		$row = GSM_Restore_Service::get( (int) $req['id'] );
		if ( ! $row ) {
			return new WP_Error( 'gsm_rp_notfound', __( 'לא נמצא.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$filename = 'restore-point-' . (int) $req['id'] . '-' . current_time( 'Y-m-d' ) . '.json';
		return rest_ensure_response(
			array(
				'filename' => $filename,
				'mime'     => 'application/json',
				'b64'      => base64_encode( (string) $row['payload'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			)
		);
	}
}
