<?php
/**
 * QR issuance service — business logic for tracking issued QR labels.
 *
 * Statuses mirror the old portal's three groups:
 *   issued          — QR printed & applied ("הונפק")
 *   pending_reissue — needs a new label ("ממתין להנפקה מחדש")
 *   archived        — retired ("ארכיון")
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_QR_Issuance_Service
 */
class GSM_QR_Issuance_Service {

	/**
	 * Allowed statuses.
	 *
	 * @return string[]
	 */
	public static function allowed_statuses() {
		return array( 'issued', 'pending_reissue', 'archived' );
	}

	/**
	 * Sanitize raw input.
	 *
	 * @param array $input Raw.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$clean = array();
		if ( isset( $input['equipment_id'] ) ) {
			$clean['equipment_id'] = (int) $input['equipment_id'];
		}
		foreach ( array( 'equipment_name', 'pi_name', 'qr_number', 'status' ) as $f ) {
			if ( isset( $input[ $f ] ) ) {
				$clean[ $f ] = sanitize_text_field( wp_unslash( $input[ $f ] ) );
			}
		}
		if ( isset( $input['note'] ) ) {
			$clean['note'] = sanitize_textarea_field( wp_unslash( $input['note'] ) );
		}
		if ( isset( $input['issued_date'] ) ) {
			$clean['issued_date'] = self::valid_date( $input['issued_date'] ) ? $input['issued_date'] : null;
		}
		return $clean;
	}

	/**
	 * Validate a Y-m-d date.
	 *
	 * @param string $date Date.
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
	 * Create an issuance record.
	 *
	 * @param array $input Raw input.
	 * @return int|WP_Error
	 */
	public static function create( $input ) {
		$data = self::sanitize( $input );
		if ( empty( $data['status'] ) || ! in_array( $data['status'], self::allowed_statuses(), true ) ) {
			$data['status'] = 'issued';
		}
		if ( empty( $data['issued_date'] ) ) {
			$data['issued_date'] = current_time( 'Y-m-d' );
		}
		if ( empty( $data['equipment_name'] ) && ! empty( $data['equipment_id'] ) ) {
			$eq = GSM_Equipment_Repo::get( (int) $data['equipment_id'] );
			if ( $eq ) {
				$data['equipment_name'] = isset( $eq['name'] ) ? $eq['name'] : '';
				if ( empty( $data['pi_name'] ) ) {
					$data['pi_name'] = isset( $eq['pi_name'] ) ? $eq['pi_name'] : '';
				}
				if ( empty( $data['qr_number'] ) ) {
					$data['qr_number'] = isset( $eq['asset_code'] ) ? $eq['asset_code'] : '';
				}
			}
		}
		$id = GSM_QR_Issuance_Repo::insert( $data );
		if ( ! is_wp_error( $id ) && class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'create', 'qr_issuance', (int) $id, isset( $data['equipment_name'] ) ? $data['equipment_name'] : '', 'נרשמה הנפקת QR' );
		}
		return $id;
	}

	/**
	 * Update a record.
	 *
	 * @param int   $id    ID.
	 * @param array $input Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, $input ) {
		$existing = GSM_QR_Issuance_Repo::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'gsm_not_found', __( 'Issuance record not found.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$data = self::sanitize( $input );
		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::allowed_statuses(), true ) ) {
			return new WP_Error( 'gsm_validation', __( 'Invalid issuance status.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$res = GSM_QR_Issuance_Repo::update( $id, $data );
		if ( ! is_wp_error( $res ) && class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'qr_issuance', (int) $id, isset( $existing['equipment_name'] ) ? $existing['equipment_name'] : '', 'עודכנה הנפקת QR' );
		}
		return $res;
	}

	/**
	 * Move a record to a new status.
	 *
	 * @param int    $id     ID.
	 * @param string $status New status.
	 * @return true|WP_Error
	 */
	public static function set_status( $id, $status ) {
		$status = sanitize_text_field( (string) $status );
		if ( ! in_array( $status, self::allowed_statuses(), true ) ) {
			return new WP_Error( 'gsm_validation', __( 'Invalid issuance status.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$existing = GSM_QR_Issuance_Repo::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'gsm_not_found', __( 'Issuance record not found.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$res = GSM_QR_Issuance_Repo::update( $id, array( 'status' => $status ) );
		if ( ! is_wp_error( $res ) && class_exists( 'GSM_Audit' ) ) {
			$labels = array( 'issued' => 'הונפק', 'pending_reissue' => 'ממתין להנפקה מחדש', 'archived' => 'ארכיון' );
			GSM_Audit::log( 'update', 'qr_issuance', (int) $id, isset( $existing['equipment_name'] ) ? $existing['equipment_name'] : '', 'סטטוס הנפקה → ' . ( isset( $labels[ $status ] ) ? $labels[ $status ] : $status ) );
		}
		return $res;
	}

	/**
	 * Delete a record (only when archived).
	 *
	 * @param int $id ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$existing = GSM_QR_Issuance_Repo::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'gsm_not_found', __( 'Issuance record not found.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		if ( 'archived' !== (string) $existing['status'] ) {
			return new WP_Error( 'gsm_rule', __( 'Only archived records can be deleted.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! GSM_QR_Issuance_Repo::delete( $id ) ) {
			return new WP_Error( 'gsm_db_delete', __( 'Failed to delete issuance record.', 'gas-safety-manager' ) );
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'delete', 'qr_issuance', (int) $id, isset( $existing['equipment_name'] ) ? $existing['equipment_name'] : '', 'הנפקת QR נמחקה', $existing );
		}
		return true;
	}

	/**
	 * Create issuance records in bulk from a list of equipment ids.
	 * Idempotent: equipment that already has a record is skipped.
	 *
	 * @param int[] $equipment_ids Equipment IDs.
	 * @return array { created, skipped }
	 */
	public static function bulk_from_equipment( $equipment_ids ) {
		$created = 0;
		$skipped = 0;
		foreach ( (array) $equipment_ids as $eid ) {
			$eid = (int) $eid;
			if ( ! $eid ) {
				continue;
			}
			if ( GSM_QR_Issuance_Repo::get_by_equipment( $eid ) ) {
				$skipped++;
				continue;
			}
			$res = self::create( array( 'equipment_id' => $eid, 'status' => 'issued' ) );
			if ( is_wp_error( $res ) ) {
				$skipped++;
			} else {
				$created++;
			}
		}
		return array( 'created' => $created, 'skipped' => $skipped );
	}

	/**
	 * List for the UI (items + per-status counts).
	 *
	 * @param array $args Filters.
	 * @return array
	 */
	public static function list( $args = array() ) {
		return array(
			'items'  => GSM_QR_Issuance_Repo::list( $args ),
			'counts' => GSM_QR_Issuance_Repo::counts_by_status(),
		);
	}
}
