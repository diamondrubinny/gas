<?php
/**
 * Offline data importer.
 *
 * Imports equipment (and, when present, inspections + faults) from a decoded
 * JSON structure produced by the portal export. This is the OFFLINE path:
 * used when the live browser-Console migration can't reach WordPress (e.g. the
 * network blocks outside access). The admin uploads a .json file and this
 * class upserts everything server-side, with no external network.
 *
 * All writes go through the service layer, so sanitization, validation,
 * asset_code allocation (Monday number preserved) and qr_token generation all
 * apply exactly as they do for a live migration.
 *
 * Accepted shapes:
 *   - A bare array of equipment rows.
 *   - An object: { "equipment": [...], "inspections": [...], "faults": [...] }
 *
 * Linking inspections/faults to equipment (the portal only exposes the linked
 * equipment NAME, not its Monday id): by equipment_monday_id when present,
 * otherwise by exact equipment_name. Import equipment first so names resolve.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Importer
 */
class GSM_Importer {

	const MAX_ROWS = 20000;

	/**
	 * Import a decoded JSON structure.
	 *
	 * @param mixed $data Decoded JSON (array or associative array).
	 * @return array|WP_Error Per-section counts, or WP_Error on a fatal issue.
	 */
	public static function import_array( $data ) {
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'gsm_import_bad', __( 'הקובץ אינו JSON תקין (צפוי מערך או אובייקט).', 'gas-safety-manager' ) );
		}

		// Normalize into three lists.
		$equipment   = array();
		$inspections = array();
		$faults      = array();

		// A bare list (numeric keys) is treated as an equipment list.
		$is_list = array_keys( $data ) === range( 0, count( $data ) - 1 );
		if ( $is_list ) {
			$equipment = $data;
		} else {
			$equipment   = ( isset( $data['equipment'] ) && is_array( $data['equipment'] ) ) ? $data['equipment'] : array();
			$inspections = ( isset( $data['inspections'] ) && is_array( $data['inspections'] ) ) ? $data['inspections'] : array();
			$faults      = ( isset( $data['faults'] ) && is_array( $data['faults'] ) ) ? $data['faults'] : array();
		}

		$total = count( $equipment ) + count( $inspections ) + count( $faults );
		if ( $total > self::MAX_ROWS ) {
			return new WP_Error(
				'gsm_import_too_big',
				/* translators: %d: max rows */
				sprintf( __( 'הקובץ גדול מדי (%d רשומות, מקסימום %d). פצל לקבצים קטנים יותר.', 'gas-safety-manager' ), (int) $total, self::MAX_ROWS )
			);
		}

		$result = array(
			'equipment'   => self::blank(),
			'inspections' => self::blank(),
			'faults'      => self::blank(),
		);

		// --- 1) Equipment: upsert by monday_id -----------------------------
		foreach ( $equipment as $i => $raw ) {
			if ( ! is_array( $raw ) ) {
				$result['equipment']['skipped']++;
				continue;
			}
			$mid      = isset( $raw['monday_id'] ) ? (string) $raw['monday_id'] : '';
			$existing = ( '' !== $mid ) ? GSM_Equipment_Repo::get_by_monday_id( $mid ) : null;

			if ( $existing ) {
				$res = GSM_Equipment_Service::update( (int) $existing['id'], $raw );
				if ( is_wp_error( $res ) ) {
					self::add_err( $result['equipment'], $i, $mid, $res );
				} else {
					$result['equipment']['updated']++;
				}
			} else {
				$res = GSM_Equipment_Service::create( $raw );
				if ( is_wp_error( $res ) ) {
					self::add_err( $result['equipment'], $i, $mid, $res );
				} else {
					$result['equipment']['created']++;
				}
			}
		}

		// --- Build equipment lookup maps -----------------------------------
		$by_name = array();
		$by_mid  = array();
		$all     = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
		foreach ( (array) $all as $e ) {
			if ( ! empty( $e['name'] ) && ! isset( $by_name[ $e['name'] ] ) ) {
				$by_name[ $e['name'] ] = (int) $e['id'];
			}
			if ( ! empty( $e['monday_id'] ) ) {
				$by_mid[ (string) $e['monday_id'] ] = (int) $e['id'];
			}
		}

		// --- 2) Inspections: upsert by monday_id ---------------------------
		foreach ( $inspections as $i => $raw ) {
			if ( ! is_array( $raw ) ) {
				$result['inspections']['skipped']++;
				continue;
			}
			$eid = self::resolve_equipment( $raw, $by_mid, $by_name );
			if ( ! $eid ) {
				$result['inspections']['skipped']++;
				self::add_note( $result['inspections'], $i, __( 'לא נמצא ציוד תואם', 'gas-safety-manager' ) . ': ' . self::eq_label( $raw ) );
				continue;
			}
			$raw['equipment_id'] = $eid;
			$mid      = isset( $raw['monday_id'] ) ? (string) $raw['monday_id'] : '';
			$existing = ( '' !== $mid ) ? GSM_Inspections_Repo::get_by_monday_id( $mid ) : null;

			if ( $existing ) {
				$res = GSM_Inspections_Service::update( (int) $existing['id'], $raw );
				if ( is_wp_error( $res ) ) {
					self::add_err( $result['inspections'], $i, $mid, $res );
				} else {
					$result['inspections']['updated']++;
				}
			} else {
				$res = GSM_Inspections_Service::create( $raw );
				if ( is_wp_error( $res ) ) {
					self::add_err( $result['inspections'], $i, $mid, $res );
				} else {
					$result['inspections']['created']++;
				}
			}
		}

		// --- 3) Faults: upsert by monday_id --------------------------------
		foreach ( $faults as $i => $raw ) {
			if ( ! is_array( $raw ) ) {
				$result['faults']['skipped']++;
				continue;
			}
			$eid = self::resolve_equipment( $raw, $by_mid, $by_name );
			if ( ! $eid ) {
				$result['faults']['skipped']++;
				self::add_note( $result['faults'], $i, __( 'לא נמצא ציוד תואם', 'gas-safety-manager' ) . ': ' . self::eq_label( $raw ) );
				continue;
			}
			$raw['equipment_id'] = $eid;
			$mid      = isset( $raw['monday_id'] ) ? (string) $raw['monday_id'] : '';
			$existing = ( '' !== $mid ) ? GSM_Faults_Repo::get_by_monday_id( $mid ) : null;

			if ( $existing ) {
				$res = GSM_Faults_Service::update( (int) $existing['id'], $raw );
				if ( is_wp_error( $res ) ) {
					self::add_err( $result['faults'], $i, $mid, $res );
				} else {
					$result['faults']['updated']++;
				}
			} else {
				$res = GSM_Faults_Service::create( $raw );
				if ( is_wp_error( $res ) ) {
					self::add_err( $result['faults'], $i, $mid, $res );
				} else {
					$result['faults']['created']++;
				}
			}
		}

		return $result;
	}

	/**
	 * Resolve an equipment DB id from a child row (inspection/fault).
	 *
	 * @param array $row     Child row.
	 * @param array $by_mid  monday_id => id map.
	 * @param array $by_name name => id map.
	 * @return int Equipment id, or 0 if unresolved.
	 */
	private static function resolve_equipment( $row, $by_mid, $by_name ) {
		$emid = isset( $row['equipment_monday_id'] ) ? (string) $row['equipment_monday_id'] : '';
		if ( '' !== $emid && isset( $by_mid[ $emid ] ) ) {
			return (int) $by_mid[ $emid ];
		}
		$ename = isset( $row['equipment_name'] ) ? (string) $row['equipment_name'] : '';
		if ( '' !== $ename && isset( $by_name[ $ename ] ) ) {
			return (int) $by_name[ $ename ];
		}
		return 0;
	}

	/**
	 * Label for a child row's intended equipment (for error messages).
	 *
	 * @param array $row Child row.
	 * @return string
	 */
	private static function eq_label( $row ) {
		if ( ! empty( $row['equipment_name'] ) ) {
			return (string) $row['equipment_name'];
		}
		if ( ! empty( $row['equipment_monday_id'] ) ) {
			return '#' . (string) $row['equipment_monday_id'];
		}
		return '—';
	}

	/**
	 * Blank per-section counter.
	 *
	 * @return array
	 */
	private static function blank() {
		return array( 'created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => array() );
	}

	/**
	 * Record a WP_Error against a section (capped list).
	 *
	 * @param array    $section Section counters (by reference).
	 * @param int      $i       Row index.
	 * @param string   $mid     Monday id.
	 * @param WP_Error $err     Error.
	 * @return void
	 */
	private static function add_err( &$section, $i, $mid, $err ) {
		if ( count( $section['errors'] ) < 50 ) {
			$section['errors'][] = array(
				'index'     => (int) $i,
				'monday_id' => (string) $mid,
				'error'     => $err->get_error_message(),
			);
		}
	}

	/**
	 * Record a plain note against a section (capped list).
	 *
	 * @param array  $section Section counters (by reference).
	 * @param int    $i       Row index.
	 * @param string $note    Note text.
	 * @return void
	 */
	private static function add_note( &$section, $i, $note ) {
		if ( count( $section['errors'] ) < 50 ) {
			$section['errors'][] = array(
				'index' => (int) $i,
				'error' => (string) $note,
			);
		}
	}
}
