<?php
/**
 * Checklist upload: file layouts, limits, and that nothing is saved by it.
 * Run: wp eval-file tests/integration/checklist-import-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
$fail = 0;
$t    = function ( $name, $ok, $extra = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $extra ? '' : ' — ' . $extra ) . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$C     = 'GSM_REST_Inspection_Config';
$texts = function ( $l ) {
	return wp_list_pluck( $l, 'text' );
};

$t( 'type cells: Hebrew + English', 'quarterly' === $C::list_key( 'רבעונית' ) && 'monthly' === $C::list_key( 'חודשית' ) && 'quarterly' === $C::list_key( 'Quarterly' ) && 'monthly' === $C::list_key( 'monthly' ) && '' === $C::list_key( 'שנתית' ) );

// The system's own export layout.
$r = $C::rows_to_items(
	array( 'סוג ביקורת', "מס'", 'פריט בדיקה' ),
	array( array( 'חודשית', '1', 'בדיקת דליפות' ), array( 'חודשית', '2', 'שילוט תקין' ), array( 'רבעונית', '1', 'שלמות צנרת' ), array( 'שנתית', '1', 'לא מוכר' ), array( 'רבעונית', '2', '' ), array( 'רבעונית', '3', 'שלמות צנרת' ) ),
	'quarterly'
);
$t( 'export layout: split by type, unknown type skipped, blanks + duplicates dropped', array( 'שלמות צנרת' ) === $texts( $r['quarterly'] ) && array( 'בדיקת דליפות', 'שילוט תקין' ) === $texts( $r['monthly'] ) && 1 === $r['skipped'], wp_json_encode( $r ) );

// Item column only -> the chosen default list.
$r = $C::rows_to_items( array( 'סעיף' ), array( array( 'א' ), array( 'ב' ) ), 'monthly' );
$t( 'item column without a type column goes to the chosen checklist', array( 'א', 'ב' ) === $texts( $r['monthly'] ) && array() === $r['quarterly'] );

// A plain list with no header: the first line is an item too.
$r = $C::rows_to_items( array( 'בדיקת ברזים' ), array( array( 'בדיקת מד לחץ' ), array( 'ניקיון הארון' ) ), 'quarterly' );
$t( 'plain list without a header keeps its first line', array( 'בדיקת ברזים', 'בדיקת מד לחץ', 'ניקיון הארון' ) === $texts( $r['quarterly'] ), wp_json_encode( $r ) );

// Numbered list: "1 | text".
$r = $C::rows_to_items( array( '1', 'בדיקת ברזים' ), array( array( '2', 'בדיקת מד לחץ' ) ), 'quarterly' );
$t( 'numbered list: the text column is used, not the numbers', array( 'בדיקת ברזים', 'בדיקת מד לחץ' ) === $texts( $r['quarterly'] ) );

// Hostile content is plain text; long items are cut; the list is capped.
$r = $C::rows_to_items( array( 'פריט' ), array( array( '<script>alert(1)</script>בדיקה' ), array( str_repeat( 'א', 1000 ) ) ), 'quarterly' );
$t( 'tags stripped, item cut to 300 chars', false === strpos( $r['quarterly'][0]['text'], '<' ) && 300 === mb_strlen( $r['quarterly'][1]['text'] ) );
$many = array();
for ( $i = 0; $i < 250; $i++ ) {
	$many[] = array( 'סעיף ' . $i );
}
$r = $C::rows_to_items( array( 'פריט' ), $many, 'quarterly' );
$t( 'capped at 200 items, reported as truncated', 200 === count( $r['quarterly'] ) && true === $r['truncated'] );
$r = $C::rows_to_items( array( 'x' ), array(), 'quarterly' );
$t( 'nothing usable -> empty', array() === $C::rows_to_items( array( '1', '2' ), array( array( '3', '4' ) ), 'quarterly' )['quarterly'] );

// Route: manager only; needs a file; never writes the configuration.
$before = get_option( 'gsm_inspection_config', null );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$uid    = wp_insert_user( array( 'user_login' => 'cl-' . wp_generate_password( 5, false ), 'user_email' => 'cl-' . wp_generate_password( 5, false ) . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'gsm_lab_manager' ) );
wp_set_current_user( $uid );
$t( 'non-manager refused (403)', 403 === rest_do_request( new WP_REST_Request( 'POST', '/gas/v1/inspection-config/import' ) )->get_status() );
wp_set_current_user( (int) $admins[0] );
$t( 'manager without a file gets 400', 400 === rest_do_request( new WP_REST_Request( 'POST', '/gas/v1/inspection-config/import' ) )->get_status() );
$t( 'the stored configuration is untouched', get_option( 'gsm_inspection_config', null ) === $before );
require_once ABSPATH . 'wp-admin/includes/user.php';
wp_delete_user( $uid );

echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
