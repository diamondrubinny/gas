<?php
/**
 * Authentication & authorization helpers.
 *
 * Two audiences use the API:
 *   1. Logged-in staff (admin / lab manager / PI) — authorized by WP
 *      capabilities + REST nonce.
 *   2. Field users hitting the public bridge — authorized by a QR token
 *      (per-item read), never by identity.
 *
 * This class centralizes the permission_callback logic so controllers stay
 * declarative.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Auth
 */
class GSM_Auth {

	/**
	 * Can the current user manage everything (admin)?
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( 'gsm_manage_all' );
	}

	/**
	 * Can the current user edit equipment/inspections/faults
	 * (lab manager or admin)?
	 *
	 * @return bool
	 */
	public static function can_edit() {
		return current_user_can( 'gsm_edit_equipment' ) || current_user_can( 'gsm_manage_all' );
	}

	/**
	 * Can the current user view / report (PI, lab manager, or admin)?
	 *
	 * @return bool
	 */
	public static function can_report() {
		return current_user_can( 'gsm_report' )
			|| current_user_can( 'gsm_edit_equipment' )
			|| current_user_can( 'gsm_manage_all' );
	}

	/**
	 * Machine role key for the current (or given) user.
	 *
	 * @param WP_User|null $user Optional user; defaults to current.
	 * @return string manager|ahmat|lab_manager|pi|user
	 */
	public static function role_key( $user = null ) {
		$u = $user ? $user : wp_get_current_user();
		if ( ! $u || ! $u->ID ) {
			return 'guest';
		}
		if ( user_can( $u, 'gsm_manage_all' ) ) {
			return 'manager';
		}
		// A user could in principle hold more than one role; pick by the
		// inspection capability that defines their primary duty.
		$quarterly = user_can( $u, 'gsm_inspect_quarterly' );
		$monthly   = user_can( $u, 'gsm_inspect_monthly' );
		if ( $quarterly && ! $monthly ) {
			return 'ahmat';
		}
		if ( user_can( $u, 'gsm_edit_equipment' ) ) {
			return 'lab_manager';
		}
		if ( user_can( $u, 'gsm_report' ) ) {
			return 'pi';
		}
		return 'user';
	}

	/**
	 * Inspection types the current (or given) user is allowed to perform.
	 *
	 * Rule: אחמ"ת → quarterly; lab manager / PI → monthly; manager → both.
	 * Everyone who can inspect at all may also file an ad-hoc inspection.
	 *
	 * @param WP_User|null $user Optional user; defaults to current.
	 * @return string[] Subset of quarterly|monthly|adhoc.
	 */
	public static function inspect_types( $user = null ) {
		$u     = $user ? $user : wp_get_current_user();
		$types = array();
		if ( ! $u || ! $u->ID ) {
			return $types;
		}
		$manage = user_can( $u, 'gsm_manage_all' );
		if ( $manage || user_can( $u, 'gsm_inspect_quarterly' ) ) {
			$types[] = 'quarterly';
		}
		if ( $manage || user_can( $u, 'gsm_inspect_monthly' ) ) {
			$types[] = 'monthly';
		}
		if ( ! empty( $types ) ) {
			$types[] = 'adhoc';
		}
		return $types;
	}

	/**
	 * Whether the current user may file an inspection of the given type.
	 *
	 * @param string $type Inspection type.
	 * @return bool
	 */
	public static function can_inspect_type( $type ) {
		return in_array( (string) $type, self::inspect_types(), true );
	}

	/**
	 * Whether a user is active (not deactivated by a manager).
	 * Active by default; only an explicit '0' meta deactivates.
	 *
	 * @param int|WP_User $user User or ID.
	 * @return bool
	 */
	public static function is_active( $user ) {
		$id = is_object( $user ) ? (int) $user->ID : (int) $user;
		if ( ! $id ) {
			return false;
		}
		return '0' !== (string) get_user_meta( $id, 'gsm_active', true );
	}

	/**
	 * permission_callback: require manage capability.
	 *
	 * @return bool|WP_Error
	 */
	public static function require_manage() {
		if ( self::can_manage() ) {
			return true;
		}
		return self::deny();
	}

	/**
	 * permission_callback: require edit capability.
	 *
	 * @return bool|WP_Error
	 */
	public static function require_edit() {
		if ( self::can_edit() ) {
			return true;
		}
		return self::deny();
	}

	/**
	 * permission_callback: require report/view capability.
	 *
	 * @return bool|WP_Error
	 */
	public static function require_report() {
		if ( self::can_report() ) {
			return true;
		}
		return self::deny();
	}

	/**
	 * Can the current user manage QR issuance? Manager (full) or an אחמ"ת,
	 * mirroring the old portal's "מנהל,אחמ"ת" restriction on that tab.
	 *
	 * @return bool
	 */
	public static function can_issuance() {
		return self::can_manage() || 'ahmat' === self::role_key();
	}

	/**
	 * permission_callback: require QR-issuance access.
	 *
	 * @return bool|WP_Error
	 */
	public static function require_issuance() {
		if ( self::can_issuance() ) {
			return true;
		}
		return self::deny();
	}

	/**
	 * A standard 401/403 error. We return 403 for logged-in-but-unauthorized,
	 * 401 when there is no authenticated user at all.
	 *
	 * @return WP_Error
	 */
	private static function deny() {
		if ( is_user_logged_in() ) {
			return new WP_Error(
				'gsm_forbidden',
				__( 'You do not have permission to perform this action.', 'gas-safety-manager' ),
				array( 'status' => 403 )
			);
		}
		return new WP_Error(
			'gsm_unauthorized',
			__( 'Authentication required.', 'gas-safety-manager' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Hash an IP address for privacy-preserving scan logs (Amendment 13).
	 * Uses a site-specific salt so hashes are not cross-site correlatable.
	 *
	 * @param string $ip Raw IP.
	 * @return string 64-char hash.
	 */
	public static function hash_ip( $ip ) {
		$salt = wp_salt( 'nonce' );
		return hash( 'sha256', $salt . '|' . $ip );
	}

	/**
	 * Best-effort client IP (behind proxies it may be the proxy).
	 * We only ever store the HASH of this, never the raw value.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		return $ip;
	}
}
