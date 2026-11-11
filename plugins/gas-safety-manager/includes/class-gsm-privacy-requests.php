<?php
/**
 * Privacy deletion requests: find every occurrence of a person and
 * anonymize it (Israeli Privacy Protection Law Amendment 13 / GDPR art. 17).
 *
 * A person's name / email can appear in free-text and list fields across the
 * system, and inside JSON snapshots (audit before_json, restore points). This
 * service scans all of them and replaces each whole-word match with a
 * pseudonym ("משתמש נמחק #N"). Professional records (inspections, faults)
 * are kept — only the identifying text is replaced. The same matcher is used
 * for the preview scan and for the replacement, so what the manager sees is
 * exactly what is changed.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Table / column names come from locations() (hard-coded); every value is
// bound through $wpdb->prepare().
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

/**
 * Class GSM_Privacy_Requests
 */
class GSM_Privacy_Requests {

	const BATCH = 500;

	/**
	 * Where personal identifiers can appear: [ table suffix, column, kind, label ].
	 * kind 'text' = plain/list text; 'json' = JSON document (strings walked).
	 *
	 * @return array
	 */
	public static function locations() {
		return array(
			array( 'equipment', 'pi_name', 'text', 'ציוד — חוקר ראשי' ),
			array( 'equipment', 'lab_managers', 'text', 'ציוד — מנהלי מעבדה' ),
			array( 'equipment', 'maint_staff', 'text', 'ציוד — אנשי תחזוקה' ),
			array( 'equipment', 'maint_emails', 'text', 'ציוד — אימיילים של תחזוקה' ),
			array( 'equipment', 'contacts', 'text', 'ציוד — אנשי קשר' ),
			array( 'equipment', 'phone', 'text', 'ציוד — טלפון' ),
			array( 'equipment', 'notes', 'text', 'ציוד — הערות' ),
			array( 'inspections', 'inspector', 'text', 'ביקורות — מבצע' ),
			array( 'inspections', 'inspector_email', 'text', 'ביקורות — אימייל מבצע' ),
			array( 'inspections', 'pi_name', 'text', 'ביקורות — חוקר ראשי' ),
			array( 'inspections', 'notes', 'text', 'ביקורות — הערות' ),
			array( 'faults', 'reporter', 'text', 'תקלות — מדווח' ),
			array( 'faults', 'description', 'text', 'תקלות — תיאור' ),
			array( 'qr_issuance', 'pi_name', 'text', 'הנפקות QR — חוקר' ),
			array( 'qr_issuance', 'note', 'text', 'הנפקות QR — הערה' ),
			array( 'attachments', 'uploaded_by', 'text', 'צרופות — הועלה על ידי' ),
			array( 'comm_log', 'recipient', 'text', 'יומן שליחות — נמען' ),
			array( 'audit', 'actor', 'text', 'יומן בקרה — מבצע' ),
			array( 'audit', 'item_name', 'text', 'יומן בקרה — פריט' ),
			array( 'audit', 'summary', 'text', 'יומן בקרה — פירוט' ),
			array( 'audit', 'before_json', 'json', 'יומן בקרה — עותק לפני מחיקה' ),
			array( 'restore_points', 'created_by', 'text', 'נקודות שחזור — נוצר על ידי' ),
			array( 'restore_points', 'payload', 'json', 'נקודות שחזור — תוכן' ),
		);
	}

	/**
	 * One request row.
	 *
	 * @param int $id Request id.
	 * @return array|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}gas_deletion_requests WHERE id = %d", (int) $id ), ARRAY_A );
		return $row ? $row : null;
	}

	/**
	 * All requests, newest first, with the account's current display name.
	 *
	 * @return array
	 */
	public static function list_all() {
		global $wpdb;
		$rows = (array) $wpdb->get_results( "SELECT * FROM {$wpdb->prefix}gas_deletion_requests ORDER BY id DESC LIMIT 500", ARRAY_A );
		foreach ( $rows as &$r ) {
			$u              = ! empty( $r['user_id'] ) ? get_userdata( (int) $r['user_id'] ) : false;
			$r['user_name'] = $u ? $u->display_name : '';
			$r['user_gone'] = ! $u;
		}
		unset( $r );
		return $rows;
	}

	/**
	 * Default identifiers for a request: the account's display name + emails.
	 *
	 * @param array $req Request row.
	 * @return string[]
	 */
	public static function default_identifiers( $req ) {
		$ids = array();
		if ( ! empty( $req['email'] ) ) {
			$ids[] = (string) $req['email'];
		}
		$u = ! empty( $req['user_id'] ) ? get_userdata( (int) $req['user_id'] ) : false;
		if ( $u ) {
			$ids[] = (string) $u->display_name;
			$ids[] = (string) $u->user_email;
		}
		return self::clean_identifiers( $ids );
	}

	/**
	 * Normalise identifiers: trim, drop too-short / duplicate values.
	 *
	 * @param array $ids Identifiers.
	 * @return string[]
	 */
	public static function clean_identifiers( $ids ) {
		$out = array();
		foreach ( (array) $ids as $s ) {
			$s = trim( preg_replace( '/\s+/u', ' ', (string) $s ) );
			// Very short values would over-match ("Dan" inside every list of names is fine, "D" is not).
			if ( function_exists( 'mb_strlen' ) ? mb_strlen( $s ) < 3 : strlen( $s ) < 3 ) {
				continue;
			}
			$out[ function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s ) ] = $s;
		}
		return array_values( $out );
	}

	/**
	 * Whole-word, case-insensitive regex matching any identifier.
	 * "Dana Levi" matches "Dana Levi" / "dana levi" but not "Dana Levitz".
	 *
	 * @param string[] $ids Identifiers.
	 * @return string
	 */
	public static function pattern( $ids ) {
		$alts = array();
		foreach ( $ids as $s ) {
			$q = preg_quote( $s, '/' );
			$q = preg_replace( '/\\\\?\s+/u', '\s+', $q ); // Any whitespace between words.
			$alts[] = $q;
		}
		usort(
			$alts,
			static function ( $a, $b ) {
				return strlen( $b ) - strlen( $a ); // Longest first ("Dana Levi" before "Dana").
			}
		);
		return '/(?<![\p{L}\p{N}@._-])(?:' . implode( '|', $alts ) . ')(?![\p{L}\p{N}@-]|\.[\p{L}\p{N}])/iu';
	}

	/**
	 * Replace matches inside a value (text) or inside every string of a JSON
	 * document. Returns [ new value, matches ].
	 *
	 * @param string $value   Stored value.
	 * @param string $kind    'text' | 'json'.
	 * @param string $pattern Regex.
	 * @param string $with    Replacement.
	 * @return array
	 */
	public static function replace_in( $value, $kind, $pattern, $with ) {
		$value = (string) $value;
		if ( 'json' !== $kind ) {
			$n   = 0;
			$new = preg_replace( $pattern, $with, $value, -1, $n );
			return array( null === $new ? $value : $new, (int) $n );
		}
		$data = json_decode( $value, true );
		if ( null === $data ) {
			return array( $value, 0 );
		}
		$count = 0;
		$walk  = function ( $v ) use ( &$walk, &$count, $pattern, $with ) {
			if ( is_array( $v ) ) {
				$out = array();
				foreach ( $v as $k => $x ) {
					$nk         = is_string( $k ) ? $walk( $k ) : $k;
					$out[ $nk ] = $walk( $x );
				}
				return $out;
			}
			if ( is_string( $v ) ) {
				$n = 0;
				$r = preg_replace( $pattern, $with, $v, -1, $n );
				$count += (int) $n;
				return null === $r ? $v : $r;
			}
			return $v;
		};
		$new = $walk( $data );
		return array( $count ? wp_json_encode( $new ) : $value, $count );
	}

	/**
	 * Scan (dry run) or anonymize.
	 *
	 * @param string[] $ids    Identifiers.
	 * @param bool     $apply  false = count only.
	 * @param string   $with   Pseudonym.
	 * @return array { locations: [ { label, rows, matches } ], total_rows, total_matches }
	 */
	public static function process( $ids, $apply, $with = '' ) {
		global $wpdb;
		$ids = self::clean_identifiers( $ids );
		$res = array( 'locations' => array(), 'total_rows' => 0, 'total_matches' => 0 );
		if ( empty( $ids ) ) {
			return $res;
		}
		$pattern = self::pattern( $ids );

		foreach ( self::locations() as $loc ) {
			list( $suffix, $col, $kind, $label ) = $loc;
			$table = $wpdb->prefix . 'gas_' . $suffix;

			// Pre-filter in SQL (LIKE on each identifier; for JSON also its
			// \u-escaped form, since wp_json_encode escapes non-ASCII).
			$likes  = array();
			$params = array();
			foreach ( $ids as $s ) {
				$likes[]  = "{$col} LIKE %s";
				$params[] = '%' . $wpdb->esc_like( $s ) . '%';
				if ( 'json' === $kind ) {
					$esc = substr( (string) wp_json_encode( $s ), 1, -1 );
					if ( $esc !== $s ) {
						$likes[]  = "{$col} LIKE %s";
						$params[] = '%' . $wpdb->esc_like( $esc ) . '%';
					}
				}
			}
			$where = '(' . implode( ' OR ', $likes ) . ')';
			$rows  = 0;
			$found = 0;
			$after = 0;
			for ( $i = 0; $i < 1000; $i++ ) {
				// One %d (cursor) + one %s per LIKE in $where + one %d (limit), in that order.
				// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built dynamically above.
				$q    = $wpdb->prepare( "SELECT id, {$col} AS v FROM {$table} WHERE id > %d AND {$where} ORDER BY id LIMIT %d", array_merge( array( $after ), $params, array( self::BATCH ) ) );
				$list = (array) $wpdb->get_results( $q, ARRAY_A );
				if ( empty( $list ) ) {
					break;
				}
				foreach ( $list as $r ) {
					$after = (int) $r['id'];
					list( $new, $n ) = self::replace_in( $r['v'], $kind, $pattern, $with );
					if ( $n > 0 ) {
						$rows++;
						$found += $n;
						if ( $apply ) {
							$wpdb->update( $table, array( $col => $new ), array( 'id' => (int) $r['id'] ) );
						}
					}
				}
				if ( count( $list ) < self::BATCH ) {
					break;
				}
			}
			if ( $rows ) {
				$res['locations'][] = array( 'label' => $label, 'rows' => $rows, 'matches' => $found );
			}
			$res['total_rows']    += $rows;
			$res['total_matches'] += $found;
		}
		return $res;
	}

	/**
	 * Set a request's status (open | done | rejected).
	 *
	 * @param int    $id     Request id.
	 * @param string $status Status.
	 * @return void
	 */
	public static function set_status( $id, $status ) {
		global $wpdb;
		$wpdb->update( $wpdb->prefix . 'gas_deletion_requests', array( 'status' => $status ), array( 'id' => (int) $id ) );
	}
}
