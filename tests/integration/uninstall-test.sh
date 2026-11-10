#!/usr/bin/env bash
# PHP snippets below are single-quoted on purpose (no shell expansion).
# shellcheck disable=SC2016
# Uninstall leaves no plugin data behind (tables, files, options, user meta,
# roles, cron). DESTRUCTIVE for the target site — use a throwaway test site.
# Usage: tests/integration/uninstall-test.sh <wp-path> <wp-cli.phar>
set -euo pipefail
SITE="$1"
WP="php $2 --path=$SITE --allow-root"

$WP plugin activate gas-safety-manager --quiet || true

# Seed data in every place the plugin writes to.
$WP eval '
global $wpdb;
$up  = wp_upload_dir();
$dir = trailingslashit( $up["basedir"] ) . "gas-private/2026/11";
wp_mkdir_p( $dir );
file_put_contents( $dir . "/test.png", "x" );
$wpdb->insert( $wpdb->prefix . "gas_audit", array( "created_at" => current_time( "mysql" ), "actor" => "uninstall-test", "actor_id" => 0, "action" => "update", "entity" => "system", "entity_id" => 0, "item_name" => "", "summary" => "seed", "undone" => 0 ) );
$wpdb->insert( $wpdb->prefix . "gas_restore_points", array( "created_at" => current_time( "mysql" ), "created_by" => "t", "label" => "t", "kind" => "manual", "counts_json" => "{}", "payload" => "{}" ) );
update_user_meta( 1, "gsm_phone", "050-0000000" );
update_user_meta( 1, "gsm_avatar", "x" );
update_option( "gsm_bi_token", "seed" );
set_transient( "gsm_rl_uninstall_test", 3, 600 );
GSM_Retention::sync_cron();
echo "seeded\n";
'

$WP plugin uninstall gas-safety-manager --deactivate --skip-delete --quiet

$WP eval '
global $wpdb;
$fail = 0;
$t = function ( $n, $ok, $x = "" ) use ( &$fail ) { echo ( $ok ? "PASS " : "FAIL " ) . $n . ( $ok ? "" : " — " . $x ) . "\n"; if ( ! $ok ) { $fail++; } };
$left = array();
foreach ( array( "equipment","inspections","inspection_items","faults","scans","checklist_templates","audit","deletion_requests","qr_issuance","restore_points","attachments","comm_log" ) as $s ) {
	$name = $wpdb->prefix . "gas_" . $s;
	$wpdb->suppress_errors( true );
	$r = $wpdb->get_var( "SELECT COUNT(*) FROM `{$name}`" );
	$wpdb->suppress_errors( false );
	if ( null !== $r ) { $left[] = $s; }
}
$t( "all 12 tables dropped", empty( $left ), implode( ",", $left ) );
$up = wp_upload_dir();
$t( "private attachment folder removed", ! file_exists( trailingslashit( $up["basedir"] ) . "gas-private" ) );
$opts = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", "gsm\\_%", "\\_transient\\_gsm\\_%" ) );
$t( "no gsm_* options or transients", 0 === $opts, (string) $opts );
$um = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", "gsm\\_%" ) );
$t( "no gsm_* user meta", 0 === $um, (string) $um );
$roles = array_intersect( array( "gsm_manager", "gsm_ahmat", "gsm_lab_manager", "gsm_pi" ), array_keys( wp_roles()->roles ) );
$t( "plugin roles removed", empty( $roles ), implode( ",", $roles ) );
$caps = array();
foreach ( wp_roles()->role_objects as $r ) { foreach ( array( "gsm_manage_all","gsm_edit_equipment","gsm_report","gsm_inspect_quarterly","gsm_inspect_monthly" ) as $c ) { if ( $r->has_cap( $c ) ) { $caps[] = $r->name . ":" . $c; } } }
$t( "plugin capabilities removed from all roles", empty( $caps ), implode( ",", $caps ) );
$t( "cron events cleared", ! wp_next_scheduled( "gsm_daily_retention" ) && ! wp_next_scheduled( "gsm_daily_reminders" ) );
echo ( $fail ? $fail . " failed" : "all passed" ) . "\n";
exit( $fail ? 1 : 0 );
'
