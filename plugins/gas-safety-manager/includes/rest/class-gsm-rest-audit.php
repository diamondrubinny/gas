<?php
/**
 * REST controller: Audit log (read).
 *
 *   GET /gas/v1/audit?entity=&limit=   (manage)
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Audit
 */
class GSM_REST_Audit {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/audit',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_entries' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/audit/(?P<id>\d+)/undo',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'undo' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
	}

	/**
	 * POST /audit/{id}/undo — re-insert a deleted row from its before_json.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function undo( $req ) {
		$entry = GSM_Audit::get( (int) $req['id'] );
		if ( ! $entry ) {
			return new WP_Error( 'gsm_not_found', __( 'רשומת יומן לא נמצאה.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		if ( 'delete' !== ( isset( $entry['action'] ) ? $entry['action'] : '' ) ) {
			return new WP_Error( 'gsm_undo_bad', __( 'ניתן לבטל רק פעולות מחיקה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! empty( $entry['undone'] ) ) {
			return new WP_Error( 'gsm_undo_done', __( 'הפעולה כבר שוחזרה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$before = json_decode( (string) ( isset( $entry['before_json'] ) ? $entry['before_json'] : '' ), true );
		if ( ! is_array( $before ) || empty( $before ) ) {
			return new WP_Error( 'gsm_undo_nodata', __( 'אין נתונים לשחזור ברשומה זו.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$entity = isset( $entry['entity'] ) ? $entry['entity'] : '';
		$map    = array( 'equipment' => 'gas_equipment', 'fault' => 'gas_faults', 'inspection' => 'gas_inspections' );
		if ( ! isset( $map[ $entity ] ) ) {
			return new WP_Error( 'gsm_undo_entity', __( 'לא ניתן לשחזר סוג זה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		global $wpdb;
		$table = $wpdb->prefix . $map[ $entity ];
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cols = (array) $wpdb->get_col( "DESCRIBE {$table}" );
		$data = array();
		foreach ( $before as $k => $v ) {
			if ( in_array( $k, $cols, true ) ) {
				$data[ $k ] = $v;
			}
		}
		if ( empty( $data ) ) {
			return new WP_Error( 'gsm_undo_cols', __( 'מבנה הנתונים אינו תואם.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		// Restore with the original id; if it is taken, insert as a new row.
		$ok = $wpdb->insert( $table, $data );
		if ( false === $ok ) {
			unset( $data['id'] );
			$ok = $wpdb->insert( $table, $data );
		}
		if ( false === $ok ) {
			return new WP_Error( 'gsm_undo_fail', __( 'שחזור נכשל.', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}
		$new_id = (int) $wpdb->insert_id;
		GSM_Audit::mark_undone( (int) $entry['id'] );
		GSM_Audit::log( 'undo', $entity, $new_id, isset( $entry['item_name'] ) ? $entry['item_name'] : '', 'שוחזר ממחיקה (יומן #' . (int) $entry['id'] . ')' );
		return rest_ensure_response( array( 'restored' => true, 'entity' => $entity, 'id' => $new_id ) );
	}

	/**
	 * GET /audit
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function list_entries( $req ) {
		$entity = (string) $req->get_param( 'entity' );
		$limit  = (int) $req->get_param( 'limit' );
		$rows   = GSM_Audit::list(
			array(
				'entity' => $entity,
				'limit'  => $limit ? $limit : 300,
			)
		);
		$action_labels = array(
			'create' => 'יצירה',
			'update' => 'עדכון',
			'delete' => 'מחיקה',
			'import' => 'ייבוא',
			'undo'   => 'ביטול',
		);
		$entity_labels = array(
			'equipment'  => 'ציוד',
			'fault'      => 'תקלה',
			'inspection' => 'ביקורת',
			'user'       => 'משתמש',
		);
		$out = array();
		foreach ( $rows as $r ) {
			$a       = isset( $r['action'] ) ? $r['action'] : '';
			$e       = isset( $r['entity'] ) ? $r['entity'] : '';
			$out[] = array(
				'id'           => (int) $r['id'],
				'created_at'   => $r['created_at'],
				'actor'        => $r['actor'],
				'action'       => $a,
				'action_label' => isset( $action_labels[ $a ] ) ? $action_labels[ $a ] : $a,
				'entity'       => $e,
				'entity_label' => isset( $entity_labels[ $e ] ) ? $entity_labels[ $e ] : $e,
				'item_name'    => $r['item_name'],
				'summary'      => $r['summary'],
				'undone'       => ! empty( $r['undone'] ),
				'undoable'     => ( 'delete' === $a && empty( $r['undone'] ) && ! empty( $r['before_json'] ) ),
			);
		}
		return rest_ensure_response( $out );
	}
}
