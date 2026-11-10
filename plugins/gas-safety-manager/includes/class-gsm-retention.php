<?php
/**
 * Data retention: periodic deletion of old log-type records.
 *
 * Personal data must not be kept longer than needed (Israeli Privacy
 * Protection Law / GDPR storage limitation). These tables only ever grew:
 *
 *   gas_scans              QR scan log (user id, hashed IP)        scanned_at
 *   gas_comm_log           email / WhatsApp delivery log            created_at
 *   gas_audit              audit trail incl. before_json snapshots  created_at
 *   gas_deletion_requests  privacy deletion requests — HANDLED only created_at
 *                          (status <> 'open'; open requests are never removed)
 *
 * Periods are in months; 0 = keep forever. Defaults (conservative, chosen by
 * the owner pending legal review): 24 / 24 / 84 / 24.
 *
 * Safety:
 *   - automatic runs start only GRACE_DAYS after the feature is first armed
 *     (so an upgrade never deletes production data the same day);
 *   - deletes in id batches (portable, short locks);
 *   - every run that deletes something is written to the audit log.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Retention
 */
class GSM_Retention {

	const CRON       = 'gsm_daily_retention';
	const OPTION     = 'gsm_retention';
	const ARMED      = 'gsm_retention_armed_at';
	const LAST       = 'gsm_retention_last';
	const GRACE_DAYS = 7;
	const BATCH      = 500;
	const MAX_MONTHS = 1200;

	/**
	 * Table key => [ table suffix, date column, human label, extra WHERE ].
	 *
	 * @return array
	 */
	public static function targets() {
		return array(
			'scans'     => array( 'scans', 'scanned_at', 'יומן סריקות QR', '' ),
			'comm'      => array( 'comm_log', 'created_at', 'יומן שליחות', '' ),
			'audit'     => array( 'audit', 'created_at', 'יומן בקרה', '' ),
			'deletions' => array( 'deletion_requests', 'created_at', 'בקשות מחיקה שטופלו', "status <> 'open'" ),
		);
	}

	/**
	 * Default periods in months.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'scans'     => 24,
			'comm'      => 24,
			'audit'     => 84,
			'deletions' => 24,
		);
	}

	/**
	 * Current periods (months, 0 = keep forever).
	 *
	 * @return array
	 */
	public static function config() {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		$out   = array();
		foreach ( self::defaults() as $k => $d ) {
			$out[ $k ] = isset( $saved[ $k ] ) ? self::clamp( $saved[ $k ] ) : $d;
		}
		return $out;
	}

	/**
	 * Save periods (unknown keys ignored, values clamped).
	 *
	 * @param array $in Input.
	 * @return array Saved config.
	 */
	public static function save( $in ) {
		$cur = self::config();
		foreach ( array_keys( self::defaults() ) as $k ) {
			if ( isset( $in[ $k ] ) ) {
				$cur[ $k ] = self::clamp( $in[ $k ] );
			}
		}
		update_option( self::OPTION, $cur, false );
		return $cur;
	}

	/**
	 * Months value -> int in [0, MAX_MONTHS].
	 *
	 * @param mixed $v Value.
	 * @return int
	 */
	private static function clamp( $v ) {
		$n = is_numeric( $v ) ? (int) $v : 0;
		return max( 0, min( self::MAX_MONTHS, $n ) );
	}

	/**
	 * Timestamp from which automatic runs may delete (armed + grace).
	 *
	 * @return int
	 */
	public static function active_from() {
		$armed = (int) get_option( self::ARMED, 0 );
		if ( ! $armed ) {
			$armed = time();
			update_option( self::ARMED, $armed, false );
		}
		return $armed + self::GRACE_DAYS * DAY_IN_SECONDS;
	}

	/**
	 * Keep the daily event scheduled (init). Arms the grace period once.
	 */
	public static function sync_cron() {
		self::active_from();
		if ( ! wp_next_scheduled( self::CRON ) ) {
			wp_schedule_event( time() + 2 * HOUR_IN_SECONDS, 'daily', self::CRON );
		}
	}

	/**
	 * Cron callback: respects the grace period.
	 *
	 * @return array|null Result, or null when still in the grace period.
	 */
	public static function run_scheduled() {
		if ( time() < self::active_from() ) {
			return null;
		}
		return self::run( 'auto' );
	}

	/**
	 * Cutoff datetime (site time, same as current_time('mysql')) for N months.
	 *
	 * @param int $months Months.
	 * @return string
	 */
	public static function cutoff( $months ) {
		return wp_date( 'Y-m-d H:i:s', strtotime( '-' . (int) $months . ' months', time() ) );
	}

	/**
	 * Delete everything older than its retention period.
	 *
	 * @param string $trigger 'auto' | 'manual'.
	 * @return array { key => deleted count, ... , '_at' => datetime, '_trigger' => string }
	 */
	public static function run( $trigger = 'manual' ) {
		global $wpdb;
		$cfg    = self::config();
		$result = array();
		$total  = 0;

		foreach ( self::targets() as $key => $t ) {
			$result[ $key ] = 0;
			$months         = isset( $cfg[ $key ] ) ? (int) $cfg[ $key ] : 0;
			if ( $months <= 0 ) {
				continue; // Keep forever.
			}
			$table = $wpdb->prefix . 'gas_' . $t[0];
			$col   = $t[1];
			$extra = '' !== $t[3] ? ' AND ' . $t[3] : '';
			$cut   = self::cutoff( $months );

			// Batches of ids: portable (no DELETE … LIMIT) and short locks.
			for ( $i = 0; $i < 200; $i++ ) {
				// Table / column / extra come from targets(), never from input.
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$table} WHERE {$col} < %s{$extra} ORDER BY id LIMIT %d", $cut, self::BATCH ) );
				if ( empty( $ids ) ) {
					break;
				}
				$ids = array_map( 'intval', $ids );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared -- ids are ints.
				$n = (int) $wpdb->query( "DELETE FROM {$table} WHERE id IN (" . implode( ',', $ids ) . ')' );
				$result[ $key ] += $n;
				if ( count( $ids ) < self::BATCH ) {
					break;
				}
			}
			$total += $result[ $key ];
		}

		$result['_at']      = current_time( 'mysql' );
		$result['_trigger'] = $trigger;
		update_option( self::LAST, $result, false );

		if ( $total > 0 && class_exists( 'GSM_Audit' ) ) {
			$parts = array();
			foreach ( self::targets() as $key => $t ) {
				if ( $result[ $key ] > 0 ) {
					$parts[] = $t[2] . ': ' . $result[ $key ];
				}
			}
			GSM_Audit::log( 'update', 'system', 0, '', 'ניקוי לפי מדיניות שמירה (' . ( 'auto' === $trigger ? 'אוטומטי' : 'ידני' ) . '): ' . implode( ', ', $parts ) );
		}
		return $result;
	}

	/**
	 * Rows that the next run would delete (for the settings screen).
	 *
	 * @return array key => count
	 */
	public static function preview() {
		global $wpdb;
		$cfg = self::config();
		$out = array();
		foreach ( self::targets() as $key => $t ) {
			$months = (int) $cfg[ $key ];
			if ( $months <= 0 ) {
				$out[ $key ] = 0;
				continue;
			}
			$table = $wpdb->prefix . 'gas_' . $t[0];
			$extra = '' !== $t[3] ? ' AND ' . $t[3] : '';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$out[ $key ] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$t[1]} < %s{$extra}", self::cutoff( $months ) ) );
		}
		return $out;
	}
}
