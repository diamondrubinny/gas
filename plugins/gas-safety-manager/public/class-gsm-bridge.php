<?php
/**
 * Public bridge page — the [gas_bridge] shortcode.
 *
 * This is the PUBLIC page a field user lands on after scanning a QR code
 * (/bridge?eq=TOKEN or ?eq=MONDAY_ID). Unlike the management UI, it needs no
 * login — the token is the authorization. It shows equipment details and lets
 * anyone report a fault.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Bridge
 */
class GSM_Bridge {

	/**
	 * Hook shortcode + assets.
	 */
	public function init() {
		add_shortcode( 'gas_bridge', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Register bridge assets.
	 */
	public function register_assets() {
		wp_register_style(
			'gsm-app',
			GSM_PLUGIN_URL . 'public/assets/css/app-ui.css',
			array(),
			GSM_VERSION
		);
		wp_register_script(
			'gsm-bridge',
			GSM_PLUGIN_URL . 'public/assets/js/bridge.js',
			array(),
			GSM_VERSION,
			true
		);
	}

	/**
	 * Render the bridge mount point. Public — no login required.
	 *
	 * @return string
	 */
	public function render() {
		wp_enqueue_style( 'gsm-app' );
		wp_enqueue_script( 'gsm-bridge' );

		$bridge_cfg = get_option( 'gsm_bridge_config', array() );
		if ( ! is_array( $bridge_cfg ) ) {
			$bridge_cfg = array();
		}
		if ( class_exists( 'GSM_REST_Tools' ) ) {
			$bridge_cfg = array_merge( GSM_REST_Tools::bridge_defaults(), $bridge_cfg );
		}

		$config = array(
			'restRoot' => esc_url_raw( rest_url( 'gas/v1' ) ),
			// A nonce only helps logged-in users; the bridge read is public.
			'nonce'    => is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '',
			'cfg'      => $bridge_cfg,
		);
		wp_add_inline_script(
			'gsm-bridge',
			'window.GSM_BRIDGE = ' . wp_json_encode( $config ) . ';',
			'before'
		);

		return '<div id="gsm-bridge" class="gs-app-mount"><div class="gs-loading">טוען פרטי ציוד…</div></div>';
	}
}
