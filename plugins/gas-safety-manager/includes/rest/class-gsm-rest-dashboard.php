<?php
/**
 * Dashboard service + REST controller.
 *
 * Computes the stats and the quarterly-deadline list. Crucially, this is
 * where the logic that plagued the Monday system is now correct BY DESIGN:
 *   - Only quarterly inspections count toward the quarterly timer
 *     (WHERE type='quarterly') — no notes-tag parsing.
 *   - Open defects come straight from inspection_items (item_result='defect').
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Dashboard_Service
 */
class GSM_Dashboard_Service {

	/**
	 * Build the full dashboard payload.
	 *
	 * @return array
	 */
	public static function build() {
		return array(
			'stats'     => self::stats(),
			'coverage'  => self::coverage(),
			'deadlines' => self::quarterly_deadlines(),
		);
	}

	/**
	 * Headline counts.
	 *
	 * @return array
	 */
	public static function stats() {
		global $wpdb;
		$p = $wpdb->prefix . 'gas_';

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$equipment    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}equipment" );
		$inspections  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}inspections" );
		$faults_open  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}faults WHERE status IN ('open','in_progress')" );
		$open_defects = GSM_Inspections_Repo::count_open_defects( 0 );

		// Quarterly inspections due within threshold (default 90 days).
		$threshold    = (int) get_option( 'gsm_quarterly_days', 90 );
		$soon         = gmdate( 'Y-m-d', time() + ( 14 * DAY_IN_SECONDS ) );
		$quarterly_due = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$p}equipment WHERE next_quarterly IS NOT NULL AND next_quarterly <= %s",
				$soon
			)
		);
		// phpcs:enable

		return array(
			'equipment'     => $equipment,
			'inspections'   => $inspections,
			'faults_open'   => $faults_open,
			'open_defects'  => $open_defects,
			'quarterly_due' => $quarterly_due,
			'threshold'     => $threshold,
		);
	}

	/**
	 * Scan coverage + monthly-inspection overdue + a 14-day scan trend.
	 *
	 * @return array
	 */
	public static function coverage() {
		global $wpdb;
		$p = $wpdb->prefix . 'gas_';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}equipment" );

		// Distinct equipment scanned in the last 30 days.
		$since30   = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$scanned30 = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT equipment_id) FROM {$p}scans WHERE scanned_at >= %s", $since30 ) );

		// Monthly overdue: equipment with NO monthly inspection inside the
		// configured monthly cadence (default 30d). Never-inspected counts too.
		$cfg          = get_option( 'gsm_inspection_config', array() );
		$monthly_days = ( is_array( $cfg ) && isset( $cfg['rules']['monthlyEveryDays'] ) ) ? max( 1, (int) $cfg['rules']['monthlyEveryDays'] ) : 30;
		$cutoff       = gmdate( 'Y-m-d', time() - $monthly_days * DAY_IN_SECONDS );
		$monthly_ok   = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT equipment_id) FROM {$p}inspections WHERE type = 'monthly' AND inspection_date >= %s", $cutoff ) );
		$monthly_overdue = max( 0, $total - $monthly_ok );

		// 14-day scan trend (scans per calendar day).
		$since14 = gmdate( 'Y-m-d 00:00:00', time() - 13 * DAY_IN_SECONDS );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT DATE(scanned_at) d, COUNT(*) c FROM {$p}scans WHERE scanned_at >= %s GROUP BY DATE(scanned_at)", $since14 ), ARRAY_A );
		// phpcs:enable
		$by = array();
		foreach ( (array) $rows as $r ) {
			$by[ $r['d'] ] = (int) $r['c'];
		}
		$trend = array();
		for ( $i = 13; $i >= 0; $i-- ) {
			$day     = gmdate( 'Y-m-d', time() - $i * DAY_IN_SECONDS );
			$trend[] = array( 'date' => $day, 'count' => isset( $by[ $day ] ) ? $by[ $day ] : 0 );
		}

		return array(
			'total'           => $total,
			'scanned_30d'     => $scanned30,
			'not_scanned_30d' => max( 0, $total - $scanned30 ),
			'coverage_pct'    => $total > 0 ? (int) round( 100 * $scanned30 / $total ) : 0,
			'monthly_days'    => $monthly_days,
			'monthly_overdue' => $monthly_overdue,
			'trend_14d'       => $trend,
		);
	}

	/**
	 * Per-equipment quarterly deadline list, with open-defect counts.
	 *
	 * @return array
	 */
	public static function quarterly_deadlines() {
		global $wpdb;
		$p         = $wpdb->prefix . 'gas_';
		$threshold = (int) get_option( 'gsm_quarterly_days', 90 );
		$today     = time();

		// Equipment that has a next_quarterly date, soonest first.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			"SELECT id, name, last_quarterly, next_quarterly FROM {$p}equipment
			 WHERE next_quarterly IS NOT NULL AND next_quarterly <> ''
			 ORDER BY next_quarterly ASC LIMIT 100",
			ARRAY_A
		);
		// phpcs:enable

		if ( ! $rows ) {
			return array();
		}

		$out = array();
		foreach ( $rows as $r ) {
			$next = strtotime( $r['next_quarterly'] );
			if ( ! $next ) {
				continue;
			}
			$days_left    = (int) floor( ( $next - $today ) / DAY_IN_SECONDS );
			$open_defects = GSM_Inspections_Repo::count_open_defects( (int) $r['id'] );

			$out[] = array(
				'id'             => (int) $r['id'],
				'name'           => $r['name'],
				'last_quarterly' => $r['last_quarterly'],
				'next_quarterly' => $r['next_quarterly'],
				'days_left'      => $days_left,
				'open_defects'   => $open_defects,
			);
		}
		return $out;
	}
}

/**
 * Class GSM_REST_Dashboard
 */
class GSM_REST_Dashboard {

	const NS = 'gas/v1';

	/**
	 * Register dashboard route.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/dashboard',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_dashboard' ),
				'permission_callback' => array( 'GSM_Auth', 'require_report' ),
			)
		);
	}

	/**
	 * GET /dashboard
	 *
	 * @return WP_REST_Response
	 */
	public function get_dashboard() {
		return rest_ensure_response( GSM_Dashboard_Service::build() );
	}
}
