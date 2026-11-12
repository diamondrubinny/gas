<?php
/**
 * Plugin Name:       Gas Safety Manager
 * Plugin URI:        https://safety-tech.dev
 * Description:       Gas systems maintenance management for the Technion Safety Unit. Manages equipment, inspections, faults, and QR codes. Full replacement for the Monday.com backend with native WordPress storage, including a batch QR sticker generator.
 * Version:           2.23.0
 * Requires at least: 6.4
 * Requires PHP:      8.2
 * Author:            Diamond Rubinny
 * Text Domain:       gas-safety-manager
 * Domain Path:       /languages
 *
 * @package GasSafetyManager
 */

// Block direct access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'GSM_VERSION', '2.23.0' );
define( 'GSM_DB_VERSION', '10' );
define( 'GSM_PLUGIN_FILE', __FILE__ );
define( 'GSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'GSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'GSM_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

/**
 * Feature flag: while OFF, the plugin creates its tables and admin scaffolding
 * but does NOT expose public endpoints or replace anything user-facing.
 * This lets us build safely on a live site. Flip via the option below.
 */
define( 'GSM_FEATURE_ENABLED', get_option( 'gsm_feature_enabled', false ) );

// ---------------------------------------------------------------------------
// Includes
// ---------------------------------------------------------------------------
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-activator.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-deactivator.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-loader.php';

// Audit logger + outgoing integrations (used by the service layer).
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-audit.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-integrations.php';

// Data Access layer.
require_once GSM_PLUGIN_DIR . 'includes/db/class-gsm-equipment-repo.php';
require_once GSM_PLUGIN_DIR . 'includes/db/class-gsm-faults-repo.php';
require_once GSM_PLUGIN_DIR . 'includes/db/class-gsm-inspections-repo.php';
require_once GSM_PLUGIN_DIR . 'includes/db/class-gsm-qr-issuance-repo.php';

// Service layer (business logic).
require_once GSM_PLUGIN_DIR . 'includes/services/class-gsm-equipment-service.php';
require_once GSM_PLUGIN_DIR . 'includes/services/class-gsm-faults-service.php';
require_once GSM_PLUGIN_DIR . 'includes/services/class-gsm-inspections-service.php';
require_once GSM_PLUGIN_DIR . 'includes/services/class-gsm-qr-issuance-service.php';
require_once GSM_PLUGIN_DIR . 'includes/services/class-gsm-restore-service.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-bulk.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-rate-limit.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-security.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-comm.php';
require_once GSM_PLUGIN_DIR . 'includes/db/class-gsm-attachments-repo.php';
require_once GSM_PLUGIN_DIR . 'includes/services/class-gsm-attachments-service.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-attachments.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-comm.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-retention.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-retention.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-chat.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-chat.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-privacy-requests.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-privacy.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-captcha.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-captcha.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-users-import.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-messages.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-messages.php';
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-legacy.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-legacy.php';

// Auth helpers.
require_once GSM_PLUGIN_DIR . 'includes/auth/class-gsm-auth.php';
require_once GSM_PLUGIN_DIR . 'includes/auth/class-gsm-2fa.php';
require_once GSM_PLUGIN_DIR . 'includes/auth/class-gsm-passkeys.php';
require_once GSM_PLUGIN_DIR . 'includes/auth/class-gsm-sso.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-sso.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-2fa.php';

// REST controllers.
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-equipment.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-faults-inspections.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-dashboard.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-qr.php';
	require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-auth.php';
	require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-settings.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-migration.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-export.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-inspection-config.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-users.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-audit.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-tools.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-bi.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-integrations.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-qr-issuance.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-db.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-import.php';
require_once GSM_PLUGIN_DIR . 'includes/rest/class-gsm-rest-restore.php';

// Offline importer (JSON file upload path).
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-importer.php';

// XLSX writer (Excel export).
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-xlsx.php';

// Spreadsheet reader (CSV/XLSX import).
require_once GSM_PLUGIN_DIR . 'includes/class-gsm-spreadsheet.php';

// Admin.
require_once GSM_PLUGIN_DIR . 'admin/class-gsm-admin-migration.php';

// Public (shortcode UI).
require_once GSM_PLUGIN_DIR . 'public/class-gsm-public.php';
require_once GSM_PLUGIN_DIR . 'public/class-gsm-bridge.php';

// ---------------------------------------------------------------------------
// Activation / Deactivation
// ---------------------------------------------------------------------------
register_activation_hook( __FILE__, array( 'GSM_Activator', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'GSM_Deactivator', 'deactivate' ) );

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
/**
 * Start the plugin on plugins_loaded so all WordPress APIs are available.
 */
function gsm_run() {
	// Load translations.
	load_plugin_textdomain(
		'gas-safety-manager',
		false,
		dirname( GSM_PLUGIN_BASENAME ) . '/languages'
	);

	// Run DB upgrade check (creates/updates tables if version changed).
	GSM_Activator::maybe_upgrade();

	// Initialize the loader (registers hooks for admin, REST, public).
	$loader = new GSM_Loader();
	$loader->run();
}
add_action( 'plugins_loaded', 'gsm_run' );

// Security + communication wiring.
add_action( 'send_headers', array( 'GSM_Security', 'headers' ) );
// Content-Security-Policy on the app + QR bridge pages (needs the resolved
// query, so template_redirect — still before any output).
add_action( 'template_redirect', array( 'GSM_Security', 'csp' ), 1 );
add_action( 'template_redirect', array( 'GSM_Security', 'app_page_privacy' ), 1 );
// App + bridge pages are Hebrew regardless of the site language (WCAG 3.1.1).
add_filter( 'language_attributes', array( 'GSM_Public', 'app_language_attributes' ), 20 );
add_filter( 'wp_inline_script_attributes', array( 'GSM_Security', 'script_nonce_attr' ) );
add_filter( 'wp_script_attributes', array( 'GSM_Security', 'script_nonce_attr' ) );
add_action( 'gsm_daily_reminders', array( 'GSM_Comm', 'run_reminders' ) );
add_action( 'init', array( 'GSM_Comm', 'sync_cron' ) );
// Data retention: daily cleanup of old log records (starts after a grace period).
add_action( GSM_Retention::CRON, array( 'GSM_Retention', 'run_scheduled' ) );
add_action( 'init', array( 'GSM_Retention', 'sync_cron' ) );
// Pull from the old system: optional daily sync (off unless enabled).
add_action( GSM_Legacy::CRON, array( 'GSM_Legacy', 'run_scheduled' ) );
add_action( 'init', array( 'GSM_Legacy', 'sync_cron' ) );
// Chat widget (Botwa): click-to-load launcher + CSP allowance, only where enabled.
add_action( 'wp_enqueue_scripts', array( 'GSM_Chat', 'enqueue' ), 20 );
// A deactivated user cannot sign in by any path. Priority 30 runs AFTER the
// password check (20), so it never reveals that an account exists/is blocked
// to someone who does not know the password.
add_filter( 'authenticate', array( 'GSM_Auth', 'block_inactive_login' ), 30 );
// Two-factor: second step after the password (40), on every password login path.
add_filter( 'authenticate', array( 'GSM_2FA', 'check_login' ), 40, 3 );
add_action( 'login_form', array( 'GSM_2FA', 'login_field' ) );
add_filter( 'wp_is_application_passwords_available_for_user', array( 'GSM_2FA', 'app_passwords' ), 10, 2 );
add_filter( 'gsm_csp_directives', array( 'GSM_Chat', 'csp' ) );
// reCAPTCHA (optional, off by default): loader + CSP allowance only where used.
add_action( 'wp_enqueue_scripts', array( 'GSM_Captcha', 'enqueue' ), 25 );
add_filter( 'gsm_csp_directives', array( 'GSM_Captcha', 'csp' ) );
// Accessibility adjustments menu on every front-end page (app, QR page, site).
add_action( 'wp_enqueue_scripts', 'gsm_enqueue_a11y_widget', 30 );
/**
 * Enqueue the accessibility adjustments menu. Local files only; choices stay
 * in the visitor's browser. Switch off with: add_filter( 'gsm_a11y_widget', '__return_false' ).
 */
function gsm_enqueue_a11y_widget() {
	if ( is_admin() || ! apply_filters( 'gsm_a11y_widget', true ) ) {
		return;
	}
	wp_enqueue_style( 'gsm-a11y-widget', GSM_PLUGIN_URL . 'public/assets/css/a11y-widget.css', array(), GSM_VERSION );
	wp_enqueue_script( 'gsm-a11y-widget', GSM_PLUGIN_URL . 'public/assets/js/a11y-widget.js', array(), GSM_VERSION, true );
}
register_deactivation_hook( __FILE__, 'gsm_deactivate_cron' );
/**
 * Clear scheduled reminders on deactivation.
 */
function gsm_deactivate_cron() {
	wp_clear_scheduled_hook( 'gsm_daily_reminders' );
	wp_clear_scheduled_hook( 'gsm_daily_retention' );
	wp_clear_scheduled_hook( 'gsm_legacy_sync' );
}

/**
 * Ensure the front-end pages exist. Runs on 'init' (not plugins_loaded),
 * which is late enough for wp_insert_post to be safe. Cheap: only inserts
 * when a page is actually missing.
 */
function gsm_ensure_pages() {
	// Only run in a normal context, and not during AJAX/REST/cron to keep
	// those requests fast.
	if ( wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}
	if ( defined( 'REST_REQUEST' ) && REST_REQUEST ) {
		return;
	}
	// Run at most once per load, and skip if both pages already recorded.
	$app_id    = (int) get_option( 'gsm_app_page_id', 0 );
	$bridge_id = (int) get_option( 'gsm_bridge_page_id', 0 );
	if ( $app_id && $bridge_id && get_post( $app_id ) && get_post( $bridge_id ) ) {
		return;
	}
	// Never let a page-creation hiccup take down the whole site.
	try {
		GSM_Activator::create_pages();
	} catch ( \Throwable $e ) {
		// Silently ignore — pages can be created manually if needed.
		return;
	}
}
add_action( 'init', 'gsm_ensure_pages' );
