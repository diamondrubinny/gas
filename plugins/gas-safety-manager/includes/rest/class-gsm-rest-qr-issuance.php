<?php
/**
 * REST controller: QR issuance manager.
 *
 *   GET    /gas/v1/qr-issuance              (issuance) — list {items,counts}
 *   POST   /gas/v1/qr-issuance              (issuance) — create
 *   PUT    /gas/v1/qr-issuance/{id}         (issuance) — update
 *   POST   /gas/v1/qr-issuance/{id}/status  (issuance) — move status
 *   DELETE /gas/v1/qr-issuance/{id}         (issuance) — delete (archived only)
 *   POST   /gas/v1/qr-issuance/bulk         (issuance) — create from equipment_ids
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_QR_Issuance
 */
class GSM_REST_QR_Issuance {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$perm = array( 'GSM_Auth', 'require_issuance' );

		register_rest_route(
			self::NS,
			'/qr-issuance',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/qr-issuance/bulk',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'bulk' ),
				'permission_callback' => $perm,
			)
		);

		register_rest_route(
			self::NS,
			'/qr-issuance/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete' ),
					'permission_callback' => $perm,
				),
			)
		);

		register_rest_route(
			self::NS,
			'/qr-issuance/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_status' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * Request body as array.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array
	 */
	private function body( $req ) {
		$data = $req->get_json_params();
		if ( ! is_array( $data ) ) {
			$data = $req->get_params();
		}
		return (array) $data;
	}

	/**
	 * GET /qr-issuance
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function list_items( $req ) {
		$args = array(
			'status' => (string) $req->get_param( 'status' ),
			'search' => (string) $req->get_param( 'search' ),
			'limit'  => $req->get_param( 'limit' ) ? (int) $req->get_param( 'limit' ) : 1000,
			'offset' => (int) $req->get_param( 'offset' ),
		);
		return rest_ensure_response( GSM_QR_Issuance_Service::list( $args ) );
	}

	/**
	 * POST /qr-issuance
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( $req ) {
		$id = GSM_QR_Issuance_Service::create( $this->body( $req ) );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'id' => (int) $id ) );
	}

	/**
	 * PUT /qr-issuance/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update( $req ) {
		$res = GSM_QR_Issuance_Service::update( (int) $req['id'], $this->body( $req ) );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( array( 'updated' => true ) );
	}

	/**
	 * POST /qr-issuance/{id}/status
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_status( $req ) {
		$body = $this->body( $req );
		$res  = GSM_QR_Issuance_Service::set_status( (int) $req['id'], isset( $body['status'] ) ? $body['status'] : '' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( array( 'updated' => true ) );
	}

	/**
	 * DELETE /qr-issuance/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( $req ) {
		$res = GSM_QR_Issuance_Service::delete( (int) $req['id'] );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * POST /qr-issuance/bulk
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function bulk( $req ) {
		$body = $this->body( $req );
		$ids  = isset( $body['equipment_ids'] ) && is_array( $body['equipment_ids'] ) ? $body['equipment_ids'] : array();
		if ( count( $ids ) > 5000 ) {
			return new WP_Error( 'gsm_qri_too_many', __( 'יותר מדי פריטים בבקשה אחת (מקסימום 5000).', 'gas-safety-manager' ), array( 'status' => 413 ) );
		}
		return rest_ensure_response( GSM_QR_Issuance_Service::bulk_from_equipment( $ids ) );
	}
}
