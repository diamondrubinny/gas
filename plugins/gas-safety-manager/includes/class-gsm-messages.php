<?php
/**
 * Field messages + feedback / improvement requests.
 *
 * One table (gas_messages) with two kinds:
 *   - field    : a short note from any signed-in user to the managers
 *                ("הודעות מהשטח"). Status: unread | read.
 *   - feedback : a bug report / improvement request / question with a type,
 *                priority and a status the managers move
 *                (open | in_progress | done | wontfix).
 *
 * Managers see everything; other users see only what they wrote themselves.
 * Text is stored as plain text (sanitized) and escaped on output.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Messages
 */
class GSM_Messages {

	const MAX_TEXT     = 2000;
	const RATE_PER_HR  = 20;
	const FB_TYPES     = array( 'bug', 'feature', 'question' );
	const FB_PRIORITY  = array( 'low', 'medium', 'high', 'critical' );
	const FB_STATUS    = array( 'open', 'in_progress', 'done', 'wontfix' );

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'gas_messages';
	}

	/**
	 * Per-user hourly limit (both kinds together).
	 *
	 * @param int $uid User id.
	 * @return bool True when allowed (and counted).
	 */
	public static function rate_ok( $uid ) {
		$k = 'gsm_msg_rate_' . (int) $uid;
		$n = (int) get_transient( $k );
		if ( $n >= self::RATE_PER_HR ) {
			return false;
		}
		set_transient( $k, $n + 1, HOUR_IN_SECONDS );
		return true;
	}

	/**
	 * Cut a string to a number of characters.
	 *
	 * @param string $s   Text.
	 * @param int    $len Max chars.
	 * @return string
	 */
	private static function clip( $s, $len ) {
		return function_exists( 'mb_substr' ) ? mb_substr( (string) $s, 0, $len ) : substr( (string) $s, 0, $len );
	}

	/**
	 * Create a message.
	 *
	 * @param string  $kind field|feedback.
	 * @param WP_User $user Author.
	 * @param array   $in   Raw input.
	 * @return int|WP_Error New id.
	 */
	public static function create( $kind, $user, $in ) {
		global $wpdb;
		$text = self::clip( trim( sanitize_textarea_field( isset( $in['text'] ) ? (string) $in['text'] : '' ) ), self::MAX_TEXT );
		if ( '' === $text ) {
			return new WP_Error( 'gsm_msg_empty', __( 'יש לכתוב את תוכן ההודעה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$row = array(
			'kind'       => 'feedback' === $kind ? 'feedback' : 'field',
			'created_at' => current_time( 'mysql' ),
			'user_id'    => (int) $user->ID,
			'author'     => self::clip( $user->display_name ? $user->display_name : $user->user_login, 255 ),
			'text'       => $text,
			'status'     => 'feedback' === $kind ? 'open' : 'unread',
		);
		if ( 'feedback' === $kind ) {
			$type            = isset( $in['type'] ) ? (string) $in['type'] : '';
			$prio            = isset( $in['priority'] ) ? (string) $in['priority'] : '';
			$row['fb_type']  = in_array( $type, self::FB_TYPES, true ) ? $type : 'bug';
			$row['priority'] = in_array( $prio, self::FB_PRIORITY, true ) ? $prio : 'medium';
			$row['title']    = self::clip( sanitize_text_field( isset( $in['title'] ) ? (string) $in['title'] : '' ), 160 );
			$row['area']     = self::clip( sanitize_text_field( isset( $in['area'] ) ? (string) $in['area'] : '' ), 120 );
		}
		if ( ! self::rate_ok( $user->ID ) ) {
			return new WP_Error( 'gsm_msg_rate', __( 'נשלחו יותר מדי הודעות בשעה האחרונה. נסו שוב מאוחר יותר.', 'gas-safety-manager' ), array( 'status' => 429 ) );
		}
		if ( false === $wpdb->insert( self::table(), $row ) ) {
			return new WP_Error( 'gsm_msg_db', __( 'שמירת ההודעה נכשלה.', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}
		return (int) $wpdb->insert_id;
	}

	/**
	 * List messages of a kind. Managers: all. Others: their own.
	 *
	 * @param string $kind   field|feedback.
	 * @param int    $uid    Viewer.
	 * @param bool   $manage Viewer is a manager.
	 * @param int    $limit  Max rows.
	 * @return array
	 */
	public static function list_for( $kind, $uid, $manage, $limit = 100 ) {
		global $wpdb;
		$t     = self::table();
		$limit = max( 1, min( 300, (int) $limit ) );
		if ( $manage ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE kind = %s ORDER BY id DESC LIMIT %d", $kind, $limit ), ARRAY_A );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE kind = %s AND user_id = %d ORDER BY id DESC LIMIT %d", $kind, (int) $uid, $limit ), ARRAY_A );
		}
		$out = array();
		foreach ( (array) $rows as $r ) {
			$m = array(
				'id'         => (int) $r['id'],
				'created_at' => (string) $r['created_at'],
				'author'     => (string) $r['author'],
				'mine'       => (int) $r['user_id'] === (int) $uid,
				'text'       => (string) $r['text'],
				'status'     => (string) $r['status'],
			);
			if ( 'feedback' === $kind ) {
				$m['type']       = (string) $r['fb_type'];
				$m['priority']   = (string) $r['priority'];
				$m['title']      = (string) $r['title'];
				$m['area']       = (string) $r['area'];
				$m['handled_by'] = (string) $r['handled_by'];
				$m['handled_at'] = (string) $r['handled_at'];
			}
			$out[] = $m;
		}
		return $out;
	}

	/**
	 * Fetch one row of a kind.
	 *
	 * @param string $kind Kind.
	 * @param int    $id   Id.
	 * @return array|null
	 */
	public static function get( $kind, $id ) {
		global $wpdb;
		$t = self::table();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d AND kind = %s", (int) $id, $kind ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * Set a status (validated by the caller) and record who handled it.
	 *
	 * @param int    $id     Id.
	 * @param string $status Status.
	 * @return void
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$u = wp_get_current_user();
		$wpdb->update(
			self::table(),
			array(
				'status'     => $status,
				'handled_by' => self::clip( $u && $u->ID ? $u->display_name : '', 255 ),
				'handled_at' => current_time( 'mysql' ),
			),
			array( 'id' => (int) $id )
		);
	}

	/**
	 * Unread field messages + active feedback (for the manager's badge).
	 *
	 * @return array { field_unread, feedback_active }
	 */
	public static function counts() {
		global $wpdb;
		$t = self::table();
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$out = array(
			'field_unread'    => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE kind = 'field' AND status = 'unread'" ),
			'feedback_active' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t} WHERE kind = 'feedback' AND status IN ('open','in_progress')" ),
		);
		// phpcs:enable
		return $out;
	}
}
