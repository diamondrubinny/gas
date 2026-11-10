<?php
/**
 * Secure attachment handling (photos/PDFs on inspections & faults).
 *
 * Security controls:
 *  - allow-list of types (jpg/png/webp/pdf) by BOTH extension and real MIME
 *    (wp_check_filetype_and_ext + finfo + getimagesize for images);
 *  - size cap (8MB) and per-entity count cap (10);
 *  - stored OUTSIDE the web root style public area, under uploads/gas-private/
 *    which ships with a deny-all .htaccess + index.php, and is served only
 *    through an authorized REST endpoint (never a direct URL, never executed);
 *  - randomized stored filename (original name kept only as metadata, escaped
 *    on output); files written 0640;
 *  - optional AV verdict via the 'gsm_attachment_scan' filter (ClamAV/service);
 *  - path-traversal guard on read.
 *
 * @package GasSafetyManager
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Attachments_Service
 */
class GSM_Attachments_Service {

	const MAX_SIZE       = 8388608; // 8 MB.
	const MAX_PER_ENTITY = 10;

	/**
	 * Allowed real MIME => allowed extensions.
	 *
	 * @return array
	 */
	private static function allowed() {
		return array(
			'image/jpeg'      => array( 'jpg', 'jpeg' ),
			'image/png'       => array( 'png' ),
			'image/webp'      => array( 'webp' ),
			'application/pdf' => array( 'pdf' ),
		);
	}

	/**
	 * Entities attachments may belong to.
	 *
	 * @return array
	 */
	public static function entities() {
		return array( 'inspection', 'fault', 'equipment' );
	}

	/**
	 * Absolute private storage directory (ensured + protected).
	 *
	 * @return string|WP_Error
	 */
	private static function private_dir() {
		$up = wp_upload_dir();
		if ( ! empty( $up['error'] ) ) {
			return new WP_Error( 'gsm_up_dir', __( 'תיקיית ההעלאות אינה זמינה.', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}
		$base = trailingslashit( $up['basedir'] ) . 'gas-private';
		$sub  = trailingslashit( $base ) . gmdate( 'Y/m' );
		if ( ! wp_mkdir_p( $sub ) ) {
			return new WP_Error( 'gsm_up_mkdir', __( 'יצירת תיקיית אחסון נכשלה.', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}
		self::harden_dir( $base );
		return $sub;
	}

	/**
	 * Drop deny-all guards into the private directory (idempotent).
	 *
	 * @param string $dir Base dir.
	 */
	public static function harden_dir( $dir ) {
		$ht = trailingslashit( $dir ) . '.htaccess';
		if ( ! file_exists( $ht ) ) {
			// Apache 2.2 + 2.4 deny-all.
			$rules = "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n";
			@file_put_contents( $ht, $rules ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$idx = trailingslashit( $dir ) . 'index.php';
		if ( ! file_exists( $idx ) ) {
			@file_put_contents( $idx, "<?php // Silence is golden.\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		$wc = trailingslashit( $dir ) . 'web.config';
		if ( ! file_exists( $wc ) ) {
			@file_put_contents( $wc, "<configuration><system.webServer><authorization><deny users=\"*\" /></authorization></system.webServer></configuration>\n" ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
	}

	/**
	 * Validate + store an uploaded file against an entity.
	 *
	 * @param string $entity    inspection|fault|equipment.
	 * @param int    $entity_id Owner id.
	 * @param array  $file      One entry from WP_REST_Request::get_file_params().
	 * @param string $actor     Uploader display name.
	 * @return array|WP_Error Stored row meta, or error.
	 */
	public static function store( $entity, $entity_id, $file, $actor ) {
		if ( ! in_array( $entity, self::entities(), true ) ) {
			return new WP_Error( 'gsm_att_entity', __( 'סוג ישות לא נתמך.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! is_array( $file ) || empty( $file['tmp_name'] ) || ! isset( $file['error'] ) ) {
			return new WP_Error( 'gsm_att_nofile', __( 'לא התקבל קובץ.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( UPLOAD_ERR_OK !== (int) $file['error'] ) {
			return new WP_Error( 'gsm_att_uperr', __( 'העלאת הקובץ נכשלה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'gsm_att_notupload', __( 'מקור הקובץ אינו תקין.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$size = (int) ( isset( $file['size'] ) ? $file['size'] : filesize( $file['tmp_name'] ) );
		if ( $size <= 0 || $size > self::MAX_SIZE ) {
			return new WP_Error( 'gsm_att_size', __( 'גודל הקובץ חייב להיות עד 8MB.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( GSM_Attachments_Repo::count_for( $entity, $entity_id ) >= self::MAX_PER_ENTITY ) {
			return new WP_Error( 'gsm_att_count', __( 'הגעתם למקסימום הצרופות לפריט זה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}

		$orig = sanitize_file_name( (string) ( isset( $file['name'] ) ? $file['name'] : 'file' ) );
		$ext  = strtolower( (string) pathinfo( $orig, PATHINFO_EXTENSION ) );

		// Real type check: WP's filetype-and-ext + finfo must agree with the
		// allow-list, and the extension must match the detected real MIME.
		$allowed = self::allowed();
		$check   = wp_check_filetype_and_ext( $file['tmp_name'], $orig );
		$real    = self::finfo_mime( $file['tmp_name'] );
		$mime    = $check['type'] ? $check['type'] : $real;

		if ( ! $mime || ! isset( $allowed[ $mime ] ) ) {
			return new WP_Error( 'gsm_att_type', __( 'סוג קובץ לא מורשה. מותר: JPG, PNG, WEBP, PDF.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( $real && $real !== $mime && ! ( 'image/jpeg' === $mime && 'image/jpeg' === $real ) ) {
			return new WP_Error( 'gsm_att_mime', __( 'אי-התאמה בין סוג הקובץ לתוכנו.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $ext, $allowed[ $mime ], true ) ) {
			return new WP_Error( 'gsm_att_ext', __( 'סיומת הקובץ אינה תואמת לסוג.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		// Images must actually decode as images.
		if ( 0 === strpos( $mime, 'image/' ) ) {
			$info = @getimagesize( $file['tmp_name'] ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( false === $info ) {
				return new WP_Error( 'gsm_att_img', __( 'הקובץ אינו תמונה תקינה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
			}
		}

		// Optional AV / content scan (integrators return 'clean' | 'blocked').
		$verdict = apply_filters( 'gsm_attachment_scan', 'clean', $file['tmp_name'], $mime );
		if ( 'blocked' === $verdict ) {
			return new WP_Error( 'gsm_att_scan', __( 'הקובץ נחסם בבדיקת אבטחה.', 'gas-safety-manager' ), array( 'status' => 422 ) );
		}

		$dir = self::private_dir();
		if ( is_wp_error( $dir ) ) {
			return $dir;
		}
		$stored = wp_generate_password( 24, false, false ) . '.' . $ext;
		$dest   = trailingslashit( $dir ) . $stored;
		if ( ! move_uploaded_file( $file['tmp_name'], $dest ) ) {
			return new WP_Error( 'gsm_att_move', __( 'שמירת הקובץ נכשלה.', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}
		@chmod( $dest, 0640 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_chmod

		$up      = wp_upload_dir();
		$rel     = ltrim( str_replace( trailingslashit( $up['basedir'] ), '', $dest ), '/' );
		$id      = GSM_Attachments_Repo::insert(
			array(
				'entity'      => $entity,
				'entity_id'   => (int) $entity_id,
				'orig_name'   => $orig,
				'stored_name' => $stored,
				'rel_path'    => $rel,
				'mime'        => $mime,
				'size'        => $size,
				'sha256'      => hash_file( 'sha256', $dest ),
				'uploaded_by' => substr( (string) $actor, 0, 255 ),
				'status'      => 'clean',
				'created_at'  => current_time( 'mysql' ),
			)
		);
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'create', $entity, (int) $entity_id, $orig, 'צורפה צרופה: ' . $orig );
		}
		return array(
			'id'         => $id,
			'orig_name'  => $orig,
			'mime'       => $mime,
			'size'       => $size,
			'status'     => 'clean',
			'created_at' => current_time( 'mysql' ),
		);
	}

	/**
	 * Detect MIME with fileinfo when available.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private static function finfo_mime( $path ) {
		if ( function_exists( 'finfo_open' ) ) {
			$f = finfo_open( FILEINFO_MIME_TYPE );
			if ( $f ) {
				$m = finfo_file( $f, $path );
				finfo_close( $f );
				return (string) $m;
			}
		}
		return '';
	}

	/**
	 * Stream an attachment to the browser (inline, non-executable, no sniff).
	 * Path-traversal guarded. Caller must have authorized access already.
	 *
	 * @param array $row Attachment row.
	 * @return void|WP_Error
	 */
	public static function stream( $row ) {
		if ( 'clean' !== ( isset( $row['status'] ) ? $row['status'] : '' ) ) {
			return new WP_Error( 'gsm_att_unavail', __( 'הצרופה אינה זמינה.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}
		$up   = wp_upload_dir();
		$base = trailingslashit( $up['basedir'] ) . 'gas-private';
		$path = trailingslashit( $up['basedir'] ) . ltrim( (string) $row['rel_path'], '/' );
		$real = realpath( $path );
		if ( false === $real || 0 !== strpos( $real, realpath( $base ) ) || ! is_file( $real ) ) {
			return new WP_Error( 'gsm_att_missing', __( 'הצרופה לא נמצאה.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		if ( class_exists( 'GSM_Security' ) ) {
			GSM_Security::stream_headers();
		}
		header( 'Content-Type: ' . $row['mime'] );
		header( 'Content-Length: ' . (string) filesize( $real ) );
		header( 'Content-Disposition: inline; filename="' . rawurlencode( $row['orig_name'] ) . '"' );
		readfile( $real ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		exit;
	}

	/**
	 * Delete an attachment (row + file).
	 *
	 * @param array $row Attachment row.
	 * @return bool
	 */
	public static function delete( $row ) {
		$up   = wp_upload_dir();
		$path = trailingslashit( $up['basedir'] ) . ltrim( (string) $row['rel_path'], '/' );
		$real = realpath( $path );
		$base = realpath( trailingslashit( $up['basedir'] ) . 'gas-private' );
		if ( $real && $base && 0 === strpos( $real, $base ) && is_file( $real ) ) {
			wp_delete_file( $real );
		}
		return GSM_Attachments_Repo::delete( (int) $row['id'] );
	}
}
