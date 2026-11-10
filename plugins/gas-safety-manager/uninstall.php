<?php
/**
 * Uninstall — runs only when the user deletes the plugin from WordPress.
 *
 * This is the ONLY place that drops tables. It is guarded so it never runs
 * on a simple deactivation. Because this destroys data, it also checks for
 * an explicit opt-in option; if the site owner wants to keep data after
 * delete, they set gsm_keep_data_on_uninstall = true.
 *
 * @package GasSafetyManager
 */

// If uninstall not called from WordPress, exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

// Respect a "keep data" opt-in.
if ( get_option( 'gsm_keep_data_on_uninstall', false ) ) {
	return;
}

global $wpdb;
$p = $wpdb->prefix . 'gas_';

$tables = array(
	'equipment',
	'inspections',
	'inspection_items',
	'faults',
	'scans',
	'checklist_templates',
);

foreach ( $tables as $t ) {
	$name = $p . $t;
	// Table names cannot be parameterized; they are built from a trusted prefix.
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
}

// Remove options.
delete_option( 'gsm_db_version' );
delete_option( 'gsm_feature_enabled' );
delete_option( 'gsm_settings' );
delete_option( 'gsm_keep_data_on_uninstall' );
delete_option( 'gsm_bi_token' );
delete_option( 'gsm_public_token' );

// Remove custom roles.
remove_role( 'gsm_lab_manager' );
remove_role( 'gsm_pi' );

// Strip custom caps from administrator.
$admin = get_role( 'administrator' );
if ( $admin ) {
	$admin->remove_cap( 'gsm_manage_all' );
	$admin->remove_cap( 'gsm_edit_equipment' );
	$admin->remove_cap( 'gsm_report' );
}
