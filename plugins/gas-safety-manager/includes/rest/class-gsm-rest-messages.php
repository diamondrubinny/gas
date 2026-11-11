<?php
/**
 * REST controller: field messages + feedback.
 *
 *   GET    /gas/v1/field-messages              (signed-in) list (managers: all; others: own)
 *   POST   /gas/v1/field-messages              (signed-in) { text }
 *   POST   /gas/v1/field-messages/{id}/read    (manager)
 *   DELETE /gas/v1/field-messages/{id}         (manager)
 *   POST   /gas/v1/field-messages/clear        (manager) { read_only }
 *   GET    /gas/v1/feedback                    (signed-in) list (managers: all; others: own)
 *   POST   /gas/v1/feedback                    (signed-in) { type, priority, title, area, text }
 *   POST   /gas/v1/feedback/{id}/status        (manager) { status }
 *   DELETE /gas/v1/feedback/{id}               (manager)
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Messages
 */
class GSM_REST_Messages {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$any = array( 'GSM_Auth', 'require_report' );
		$mgr = array( 'GSM_Auth', 'require_manage' );
		foreach ( array( 'field-messages' => 'field', 'feedback' => 'feedback' ) as $base => $kind ) {
			register_rest_route(
				self::NS,
				'/' . $base,
				array(
					array(
						'methods'             => WP_REST_Server::READABLE,
						'callback'            => function () use ( $kind ) {
							return $this->index( $kind );
						},
						'permission_callback' => $any,
					),
					array(
						'methods'             => WP_REST_Server::CREATABLE,
						'callback'            => function ( $req ) use ( $kind ) {
							return $this->create( $kind, $req );
						},
						'permission_callback' => $any,
					),
				)
			);
			register_rest_route(
				self::NS,
				'/' . $base . '/(?P<id>\d+)',
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => function ( $req ) use ( $kind ) {
						return $this->delete( $kind, $req );
					},
					'permission_callback' => $mgr,
				)
			);
		}
		register_rest_route(
			self::NS,
			'/field-messages/(?P<id>\d+)/read',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'mark_read' ),
				'permission_callback' => $mgr,
			)
		);
		register_rest_route(
			self::NS,
			'/field-messages/clear',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'clear' ),
				'permission_callback' => $mgr,
			)
		);
		register_rest_route(
			self::NS,
			'/feedback/(?P<id>\d+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_status' ),
				'permission_callback' => $mgr,
			)
		);
	}

	/**
	 * List.
	 *
	 * @param string $kind Kind.
	 * @return WP_REST_Response
	 */
	public function index( $kind ) {
		$manage = GSM_Auth::can_manage();
		$out    = array(
			'items'  => GSM_Messages::list_for( $kind, get_current_user_id(), $manage ),
			'manage' => $manage,
		);
		if ( $manage ) {
			$out['counts'] = GSM_Messages::counts();
		}
		return rest_ensure_response( $out );
	}

	/**
	 * Create.
	 *
	 * @param string          $kind Kind.
	 * @param WP_REST_Request $req  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create( $kind, $req ) {
		$b  = (array) $req->get_json_params();
		$in = array();
		foreach ( array( 'text', 'type', 'priority', 'title', 'area' ) as $k ) {
			if ( isset( $b[ $k ] ) && is_string( $b[ $k ] ) ) {
				$in[ $k ] = $b[ $k ];
			}
		}
		$id = GSM_Messages::create( $kind, wp_get_current_user(), $in );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		return rest_ensure_response( array( 'id' => $id ) );
	}

	/**
	 * Row or 404.
	 *
	 * @param string          $kind Kind.
	 * @param WP_REST_Request $req  Request.
	 * @return array|WP_Error
	 */
	private function row( $kind, $req ) {
		$row = GSM_Messages::get( $kind, (int) $req['id'] );
		return $row ? $row : new WP_Error( 'gsm_not_found', __( 'ההודעה לא נמצאה.', 'gas-safety-manager' ), array( 'status' => 404 ) );
	}

	/**
	 * POST /field-messages/{id}/read
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function mark_read( $req ) {
		$row = $this->row( 'field', $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		GSM_Messages::set_status( (int) $row['id'], 'read' );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /feedback/{id}/status
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_status( $req ) {
		$row = $this->row( 'feedback', $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$b      = (array) $req->get_json_params();
		$status = isset( $b['status'] ) ? (string) $b['status'] : '';
		if ( ! in_array( $status, GSM_Messages::FB_STATUS, true ) ) {
			return new WP_Error( 'gsm_validation', __( 'סטטוס לא תקין.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		GSM_Messages::set_status( (int) $row['id'], $status );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * DELETE /{base}/{id}
	 *
	 * @param string          $kind Kind.
	 * @param WP_REST_Request $req  Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete( $kind, $req ) {
		global $wpdb;
		$row = $this->row( $kind, $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$wpdb->delete( GSM_Messages::table(), array( 'id' => (int) $row['id'] ) );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'delete', 'message', (int) $row['id'], 'field' === $kind ? 'הודעה מהשטח' : 'משוב', 'field' === $kind ? 'נמחקה הודעה מהשטח' : 'נמחקה פניית משוב' );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /field-messages/clear
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function clear( $req ) {
		global $wpdb;
		$b = (array) $req->get_json_params();
		$t = GSM_Messages::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$n = ! empty( $b['read_only'] )
			? (int) $wpdb->query( "DELETE FROM {$t} WHERE kind = 'field' AND status = 'read'" )
			: (int) $wpdb->query( "DELETE FROM {$t} WHERE kind = 'field'" );
		// phpcs:enable
		if ( $n && class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'delete', 'message', 0, 'הודעות מהשטח', 'נוקו ' . $n . ' הודעות מהשטח' . ( ! empty( $b['read_only'] ) ? ' (שנקראו)' : '' ) );
		}
		return rest_ensure_response( array( 'deleted' => $n ) );
	}
}
