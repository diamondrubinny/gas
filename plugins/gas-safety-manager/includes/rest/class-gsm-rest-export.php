<?php
/**
 * REST controller: Excel export (reports).
 *
 * GET /gas/v1/export/{type}  (type = equipment|inspections|faults|scans)
 * Returns { filename, mime, b64 } so the SPA can build a Blob and download —
 * keeps the request authenticated (nonce) with no streaming/header issues.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Export
 */
class GSM_REST_Export {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/export/(?P<type>equipment|inspections|faults|scans|checklists)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => array( 'GSM_Auth', 'require_report' ),
			)
		);
	}

	/**
	 * Build the requested export and return it base64-encoded.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function export( $req ) {
		// Large exports can be memory/time heavy — raise limits defensively so
		// the request can't trip an uncatchable resource fatal on big datasets.
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 120 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}

		$type = (string) $req['type'];

		// Wrap the whole build so any failure comes back as a readable REST
		// error (with the real reason) instead of WordPress's generic
		// "critical error" page, which hides what actually went wrong.
		try {
			switch ( $type ) {
				case 'equipment':
					$sheet = $this->equipment_sheet();
					$label = 'ציוד';
					break;
				case 'inspections':
					$sheet = $this->inspections_sheet();
					$label = 'ביקורות';
					break;
				case 'faults':
					$sheet = $this->faults_sheet();
					$label = 'תקלות';
					break;
				case 'scans':
					$sheet = $this->scans_sheet();
					$label = 'סריקות';
					break;
				case 'checklists':
					$sheet = $this->checklists_sheet();
					$label = 'צ׳ק-ליסטים';
					break;
				default:
					return new WP_Error( 'gsm_export_bad', __( 'Unknown export type.', 'gas-safety-manager' ), array( 'status' => 400 ) );
			}

			$out      = GSM_Xlsx::build( array( $sheet ) );
			$filename = 'דוח-' . $label . '-' . current_time( 'Y-m-d' ) . '.' . $out['ext'];

			return rest_ensure_response(
				array(
					'filename' => $filename,
					'mime'     => $out['mime'],
					'b64'      => base64_encode( $out['data'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				)
			);
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'gsm_export_failed',
				'שגיאת ייצוא: ' . $e->getMessage() . ' [' . basename( $e->getFile() ) . ':' . $e->getLine() . ']',
				array( 'status' => 500 )
			);
		}
	}

	/**
	 * Checklists sheet — both role checklists (monthly + quarterly) in one sheet.
	 * Reads the stored inspection config; no DB tables involved.
	 *
	 * @return array
	 */
	private function checklists_sheet() {
		$cfg    = get_option( 'gsm_inspection_config', array() );
		$lists  = ( is_array( $cfg ) && isset( $cfg['checklists'] ) && is_array( $cfg['checklists'] ) ) ? $cfg['checklists'] : array();
		$groups = array( 'monthly' => 'חודשית', 'quarterly' => 'רבעונית' );
		$rows   = array( array( 'סוג ביקורת', 'מס\'', 'פריט בדיקה' ) );
		foreach ( $groups as $key => $label ) {
			$items = ( isset( $lists[ $key ] ) && is_array( $lists[ $key ] ) ) ? $lists[ $key ] : array();
			$n     = 0;
			foreach ( $items as $it ) {
				$text = is_array( $it ) ? ( isset( $it['text'] ) ? $it['text'] : '' ) : (string) $it;
				$text = trim( (string) $text );
				if ( '' === $text ) {
					continue;
				}
				$n++;
				$rows[] = array( $label, $n, $text );
			}
		}
		return array( 'name' => 'צק-ליסטים', 'rows' => $rows );
	}

	/**
	 * Map of equipment id => display label ("asset_code — name").
	 *
	 * @return array{names:array<int,string>}
	 */
	private function equipment_names() {
		$names = array();
		$rows  = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
		foreach ( (array) $rows as $e ) {
			$code        = ! empty( $e['asset_code'] ) ? ( $e['asset_code'] . ' — ' ) : '';
			$names[ (int) $e['id'] ] = $code . ( isset( $e['name'] ) ? $e['name'] : '' );
		}
		return $names;
	}

	/**
	 * Equipment sheet.
	 *
	 * @return array
	 */
	private function equipment_sheet() {
		$rows = array(
			array( 'מספר', 'שם הציוד', 'סוג גז', 'קטגוריה', 'פקולטה', 'בניין', 'קומה', 'חדר', 'מיקום', 'מיקום פונקציונלי', 'מספר פאנל', 'לחץ עבודה', 'מס\' צילינדרים', 'מלאי צילינדרים', 'GIS', 'חוקר ראשי', 'מנהלי מעבדה', 'אנשי תחזוקה', 'טלפון', 'אנשי קשר', 'סטטוס', 'ביקורת אחרונה', 'ביקורת הבאה', 'הערות', 'Monday ID' ),
		);
		$items = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'name', 'order' => 'ASC' ) );
		foreach ( (array) $items as $e ) {
			$rows[] = array(
				self::v( $e, 'asset_code' ), self::v( $e, 'name' ), self::v( $e, 'gas_type' ), self::v( $e, 'category' ),
				self::v( $e, 'faculty' ), self::v( $e, 'building' ), self::v( $e, 'floor' ), self::v( $e, 'room' ),
				self::v( $e, 'location' ), self::v( $e, 'functional_loc' ), self::v( $e, 'panel_number' ), self::v( $e, 'work_pressure' ),
				self::v( $e, 'cylinders_count' ), self::v( $e, 'cylinders_stock' ), self::v( $e, 'gis' ),
				self::v( $e, 'pi_name' ), self::v( $e, 'lab_managers' ), self::v( $e, 'maint_staff' ),
				self::v( $e, 'phone' ), self::v( $e, 'contacts' ), self::v( $e, 'status' ),
				self::v( $e, 'last_quarterly' ), self::v( $e, 'next_quarterly' ), self::v( $e, 'notes' ), self::v( $e, 'monday_id' ),
			);
		}
		return array( 'name' => 'ציוד', 'rows' => $rows );
	}

	/**
	 * Inspections sheet.
	 *
	 * @return array
	 */
	private function inspections_sheet() {
		$names = $this->equipment_names();
		$types = array( 'quarterly' => 'רבעונית', 'monthly' => 'חודשית', 'adhoc' => 'אד-הוק' );
		$rows  = array(
			array( 'ציוד', 'סוג', 'תאריך', 'מבצע', 'חוקר ראשי', 'תוצאה', 'הערות' ),
		);
		$items = GSM_Inspections_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
		foreach ( (array) $items as $i ) {
			$eid    = (int) ( isset( $i['equipment_id'] ) ? $i['equipment_id'] : 0 );
			$type   = isset( $i['type'] ) ? $i['type'] : '';
			$result = ( isset( $i['result'] ) && 'defects' === $i['result'] ) ? 'ליקויים' : 'תקין';
			$rows[] = array(
				isset( $names[ $eid ] ) ? $names[ $eid ] : ( '#' . $eid ),
				isset( $types[ $type ] ) ? $types[ $type ] : $type,
				self::v( $i, 'inspection_date' ), self::v( $i, 'inspector' ), self::v( $i, 'pi_name' ),
				$result, self::v( $i, 'notes' ),
			);
		}
		return array( 'name' => 'ביקורות', 'rows' => $rows );
	}

	/**
	 * Faults sheet.
	 *
	 * @return array
	 */
	private function faults_sheet() {
		$names = $this->equipment_names();
		$pri   = array( 'low' => 'נמוך', 'normal' => 'רגיל', 'high' => 'גבוה', 'urgent' => 'דחוף' );
		$stat  = array( 'open' => 'פתוח', 'in_progress' => 'בטיפול', 'resolved' => 'טופל', 'closed' => 'סגור' );
		$rows  = array(
			array( 'ציוד', 'תיאור', 'עדיפות', 'סטטוס', 'מדווח', 'נפתחה', 'נסגרה' ),
		);
		$items = GSM_Faults_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
		foreach ( (array) $items as $f ) {
			$eid    = (int) ( isset( $f['equipment_id'] ) ? $f['equipment_id'] : 0 );
			$p      = isset( $f['priority'] ) ? $f['priority'] : '';
			$s      = isset( $f['status'] ) ? $f['status'] : '';
			$rows[] = array(
				isset( $names[ $eid ] ) ? $names[ $eid ] : ( '#' . $eid ),
				self::v( $f, 'description' ),
				isset( $pri[ $p ] ) ? $pri[ $p ] : $p,
				isset( $stat[ $s ] ) ? $stat[ $s ] : $s,
				self::v( $f, 'reporter' ), self::v( $f, 'opened_date' ), self::v( $f, 'resolved_date' ),
			);
		}
		return array( 'name' => 'תקלות', 'rows' => $rows );
	}

	/**
	 * Scans sheet (most recent first, capped).
	 *
	 * @return array
	 */
	private function scans_sheet() {
		global $wpdb;
		$names = $this->equipment_names();
		$table = $wpdb->prefix . 'gas_scans';
		$rows  = array( array( 'ציוד', 'תאריך ושעה' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$scans = $wpdb->get_results( "SELECT equipment_id, scanned_at FROM {$table} ORDER BY scanned_at DESC LIMIT 20000", ARRAY_A );
		foreach ( (array) $scans as $s ) {
			$eid    = (int) ( isset( $s['equipment_id'] ) ? $s['equipment_id'] : 0 );
			$rows[] = array(
				isset( $names[ $eid ] ) ? $names[ $eid ] : ( '#' . $eid ),
				self::v( $s, 'scanned_at' ),
			);
		}
		return array( 'name' => 'סריקות', 'rows' => $rows );
	}

	/**
	 * Safe value getter.
	 *
	 * @param array  $row Row.
	 * @param string $key Key.
	 * @return string
	 */
	private static function v( $row, $key ) {
		return isset( $row[ $key ] ) && null !== $row[ $key ] ? (string) $row[ $key ] : '';
	}
}
