<?php
/**
 * Central hook loader.
 *
 * In stage 0 this only wires up a minimal admin notice confirming the plugin
 * is installed and whether the feature flag is on. Later stages register
 * REST controllers, admin pages, and public assets here.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Loader
 */
class GSM_Loader {

	/**
	 * Register all hooks for the current request.
	 */
	public function run() {
		// Admin-only wiring.
		if ( is_admin() ) {
			add_action( 'admin_notices', array( $this, 'admin_status_notice' ) );
			$migration_admin = new GSM_Admin_Migration();
			$migration_admin->init();
		}

		// Public shortcode UI ([gas_app]). Works for logged-in staff; the
		// shortcode itself checks login + the REST layer checks capabilities.
		$public = new GSM_Public();
		$public->init();

		// Public bridge page ([gas_bridge]) for QR scans — no login needed.
		$bridge = new GSM_Bridge();
		$bridge->init();

		// REST API — registered always so staff can use it even while the
		// public-facing feature flag is OFF. Every route is still gated by a
		// capability check in its permission_callback, so nothing is exposed
		// to unauthorized users.
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );

		// Public + REST runtime that DOES change what end-users see is gated
		// behind the feature flag.
		if ( GSM_FEATURE_ENABLED ) {
			do_action( 'gsm_register_runtime' );
		}
	}

	/**
	 * Instantiate and register REST controllers.
	 */
	public function register_rest() {
		$equipment = new GSM_REST_Equipment();
		$equipment->register_routes();

		$faults = new GSM_REST_Faults();
		$faults->register_routes();

		$inspections = new GSM_REST_Inspections();
		$inspections->register_routes();

		$dashboard = new GSM_REST_Dashboard();
		$dashboard->register_routes();

		$qr = new GSM_REST_QR();
		$qr->register_routes();

		$auth = new GSM_REST_Auth();
		$auth->register_routes();

		$settings = new GSM_REST_Settings();
		$settings->register_routes();

		$migration = new GSM_REST_Migration();
		$migration->register_routes();

		$export = new GSM_REST_Export();
		$export->register_routes();

		$inspection_config = new GSM_REST_Inspection_Config();
		$inspection_config->register_routes();

		$users = new GSM_REST_Users();
		$users->register_routes();

		$audit = new GSM_REST_Audit();
		$audit->register_routes();

		$tools = new GSM_REST_Tools();
		$tools->register_routes();

		$bi = new GSM_REST_BI();
		$bi->register_routes();

		$integrations = new GSM_REST_Integrations();
		$integrations->register_routes();

		$qr_issuance = new GSM_REST_QR_Issuance();
		$qr_issuance->register_routes();

		$db = new GSM_REST_DB();
		$db->register_routes();

		$import = new GSM_REST_Import();
		$import->register_routes();

		$restore = new GSM_REST_Restore();
		$restore->register_routes();

		$attachments = new GSM_REST_Attachments();
		$attachments->register_routes();

		$comm = new GSM_REST_Comm();
		$comm->register_routes();

		$retention = new GSM_REST_Retention();
		$retention->register_routes();

		$chat = new GSM_REST_Chat();
		$chat->register_routes();

		$privacy = new GSM_REST_Privacy();
		$privacy->register_routes();
		$legacy = new GSM_REST_Legacy();
		$legacy->register_routes();
		$messages = new GSM_REST_Messages();
		$messages->register_routes();
		$sso = new GSM_REST_SSO();
		$sso->register_routes();
		$captcha = new GSM_REST_Captcha();
		$captcha->register_routes();
		$twofa = new GSM_REST_2FA();
		$twofa->register_routes();
	}

	/**
	 * Admin status notice. Stays silent in normal operation; only speaks up
	 * when something needs attention (a table failed to create). This replaces
	 * the build-time scaffolding notice.
	 */
	public function admin_status_notice() {
		// Only to users who can manage the plugin, and not on every screen —
		// just the Plugins and Dashboard pages to avoid noise.
		if ( ! current_user_can( 'gsm_manage_all' ) ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$id     = $screen ? $screen->id : '';
		if ( 'plugins' !== $id && 'dashboard' !== $id ) {
			return;
		}

		global $wpdb;
		$p      = $wpdb->prefix . 'gas_';
		$tables = array( 'equipment', 'inspections', 'inspection_items', 'faults', 'scans', 'checklist_templates' );
		$found  = 0;
		foreach ( $tables as $t ) {
			$name = $p . $t;
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $name ) );
			if ( $exists === $name ) {
				$found++;
			}
		}
		$total = count( $tables );

		// All good → quiet success line only on the Plugins screen.
		if ( $found === $total ) {
			if ( 'plugins' === $id ) {
				$app_id = (int) get_option( 'gsm_app_page_id', 0 );
				$url    = $app_id ? get_permalink( $app_id ) : '';
				echo '<div class="notice notice-success is-dismissible"><p><strong>Gas Safety Manager</strong> — ';
				printf(
					/* translators: %s: plugin version */
					esc_html__( 'v%s פעיל. המערכת מחליפה את Monday במלואה.', 'gas-safety-manager' ),
					esc_html( GSM_VERSION )
				);
				if ( $url ) {
					echo ' <a href="' . esc_url( $url ) . '">' . esc_html__( 'פתח את המערכת', 'gas-safety-manager' ) . '</a>';
				}
				echo '</p></div>';
			}
			return;
		}

		// Something is missing → warn.
		echo '<div class="notice notice-error"><p><strong>Gas Safety Manager</strong> — ';
		printf(
			/* translators: 1: tables found, 2: total tables */
			esc_html__( 'אזהרה: רק %1$d מתוך %2$d טבלאות קיימות. נסו להשבית ולהפעיל מחדש את התוסף.', 'gas-safety-manager' ),
			(int) $found,
			(int) $total
		);
		echo '</p></div>';
	}
}
