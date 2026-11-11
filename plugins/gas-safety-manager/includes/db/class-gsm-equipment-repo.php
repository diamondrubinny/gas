<?php
/**
 * Equipment repository — Data Access layer.
 *
 * The ONLY place that touches the equipment table directly. All SQL lives
 * here, always via $wpdb->prepare. Services and REST controllers call these
 * methods; they never write SQL themselves.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Interpolated SQL in this file is limited to: table names ($wpdb->prefix + fixed
// suffix), fixed WHERE fragments, and whitelisted ORDER BY column/direction.
// Every user-supplied value is bound through $wpdb->prepare() placeholders.
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

/**
 * Class GSM_Equipment_Repo
 */
class GSM_Equipment_Repo {

	/**
	 * Full table name (with prefix).
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gas_equipment';
	}

	/**
	 * The list of writable columns and their sanitize/format spec.
	 * Keeping this central means insert/update share one definition.
	 *
	 * Format codes: %s string, %d int.
	 *
	 * @return array<string,string> column => format
	 */
	public static function columns() {
		return array(
			'name'            => '%s',
			'gas_type'        => '%s',
			'category'        => '%s',
			'faculty'         => '%s',
			'building'        => '%s',
			'floor'           => '%s',
			'room'            => '%s',
			'location'        => '%s',
			'functional_loc'  => '%s',
			'gis'             => '%s',
			'panel_number'    => '%s',
			'work_pressure'   => '%s',
			'cylinders_count' => '%s',
			'cylinders_stock' => '%s',
			'phone'           => '%s',
			'contacts'        => '%s',
			'pi_name'         => '%s',
			'lab_managers'    => '%s',
			'maint_staff'     => '%s',
			'maint_emails'    => '%s',
			'status'          => '%s',
			'notes'           => '%s',
			'last_quarterly'  => '%s',
			'next_quarterly'  => '%s',
			'monday_id'       => '%s',
			'asset_code'      => '%s',
			'qr_token'        => '%s',
			'image_id'        => '%d',
		);
	}

	/**
	 * Get one equipment row by ID.
	 *
	 * @param int $id Equipment ID.
	 * @return array|null Row as associative array, or null if not found.
	 */
	public static function get( $id ) {
		global $wpdb;
		$id    = (int) $id;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Get one equipment row by QR token.
	 *
	 * @param string $token QR token.
	 * @return array|null
	 */
	public static function get_by_token( $token ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE qr_token = %s", $token ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * Get one equipment row by original Monday ID (used for legacy QR compat
	 * and during migration).
	 *
	 * @param string $monday_id Monday item ID.
	 * @return array|null
	 */
	public static function get_by_monday_id( $monday_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE monday_id = %s", $monday_id ),
			ARRAY_A
		);
		return $row ? $row : null;
	}

	/**
	 * List equipment with optional filters and pagination.
	 *
	 * @param array $args {
	 *     @type string $search   Search term (matches name/building/room).
	 *     @type string $building Filter by building.
	 *     @type string $status   Filter by status.
	 *     @type int    $limit    Max rows (default 100).
	 *     @type int    $offset   Offset (default 0).
	 *     @type string $orderby  Column to sort by (whitelisted).
	 *     @type string $order    ASC|DESC.
	 * }
	 * @return array List of rows (associative arrays).
	 */
	public static function list( $args = array() ) {
		global $wpdb;
		$table = self::table();

		$defaults = array(
			'search'   => '',
			'building' => '',
			'status'   => '',
			'limit'    => 100,
			'offset'   => 0,
			'orderby'  => 'name',
			'order'    => 'ASC',
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR building LIKE %s OR room LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( '' !== $args['building'] ) {
			$where[]  = 'building = %s';
			$params[] = $args['building'];
		}
		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		// Whitelist orderby to prevent SQL injection via column name.
		$allowed_orderby = array( 'name', 'building', 'status', 'next_quarterly', 'created_at', 'id' );
		$orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'name';
		$order           = ( 'DESC' === strtoupper( $args['order'] ) ) ? 'DESC' : 'ASC';

		$where_sql = implode( ' AND ', $where );
		$params[]  = (int) $args['limit'];
		$params[]  = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		return $rows ? $rows : array();
	}

	/**
	 * Count equipment matching filters (for pagination).
	 *
	 * @param array $args Same filters as list().
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;
		$table = self::table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['search'] ) ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(name LIKE %s OR building LIKE %s OR room LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}
		if ( ! empty( $args['building'] ) ) {
			$where[]  = 'building = %s';
			$params[] = $args['building'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}

		$where_sql = implode( ' AND ', $where );
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}";

		if ( $params ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $params ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}

	/**
	 * Insert a new equipment row.
	 *
	 * @param array $data Column => value (only known columns are used).
	 * @return int|WP_Error New ID on success, WP_Error on failure.
	 */
	public static function insert( $data ) {
		global $wpdb;
		$table = self::table();

		$cols    = self::columns();
		$row     = array();
		$formats = array();
		foreach ( $cols as $col => $fmt ) {
			if ( array_key_exists( $col, $data ) ) {
				$row[ $col ] = $data[ $col ];
				$formats[]   = $fmt;
			}
		}

		$now                = current_time( 'mysql' );
		$row['created_at']  = $now;
		$formats[]          = '%s';
		$row['updated_at']  = $now;
		$formats[]          = '%s';

		$ok = $wpdb->insert( $table, $row, $formats );
		if ( false === $ok ) {
			return new WP_Error( 'gsm_db_insert', __( 'Failed to insert equipment.', 'gas-safety-manager' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update an equipment row.
	 *
	 * @param int   $id   Equipment ID.
	 * @param array $data Column => value.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$table = self::table();

		$cols    = self::columns();
		$row     = array();
		$formats = array();
		foreach ( $cols as $col => $fmt ) {
			if ( array_key_exists( $col, $data ) ) {
				$row[ $col ] = $data[ $col ];
				$formats[]   = $fmt;
			}
		}

		if ( empty( $row ) ) {
			return new WP_Error( 'gsm_db_noop', __( 'No valid fields to update.', 'gas-safety-manager' ) );
		}

		$row['updated_at'] = current_time( 'mysql' );
		$formats[]         = '%s';

		$ok = $wpdb->update( $table, $row, array( 'id' => (int) $id ), $formats, array( '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'gsm_db_update', __( 'Failed to update equipment.', 'gas-safety-manager' ) );
		}
		return true;
	}

	/**
	 * Delete an equipment row.
	 *
	 * @param int $id Equipment ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = self::table();
		$ok    = $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
		return (bool) $ok;
	}

	/**
	 * Check whether a QR token already exists (for uniqueness on generation).
	 *
	 * @param string $token Token.
	 * @return bool
	 */
	public static function token_exists( $token ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE qr_token = %s LIMIT 1", $token )
		);
		return ! empty( $found );
	}

	/**
	 * Check whether an asset_code already exists (for uniqueness on allocation).
	 *
	 * @param string $code Asset code.
	 * @return int Equipment id, or 0.
	 */
	public static function id_by_asset_code( $code ) {
		global $wpdb;
		$code = trim( (string) $code );
		if ( '' === $code ) {
			return 0;
		}
		$table = self::table();
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$table} WHERE asset_code = %s ORDER BY id ASC LIMIT 1", $code ) );
	}

	/**
	 * Whether an asset code is already used.
	 *
	 * @param string $code Asset code.
	 * @return bool
	 */
	public static function asset_code_exists( $code ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare( "SELECT id FROM {$table} WHERE asset_code = %s LIMIT 1", $code )
		);
		return ! empty( $found );
	}

	/**
	 * Highest numeric suffix already used by a new-style asset code (prefix +
	 * digits, e.g. "G-1042" -> 1042). Lets the allocator start above any code
	 * that already exists, so sequence resets never collide after a restore.
	 *
	 * @param string $prefix Code prefix (e.g. "G-").
	 * @return int Highest suffix found, or 0.
	 */
	public static function max_new_asset_seq( $prefix ) {
		global $wpdb;
		$table = self::table();
		$like  = $wpdb->esc_like( $prefix ) . '%';
		// Pull candidate codes and parse in PHP (portable across MySQL/MariaDB).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$codes = $wpdb->get_col(
			$wpdb->prepare( "SELECT asset_code FROM {$table} WHERE asset_code LIKE %s", $like )
		);
		$max = 0;
		$plen = strlen( $prefix );
		foreach ( (array) $codes as $c ) {
			$suffix = substr( (string) $c, $plen );
			if ( ctype_digit( $suffix ) ) {
				$n = (int) $suffix;
				if ( $n > $max ) {
					$max = $n;
				}
			}
		}
		return $max;
	}
}
