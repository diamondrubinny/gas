<?php
/**
 * Inspections service — business logic.
 *
 * Handles inspection validation plus checklist item sanitization. The
 * inspection TYPE (quarterly/monthly/adhoc) is a real column here — no more
 * parsing it out of a notes tag like the Monday system did.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Inspections_Service
 */
class GSM_Inspections_Service {

	/**
	 * Allowed inspection types.
	 *
	 * @return string[]
	 */
	public static function allowed_types() {
		return array( 'quarterly', 'monthly', 'adhoc' );
	}

	/**
	 * Allowed overall results.
	 *
	 * @return string[]
	 */
	public static function allowed_results() {
		return array( 'ok', 'defects' );
	}

	/**
	 * Allowed per-item results.
	 *
	 * @return string[]
	 */
	public static function allowed_item_results() {
		return array( 'ok', 'defect', 'na' );
	}

	/**
	 * Sanitize inspection fields.
	 *
	 * @param array $input Raw.
	 * @return array
	 */
	public static function sanitize( $input ) {
		$clean = array();

		if ( isset( $input['equipment_id'] ) ) {
			$clean['equipment_id'] = (int) $input['equipment_id'];
		}
		foreach ( array( 'type', 'inspector', 'inspector_email', 'pi_name', 'panel_number', 'result', 'monday_id' ) as $f ) {
			if ( isset( $input[ $f ] ) ) {
				$clean[ $f ] = sanitize_text_field( wp_unslash( $input[ $f ] ) );
			}
		}
		if ( isset( $input['inspector_email'] ) ) {
			$clean['inspector_email'] = sanitize_email( wp_unslash( $input['inspector_email'] ) );
		}
		if ( isset( $input['notes'] ) ) {
			$clean['notes'] = sanitize_textarea_field( wp_unslash( $input['notes'] ) );
		}
		if ( isset( $input['inspection_date'] ) ) {
			$clean['inspection_date'] = self::valid_date( $input['inspection_date'] ) ? $input['inspection_date'] : null;
		}

		return $clean;
	}

	/**
	 * Sanitize checklist items array.
	 *
	 * @param array $items Raw items.
	 * @return array Clean items.
	 */
	public static function sanitize_items( $items ) {
		if ( ! is_array( $items ) ) {
			return array();
		}
		$clean = array();
		foreach ( $items as $idx => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$result = isset( $item['item_result'] ) ? sanitize_text_field( $item['item_result'] ) : 'ok';
			if ( ! in_array( $result, self::allowed_item_results(), true ) ) {
				$result = 'ok';
			}
			$clean[] = array(
				'item_index'  => isset( $item['item_index'] ) ? (int) $item['item_index'] : (int) $idx,
				'item_text'   => isset( $item['item_text'] ) ? sanitize_textarea_field( wp_unslash( $item['item_text'] ) ) : '',
				'item_result' => $result,
			);
		}
		return $clean;
	}

	/**
	 * Validate a date.
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
	 * Validate inspection rules.
	 *
	 * @param array $data      Clean data.
	 * @param bool  $is_create Whether creating.
	 * @return true|WP_Error
	 */
	public static function validate( $data, $is_create = true ) {
		if ( $is_create && empty( $data['equipment_id'] ) ) {
			return new WP_Error( 'gsm_validation', __( 'Inspection must reference an equipment item.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['type'] ) && '' !== $data['type'] && ! in_array( $data['type'], self::allowed_types(), true ) ) {
			return new WP_Error( 'gsm_validation', __( 'Invalid inspection type.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( isset( $data['result'] ) && '' !== $data['result'] && ! in_array( $data['result'], self::allowed_results(), true ) ) {
			return new WP_Error( 'gsm_validation', __( 'Invalid inspection result.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		return true;
	}

	/**
	 * Create an inspection with checklist items.
	 *
	 * @param array $input Raw input (may include 'items').
	 * @return int|WP_Error
	 */
	public static function create( $input ) {
		$data  = self::sanitize( $input );
		$items = self::sanitize_items( isset( $input['items'] ) ? $input['items'] : array() );

		if ( empty( $data['type'] ) ) {
			$data['type'] = 'adhoc';
		}
		if ( empty( $data['inspection_date'] ) ) {
			$data['inspection_date'] = current_time( 'Y-m-d' );
		}
		// Derive overall result from items if not explicitly set.
		if ( empty( $data['result'] ) ) {
			$has_defect     = false;
			foreach ( $items as $it ) {
				if ( 'defect' === $it['item_result'] ) {
					$has_defect = true;
					break;
				}
			}
			$data['result'] = $has_defect ? 'defects' : 'ok';
		}

		$valid = self::validate( $data, true );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}

		$id = GSM_Inspections_Repo::insert( $data, $items );
		if ( is_wp_error( $id ) ) {
			return $id;
		}

		// A completed QUARTERLY inspection advances the equipment's quarterly
		// dates (last = inspection date, next = + cadence). This is what resets
		// the dashboard deadline. Monthly/adhoc do NOT touch quarterly dates
		// (mirrors the old Worker's recordInspectionDates).
		if ( 'quarterly' === $data['type'] && ! empty( $data['equipment_id'] ) ) {
			self::advance_equipment_dates( (int) $data['equipment_id'], $data['inspection_date'] );
		}

		if ( class_exists( 'GSM_Audit' ) ) {
			$tl = array( 'quarterly' => 'רבעונית', 'monthly' => 'חודשית', 'adhoc' => 'אד-הוק' );
			$t  = isset( $tl[ $data['type'] ] ) ? $tl[ $data['type'] ] : $data['type'];
			GSM_Audit::log( 'create', 'inspection', (int) $id, 'ציוד #' . (int) $data['equipment_id'], 'בוצעה ביקורת ' . $t );
		}

		if ( class_exists( 'GSM_Integrations' ) ) {
			GSM_Integrations::fire_webhook(
				'inspection.created',
				array(
					'id'           => (int) $id,
					'equipment_id' => isset( $data['equipment_id'] ) ? (int) $data['equipment_id'] : 0,
					'type'         => isset( $data['type'] ) ? $data['type'] : '',
					'date'         => isset( $data['inspection_date'] ) ? $data['inspection_date'] : '',
				)
			);
		}

		return $id;
	}

	/**
	 * Set the equipment's last/next quarterly inspection dates after a
	 * quarterly inspection.
	 *
	 * @param int    $equipment_id Equipment ID.
	 * @param string $last_date    Inspection date (Y-m-d).
	 * @return void
	 */
	private static function advance_equipment_dates( $equipment_id, $last_date ) {
		if ( ! class_exists( 'GSM_Equipment_Repo' ) ) {
			return;
		}
		$cfg  = get_option( 'gsm_inspection_config', array() );
		$days = 90;
		if ( is_array( $cfg ) && isset( $cfg['rules']['quarterlyEveryDays'] ) ) {
			$days = max( 1, (int) $cfg['rules']['quarterlyEveryDays'] );
		}
		$ts = $last_date ? strtotime( $last_date ) : false;
		if ( ! $ts ) {
			$ts = current_time( 'timestamp' );
		}
		$next = gmdate( 'Y-m-d', $ts + ( $days * DAY_IN_SECONDS ) );
		$last = gmdate( 'Y-m-d', $ts );
		GSM_Equipment_Repo::update(
			$equipment_id,
			array(
				'last_quarterly' => $last,
				'next_quarterly' => $next,
			)
		);
	}

	/**
	 * Update an inspection (and optionally its items).
	 *
	 * @param int   $id    Inspection ID.
	 * @param array $input Raw input.
	 * @return true|WP_Error
	 */
	public static function update( $id, $input ) {
		$existing = GSM_Inspections_Repo::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'gsm_not_found', __( 'Inspection not found.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$data  = self::sanitize( $input );
		$valid = self::validate( $data, false );
		if ( is_wp_error( $valid ) ) {
			return $valid;
		}
		$res = GSM_Inspections_Repo::update( $id, $data );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		// If items provided, replace them.
		if ( isset( $input['items'] ) ) {
			$items = self::sanitize_items( $input['items'] );
			GSM_Inspections_Repo::replace_items( $id, $items );
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'inspection', (int) $id, 'ציוד #' . (int) ( isset( $existing['equipment_id'] ) ? $existing['equipment_id'] : 0 ), 'עודכנה ביקורת' );
		}
		return true;
	}

	/**
	 * Delete an inspection.
	 *
	 * @param int $id Inspection ID.
	 * @return true|WP_Error
	 */
	public static function delete( $id ) {
		$existing = GSM_Inspections_Repo::get( $id );
		if ( ! $existing ) {
			return new WP_Error( 'gsm_not_found', __( 'Inspection not found.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		if ( ! GSM_Inspections_Repo::delete( $id ) ) {
			return new WP_Error( 'gsm_db_delete', __( 'Failed to delete inspection.', 'gas-safety-manager' ) );
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'delete', 'inspection', (int) $id, 'ציוד #' . (int) ( isset( $existing['equipment_id'] ) ? $existing['equipment_id'] : 0 ), 'ביקורת נמחקה', $existing );
		}
		return true;
	}

	/**
	 * Get one inspection.
	 *
	 * @param int $id Inspection ID.
	 * @return array|WP_Error
	 */
	public static function get( $id ) {
		$row = GSM_Inspections_Repo::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'gsm_not_found', __( 'Inspection not found.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		return $row;
	}

	/**
	 * List inspections.
	 *
	 * @param array $args Filters.
	 * @return array { items, total }
	 */
	public static function list( $args = array() ) {
		return array(
			'items' => GSM_Inspections_Repo::list( $args ),
			'total' => GSM_Inspections_Repo::count( $args ),
		);
	}
}
