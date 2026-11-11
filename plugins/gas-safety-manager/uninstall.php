<?php
/**
 * Uninstall — runs only when the user deletes the plugin from WordPress.
 *
 * This is the ONLY place that drops tables. It is guarded so it never runs
 * on a simple deactivation. Because this destroys data, it also checks for
 * an explicit opt-in option; if the site owner wants to keep data after
 * delete, they set gsm_keep_data_on_uninstall = true.
 *
 * Removes everything the plugin stored (privacy: deleting the plugin must not
 * leave personal data behind): all gas_* tables, private attachment files,
 * gsm_* options and transients, gsm_* user meta, cron events, roles and caps.
 * Pages created for the app ([gas_app] / [gas_bridge]) are site content and
 * are left in place.
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

// 1. Tables — every table the activator creates.
$tables = array(
	'equipment',
	'inspections',
	'inspection_items',
	'faults',
	'scans',
	'checklist_templates',
	'audit',
	'deletion_requests',
	'qr_issuance',
	'restore_points',
	'attachments',
	'comm_log',
);
foreach ( $tables as $t ) {
	$name = $p . $t;
	// Table names cannot be parameterized; they are built from a trusted prefix.
	// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	$wpdb->query( "DROP TABLE IF EXISTS `{$name}`" );
}

// 2. Private attachment files (uploads/gas-private), guarded to that folder.
$gsm_up   = wp_upload_dir( null, false );
$gsm_base = isset( $gsm_up['basedir'] ) ? realpath( trailingslashit( $gsm_up['basedir'] ) . 'gas-private' ) : false;
$gsm_root = isset( $gsm_up['basedir'] ) ? realpath( $gsm_up['basedir'] ) : false;
if ( $gsm_base && $gsm_root && 0 === strpos( $gsm_base, trailingslashit( $gsm_root ) ) && is_dir( $gsm_base ) ) {
	$gsm_it = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $gsm_base, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $gsm_it as $gsm_f ) {
		if ( $gsm_f->isDir() && ! $gsm_f->isLink() ) {
			@rmdir( $gsm_f->getPathname() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		} else {
			wp_delete_file( $gsm_f->getPathname() );
		}
	}
	@rmdir( $gsm_base ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
}

// 3. Options and transients with the plugin prefix (incl. rate-limit counters).
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s",
		$wpdb->esc_like( 'gsm_' ) . '%',
		$wpdb->esc_like( '_transient_gsm_' ) . '%',
		$wpdb->esc_like( '_transient_timeout_gsm_' ) . '%'
	)
);

// 4. User meta written by the plugin (phone, avatar, last login, active flag).
$wpdb->query(
	$wpdb->prepare(
		"DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s",
		$wpdb->esc_like( 'gsm_' ) . '%'
	)
);
// phpcs:enable
wp_cache_flush();

// 5. Scheduled events.
wp_clear_scheduled_hook( 'gsm_daily_reminders' );
wp_clear_scheduled_hook( 'gsm_daily_retention' );
wp_clear_scheduled_hook( 'gsm_legacy_sync' );

// 6. Roles and capabilities.
foreach ( array( 'gsm_manager', 'gsm_ahmat', 'gsm_lab_manager', 'gsm_pi' ) as $gsm_role ) {
	remove_role( $gsm_role );
}
$gsm_caps = array( 'gsm_manage_all', 'gsm_edit_equipment', 'gsm_report', 'gsm_inspect_quarterly', 'gsm_inspect_monthly' );
foreach ( wp_roles()->role_objects as $gsm_r ) {
	foreach ( $gsm_caps as $gsm_cap ) {
		if ( $gsm_r->has_cap( $gsm_cap ) ) {
			$gsm_r->remove_cap( $gsm_cap );
		}
	}
}
