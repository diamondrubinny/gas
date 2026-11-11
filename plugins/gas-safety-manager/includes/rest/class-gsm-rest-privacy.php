<?php
/**
 * REST controller: privacy deletion requests (manager only).
 *
 *   GET  /gas/v1/privacy-requests                    list
 *   POST /gas/v1/privacy-requests/{id}/scan          { identifiers[] } -> where they appear (dry run)
 *   POST /gas/v1/privacy-requests/{id}/anonymize     { identifiers[], delete_account } -> replace + close
 *   POST /gas/v1/privacy-requests/{id}/reject        close without changes
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Privacy
 */
class GSM_REST_Privacy {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$manage = array( 'GSM_Auth', 'require_manage' );
		register_rest_route( self::NS, '/privacy-requests', array( 'methods' => WP_REST_Server::READABLE, 'callback' => array( $this, 'list_requests' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/privacy-requests/(?P<id>\d+)/scan', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'scan' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/privacy-requests/(?P<id>\d+)/anonymize', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'anonymize' ), 'permission_callback' => $manage ) );
		register_rest_route( self::NS, '/privacy-requests/(?P<id>\d+)/reject', array( 'methods' => WP_REST_Server::CREATABLE, 'callback' => array( $this, 'reject' ), 'permission_callback' => $manage ) );
	}

	/**
	 * Load the request or a 404.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return array|WP_Error
	 */
	private function load( $req ) {
		$row = GSM_Privacy_Requests::get( (int) $req['id'] );
		return $row ? $row : new WP_Error( 'gsm_pr_404', __( 'הבקשה לא נמצאה.', 'gas-safety-manager' ), array( 'status' => 404 ) );
	}

	/**
	 * Identifiers from the body, or the request's defaults.
	 *
	 * @param WP_REST_Request $req Request.
	 * @param array           $row Request row.
	 * @return string[]
	 */
	private function identifiers( $req, $row ) {
		$body = $req->get_json_params();
		$ids  = ( is_array( $body ) && isset( $body['identifiers'] ) && is_array( $body['identifiers'] ) )
			? array_map( 'sanitize_text_field', array_map( 'strval', array_slice( $body['identifiers'], 0, 20 ) ) )
			: GSM_Privacy_Requests::default_identifiers( $row );
		return GSM_Privacy_Requests::clean_identifiers( $ids );
	}

	/**
	 * GET /privacy-requests
	 *
	 * @return WP_REST_Response
	 */
	public function list_requests() {
		$out = array();
		foreach ( GSM_Privacy_Requests::list_all() as $r ) {
			$out[] = array(
				'id'          => (int) $r['id'],
				'created_at'  => $r['created_at'],
				'email'       => $r['email'],
				'reason'      => (string) $r['reason'],
				'status'      => $r['status'],
				'user_name'   => $r['user_name'],
				'user_gone'   => $r['user_gone'],
				'identifiers' => GSM_Privacy_Requests::default_identifiers( $r ),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /privacy-requests/{id}/scan
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function scan( $req ) {
		$row = $this->load( $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		$ids = $this->identifiers( $req, $row );
		if ( empty( $ids ) ) {
			return new WP_Error( 'gsm_pr_ids', __( 'צריך לפחות מזהה אחד (שם או אימייל, 3 תווים לפחות).', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$res                = GSM_Privacy_Requests::process( $ids, false );
		$res['identifiers'] = $ids;
		return rest_ensure_response( $res );
	}

	/**
	 * POST /privacy-requests/{id}/anonymize
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function anonymize( $req ) {
		$row = $this->load( $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		if ( 'open' !== $row['status'] ) {
			return new WP_Error( 'gsm_pr_closed', __( 'הבקשה כבר טופלה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$ids = $this->identifiers( $req, $row );
		if ( empty( $ids ) ) {
			return new WP_Error( 'gsm_pr_ids', __( 'צריך לפחות מזהה אחד (שם או אימייל, 3 תווים לפחות).', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$pseudonym = 'משתמש נמחק #' . (int) $row['id'];
		$res       = GSM_Privacy_Requests::process( $ids, true, $pseudonym );

		// Optionally delete the WordPress account (gas-app users only, never self/admins).
		$body            = $req->get_json_params();
		$account_deleted = false;
		if ( is_array( $body ) && ! empty( $body['delete_account'] ) && ! empty( $row['user_id'] ) ) {
			$u = get_userdata( (int) $row['user_id'] );
			if ( $u && (int) $u->ID !== get_current_user_id() && GSM_REST_Users::is_app_user( $u ) ) {
				require_once ABSPATH . 'wp-admin/includes/user.php';
				$account_deleted = (bool) wp_delete_user( (int) $u->ID );
			}
		}

		GSM_Privacy_Requests::set_status( (int) $row['id'], 'done' );
		if ( class_exists( 'GSM_Audit' ) ) {
			// No name / email in the log entry itself.
			GSM_Audit::log( 'update', 'system', 0, '', sprintf( 'טופלה בקשת פרטיות #%d: הוחלפו %d הופעות ב-%d רשומות%s', (int) $row['id'], (int) $res['total_matches'], (int) $res['total_rows'], $account_deleted ? '; חשבון המשתמש נמחק' : '' ) );
		}
		$res['account_deleted'] = $account_deleted;
		$res['pseudonym']       = $pseudonym;
		return rest_ensure_response( $res );
	}

	/**
	 * POST /privacy-requests/{id}/reject
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reject( $req ) {
		$row = $this->load( $req );
		if ( is_wp_error( $row ) ) {
			return $row;
		}
		GSM_Privacy_Requests::set_status( (int) $row['id'], 'rejected' );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'system', 0, '', sprintf( 'בקשת פרטיות #%d נדחתה', (int) $row['id'] ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
