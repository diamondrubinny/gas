<?php
/**
 * REST: secure attachments for inspections / faults / equipment.
 *
 *   POST   /gas/v1/attachments            (multipart: file, entity, entity_id) — report+
 *   GET    /gas/v1/attachments/list/{entity}/{id}                              — report+
 *   GET    /gas/v1/attachments/{id}       (streams the file)                   — report+
 *   DELETE /gas/v1/attachments/{id}                                            — manager OR uploader
 *
 * @package GasSafetyManager
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Attachments
 */
class GSM_REST_Attachments {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/attachments',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'upload' ),
				'permission_callback' => array( 'GSM_Auth', 'require_report' ),
			)
		);
		register_rest_route(
			self::NS,
			'/attachments/list/(?P<entity>[a-z]+)/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_for' ),
				'permission_callback' => array( 'GSM_Auth', 'require_report' ),
			)
		);
		register_rest_route(
			self::NS,
			'/attachments/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'remove' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
			)
		);
	}

	/**
	 * POST /attachments
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload( $req ) {
		if ( GSM_Rate_Limit::too_many( 'att-upload', 40, 600 ) ) {
			return GSM_Rate_Limit::error();
		}
		$entity    = sanitize_key( (string) $req->get_param( 'entity' ) );
		$entity_id = (int) $req->get_param( 'entity_id' );
		if ( ! in_array( $entity, GSM_Attachments_Service::entities(), true ) || $entity_id <= 0 ) {
			return new WP_Error( 'gsm_att_target', __( 'יש לציין ישות ומזהה תקינים.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! self::entity_exists( $entity, $entity_id ) ) {
			return new WP_Error( 'gsm_att_target404', __( 'הישות לא נמצאה.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$files = $req->get_file_params();
		$file  = isset( $files['file'] ) ? $files['file'] : null;
		if ( ! $file ) {
			return new WP_Error( 'gsm_att_nofile', __( 'לא נבחר קובץ.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$user = wp_get_current_user();
		$res  = GSM_Attachments_Service::store( $entity, $entity_id, $file, ( $user && $user->ID ) ? $user->display_name : 'user' );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		return rest_ensure_response( $res );
	}

	/**
	 * GET /attachments/list/{entity}/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function list_for( $req ) {
		$entity = sanitize_key( (string) $req['entity'] );
		$id     = (int) $req['id'];
		if ( ! in_array( $entity, GSM_Attachments_Service::entities(), true ) ) {
			return rest_ensure_response( array() );
		}
		return rest_ensure_response( GSM_Attachments_Repo::list( $entity, $id ) );
	}

	/**
	 * GET /attachments/{id} — stream the file.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_Error|void
	 */
	public function download( $req ) {
		if ( GSM_Rate_Limit::too_many( 'att-download', 300, 300 ) ) {
			return GSM_Rate_Limit::error();
		}
		$row = GSM_Attachments_Repo::get( (int) $req['id'] );
		if ( ! $row ) {
			return new WP_Error( 'gsm_att_404', __( 'הצרופה לא נמצאה.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		return GSM_Attachments_Service::stream( $row );
	}

	/**
	 * DELETE /attachments/{id} — manager or the original uploader.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function remove( $req ) {
		$row = GSM_Attachments_Repo::get( (int) $req['id'] );
		if ( ! $row ) {
			return new WP_Error( 'gsm_att_404', __( 'הצרופה לא נמצאה.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$user      = wp_get_current_user();
		$is_owner  = ( $user && $user->ID && isset( $row['uploaded_by'] ) && $row['uploaded_by'] === $user->display_name );
		$is_manage = GSM_Auth::can_manage();
		if ( ! $is_manage && ! $is_owner ) {
			return new WP_Error( 'gsm_att_forbid', __( 'אין הרשאה למחוק צרופה זו.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}
		GSM_Attachments_Service::delete( $row );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'delete', $row['entity'], (int) $row['entity_id'], $row['orig_name'], 'נמחקה צרופה: ' . $row['orig_name'] );
		}
		return rest_ensure_response( array( 'deleted' => true ) );
	}

	/**
	 * Confirm the target entity exists.
	 *
	 * @param string $entity Entity type.
	 * @param int    $id     Id.
	 * @return bool
	 */
	private static function entity_exists( $entity, $id ) {
		global $wpdb;
		$map = array(
			'inspection' => $wpdb->prefix . 'gas_inspections',
			'fault'      => $wpdb->prefix . 'gas_faults',
			'equipment'  => $wpdb->prefix . 'gas_equipment',
		);
		if ( ! isset( $map[ $entity ] ) ) {
			return false;
		}
		$t = $map[ $entity ];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE id = %d", (int) $id ) ) > 0;
	}
}
