<?php
/**
 * Pull data from the old system (safety-qr-app portal / api.safety-tech.dev).
 *
 * Source: the old Worker's read-only BI export,
 *   GET <base>/?bi=<equipment|faults|inspections|audit>&format=json&token=<BI token>
 * The BI token is read-only (no user PIN / 2FA involved). It is kept in a
 * WordPress option, never sent to the browser after saving, never logged, and
 * only sent to the configured https host (changing the host wipes it).
 *
 * The export's exact JSON shape is not documented, so rows are normalized from
 * every shape the old system is known or likely to use:
 *   - raw Monday items: { id, name, column_values: [ { id, text, column:{title} } ] }
 *   - flat objects keyed by Monday column id ("text_mm3r2tt6": "...")
 *   - flat objects keyed by the Hebrew column title ("סוג הגז": "...")
 *   - { headers: [...], rows: [[...]] } tables
 * The column ids and titles come from the old portal's own code. A check
 * (dry run) shows what was recognized before anything is written.
 *
 * Writes go through GSM_Importer (service layer, validation, bulk mode) and
 * are idempotent: upsert by Monday id; rows without one are skipped, so a
 * repeated pull never creates duplicates. A pull never deletes anything.
 * A restore point is taken before every pull.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Legacy
 */
class GSM_Legacy {

	const OPTION       = 'gsm_legacy';
	const LAST         = 'gsm_legacy_last';
	const LOCK         = 'gsm_legacy_lock';
	const CRON         = 'gsm_legacy_sync';
	const DEFAULT_BASE = 'https://api.safety-tech.dev';
	const MAX_BYTES    = 15728640; // 15 MB per data set.
	const TIMEOUT      = 30;
	const MAX_ROWS     = 20000;
	const SETS         = array( 'equipment', 'faults', 'inspections', 'audit' );

	// ------------------------------------------------------------------
	// Settings
	// ------------------------------------------------------------------

	/**
	 * Stored settings.
	 *
	 * @return array { base, token, auto }
	 */
	public static function config() {
		$c = get_option( self::OPTION, array() );
		$c = is_array( $c ) ? $c : array();
		return array(
			'base'  => isset( $c['base'] ) && '' !== $c['base'] ? (string) $c['base'] : self::DEFAULT_BASE,
			'token' => isset( $c['token'] ) ? (string) $c['token'] : '',
			'auto'  => ! empty( $c['auto'] ),
		);
	}

	/**
	 * Is there enough to pull?
	 *
	 * @return bool
	 */
	public static function configured() {
		$c = self::config();
		return '' !== $c['base'] && '' !== $c['token'];
	}

	/**
	 * Settings safe to show in the browser (token masked).
	 *
	 * @return array
	 */
	public static function public_config() {
		$c    = self::config();
		$last = get_option( self::LAST, null );
		$next = wp_next_scheduled( self::CRON );
		return array(
			'base'         => $c['base'],
			'token_masked' => '' !== $c['token'] ? substr( $c['token'], 0, 4 ) . '…' . substr( $c['token'], -2 ) : '',
			'configured'   => self::configured(),
			'auto'         => $c['auto'],
			'next_run'     => $next ? wp_date( 'Y-m-d H:i', $next ) : '',
			'last'         => is_array( $last ) ? $last : null,
		);
	}

	/**
	 * Validate a token: the old system's tokens are URL-safe strings.
	 *
	 * @param string $token Token.
	 * @return string '' when invalid.
	 */
	public static function clean_token( $token ) {
		$token = trim( (string) $token );
		return preg_match( '/^[A-Za-z0-9._~-]{16,256}$/', $token ) ? $token : '';
	}

	/**
	 * Save settings.
	 *
	 * @param array $in { base?, token?, clear_token?, auto? }.
	 * @return array|WP_Error Public config.
	 */
	public static function save( $in ) {
		$c = self::config();
		// A whole BI link pasted from the old portal ("…?bi=equipment&token=…"):
		// take the token, and the host when none was given.
		if ( isset( $in['token'] ) && false !== strpos( (string) $in['token'], '://' ) ) {
			$q = array();
			wp_parse_str( (string) wp_parse_url( trim( (string) $in['token'] ), PHP_URL_QUERY ), $q );
			if ( empty( $in['base'] ) ) {
				$in['base'] = (string) $in['token'];
			}
			$in['token'] = isset( $q['token'] ) && is_string( $q['token'] ) ? $q['token'] : 'x';
		}
		if ( isset( $in['base'] ) ) {
			$base = GSM_Chat::clean_origin( $in['base'] );
			if ( '' === $base ) {
				return new WP_Error( 'gsm_legacy_base', __( 'כתובת השרת הישן אינה תקינה. נדרשת כתובת https עם שם מתחם (למשל https://api.safety-tech.dev).', 'gas-safety-manager' ), array( 'status' => 400 ) );
			}
			if ( $base !== $c['base'] ) {
				$c['token'] = ''; // The token belongs to the old host; never send it elsewhere.
			}
			$c['base'] = $base;
		}
		if ( ! empty( $in['clear_token'] ) ) {
			$c['token'] = '';
		}
		if ( isset( $in['token'] ) && '' !== trim( (string) $in['token'] ) ) {
			$t = self::clean_token( $in['token'] );
			if ( '' === $t ) {
				return new WP_Error( 'gsm_legacy_token', __( 'הטוקן אינו בפורמט תקין (16 תווים לפחות: אותיות באנגלית, ספרות, ‎- _ . ~).', 'gas-safety-manager' ), array( 'status' => 400 ) );
			}
			$c['token'] = $t;
		}
		if ( isset( $in['auto'] ) ) {
			$c['auto'] = (bool) $in['auto'];
		}
		update_option( self::OPTION, $c, false );
		self::sync_cron();
		return self::public_config();
	}

	// ------------------------------------------------------------------
	// Daily sync
	// ------------------------------------------------------------------

	/**
	 * Keep the daily event in line with the settings (init + after save).
	 */
	public static function sync_cron() {
		$c    = self::config();
		$want = $c['auto'] && self::configured();
		$next = wp_next_scheduled( self::CRON );
		if ( $want && ! $next ) {
			wp_schedule_event( time() + 3 * HOUR_IN_SECONDS, 'daily', self::CRON );
		} elseif ( ! $want && $next ) {
			wp_clear_scheduled_hook( self::CRON );
		}
	}

	/**
	 * Cron callback.
	 *
	 * @return array|WP_Error|null
	 */
	public static function run_scheduled() {
		$c = self::config();
		if ( ! $c['auto'] || ! self::configured() ) {
			return null;
		}
		return self::run( true, self::SETS, 'auto' );
	}

	// ------------------------------------------------------------------
	// Fetch
	// ------------------------------------------------------------------

	/**
	 * Fetch one data set from the old system.
	 *
	 * @param string $set Data set.
	 * @return array|WP_Error Decoded JSON.
	 */
	public static function fetch( $set ) {
		$c = self::config();
		if ( ! self::configured() ) {
			return new WP_Error( 'gsm_legacy_config', __( 'יש להגדיר את כתובת השרת הישן ואת טוקן ה-BI.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $set, self::SETS, true ) ) {
			return new WP_Error( 'gsm_legacy_set', 'Unknown data set.', array( 'status' => 400 ) );
		}
		$url = add_query_arg(
			array(
				'bi'     => $set,
				'format' => 'json',
				'token'  => rawurlencode( $c['token'] ),
			),
			$c['base'] . '/'
		);
		$res = GSM_Security::outbound_get(
			$url,
			array(
				'timeout'             => self::TIMEOUT,
				'limit_response_size' => self::MAX_BYTES + 1,
				'headers'             => array( 'Accept' => 'application/json' ),
				'user-agent'          => 'GasSafetyManager/' . ( defined( 'GSM_VERSION' ) ? GSM_VERSION : '' ) . '; legacy-pull',
			)
		);
		$label = self::set_label( $set );
		if ( is_wp_error( $res ) ) {
			// The message may contain the URL (and so the token): never pass it on.
			$why = 'gsm_unsafe_url' === $res->get_error_code() ? $res->get_error_message() : __( 'אין תשובה מהשרת הישן (תקלת רשת או זמן המתנה).', 'gas-safety-manager' );
			return new WP_Error( 'gsm_legacy_http', $label . ': ' . $why, array( 'status' => 502 ) );
		}
		$code = (int) wp_remote_retrieve_response_code( $res );
		if ( 401 === $code || 403 === $code ) {
			return new WP_Error( 'gsm_legacy_auth', __( 'האתר הישן דחה את הטוקן. העתיקו מחדש את טוקן ה-BI מלשונית "BI וניתוח" באתר הישן.', 'gas-safety-manager' ), array( 'status' => 502 ) );
		}
		if ( $code < 200 || $code >= 300 ) {
			/* translators: 1: data set, 2: HTTP status */
			return new WP_Error( 'gsm_legacy_http', sprintf( __( '%1$s: השרת הישן החזיר שגיאה (HTTP %2$d).', 'gas-safety-manager' ), $label, $code ), array( 'status' => 502 ) );
		}
		$body = (string) wp_remote_retrieve_body( $res );
		if ( strlen( $body ) > self::MAX_BYTES ) {
			return new WP_Error( 'gsm_legacy_size', $label . ': ' . __( 'התשובה גדולה מדי (מעל 15MB).', 'gas-safety-manager' ), array( 'status' => 502 ) );
		}
		$body = preg_replace( '/^\xEF\xBB\xBF/', '', $body );
		$data = json_decode( $body, true, 64 );
		if ( ! is_array( $data ) ) {
			return new WP_Error( 'gsm_legacy_json', $label . ': ' . __( 'התשובה אינה JSON תקין.', 'gas-safety-manager' ), array( 'status' => 502 ) );
		}
		return $data;
	}

	/**
	 * Hebrew label for a data set.
	 *
	 * @param string $set Set.
	 * @return string
	 */
	public static function set_label( $set ) {
		$l = array( 'equipment' => 'ציוד', 'faults' => 'תקלות', 'inspections' => 'ביקורות', 'audit' => 'יומן בקרה' );
		return isset( $l[ $set ] ) ? $l[ $set ] : $set;
	}

	// ------------------------------------------------------------------
	// Shape + mapping
	// ------------------------------------------------------------------

	/**
	 * Find the list of rows inside a decoded response.
	 *
	 * @param array $data Decoded JSON.
	 * @return array|null Rows (each an associative array), or null if not found.
	 */
	public static function extract_rows( $data ) {
		if ( ! is_array( $data ) ) {
			return null;
		}
		if ( self::is_list( $data ) ) {
			return $data;
		}
		// { headers: [...], rows: [[...]] }.
		if ( isset( $data['headers'], $data['rows'] ) && is_array( $data['headers'] ) && is_array( $data['rows'] ) ) {
			$h   = array_values( $data['headers'] );
			$out = array();
			foreach ( $data['rows'] as $r ) {
				if ( is_array( $r ) && self::is_list( $r ) ) {
					$row = array();
					foreach ( $h as $i => $name ) {
						$row[ (string) $name ] = isset( $r[ $i ] ) ? $r[ $i ] : '';
					}
					$out[] = $row;
				} elseif ( is_array( $r ) ) {
					$out[] = $r;
				}
			}
			return $out;
		}
		// Monday API: { data: { boards: [ { items_page: { items: [...] } } ] } }.
		if ( isset( $data['data']['boards'] ) && is_array( $data['data']['boards'] ) ) {
			$out = array();
			foreach ( $data['data']['boards'] as $b ) {
				if ( isset( $b['items_page']['items'] ) && is_array( $b['items_page']['items'] ) ) {
					$out = array_merge( $out, $b['items_page']['items'] );
				} elseif ( isset( $b['items'] ) && is_array( $b['items'] ) ) {
					$out = array_merge( $out, $b['items'] );
				}
			}
			return $out;
		}
		foreach ( array( 'items', 'data', 'rows', 'records', 'result', 'entries', 'log' ) as $k ) {
			if ( isset( $data[ $k ] ) && is_array( $data[ $k ] ) ) {
				$inner = self::extract_rows( $data[ $k ] );
				if ( null !== $inner ) {
					return $inner;
				}
			}
		}
		return null;
	}

	/**
	 * Is this a 0..n-1 list?
	 *
	 * @param array $a Array.
	 * @return bool
	 */
	private static function is_list( $a ) {
		return array() === $a || array_keys( $a ) === range( 0, count( $a ) - 1 );
	}

	/**
	 * Normalize a column key for matching.
	 *
	 * @param string $k Key.
	 * @return string
	 */
	public static function norm_key( $k ) {
		$k = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $k ), 'UTF-8' ) : strtolower( trim( (string) $k ) );
		return (string) preg_replace( '/[\s\'"׳״`()\[\]\/\\\\.:_\-]+/u', '', $k );
	}

	/**
	 * A cell value as plain text.
	 *
	 * @param mixed $v Value.
	 * @return string
	 */
	private static function text( $v ) {
		if ( null === $v || is_bool( $v ) ) {
			return is_bool( $v ) ? ( $v ? '1' : '' ) : '';
		}
		if ( is_scalar( $v ) ) {
			return trim( (string) $v );
		}
		if ( is_array( $v ) ) {
			if ( isset( $v['text'] ) && is_scalar( $v['text'] ) ) {
				return trim( (string) $v['text'] );
			}
			if ( isset( $v['display_value'] ) && is_scalar( $v['display_value'] ) ) {
				return trim( (string) $v['display_value'] );
			}
			if ( isset( $v['name'] ) && is_scalar( $v['name'] ) ) {
				return trim( (string) $v['name'] );
			}
			if ( self::is_list( $v ) ) {
				$parts = array();
				foreach ( $v as $x ) {
					$t = self::text( $x );
					if ( '' !== $t ) {
						$parts[] = $t;
					}
				}
				return implode( ', ', $parts );
			}
		}
		return '';
	}

	/**
	 * Flatten a row into normalized-key => [ original key, text ].
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function flatten( $row ) {
		$out = array();
		$put = function ( $key, $val ) use ( &$out ) {
			$nk = self::norm_key( $key );
			if ( '' === $nk ) {
				return;
			}
			$t = self::text( $val );
			// Keep the first non-empty value seen for a key.
			if ( ! isset( $out[ $nk ] ) || ( '' === $out[ $nk ][1] && '' !== $t ) ) {
				$out[ $nk ] = array( (string) $key, $t );
			}
		};
		foreach ( $row as $k => $v ) {
			if ( 'column_values' === $k && is_array( $v ) ) {
				foreach ( $v as $cv ) {
					if ( ! is_array( $cv ) ) {
						continue;
					}
					$val = isset( $cv['text'] ) ? $cv['text'] : ( isset( $cv['display_value'] ) ? $cv['display_value'] : '' );
					if ( isset( $cv['id'] ) ) {
						$put( $cv['id'], $val );
					}
					$title = isset( $cv['column']['title'] ) ? $cv['column']['title'] : ( isset( $cv['title'] ) ? $cv['title'] : '' );
					if ( '' !== (string) $title ) {
						$put( $title, $val );
					}
				}
				continue;
			}
			$put( $k, $v );
		}
		return $out;
	}

	/**
	 * Column map per data set: field => aliases (first non-empty wins).
	 * Monday column ids and titles are from the old portal's code.
	 *
	 * @param string $set Set.
	 * @return array
	 */
	public static function aliases( $set ) {
		$id = array( 'monday_id', 'id', 'item_id', 'itemid', 'pulse_id', 'מזהה', 'מזהה monday', 'monday id' );
		switch ( $set ) {
			case 'equipment':
				return array(
					'monday_id'       => $id,
					'name'            => array( 'name', 'שם הציוד', 'שם ציוד', 'שם', 'item_name' ),
					'asset_code'      => array( 'asset_code', 'מספר נכס', 'מק"ט' ),
					'gas_type'        => array( 'text_mm3r2tt6', 'סוג הגז', 'סוג גז', 'gas_type', 'gas' ),
					'category'        => array( 'text_mm3rvzp5', 'קטגוריה', 'category' ),
					'faculty'         => array( 'text_mm3r5f10', 'פקולטה / יחידה', 'פקולטה', 'יחידה', 'faculty' ),
					'building'        => array( 'text_mm3r30xb', 'בניין', 'building' ),
					'floor'           => array( 'text_mm3r2m6d', 'קומה', 'floor' ),
					'room'            => array( 'text_mm2w71wv', "מס' חדר", 'מספר חדר', 'חדר', 'room' ),
					'location'        => array( 'text_mm2pskf4', 'מיקום', 'location' ),
					'functional_loc'  => array( 'text_mm3rv5gc', 'מיקום פונקציונלי', 'functional_loc' ),
					'gis'             => array( 'text_mm3r3s3m', 'gis' ),
					'panel_number'    => array( 'numeric_mm3snp0z', 'מספר פאנל', 'פאנל', 'panel_number', 'panel' ),
					'work_pressure'   => array( 'text_mm3rfday', 'לחץ עבודה', 'work_pressure' ),
					'cylinders_count' => array( 'text_mm3rd2qy', 'מספר צילינדרים', 'cylinders_count' ),
					'cylinders_stock' => array( 'text_mm3rn3n1', 'מלאי צילינדרים', 'cylinders_stock' ),
					'phone'           => array( 'text_mm46yvqa', 'טלפון', 'phone' ),
					'contacts'        => array( 'text_mm3rp14r', 'אנשי קשר', 'contacts' ),
					'pi_name'         => array( 'board_relation_mm3ssfj6', 'חוקר ראשי (PI)', 'חוקר ראשי', 'pi_name', 'pi', 'text_mm2wmj0v', 'מעבדה / חוקר ראשי(PI)' ),
					'lab_managers'    => array( 'board_relation_mm3sy4q0', 'מנהל מעבדה', 'מנהלי מעבדה', 'lab_managers' ),
					'maint_staff'     => array( 'board_relation_mm2s7r7s', 'אנשי תחזוקה', 'maint_staff' ),
					'maint_emails'    => array( 'אימייל תחזוקה', 'maint_emails' ),
					'status'          => array( 'color_mm2p6nk5', 'status_mm3sj1ne', 'color_mm3stf3p', 'סטטוס', 'status' ),
					'last_quarterly'  => array( 'date_mm2v49b4', 'ביקורת רבעונית', 'ביקורת אחרונה', 'last_quarterly' ),
					'next_quarterly'  => array( 'date_mm3sjryd', 'תאריך ביקורת הבאה', 'ביקורת הבאה', 'next_quarterly' ),
					'notes'           => array( 'text_mm3rzbd5', 'הערות', 'notes', 'text_mm2wsbkg', 'תיאור קצר' ),
				);
			case 'faults':
				return array(
					'monday_id'      => $id,
					'_name'          => array( 'name', 'שם התקלה', 'item_name' ),
					'equipment_name' => array( 'board_relation_mm2sd8tg', 'ציוד', 'שם הציוד', 'equipment_name', 'equipment' ),
					'reporter'       => array( 'text_mm2p33v6', 'מדווח', 'מדווח/ת', 'reporter' ),
					'description'    => array( 'text_mm2pye47', 'תיאור תקלה', 'תיאור', 'description' ),
					'status'         => array( 'color_mm2prmk2', 'סטטוס', 'status' ),
					'priority'       => array( 'color_mm2pww64', 'עדיפות', 'priority' ),
					'opened_date'    => array( 'date_mm2pmn2j', 'תאריך פתיחה', 'opened_date', 'date' ),
					'resolved_date'  => array( 'תאריך סגירה', 'תאריך טיפול', 'resolved_date' ),
				);
			case 'inspections':
				return array(
					'monday_id'       => $id,
					'_name'           => array( 'name', 'שם ביקורת', 'item_name' ),
					'equipment_name'  => array( 'board_relation_mm2sk6kx', 'ציוד', 'שם הציוד', 'equipment_name', 'equipment' ),
					'inspection_date' => array( 'date_mm2pwq6d', 'תאריך', 'תאריך ביקורת', 'inspection_date', 'date' ),
					'inspector'       => array( 'text_mm2pn2ct', 'מבצע', 'מבצע הביקורת', 'בודק', 'inspector' ),
					'pi_name'         => array( 'text_mm4b51w4', 'חוקר ראשי', 'pi_name' ),
					'panel_number'    => array( 'text_mm4bxve6', 'מספר פאנל', 'panel_number' ),
					'type'            => array( 'color_mm2v1xt7', 'סוג ביקורת', 'type' ),
					'result'          => array( 'color_mm2p37w3', 'תוצאה כללית', 'תוצאה', 'result' ),
					'notes'           => array( 'text_mm2p5b8d', 'הערות ביקורת', 'הערות', 'notes' ),
					'_checklist'      => array( 'text_mm2prgap', 'ממצאים', "צ'קליסט", 'checklist', 'findings' ),
				);
			case 'audit':
				return array(
					'created_at' => array( 'created_at', 'timestamp', 'ts', 'time', 'date', 'at', 'זמן', 'תאריך' ),
					'actor'      => array( 'actor', 'user', 'by', 'email', 'user_email', 'משתמש', 'מבצע' ),
					'action'     => array( 'action', 'type', 'op', 'פעולה' ),
					'item_name'  => array( 'item_name', 'item', 'name', 'target', 'פריט' ),
					'summary'    => array( 'summary', 'details', 'detail', 'description', 'message', 'text', 'פירוט', 'תיאור' ),
				);
		}
		return array();
	}

	/**
	 * Columns we know about and deliberately do not import.
	 *
	 * @return string[] Normalized keys.
	 */
	private static function ignored_keys() {
		$k = array( 'text_mm2pk11n', 'תקלות פתוחות', 'date_mm2pp8f6', 'תאריך רכישה', 'link_mm2wtez6', 'קישור לחוברת',
			'group', 'board', 'board_id', 'created_at', 'updated_at', 'state', 'subitems', 'creator', 'url', 'relative_link',
			'column_values', 'assets', 'updates', 'parent_item' );
		return array_map( array( __CLASS__, 'norm_key' ), $k );
	}

	/**
	 * Map one flattened row to fields.
	 *
	 * @param array $flat    Flattened row.
	 * @param array $aliases Field => aliases.
	 * @param array $used    Collects original key => field (by reference).
	 * @return array field => text
	 */
	private static function map_row( $flat, $aliases, &$used ) {
		$out = array();
		foreach ( $aliases as $field => $list ) {
			foreach ( $list as $alias ) {
				$nk = self::norm_key( $alias );
				if ( isset( $flat[ $nk ] ) ) {
					$used[ $flat[ $nk ][0] ] = $field;
					if ( '' !== $flat[ $nk ][1] && ! isset( $out[ $field ] ) ) {
						$out[ $field ] = $flat[ $nk ][1];
					}
				}
			}
		}
		return $out;
	}

	// ------------------------------------------------------------------
	// Value conversion
	// ------------------------------------------------------------------

	/**
	 * Common date formats to Y-m-d (else '').
	 *
	 * @param string $v Value.
	 * @return string
	 */
	public static function iso_date( $v ) {
		$v = trim( (string) $v );
		if ( preg_match( '/^(\d{4})-(\d{2})-(\d{2})/', $v, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ? $m[1] . '-' . $m[2] . '-' . $m[3] : '';
		}
		if ( preg_match( '/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})/', $v, $m ) ) {
			return checkdate( (int) $m[2], (int) $m[1], (int) $m[3] ) ? sprintf( '%04d-%02d-%02d', $m[3], $m[2], $m[1] ) : '';
		}
		return '';
	}

	/**
	 * Map free text to an allowed key by regex, else ''.
	 *
	 * @param string $v     Value.
	 * @param array  $rules [ regex => key ] in priority order.
	 * @return string
	 */
	private static function pick( $v, $rules ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		foreach ( $rules as $re => $key ) {
			if ( preg_match( $re, $v ) ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * Old checklist text to items ("✓ item", "✗ item", "— item", "item [תקין]").
	 *
	 * @param string $txt Text.
	 * @return array [ [ item_text, item_result ] ]
	 */
	public static function parse_checklist( $txt ) {
		$items = array();
		foreach ( preg_split( '/\n|\s\/\s/u', (string) $txt ) as $p ) {
			$p = trim( $p );
			if ( '' === $p ) {
				continue;
			}
			$res = '';
			if ( preg_match( '/^\x{2713}\s*/u', $p ) ) {
				$res = 'ok';
				$p   = preg_replace( '/^\x{2713}\s*/u', '', $p );
			} elseif ( preg_match( '/^[\x{2717}\x{2718}]\s*/u', $p ) ) {
				$res = 'defect';
				$p   = preg_replace( '/^[\x{2717}\x{2718}]\s*/u', '', $p );
			} elseif ( preg_match( '/^[\x{2014}\-]\s*/u', $p ) ) {
				$res = 'na';
				$p   = preg_replace( '/^[\x{2014}\-]\s*/u', '', $p );
			} elseif ( preg_match( '/^\x{1F4DD}/u', $p ) ) {
				continue; // A free-text note line, not a checklist item.
			}
			if ( preg_match( '/\[([^\]]+)\]\s*$/u', $p, $m ) ) {
				$p = trim( preg_replace( '/\[[^\]]+\]\s*$/u', '', $p ) );
				if ( '' === $res ) {
					$res = preg_match( '/ליקוי|לא\s/u', $m[1] ) ? 'defect' : ( preg_match( '/תקין/u', $m[1] ) ? 'ok' : '' );
				}
			}
			if ( '' === $p ) {
				continue;
			}
			$items[] = array(
				'item_text'   => function_exists( 'mb_substr' ) ? mb_substr( $p, 0, 500 ) : substr( $p, 0, 500 ),
				'item_result' => '' !== $res ? $res : 'na',
			);
		}
		return $items;
	}

	/**
	 * Equipment name from an old inspection name ("ביקורת רבעונית - X - 01.02.2026").
	 *
	 * @param string $name Name.
	 * @return string
	 */
	private static function equip_from_name( $name ) {
		$s = (string) $name;
		$s = preg_replace( '/\b\d{1,2}\.\d{1,2}\.\d{4}\b/u', '', $s );
		$s = preg_replace( '/\b\d{4}-\d{2}-\d{2}\b/u', '', $s );
		$s = preg_replace( '/ביקורת\s*(רבעונית|חודשית|שנתית|אד[\s-]?הוק)?/u', '', $s );
		$parts = array_filter( array_map( 'trim', explode( '-', $s ) ), 'strlen' );
		return implode( ' - ', $parts );
	}

	/**
	 * Convert a mapped row into importer fields.
	 *
	 * @param string $set Set.
	 * @param array  $r   field => text.
	 * @return array
	 */
	private static function convert( $set, $r ) {
		if ( 'equipment' === $set ) {
			if ( isset( $r['status'] ) ) {
				$r['status'] = self::pick(
					$r['status'],
					array(
						'/^(לא\s*פעיל|מושבת|inactive|disabled)/iu' => 'inactive',
						'/(תחזוק|maintenance)/iu'                  => 'maintenance',
						'/(תקול|תקלה|לא\s*תקין|faulty|fault)/iu'   => 'faulty',
						'/(פעיל|תקין|active|ok)/iu'                => 'active',
					)
				);
			}
			foreach ( array( 'last_quarterly', 'next_quarterly' ) as $d ) {
				if ( isset( $r[ $d ] ) ) {
					$r[ $d ] = self::iso_date( $r[ $d ] );
				}
			}
			if ( isset( $r['panel_number'] ) && preg_match( '/^\d+\.0+$/', $r['panel_number'] ) ) {
				$r['panel_number'] = (string) (int) $r['panel_number'];
			}
		} elseif ( 'faults' === $set ) {
			if ( empty( $r['description'] ) && ! empty( $r['_name'] ) ) {
				$r['description'] = $r['_name'];
			}
			if ( isset( $r['status'] ) ) {
				$r['status'] = self::pick(
					$r['status'],
					array(
						'/(לא\s*טופל|פתוח|חדש|open|new)/iu'         => 'open',
						'/(בטיפול|בעבודה|progress|working)/iu'      => 'in_progress',
						'/(סגור|closed)/iu'                         => 'closed',
						'/(טופל|נפתר|תוקן|resolved|done|fixed)/iu' => 'resolved',
					)
				);
			}
			if ( isset( $r['priority'] ) ) {
				$r['priority'] = self::pick(
					$r['priority'],
					array(
						'/(דחו|קריט|urgent|critical)/iu' => 'urgent',
						'/(גבוה|high)/iu'                => 'high',
						'/(נמוכ|low)/iu'                 => 'low',
						'/(רגיל|בינונ|normal|medium)/iu' => 'normal',
					)
				);
			}
			foreach ( array( 'opened_date', 'resolved_date' ) as $d ) {
				if ( isset( $r[ $d ] ) ) {
					$r[ $d ] = self::iso_date( $r[ $d ] );
				}
			}
		} elseif ( 'inspections' === $set ) {
			$hint = ( isset( $r['type'] ) ? $r['type'] : '' ) . ' ' . ( isset( $r['notes'] ) ? $r['notes'] : '' ) . ' ' . ( isset( $r['_name'] ) ? $r['_name'] : '' );
			$r['type'] = self::pick( $hint, array( '/(רבעונ|quarter)/iu' => 'quarterly', '/(חודש|month)/iu' => 'monthly' ) );
			if ( '' === $r['type'] ) {
				$r['type'] = 'adhoc';
			}
			if ( isset( $r['result'] ) ) {
				$r['result'] = self::pick( $r['result'], array( '/(ליקוי|לא\s*תקין|נכשל|defect|fail)/iu' => 'defects', '/(תקין|עבר|ok|pass)/iu' => 'ok' ) );
			}
			if ( isset( $r['inspection_date'] ) ) {
				$r['inspection_date'] = self::iso_date( $r['inspection_date'] );
			}
			if ( ! empty( $r['_checklist'] ) ) {
				$items = self::parse_checklist( $r['_checklist'] );
				if ( $items ) {
					$r['items'] = $items;
				}
			}
			if ( empty( $r['equipment_name'] ) && ! empty( $r['_name'] ) ) {
				$r['equipment_name'] = self::equip_from_name( $r['_name'] );
			}
		}
		if ( ! empty( $r['equipment_name'] ) && false !== strpos( $r['equipment_name'], ', ' ) ) {
			$r['equipment_name_alt'] = trim( strstr( $r['equipment_name'], ', ', true ) );
		}
		foreach ( array_keys( $r ) as $k ) {
			if ( '_' === substr( $k, 0, 1 ) || '' === $r[ $k ] ) {
				unset( $r[ $k ] ); // Empty values never blank an existing field.
			}
		}
		return $r;
	}

	/**
	 * Normalize a fetched data set.
	 *
	 * @param string $set  Set.
	 * @param array  $data Decoded JSON.
	 * @return array|WP_Error { rows, columns[], unknown[], fetched, no_id }
	 */
	public static function normalize( $set, $data ) {
		$rows = self::extract_rows( $data );
		if ( null === $rows ) {
			$keys = is_array( $data ) ? array_slice( array_keys( $data ), 0, 12 ) : array();
			/* translators: 1: data set, 2: keys */
			return new WP_Error( 'gsm_legacy_shape', sprintf( __( '%1$s: מבנה התשובה לא זוהה (מפתחות: %2$s). שום דבר לא נכתב.', 'gas-safety-manager' ), self::set_label( $set ), implode( ', ', array_map( 'strval', $keys ) ) ), array( 'status' => 502 ) );
		}
		if ( count( $rows ) > self::MAX_ROWS ) {
			/* translators: 1: data set, 2: max rows */
			return new WP_Error( 'gsm_legacy_rows', sprintf( __( '%1$s: יותר מ-%2$d רשומות.', 'gas-safety-manager' ), self::set_label( $set ), self::MAX_ROWS ), array( 'status' => 413 ) );
		}
		$aliases = self::aliases( $set );
		$ignored = self::ignored_keys();
		$used    = array();
		$seen    = array();
		$out     = array();
		$no_id   = 0;
		foreach ( $rows as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$flat = self::flatten( $raw );
			foreach ( $flat as $nk => $pair ) {
				$seen[ $pair[0] ] = $nk;
			}
			$mapped = self::map_row( $flat, $aliases, $used );
			if ( 'audit' === $set ) {
				$out[] = $mapped;
				continue;
			}
			if ( empty( $mapped['monday_id'] ) || ! preg_match( '/^[0-9A-Za-z_-]{1,50}$/', $mapped['monday_id'] ) ) {
				$no_id++;
				continue; // Without a stable id a repeated pull would duplicate it.
			}
			$out[] = self::convert( $set, $mapped );
		}
		$labels  = self::field_labels();
		$columns = array();
		$unknown = array();
		foreach ( $seen as $orig => $nk ) {
			if ( isset( $used[ $orig ] ) ) {
				$f         = $used[ $orig ];
				$columns[] = array( 'column' => (string) $orig, 'field' => isset( $labels[ $f ] ) ? $labels[ $f ] : $f );
			} elseif ( ! in_array( $nk, $ignored, true ) ) {
				$unknown[] = (string) $orig;
			}
		}
		return array(
			'rows'    => $out,
			'fetched' => count( $rows ),
			'no_id'   => $no_id,
			'columns' => array_slice( $columns, 0, 80 ),
			'unknown' => array_slice( $unknown, 0, 80 ),
		);
	}

	/**
	 * Hebrew labels for fields (preview).
	 *
	 * @return array
	 */
	public static function field_labels() {
		$l = class_exists( 'GSM_REST_Import' ) ? GSM_REST_Import::fields() : array();
		return array_merge(
			$l,
			array(
				'monday_id'       => 'מזהה Monday',
				'_name'           => 'שם הפריט',
				'equipment_name'  => 'ציוד (קישור)',
				'reporter'        => 'מדווח',
				'description'     => 'תיאור',
				'priority'        => 'עדיפות',
				'opened_date'     => 'תאריך פתיחה',
				'resolved_date'   => 'תאריך סגירה',
				'inspection_date' => 'תאריך ביקורת',
				'inspector'       => 'מבצע',
				'type'            => 'סוג ביקורת',
				'result'          => 'תוצאה',
				'_checklist'      => "צ'קליסט",
				'created_at'      => 'זמן',
				'actor'           => 'משתמש',
				'action'          => 'פעולה',
				'item_name'       => 'פריט',
				'summary'         => 'פירוט',
			)
		);
	}

	// ------------------------------------------------------------------
	// Audit archive
	// ------------------------------------------------------------------

	/**
	 * Import old audit entries as archive rows (action "legacy", not undoable).
	 * Duplicates (same time + actor + item + summary) are skipped; entries
	 * older than the audit retention period are not imported.
	 *
	 * @param array $rows  Mapped audit rows.
	 * @param bool  $apply Write, or only count.
	 * @return array { new, duplicates, too_old, invalid }
	 */
	private static function audit_archive( $rows, $apply ) {
		global $wpdb;
		$t      = $wpdb->prefix . 'gas_audit';
		$months = 0;
		if ( class_exists( 'GSM_Retention' ) ) {
			$cfg    = GSM_Retention::config();
			$months = isset( $cfg['audit'] ) ? (int) $cfg['audit'] : 0;
		}
		$cut   = $months > 0 ? wp_date( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) ) : '';
		$res   = array( 'new' => 0, 'duplicates' => 0, 'too_old' => 0, 'invalid' => 0 );
		$cut_s = array();
		foreach ( $rows as $r ) {
			$ts = isset( $r['created_at'] ) ? strtotime( $r['created_at'] ) : false;
			if ( ! $ts ) {
				$res['invalid']++;
				continue;
			}
			$when = wp_date( 'Y-m-d H:i:s', $ts );
			if ( '' !== $cut && $when < $cut ) {
				$res['too_old']++;
				continue;
			}
			$actor   = self::clip( isset( $r['actor'] ) ? $r['actor'] : '', 255 );
			$item    = self::clip( isset( $r['item_name'] ) ? $r['item_name'] : '', 255 );
			$action  = isset( $r['action'] ) ? sanitize_text_field( $r['action'] ) : '';
			$summary = '[מהמערכת הישנה] ' . ( '' !== $action ? $action . ': ' : '' ) . sanitize_textarea_field( isset( $r['summary'] ) ? $r['summary'] : '' );
			$summary = self::clip( $summary, 2000 );
			$key     = $when . "\n" . $actor . "\n" . $item . "\n" . $summary;
			if ( isset( $cut_s[ $key ] ) ) {
				$res['duplicates']++;
				continue;
			}
			$cut_s[ $key ] = 1;
			$exists = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE action = 'legacy' AND created_at = %s AND actor = %s AND item_name = %s AND summary = %s", $when, $actor, $item, $summary ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			if ( $exists ) {
				$res['duplicates']++;
				continue;
			}
			$res['new']++;
			if ( $apply ) {
				$wpdb->insert(
					$t,
					array(
						'created_at' => $when,
						'actor'      => $actor,
						'actor_id'   => null,
						'action'     => 'legacy',
						'entity'     => 'legacy',
						'entity_id'  => null,
						'item_name'  => $item,
						'summary'    => $summary,
						'undone'     => 0,
					)
				);
			}
		}
		return $res;
	}

	/**
	 * Sanitize + cut a string.
	 *
	 * @param string $s   Text.
	 * @param int    $len Max chars.
	 * @return string
	 */
	private static function clip( $s, $len ) {
		$s = sanitize_text_field( (string) $s );
		return function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $len ) : substr( $s, 0, $len );
	}

	// ------------------------------------------------------------------
	// Run
	// ------------------------------------------------------------------

	/**
	 * Check (dry run) or pull.
	 *
	 * Everything is fetched and normalized first; if any set fails, nothing is
	 * written. A pull takes a restore point first and aborts if that fails.
	 *
	 * @param bool   $apply Write.
	 * @param array  $sets  Data sets.
	 * @param string $mode  manual|auto.
	 * @return array|WP_Error
	 */
	public static function run( $apply, $sets = self::SETS, $mode = 'manual' ) {
		$sets = array_values( array_intersect( self::SETS, (array) $sets ) );
		if ( ! $sets ) {
			return new WP_Error( 'gsm_legacy_set', __( 'לא נבחרו נתונים למשיכה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( get_transient( self::LOCK ) ) {
			return new WP_Error( 'gsm_legacy_busy', __( 'משיכה אחרת רצה כרגע. נסו שוב בעוד כמה דקות.', 'gas-safety-manager' ), array( 'status' => 409 ) );
		}
		set_transient( self::LOCK, 1, 15 * MINUTE_IN_SECONDS );
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 300 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		try {
			$out = self::run_locked( $apply, $sets, $mode );
		} finally {
			delete_transient( self::LOCK );
		}
		if ( $apply ) {
			update_option(
				self::LAST,
				array(
					'at'     => current_time( 'mysql' ),
					'mode'   => $mode,
					'ok'     => ! is_wp_error( $out ),
					'error'  => is_wp_error( $out ) ? $out->get_error_message() : '',
					'counts' => is_wp_error( $out ) ? null : $out['counts'],
				),
				false
			);
			if ( is_wp_error( $out ) && class_exists( 'GSM_Audit' ) ) {
				GSM_Audit::log( 'import', 'import', 0, 'משיכה מהאתר הישן', 'נכשלה: ' . $out->get_error_message() );
			}
		}
		return $out;
	}

	/**
	 * Body of run() under the lock.
	 *
	 * @param bool   $apply Write.
	 * @param array  $sets  Sets.
	 * @param string $mode  Mode.
	 * @return array|WP_Error
	 */
	private static function run_locked( $apply, $sets, $mode ) {
		$norm = array();
		foreach ( $sets as $set ) {
			$data = self::fetch( $set );
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			$n = self::normalize( $set, $data );
			if ( is_wp_error( $n ) ) {
				return $n;
			}
			$norm[ $set ] = $n;
		}

		$report = array();
		$known  = array();
		foreach ( (array) GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) ) as $e ) {
			if ( ! empty( $e['name'] ) ) {
				$known[ $e['name'] ] = 1;
			}
		}
		if ( isset( $norm['equipment'] ) ) {
			foreach ( $norm['equipment']['rows'] as $r ) {
				if ( ! empty( $r['name'] ) ) {
					$known[ $r['name'] ] = 1;
				}
			}
		}
		$repos = array( 'equipment' => 'GSM_Equipment_Repo', 'faults' => 'GSM_Faults_Repo', 'inspections' => 'GSM_Inspections_Repo' );
		foreach ( $norm as $set => $n ) {
			$rep = array(
				'label'   => self::set_label( $set ),
				'fetched' => $n['fetched'],
				'columns' => $n['columns'],
				'unknown' => $n['unknown'],
			);
			if ( 'audit' === $set ) {
				$rep['audit'] = self::audit_archive( $n['rows'], false );
			} else {
				$create  = 0;
				$update  = 0;
				$unlinked = 0;
				foreach ( $n['rows'] as $r ) {
					if ( call_user_func( array( $repos[ $set ], 'get_by_monday_id' ), $r['monday_id'] ) ) {
						$update++;
					} else {
						$create++;
					}
					if ( 'equipment' !== $set ) {
						$nm = isset( $r['equipment_name'] ) ? $r['equipment_name'] : '';
						$al = isset( $r['equipment_name_alt'] ) ? $r['equipment_name_alt'] : '';
						if ( ! isset( $known[ $nm ] ) && ! isset( $known[ $al ] ) ) {
							$unlinked++;
						}
					}
				}
				$rep['valid']        = count( $n['rows'] );
				$rep['no_id']        = $n['no_id'];
				$rep['would_create'] = $create;
				$rep['would_update'] = $update;
				if ( 'equipment' !== $set ) {
					$rep['unlinked'] = $unlinked;
				}
				$rep['sample'] = array_map( array( __CLASS__, 'sample_row' ), array_slice( $n['rows'], 0, 3 ) );
			}
			$report[ $set ] = $rep;
		}

		if ( ! $apply ) {
			return array( 'applied' => false, 'sets' => $report );
		}

		// Safety net first.
		$snap = GSM_Restore_Service::snapshot( 'לפני משיכה מהאתר הישן', 'auto' === $mode ? 'auto' : 'manual' );
		if ( is_wp_error( $snap ) || empty( $snap['id'] ) ) {
			return new WP_Error( 'gsm_legacy_snapshot', __( 'לא ניתן היה ליצור נקודת שחזור, ולכן המשיכה בוטלה. שום דבר לא השתנה.', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}

		$payload = array(
			'equipment'   => isset( $norm['equipment'] ) ? $norm['equipment']['rows'] : array(),
			'faults'      => isset( $norm['faults'] ) ? $norm['faults']['rows'] : array(),
			'inspections' => isset( $norm['inspections'] ) ? $norm['inspections']['rows'] : array(),
		);
		$counts = array();
		if ( $payload['equipment'] || $payload['faults'] || $payload['inspections'] ) {
			$res = GSM_Importer::import_array( $payload, 'auto' === $mode ? 'משיכה אוטומטית מהאתר הישן' : 'משיכה מהאתר הישן' );
			if ( is_wp_error( $res ) ) {
				return $res;
			}
			foreach ( array( 'equipment', 'faults', 'inspections' ) as $k ) {
				if ( isset( $norm[ $k ] ) ) {
					$counts[ $k ] = array(
						'created' => (int) $res[ $k ]['created'],
						'updated' => (int) $res[ $k ]['updated'],
						'skipped' => (int) $res[ $k ]['skipped'] + (int) $norm[ $k ]['no_id'],
						'failed'  => (int) $res[ $k ]['failed'],
						'notes'   => array_slice( wp_list_pluck( (array) $res[ $k ]['errors'], 'error' ), 0, 10 ),
					);
				}
			}
		}
		if ( isset( $norm['audit'] ) ) {
			$counts['audit'] = self::audit_archive( $norm['audit']['rows'], true );
			if ( class_exists( 'GSM_Audit' ) ) {
				GSM_Audit::log( 'import', 'import', 0, 'משיכה מהאתר הישן', 'יומן בקרה: ' . (int) $counts['audit']['new'] . ' רשומות ארכיון נוספו' );
			}
		}
		return array( 'applied' => true, 'sets' => $report, 'counts' => $counts, 'restore_point' => (int) $snap['id'] );
	}

	/**
	 * A short preview row (no checklist dump).
	 *
	 * @param array $r Row.
	 * @return array
	 */
	private static function sample_row( $r ) {
		$labels = self::field_labels();
		$out    = array();
		foreach ( $r as $k => $v ) {
			if ( 'items' === $k ) {
				$out["צ'קליסט"] = count( $v ) . ' סעיפים';
				continue;
			}
			if ( 'equipment_name_alt' === $k || ! is_scalar( $v ) ) {
				continue;
			}
			$out[ isset( $labels[ $k ] ) ? $labels[ $k ] : $k ] = function_exists( 'mb_substr' ) ? mb_substr( (string) $v, 0, 80 ) : substr( (string) $v, 0, 80 );
		}
		return $out;
	}
}
