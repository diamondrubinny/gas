<?php
/**
 * Inspections repository — Data Access layer.
 *
 * Handles both the inspections table and its child inspection_items table
 * (one row per checklist item — the clean structure that replaces Monday's
 * crammed single-cell checklist).
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Inspections_Repo
 */
class GSM_Inspections_Repo {

	/**
	 * Inspections table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gas_inspections';
	}

	/**
	 * Items table name.
	 *
	 * @return string
	 */
	private static function items_table() {
		global $wpdb;
		return $wpdb->prefix . 'gas_inspection_items';
	}

	/**
	 * Writable columns for inspections.
	 *
	 * @return array<string,string>
	 */
	public static function columns() {
		return array(
			'equipment_id'    => '%d',
			'type'            => '%s',
			'inspection_date' => '%s',
			'inspector'       => '%s',
			'inspector_email' => '%s',
			'pi_name'         => '%s',
			'panel_number'    => '%s',
			'result'          => '%s',
			'notes'           => '%s',
			'monday_id'       => '%s',
		);
	}

	/**
	 * Get one inspection (with its items).
	 *
	 * @param int $id Inspection ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
		if ( ! $row ) {
			return null;
		}
		$row['items'] = self::get_items( (int) $id );
		return $row;
	}

	/**
	 * Get checklist items for an inspection.
	 *
	 * @param int $inspection_id Inspection ID.
	 * @return array
	 */
	public static function get_items( $inspection_id ) {
		global $wpdb;
		$items = self::items_table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( "SELECT id, item_index, item_text, item_result FROM {$items} WHERE inspection_id = %d ORDER BY item_index ASC", (int) $inspection_id ),
			ARRAY_A
		);
		return $rows ? $rows : array();
	}

	/**
	 * Get inspection by Monday ID (migration idempotency).
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
	 * List inspections with filters.
	 *
	 * @param array $args Filters: equipment_id, type, limit, offset.
	 * @return array
	 */
	public static function list( $args = array() ) {
		global $wpdb;
		$table = self::table();

		$defaults = array(
			'equipment_id' => 0,
			'type'         => '',
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
		if ( '' !== $args['type'] ) {
			$where[]  = 'type = %s';
			$params[] = $args['type'];
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = (int) $args['limit'];
		$params[]  = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY inspection_date DESC, id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return $rows ? $rows : array();
	}

	/**
	 * Count inspections with filters.
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
		if ( ! empty( $args['type'] ) ) {
			$where[]  = 'type = %s';
			$params[] = $args['type'];
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
	 * Insert an inspection plus its checklist items (transaction-like).
	 *
	 * @param array $data  Inspection columns.
	 * @param array $items Array of { item_index, item_text, item_result }.
	 * @return int|WP_Error New inspection ID.
	 */
	public static function insert( $data, $items = array() ) {
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
			return new WP_Error( 'gsm_db_insert', __( 'Failed to insert inspection.', 'gas-safety-manager' ) );
		}
		$inspection_id = (int) $wpdb->insert_id;

		// Insert checklist items.
		if ( ! empty( $items ) ) {
			self::replace_items( $inspection_id, $items );
		}

		return $inspection_id;
	}

	/**
	 * Replace all checklist items for an inspection.
	 *
	 * @param int   $inspection_id Inspection ID.
	 * @param array $items         Items to insert.
	 * @return void
	 */
	public static function replace_items( $inspection_id, $items ) {
		global $wpdb;
		$items_table = self::items_table();

		// Clear existing.
		$wpdb->delete( $items_table, array( 'inspection_id' => (int) $inspection_id ), array( '%d' ) );

		foreach ( $items as $idx => $item ) {
			$wpdb->insert(
				$items_table,
				array(
					'inspection_id' => (int) $inspection_id,
					'item_index'    => isset( $item['item_index'] ) ? (int) $item['item_index'] : (int) $idx,
					'item_text'     => isset( $item['item_text'] ) ? $item['item_text'] : '',
					'item_result'   => isset( $item['item_result'] ) ? $item['item_result'] : 'ok',
				),
				array( '%d', '%d', '%s', '%s' )
			);
		}
	}

	/**
	 * Update an inspection.
	 *
	 * @param int   $id   Inspection ID.
	 * @param array $data Columns.
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
			return new WP_Error( 'gsm_db_update', __( 'Failed to update inspection.', 'gas-safety-manager' ) );
		}
		return true;
	}

	/**
	 * Delete an inspection and its items.
	 *
	 * @param int $id Inspection ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( self::items_table(), array( 'inspection_id' => $id ), array( '%d' ) );
		return (bool) $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}

	/**
	 * Count open defects across all inspections (item_result = 'defect').
	 * Optionally scoped to one equipment.
	 *
	 * @param int $equipment_id Optional equipment ID (0 = all).
	 * @return int
	 */
	public static function count_open_defects( $equipment_id = 0 ) {
		global $wpdb;
		$insp  = self::table();
		$items = self::items_table();

		if ( $equipment_id ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$sql = "SELECT COUNT(*) FROM {$items} it INNER JOIN {$insp} i ON it.inspection_id = i.id WHERE it.item_result = 'defect' AND i.equipment_id = %d";
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, (int) $equipment_id ) );
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT COUNT(*) FROM {$items} WHERE item_result = 'defect'";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->get_var( $sql );
	}
}
