<?php
/**
 * REST controller: Inspection configuration.
 *
 * Holds the two role-based checklists (quarterly = אחמ"ת / long,
 * monthly = lab manager & PI / short) plus notification/cadence rules.
 * Stored in the gsm_inspection_config option.
 *
 *   GET  /gas/v1/inspection-config   (report) — read
 *   POST /gas/v1/inspection-config   (manage) — save
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Inspection_Config
 */
class GSM_REST_Inspection_Config {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/inspection-config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_config' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
		// Read checklist items out of an uploaded file. Nothing is saved here:
		// the screen shows the items for review and saves them with the normal save.
		register_rest_route(
			self::NS,
			'/inspection-config/import',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_file' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
	}

	/**
	 * Default config shape.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'rules'      => array(
				'monthlyEveryDays'   => 30,
				'quarterlyEveryDays' => 90,
				'reminderBeforeDays' => 7,
				'notifyPI'           => true,
				'notifyAhmat'        => true,
				'notifyLabManager'   => true,
				'notifyOnComplete'   => false,
				'extraEmails'        => array(),
			),
			'checklists' => array(
				'quarterly' => array(),
				'monthly'   => array(),
			),
		);
	}

	/**
	 * GET /inspection-config
	 *
	 * @return WP_REST_Response
	 */
	public function get_config() {
		$cfg = get_option( 'gsm_inspection_config', array() );
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}
		return rest_ensure_response( self::merge_defaults( $cfg ) );
	}

	/**
	 * POST /inspection-config
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function save_config( $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$clean = self::sanitize( $body );
		update_option( 'gsm_inspection_config', $clean );
		return rest_ensure_response( $clean );
	}

	/**
	 * Merge a stored config over defaults.
	 *
	 * @param array $cfg Stored.
	 * @return array
	 */
	private static function merge_defaults( $cfg ) {
		$d                 = self::defaults();
		$out               = $d;
		if ( isset( $cfg['rules'] ) && is_array( $cfg['rules'] ) ) {
			$out['rules'] = array_merge( $d['rules'], $cfg['rules'] );
		}
		if ( isset( $cfg['checklists'] ) && is_array( $cfg['checklists'] ) ) {
			$out['checklists']['quarterly'] = isset( $cfg['checklists']['quarterly'] ) && is_array( $cfg['checklists']['quarterly'] ) ? $cfg['checklists']['quarterly'] : array();
			$out['checklists']['monthly']   = isset( $cfg['checklists']['monthly'] ) && is_array( $cfg['checklists']['monthly'] ) ? $cfg['checklists']['monthly'] : array();
		}
		return $out;
	}

	/**
	 * Sanitize an incoming config.
	 *
	 * @param array $body Raw.
	 * @return array
	 */
	private static function sanitize( $body ) {
		$d     = self::defaults();
		$rules = ( isset( $body['rules'] ) && is_array( $body['rules'] ) ) ? $body['rules'] : array();

		$clean_rules = array(
			'monthlyEveryDays'   => self::clamp_int( $rules, 'monthlyEveryDays', 1, 3650, 30 ),
			'quarterlyEveryDays' => self::clamp_int( $rules, 'quarterlyEveryDays', 1, 3650, 90 ),
			'reminderBeforeDays' => self::clamp_int( $rules, 'reminderBeforeDays', 0, 365, 7 ),
			'notifyPI'           => ! empty( $rules['notifyPI'] ),
			'notifyAhmat'        => ! empty( $rules['notifyAhmat'] ),
			'notifyLabManager'   => ! empty( $rules['notifyLabManager'] ),
			'notifyOnComplete'   => ! empty( $rules['notifyOnComplete'] ),
			'extraEmails'        => array(),
		);
		if ( isset( $rules['extraEmails'] ) && is_array( $rules['extraEmails'] ) ) {
			foreach ( $rules['extraEmails'] as $e ) {
				$email = sanitize_email( (string) $e );
				if ( $email && is_email( $email ) ) {
					$clean_rules['extraEmails'][] = $email;
				}
			}
		}

		$checklists = ( isset( $body['checklists'] ) && is_array( $body['checklists'] ) ) ? $body['checklists'] : array();

		return array(
			'rules'      => $clean_rules,
			'checklists' => array(
				'quarterly' => self::clean_items( isset( $checklists['quarterly'] ) ? $checklists['quarterly'] : array() ),
				'monthly'   => self::clean_items( isset( $checklists['monthly'] ) ? $checklists['monthly'] : array() ),
			),
		);
	}

	/**
	 * Clean a checklist array into [{id,text}, …], dropping empties.
	 *
	 * @param mixed $items Raw.
	 * @return array
	 */
	private static function clean_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}
		$i = 0;
		foreach ( $items as $it ) {
			$i++;
			$text = '';
			$id   = '';
			if ( is_array( $it ) ) {
				$text = isset( $it['text'] ) ? sanitize_text_field( (string) $it['text'] ) : '';
				$id   = isset( $it['id'] ) ? sanitize_text_field( (string) $it['id'] ) : '';
			} else {
				$text = sanitize_text_field( (string) $it );
			}
			$text = trim( $text );
			if ( '' === $text ) {
				continue;
			}
			if ( '' === $id ) {
				$id = 'c' . $i . '_' . substr( md5( $text ), 0, 6 );
			}
			$out[] = array( 'id' => $id, 'text' => $text );
		}
		return $out;
	}

	/**
	 * Clamp an int field.
	 *
	 * @param array  $src Source.
	 * @param string $key Key.
	 * @param int    $min Min.
	 * @param int    $max Max.
	 * @param int    $def Default.
	 * @return int
	 */
	private static function clamp_int( $src, $key, $min, $max, $def ) {
		$v = isset( $src[ $key ] ) ? (int) $src[ $key ] : $def;
		return max( $min, min( $max, $v ) );
	}

	const IMPORT_MAX_BYTES = 1048576; // 1 MB.
	const IMPORT_MAX_ITEMS = 200;     // Per checklist.
	const IMPORT_MAX_CHARS = 300;     // Per item.

	/**
	 * Which checklist does a "type" cell mean? '' when not recognized.
	 *
	 * @param string $v Cell text.
	 * @return string quarterly|monthly|''
	 */
	public static function list_key( $v ) {
		$v = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $v ), 'UTF-8' ) : strtolower( trim( (string) $v ) );
		if ( preg_match( '/רבעונ|quarter|אחמ/u', $v ) ) {
			return 'quarterly';
		}
		if ( preg_match( '/חודש|month/u', $v ) ) {
			return 'monthly';
		}
		return '';
	}

	/**
	 * Turn spreadsheet rows into checklist items.
	 *
	 * Recognized layouts:
	 *  - the system's own export: "סוג ביקורת", "מס'", "פריט בדיקה";
	 *  - any sheet with an item column (and optionally a type column);
	 *  - a single column of items, with or without a header.
	 *
	 * @param array  $headers Header cells.
	 * @param array  $rows    Data rows.
	 * @param string $default Checklist for rows without a recognized type.
	 * @return array { quarterly[], monthly[], skipped, truncated }
	 */
	public static function rows_to_items( $headers, $rows, $default ) {
		$default = 'monthly' === $default ? 'monthly' : 'quarterly';
		$norm    = function ( $h ) {
			$h = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( (string) $h ), 'UTF-8' ) : strtolower( trim( (string) $h ) );
			return (string) preg_replace( '/[\s\'"׳״._\-]+/u', '', $h );
		};
		$type_col = -1;
		$item_col = -1;
		foreach ( array_values( (array) $headers ) as $i => $h ) {
			$n = $norm( $h );
			if ( -1 === $type_col && in_array( $n, array( 'סוגביקורת', 'סוג', 'type', 'checklist', 'list' ), true ) ) {
				$type_col = $i;
			} elseif ( -1 === $item_col && in_array( $n, array( 'פריטבדיקה', 'פריט', 'סעיף', 'סעיףצקליסט', 'תיאור', 'תיאורסעיף', 'item', 'text', 'description', 'check' ), true ) ) {
				$item_col = $i;
			}
		}
		$all = (array) $rows;
		if ( -1 === $item_col ) {
			// No recognizable header: the first row is data too, and the item is
			// the widest text column (a plain list has just one).
			array_unshift( $all, array_values( (array) $headers ) );
			$type_col = -1;
			$width    = array();
			foreach ( $all as $r ) {
				foreach ( array_values( (array) $r ) as $i => $c ) {
					$c = trim( (string) $c );
					if ( '' !== $c && ! is_numeric( $c ) ) {
						$width[ $i ] = ( isset( $width[ $i ] ) ? $width[ $i ] : 0 ) + strlen( $c );
					}
				}
			}
			if ( ! $width ) {
				return array( 'quarterly' => array(), 'monthly' => array(), 'skipped' => 0, 'truncated' => false );
			}
			arsort( $width );
			$item_col = (int) key( $width );
		}
		$out   = array( 'quarterly' => array(), 'monthly' => array() );
		$seen  = array( 'quarterly' => array(), 'monthly' => array() );
		$skip  = 0;
		$trunc = false;
		foreach ( $all as $r ) {
			$r    = array_values( (array) $r );
			$text = isset( $r[ $item_col ] ) ? trim( sanitize_text_field( (string) $r[ $item_col ] ) ) : '';
			if ( '' === $text ) {
				continue;
			}
			$key = $default;
			if ( $type_col >= 0 && isset( $r[ $type_col ] ) && '' !== trim( (string) $r[ $type_col ] ) ) {
				$key = self::list_key( $r[ $type_col ] );
				if ( '' === $key ) {
					$skip++; // A type we do not know (e.g. "שנתית").
					continue;
				}
			}
			$text = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, self::IMPORT_MAX_CHARS ) : substr( $text, 0, self::IMPORT_MAX_CHARS );
			if ( isset( $seen[ $key ][ $text ] ) ) {
				continue; // Same item twice in the file.
			}
			if ( count( $out[ $key ] ) >= self::IMPORT_MAX_ITEMS ) {
				$trunc = true;
				continue;
			}
			$seen[ $key ][ $text ] = 1;
			$out[ $key ][]         = array( 'text' => $text );
		}
		return array( 'quarterly' => $out['quarterly'], 'monthly' => $out['monthly'], 'skipped' => $skip, 'truncated' => $trunc );
	}

	/**
	 * POST /inspection-config/import — multipart: file (+ default=quarterly|monthly).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_file( $req ) {
		$files = $req->get_file_params();
		$file  = isset( $files['file'] ) && is_array( $files['file'] ) ? $files['file'] : null;
		if ( ! $file || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'gsm_upload', __( 'לא התקבל קובץ.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] <= 0 || (int) $file['size'] > self::IMPORT_MAX_BYTES ) {
			return new WP_Error( 'gsm_upload', __( 'הקובץ גדול מדי (עד 1MB).', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt', 'xlsx' ), true ) ) {
			return new WP_Error( 'gsm_file_type', __( 'סוגי קבצים מותרים: Excel (XLSX), CSV או TXT.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		try {
			$res = GSM_Spreadsheet::read( $file['tmp_name'], $ext );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gsm_parse', __( 'לא ניתן היה לקרוא את הקובץ.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$out = self::rows_to_items( isset( $res['headers'] ) ? $res['headers'] : array(), isset( $res['rows'] ) ? $res['rows'] : array(), (string) $req->get_param( 'default' ) );
		if ( ! $out['quarterly'] && ! $out['monthly'] ) {
			return new WP_Error( 'gsm_empty', __( 'לא נמצאו סעיפים בקובץ.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( $out );
	}
}
