<?php
/**
 * Equipment service — business logic layer.
 *
 * Validates and sanitizes input, applies rules, then delegates persistence
 * to GSM_Equipment_Repo. REST controllers call this; never the repo directly
 * for writes that need validation.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Equipment_Service
 */
class GSM_Equipment_Service {

	/**
	 * Allowed status values.
	 *
	 * @return string[]
	 */
	public static function allowed_statuses() {
		return array( 'active', 'maintenance', 'faulty', 'inactive', '' );
	}

	/**
	 * Sanitize raw input into a clean column => value array.
	 * Only known columns survive. Text is sanitized; dates validated.
	 *
	 * @param array $input Raw input (e.g. from REST request).
	 * @return array Clean data.
	 */
	public static function sanitize( $input ) {
		$clean = array();

		$text_fields = array(
			'name', 'gas_type', 'category', 'faculty', 'building', 'floor',
			'room', 'location', 'functional_loc', 'gis', 'panel_number',
			'work_pressure', 'cylinders_count', 'cylinders_stock', 'phone',
			'pi_name', 'status', 'monday_id', 'asset_code',
		);
		foreach ( $text_fields as $f ) {
			if ( isset( $input[ $f ] ) ) {
				$clean[ $f ] = sanitize_text_field( wp_unslash( $input[ $f ] ) );
			}
		}

		// Multi-line / longer text fields.
		$textarea_fields = array( 'contacts', 'lab_managers', 'maint_staff', 'maint_emails', 'notes' );
		foreach ( $textarea_fields as $f ) {
			if ( isset( $input[ $f ] ) ) {
				$clean[ $f ] = sanitize_textarea_field( wp_unslash( $input[ $f ] ) );
			}
		}

		// Dates: accept YYYY-MM-DD only, else store empty.
		foreach ( array( 'last_quarterly', 'next_quarterly' ) as $d ) {
			if ( isset( $input[ $d ] ) ) {
				$clean[ $d ] = self::valid_date( $input[ $d ] ) ? $input[ $d ] : null;
			}
		}

		// Image ID (int).
		if ( isset( $input['image_id'] ) ) {
			$clean['image_id'] = (int) $input['image_id'];
		}

		return $clean;
	}

	/**
	 * Validate a date string as YYYY-MM-DD.
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private static function valid_date( $date ) {
		if ( ! is_string( $date ) || '' === $date ) {
			return false;
		}
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}

	/**
	 * Validate business rules. Returns WP_Error if invalid.
	 *
	 * @param array $data     Clean data.
	 * @param bool  $is_create Whether this is a create (name required).
	 * @return true|WP_Error
	 */
	public static function validate( $data, $is_create = true ) {
		if ( $is_create && empty( $data['name'] ) ) {
			return new WP_Error(
				'gsm_validation',
				__( 'Equipment name is required.', 'gas-safety-manager' ),
				array( 'status' => 400 )
			);
		}
		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::allowed_statuses(), true ) ) {
			return new WP_Error(
				'gsm_validation',
				__( 'Invalid status value.', 'gas-safety-manager' ),
				array( 'status' => 400 )
			);
		}
		return true;
	}

	/**
	 * Generate a unique, unguessable QR token.
	 *
	 * @return string 32-hex-char token.
	 */
	public static function generate_token() {
		do {
			// 16 random bytes -> 32 hex chars. Cryptographically strong.
			$token = bin2hex( random_bytes( 16 ) );
		} while ( GSM_Equipment_Repo::token_exists( $token ) );
		return $token;
	}

	/**
	 * The prefix used for brand-new asset codes (equipment that never existed
	 * in Monday). Kept short and distinct from Monday's long numeric IDs so a
	 * glance at a label tells you whether it is a migrated or a new item.
	 *
	 * @return string
	 */
	public static function new_code_prefix() {
		return (string) apply_filters( 'gsm_new_asset_prefix', 'G-' );
	}

	/**
	 * Decide the human-facing asset number for an item being created.
	 *
	 * Rule (per spec): keep the EXISTING Monday number for migrated items,
	 * allocate a NEW sequential number for genuinely new equipment.
	 *
	 *   - monday_id present  -> asset_code = monday_id (preserve the number
	 *                           already printed on the physical label)
	 *   - otherwise          -> next free "G-####" code (>= G-1001)
	 *
	 * Sequence state lives in the gsm_asset_seq option, but we also scan the
	 * table (max_new_asset_seq) so a reset/restore can never hand out a code
	 * that already exists.
	 *
	 * @param array $data Clean data (may contain monday_id / asset_code).
	 * @return string Allocated asset code.
	 */
	public static function allocate_asset_code( $data ) {
		// Respect an explicit code if one was supplied (e.g. manual import).
		if ( ! empty( $data['asset_code'] ) ) {
			return (string) $data['asset_code'];
		}

		// Migrated item: the Monday number IS the asset number.
		if ( ! empty( $data['monday_id'] ) ) {
			return (string) $data['monday_id'];
		}

		// New item: allocate the next free G-#### code.
		$prefix = self::new_code_prefix();
		$start  = (int) apply_filters( 'gsm_new_asset_start', 1001 );

		$next = (int) get_option( 'gsm_asset_seq', 0 );
		$seen = GSM_Equipment_Repo::max_new_asset_seq( $prefix );
		$next = max( $next, $seen, $start - 1 ) + 1;

		// Guard against any lingering collision (defensive loop).
		$code = $prefix . $next;
		while ( GSM_Equipment_Repo::asset_code_exists( $code ) ) {
			$next++;
			$code = $prefix . $next;
		}

		update_option( 'gsm_asset_seq', $next );
		return $code;
	}

	/**
	 * Create a new equipment item.
	 *
	 * @param array $input Raw input.
	 * @return int|WP_Error New ID or error.
	 */
	public static function create( $input ) {
		$data = self::sanitize( $input );

		$valid = self::validate( $data, true );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Always assign a fresh QR token unless one was explicitly provided
		// (e.g. during migration to preserve an existing mapping).
		if ( empty( $data['qr_token'] ) ) {
			$data['qr_token'] = self::generate_token();
		}

		// Assign the human-facing asset number: preserve Monday's number for
		// migrated items, allocate a new G-#### for genuinely new equipment.
		if ( empty( $data['asset_code'] ) ) {
			$data['asset_code'] = self::allocate_asset_code( $data );
		}

		$id = GSM_Equipment_Repo::insert( $data );
		if ( ! is_wp_error( $id ) && class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'create', 'equipment', (int) $id, isset( $data['name'] ) ? $data['name'] : '', 'נוצר ציוד חדש' );
		}
		if ( ! is_wp_error( $id ) && class_exists( 'GSM_Integrations' ) ) {
			GSM_Integrations::fire_webhook(
				'equipment.created',
				array(
					'id'       => (int) $id,
					'name'     => isset( $data['name'] ) ? $data['name'] : '',
					'building' => isset( $data['building'] ) ? $data['building'] : '',
				)
			);
		}
		return $id;
	}

	/**
	 * Update an equipment item.
	 *
	 * @param int   $id    Equipment ID.
	 * @param array $input Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, $input ) {
		$existing = GSM_Equipment_Repo::get( $id );
		if ( ! $existing ) {
			return new WP_Error(
				'gsm_not_found',
				__( 'Equipment not found.', 'gas-safety-manager' ),
				array( 'status' => 404 )
			);
		}

		$data  = self::sanitize( $input );
		$valid = self::validate( $data, false );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		// Never allow token to be blanked via update.
		if ( array_key_exists( 'qr_token', $data ) && empty( $data['qr_token'] ) ) {
			unset( $data['qr_token'] );
		}

		$res = GSM_Equipment_Repo::update( $id, $data );
		if ( ! is_wp_error( $res ) && class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'equipment', (int) $id, isset( $existing['name'] ) ? $existing['name'] : '', 'עודכנו פרטי ציוד' );
		}
		return $res;
	}

	/**
	 * Delete an equipment item.
	 *
	 * @param int $id Equipment ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$existing = GSM_Equipment_Repo::get( $id );
		if ( ! $existing ) {
			return new WP_Error(
				'gsm_not_found',
				__( 'Equipment not found.', 'gas-safety-manager' ),
				array( 'status' => 404 )
			);
		}
		$ok = GSM_Equipment_Repo::delete( $id );
		if ( ! $ok ) {
			return new WP_Error( 'gsm_db_delete', __( 'Failed to delete equipment.', 'gas-safety-manager' ) );
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'delete', 'equipment', (int) $id, isset( $existing['name'] ) ? $existing['name'] : '', 'ציוד נמחק', $existing );
		}
		return true;
	}

	/**
	 * Get one item (pass-through with not-found handling).
	 *
	 * @param int $id Equipment ID.
	 * @return array|WP_Error
	 */
	public static function get( $id ) {
		$row = GSM_Equipment_Repo::get( $id );
		if ( ! $row ) {
			return new WP_Error(
				'gsm_not_found',
				__( 'Equipment not found.', 'gas-safety-manager' ),
				array( 'status' => 404 )
			);
		}
		return $row;
	}

	/**
	 * List items with filters.
	 *
	 * @param array $args Filters.
	 * @return array { items: array, total: int }
	 */
	public static function list( $args = array() ) {
		$items = GSM_Equipment_Repo::list( $args );
		$total = GSM_Equipment_Repo::count( $args );
		return array(
			'items' => $items,
			'total' => $total,
		);
	}
}
