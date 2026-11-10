<?php
/**
 * REST controller: internal database browser ("מסד נתונים פנימי").
 *
 * The WP equivalent of the old portal's generic "board management" tool —
 * but read-oriented by design: browse / search / paginate / export any of the
 * system's own tables. Editing of business records is done through the
 * validated, audited forms (equipment / faults / inspections), never by raw
 * row writes — raw edits would bypass validation and corrupt the audit trail.
 *
 *   GET  /gas/v1/db/tables              (manage) — tables + row counts
 *   GET  /gas/v1/db/table/{key}         (manage) — columns + rows (search/paged)
 *   GET  /gas/v1/db/export/{key}        (manage) — CSV of the whole table
 *
 * Table identifiers come from a hard-coded whitelist, so the table name is
 * never taken from user input.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_DB
 */
class GSM_REST_DB {

	const NS = 'gas/v1';

	/** Columns never returned to the browser or exported (security/privacy). */
	const HIDDEN = array( 'qr_token', 'ip_hash' );

	/** Max characters per cell in the browse view (full value stays in CSV). */
	const CELL_MAX = 300;

	/**
	 * Whitelisted tables. Keys are stable identifiers; 'suffix' is appended to
	 * the gas_ table prefix. 'search' lists LIKE-able columns. 'edit' names the
	 * SPA route that edits a row (null = read-only).
	 *
	 * @return array
	 */
	public static function registry() {
		return array(
			'equipment'           => array( 'suffix' => 'equipment', 'label' => 'ציוד', 'search' => array( 'name', 'building', 'room', 'pi_name', 'asset_code', 'gas_type' ), 'edit' => 'equipment', 'order' => 'name ASC' ),
			'inspections'         => array( 'suffix' => 'inspections', 'label' => 'ביקורות', 'search' => array( 'inspector', 'pi_name', 'type' ), 'edit' => 'inspections', 'order' => 'id DESC' ),
			'faults'              => array( 'suffix' => 'faults', 'label' => 'תקלות', 'search' => array( 'reporter', 'description', 'status', 'priority' ), 'edit' => 'faults', 'order' => 'id DESC' ),
			'qr_issuance'         => array( 'suffix' => 'qr_issuance', 'label' => 'הנפקות QR', 'search' => array( 'equipment_name', 'pi_name', 'qr_number', 'status' ), 'edit' => 'qrissue', 'order' => 'id DESC' ),
			'scans'               => array( 'suffix' => 'scans', 'label' => 'סריקות', 'search' => array(), 'edit' => null, 'order' => 'scanned_at DESC' ),
			'inspection_items'    => array( 'suffix' => 'inspection_items', 'label' => 'פריטי ביקורת', 'search' => array( 'item_text', 'item_result' ), 'edit' => null, 'order' => 'id DESC' ),
			'audit'               => array( 'suffix' => 'audit', 'label' => 'יומן בקרה', 'search' => array( 'actor', 'action', 'entity', 'item_name', 'summary' ), 'edit' => null, 'order' => 'id DESC' ),
			'checklist_templates' => array( 'suffix' => 'checklist_templates', 'label' => 'תבניות צ׳קליסט', 'search' => array( 'item_text', 'type' ), 'edit' => null, 'order' => 'id ASC' ),
			'deletion_requests'   => array( 'suffix' => 'deletion_requests', 'label' => 'בקשות מחיקה (פרטיות)', 'search' => array( 'email', 'status' ), 'edit' => null, 'order' => 'id DESC' ),
		);
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$perm = array( 'GSM_Auth', 'require_manage' );

		register_rest_route(
			self::NS,
			'/db/tables',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'tables' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/db/table/(?P<key>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'table' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/db/export/(?P<key>[a-z_]+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'export' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * Resolve a whitelisted key to its full table name + meta, or null.
	 *
	 * @param string $key Key.
	 * @return array|null
	 */
	private function resolve( $key ) {
		global $wpdb;
		$reg = self::registry();
		if ( ! isset( $reg[ $key ] ) ) {
			return null;
		}
		$meta          = $reg[ $key ];
		$meta['key']   = $key;
		$meta['table'] = $wpdb->prefix . 'gas_' . $meta['suffix'];
		return $meta;
	}

	/**
	 * Column names of a (whitelisted) table, via SHOW COLUMNS.
	 *
	 * @param string $table Full table name (from whitelist only).
	 * @return string[]
	 */
	private function columns( $table ) {
		global $wpdb;
		// $table originates from the hard-coded whitelist, not user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$table}" );
		$out  = array();
		foreach ( (array) $cols as $c ) {
			if ( ! in_array( $c, self::HIDDEN, true ) ) {
				$out[] = $c;
			}
		}
		return $out;
	}

	/**
	 * GET /db/tables
	 *
	 * @return WP_REST_Response
	 */
	public function tables() {
		global $wpdb;
		$out = array();
		foreach ( self::registry() as $key => $meta ) {
			$table = $wpdb->prefix . 'gas_' . $meta['suffix'];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
			$out[] = array(
				'key'      => $key,
				'label'    => $meta['label'],
				'count'    => $count,
				'editable' => ! empty( $meta['edit'] ) ? $meta['edit'] : '',
				'readonly' => empty( $meta['edit'] ),
			);
		}
		return rest_ensure_response( array( 'tables' => $out ) );
	}

	/**
	 * GET /db/table/{key}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function table( $req ) {
		global $wpdb;
		$meta = $this->resolve( (string) $req['key'] );
		if ( ! $meta ) {
			return new WP_Error( 'gsm_not_found', __( 'Unknown table.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$table   = $meta['table'];
		$columns = $this->columns( $table );

		$search = trim( (string) $req->get_param( 'search' ) );
		$limit  = $req->get_param( 'limit' ) ? max( 1, min( 500, (int) $req->get_param( 'limit' ) ) ) : 50;
		$offset = max( 0, (int) $req->get_param( 'offset' ) );

		list( $where_sql, $params ) = $this->build_where( $meta, $search );

		// Count.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";
		$total     = $params
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			? (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) )
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			: (int) $wpdb->get_var( $count_sql );

		$order = isset( $meta['order'] ) ? $meta['order'] : 'id DESC';
		$rowp  = array_merge( $params, array( $limit, $offset ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $rowp ), ARRAY_A );
		$rows = $rows ? $rows : array();

		// Strip hidden columns + truncate long cells for the browse view.
		$clean = array();
		foreach ( $rows as $r ) {
			$row = array();
			foreach ( $columns as $c ) {
				$v = isset( $r[ $c ] ) ? (string) $r[ $c ] : '';
				// Multibyte-safe truncation (cells may hold Hebrew text).
				if ( mb_strlen( $v, 'UTF-8' ) > self::CELL_MAX ) {
					$v = mb_substr( $v, 0, self::CELL_MAX, 'UTF-8' ) . '…';
				}
				$row[ $c ] = $v;
			}
			$clean[] = $row;
		}

		return rest_ensure_response(
			array(
				'key'        => $meta['key'],
				'label'      => $meta['label'],
				'columns'    => $columns,
				'rows'       => $clean,
				'total'      => $total,
				'limit'      => $limit,
				'offset'     => $offset,
				'editableVia'=> ! empty( $meta['edit'] ) ? $meta['edit'] : '',
				'searchable' => ! empty( $meta['search'] ),
			)
		);
	}

	/**
	 * Build the WHERE clause + params for a search.
	 *
	 * @param array  $meta   Table meta.
	 * @param string $search Search term.
	 * @return array [ where_sql, params ]
	 */
	private function build_where( $meta, $search ) {
		global $wpdb;
		if ( '' === $search || empty( $meta['search'] ) ) {
			return array( '1=1', array() );
		}
		$like   = '%' . $wpdb->esc_like( $search ) . '%';
		$parts  = array();
		$params = array();
		foreach ( $meta['search'] as $col ) {
			// Column names come from the hard-coded whitelist.
			$parts[]  = "{$col} LIKE %s";
			$params[] = $like;
		}
		return array( '(' . implode( ' OR ', $parts ) . ')', $params );
	}

	/**
	 * GET /db/export/{key} — full-table CSV (raw output).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_Error|void
	 */
	public function export( $req ) {
		global $wpdb;
		$meta = $this->resolve( (string) $req['key'] );
		if ( ! $meta ) {
			return new WP_Error( 'gsm_not_found', __( 'Unknown table.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$table   = $meta['table'];
		$columns = $this->columns( $table );
		$order   = isset( $meta['order'] ) ? $meta['order'] : 'id DESC';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY {$order}", ARRAY_A );
		$rows = $rows ? $rows : array();

		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $meta['key'] . '.csv"' );
		echo "\xEF\xBB\xBF"; // UTF-8 BOM for Excel.
		echo $this->csv_line( $columns );
		foreach ( $rows as $r ) {
			$line = array();
			foreach ( $columns as $c ) {
				$line[] = isset( $r[ $c ] ) ? $r[ $c ] : '';
			}
			echo $this->csv_line( $line );
		}
		exit;
	}

	/**
	 * One CSV line (RFC-4180 quoting).
	 *
	 * @param array $cells Cells.
	 * @return string
	 */
	private function csv_line( $cells ) {
		$out = array();
		foreach ( $cells as $c ) {
			$v     = GSM_Security::csv_cell( $c );
			$v     = str_replace( '"', '""', $v );
			$out[] = '"' . $v . '"';
		}
		return implode( ',', $out ) . "\r\n";
	}
}
