<?php
/**
 * Admin: Migration page.
 *
 * Lets an admin generate a one-time migration key (shown once), see current
 * equipment count, and disable migration when done. The key authorizes the
 * browser-Console import script to POST equipment from Monday.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Admin_Migration
 */
class GSM_Admin_Migration {

	/**
	 * Hook admin menu + form handling.
	 */
	public function init() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_gsm_gen_key', array( $this, 'handle_gen_key' ) );
		add_action( 'admin_post_gsm_revoke_key', array( $this, 'handle_revoke_key' ) );
		add_action( 'admin_post_gsm_import_file', array( $this, 'handle_import_file' ) );
		add_action( 'admin_post_gsm_export_backup', array( $this, 'handle_export_backup' ) );
	}

	/**
	 * Stream a full-data JSON backup (equipment + inspections + faults) in the
	 * same shape the importer accepts, so it round-trips. Admin-only + nonce.
	 *
	 * Note: on re-import, items that have a monday_id upsert by it; items with
	 * no monday_id get a fresh qr_token/asset_code (tokens are not re-applied
	 * from the file by design).
	 *
	 * @return void
	 */
	public function handle_export_backup() {
		if ( ! current_user_can( 'gsm_manage_all' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gas-safety-manager' ) );
		}
		check_admin_referer( 'gsm_export_backup' );

		// Equipment + id->name map.
		$equipment = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
		$names     = array();
		$eq_out    = array();
		foreach ( (array) $equipment as $e ) {
			$names[ (int) $e['id'] ] = isset( $e['name'] ) ? $e['name'] : '';
			unset( $e['id'], $e['created_at'], $e['updated_at'], $e['image_id'] );
			$eq_out[] = $e;
		}

		// Inspections (+ items + equipment_name).
		$insp_out = array();
		$insp     = GSM_Inspections_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
		foreach ( (array) $insp as $i ) {
			$eid   = (int) ( isset( $i['equipment_id'] ) ? $i['equipment_id'] : 0 );
			$items = GSM_Inspections_Repo::get_items( $eid ? (int) $i['id'] : 0 );
			$clean_items = array();
			foreach ( (array) $items as $it ) {
				$clean_items[] = array(
					'item_index'  => isset( $it['item_index'] ) ? (int) $it['item_index'] : 0,
					'item_text'   => isset( $it['item_text'] ) ? $it['item_text'] : '',
					'item_result' => isset( $it['item_result'] ) ? $it['item_result'] : 'ok',
				);
			}
			$insp_out[] = array(
				'monday_id'       => isset( $i['monday_id'] ) ? $i['monday_id'] : '',
				'equipment_name'  => isset( $names[ $eid ] ) ? $names[ $eid ] : '',
				'type'            => isset( $i['type'] ) ? $i['type'] : '',
				'inspection_date' => isset( $i['inspection_date'] ) ? $i['inspection_date'] : '',
				'inspector'       => isset( $i['inspector'] ) ? $i['inspector'] : '',
				'pi_name'         => isset( $i['pi_name'] ) ? $i['pi_name'] : '',
				'panel_number'    => isset( $i['panel_number'] ) ? $i['panel_number'] : '',
				'result'          => isset( $i['result'] ) ? $i['result'] : '',
				'notes'           => isset( $i['notes'] ) ? $i['notes'] : '',
				'items'           => $clean_items,
			);
		}

		// Faults (+ equipment_name).
		$fault_out = array();
		$faults    = GSM_Faults_Repo::list( array( 'limit' => 100000, 'offset' => 0 ) );
		foreach ( (array) $faults as $f ) {
			$eid         = (int) ( isset( $f['equipment_id'] ) ? $f['equipment_id'] : 0 );
			$fault_out[] = array(
				'monday_id'      => isset( $f['monday_id'] ) ? $f['monday_id'] : '',
				'equipment_name' => isset( $names[ $eid ] ) ? $names[ $eid ] : '',
				'reporter'       => isset( $f['reporter'] ) ? $f['reporter'] : '',
				'description'    => isset( $f['description'] ) ? $f['description'] : '',
				'status'         => isset( $f['status'] ) ? $f['status'] : '',
				'priority'       => isset( $f['priority'] ) ? $f['priority'] : '',
				'opened_date'    => isset( $f['opened_date'] ) ? $f['opened_date'] : '',
				'resolved_date'  => isset( $f['resolved_date'] ) ? $f['resolved_date'] : '',
			);
		}

		$payload = array(
			'exported_at' => current_time( 'c' ),
			'source'      => 'gas-safety-manager',
			'version'     => GSM_VERSION,
			'equipment'   => $eq_out,
			'inspections' => $insp_out,
			'faults'      => $fault_out,
		);

		$filename = 'gas-backup-' . current_time( 'Y-m-d' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Add the admin menu page.
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Gas Safety — Migration', 'gas-safety-manager' ),
			__( 'Gas Migration', 'gas-safety-manager' ),
			'gsm_manage_all',
			'gsm-migration',
			array( $this, 'render' ),
			'dashicons-migrate',
			58
		);
	}

	/**
	 * Handle "generate key" form (admin-post).
	 */
	public function handle_gen_key() {
		if ( ! current_user_can( 'gsm_manage_all' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gas-safety-manager' ) );
		}
		check_admin_referer( 'gsm_gen_key' );

		$key = bin2hex( random_bytes( 24 ) ); // 48 hex chars.
		update_option( 'gsm_migration_key', $key );
		// Stash once for one-time display via a short transient.
		set_transient( 'gsm_migration_key_show', $key, 300 );

		wp_safe_redirect( admin_url( 'admin.php?page=gsm-migration&generated=1' ) );
		exit;
	}

	/**
	 * Handle "revoke key" form (admin-post).
	 */
	public function handle_revoke_key() {
		if ( ! current_user_can( 'gsm_manage_all' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gas-safety-manager' ) );
		}
		check_admin_referer( 'gsm_revoke_key' );

		delete_option( 'gsm_migration_key' );
		delete_transient( 'gsm_migration_key_show' );

		wp_safe_redirect( admin_url( 'admin.php?page=gsm-migration&revoked=1' ) );
		exit;
	}

	/**
	 * Handle the offline JSON file import (admin-post, multipart upload).
	 *
	 * Security: manage cap + nonce; only a real uploaded .json file, size
	 * capped; parsed with json_decode (no code execution); all writes go
	 * through the service layer via GSM_Importer.
	 */
	public function handle_import_file() {
		if ( ! current_user_can( 'gsm_manage_all' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'gas-safety-manager' ) );
		}
		check_admin_referer( 'gsm_import_file' );

		$redirect = admin_url( 'admin.php?page=gsm-migration' );

		// Validate the upload.
		if ( empty( $_FILES['gsm_import'] ) || ! isset( $_FILES['gsm_import']['tmp_name'] ) ) {
			$this->import_fail( 'nofile' );
		}
		$file = $_FILES['gsm_import']; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput

		$err_code = isset( $file['error'] ) ? (int) $file['error'] : UPLOAD_ERR_NO_FILE;
		if ( UPLOAD_ERR_OK !== $err_code ) {
			$this->import_fail( 'upload' );
		}
		$tmp = isset( $file['tmp_name'] ) ? $file['tmp_name'] : '';
		if ( ! $tmp || ! is_uploaded_file( $tmp ) ) {
			$this->import_fail( 'upload' );
		}
		$size = isset( $file['size'] ) ? (int) $file['size'] : 0;
		if ( $size <= 0 || $size > 15 * 1024 * 1024 ) {
			$this->import_fail( 'size' );
		}
		$name = isset( $file['name'] ) ? sanitize_file_name( $file['name'] ) : '';
		if ( ! preg_match( '/\.json$/i', $name ) ) {
			$this->import_fail( 'type' );
		}

		// Read + decode.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$raw = file_get_contents( $tmp );
		if ( false === $raw || '' === $raw ) {
			$this->import_fail( 'read' );
		}
		$data = json_decode( $raw, true, 64 );
		if ( null === $data && JSON_ERROR_NONE !== json_last_error() ) {
			$this->import_fail( 'json' );
		}

		// Import.
		$res = GSM_Importer::import_array( $data );
		if ( is_wp_error( $res ) ) {
			set_transient( 'gsm_import_error', $res->get_error_message(), 300 );
			wp_safe_redirect( add_query_arg( 'import_error', '1', $redirect ) );
			exit;
		}

		set_transient( 'gsm_import_result', $res, 300 );
		wp_safe_redirect( add_query_arg( 'imported', '1', $redirect ) );
		exit;
	}

	/**
	 * Redirect back with an import error reason.
	 *
	 * @param string $reason Reason slug.
	 * @return void
	 */
	private function import_fail( $reason ) {
		$msgs = array(
			'nofile' => __( 'לא נבחר קובץ.', 'gas-safety-manager' ),
			'upload' => __( 'העלאת הקובץ נכשלה.', 'gas-safety-manager' ),
			'size'   => __( 'הקובץ גדול מדי (מקסימום 15MB).', 'gas-safety-manager' ),
			'type'   => __( 'יש להעלות קובץ JSON (.json) בלבד.', 'gas-safety-manager' ),
			'read'   => __( 'לא ניתן לקרוא את הקובץ.', 'gas-safety-manager' ),
			'json'   => __( 'הקובץ אינו JSON תקין.', 'gas-safety-manager' ),
		);
		$msg = isset( $msgs[ $reason ] ) ? $msgs[ $reason ] : __( 'שגיאה בייבוא.', 'gas-safety-manager' );
		set_transient( 'gsm_import_error', $msg, 300 );
		wp_safe_redirect( add_query_arg( 'import_error', '1', admin_url( 'admin.php?page=gsm-migration' ) ) );
		exit;
	}

	/**
	 * Render the result of the last offline import (from a transient).
	 *
	 * @return void
	 */
	private function render_import_result() {
		$res = get_transient( 'gsm_import_result' );
		delete_transient( 'gsm_import_result' );
		if ( ! is_array( $res ) ) {
			echo '<div class="notice notice-success"><p>' . esc_html__( 'הייבוא הסתיים.', 'gas-safety-manager' ) . '</p></div>';
			return;
		}

		$labels = array(
			'equipment'   => __( 'ציוד', 'gas-safety-manager' ),
			'inspections' => __( 'ביקורות', 'gas-safety-manager' ),
			'faults'      => __( 'תקלות', 'gas-safety-manager' ),
		);

		echo '<div class="notice notice-success"><p><strong>' . esc_html__( 'הייבוא הסתיים.', 'gas-safety-manager' ) . '</strong></p>';
		echo '<table class="widefat striped" style="max-width:620px;margin:8px 0"><thead><tr>';
		echo '<th>' . esc_html__( 'ישות', 'gas-safety-manager' ) . '</th><th>' . esc_html__( 'נוצרו', 'gas-safety-manager' ) . '</th><th>' . esc_html__( 'עודכנו', 'gas-safety-manager' ) . '</th><th>' . esc_html__( 'דולגו', 'gas-safety-manager' ) . '</th><th>' . esc_html__( 'שגיאות', 'gas-safety-manager' ) . '</th>';
		echo '</tr></thead><tbody>';
		foreach ( $labels as $key => $label ) {
			$s = isset( $res[ $key ] ) ? $res[ $key ] : array();
			echo '<tr><td><strong>' . esc_html( $label ) . '</strong></td>';
			echo '<td>' . (int) ( isset( $s['created'] ) ? $s['created'] : 0 ) . '</td>';
			echo '<td>' . (int) ( isset( $s['updated'] ) ? $s['updated'] : 0 ) . '</td>';
			echo '<td>' . (int) ( isset( $s['skipped'] ) ? $s['skipped'] : 0 ) . '</td>';
			echo '<td>' . (int) ( isset( $s['errors'] ) ? count( $s['errors'] ) : 0 ) . '</td></tr>';
		}
		echo '</tbody></table>';

		// Show first few errors per section, if any.
		foreach ( $labels as $key => $label ) {
			$errs = ( isset( $res[ $key ]['errors'] ) && is_array( $res[ $key ]['errors'] ) ) ? $res[ $key ]['errors'] : array();
			if ( empty( $errs ) ) {
				continue;
			}
			echo '<p style="margin:6px 0 2px"><strong>' . esc_html( $label ) . ' — ' . esc_html__( 'שגיאות/דילוגים', 'gas-safety-manager' ) . ':</strong></p><ul style="margin:0 18px 8px;list-style:disc">';
			$shown = 0;
			foreach ( $errs as $e ) {
				if ( $shown >= 10 ) {
					echo '<li>…</li>';
					break;
				}
				$idx = isset( $e['index'] ) ? ( '#' . (int) $e['index'] . ' ' ) : '';
				$msg = isset( $e['error'] ) ? $e['error'] : '';
				echo '<li>' . esc_html( $idx . $msg ) . '</li>';
				$shown++;
			}
			echo '</ul>';
		}
		echo '</div>';
	}

	/**
	 * Render the page.
	 */
	public function render() {
		if ( ! current_user_can( 'gsm_manage_all' ) ) {
			return;
		}

		$enabled   = '' !== (string) get_option( 'gsm_migration_key', '' );
		$show_key  = get_transient( 'gsm_migration_key_show' );
		$rest_root = esc_url_raw( rest_url( 'gas/v1' ) );
		$count     = (int) GSM_Equipment_Repo::count( array() );

		echo '<div class="wrap">';
		echo '<h1>' . esc_html__( 'Gas Safety — Data Migration', 'gas-safety-manager' ) . '</h1>';

		// Notices.
		if ( isset( $_GET['generated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-success"><p>' . esc_html__( 'Migration key generated. Copy it now — it is shown only once.', 'gas-safety-manager' ) . '</p></div>';
		}
		if ( isset( $_GET['revoked'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			echo '<div class="notice notice-info"><p>' . esc_html__( 'Migration disabled. The key has been revoked.', 'gas-safety-manager' ) . '</p></div>';
		}
		// Offline-import result / error notices.
		if ( isset( $_GET['import_error'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$emsg = get_transient( 'gsm_import_error' );
			delete_transient( 'gsm_import_error' );
			echo '<div class="notice notice-error"><p><strong>' . esc_html__( 'ייבוא נכשל', 'gas-safety-manager' ) . ':</strong> ' . esc_html( $emsg ? $emsg : __( 'שגיאה לא ידועה.', 'gas-safety-manager' ) ) . '</p></div>';
		}
		if ( isset( $_GET['imported'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->render_import_result();
		}

		// Current status.
		echo '<div class="card" style="max-width:720px;padding:16px;margin-bottom:16px">';
		echo '<h2>' . esc_html__( 'Status', 'gas-safety-manager' ) . '</h2>';
		echo '<p>' . sprintf(
			/* translators: %d: equipment count */
			esc_html__( 'Equipment rows currently in WordPress: %d', 'gas-safety-manager' ),
			$count
		) . '</p>';
		echo '<p>' . esc_html__( 'Migration status:', 'gas-safety-manager' ) . ' <strong>' .
			( $enabled ? esc_html__( 'ENABLED', 'gas-safety-manager' ) : esc_html__( 'disabled', 'gas-safety-manager' ) ) .
			'</strong></p>';
		echo '</div>';

		// Offline import card (JSON file upload) — works with no outside access.
		echo '<div class="card" style="max-width:720px;padding:16px;margin-bottom:16px;border-left:4px solid #2271b1">';
		echo '<h2>' . esc_html__( 'ייבוא מקובץ (לא מקוון)', 'gas-safety-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'מריצים את סקריפט הייצוא בפורטל, מורידים קובץ JSON, ומעלים אותו כאן. שיטה זו אינה דורשת גישה חיצונית — הייבוא מתבצע בשרת. ייבוא הציוד קודם; ביקורות/תקלות מקושרות לציוד לפי שם.', 'gas-safety-manager' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gsm_import_file' );
		echo '<input type="hidden" name="action" value="gsm_import_file">';
		echo '<p><input type="file" name="gsm_import" accept=".json,application/json" required></p>';
		submit_button( __( 'העלה וייבא', 'gas-safety-manager' ), 'primary', 'submit', false );
		echo ' <span class="description">' . esc_html__( 'קובץ JSON עד 15MB. ההרצה idempotent (חוזרת = מעדכנת לפי monday_id).', 'gas-safety-manager' ) . '</span>';
		echo '</form>';
		echo '</div>';

		// Backup / export card (reverse of import — data safety).
		echo '<div class="card" style="max-width:720px;padding:16px;margin-bottom:16px;border-left:4px solid #1a9b54">';
		echo '<h2>' . esc_html__( 'גיבוי / ייצוא נתונים (JSON)', 'gas-safety-manager' ) . '</h2>';
		echo '<p>' . esc_html__( 'מוריד את כל הנתונים (ציוד + ביקורות + תקלות) לקובץ JSON — לגיבוי ולשחזור. ניתן לייבא אותו חזרה דרך "ייבוא מקובץ". מומלץ לגבות לפני כל שינוי גדול.', 'gas-safety-manager' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'gsm_export_backup' );
		echo '<input type="hidden" name="action" value="gsm_export_backup">';
		submit_button( __( 'הורד גיבוי JSON', 'gas-safety-manager' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '</div>';

		// One-time key display.
		if ( $show_key ) {
			echo '<div class="card" style="max-width:720px;padding:16px;margin-bottom:16px;border-left:4px solid #d63638">';
			echo '<h2>' . esc_html__( 'Your migration key (copy now!)', 'gas-safety-manager' ) . '</h2>';
			echo '<p><code style="font-size:15px;user-select:all;background:#f6f7f7;padding:6px 10px;display:inline-block">' . esc_html( $show_key ) . '</code></p>';
			echo '<p class="description">' . esc_html__( 'You will paste this into the migration script. It will not be shown again.', 'gas-safety-manager' ) . '</p>';
			echo '<p class="description"><strong>REST root:</strong> <code style="user-select:all">' . esc_html( $rest_root ) . '</code></p>';
			delete_transient( 'gsm_migration_key_show' );
		}

		// Generate / Revoke buttons.
		echo '<div class="card" style="max-width:720px;padding:16px">';
		if ( ! $enabled ) {
			echo '<h2>' . esc_html__( 'Enable migration', 'gas-safety-manager' ) . '</h2>';
			echo '<p>' . esc_html__( 'Generate a one-time key to allow importing equipment from Monday via the portal Console.', 'gas-safety-manager' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gsm_gen_key' );
			echo '<input type="hidden" name="action" value="gsm_gen_key">';
			submit_button( __( 'Generate migration key', 'gas-safety-manager' ) );
			echo '</form>';
		} else {
			echo '<h2>' . esc_html__( 'Disable migration', 'gas-safety-manager' ) . '</h2>';
			echo '<p>' . esc_html__( 'When the import is done and verified, revoke the key to close the migration endpoint.', 'gas-safety-manager' ) . '</p>';
			echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
			wp_nonce_field( 'gsm_revoke_key' );
			echo '<input type="hidden" name="action" value="gsm_revoke_key">';
			submit_button( __( 'Revoke key / disable migration', 'gas-safety-manager' ), 'delete' );
			echo '</form>';
		}
		echo '</div>';

		echo '</div>'; // wrap.
	}
}
