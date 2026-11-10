<?php
/**
 * REST controller: small self-contained tools.
 *
 *   GET  /gas/v1/quality          (report)  — data-quality scan of equipment
 *   GET  /gas/v1/profile          (report)  — current user's profile
 *   POST /gas/v1/profile          (report)  — update own name/phone
 *   GET  /gas/v1/bridge-config    (report)  — public scan-page config
 *   POST /gas/v1/bridge-config    (manage)  — save it
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Tools
 */
class GSM_REST_Tools {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/quality',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'quality' ),
				'permission_callback' => array( 'GSM_Auth', 'require_report' ),
			)
		);
		register_rest_route(
			self::NS,
			'/profile',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_profile' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_profile' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/profile/deletion-request',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'deletion_request' ),
				'permission_callback' => array( 'GSM_Auth', 'require_report' ),
			)
		);
		register_rest_route(
			self::NS,
			'/bridge-config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_bridge_config' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_bridge_config' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
	}

	/**
	 * GET /quality — missing-field scan.
	 *
	 * @return WP_REST_Response
	 */
	public function quality() {
		$items = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'name', 'order' => 'ASC' ) );
		$checks = array(
			'noPI'        => array( 'label' => 'ללא חוקר ראשי', 'field' => 'pi_name', 'sev' => 'danger' ),
			'noPanelNum'  => array( 'label' => 'ללא מספר פאנל', 'field' => 'panel_number', 'sev' => 'warn' ),
			'noGasType'   => array( 'label' => 'ללא סוג גז', 'field' => 'gas_type', 'sev' => 'warn' ),
			'noLocation'  => array( 'label' => 'ללא מיקום', 'field' => 'building', 'sev' => 'danger' ),
			'noNextInsp'  => array( 'label' => 'ללא תאריך ביקורת הבאה', 'field' => 'next_quarterly', 'sev' => 'warn' ),
		);
		$summary = array();
		$issues  = array();
		foreach ( $checks as $key => $c ) {
			$summary[ $key ] = array( 'label' => $c['label'], 'sev' => $c['sev'], 'count' => 0 );
			$issues[ $key ]  = array();
		}
		foreach ( (array) $items as $e ) {
			foreach ( $checks as $key => $c ) {
				$v = isset( $e[ $c['field'] ] ) ? trim( (string) $e[ $c['field'] ] ) : '';
				if ( '' === $v ) {
					$summary[ $key ]['count']++;
					if ( count( $issues[ $key ] ) < 200 ) {
						$issues[ $key ][] = array( 'id' => (int) $e['id'], 'name' => $e['name'] );
					}
				}
			}
		}
		return rest_ensure_response(
			array(
				'total'   => count( (array) $items ),
				'summary' => $summary,
				'issues'  => $issues,
			)
		);
	}

	/**
	 * GET /profile
	 *
	 * @return WP_REST_Response
	 */
	public function get_profile() {
		$u = wp_get_current_user();
		return rest_ensure_response(
			array(
				'name'   => $u->display_name,
				'email'  => $u->user_email,
				'phone'  => (string) get_user_meta( $u->ID, 'gsm_phone', true ),
				'avatar' => (string) get_user_meta( $u->ID, 'gsm_avatar', true ),
			)
		);
	}

	/**
	 * POST /profile — update own name + phone.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_profile( $req ) {
		$u    = wp_get_current_user();
		$body = $req->get_json_params();
		$name = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
		$phone = isset( $body['phone'] ) ? sanitize_text_field( (string) $body['phone'] ) : '';

		if ( '' !== $name ) {
			wp_update_user( array( 'ID' => $u->ID, 'display_name' => $name ) );
		}
		update_user_meta( $u->ID, 'gsm_phone', $phone );

		// Avatar: a single emoji (store a short string only).
		if ( isset( $body['avatar'] ) ) {
			$av = sanitize_text_field( (string) $body['avatar'] );
			if ( function_exists( 'mb_substr' ) ) {
				$av = mb_substr( $av, 0, 4 );
			}
			update_user_meta( $u->ID, 'gsm_avatar', $av );
		}

		return $this->get_profile();
	}

	/**
	 * POST /profile/deletion-request — Amendment 13: record the request,
	 * clear the user's personal extras now, and notify the admin.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function deletion_request( $req ) {
		global $wpdb;
		$u      = wp_get_current_user();
		$body   = $req->get_json_params();
		$reason = isset( $body['reason'] ) ? sanitize_textarea_field( (string) $body['reason'] ) : '';

		// Record the request.
		$wpdb->insert(
			$wpdb->prefix . 'gas_deletion_requests',
			array(
				'created_at' => current_time( 'mysql' ),
				'user_id'    => (int) $u->ID,
				'email'      => $u->user_email,
				'reason'     => $reason,
				'status'     => 'open',
			),
			array( '%s', '%d', '%s', '%s', '%s' )
		);

		// Immediately clear the personal extras we hold.
		delete_user_meta( $u->ID, 'gsm_phone' );
		delete_user_meta( $u->ID, 'gsm_avatar' );

		// Notify the admin (best-effort — needs SMTP to actually send).
		$admin = get_option( 'admin_email' );
		if ( $admin ) {
			wp_mail(
				$admin,
				__( 'בקשת מחיקת פרטים — מערכת הגזים', 'gas-safety-manager' ),
				sprintf( /* translators: 1 user, 2 email, 3 reason */ __( "התקבלה בקשת מחיקת פרטים (תיקון 13).\nמשתמש: %1\$s\nאימייל: %2\$s\nסיבה: %3\$s", 'gas-safety-manager' ), $u->display_name, $u->user_email, $reason )
			);
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Default bridge config.
	 *
	 * @return array
	 */
	public static function bridge_defaults() {
		return array(
			'showStatus'       => true,
			'showPI'           => true,
			'showLabManager'   => true,
			'showMaintenance'  => true,
			'showPhone'        => true,
			'showFaultButton'  => true,
			'compactMode'      => false,
			'primaryColor'     => '#002147',
			'accentColor'      => '#e0a526',
		);
	}

	/**
	 * GET /bridge-config
	 *
	 * @return WP_REST_Response
	 */
	public function get_bridge_config() {
		$cfg = get_option( 'gsm_bridge_config', array() );
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}
		return rest_ensure_response( array_merge( self::bridge_defaults(), $cfg ) );
	}

	/**
	 * POST /bridge-config
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function save_bridge_config( $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$d    = self::bridge_defaults();
		$bool = array( 'showStatus', 'showPI', 'showLabManager', 'showMaintenance', 'showPhone', 'showFaultButton', 'compactMode' );
		$clean = array();
		foreach ( $bool as $k ) {
			$clean[ $k ] = isset( $body[ $k ] ) ? (bool) $body[ $k ] : $d[ $k ];
		}
		foreach ( array( 'primaryColor', 'accentColor' ) as $c ) {
			$v = isset( $body[ $c ] ) ? sanitize_hex_color( (string) $body[ $c ] ) : '';
			$clean[ $c ] = $v ? $v : $d[ $c ];
		}
		update_option( 'gsm_bridge_config', $clean );
		return rest_ensure_response( $clean );
	}
}
