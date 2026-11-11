<?php
/**
 * Integration tests for the fixes from the 2.14 code review, on a real
 * WordPress + DB. Run: wp eval-file tests/integration/security-fixes-test.php
 * Creates marked users / records and removes them at the end.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
global $wpdb;
$fail = 0;
$t    = function ( $name, $ok, $extra = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $extra ? '' : ' — ' . $extra ) . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$rest = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $r );
};
$mark    = 'sec-test-' . wp_generate_password( 6, false );
$admins  = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin   = (int) $admins[0];
$created = array( 'users' => array(), 'equipment' => array() );
$mkuser  = function ( $role, $suffix ) use ( $mark, &$created ) {
	$id = wp_insert_user( array( 'user_login' => $mark . '-' . $suffix, 'user_email' => $mark . '-' . $suffix . '@example.test', 'user_pass' => 'Pw-' . $mark, 'role' => $role ) );
	$created['users'][] = $id;
	return (int) $id;
};

// ---- 1. Deactivated users lose access everywhere (HIGH) --------------------
$mgr = $mkuser( 'gsm_manager', 'mgr' );
wp_set_current_user( $mgr );
$t( 'active manager can reach /users', 200 === $rest( 'GET', '/gas/v1/users' )->get_status() );
update_user_meta( $mgr, 'gsm_active', '0' );
wp_set_current_user( 0 );
wp_set_current_user( $mgr );
$t( 'deactivated manager: can_manage() is false', ! GSM_Auth::can_manage() && ! GSM_Auth::can_report() && ! GSM_Auth::can_issuance() );
$t( 'deactivated manager: /users -> 403', 403 === $rest( 'GET', '/gas/v1/users' )->get_status() );
$t( 'deactivated manager: /retention -> 403', 403 === $rest( 'GET', '/gas/v1/retention' )->get_status() );
$wrong = wp_authenticate( $mark . '-mgr', 'not-the-password' );
$right = wp_authenticate( $mark . '-mgr', 'Pw-' . $mark );
$t( 'wrong password on a deactivated account = generic error (no enumeration)', is_wp_error( $wrong ) && 'gsm_inactive' !== $wrong->get_error_code(), is_wp_error( $wrong ) ? $wrong->get_error_code() : 'no error' );
$t( 'correct password on a deactivated account = refused (gsm_inactive)', is_wp_error( $right ) && 'gsm_inactive' === $right->get_error_code(), is_wp_error( $right ) ? $right->get_error_code() : 'logged in!' );
update_user_meta( $mgr, 'gsm_active', '1' );

$lab   = $mkuser( 'gsm_lab_manager', 'lab' );
$tok   = WP_Session_Tokens::get_instance( $lab );
$tok->create( time() + HOUR_IN_SECONDS );
wp_set_current_user( $admin );
$res = $rest( 'PUT', '/gas/v1/users/' . $lab, array( 'active' => false ) );
$t( 'deactivating via REST destroys all sessions', 200 === $res->get_status() && 0 === count( WP_Session_Tokens::get_instance( $lab )->get_all() ) );

// ---- 7. A gas manager cannot touch non-gas site users -----------------------
$editor = $mkuser( 'editor', 'editor' );
wp_set_current_user( $mgr );
$res = $rest( 'PUT', '/gas/v1/users/' . $editor, array( 'role' => 'pi' ) );
$t( 'manager cannot change a site editor (403)', 403 === $res->get_status() && in_array( 'editor', get_userdata( $editor )->roles, true ) );
$t( 'manager cannot reset a site editor password (403)', 403 === $rest( 'POST', '/gas/v1/users/' . $editor . '/reset' )->get_status() );
$both = $mkuser( 'gsm_pi', 'both' );
( new WP_User( $both ) )->add_role( 'editor' );
$res  = $rest( 'PUT', '/gas/v1/users/' . $both, array( 'role' => 'ahmat' ) );
$r    = get_userdata( $both )->roles;
$t( 'gas role swap keeps the other role', 200 === $res->get_status() && in_array( 'gsm_ahmat', $r, true ) && in_array( 'editor', $r, true ) && ! in_array( 'gsm_pi', $r, true ), implode( ',', $r ) );

// ---- 2. WhatsApp token / webhook secret do not follow a host change; SSRF ---
wp_set_current_user( $admin );
$comm_before = get_option( 'gsm_comm', null );
$int_before  = get_option( 'gsm_integrations', null );
GSM_Comm::save( array( 'whatsapp_url' => 'https://gw.example.test/send', 'whatsapp_token' => 'secret-token-1', 'whatsapp_enabled' => true ) );
GSM_Comm::save( array( 'whatsapp_url' => 'https://gw.example.test/other', 'whatsapp_enabled' => true ) );
$c = get_option( 'gsm_comm' );
$t( 'same host: token kept', 'secret-token-1' === $c['whatsapp_token'] );
GSM_Comm::save( array( 'whatsapp_url' => 'https://attacker.example.test/x', 'whatsapp_enabled' => true ) );
$c = get_option( 'gsm_comm' );
$t( 'host changed without a new token: token wiped', '' === $c['whatsapp_token'] );
GSM_Comm::save( array( 'whatsapp_url' => 'http://gw.example.test/send', 'whatsapp_token' => 'x' ) );
$c = get_option( 'gsm_comm' );
$t( 'WhatsApp URL must be https', '' === $c['whatsapp_url'] );
// (wp_safe_remote_post allows the site's OWN host — on this test site that is
// 127.0.0.1 — so probe another loopback address, metadata and a private net.)
$ssrf = array();
foreach ( array( 'http://127.0.0.2:8899/', 'http://169.254.169.254/latest/meta-data/', 'http://10.0.0.5/', 'http://192.168.1.1/' ) as $u ) {
	$ssrf[ $u ] = is_wp_error( GSM_Security::outbound_post( $u, array( 'timeout' => 3 ) ) );
}
$t( 'outbound calls refuse loopback / metadata / private networks (SSRF)', ! in_array( false, $ssrf, true ), wp_json_encode( $ssrf ) );
GSM_Integrations::save( array( 'webhookUrl' => 'https://hooks.example.test/a', 'webhookSecret' => 'sig-secret' ) );
GSM_Integrations::save( array( 'webhookUrl' => 'https://evil.example.test/a' ) );
$ic = get_option( GSM_Integrations::OPTION );
$t( 'webhook secret wiped when host changes', '' === $ic['webhookSecret'] );
null === $comm_before ? delete_option( 'gsm_comm' ) : update_option( 'gsm_comm', $comm_before );
null === $int_before ? delete_option( GSM_Integrations::OPTION ) : update_option( GSM_Integrations::OPTION, $int_before );

// ---- 3 + 9. Undo restores inspection items; QR-issuance undo works ----------
$eq_id                  = (int) GSM_Equipment_Service::create( array( 'name' => $mark . ' equipment', 'asset_code' => $mark . '-A1' ) );
$created['equipment'][] = $eq_id;
$insp = GSM_Inspections_Service::create( array( 'equipment_id' => $eq_id, 'type' => 'quarterly', 'inspection_date' => '2026-11-01', 'items' => array(
	array( 'item_text' => 'valve', 'item_result' => 'ok' ),
	array( 'item_text' => 'leak test', 'item_result' => 'defect' ),
) ) );
$insp_id = is_array( $insp ) ? (int) $insp['id'] : (int) $insp;
GSM_Inspections_Service::delete( $insp_id );
$aid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}gas_audit WHERE action='delete' AND entity='inspection' AND entity_id=%d ORDER BY id DESC LIMIT 1", $insp_id ) );
$res = $rest( 'POST', '/gas/v1/audit/' . $aid . '/undo' );
$nid = (int) ( $res->get_data()['id'] ?? 0 );
$t( 'undo inspection restores its 2 checklist items', 200 === $res->get_status() && 2 === count( GSM_Inspections_Repo::get_items( $nid ) ), wp_json_encode( $res->get_data() ) );
$qr = GSM_QR_Issuance_Service::create( array( 'equipment_id' => $eq_id, 'equipment_name' => $mark, 'status' => 'archived' ) );
$qid = is_array( $qr ) ? (int) ( $qr['id'] ?? 0 ) : (int) $qr;
GSM_QR_Issuance_Service::delete( $qid );
$qaid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}gas_audit WHERE action='delete' AND entity='qr_issuance' AND entity_id=%d ORDER BY id DESC LIMIT 1", $qid ) );
$t( 'QR-issuance undo succeeds (was offered but always failed)', $qaid > 0 && 200 === $rest( 'POST', '/gas/v1/audit/' . $qaid . '/undo' )->get_status() );

// ---- 4. Restore does not duplicate checklist items --------------------------
$snap = GSM_Restore_Service::snapshot( $mark, 'manual' );
$t( 'snapshot returns a real id', ! empty( $snap['id'] ) );
GSM_Inspections_Service::update( $nid, array( 'items' => array(
	array( 'item_text' => 'new 1', 'item_result' => 'ok' ),
	array( 'item_text' => 'new 2', 'item_result' => 'ok' ),
	array( 'item_text' => 'new 3', 'item_result' => 'ok' ),
) ) );
$rr    = GSM_Restore_Service::restore( (int) $snap['id'] );
$items = GSM_Inspections_Repo::get_items( $nid );
$t( 'restore: items = snapshot items only (no duplicates)', ! is_wp_error( $rr ) && 2 === count( $items ) && 'valve' === $items[0]['item_text'], count( $items ) . ' items' );

// ---- 5. Import update: empty cells do not wipe values -----------------------
GSM_Equipment_Service::update( $eq_id, array( 'pi_name' => 'Dr Keep', 'phone' => '050-1' ) );
$res = $rest( 'POST', '/gas/v1/import/equipment', array(
	'headers'       => array( 'asset_code', 'pi_name', 'phone', 'room' ),
	'rows'          => array( array( $mark . '-A1', '', '', '7' ) ),
	'mapping'       => array( 'asset_code' => 0, 'pi_name' => 1, 'phone' => 2, 'room' => 3 ),
	'keyField'      => 'asset_code',
	'createMissing' => false,
) );
$e = GSM_Equipment_Repo::get( $eq_id );
$t( 'import update keeps values for empty cells, applies filled ones', 200 === $res->get_status() && 'Dr Keep' === $e['pi_name'] && '050-1' === $e['phone'] && '7' === (string) $e['room'], wp_json_encode( array( $e['pi_name'], $e['phone'], $e['room'] ) ) );
$big = $rest( 'POST', '/gas/v1/import/equipment', array( 'rows' => array_fill( 0, GSM_Spreadsheet::MAX_ROWS + 1, array( 'x' ) ), 'mapping' => array( 'name' => 0 ) ) );
$t( 'import rejects more rows than the cap (413)', 413 === $big->get_status() );

// ---- 10. Backup re-import does not duplicate equipment without monday_id -----
$before = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}gas_equipment WHERE asset_code = %s", $mark . '-A1' ) );
GSM_Importer::import_array( array( 'equipment' => array( array( 'name' => $mark . ' equipment', 'asset_code' => $mark . '-A1', 'room' => '8' ) ) ) );
$after = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}gas_equipment WHERE asset_code = %s", $mark . '-A1' ) );
$t( 'backup re-import updates by asset_code (no duplicate)', 1 === $before && 1 === $after && '8' === (string) GSM_Equipment_Repo::get( $eq_id )['room'], "$before -> $after" );

// ---- 11. Small fixes -------------------------------------------------------
$x   = GSM_Xlsx::build( array( array( 'name' => 'S', 'rows' => array( array( 'a', "bad\x0Bcell" ) ) ) ) );
$tmp = wp_tempnam( 'x' );
file_put_contents( $tmp, $x['data'] );
$zip = new ZipArchive();
$ok  = true === $zip->open( $tmp ) && false !== simplexml_load_string( (string) $zip->getFromName( 'xl/worksheets/sheet1.xml' ) );
$t( 'XLSX with a control character is still valid XML', $ok );
@unlink( $tmp ); // phpcs:ignore
wp_set_current_user( $mgr );
update_user_meta( $mgr, 'gsm_phone', '052-9' );
$rest( 'POST', '/gas/v1/profile', array( 'name' => 'Renamed' ) );
$t( 'profile save without phone keeps the phone', '052-9' === get_user_meta( $mgr, 'gsm_phone', true ) );

// ---- Cleanup ---------------------------------------------------------------
wp_set_current_user( $admin );
foreach ( $created['equipment'] as $id ) {
	$wpdb->delete( $wpdb->prefix . 'gas_inspection_items', array( 'inspection_id' => $nid ) );
	$wpdb->delete( $wpdb->prefix . 'gas_inspections', array( 'equipment_id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'gas_qr_issuance', array( 'equipment_id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'gas_equipment', array( 'id' => $id ) );
}
if ( ! empty( $snap['id'] ) ) {
	GSM_Restore_Service::delete( (int) $snap['id'] );
}
$wpdb->query( "DELETE FROM {$wpdb->prefix}gas_restore_points WHERE label LIKE 'גיבוי אוטומטי לפני שחזור%' AND created_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)" );
require_once ABSPATH . 'wp-admin/includes/user.php';
foreach ( $created['users'] as $uid ) {
	wp_delete_user( $uid );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
