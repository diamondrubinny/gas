<?php
/**
 * Public-facing: the [gas_app] shortcode — the standalone SPA.
 *
 * Renders a single mount point and loads the SPA (router, auth, views, forms,
 * bootstrap) in dependency order. The app handles home/login/dashboard/etc.
 * client-side via a hash router. Authentication is via WordPress users.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Public
 */
class GSM_Public {

	/**
	 * Hook shortcode + assets.
	 */
	public function init() {
		add_shortcode( 'gas_app', array( $this, 'render_app' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
		// Lightweight nonce refresher. admin-ajax authenticates by the login
		// cookie and does NOT require a REST nonce, so the SPA can recover a
		// fresh REST nonce here even after the page's baked-in nonce expired
		// (no manual refresh needed). Returns only { logged_in, nonce }.
		add_action( 'wp_ajax_gsm_nonce', array( $this, 'ajax_nonce' ) );
		add_action( 'wp_ajax_nopriv_gsm_nonce', array( $this, 'ajax_nonce' ) );
	}

	/**
	 * language_attributes filter: the app and the QR bridge page are always in
	 * Hebrew, whatever the site language is, so declare lang="he-IL" (and RTL)
	 * on them — screen readers pick the voice from it (WCAG 3.1.1).
	 *
	 * @param string $output Attributes for the <html> tag.
	 * @return string
	 */
	public static function app_language_attributes( $output ) {
		if ( ! class_exists( 'GSM_Security' ) || ! GSM_Security::is_app_request() ) {
			return $output;
		}
		$output = preg_replace( '/\blang="[^"]*"/', '', (string) $output );
		$output = preg_replace( '/\bdir="[^"]*"/', '', $output );
		return trim( 'lang="he-IL" dir="rtl" ' . trim( $output ) );
	}

	/**
	 * admin-ajax: return a fresh wp_rest nonce for the current login cookie.
	 */
	public function ajax_nonce() {
		nocache_headers();
		$logged_in = is_user_logged_in();
		if ( $logged_in && class_exists( 'GSM_Auth' ) && ! GSM_Auth::is_active( wp_get_current_user() ) ) {
			$logged_in = false; // Blocked mid-session = logged out for the app.
		}
		wp_send_json(
			array(
				'logged_in' => (bool) $logged_in,
				'nonce'     => wp_create_nonce( 'wp_rest' ),
			)
		);
	}

	/**
	 * Register assets (enqueue only when the shortcode renders).
	 */
	public function register_assets() {
		// Rubik is bundled (public/assets/fonts) — no Google Fonts request.
		wp_register_style( 'gsm-rubik', GSM_PLUGIN_URL . 'public/assets/css/fonts.css', array(), GSM_VERSION );
		wp_register_style( 'gsm-app', GSM_PLUGIN_URL . 'public/assets/css/app-ui.css', array( 'gsm-rubik' ), GSM_VERSION );

		// SPA scripts in dependency order. app.js depends on all of them.
		$base = GSM_PLUGIN_URL . 'public/assets/js/';
		wp_register_script( 'gsm-a11y', $base . 'a11y.js', array(), GSM_VERSION, true );
		wp_register_script( 'gsm-router', $base . 'router.js', array( 'gsm-a11y' ), GSM_VERSION, true );
		wp_register_script( 'gsm-auth', $base . 'auth.js', array( 'gsm-router' ), GSM_VERSION, true );
		wp_register_script( 'gsm-forms', $base . 'forms.js', array( 'gsm-auth' ), GSM_VERSION, true );
		wp_register_script( 'gsm-view-homelogin', $base . 'views/home-login.js', array( 'gsm-auth' ), GSM_VERSION, true );
		// QR + Chart libs are bundled with the plugin (served same-origin), NOT
		// from a CDN — the Technion network blocks outside CDNs, which left QR
		// codes blank and BI charts missing. Local copies always load.
		$vendor = GSM_PLUGIN_URL . 'public/assets/vendor/';
		// qrcode-generator 2.0.4 (MIT, maintained) + a small UTF-8 / PNG helper;
		// replaced the unmaintained qrcodejs. Chart.js 4.6.1.
		wp_register_script( 'gsm-qrcode-lib', $vendor . 'qrcode-generator.js', array(), '2.0.4', true );
		wp_register_script( 'gsm-qrcode', $base . 'qr.js', array( 'gsm-qrcode-lib', 'gsm-auth' ), GSM_VERSION, true );
		wp_register_script( 'gsm-chartjs', $vendor . 'chart.umd.min.js', array(), '4.6.1', true );
		wp_register_script( 'gsm-view-app', $base . 'views/app-views.js', array( 'gsm-auth', 'gsm-forms', 'gsm-qrcode', 'gsm-chartjs' ), GSM_VERSION, true );
		wp_register_script( 'gsm-app', $base . 'app.js', array( 'gsm-router', 'gsm-auth', 'gsm-forms', 'gsm-view-homelogin', 'gsm-view-app' ), GSM_VERSION, true );
	}

	/**
	 * Branding (title/subtitle/logo) with safe defaults.
	 *
	 * @return array
	 */
	private static function branding() {
		$b = get_option( 'gsm_branding', array() );
		$b = is_array( $b ) ? $b : array();
		return array(
			'title'    => ( isset( $b['title'] ) && '' !== $b['title'] ) ? (string) $b['title'] : '',
			'subtitle' => isset( $b['subtitle'] ) ? (string) $b['subtitle'] : '',
			'logo_url' => isset( $b['logo_url'] ) ? esc_url_raw( (string) $b['logo_url'] ) : '',
		);
	}

	/**
	 * Shortcode callback — the app mounts here.
	 *
	 * @return string
	 */
	public function render_app() {
		wp_enqueue_style( 'gsm-app' );
		wp_enqueue_script( 'gsm-app' ); // pulls in all dependencies

		$bridge_id  = (int) get_option( 'gsm_bridge_page_id', 0 );
		$bridge_url = $bridge_id ? get_permalink( $bridge_id ) : '';

		$config = array(
			'restRoot'  => esc_url_raw( rest_url( 'gas/v1' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			// Real bridge-page permalink from WP — correct under any permalink
			// setting (pretty or plain ?page_id=). Used to build QR links.
			'bridgeUrl' => $bridge_url ? esc_url_raw( $bridge_url ) : '',
			'assetsUrl' => esc_url_raw( GSM_PLUGIN_URL . 'public/assets/' ),
			'ajaxUrl'   => esc_url_raw( admin_url( 'admin-ajax.php' ) ),
			'branding'  => self::branding(),
			'navOrder'  => array_values( (array) get_option( 'gsm_nav_order', array() ) ),
		);
		// Attach config to the first script in the chain so all have it.
		wp_add_inline_script( 'gsm-router', 'window.GSM_CONFIG = ' . wp_json_encode( $config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );

		return '<div id="gsm-app" class="gs-app"><div class="gs-loading">טוען מערכת…</div></div>';
	}
}
