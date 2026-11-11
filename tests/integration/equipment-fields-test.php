<?php
/**
 * Equipment fields added in DB v9: purchase_date, manual_url.
 * Run: wp eval-file tests/integration/equipment-fields-test.php
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
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$tag = wp_generate_password( 5, false );

$cols = $wpdb->get_col( "SHOW COLUMNS FROM {$wpdb->prefix}gas_equipment" );
$t( 'upgrade (DB v9+) added both columns', in_array( 'purchase_date', $cols, true ) && in_array( 'manual_url', $cols, true ) && (int) get_option( 'gsm_db_version' ) >= 9, implode( ',', $cols ) );

$id = GSM_Equipment_Service::create( array( 'name' => 'EF ' . $tag, 'purchase_date' => '2020-05-17', 'manual_url' => 'https://example.com/a b.pdf?x=1' ) );
$e  = is_wp_error( $id ) ? null : GSM_Equipment_Repo::get( (int) $id );
$t( 'create stores date + https link (escaped)', $e && '2020-05-17' === $e['purchase_date'] && 0 === strpos( $e['manual_url'], 'https://example.com/a%20b.pdf' ), $e ? $e['manual_url'] : 'error' );
$r = GSM_Equipment_Service::update( (int) $id, array( 'manual_url' => 'http://example.com/m.pdf' ) );
$t( 'http link rejected', is_wp_error( $r ) && 'https://example.com/a%20b.pdf?x=1' === GSM_Equipment_Repo::get( (int) $id )['manual_url'] );
$r = GSM_Equipment_Service::update( (int) $id, array( 'manual_url' => 'javascript:alert(1)' ) );
$t( 'javascript: link never stored', '' === GSM_Equipment_Repo::get( (int) $id )['manual_url'] || is_wp_error( $r ) );
$t( 'no script scheme in the stored value', false === stripos( (string) GSM_Equipment_Repo::get( (int) $id )['manual_url'], 'javascript' ) );
$r = GSM_Equipment_Service::update( (int) $id, array( 'purchase_date' => '31/12/2020' ) );
$t( 'invalid date format stored as empty, not garbage', ! is_wp_error( $r ) && empty( GSM_Equipment_Repo::get( (int) $id )['purchase_date'] ) );
GSM_Equipment_Service::update( (int) $id, array( 'manual_url' => 'https://example.com/ok.pdf', 'purchase_date' => '2019-01-02' ) );
$req = new WP_REST_Request( 'GET', '/gas/v1/equipment/' . (int) $id );
$d   = rest_do_request( $req )->get_data();
$t( 'REST returns both fields', '2019-01-02' === $d['purchase_date'] && 'https://example.com/ok.pdf' === $d['manual_url'] );
$t( 'import field list includes both', isset( GSM_REST_Import::fields()['purchase_date'], GSM_REST_Import::fields()['manual_url'] ) );
$snap = GSM_Restore_Service::snapshot( 'EF ' . $tag, 'manual' );
$t( 'restore point contains the new fields', false !== strpos( (string) GSM_Restore_Service::get( (int) $snap['id'] )['payload'], 'ok.pdf' ) );
GSM_Restore_Service::delete( (int) $snap['id'] );
$wpdb->delete( $wpdb->prefix . 'gas_equipment', array( 'id' => (int) $id ) );
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
