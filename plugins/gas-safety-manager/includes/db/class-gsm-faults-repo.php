<?php
/**
 * Faults repository — Data Access layer.
 *
 * Only place that touches the faults table. All SQL via $wpdb->prepare.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Faults_Repo
 */
class GSM_Faults_Repo {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gas_faults';
	}

	/**
	 * Writable columns and formats.
	 *
	 * @return array<string,string>
	 */
	public static function columns() {
		return array(
			'equipment_id'  => '%d',
			'reporter'      => '%s',
			'description'   => '%s',
			'status'        => '%s',
			'priority'      => '%s',
			'opened_date'   => '%s',
			'resolved_date' => '%s',
			'monday_id'     => '%s',
		);
	}

	/**
	 * Get one fault by ID.
	 *
	 * @param int $id Fault ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Get a fault by original Monday ID (migration idempotency).
	 *
	 * @param string $monday_id Monday ID.
	 * @return array|null
	 */
	public static function get_by_monday_id( $monday_id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE monday_id = %s", $monday_id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * List faults with filters.
	 *
	 * @param array $args Filters: equipment_id, status, priority, limit, offset.
	 * @return array
	 */
	public static function list( $args = array() ) {
		global $wpdb;
		$table = self::table();

		$defaults = array(
			'equipment_id' => 0,
			'status'       => '',
			'priority'     => '',
			'limit'        => 100,
			'offset'       => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['equipment_id'] ) ) {
			$where[]  = 'equipment_id = %d';
			$params[] = (int) $args['equipment_id'];
		}
		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['priority'] ) {
			$where[]  = 'priority = %s';
			$params[] = $args['priority'];
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = (int) $args['limit'];
		$params[]  = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY opened_date DESC, id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return $rows ? $rows : array();
	}

	/**
	 * Count faults with filters.
	 *
	 * @param array $args Filters.
	 * @return int
	 */
	public static function count( $args = array() ) {
		global $wpdb;
		$table = self::table();

		$where  = array( '1=1' );
		$params = array();

		if ( ! empty( $args['equipment_id'] ) ) {
			$where[]  = 'equipment_id = %d';
			$params[] = (int) $args['equipment_id'];
		}
		if ( ! empty( $args['status'] ) ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( ! empty( $args['priority'] ) ) {
			$where[]  = 'priority = %s';
			$params[] = $args['priority'];
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
	 * Insert a fault.
	 *
	 * @param array $data Column => value.
	 * @return int|WP_Error
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
		$row['created_at'] = current_time( 'mysql' );
		$formats[]         = '%s';

		$ok = $wpdb->insert( $table, $row, $formats );
		if ( false === $ok ) {
			return new WP_Error( 'gsm_db_insert', __( 'Failed to insert fault.', 'gas-safety-manager' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a fault.
	 *
	 * @param int   $id   Fault ID.
	 * @param array $data Column => value.
	 * @return bool|WP_Error
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

		$ok = $wpdb->update( $table, $row, array( 'id' => (int) $id ), $formats, array( '%d' ) );
		if ( false === $ok ) {
			return new WP_Error( 'gsm_db_update', __( 'Failed to update fault.', 'gas-safety-manager' ) );
		}
		return true;
	}

	/**
	 * Delete a fault.
	 *
	 * @param int $id Fault ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$table = self::table();
		return (bool) $wpdb->delete( $table, array( 'id' => (int) $id ), array( '%d' ) );
	}
}
