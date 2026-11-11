<?php
/**
 * REST controller: Excel/CSV equipment import with column mapping.
 *
 *   POST /gas/v1/import/analyze    (manage) — multipart upload -> {headers,rows,total,fields}
 *   POST /gas/v1/import/equipment  (manage) — {headers,rows,mapping,keyField,createMissing} -> upsert
 *
 * The file is parsed from its upload temp path and discarded — never stored in
 * a public location and never executed. Writes go through the equipment service
 * (validation + audit), never raw SQL.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Import
 */
class GSM_REST_Import {

	const NS            = 'gas/v1';
	const MAX_FILE_SIZE = 5242880; // 5 MB.

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$perm = array( 'GSM_Auth', 'require_manage' );

		register_rest_route(
			self::NS,
			'/import/analyze',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'analyze' ),
				'permission_callback' => $perm,
			)
		);
		register_rest_route(
			self::NS,
			'/import/equipment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'import_equipment' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * Importable equipment fields (key => Hebrew label) for the mapping UI.
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			'asset_code'      => 'מספר נכס',
			'monday_id'       => 'Monday ID',
			'name'            => 'שם הציוד',
			'gas_type'        => 'סוג גז',
			'category'        => 'קטגוריה',
			'faculty'         => 'פקולטה',
			'building'        => 'בניין',
			'floor'           => 'קומה',
			'room'            => 'חדר',
			'location'        => 'מיקום',
			'functional_loc'  => 'מיקום פונקציונלי',
			'gis'             => 'GIS',
			'panel_number'    => 'מספר פאנל',
			'work_pressure'   => 'לחץ עבודה',
			'cylinders_count' => 'מספר צילינדרים',
			'cylinders_stock' => 'מלאי צילינדרים',
			'phone'           => 'טלפון',
			'pi_name'         => 'חוקר ראשי',
			'lab_managers'    => 'מנהלי מעבדה',
			'maint_staff'     => 'אנשי תחזוקה',
			'maint_emails'    => 'אימייל תחזוקה',
			'contacts'        => 'אנשי קשר',
			'status'          => 'סטטוס',
			'last_quarterly'  => 'ביקורת אחרונה',
			'next_quarterly'  => 'ביקורת הבאה',
			'purchase_date'   => 'תאריך רכישה',
			'manual_url'      => 'קישור לחוברת',
			'notes'           => 'הערות',
		);
	}

	/**
	 * POST /import/analyze — parse the uploaded file.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function analyze( $req ) {
		$files = $req->get_file_params();
		if ( empty( $files['file'] ) || ! is_array( $files['file'] ) ) {
			return new WP_Error( 'gsm_no_file', __( 'No file uploaded.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$file = $files['file'];

		if ( ! isset( $file['error'] ) || UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'gsm_upload', __( 'File upload failed.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( (int) $file['size'] > self::MAX_FILE_SIZE ) {
			return new WP_Error( 'gsm_upload', __( 'File is too large (max 5MB).', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'gsm_upload', __( 'Invalid upload.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}

		// Validate extension + MIME via WordPress.
		$check = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'] );
		$ext   = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		$allowed = array( 'csv', 'txt', 'xlsx' );
		if ( ! in_array( $ext, $allowed, true ) ) {
			return new WP_Error( 'gsm_file_type', __( 'Only CSV or XLSX files are allowed.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}

		try {
			$res = GSM_Spreadsheet::read( $file['tmp_name'], $ext );
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gsm_parse', 'שגיאת קריאה: ' . $e->getMessage(), array( 'status' => 400 ) );
		}
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		if ( empty( $res['headers'] ) ) {
			return new WP_Error( 'gsm_empty', __( 'The file has no columns / rows.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response(
			array(
				'headers'   => $res['headers'],
				'rows'      => $res['rows'],
				'total'     => count( $res['rows'] ),
				'truncated' => ! empty( $res['truncated'] ),
				'fields'    => self::fields(),
			)
		);
	}

	/**
	 * POST /import/equipment — upsert mapped rows.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function import_equipment( $req ) {
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = $req->get_params();
		}

		$rows    = isset( $body['rows'] ) && is_array( $body['rows'] ) ? $body['rows'] : array();
		if ( count( $rows ) > GSM_Spreadsheet::MAX_ROWS ) {
			return new WP_Error( 'gsm_import_too_many', sprintf( /* translators: %d: max rows */ __( 'יותר מדי שורות (מקסימום %d). פצלו את הקובץ.', 'gas-safety-manager' ), GSM_Spreadsheet::MAX_ROWS ), array( 'status' => 413 ) );
		}
		$mapping = isset( $body['mapping'] ) && is_array( $body['mapping'] ) ? $body['mapping'] : array();
		$key     = isset( $body['keyField'] ) ? sanitize_key( (string) $body['keyField'] ) : 'asset_code';
		$create_missing = ! isset( $body['createMissing'] ) || (bool) $body['createMissing'];

		$valid_fields = array_keys( self::fields() );
		if ( ! in_array( $key, $valid_fields, true ) ) {
			$key = 'asset_code';
		}
		// Keep only known target fields with integer column indices.
		$map = array();
		foreach ( $mapping as $field => $col ) {
			$field = sanitize_key( (string) $field );
			if ( in_array( $field, $valid_fields, true ) && '' !== (string) $col && null !== $col ) {
				$map[ $field ] = (int) $col;
			}
		}
		if ( empty( $map ) ) {
			return new WP_Error( 'gsm_map', __( 'No columns were mapped to fields.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! isset( $map[ $key ] ) && ! isset( $map['name'] ) ) {
			return new WP_Error( 'gsm_map', __( 'Map at least the key field or the name field.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}

		// Index existing equipment by the key field (for upsert).
		$existing = array();
		if ( isset( $map[ $key ] ) ) {
			$all = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
			foreach ( (array) $all as $e ) {
				$kv = isset( $e[ $key ] ) ? trim( (string) $e[ $key ] ) : '';
				if ( '' !== $kv && ! isset( $existing[ $kv ] ) ) {
					$existing[ $kv ] = (int) $e['id'];
				}
			}
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = array();

		try {
			$rownum = 1; // header is row 1 for the user.
			foreach ( $rows as $row ) {
				$rownum++;
				$row   = array_values( (array) $row );
				$input = array();
				foreach ( $map as $field => $col ) {
					$input[ $field ] = isset( $row[ $col ] ) ? (string) $row[ $col ] : '';
				}
				$input = self::normalize( $input );

				$kv = isset( $map[ $key ] ) ? trim( (string) ( isset( $input[ $key ] ) ? $input[ $key ] : '' ) ) : '';

				if ( '' !== $kv && isset( $existing[ $kv ] ) ) {
					// Update only what the sheet actually fills in: an empty cell
					// must not wipe an existing value (sparse sheets are common).
					$changes = array_filter(
						$input,
						static function ( $v ) {
							return '' !== trim( (string) $v );
						}
					);
					if ( empty( $changes ) ) {
						$skipped++;
						continue;
					}
					$res = GSM_Equipment_Service::update( $existing[ $kv ], $changes );
					if ( is_wp_error( $res ) ) {
						$skipped++;
						self::add_error( $errors, $rownum, $res->get_error_message() );
					} else {
						$updated++;
					}
					continue;
				}

				// No existing match -> create (if allowed and name present).
				if ( ! $create_missing ) {
					$skipped++;
					continue;
				}
				if ( empty( $input['name'] ) ) {
					$skipped++;
					self::add_error( $errors, $rownum, 'חסר שם ציוד — לא נוצר' );
					continue;
				}
				$new = GSM_Equipment_Service::create( $input );
				if ( is_wp_error( $new ) ) {
					$skipped++;
					self::add_error( $errors, $rownum, $new->get_error_message() );
				} else {
					$created++;
					// Track so a duplicate key later in the same file updates it.
					if ( '' !== $kv ) {
						$existing[ $kv ] = (int) $new;
					}
				}
			}
		} catch ( \Throwable $e ) {
			return new WP_Error( 'gsm_import_failed', 'שגיאת ייבוא: ' . $e->getMessage() . ' [' . basename( $e->getFile() ) . ':' . $e->getLine() . ']', array( 'status' => 500 ) );
		}

		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'import', 'equipment', 0, 'ייבוא ציוד', 'נוצרו ' . $created . ', עודכנו ' . $updated . ', דולגו ' . $skipped );
		}

		return rest_ensure_response(
			array(
				'created' => $created,
				'updated' => $updated,
				'skipped' => $skipped,
				'errors'  => $errors,
			)
		);
	}

	/**
	 * Append an error (capped).
	 *
	 * @param array  $errors By-ref list.
	 * @param int    $row    Row number.
	 * @param string $msg    Message.
	 */
	private static function add_error( &$errors, $row, $msg ) {
		if ( count( $errors ) < 50 ) {
			$errors[] = array( 'row' => (int) $row, 'message' => (string) $msg );
		}
	}

	/**
	 * Normalize imported values: status labels -> keys, dates -> Y-m-d.
	 *
	 * @param array $input Raw mapped input.
	 * @return array
	 */
	private static function normalize( $input ) {
		// Status: accept Hebrew labels or keys; drop anything unrecognized so a
		// free-text status never fails the whole row on validation.
		if ( isset( $input['status'] ) ) {
			$s   = trim( (string) $input['status'] );
			$map = array(
				'פעיל' => 'active', 'תקין' => 'active', 'active' => 'active',
				'בתחזוקה' => 'maintenance', 'תחזוקה' => 'maintenance', 'maintenance' => 'maintenance',
				'תקול' => 'faulty', 'תקלה' => 'faulty', 'faulty' => 'faulty',
				'לא פעיל' => 'inactive', 'מושבת' => 'inactive', 'inactive' => 'inactive',
			);
			if ( isset( $map[ $s ] ) ) {
				$input['status'] = $map[ $s ];
			} else {
				unset( $input['status'] );
			}
		}
		// A manual link that is not https would fail the whole row: drop it.
		if ( isset( $input['manual_url'] ) && 0 !== stripos( trim( (string) $input['manual_url'] ), 'https://' ) ) {
			unset( $input['manual_url'] );
		}
		foreach ( array( 'last_quarterly', 'next_quarterly', 'purchase_date' ) as $d ) {
			if ( isset( $input[ $d ] ) ) {
				$input[ $d ] = self::to_iso_date( $input[ $d ] );
				if ( '' === $input[ $d ] ) {
					unset( $input[ $d ] );
				}
			}
		}
		return $input;
	}

	/**
	 * Convert common date formats to Y-m-d (else '').
	 *
	 * @param string $v Value.
	 * @return string
	 */
	private static function to_iso_date( $v ) {
		$v = trim( (string) $v );
		if ( '' === $v ) {
			return '';
		}
		// Already ISO.
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ) {
			return $v;
		}
		// DD/MM/YYYY or DD.MM.YYYY or DD-MM-YYYY.
		if ( preg_match( '#^(\d{1,2})[./-](\d{1,2})[./-](\d{4})$#', $v, $m ) ) {
			$d = sprintf( '%04d-%02d-%02d', (int) $m[3], (int) $m[2], (int) $m[1] );
			$dt = DateTime::createFromFormat( 'Y-m-d', $d );
			return ( $dt && $dt->format( 'Y-m-d' ) === $d ) ? $d : '';
		}
		return '';
	}
}
