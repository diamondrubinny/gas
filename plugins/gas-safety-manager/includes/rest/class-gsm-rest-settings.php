<?php
/**
 * REST controller: Settings.
 *
 * Stores admin-configurable thresholds (quarterly/monthly overdue days) in
 * wp_options. Read requires report cap; write requires manage cap.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Settings
 */
class GSM_REST_Settings {

	const NS = 'gas/v1';

	/**
	 * Register settings routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_settings' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);

		// Role → allowed nav items matrix.
		register_rest_route(
			self::NS,
			'/settings/role-perms',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_role_perms' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_role_perms' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);

		// Global menu show/hide.
		register_rest_route(
			self::NS,
			'/settings/menu',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_menu' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_menu' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
	}

	/**
	 * Items that can never be hidden/removed (anti-lockout).
	 *
	 * @return string[]
	 */
	private static function locked_items() {
		return array( 'dashboard', 'profile', 'help', 'admin', 'users' );
	}

	/**
	 * GET /settings/role-perms → { perms: { roleKey: [itemIds] } }.
	 *
	 * @return WP_REST_Response
	 */
	public function get_role_perms() {
		$perms = get_option( 'gsm_role_perms', array() );
		if ( ! is_array( $perms ) ) {
			$perms = array();
		}
		return rest_ensure_response( array( 'perms' => $perms ) );
	}

	/**
	 * POST /settings/role-perms { perms: { roleKey: [itemIds] } }.
	 * An empty perms object ({}) resets to defaults (capability-based).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function save_role_perms( $req ) {
		$body  = $req->get_json_params();
		$perms = ( isset( $body['perms'] ) && is_array( $body['perms'] ) ) ? $body['perms'] : array();
		$allowed_roles = array( 'manager', 'ahmat', 'lab_manager', 'pi' );
		$clean = array();
		foreach ( $perms as $role => $ids ) {
			if ( ! in_array( $role, $allowed_roles, true ) || ! is_array( $ids ) ) {
				continue;
			}
			$list = array();
			foreach ( $ids as $id ) {
				$list[] = sanitize_key( (string) $id );
			}
			// Force locked items in.
			$list = array_values( array_unique( array_merge( $list, self::locked_items() ) ) );
			$clean[ $role ] = $list;
		}
		update_option( 'gsm_role_perms', $clean );
		return rest_ensure_response( array( 'perms' => $clean ) );
	}

	/**
	 * GET /settings/menu → { hidden: [itemIds] }.
	 *
	 * @return WP_REST_Response
	 */
	public function get_menu() {
		$hidden = get_option( 'gsm_portal_hidden', array() );
		if ( ! is_array( $hidden ) ) {
			$hidden = array();
		}
		return rest_ensure_response( array( 'hidden' => array_values( $hidden ) ) );
	}

	/**
	 * POST /settings/menu { hidden: [itemIds] }.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function save_menu( $req ) {
		$body   = $req->get_json_params();
		$hidden = ( isset( $body['hidden'] ) && is_array( $body['hidden'] ) ) ? $body['hidden'] : array();
		$locked = self::locked_items();
		$clean  = array();
		foreach ( $hidden as $id ) {
			$id = sanitize_key( (string) $id );
			if ( $id && ! in_array( $id, $locked, true ) ) {
				$clean[] = $id;
			}
		}
		$clean = array_values( array_unique( $clean ) );
		update_option( 'gsm_portal_hidden', $clean );
		return rest_ensure_response( array( 'hidden' => $clean ) );
	}

	/**
	 * GET /settings
	 *
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return rest_ensure_response(
			array(
				'quarterly_days' => (int) get_option( 'gsm_quarterly_days', 90 ),
				'monthly_days'   => (int) get_option( 'gsm_monthly_days', 30 ),
				'branding'       => (array) get_option( 'gsm_branding', array() ),
				'nav_order'      => array_values( (array) get_option( 'gsm_nav_order', array() ) ),
			)
		);
	}

	/**
	 * POST /settings
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function save_settings( $req ) {
		$body = $req->get_json_params();
		$body = is_array( $body ) ? $body : array();
		if ( isset( $body['quarterly_days'] ) ) {
			update_option( 'gsm_quarterly_days', max( 1, min( 3650, (int) $body['quarterly_days'] ) ) );
		}
		if ( isset( $body['monthly_days'] ) ) {
			update_option( 'gsm_monthly_days', max( 1, min( 3650, (int) $body['monthly_days'] ) ) );
		}

		if ( isset( $body['branding'] ) && is_array( $body['branding'] ) ) {
			$b = $body['branding'];
			update_option(
				'gsm_branding',
				array(
					'title'    => isset( $b['title'] ) ? sanitize_text_field( (string) $b['title'] ) : '',
					'subtitle' => isset( $b['subtitle'] ) ? sanitize_text_field( (string) $b['subtitle'] ) : '',
					'logo_url' => isset( $b['logo_url'] ) ? esc_url_raw( (string) $b['logo_url'] ) : '',
				)
			);
		}
		if ( isset( $body['nav_order'] ) && is_array( $body['nav_order'] ) ) {
			$order = array();
			foreach ( $body['nav_order'] as $id ) {
				$id = sanitize_key( (string) $id );
				if ( '' !== $id ) {
					$order[] = $id;
				}
			}
			update_option( 'gsm_nav_order', array_values( array_unique( $order ) ) );
		}

		return rest_ensure_response(
			array(
				'quarterly_days' => (int) get_option( 'gsm_quarterly_days', 90 ),
				'monthly_days'   => (int) get_option( 'gsm_monthly_days', 30 ),
				'branding'       => (array) get_option( 'gsm_branding', array() ),
				'nav_order'      => array_values( (array) get_option( 'gsm_nav_order', array() ) ),
				'saved'          => true,
			)
		);
	}
}
