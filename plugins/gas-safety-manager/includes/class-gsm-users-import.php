<?php
/**
 * Import users from a file.
 *
 * Accepts the users export of the old system (JSON) or a CSV / XLSX sheet.
 * Columns are recognized by name: name, email, role, active (Hebrew or
 * English). Old PIN codes / PIN hashes in the file are ignored and never
 * stored: every imported user gets a random password and, optionally, an
 * e-mail with a link to choose their own.
 *
 * Existing accounts (same e-mail) are never changed. A preview (dry run)
 * shows exactly what would happen before anything is created.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Users_Import
 */
class GSM_Users_Import {

	const MAX_ROWS  = 500;
	const MAX_BYTES = 2097152; // 2 MB.

	/**
	 * Column aliases.
	 *
	 * @return array
	 */
	private static function aliases() {
		return array(
			'name'   => array( 'name', 'שם', 'שם מלא', 'full_name', 'fullname', 'display_name', 'item_name' ),
			'email'  => array( 'email', 'mail', 'אימייל', 'מייל', 'דוא"ל', 'דואל', 'text_mm2sb7g4', 'user_email' ),
			'role'   => array( 'role', 'תפקיד', 'color_mm2s4qcb' ),
			'active' => array( 'active', 'status', 'סטטוס', 'פעיל', 'color_mm2st51e' ),
		);
	}

	/**
	 * Role text to a role key ('' when unknown).
	 *
	 * @param string $v Value.
	 * @return string
	 */
	public static function role_key( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		$low = function_exists( 'mb_strtolower' ) ? mb_strtolower( $v, 'UTF-8' ) : strtolower( $v );
		if ( isset( GSM_REST_Users::role_map()[ $low ] ) ) {
			return $low;
		}
		// Order matters: "מנהל מעבדה" before "מנהל".
		$rules = array(
			'/אחמ|ahmat/u'                              => 'ahmat',
			'/מנהל\s*מעבדה|lab[\s_-]*manager/u'          => 'lab_manager',
			'/חוקר|^pi$|principal/u'                     => 'pi',
			'/^מנהל$|מנהל\s*מערכת|^manager$|^admin$/u'   => 'manager',
		);
		foreach ( $rules as $re => $key ) {
			if ( preg_match( $re, $low ) ) {
				return $key;
			}
		}
		return '';
	}

	/**
	 * Read a file into raw rows (associative arrays).
	 *
	 * @param string $path File path.
	 * @param string $ext  Extension.
	 * @return array|WP_Error
	 */
	public static function read( $path, $ext ) {
		$ext = strtolower( $ext );
		if ( 'json' === $ext ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$raw  = (string) file_get_contents( $path );
			$data = json_decode( preg_replace( '/^\xEF\xBB\xBF/', '', $raw ), true, 32 );
			$rows = is_array( $data ) ? GSM_Legacy::extract_rows( isset( $data['users'] ) && is_array( $data['users'] ) ? $data['users'] : $data ) : null;
			if ( null === $rows ) {
				return new WP_Error( 'gsm_users_json', __( 'הקובץ אינו JSON תקין, או שלא נמצאה בו רשימת משתמשים.', 'gas-safety-manager' ), array( 'status' => 400 ) );
			}
			return $rows;
		}
		$res = GSM_Spreadsheet::read( $path, $ext );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$rows = array();
		foreach ( (array) $res['rows'] as $r ) {
			$row = array();
			foreach ( (array) $res['headers'] as $i => $h ) {
				$row[ (string) $h ] = isset( $r[ $i ] ) ? $r[ $i ] : '';
			}
			$rows[] = $row;
		}
		return $rows;
	}

	/**
	 * Plan the import: classify every row.
	 *
	 * @param array $rows Raw rows.
	 * @return array|WP_Error { rows[], counts{} }
	 */
	public static function plan( $rows ) {
		if ( count( $rows ) > self::MAX_ROWS ) {
			/* translators: %d: max rows */
			return new WP_Error( 'gsm_users_rows', sprintf( __( 'יותר מדי שורות (מקסימום %d).', 'gas-safety-manager' ), self::MAX_ROWS ), array( 'status' => 413 ) );
		}
		$al     = array();
		foreach ( self::aliases() as $field => $list ) {
			foreach ( $list as $a ) {
				$al[ GSM_Legacy::norm_key( $a ) ] = $field;
			}
		}
		$out    = array();
		$seen   = array();
		$counts = array( 'new' => 0, 'existing' => 0, 'invalid' => 0, 'duplicate' => 0 );
		foreach ( $rows as $raw ) {
			if ( ! is_array( $raw ) ) {
				continue;
			}
			$r = array( 'name' => '', 'email' => '', 'role' => '', 'active' => '' );
			// Monday items: column_values[] with id/text.
			if ( isset( $raw['column_values'] ) && is_array( $raw['column_values'] ) ) {
				foreach ( $raw['column_values'] as $cv ) {
					if ( is_array( $cv ) && isset( $cv['id'] ) ) {
						$raw[ (string) $cv['id'] ] = isset( $cv['text'] ) ? $cv['text'] : '';
					}
				}
			}
			foreach ( $raw as $k => $v ) {
				$nk = GSM_Legacy::norm_key( $k );
				if ( isset( $al[ $nk ] ) && is_scalar( $v ) && '' === $r[ $al[ $nk ] ] ) {
					$r[ $al[ $nk ] ] = trim( (string) $v );
				}
			}
			$email  = sanitize_email( $r['email'] );
			$name   = sanitize_text_field( $r['name'] );
			$role   = self::role_key( $r['role'] );
			$active = ! preg_match( '/לא\s*פעיל|מושב|inactive|disabled|^0$|^false$/iu', $r['active'] );
			$row    = array( 'name' => $name, 'email' => $email, 'role' => $role, 'role_text' => sanitize_text_field( $r['role'] ), 'active' => $active );
			if ( '' === $email || ! is_email( $email ) ) {
				$row['state'] = 'invalid';
				$row['why']   = 'אימייל חסר או לא תקין';
			} elseif ( '' === $role ) {
				$row['state'] = 'invalid';
				$row['why']   = 'תפקיד לא מזוהה' . ( '' !== $row['role_text'] ? ': ' . $row['role_text'] : '' );
			} elseif ( isset( $seen[ strtolower( $email ) ] ) ) {
				$row['state'] = 'duplicate';
				$row['why']   = 'מופיע פעמיים בקובץ';
			} elseif ( email_exists( $email ) || username_exists( $email ) ) {
				$row['state'] = 'existing';
				$row['why']   = 'כבר קיים במערכת (לא ישתנה)';
			} else {
				$row['state'] = 'new';
				$row['why']   = '';
				if ( '' === $name ) {
					$row['name'] = strstr( $email, '@', true );
				}
			}
			$seen[ strtolower( $email ) ] = 1;
			$counts[ $row['state'] ]++;
			$out[] = $row;
		}
		return array( 'rows' => $out, 'counts' => $counts );
	}

	/**
	 * Create the users marked "new".
	 *
	 * @param array    $plan  Result of plan().
	 * @param callable $email Callback( WP_User ) to send the set-password e-mail, or null.
	 * @return array { created, emailed, failed[] }
	 */
	public static function apply( $plan, $email = null ) {
		$map = GSM_REST_Users::role_map();
		$res = array( 'created' => 0, 'emailed' => 0, 'failed' => array() );
		foreach ( $plan['rows'] as $row ) {
			if ( 'new' !== $row['state'] ) {
				continue;
			}
			$uid = wp_insert_user(
				array(
					'user_login'   => $row['email'],
					'user_email'   => $row['email'],
					'display_name' => $row['name'],
					'first_name'   => $row['name'],
					'user_pass'    => wp_generate_password( 24, true, true ),
					'role'         => $map[ $row['role'] ],
				)
			);
			if ( is_wp_error( $uid ) ) {
				$res['failed'][] = $row['email'] . ': ' . $uid->get_error_message();
				continue;
			}
			update_user_meta( $uid, 'gsm_active', $row['active'] ? '1' : '0' );
			$res['created']++;
			if ( $email && $row['active'] ) {
				$ok = call_user_func( $email, get_user_by( 'id', $uid ) );
				if ( true === $ok ) {
					$res['emailed']++;
				}
			}
		}
		return $res;
	}
}
