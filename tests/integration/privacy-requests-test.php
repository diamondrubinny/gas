<?php
/**
 * Privacy deletion requests: scan + anonymize on a real WordPress + DB.
 * Run: wp eval-file tests/integration/privacy-requests-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
global $wpdb;
$p    = $wpdb->prefix . 'gas_';
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
$tag   = wp_generate_password( 5, false );
$NAME  = 'דנה לוי' . $tag;           // Unique per run, Hebrew (exercises \u-escaped JSON).
$EMAIL = 'dana.' . strtolower( $tag ) . '@example.test';
$LOOK  = $NAME . 'ה';                 // Look-alike: must NOT be touched.
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];
wp_set_current_user( $admin );

// The person: a gas-app user.
$uid = wp_insert_user( array( 'user_login' => 'pr-' . $tag, 'user_email' => $EMAIL, 'display_name' => $NAME, 'user_pass' => wp_generate_password(), 'role' => 'gsm_lab_manager' ) );

// Seed the name/email everywhere.
$eq  = (int) GSM_Equipment_Service::create( array( 'name' => 'PR equipment ' . $tag, 'asset_code' => 'PR-' . $tag, 'pi_name' => $NAME, 'lab_managers' => 'רון, ' . $NAME . ', רות', 'maint_emails' => $EMAIL . ', other@example.test', 'notes' => 'קשר: ' . $NAME . '. ' . $LOOK ) );
$eq2 = (int) GSM_Equipment_Service::create( array( 'name' => 'PR look-alike ' . $tag, 'asset_code' => 'PR2-' . $tag, 'pi_name' => $LOOK ) );
$ins = GSM_Inspections_Service::create( array( 'equipment_id' => $eq, 'type' => 'quarterly', 'inspection_date' => '2026-11-01', 'inspector' => $NAME, 'inspector_email' => $EMAIL, 'items' => array( array( 'item_text' => 'x', 'item_result' => 'ok' ) ) ) );
$ins_id = is_array( $ins ) ? (int) $ins['id'] : (int) $ins;
$flt = GSM_Faults_Service::create( array( 'equipment_id' => $eq, 'description' => 'דווח על ידי ' . $NAME, 'reporter' => $NAME, 'priority' => 'normal' ) );
$wpdb->insert( $p . 'comm_log', array( 'kind' => 'broadcast', 'channel' => 'email', 'recipient' => $EMAIL, 'subject' => 's', 'status' => 'sent', 'ref' => 'pr', 'created_at' => current_time( 'mysql' ) ) );
// An equipment deletion puts a full JSON copy (with the name) into the audit log.
$eq3 = (int) GSM_Equipment_Service::create( array( 'name' => 'PR deleted ' . $tag, 'asset_code' => 'PR3-' . $tag, 'pi_name' => $NAME ) );
GSM_Equipment_Service::delete( $eq3 );
// A restore point snapshots everything (JSON, Hebrew escaped as \uXXXX).
$snap = GSM_Restore_Service::snapshot( 'PR ' . $tag, 'manual' );

// The deletion request (as the user would file it).
wp_set_current_user( $uid );
$res = $rest( 'POST', '/gas/v1/profile/deletion-request', array( 'reason' => 'test ' . $tag ) );
wp_set_current_user( $admin );
$rid = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$p}deletion_requests WHERE user_id = %d ORDER BY id DESC LIMIT 1", $uid ) );
$t( 'request filed', $rid > 0, (string) $res->get_status() );

// Counting helper: occurrences anywhere (incl. JSON decoded), and look-alikes.
$occ = function ( $needle ) use ( $wpdb, $p ) {
	$n = 0;
	foreach ( GSM_Privacy_Requests::locations() as $l ) {
		$rows = $wpdb->get_col( "SELECT {$l[1]} FROM {$p}{$l[0]}" );
		foreach ( (array) $rows as $v ) {
			$v  = (string) $v;
			$n += substr_count( $v, $needle );
			if ( 'json' === $l[2] && null !== json_decode( $v ) ) {
				$n += substr_count( wp_json_encode( json_decode( $v, true ), JSON_UNESCAPED_UNICODE ), $needle ) - substr_count( $v, $needle );
			}
		}
	}
	return $n;
};
$t( 'seeded: name appears in many places', $occ( $NAME ) - $occ( $LOOK ) >= 8, (string) ( $occ( $NAME ) - $occ( $LOOK ) ) );

// Permissions.
wp_set_current_user( $uid );
$t( 'non-manager cannot list requests (403)', 403 === $rest( 'GET', '/gas/v1/privacy-requests' )->get_status() );
wp_set_current_user( $admin );

// List + defaults.
$list = $rest( 'GET', '/gas/v1/privacy-requests' )->get_data();
$mine = array_values( array_filter( $list, function ( $r ) use ( $rid ) { return (int) $r['id'] === $rid; } ) );
$t( 'listed with default identifiers (name + email)', ! empty( $mine ) && in_array( $NAME, $mine[0]['identifiers'], true ) && in_array( $EMAIL, $mine[0]['identifiers'], true ) );

// Scan (dry run) changes nothing.
$before = $occ( $NAME ) - $occ( $LOOK );
$scan   = $rest( 'POST', '/gas/v1/privacy-requests/' . $rid . '/scan', array( 'identifiers' => array( $NAME, $EMAIL ) ) )->get_data();
$labels = wp_list_pluck( $scan['locations'], 'label' );
$t( 'scan finds JSON copies too (audit + restore point)', in_array( 'יומן בקרה — עותק לפני מחיקה', $labels, true ) && in_array( 'נקודות שחזור — תוכן', $labels, true ), implode( ' | ', $labels ) );
$t( 'scan is a dry run', $occ( $NAME ) - $occ( $LOOK ) === $before );

// Anonymize + delete account.
$out = $rest( 'POST', '/gas/v1/privacy-requests/' . $rid . '/anonymize', array( 'identifiers' => array( $NAME, $EMAIL ), 'delete_account' => true ) );
$d   = $out->get_data();
$t( 'anonymize: 200 and replaced as many as scanned', 200 === $out->get_status() && (int) $d['total_matches'] === (int) $scan['total_matches'] && $d['total_matches'] > 0, wp_json_encode( array( $scan['total_matches'], $d['total_matches'] ?? null ) ) );
// The look-alike contains the name as a substring, so subtract its occurrences.
$left = $occ( $NAME ) - $occ( $LOOK );
$t( 'no occurrence of the name left anywhere (incl. JSON)', 0 === $left, $left . ' left' );
$t( 'no occurrence of the email left anywhere', 0 === $occ( $EMAIL ), (string) $occ( $EMAIL ) );
$t( 'look-alike name untouched', $occ( $LOOK ) >= 2 && $LOOK === GSM_Equipment_Repo::get( $eq2 )['pi_name'] );
$e = GSM_Equipment_Repo::get( $eq );
$t( 'list fields keep the other people', 'רון, משתמש נמחק #' . $rid . ', רות' === $e['lab_managers'] && false !== strpos( $e['maint_emails'], 'other@example.test' ), $e['lab_managers'] . ' / ' . $e['maint_emails'] );
$t( 'professional record kept (inspection + its items)', null !== GSM_Inspections_Repo::get( $ins_id ) && 1 === count( GSM_Inspections_Repo::get_items( $ins_id ) ) );
$rp = GSM_Restore_Service::get( (int) $snap['id'] );
$t( 'restore point still valid JSON after anonymization', is_array( json_decode( (string) $rp['payload'], true ) ) );
$t( 'WordPress account deleted', false === get_userdata( $uid ) );
$t( 'request closed (done)', 'done' === GSM_Privacy_Requests::get( $rid )['status'] );
$last = (string) $wpdb->get_var( "SELECT summary FROM {$p}audit ORDER BY id DESC LIMIT 1" );
$t( 'audit entry written without the name / email', false !== strpos( $last, 'טופלה בקשת פרטיות #' . $rid ) && false === strpos( $last, $NAME ) && false === strpos( $last, $EMAIL ), $last );
$t( 'closed request cannot be anonymized again', 400 === $rest( 'POST', '/gas/v1/privacy-requests/' . $rid . '/anonymize', array( 'identifiers' => array( $NAME ) ) )->get_status() );
$t( 'too-short identifiers rejected', 400 === $rest( 'POST', '/gas/v1/privacy-requests/' . $rid . '/scan', array( 'identifiers' => array( 'ab', ' ' ) ) )->get_status() );

// Cleanup.
foreach ( array( $eq, $eq2 ) as $id ) {
	$wpdb->delete( $p . 'inspection_items', array( 'inspection_id' => $ins_id ) );
	$wpdb->delete( $p . 'inspections', array( 'equipment_id' => $id ) );
	$wpdb->delete( $p . 'faults', array( 'equipment_id' => $id ) );
	$wpdb->delete( $p . 'equipment', array( 'id' => $id ) );
}
$wpdb->delete( $p . 'deletion_requests', array( 'id' => $rid ) );
$wpdb->delete( $p . 'comm_log', array( 'ref' => 'pr' ) );
if ( ! empty( $snap['id'] ) ) {
	GSM_Restore_Service::delete( (int) $snap['id'] );
}
if ( get_userdata( $uid ) ) {
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $uid );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
