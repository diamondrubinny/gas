<?php
/**
 * Integration test for GSM_Retention against a real WordPress + database.
 * Run: wp eval-file tests/integration/retention-test.php
 *
 * Inserts marked rows (old / recent), runs the cleanup, checks exact counts,
 * then removes its rows and restores the original options.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
global $wpdb;
$p    = $wpdb->prefix . 'gas_';
$mark = 'retention-test';
$fail = 0;
$t    = function ( $name, $ok, $extra = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $extra ? '' : ' — ' . $extra ) . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$ago = function ( $months, $days = 0 ) {
	return wp_date( 'Y-m-d H:i:s', strtotime( '-' . (int) $months . ' months -' . (int) $days . ' days', time() ) );
};
$now = current_time( 'mysql' );

$saved_cfg   = get_option( GSM_Retention::OPTION, null );
$saved_armed = get_option( GSM_Retention::ARMED, null );
$saved_last  = get_option( GSM_Retention::LAST, null );

$cleanup = function () use ( $wpdb, $p, $mark ) {
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}scans WHERE ip_hash = %s", $mark ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}comm_log WHERE ref = %s", $mark ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}audit WHERE actor = %s", $mark ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}deletion_requests WHERE reason = %s", $mark ) );
};
$count = function ( $table, $col, $val ) use ( $wpdb, $p ) {
	return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}{$table} WHERE {$col} = %s", $val ) );
};
$cleanup();
delete_option( GSM_Retention::OPTION ); // Defaults: 24 / 24 / 84 / 24.

// ---- Seed ----------------------------------------------------------------
// Scans: 1200 older than 24 months (several batches), 3 recent, 2 just inside.
for ( $i = 0; $i < 1200; $i++ ) {
	$wpdb->insert( $p . 'scans', array( 'equipment_id' => 1, 'scanned_at' => $ago( 25, $i % 30 ), 'user_id' => 0, 'ip_hash' => $mark ) );
}
for ( $i = 0; $i < 3; $i++ ) {
	$wpdb->insert( $p . 'scans', array( 'equipment_id' => 1, 'scanned_at' => $now, 'user_id' => 0, 'ip_hash' => $mark ) );
}
for ( $i = 0; $i < 2; $i++ ) {
	$wpdb->insert( $p . 'scans', array( 'equipment_id' => 1, 'scanned_at' => $ago( 23 ), 'user_id' => 0, 'ip_hash' => $mark ) );
}
// Comm log: 4 old, 1 recent.
for ( $i = 0; $i < 5; $i++ ) {
	$wpdb->insert( $p . 'comm_log', array( 'kind' => 'reminder', 'channel' => 'email', 'recipient' => 'x@example.test', 'subject' => 's', 'status' => 'sent', 'ref' => $mark, 'created_at' => $i < 4 ? $ago( 30 ) : $now ) );
}
// Audit: 2 older than 84 months, 2 at 60 months (kept), 1 recent.
foreach ( array( 90, 86, 60, 60, 0 ) as $m ) {
	$wpdb->insert( $p . 'audit', array( 'created_at' => $m ? $ago( $m ) : $now, 'actor' => $mark, 'actor_id' => 0, 'action' => 'update', 'entity' => 'system', 'entity_id' => 0, 'item_name' => '', 'summary' => 'seed', 'undone' => 0 ) );
}
// Deletion requests: old OPEN (never deleted), old DONE (deleted), recent DONE (kept).
$wpdb->insert( $p . 'deletion_requests', array( 'created_at' => $ago( 40 ), 'user_id' => 0, 'email' => 'a@example.test', 'reason' => $mark, 'status' => 'open' ) );
$wpdb->insert( $p . 'deletion_requests', array( 'created_at' => $ago( 40 ), 'user_id' => 0, 'email' => 'b@example.test', 'reason' => $mark, 'status' => 'done' ) );
$wpdb->insert( $p . 'deletion_requests', array( 'created_at' => $now, 'user_id' => 0, 'email' => 'c@example.test', 'reason' => $mark, 'status' => 'done' ) );

$t( 'seeded', 1205 === $count( 'scans', 'ip_hash', $mark ) && 5 === $count( 'comm_log', 'ref', $mark ) && 5 === $count( 'audit', 'actor', $mark ) );

// ---- Grace period: a freshly armed install must not delete automatically ----
delete_option( GSM_Retention::ARMED );
$r = GSM_Retention::run_scheduled();
$t( 'grace period: scheduled run does nothing on a fresh install', null === $r && 1205 === $count( 'scans', 'ip_hash', $mark ) );
$t( 'grace period is 7 days', abs( GSM_Retention::active_from() - ( time() + 7 * DAY_IN_SECONDS ) ) < 5 );

// ---- Preview -------------------------------------------------------------
$pv = GSM_Retention::preview();
$t( 'preview counts what would be deleted (at least our old rows)', $pv['scans'] >= 1200 && $pv['comm'] >= 4 && $pv['audit'] >= 2 && $pv['deletions'] >= 1, wp_json_encode( $pv ) );

// ---- Scheduled run after the grace period --------------------------------
update_option( GSM_Retention::ARMED, time() - 8 * DAY_IN_SECONDS, false );
$audit_before = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}audit WHERE summary LIKE '%ניקוי לפי מדיניות שמירה%'" );
$r = GSM_Retention::run_scheduled();
$t( 'scheduled run after grace runs', is_array( $r ) && 'auto' === $r['_trigger'] );
$t( 'scans: 1200 old deleted across batches, 5 recent kept', 5 === $count( 'scans', 'ip_hash', $mark ), 'left=' . $count( 'scans', 'ip_hash', $mark ) );
$t( 'scans just inside the period (23 months) kept', 2 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}scans WHERE ip_hash = %s AND scanned_at < %s", $mark, $ago( 12 ) ) ) );
$t( 'comm log: 4 old deleted, 1 kept', 1 === $count( 'comm_log', 'ref', $mark ) );
$t( 'audit: >84 months deleted, 60-month + recent kept', 3 === $count( 'audit', 'actor', $mark ) );
$t( 'deletion requests: OPEN never deleted, old done deleted, recent kept', 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}deletion_requests WHERE reason = %s AND status = 'open'", $mark ) ) && 1 === (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}deletion_requests WHERE reason = %s AND status = 'done'", $mark ) ) );
$t( 'result counts reported', $r['scans'] >= 1200 && $r['comm'] >= 4 && $r['audit'] >= 2 && $r['deletions'] >= 1, wp_json_encode( $r ) );
$audit_after = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}audit WHERE summary LIKE '%ניקוי לפי מדיניות שמירה%'" );
$t( 'cleanup recorded in the audit log', $audit_after === $audit_before + 1 );
$t( 'last run stored', is_array( get_option( GSM_Retention::LAST ) ) );

// ---- 0 = keep forever ----------------------------------------------------
GSM_Retention::save( array( 'scans' => 0 ) );
$wpdb->insert( $p . 'scans', array( 'equipment_id' => 1, 'scanned_at' => $ago( 120 ), 'user_id' => 0, 'ip_hash' => $mark ) );
GSM_Retention::run( 'manual' );
$t( '0 months = never deleted', 6 === $count( 'scans', 'ip_hash', $mark ) );

// ---- Input clamping ------------------------------------------------------
$c = GSM_Retention::save( array( 'scans' => -5, 'comm' => 'abc', 'audit' => 99999, 'bogus' => 3 ) );
$t( 'save clamps values and ignores unknown keys', 0 === $c['scans'] && 0 === $c['comm'] && 1200 === $c['audit'] && ! isset( $c['bogus'] ), wp_json_encode( $c ) );

// ---- REST permissions ----------------------------------------------------
wp_set_current_user( 0 );
$res = rest_do_request( new WP_REST_Request( 'GET', '/gas/v1/retention' ) );
$t( 'GET /retention anonymous -> 401/403', in_array( $res->get_status(), array( 401, 403 ), true ), (string) $res->get_status() );
$res = rest_do_request( new WP_REST_Request( 'POST', '/gas/v1/retention/run' ) );
$t( 'POST /retention/run anonymous -> 401/403', in_array( $res->get_status(), array( 401, 403 ), true ), (string) $res->get_status() );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
wp_set_current_user( (int) $admins[0] );
$res = rest_do_request( new WP_REST_Request( 'GET', '/gas/v1/retention' ) );
$d   = $res->get_data();
$t( 'GET /retention as admin -> 200 with config/preview/labels', 200 === $res->get_status() && isset( $d['config'], $d['preview'], $d['labels'], $d['active_from'] ) );
$t( 'cron event scheduled', (bool) wp_next_scheduled( GSM_Retention::CRON ) );

// ---- Restore ---------------------------------------------------------------
$cleanup();
null === $saved_cfg ? delete_option( GSM_Retention::OPTION ) : update_option( GSM_Retention::OPTION, $saved_cfg, false );
null === $saved_armed ? delete_option( GSM_Retention::ARMED ) : update_option( GSM_Retention::ARMED, $saved_armed, false );
null === $saved_last ? delete_option( GSM_Retention::LAST ) : update_option( GSM_Retention::LAST, $saved_last, false );
$wpdb->query( "DELETE FROM {$p}audit WHERE summary LIKE '%ניקוי לפי מדיניות שמירה%' OR summary LIKE '%עודכנה מדיניות שמירה%'" );

echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
