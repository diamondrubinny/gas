<?php
/**
 * REST controller: BI / analytics + token-gated data export.
 *
 *   GET  /gas/v1/bi/advanced                         (report) — chart data
 *   GET  /gas/v1/bi/token                            (manage) — current token
 *   POST /gas/v1/bi/token/rotate                     (manage) — new token
 *   GET  /gas/v1/bi/data/{set}?format=csv|json&token= (public, token) — export
 *   GET  /gas/v1/bi/ical?token=                       (public, token) — calendar
 *   GET  /gas/v1/bi/public-token                     (manage) — public dashboard token
 *   POST /gas/v1/bi/public-token/rotate              (manage) — new public token
 *   GET  /gas/v1/bi/public?token=                     (public, PUBLIC token) — aggregates
 *
 * The data/ical endpoints are public but gated by a long random token (like a
 * Google Calendar secret address), for Power BI / Excel / SAP / calendar subs.
 *
 * Two SEPARATE secrets (since 2.13.1):
 *   - gsm_bi_token     : full row-level export (contains personal data). Never
 *                        accepted by /bi/public.
 *   - gsm_public_token : aggregate-only public dashboard. Never accepted by
 *                        /bi/data or /bi/ical, so sharing the dashboard link
 *                        can not leak row-level / personal data.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_BI
 */
class GSM_REST_BI {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/bi/advanced',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'advanced' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/bi/token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_token' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/bi/token/rotate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rotate_token' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/bi/public-token',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_public_token' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/bi/public-token/rotate',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rotate_public_token' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/bi/public-links',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_public_links' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_public_link' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/bi/public-links/(?P<id>[a-f0-9]{12})',
			array(
				'methods'             => WP_REST_Server::DELETABLE,
				'callback'            => array( $this, 'delete_public_link' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/bi/data/(?P<set>equipment|faults|inspections)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export_data' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/bi/ical',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'ical' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/bi/public',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'public_summary' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * GET /bi/public?token=... — aggregate-only public summary (no personal data).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function public_summary( $req ) {
		if ( GSM_Rate_Limit::too_many( 'bi-public', 120, 300 ) ) {
			return GSM_Rate_Limit::error();
		}
		// Aggregate-only endpoint: accepts ONLY the public token or one of the
		// named public links, never the BI export token.
		if ( ! self::token_matches( $req, self::public_token() ) && ! self::public_link_hit( self::provided_token( $req ) ) ) {
			return new WP_Error( 'gsm_bi_token', 'Invalid token', array( 'status' => 403 ) );
		}
		global $wpdb;
		$p = $wpdb->prefix . 'gas_';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total     = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}equipment" );
		$by_status = array();
		foreach ( (array) $wpdb->get_results( "SELECT status, COUNT(*) c FROM {$p}equipment GROUP BY status", ARRAY_A ) as $r ) {
			$by_status[] = array( 'label' => $r['status'] ? $r['status'] : '—', 'count' => (int) $r['c'] );
		}
		$faults_open = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}faults WHERE status IN ('open','in_progress')" );
		$today       = gmdate( 'Y-m-d' );
		$overdue     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}equipment WHERE next_quarterly IS NOT NULL AND next_quarterly <> '' AND next_quarterly < %s", $today ) );
		$since30     = gmdate( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );
		$scanned     = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT equipment_id) FROM {$p}scans WHERE scanned_at >= %s", $since30 ) );
		// phpcs:enable
		return rest_ensure_response(
			array(
				'org'                => get_bloginfo( 'name' ),
				'generated'          => current_time( 'mysql' ),
				'total_equipment'    => $total,
				'by_status'          => $by_status,
				'faults_open'        => $faults_open,
				'quarterly_overdue'  => $overdue,
				'scan_coverage_pct'  => $total > 0 ? (int) round( 100 * $scanned / $total ) : 0,
			)
		);
	}

	/**
	 * Get or create the BI token.
	 *
	 * @return string
	 */
	public static function token() {
		$t = (string) get_option( 'gsm_bi_token', '' );
		if ( '' === $t ) {
			$t = bin2hex( random_bytes( 16 ) );
			update_option( 'gsm_bi_token', $t );
		}
		return $t;
	}

	/**
	 * Get or create the public-dashboard token (separate from the BI export token).
	 *
	 * @return string
	 */
	public static function public_token() {
		$t = (string) get_option( 'gsm_public_token', '' );
		if ( '' === $t ) {
			$t = bin2hex( random_bytes( 16 ) );
			update_option( 'gsm_public_token', $t, false );
		}
		return $t;
	}

	// ---- Named public links (several shareable dashboard links) -------------

	const LINKS_OPTION = 'gsm_public_links';
	const MAX_LINKS    = 20;

	/**
	 * Stored links: [ { id, label, token, created_at, created_by, views, last_view } ].
	 *
	 * @return array
	 */
	public static function public_links() {
		$l = get_option( self::LINKS_OPTION, array() );
		return is_array( $l ) ? array_values( $l ) : array();
	}

	/**
	 * Does the token belong to a named link? Counts the view (a counter and
	 * the last time only — no IP address or other visitor data is kept).
	 *
	 * @param string $token Provided token.
	 * @return bool
	 */
	private static function public_link_hit( $token ) {
		if ( ! is_string( $token ) || ! preg_match( '/^[a-f0-9]{32}$/', $token ) ) {
			return false;
		}
		$links = self::public_links();
		$found = false;
		foreach ( $links as $i => $l ) {
			// Compare every link in constant time; no early exit.
			if ( isset( $l['token'] ) && hash_equals( (string) $l['token'], $token ) ) {
				$links[ $i ]['views']     = ( isset( $l['views'] ) ? (int) $l['views'] : 0 ) + 1;
				$links[ $i ]['last_view'] = current_time( 'mysql' );
				$found                    = true;
			}
		}
		if ( $found ) {
			update_option( self::LINKS_OPTION, $links, false );
		}
		return $found;
	}

	/**
	 * GET /bi/public-links
	 *
	 * @return WP_REST_Response
	 */
	public function list_public_links() {
		return rest_ensure_response( array( 'links' => self::public_links(), 'max' => self::MAX_LINKS ) );
	}

	/**
	 * POST /bi/public-links { label }
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_public_link( $req ) {
		$b     = (array) $req->get_json_params();
		$label = isset( $b['label'] ) && is_string( $b['label'] ) ? trim( sanitize_text_field( $b['label'] ) ) : '';
		$label = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 80 ) : substr( $label, 0, 80 );
		if ( '' === $label ) {
			return new WP_Error( 'gsm_validation', __( 'יש לתת שם לקישור (למשל: למי הוא נשלח).', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$links = self::public_links();
		if ( count( $links ) >= self::MAX_LINKS ) {
			return new WP_Error( 'gsm_validation', __( 'הגעתם למספר הקישורים המרבי. בטלו קישור שאינו בשימוש.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$u    = wp_get_current_user();
		$link = array(
			'id'         => bin2hex( random_bytes( 6 ) ),
			'label'      => $label,
			'token'      => bin2hex( random_bytes( 16 ) ),
			'created_at' => current_time( 'mysql' ),
			'created_by' => $u && $u->ID ? $u->display_name : '',
			'views'      => 0,
			'last_view'  => '',
		);
		$links[] = $link;
		update_option( self::LINKS_OPTION, $links, false );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'create', 'system', 0, $label, 'נוצר קישור ללוח המצב הציבורי' );
		}
		return rest_ensure_response( $link );
	}

	/**
	 * DELETE /bi/public-links/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_public_link( $req ) {
		$id    = (string) $req['id'];
		$links = self::public_links();
		$keep  = array();
		$gone  = null;
		foreach ( $links as $l ) {
			if ( isset( $l['id'] ) && hash_equals( (string) $l['id'], $id ) ) {
				$gone = $l;
			} else {
				$keep[] = $l;
			}
		}
		if ( ! $gone ) {
			return new WP_Error( 'gsm_not_found', __( 'הקישור לא נמצא.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		update_option( self::LINKS_OPTION, $keep, false );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'delete', 'system', 0, isset( $gone['label'] ) ? $gone['label'] : '', 'בוטל קישור ללוח המצב הציבורי' );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * GET /bi/public-token
	 *
	 * @return WP_REST_Response
	 */
	public function get_public_token() {
		return rest_ensure_response( array( 'token' => self::public_token() ) );
	}

	/**
	 * POST /bi/public-token/rotate
	 *
	 * @return WP_REST_Response
	 */
	public function rotate_public_token() {
		$t = bin2hex( random_bytes( 16 ) );
		update_option( 'gsm_public_token', $t, false );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'system', 0, '', 'הוחלף טוקן לוח המצב הציבורי' );
		}
		return rest_ensure_response( array( 'token' => $t ) );
	}

	/**
	 * GET /bi/token
	 *
	 * @return WP_REST_Response
	 */
	public function get_token() {
		return rest_ensure_response( array( 'token' => self::token() ) );
	}

	/**
	 * POST /bi/token/rotate
	 *
	 * @return WP_REST_Response
	 */
	public function rotate_token() {
		$t = bin2hex( random_bytes( 16 ) );
		update_option( 'gsm_bi_token', $t );
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'system', 0, '', 'הוחלף טוקן ה-BI (קישורי ייצוא)' );
		}
		return rest_ensure_response( array( 'token' => $t ) );
	}

	/**
	 * Verify the token query param.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return bool
	 */
	private function check_token( $req ) {
		return self::token_matches( $req, self::token() );
	}

	/**
	 * Constant-time compare of the request's token param against an expected secret.
	 *
	 * @param WP_REST_Request $req      Request.
	 * @param string          $expected Expected token.
	 * @return bool
	 */
	private static function token_matches( $req, $expected ) {
		$provided = self::provided_token( $req );
		if ( '' === $provided || '' === (string) $expected ) {
			return false;
		}
		return hash_equals( (string) $expected, $provided );
	}

	/**
	 * The token a client sent. The X-GSM-Token header is preferred: a header is
	 * not written to web-server / proxy access logs and never leaks through a
	 * Referer, unlike a query string. ?token= stays supported for clients that
	 * cannot send headers (calendar subscriptions) and for existing links.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return string
	 */
	private static function provided_token( $req ) {
		$h = $req->get_header( 'x_gsm_token' );
		if ( is_string( $h ) && '' !== trim( $h ) ) {
			return trim( $h );
		}
		$q = $req->get_param( 'token' );
		return is_string( $q ) ? $q : '';
	}

	/**
	 * Headers for token-gated raw exports: no caching, no indexing, no framing.
	 * No CORS header on purpose — Power BI / Excel / calendar clients fetch
	 * server-side and do not need it; omitting it stops arbitrary web pages
	 * from reading the data in a browser.
	 */
	private static function export_headers() {
		header( 'Cache-Control: no-store, max-age=0' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		header( 'X-Content-Type-Options: nosniff' );
		header( 'Referrer-Policy: no-referrer' );
	}

	/**
	 * GET /bi/advanced — breakdowns for charts.
	 *
	 * @return WP_REST_Response
	 */
	public function advanced() {
		$items = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
		$status = array();
		$gas    = array();
		$bld    = array();
		$pis    = array();
		$today  = current_time( 'Y-m-d' );
		$overdue = 0;
		$status_labels = array( 'active' => 'פעיל', 'maintenance' => 'בתחזוקה', 'faulty' => 'תקול', 'inactive' => 'לא פעיל', '' => 'לא מוגדר' );

		foreach ( (array) $items as $e ) {
			$s = isset( $e['status'] ) ? $e['status'] : '';
			$sl = isset( $status_labels[ $s ] ) ? $status_labels[ $s ] : ( $s ? $s : 'לא מוגדר' );
			$status[ $sl ] = ( isset( $status[ $sl ] ) ? $status[ $sl ] : 0 ) + 1;

			$g = trim( (string) ( isset( $e['gas_type'] ) ? $e['gas_type'] : '' ) );
			if ( '' === $g ) { $g = 'לא מוגדר'; }
			$gas[ $g ] = ( isset( $gas[ $g ] ) ? $gas[ $g ] : 0 ) + 1;

			$b = trim( (string) ( isset( $e['building'] ) ? $e['building'] : '' ) );
			if ( '' === $b ) { $b = 'לא מוגדר'; }
			$bld[ $b ] = ( isset( $bld[ $b ] ) ? $bld[ $b ] : 0 ) + 1;

			$p = trim( (string) ( isset( $e['pi_name'] ) ? $e['pi_name'] : '' ) );
			if ( '' !== $p ) {
				$pis[ $p ] = ( isset( $pis[ $p ] ) ? $pis[ $p ] : 0 ) + 1;
			}

			$nq = isset( $e['next_quarterly'] ) ? (string) $e['next_quarterly'] : '';
			if ( '' !== $nq && $nq < $today ) {
				$overdue++;
			}
		}
		arsort( $pis );
		$top_pis = array_slice( self::to_pairs( $pis ), 0, 10 );

		return rest_ensure_response(
			array(
				'total'             => count( (array) $items ),
				'overdueQuarterly'  => $overdue,
				'statusBreakdown'   => self::to_pairs( $status ),
				'gasBreakdown'      => self::to_pairs( $gas ),
				'buildingBreakdown' => self::to_pairs( $bld ),
				'topPIs'            => $top_pis,
			)
		);
	}

	/**
	 * Map {label:count} → [[label,count],…].
	 *
	 * @param array $m Map.
	 * @return array
	 */
	private static function to_pairs( $m ) {
		$out = array();
		foreach ( $m as $k => $v ) {
			$out[] = array( (string) $k, (int) $v );
		}
		return $out;
	}

	/**
	 * GET /bi/data/{set} — token-gated CSV/JSON export (raw output).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_Error|void
	 */
	public function export_data( $req ) {
		if ( GSM_Rate_Limit::too_many( 'bi-data', 60, 300 ) ) {
			return GSM_Rate_Limit::error();
		}
		if ( ! $this->check_token( $req ) ) {
			return new WP_Error( 'gsm_bi_token', 'Invalid token', array( 'status' => 403 ) );
		}
		$set    = (string) $req['set'];
		$format = strtolower( (string) $req->get_param( 'format' ) );
		$format = in_array( $format, array( 'json', 'sap' ), true ) ? $format : 'csv';
		// SAP-friendly CSV (as in the old system): semicolon separator,
		// UTF-8 BOM, CRLF — loads in SAP GUI / LSMW / CPI.
		$sep = 'sap' === $format ? ';' : ',';

		$names = $this->equipment_names();
		$rows  = array();
		if ( 'equipment' === $set ) {
			$rows = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
			foreach ( $rows as &$r ) { unset( $r['qr_token'] ); }
			unset( $r );
		} elseif ( 'faults' === $set ) {
			$rows = GSM_Faults_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
			foreach ( $rows as &$r ) { $r['equipment_name'] = isset( $names[ (int) $r['equipment_id'] ] ) ? $names[ (int) $r['equipment_id'] ] : ''; }
			unset( $r );
		} else {
			$rows = GSM_Inspections_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
			foreach ( $rows as &$r ) { $r['equipment_name'] = isset( $names[ (int) $r['equipment_id'] ] ) ? $names[ (int) $r['equipment_id'] ] : ''; }
			unset( $r );
		}

		self::export_headers();
		if ( 'json' === $format ) {
			header( 'Content-Type: application/json; charset=utf-8' );
			echo wp_json_encode( array_values( $rows ), JSON_UNESCAPED_UNICODE );
			exit;
		}

		// CSV (UTF-8 BOM).
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="gas-' . $set . ( 'sap' === $format ? '-sap' : '' ) . '.csv"' );
		echo "\xEF\xBB\xBF";
		if ( ! empty( $rows ) ) {
			$cols = array_keys( $rows[0] );
			echo self::csv_line( $cols, $sep ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw text/csv body (nosniff), cells neutralized by GSM_Security::csv_cell().
			foreach ( $rows as $row ) {
				$line = array();
				foreach ( $cols as $c ) { $line[] = isset( $row[ $c ] ) ? $row[ $c ] : ''; }
				echo self::csv_line( $line, $sep ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw text/csv body (nosniff), cells neutralized by GSM_Security::csv_cell().
			}
		}
		exit;
	}

	/**
	 * GET /bi/ical — token-gated calendar of upcoming quarterly inspections.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_Error|void
	 */
	public function ical( $req ) {
		if ( GSM_Rate_Limit::too_many( 'bi-ical', 60, 300 ) ) {
			return GSM_Rate_Limit::error();
		}
		if ( ! $this->check_token( $req ) ) {
			return new WP_Error( 'gsm_bi_token', 'Invalid token', array( 'status' => 403 ) );
		}
		$items = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
		$lines = array( 'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Gas Safety//HE', 'CALSCALE:GREGORIAN' );
		foreach ( (array) $items as $e ) {
			$nq = isset( $e['next_quarterly'] ) ? (string) $e['next_quarterly'] : '';
			if ( '' === $nq ) { continue; }
			$d = str_replace( '-', '', substr( $nq, 0, 10 ) );
			if ( strlen( $d ) !== 8 ) { continue; }
			$uid = 'gsm-' . (int) $e['id'] . '@gas-safety';
			$sum = 'ביקורת רבעונית: ' . ( isset( $e['name'] ) ? $e['name'] : '' );
			$lines[] = 'BEGIN:VEVENT';
			$lines[] = 'UID:' . $uid;
			$lines[] = 'DTSTART;VALUE=DATE:' . $d;
			$lines[] = 'SUMMARY:' . self::ics_esc( $sum );
			$lines[] = 'DESCRIPTION:' . self::ics_esc( 'בניין ' . ( isset( $e['building'] ) ? $e['building'] : '' ) );
			$lines[] = 'END:VEVENT';
		}
		$lines[] = 'END:VCALENDAR';
		header( 'Content-Type: text/calendar; charset=utf-8' );
		header( 'Content-Disposition: inline; filename="gas-inspections.ics"' );
		self::export_headers();
		echo implode( "\r\n", $lines ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- raw text/calendar body, values escaped by ics_esc().
		exit;
	}

	/**
	 * Equipment id → "asset — name".
	 *
	 * @return array
	 */
	private function equipment_names() {
		$names = array();
		$rows  = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
		foreach ( (array) $rows as $e ) {
			$code = ! empty( $e['asset_code'] ) ? ( $e['asset_code'] . ' — ' ) : '';
			$names[ (int) $e['id'] ] = $code . ( isset( $e['name'] ) ? $e['name'] : '' );
		}
		return $names;
	}

	/**
	 * One CSV line.
	 *
	 * @param array $cells Cells.
	 * @return string
	 */
	private static function csv_line( $cells, $sep = ',' ) {
		$out = array();
		foreach ( $cells as $c ) {
			$v = GSM_Security::csv_cell( $c );
			$v = str_replace( '"', '""', $v );
			$out[] = '"' . $v . '"';
		}
		return implode( $sep, $out ) . "\r\n";
	}

	/**
	 * Escape an iCal text value.
	 *
	 * @param string $s Value.
	 * @return string
	 */
	private static function ics_esc( $s ) {
		return str_replace( array( '\\', ';', ',', "\n" ), array( '\\\\', '\\;', '\\,', '\\n' ), (string) $s );
	}
}
