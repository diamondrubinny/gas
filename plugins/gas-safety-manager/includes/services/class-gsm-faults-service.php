<?php
/**
 * Faults service — business logic.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Faults_Service
 */
class GSM_Faults_Service {

	/**
	 * Allowed status values.
	 *
	 * @return string[]
	 */
	public static function allowed_statuses() {
		return array( 'open', 'in_progress', 'resolved', 'closed' );
	}

	/**
	 * Allowed priority values.
	 *
	 * @return string[]
	 */
	public static function allowed_priorities() {
		return array( 'low', 'normal', 'high', 'urgent' );
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
		foreach ( array( 'reporter', 'status', 'priority', 'monday_id' ) as $f ) {
			if ( isset( $input[ $f ] ) ) {
				$clean[ $f ] = sanitize_text_field( wp_unslash( $input[ $f ] ) );
			}
		}
		if ( isset( $input['description'] ) ) {
			$clean['description'] = sanitize_textarea_field( wp_unslash( $input['description'] ) );
		}
		foreach ( array( 'opened_date', 'resolved_date' ) as $d ) {
			if ( isset( $input[ $d ] ) ) {
				$clean[ $d ] = self::valid_date( $input[ $d ] ) ? $input[ $d ] : null;
			}
		}

		// Defaults for new faults.
		if ( ! isset( $clean['status'] ) || '' === $clean['status'] ) {
			$clean['status'] = 'open';
		}
		if ( ! isset( $clean['priority'] ) || '' === $clean['priority'] ) {
			$clean['priority'] = 'normal';
		}

		return $clean;
	}

	/**
	 * Validate a date string.
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
	 * Validate business rules.
	 *
	 * @param array $data      Clean data.
	 * @param bool  $is_create Whether creating.
	 * @return true|WP_Error
	 */
	public static function validate( $data, $is_create = true ) {
		if ( $is_create && empty( $data['description'] ) ) {
			return new WP_Error( 'gsm_validation', __( 'Fault description is required.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['status'] ) && ! in_array( $data['status'], self::allowed_statuses(), true ) ) {
			return new WP_Error( 'gsm_validation', __( 'Invalid fault status.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['priority'] ) && ! in_array( $data['priority'], self::allowed_priorities(), true ) ) {
			return new WP_Error( 'gsm_validation', __( 'Invalid fault priority.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Create a fault.
	 *
	 * @param array $input Raw input.
	 * @return int|WP_Error
	 */
	public static function create( $input ) {
		$data = self::sanitize( $input );

		// Default opened_date to today if not given.
		if ( empty( $data['opened_date'] ) ) {
			$data['opened_date'] = current_time( 'Y-m-d' );
		}

		$valid = self::validate( $data, true );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$id = GSM_Faults_Repo::insert( $data );
		if ( ! is_wp_error( $id ) && class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'create', 'fault', (int) $id, isset( $data['description'] ) ? wp_trim_words( $data['description'], 6 ) : '', 'דווחה תקלה' );
		}
		if ( ! is_wp_error( $id ) && class_exists( 'GSM_Integrations' ) ) {
			GSM_Integrations::fire_webhook(
				'fault.created',
				array(
					'id'           => (int) $id,
					'equipment_id' => isset( $data['equipment_id'] ) ? (int) $data['equipment_id'] : 0,
					'priority'     => isset( $data['priority'] ) ? $data['priority'] : '',
					'status'       => isset( $data['status'] ) ? $data['status'] : '',
					'description'  => isset( $data['description'] ) ? $data['description'] : '',
				)
			);
		}
		// No alert for urgent faults that arrive through a bulk import (old records).
		if ( ! is_wp_error( $id ) && class_exists( 'GSM_Comm' ) && ! ( class_exists( 'GSM_Bulk' ) && GSM_Bulk::active() ) && isset( $data['priority'] ) && 'urgent' === $data['priority'] ) {
			GSM_Comm::on_urgent_fault( isset( $data['equipment_id'] ) ? (int) $data['equipment_id'] : 0, isset( $data['description'] ) ? $data['description'] : '' );
		}
		return $id;
	}

	/**
	 * Update a fault.
	 *
	 * @param int   $id    Fault ID.
	 * @param array $input Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, $input ) {
		$existing = GSM_Faults_Repo::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'gsm_not_found', __( 'Fault not found.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$data  = self::sanitize( $input );
		$valid = self::validate( $data, false );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$res = GSM_Faults_Repo::update( $id, $data );
		if ( ! is_wp_error( $res ) && class_exists( 'GSM_Audit' ) ) {
			$st = isset( $data['status'] ) ? ' → ' . $data['status'] : '';
			GSM_Audit::log( 'update', 'fault', (int) $id, isset( $existing['description'] ) ? wp_trim_words( $existing['description'], 6 ) : '', 'עודכנה תקלה' . $st );
		}
		if ( ! is_wp_error( $res ) && class_exists( 'GSM_Integrations' ) ) {
			GSM_Integrations::fire_webhook(
				'fault.updated',
				array(
					'id'           => (int) $id,
					'equipment_id' => isset( $existing['equipment_id'] ) ? (int) $existing['equipment_id'] : 0,
					'status'       => isset( $data['status'] ) ? $data['status'] : ( isset( $existing['status'] ) ? $existing['status'] : '' ),
				)
			);
		}
		return $res;
	}

	/**
	 * Delete a fault.
	 *
	 * @param int $id Fault ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$existing = GSM_Faults_Repo::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'gsm_not_found', __( 'Fault not found.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		if ( ! GSM_Faults_Repo::delete( $id ) ) {
			return new WP_Error( 'gsm_db_delete', __( 'Failed to delete fault.', 'gas-safety-manager' ) );
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'delete', 'fault', (int) $id, isset( $existing['description'] ) ? wp_trim_words( $existing['description'], 6 ) : '', 'תקלה נמחקה', $existing );
		}
		return true;
	}

	/**
	 * Get one fault.
	 *
	 * @param int $id Fault ID.
	 * @return array|WP_Error
	 */
	public static function get( $id ) {
		$row = GSM_Faults_Repo::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'gsm_not_found', __( 'Fault not found.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		return $row;
	}

	/**
	 * List faults.
	 *
	 * @param array $args Filters.
	 * @return array { items, total }
	 */
	public static function list( $args = array() ) {
		return array(
			'items' => GSM_Faults_Repo::list( $args ),
			'total' => GSM_Faults_Repo::count( $args ),
		);
	}
}
