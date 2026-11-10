<?php
/**
 * Restore points — full data snapshots + non-destructive restore.
 *
 * A "restore point" stores a JSON snapshot of all operational data (equipment,
 * faults, inspections, inspection items + the inspection config). Restoring
 * re-applies the snapshot with REPLACE INTO (upsert by primary key): it
 * recreates rows that were deleted and reverts rows that were changed, but
 * never deletes rows created AFTER the snapshot — so a restore cannot wipe
 * newer work. A safety snapshot of the current state is taken automatically
 * before every restore.
 *
 * @package GasSafetyManager
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Restore_Service
 */
class GSM_Restore_Service {

	/**
	 * How many restore points to keep (older ones are pruned).
	 */
	const KEEP = 20;

	/**
	 * Restore-points table.
	 *
	 * @return string
	 */
	private static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gas_restore_points';
	}

	/**
	 * Operational tables included in a snapshot (key => full table name).
	 *
	 * @return array
	 */
	private static function tables() {
		global $wpdb;
		$p = $wpdb->prefix . 'gas_';
		return array(
			'equipment'        => $p . 'equipment',
			'faults'           => $p . 'faults',
			'inspections'      => $p . 'inspections',
			'inspection_items' => $p . 'inspection_items',
		);
	}

	/**
	 * Gather a full snapshot of the current data.
	 *
	 * @return array
	 */
	public static function collect() {
		global $wpdb;
		$out = array(
			'version' => defined( 'GSM_DB_VERSION' ) ? GSM_DB_VERSION : '',
			'created' => current_time( 'mysql' ),
			'data'    => array(),
			'options' => array(
				'gsm_inspection_config' => get_option( 'gsm_inspection_config', array() ),
			),
		);
		foreach ( self::tables() as $key => $t ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows               = $wpdb->get_results( "SELECT * FROM {$t}", ARRAY_A );
			$out['data'][ $key ] = $rows ? $rows : array();
		}
		return $out;
	}

	/**
	 * Row counts per table from a snapshot.
	 *
	 * @param array $snap Snapshot.
	 * @return array
	 */
	public static function counts( $snap ) {
		$c    = array();
		$data = ( isset( $snap['data'] ) && is_array( $snap['data'] ) ) ? $snap['data'] : array();
		foreach ( $data as $k => $rows ) {
			$c[ $k ] = is_array( $rows ) ? count( $rows ) : 0;
		}
		return $c;
	}

	/**
	 * Create a restore point.
	 *
	 * @param string $label Human label.
	 * @param string $kind  manual|auto.
	 * @return array { id, counts }
	 */
	public static function snapshot( $label, $kind = 'manual' ) {
		global $wpdb;
		$snap   = self::collect();
		$counts = self::counts( $snap );
		$user   = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$actor  = ( $user && $user->ID ) ? ( $user->display_name ? $user->display_name : $user->user_login ) : 'system';

		$wpdb->insert(
			self::table(),
			array(
				'created_at'  => current_time( 'mysql' ),
				'created_by'  => substr( (string) $actor, 0, 255 ),
				'label'       => substr( (string) $label, 0, 255 ),
				'kind'        => substr( (string) $kind, 0, 20 ),
				'counts_json' => wp_json_encode( $counts ),
				'payload'     => wp_json_encode( $snap ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$id = (int) $wpdb->insert_id;
		self::prune();
		return array( 'id' => $id, 'counts' => $counts );
	}

	/**
	 * List restore points (no payload).
	 *
	 * @return array
	 */
	public static function list() {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( "SELECT id, created_at, created_by, label, kind, counts_json, LENGTH(payload) AS size FROM {$t} ORDER BY id DESC LIMIT 100", ARRAY_A );
		$out  = array();
		foreach ( (array) $rows as $r ) {
			$out[] = array(
				'id'         => (int) $r['id'],
				'created_at' => $r['created_at'],
				'created_by' => $r['created_by'],
				'label'      => $r['label'],
				'kind'       => $r['kind'],
				'counts'     => json_decode( (string) $r['counts_json'], true ),
				'size'       => (int) $r['size'],
			);
		}
		return $out;
	}

	/**
	 * Get one restore point (with payload).
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
	 * Delete a restore point.
	 *
	 * @param int $id Id.
	 * @return bool
	 */
	public static function delete( $id ) {
		global $wpdb;
		return (bool) $wpdb->delete( self::table(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	/**
	 * Keep only the newest N restore points.
	 *
	 * @param int $keep Keep count.
	 * @return void
	 */
	public static function prune( $keep = self::KEEP ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} ORDER BY id DESC LIMIT 100000 OFFSET %d", max( 1, (int) $keep ) ) );
		foreach ( (array) $ids as $id ) {
			$wpdb->delete( $t, array( 'id' => (int) $id ), array( '%d' ) );
		}
	}

	/**
	 * Restore from a point (non-destructive upsert). Takes a safety snapshot
	 * of the current state first.
	 *
	 * @param int $id Restore-point id.
	 * @return array|WP_Error
	 */
	public static function restore( $id ) {
		global $wpdb;
		$row = self::get( $id );
		if ( ! $row ) {
			return new WP_Error( 'gsm_rp_notfound', __( 'נקודת שחזור לא נמצאה.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		$snap = json_decode( (string) $row['payload'], true );
		if ( ! is_array( $snap ) || empty( $snap['data'] ) || ! is_array( $snap['data'] ) ) {
			return new WP_Error( 'gsm_rp_bad', __( 'נקודת השחזור פגומה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}

		// Safety snapshot of the CURRENT state before overwriting anything.
		self::snapshot( 'גיבוי אוטומטי לפני שחזור #' . (int) $id, 'auto' );

		$map     = self::tables();
		$applied = array();
		foreach ( $snap['data'] as $key => $rows ) {
			if ( ! isset( $map[ $key ] ) || ! is_array( $rows ) ) {
				continue;
			}
			$t = $map[ $key ];
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$cols = (array) $wpdb->get_col( "DESCRIBE {$t}" );
			$n    = 0;
			foreach ( $rows as $r ) {
				if ( ! is_array( $r ) ) {
					continue;
				}
				$data = array();
				foreach ( $r as $k => $v ) {
					if ( in_array( $k, $cols, true ) ) {
						$data[ $k ] = $v;
					}
				}
				if ( $data ) {
					$wpdb->replace( $t, $data ); // REPLACE INTO — upsert by primary key.
					$n++;
				}
			}
			$applied[ $key ] = $n;
		}

		if ( isset( $snap['options']['gsm_inspection_config'] ) ) {
			update_option( 'gsm_inspection_config', $snap['options']['gsm_inspection_config'] );
		}

		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'restore', 'system', 0, '', 'שוחזרה נקודת שחזור #' . (int) $id );
		}
		return array( 'restored' => true, 'applied' => $applied );
	}
}
