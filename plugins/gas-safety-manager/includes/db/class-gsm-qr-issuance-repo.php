<?php
/**
 * Data access for the QR issuance table (gas_qr_issuance).
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
 * Class GSM_QR_Issuance_Repo
 */
class GSM_QR_Issuance_Repo {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gas_qr_issuance';
	}

	/**
	 * Get one record.
	 *
	 * @param int $id ID.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", (int) $id ), ARRAY_A );
	}

	/**
	 * Find an existing record for an equipment id.
	 *
	 * @param int $equipment_id Equipment ID.
	 * @return array|null
	 */
	public static function get_by_equipment( $equipment_id ) {
		global $wpdb;
		$table = self::table();
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE equipment_id = %d ORDER BY id DESC LIMIT 1", (int) $equipment_id ), ARRAY_A );
	}

	/**
	 * List records with optional status filter + search.
	 *
	 * @param array $args { status, search, limit, offset }.
	 * @return array
	 */
	public static function list( $args = array() ) {
		global $wpdb;
		$table = self::table();

		$defaults = array(
			'status' => '',
			'search' => '',
			'limit'  => 1000,
			'offset' => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$where  = array( '1=1' );
		$params = array();

		if ( '' !== $args['status'] ) {
			$where[]  = 'status = %s';
			$params[] = $args['status'];
		}
		if ( '' !== $args['search'] ) {
			$like     = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$where[]  = '(equipment_name LIKE %s OR pi_name LIKE %s OR qr_number LIKE %s)';
			$params[] = $like;
			$params[] = $like;
			$params[] = $like;
		}

		$where_sql = implode( ' AND ', $where );
		$params[]  = (int) $args['limit'];
		$params[]  = (int) $args['offset'];

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY updated_at DESC, id DESC LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return $rows ? $rows : array();
	}

	/**
	 * Insert a record.
	 *
	 * @param array $data Clean data.
	 * @return int|WP_Error
	 */
	public static function insert( $data ) {
		global $wpdb;
		$now            = current_time( 'mysql' );
		$data['created_at'] = $now;
		$data['updated_at'] = $now;
		$ok = $wpdb->insert( self::table(), $data );
		if ( false === $ok ) {
			return new WP_Error( 'gsm_db_insert', __( 'Failed to insert issuance record.', 'gas-safety-manager' ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * Update a record.
	 *
	 * @param int   $id   ID.
	 * @param array $data Clean data.
	 * @return true|WP_Error
	 */
	public static function update( $id, $data ) {
		global $wpdb;
		$data['updated_at'] = current_time( 'mysql' );
		$ok = $wpdb->update( self::table(), $data, array( 'id' => (int) $id ) );
		if ( false === $ok ) {
			return new WP_Error( 'gsm_db_update', __( 'Failed to update issuance record.', 'gas-safety-manager' ) );
		}
		return true;
	}

	/**
	 * Delete a record.
	 *
	 * @param int $id ID.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ) );
	}

	/**
	 * Count by status (for the tab badges).
	 *
	 * @return array status => count
	 */
	public static function counts_by_status() {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT status, COUNT(*) AS c FROM {$table} GROUP BY status", ARRAY_A );
		$out   = array();
		foreach ( (array) $rows as $r ) {
			$out[ (string) $r['status'] ] = (int) $r['c'];
		}
		return $out;
	}
}
