<?php
/**
 * Users import: shapes, role mapping, PIN hashes never stored, existing
 * accounts untouched, preview vs apply.
 * Run: wp eval-file tests/integration/users-import-test.php
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
$tag    = strtolower( wp_generate_password( 6, false ) );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1 ) );
$admin  = $admins[0];
wp_set_current_user( $admin->ID );
$tmp     = array();
$created = array();
$write   = function ( $ext, $content ) use ( &$tmp ) {
	$f     = wp_tempnam( 'gsm-ui' ) . '.' . $ext;
	file_put_contents( $f, $content );
	$tmp[] = $f;
	return $f;
};

try {
	$t( 'role mapping (Hebrew + keys, "מנהל מעבדה" is not "מנהל")', 'ahmat' === GSM_Users_Import::role_key( 'אחמ"ת' ) && 'lab_manager' === GSM_Users_Import::role_key( 'מנהל מעבדה' ) && 'manager' === GSM_Users_Import::role_key( 'מנהל' ) && 'pi' === GSM_Users_Import::role_key( 'חוקר ראשי' ) && 'pi' === GSM_Users_Import::role_key( 'PI' ) && 'manager' === GSM_Users_Import::role_key( 'manager' ) && '' === GSM_Users_Import::role_key( 'אורח' ) );

	// Old-system style JSON, with PIN hashes that must be ignored.
	$json = wp_json_encode(
		array(
			'exportedAt' => '2026-11-12',
			'users'      => array(
				array( 'id' => '1', 'name' => 'דנה כהן ' . $tag, 'email' => 'dana-' . $tag . '@example.test', 'role' => 'אחמ"ת', 'active' => 'פעיל', 'pinHash' => 'abc123hash', 'pin' => '482913' ),
				array( 'id' => '2', 'name' => 'רון לוי', 'email' => 'ron-' . $tag . '@example.test', 'role' => 'מנהל מעבדה', 'active' => 'לא פעיל' ),
				array( 'id' => '3', 'name' => 'כבר קיים', 'email' => $admin->user_email, 'role' => 'חוקר ראשי', 'active' => 'פעיל' ),
				array( 'id' => '4', 'name' => 'בלי מייל', 'email' => 'not-an-email', 'role' => 'מנהל' ),
				array( 'id' => '5', 'name' => 'תפקיד מוזר', 'email' => 'odd-' . $tag . '@example.test', 'role' => 'אורח' ),
				array( 'id' => '6', 'name' => 'כפול', 'email' => 'DANA-' . $tag . '@example.test', 'role' => 'מנהל' ),
			),
		),
		JSON_UNESCAPED_UNICODE
	);
	$rows = GSM_Users_Import::read( $write( 'json', $json ), 'json' );
	$plan = GSM_Users_Import::plan( $rows );
	$t( 'JSON export: 2 new, 1 existing, 2 invalid, 1 duplicate', array( 'new' => 2, 'existing' => 1, 'invalid' => 2, 'duplicate' => 1 ) === $plan['counts'], wp_json_encode( $plan['counts'] ) );
	$t( 'plan carries no PIN data', false === strpos( wp_json_encode( $plan ), 'abc123hash' ) && false === strpos( wp_json_encode( $plan ), '482913' ) );
	$t( 'preview creates nothing', ! email_exists( 'dana-' . $tag . '@example.test' ) );

	$sent = 0;
	$res  = GSM_Users_Import::apply( $plan, function ( $u ) use ( &$sent ) { $sent++; return true; } );
	$u1   = get_user_by( 'email', 'dana-' . $tag . '@example.test' );
	$u2   = get_user_by( 'email', 'ron-' . $tag . '@example.test' );
	$created = array_filter( array( $u1 ? $u1->ID : 0, $u2 ? $u2->ID : 0 ) );
	$t( 'apply: 2 created with the right roles', 2 === $res['created'] && $u1 && in_array( 'gsm_ahmat', $u1->roles, true ) && $u2 && in_array( 'gsm_lab_manager', $u2->roles, true ) );
	$t( 'inactive user imported as inactive, and not e-mailed', $u2 && ! GSM_Auth::is_active( $u2 ) && GSM_Auth::is_active( $u1 ) && 1 === $sent && 1 === $res['emailed'] );
	$t( 'old PIN does not work as a password; no PIN stored in user meta', $u1 && ! wp_check_password( '482913', $u1->user_pass, $u1->ID ) && false === strpos( wp_json_encode( get_user_meta( $u1->ID ) ), 'abc123hash' ) );
	$t( 'existing account untouched (administrator keeps its role)', in_array( 'administrator', get_userdata( $admin->ID )->roles, true ) && ! in_array( 'gsm_pi', get_userdata( $admin->ID )->roles, true ) );
	$again = GSM_Users_Import::plan( $rows );
	$t( 'second run: nothing new', 0 === $again['counts']['new'] && 3 === $again['counts']['existing'] );

	// CSV with Hebrew headers.
	$csv  = "\xEF\xBB\xBFשם מלא,מייל,תפקיד,סטטוס\r\nיעל " . $tag . ',yael-' . $tag . "@example.test,חוקר ראשי,פעיל\r\n";
	$rows = GSM_Users_Import::read( $write( 'csv', $csv ), 'csv' );
	$p2   = GSM_Users_Import::plan( $rows );
	$t( 'CSV with Hebrew headers recognized', 1 === $p2['counts']['new'] && 'pi' === $p2['rows'][0]['role'] && 'יעל ' . $tag === $p2['rows'][0]['name'], wp_json_encode( $p2 ) );

	// Raw Monday items.
	$mon  = wp_json_encode( array( 'items' => array( array( 'id' => '9', 'name' => 'Monday ' . $tag, 'column_values' => array( array( 'id' => 'text_mm2sb7g4', 'text' => 'mon-' . $tag . '@example.test' ), array( 'id' => 'color_mm2s4qcb', 'text' => 'מנהל' ), array( 'id' => 'color_mm2st51e', 'text' => 'פעיל' ) ) ) ) ) );
	$p3   = GSM_Users_Import::plan( GSM_Users_Import::read( $write( 'json', $mon ), 'json' ) );
	$t( 'raw Monday items recognized by column id', 1 === $p3['counts']['new'] && 'manager' === $p3['rows'][0]['role'] && 'mon-' . $tag . '@example.test' === $p3['rows'][0]['email'], wp_json_encode( $p3 ) );

	$t( 'invalid JSON refused', is_wp_error( GSM_Users_Import::read( $write( 'json', '{"users": [' ), 'json' ) ) );
	$big = array();
	for ( $i = 0; $i <= GSM_Users_Import::MAX_ROWS; $i++ ) {
		$big[] = array( 'email' => 'b' . $i . '@example.test', 'role' => 'pi' );
	}
	$t( 'row limit enforced', is_wp_error( GSM_Users_Import::plan( $big ) ) );

	// Route permissions.
	$lab = wp_insert_user( array( 'user_login' => 'ui-' . $tag, 'user_email' => 'ui-' . $tag . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'gsm_lab_manager' ) );
	$created[] = $lab;
	wp_set_current_user( $lab );
	$t( 'non-manager cannot import (403)', 403 === rest_do_request( new WP_REST_Request( 'POST', '/gas/v1/users/import' ) )->get_status() );
	wp_set_current_user( $admin->ID );
	$t( 'manager without a file gets 400', 400 === rest_do_request( new WP_REST_Request( 'POST', '/gas/v1/users/import' ) )->get_status() );
} finally {
	wp_set_current_user( $admin->ID );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	foreach ( $created as $id ) {
		if ( $id ) {
			wp_delete_user( (int) $id );
		}
	}
	foreach ( $tmp as $f ) {
		@unlink( $f );
		@unlink( preg_replace( '/\.(json|csv)$/', '', $f ) );
	}
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
