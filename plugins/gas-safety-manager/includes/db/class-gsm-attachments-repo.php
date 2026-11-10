<?php
/**
 * Data access for gas_attachments.
 *
 * @package GasSafetyManager
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Attachments_Repo
 */
class GSM_Attachments_Repo {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gas_attachments';
	}

	/**
	 * Insert a row.
	 *
	 * @param array $data Columns.
	 * @return int
	 */
	public static function insert( $data ) {
		global $wpdb;
		$wpdb->insert( self::table(), $data );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Get one row.
	 *
	 * @param int $id Id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * List attachment metadata for an entity (no paths).
	 *
	 * @param string $entity    Entity type.
	 * @param int    $entity_id Entity id.
	 * @return array
	 */
	public static function list( $entity, $entity_id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT id, entity, entity_id, orig_name, mime, size, status, uploaded_by, created_at FROM {$t} WHERE entity = %s AND entity_id = %d ORDER BY id DESC", $entity, (int) $entity_id ), ARRAY_A );
		return $rows ? $rows : array();
	}

	/**
	 * Count attachments on an entity.
	 *
	 * @param string $entity    Entity type.
	 * @param int    $entity_id Entity id.
	 * @return int
	 */
	public static function count_for( $entity, $entity_id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE entity = %s AND entity_id = %d", $entity, (int) $entity_id ) );
	}

	/**
	 * Delete a row.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}
}
