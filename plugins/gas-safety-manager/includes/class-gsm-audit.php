<?php
/**
 * Audit log.
 *
 * A thin, central logger the service layer calls on create/update/delete, plus
 * read/undo helpers for the REST controller. Keeps a human "summary" and, for
 * deletes, the full previous row (before_json) so a delete can be undone.
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
 * Class GSM_Audit
 */
class GSM_Audit {

	/**
	 * Table name.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gas_audit';
	}

	/**
	 * Record an audit entry.
	 *
	 * @param string $action  create|update|delete|import|undo.
	 * @param string $entity  equipment|fault|inspection|user|…
	 * @param int    $id      Entity id.
	 * @param string $name    Human item name.
	 * @param string $summary Short description of the change.
	 * @param array  $before  Previous row (for undo of deletes); optional.
	 * @return void
	 */
	public static function log( $action, $entity, $id, $name = '', $summary = '', $before = null ) {
		global $wpdb;
		$user  = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$actor = ( $user && $user->ID ) ? ( $user->display_name ? $user->display_name : $user->user_login ) : 'system';

		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql' ),
				'actor'       => $actor,
				'actor_id'    => ( $user && $user->ID ) ? (int) $user->ID : null,
				'action'      => substr( (string) $action, 0, 30 ),
				'entity'      => substr( (string) $entity, 0, 30 ),
				'entity_id'   => $id ? (int) $id : null,
				'item_name'   => substr( (string) $name, 0, 255 ),
				'summary'     => (string) $summary,
				'before_json' => ( null !== $before ) ? wp_json_encode( $before ) : null,
				'undone'      => 0,
			),
			array( '%s', '%s', '%d', '%s', '%s', '%d', '%s', '%s', '%s', '%d' )
		);
	}

	/**
	 * List recent entries.
	 *
	 * @param array $args { limit, offset, entity }.
	 * @return array
	 */
	public static function list( $args = array() ) {
		global $wpdb;
		$table  = self::table();
		$limit  = isset( $args['limit'] ) ? max( 1, min( 2000, (int) $args['limit'] ) ) : 300;
		$offset = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;
		$entity = isset( $args['entity'] ) ? (string) $args['entity'] : '';

		if ( '' !== $entity ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} WHERE entity = %s ORDER BY id DESC LIMIT %d OFFSET %d", $entity, $limit, $offset ),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results(
				$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d OFFSET %d", $limit, $offset ),
				ARRAY_A
			);
		}
		return $rows ? $rows : array();
	}

	/**
	 * Get one entry.
	 *
	 * @param int $id Entry id.
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
	 * Mark an entry as undone.
	 *
	 * @param int $id Entry id.
	 * @return void
	 */
	public static function mark_undone( $id ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'undone' => 1 ), array( 'id' => (int) $id ), array( '%d' ), array( '%d' ) );
	}
}
