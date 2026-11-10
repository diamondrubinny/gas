<?php
/**
 * Fired during plugin activation.
 *
 * Creates all custom tables and registers roles/capabilities.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Activator
 */
class GSM_Activator {

	/**
	 * Run on activation.
	 */
	public static function activate() {
		self::create_tables();
		self::register_roles();
		self::backfill_asset_codes();
		self::seed_inspection_config();
		self::create_pages();
		update_option( 'gsm_db_version', GSM_DB_VERSION );
		// This build fully replaces the Monday backend, so the runtime is ON
		// by default. (The option still exists so it can be toggled.)
		if ( false === get_option( 'gsm_feature_enabled', false ) ) {
			update_option( 'gsm_feature_enabled', true );
		}
	}

	/**
	 * Create the front-end pages (management app + public bridge) if they
	 * don't exist yet. Stores their IDs so we don't duplicate on re-activate.
	 */
	public static function create_pages() {
		// Safety: wp_insert_post must be available. On very early hooks it may
		// not be — bail quietly and let the 'init' hook retry.
		if ( ! function_exists( 'wp_insert_post' ) ) {
			return;
		}

		// Management app page.
		$app_id = (int) get_option( 'gsm_app_page_id', 0 );
		if ( ! $app_id || ! get_post( $app_id ) ) {
			$app_id = wp_insert_post(
				array(
					'post_title'   => 'מערכת גזים',
					'post_name'    => 'gas-app',
					'post_content' => '[gas_app]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			if ( $app_id && ! is_wp_error( $app_id ) ) {
				update_option( 'gsm_app_page_id', (int) $app_id );
			}
		}

		// Public bridge page (QR landing).
		$bridge_id = (int) get_option( 'gsm_bridge_page_id', 0 );
		if ( ! $bridge_id || ! get_post( $bridge_id ) ) {
			$bridge_id = wp_insert_post(
				array(
					'post_title'   => 'פרטי ציוד',
					'post_name'    => 'bridge',
					'post_content' => '[gas_bridge]',
					'post_status'  => 'publish',
					'post_type'    => 'page',
				)
			);
			if ( $bridge_id && ! is_wp_error( $bridge_id ) ) {
				update_option( 'gsm_bridge_page_id', (int) $bridge_id );
			}
		}
	}

	/**
	 * Check stored DB version and upgrade tables if needed.
	 * Called on every load (cheap: only runs dbDelta when version differs).
	 */
	public static function maybe_upgrade() {
		$installed = get_option( 'gsm_db_version', '0' );
		if ( (string) $installed !== (string) GSM_DB_VERSION ) {
			self::create_tables();
			self::register_roles();
			self::backfill_asset_codes();
			self::seed_inspection_config();
			update_option( 'gsm_db_version', GSM_DB_VERSION );
		}
		// NOTE: page creation is NOT called here — plugins_loaded is too early
		// for wp_insert_post. It runs on activation and on the 'init' hook
		// (see gsm_ensure_pages() in the main file).
	}

	/**
	 * One-time data fix after the asset_code column is added (DB v2).
	 *
	 * Migrated equipment already carries its original Monday item number in
	 * monday_id — that IS the number printed on the physical label today, so
	 * we copy it into asset_code (the human-facing number the system now
	 * shows and prints). New equipment created from scratch gets a fresh
	 * G-#### code at insert time (see GSM_Equipment_Service). This runs only
	 * when the DB version changes, so it is effectively idempotent.
	 *
	 * @return void
	 */
	public static function backfill_asset_codes() {
		global $wpdb;
		$table = $wpdb->prefix . 'gas_equipment';

		// Column may not exist yet on a brand-new install path; guard it.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + a hard-coded suffix, never user input.
		$has_col = $wpdb->get_results( "SHOW COLUMNS FROM {$table} LIKE 'asset_code'" );
		if ( empty( $has_col ) ) {
			return;
		}

		// Copy the preserved Monday number into any empty asset_code.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + a hard-coded suffix.
		$wpdb->query(
			"UPDATE {$table}
			 SET asset_code = monday_id
			 WHERE ( asset_code IS NULL OR asset_code = '' )
			   AND monday_id IS NOT NULL AND monday_id <> ''"
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Seed the default inspection configuration (checklists + rules) if it
	 * doesn't exist yet. Checklists start empty — the manager fills them in
	 * the "הגדרות ביקורת" screen (we never invent safety checklist content).
	 *
	 * @return void
	 */
	public static function seed_inspection_config() {
		if ( false !== get_option( 'gsm_inspection_config', false ) ) {
			return;
		}
		add_option(
			'gsm_inspection_config',
			array(
				'rules'      => array(
					'monthlyEveryDays'   => 30,
					'quarterlyEveryDays' => 90,
					'reminderBeforeDays' => 7,
					'notifyPI'           => true,
					'notifyAhmat'        => true,
					'notifyLabManager'   => true,
					'notifyOnComplete'   => false,
					'extraEmails'        => array(),
				),
				'checklists' => array(
					'quarterly' => array(),
					'monthly'   => array(),
				),
			)
		);
	}

	/**
	 * Create all custom tables using dbDelta.
	 */
	public static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$p               = $wpdb->prefix . 'gas_';

		// -------------------------------------------------------------------
		// Equipment
		// -------------------------------------------------------------------
		$sql_equipment = "CREATE TABLE {$p}equipment (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(255) NOT NULL DEFAULT '',
			gas_type VARCHAR(100) NOT NULL DEFAULT '',
			category VARCHAR(100) NOT NULL DEFAULT '',
			faculty VARCHAR(150) NOT NULL DEFAULT '',
			building VARCHAR(150) NOT NULL DEFAULT '',
			floor VARCHAR(50) NOT NULL DEFAULT '',
			room VARCHAR(100) NOT NULL DEFAULT '',
			location VARCHAR(255) NOT NULL DEFAULT '',
			functional_loc VARCHAR(255) NOT NULL DEFAULT '',
			gis VARCHAR(255) NOT NULL DEFAULT '',
			panel_number VARCHAR(50) NOT NULL DEFAULT '',
			work_pressure VARCHAR(50) NOT NULL DEFAULT '',
			cylinders_count VARCHAR(50) NOT NULL DEFAULT '',
			cylinders_stock VARCHAR(50) NOT NULL DEFAULT '',
			phone VARCHAR(50) NOT NULL DEFAULT '',
			contacts TEXT NULL,
			pi_name VARCHAR(255) NOT NULL DEFAULT '',
			lab_managers TEXT NULL,
			maint_staff TEXT NULL,
			maint_emails TEXT NULL,
			status VARCHAR(50) NOT NULL DEFAULT '',
			notes TEXT NULL,
			last_quarterly DATE NULL,
			next_quarterly DATE NULL,
			monday_id VARCHAR(50) NOT NULL DEFAULT '',
			asset_code VARCHAR(50) NOT NULL DEFAULT '',
			qr_token VARCHAR(64) NOT NULL DEFAULT '',
			image_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY building (building),
			KEY status (status),
			KEY next_quarterly (next_quarterly),
			UNIQUE KEY qr_token (qr_token),
			KEY monday_id (monday_id),
			KEY asset_code (asset_code)
		) {$charset_collate};";

		// -------------------------------------------------------------------
		// Inspections
		// -------------------------------------------------------------------
		$sql_inspections = "CREATE TABLE {$p}inspections (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			equipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			type VARCHAR(20) NOT NULL DEFAULT 'adhoc',
			inspection_date DATE NULL,
			inspector VARCHAR(255) NOT NULL DEFAULT '',
			inspector_email VARCHAR(255) NOT NULL DEFAULT '',
			pi_name VARCHAR(255) NOT NULL DEFAULT '',
			panel_number VARCHAR(50) NOT NULL DEFAULT '',
			result VARCHAR(20) NOT NULL DEFAULT 'ok',
			notes TEXT NULL,
			monday_id VARCHAR(50) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY equipment_id (equipment_id),
			KEY type (type),
			KEY inspection_date (inspection_date)
		) {$charset_collate};";

		// -------------------------------------------------------------------
		// Inspection items (checklist rows)
		// -------------------------------------------------------------------
		$sql_items = "CREATE TABLE {$p}inspection_items (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			inspection_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			item_index INT NOT NULL DEFAULT 0,
			item_text TEXT NULL,
			item_result VARCHAR(20) NOT NULL DEFAULT 'ok',
			PRIMARY KEY  (id),
			KEY inspection_id (inspection_id)
		) {$charset_collate};";

		// -------------------------------------------------------------------
		// Faults
		// -------------------------------------------------------------------
		$sql_faults = "CREATE TABLE {$p}faults (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			equipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			reporter VARCHAR(255) NOT NULL DEFAULT '',
			description TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			priority VARCHAR(20) NOT NULL DEFAULT 'normal',
			opened_date DATE NULL,
			resolved_date DATE NULL,
			monday_id VARCHAR(50) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY equipment_id (equipment_id),
			KEY status (status),
			KEY priority (priority)
		) {$charset_collate};";

		// -------------------------------------------------------------------
		// Scans (QR scan log)
		// -------------------------------------------------------------------
		$sql_scans = "CREATE TABLE {$p}scans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			equipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			scanned_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			user_id BIGINT UNSIGNED NULL,
			ip_hash VARCHAR(64) NOT NULL DEFAULT '',
			PRIMARY KEY  (id),
			KEY equipment_id (equipment_id),
			KEY scanned_at (scanned_at)
		) {$charset_collate};";

		// -------------------------------------------------------------------
		// Checklist templates
		// -------------------------------------------------------------------
		$sql_templates = "CREATE TABLE {$p}checklist_templates (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			type VARCHAR(20) NOT NULL DEFAULT 'quarterly',
			item_index INT NOT NULL DEFAULT 0,
			item_text TEXT NULL,
			active TINYINT(1) NOT NULL DEFAULT 1,
			PRIMARY KEY  (id),
			KEY type (type)
		) {$charset_collate};";

		// -------------------------------------------------------------------
		// Audit log (who did what, when)
		// -------------------------------------------------------------------
		$sql_audit = "CREATE TABLE {$p}audit (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			actor VARCHAR(255) NOT NULL DEFAULT '',
			actor_id BIGINT UNSIGNED NULL,
			action VARCHAR(30) NOT NULL DEFAULT '',
			entity VARCHAR(30) NOT NULL DEFAULT '',
			entity_id BIGINT UNSIGNED NULL,
			item_name VARCHAR(255) NOT NULL DEFAULT '',
			summary TEXT NULL,
			before_json LONGTEXT NULL,
			undone TINYINT(1) NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			KEY created_at (created_at),
			KEY entity (entity),
			KEY action (action)
		) {$charset_collate};";

		// -------------------------------------------------------------------
		// Profile deletion requests (Privacy Law Amendment 13)
		// -------------------------------------------------------------------
		$sql_deletions = "CREATE TABLE {$p}deletion_requests (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			user_id BIGINT UNSIGNED NULL,
			email VARCHAR(255) NOT NULL DEFAULT '',
			reason TEXT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'open',
			PRIMARY KEY  (id),
			KEY status (status)
		) {$charset_collate};";

		// -------------------------------------------------------------------
		// QR issuance tracking (which equipment got a QR label, and its stage)
		// -------------------------------------------------------------------
		$sql_qr_issuance = "CREATE TABLE {$p}qr_issuance (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			equipment_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			equipment_name VARCHAR(255) NOT NULL DEFAULT '',
			pi_name VARCHAR(255) NOT NULL DEFAULT '',
			qr_number VARCHAR(50) NOT NULL DEFAULT '',
			issued_date DATE NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'issued',
			note TEXT NULL,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			updated_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY equipment_id (equipment_id),
			KEY status (status)
		) {$charset_collate};";

		$sql_restore = "CREATE TABLE {$p}restore_points (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			created_by VARCHAR(255) NOT NULL DEFAULT '',
			label VARCHAR(255) NOT NULL DEFAULT '',
			kind VARCHAR(20) NOT NULL DEFAULT 'manual',
			counts_json TEXT NULL,
			payload LONGTEXT NULL,
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		// dbDelta each table separately (it is picky about formatting).
		dbDelta( $sql_equipment );
		dbDelta( $sql_inspections );
		dbDelta( $sql_items );
		dbDelta( $sql_faults );
		dbDelta( $sql_scans );
		dbDelta( $sql_templates );
		dbDelta( $sql_audit );
		dbDelta( $sql_deletions );
		dbDelta( $sql_qr_issuance );
		$sql_attach = "CREATE TABLE {$p}attachments (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			entity VARCHAR(20) NOT NULL DEFAULT '',
			entity_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			orig_name VARCHAR(255) NOT NULL DEFAULT '',
			stored_name VARCHAR(255) NOT NULL DEFAULT '',
			rel_path VARCHAR(255) NOT NULL DEFAULT '',
			mime VARCHAR(100) NOT NULL DEFAULT '',
			size BIGINT UNSIGNED NOT NULL DEFAULT 0,
			sha256 CHAR(64) NOT NULL DEFAULT '',
			uploaded_by VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT 'clean',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY entity (entity, entity_id)
		) {$charset_collate};";

		$sql_comm = "CREATE TABLE {$p}comm_log (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			kind VARCHAR(20) NOT NULL DEFAULT '',
			channel VARCHAR(20) NOT NULL DEFAULT '',
			recipient VARCHAR(255) NOT NULL DEFAULT '',
			subject VARCHAR(255) NOT NULL DEFAULT '',
			status VARCHAR(20) NOT NULL DEFAULT '',
			ref VARCHAR(255) NOT NULL DEFAULT '',
			created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
			PRIMARY KEY  (id),
			KEY created_at (created_at)
		) {$charset_collate};";

		dbDelta( $sql_restore );
		dbDelta( $sql_attach );
		dbDelta( $sql_comm );
	}

	/**
	 * Register the three custom roles and capabilities.
	 *
	 * Capabilities:
	 *   gsm_manage_all      - full control (admin)
	 *   gsm_edit_equipment  - edit equipment/inspections/faults (lab manager)
	 *   gsm_report          - view + report (PI)
	 */
	public static function register_roles() {
		// Give all capabilities to the WP administrator (incl. both inspection
		// types — a manager may perform either inspection).
		$admin = get_role( 'administrator' );
		if ( $admin ) {
			$admin->add_cap( 'gsm_manage_all' );
			$admin->add_cap( 'gsm_edit_equipment' );
			$admin->add_cap( 'gsm_report' );
			$admin->add_cap( 'gsm_inspect_quarterly' );
			$admin->add_cap( 'gsm_inspect_monthly' );
		}

		// Gas-system manager — full control of the gas system WITHOUT being a
		// WordPress administrator (so staff managers aren't site admins).
		add_role(
			'gsm_manager',
			__( 'Gas — Manager', 'gas-safety-manager' ),
			array(
				'read'                  => true,
				'gsm_manage_all'        => true,
				'gsm_edit_equipment'    => true,
				'gsm_report'            => true,
				'gsm_inspect_quarterly' => true,
				'gsm_inspect_monthly'   => true,
			)
		);
		$mgr = get_role( 'gsm_manager' );
		if ( $mgr ) {
			$mgr->add_cap( 'gsm_manage_all' );
			$mgr->add_cap( 'gsm_edit_equipment' );
			$mgr->add_cap( 'gsm_report' );
			$mgr->add_cap( 'gsm_inspect_quarterly' );
			$mgr->add_cap( 'gsm_inspect_monthly' );
		}

		// אחמ"ת — safety maintenance staff: performs the QUARTERLY inspection
		// (the long checklist). View + report + quarterly inspections.
		add_role(
			'gsm_ahmat',
			__( 'Gas — אחמ"ת (Quarterly)', 'gas-safety-manager' ),
			array(
				'read'                  => true,
				'gsm_report'            => true,
				'gsm_inspect_quarterly' => true,
			)
		);

		// Lab manager: edit equipment + report + MONTHLY inspection (short list).
		add_role(
			'gsm_lab_manager',
			__( 'Gas — Lab Manager (Monthly)', 'gas-safety-manager' ),
			array(
				'read'                => true,
				'gsm_edit_equipment'  => true,
				'gsm_report'          => true,
				'gsm_inspect_monthly' => true,
			)
		);

		// PI (Principal Investigator): view + report + MONTHLY inspection.
		add_role(
			'gsm_pi',
			__( 'Gas — Principal Investigator (Monthly)', 'gas-safety-manager' ),
			array(
				'read'                => true,
				'gsm_report'          => true,
				'gsm_inspect_monthly' => true,
			)
		);

		// Existing installs: ensure the inspection caps land on roles that were
		// created before this version (add_role is a no-op if the role exists).
		$lab = get_role( 'gsm_lab_manager' );
		if ( $lab ) {
			$lab->add_cap( 'gsm_inspect_monthly' );
		}
		$pi = get_role( 'gsm_pi' );
		if ( $pi ) {
			$pi->add_cap( 'gsm_inspect_monthly' );
		}
	}
}
