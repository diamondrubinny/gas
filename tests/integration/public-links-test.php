<?php
/**
 * Named public-dashboard links: separate tokens, view counter, revoke,
 * and the boundary to the row-level BI export.
 * Run: wp eval-file tests/integration/public-links-test.php
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
$rest = function ( $method, $route, $body = null, $query = array() ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	$r->set_query_params( $query );
	return rest_do_request( $r );
};
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];
$before = get_option( GSM_REST_BI::LINKS_OPTION, null );
delete_option( GSM_REST_BI::LINKS_OPTION );
foreach ( array( 'bi-public', 'bi-data', 'bi-ical' ) as $k ) {
	delete_transient( 'gsm_rl_' . md5( $k . '|' . GSM_Rate_Limit::client_ip() ) );
}
$uid = wp_insert_user( array( 'user_login' => 'pl-' . wp_generate_password( 5, false ), 'user_email' => 'pl-' . wp_generate_password( 5, false ) . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'gsm_lab_manager' ) );

try {
	wp_set_current_user( $uid );
	$t( 'non-manager: list / create / delete refused (403)', 403 === $rest( 'GET', '/gas/v1/bi/public-links' )->get_status() && 403 === $rest( 'POST', '/gas/v1/bi/public-links', array( 'label' => 'x' ) )->get_status() && 403 === $rest( 'DELETE', '/gas/v1/bi/public-links/aaaaaaaaaaaa' )->get_status() );

	wp_set_current_user( $admin );
	$t( 'label is required', 400 === $rest( 'POST', '/gas/v1/bi/public-links', array( 'label' => '   ' ) )->get_status() );
	$a = $rest( 'POST', '/gas/v1/bi/public-links', array( 'label' => 'הנהלת <b>הפקולטה</b>' ) )->get_data();
	$b = $rest( 'POST', '/gas/v1/bi/public-links', array( 'label' => 'ועדת בטיחות' ) )->get_data();
	$t( 'created two links with distinct random tokens, label sanitized', preg_match( '/^[a-f0-9]{32}$/', $a['token'] ) && $a['token'] !== $b['token'] && 'הנהלת הפקולטה' === $a['label'] && $a['token'] !== GSM_REST_BI::public_token() );

	wp_set_current_user( 0 );
	$r = $rest( 'GET', '/gas/v1/bi/public', null, array( 'token' => $a['token'] ) );
	$t( 'a named link opens the aggregate dashboard', 200 === $r->get_status() && isset( $r->get_data()['total_equipment'] ) );
	$t( 'the answer has aggregates only (no names / emails)', false === strpos( wp_json_encode( $r->get_data() ), '@' ) && ! isset( $r->get_data()['items'] ) );
	$rest( 'GET', '/gas/v1/bi/public', null, array( 'token' => $a['token'] ) );
	$links = GSM_REST_BI::public_links();
	$t( 'views counted per link (2 and 0), last view recorded', 2 === (int) $links[0]['views'] && '' !== $links[0]['last_view'] && 0 === (int) $links[1]['views'] );
	$t( 'no visitor data stored with the link', array( 'id', 'label', 'token', 'created_at', 'created_by', 'views', 'last_view' ) === array_keys( $links[0] ) );
	$t( 'main public token still works', 200 === $rest( 'GET', '/gas/v1/bi/public', null, array( 'token' => GSM_REST_BI::public_token() ) )->get_status() );
	$t( 'a named link does NOT open the row-level export', 403 === $rest( 'GET', '/gas/v1/bi/data/equipment', null, array( 'token' => $a['token'], 'format' => 'json' ) )->get_status() );
	$t( 'a named link does NOT open the calendar feed', 403 === $rest( 'GET', '/gas/v1/bi/ical', null, array( 'token' => $a['token'] ) )->get_status() );
	$t( 'the BI export token does NOT open the public dashboard', 403 === $rest( 'GET', '/gas/v1/bi/public', null, array( 'token' => GSM_REST_BI::token() ) )->get_status() );
	$t( 'wrong / malformed tokens refused', 403 === $rest( 'GET', '/gas/v1/bi/public', null, array( 'token' => str_repeat( 'a', 32 ) ) )->get_status() && 403 === $rest( 'GET', '/gas/v1/bi/public', null, array( 'token' => $a['token'] . 'x' ) )->get_status() );

	wp_set_current_user( $admin );
	$t( 'revoke one link', 200 === $rest( 'DELETE', '/gas/v1/bi/public-links/' . $a['id'] )->get_status() && 1 === count( GSM_REST_BI::public_links() ) );
	$t( 'revoking an unknown id is 404', 404 === $rest( 'DELETE', '/gas/v1/bi/public-links/' . $a['id'] )->get_status() );
	wp_set_current_user( 0 );
	$t( 'revoked link stops working, the other still works', 403 === $rest( 'GET', '/gas/v1/bi/public', null, array( 'token' => $a['token'] ) )->get_status() && 200 === $rest( 'GET', '/gas/v1/bi/public', null, array( 'token' => $b['token'] ) )->get_status() );

	wp_set_current_user( $admin );
	$many = array();
	for ( $i = 0; $i < GSM_REST_BI::MAX_LINKS; $i++ ) {
		$many[] = array( 'id' => bin2hex( random_bytes( 6 ) ), 'label' => 'L' . $i, 'token' => bin2hex( random_bytes( 16 ) ), 'created_at' => '', 'created_by' => '', 'views' => 0, 'last_view' => '' );
	}
	update_option( GSM_REST_BI::LINKS_OPTION, $many, false );
	$t( 'link limit enforced', 400 === $rest( 'POST', '/gas/v1/bi/public-links', array( 'label' => 'one too many' ) )->get_status() );
} finally {
	wp_set_current_user( $admin );
	if ( null === $before ) {
		delete_option( GSM_REST_BI::LINKS_OPTION );
	} else {
		update_option( GSM_REST_BI::LINKS_OPTION, $before, false );
	}
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $uid );
	global $wpdb;
	$wpdb->query( "DELETE FROM {$wpdb->prefix}gas_audit WHERE summary LIKE '%קישור ללוח המצב הציבורי%'" );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
