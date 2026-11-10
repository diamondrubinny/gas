<?php
/**
 * REST controllers: Faults + Inspections.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Faults
 */
class GSM_REST_Faults {

	const NS = 'gas/v1';

	/**
	 * Register fault routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/faults',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
					'args'                => array(
						'equipment_id' => array( 'sanitize_callback' => 'absint' ),
						'status'       => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'priority'     => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'page'         => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page'     => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ), // PI can report faults
				),
			)
		);

		register_rest_route(
			self::NS,
			'/faults/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_edit' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
	}

	/**
	 * GET /faults
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function list_items( $req ) {
		$page     = max( 1, (int) $req->get_param( 'page' ) );
		$per_page = min( 200, max( 1, (int) $req->get_param( 'per_page' ) ) );
		$result   = GSM_Faults_Service::list(
			array(
				'equipment_id' => (int) $req->get_param( 'equipment_id' ),
				'status'       => (string) $req->get_param( 'status' ),
				'priority'     => (string) $req->get_param( 'priority' ),
				'limit'        => $per_page,
				'offset'       => ( $page - 1 ) * $per_page,
			)
		);
		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		return $response;
	}

	/**
	 * GET /faults/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $req ) {
		$item = GSM_Faults_Service::get( (int) $req['id'] );
		return is_wp_error( $item ) ? $item : rest_ensure_response( $item );
	}

	/**
	 * POST /faults
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $req ) {
		$input = $req->get_json_params();
		if ( empty( $input ) ) {
			$input = $req->get_params();
		}
		$id = GSM_Faults_Service::create( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$response = rest_ensure_response( GSM_Faults_Service::get( $id ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * PUT /faults/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $req ) {
		$input = $req->get_json_params();
		if ( empty( $input ) ) {
			$input = $req->get_params();
		}
		$ok = GSM_Faults_Service::update( (int) $req['id'], $input );
		return is_wp_error( $ok ) ? $ok : rest_ensure_response( GSM_Faults_Service::get( (int) $req['id'] ) );
	}

	/**
	 * DELETE /faults/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $req ) {
		$ok = GSM_Faults_Service::delete( (int) $req['id'] );
		return is_wp_error( $ok ) ? $ok : rest_ensure_response( array( 'deleted' => true, 'id' => (int) $req['id'] ) );
	}
}

/**
 * Class GSM_REST_Inspections
 */
class GSM_REST_Inspections {

	const NS = 'gas/v1';

	/**
	 * Register inspection routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/inspections',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
					'args'                => array(
						'equipment_id' => array( 'sanitize_callback' => 'absint' ),
						'type'         => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'page'         => array( 'default' => 1, 'sanitize_callback' => 'absint' ),
						'per_page'     => array( 'default' => 50, 'sanitize_callback' => 'absint' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ), // PI can submit inspections
				),
			)
		);

		register_rest_route(
			self::NS,
			'/inspections/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_edit' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
	}

	/**
	 * GET /inspections
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function list_items( $req ) {
		$page     = max( 1, (int) $req->get_param( 'page' ) );
		$per_page = min( 200, max( 1, (int) $req->get_param( 'per_page' ) ) );
		$result   = GSM_Inspections_Service::list(
			array(
				'equipment_id' => (int) $req->get_param( 'equipment_id' ),
				'type'         => (string) $req->get_param( 'type' ),
				'limit'        => $per_page,
				'offset'       => ( $page - 1 ) * $per_page,
			)
		);
		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		return $response;
	}

	/**
	 * GET /inspections/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $req ) {
		$item = GSM_Inspections_Service::get( (int) $req['id'] );
		return is_wp_error( $item ) ? $item : rest_ensure_response( $item );
	}

	/**
	 * POST /inspections
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $req ) {
		$input = $req->get_json_params();
		if ( empty( $input ) ) {
			$input = $req->get_params();
		}

		// Enforce the role ↔ inspection-type rule: אחמ"ת files quarterly, a lab
		// manager / PI files monthly, a manager may file either. (adhoc is
		// allowed for anyone who can inspect at all.)
		$type = isset( $input['type'] ) && '' !== $input['type'] ? (string) $input['type'] : 'adhoc';
		if ( ! GSM_Auth::can_inspect_type( $type ) ) {
			return new WP_Error(
				'gsm_inspect_role',
				__( 'אין לך הרשאה לבצע ביקורת מסוג זה. אחמ"ת מבצע רבעונית; מנהל מעבדה/PI מבצע חודשית.', 'gas-safety-manager' ),
				array( 'status' => 403 )
			);
		}

		$id = GSM_Inspections_Service::create( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$response = rest_ensure_response( GSM_Inspections_Service::get( $id ) );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * PUT /inspections/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $req ) {
		$input = $req->get_json_params();
		if ( empty( $input ) ) {
			$input = $req->get_params();
		}
		$ok = GSM_Inspections_Service::update( (int) $req['id'], $input );
		return is_wp_error( $ok ) ? $ok : rest_ensure_response( GSM_Inspections_Service::get( (int) $req['id'] ) );
	}

	/**
	 * DELETE /inspections/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $req ) {
		$ok = GSM_Inspections_Service::delete( (int) $req['id'] );
		return is_wp_error( $ok ) ? $ok : rest_ensure_response( array( 'deleted' => true, 'id' => (int) $req['id'] ) );
	}
}
