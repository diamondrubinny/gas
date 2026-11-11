<?php
/**
 * Botwa chat widget integration (privacy- and CSP-friendly).
 *
 * - The vendor loader is served from this plugin (public/assets/vendor/
 *   botwa-widget.js — a reviewed, pinned copy), so no third-party script runs
 *   on our pages; only the chat iframe and its JSON config come from the
 *   chat server.
 * - Click-to-load: pages show our own "chat" button; nothing is requested
 *   from the chat server until a visitor clicks it.
 * - Off by default. Configured in "הגדרות אתר" by pasting the vendor's embed
 *   snippet; only an https origin and a key in the vendor's own format
 *   (wc_…) are accepted.
 * - The plugin never calls BotwaChat.identify(): no name / email / phone is
 *   sent to the chat provider.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Chat
 */
class GSM_Chat {

	const OPTION = 'gsm_chat';

	/**
	 * Placements: key => label.
	 *
	 * @return array
	 */
	public static function placements() {
		return array(
			'bridge' => 'עמוד ה-QR הציבורי',
			'app'    => 'בתוך המערכת (משתמשים מחוברים)',
			'site'   => 'שאר עמודי האתר',
		);
	}

	/**
	 * Current settings (with safe defaults).
	 *
	 * @return array
	 */
	public static function config() {
		$c = get_option( self::OPTION, array() );
		$c = is_array( $c ) ? $c : array();
		$w = isset( $c['where'] ) && is_array( $c['where'] ) ? $c['where'] : array();
		$where = array();
		foreach ( array_keys( self::placements() ) as $k ) {
			$where[ $k ] = ! empty( $w[ $k ] );
		}
		return array(
			'enabled' => ! empty( $c['enabled'] ),
			'origin'  => isset( $c['origin'] ) ? (string) $c['origin'] : '',
			'key'     => isset( $c['key'] ) ? (string) $c['key'] : '',
			'label'   => isset( $c['label'] ) && '' !== $c['label'] ? (string) $c['label'] : 'צ׳אט',
			'where'   => $where,
		);
	}

	/**
	 * Validate an https origin. Returns "https://host[:port]" or ''.
	 *
	 * @param string $url URL or origin.
	 * @return string
	 */
	public static function clean_origin( $url ) {
		$p = wp_parse_url( trim( (string) $url ) );
		if ( empty( $p['scheme'] ) || 'https' !== strtolower( $p['scheme'] ) || empty( $p['host'] ) ) {
			return '';
		}
		$host = strtolower( trim( $p['host'], '[]' ) );
		// Public DNS hostnames only: no IP literals (v4/v6), no single-label or
		// internal names; the last label (TLD) must be alphabetic.
		if ( false !== filter_var( $host, FILTER_VALIDATE_IP )
			|| ! preg_match( '/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,63}$/', $host ) ) {
			return '';
		}
		$port = isset( $p['port'] ) ? (int) $p['port'] : 0;
		return 'https://' . $host . ( $port && 443 !== $port ? ':' . $port : '' );
	}

	/**
	 * Key in the vendor's format (the loader itself enforces the same pattern).
	 *
	 * @param string $key Key.
	 * @return string '' when invalid.
	 */
	public static function clean_key( $key ) {
		$key = trim( (string) $key );
		return preg_match( '/^wc_[A-Za-z0-9_-]{10,60}$/', $key ) ? $key : '';
	}

	/**
	 * Parse the vendor embed snippet: <script src="https://HOST/widget.js" data-key="wc_…">.
	 *
	 * @param string $snippet Pasted HTML.
	 * @return array|WP_Error { origin, key }
	 */
	public static function parse_snippet( $snippet ) {
		$snippet = (string) $snippet;
		if ( ! preg_match( '/\bsrc\s*=\s*["\']([^"\']+)["\']/i', $snippet, $m_src )
			|| ! preg_match( '/\bdata-key\s*=\s*["\']([^"\']+)["\']/i', $snippet, $m_key ) ) {
			return new WP_Error( 'gsm_chat_snippet', 'לא נמצאו בקוד ההטמעה src ו-data-key.', array( 'status' => 400 ) );
		}
		$path = (string) wp_parse_url( $m_src[1], PHP_URL_PATH );
		if ( ! preg_match( '#/widget\.js$#', $path ) ) {
			return new WP_Error( 'gsm_chat_snippet', 'כתובת הסקריפט צריכה להסתיים ב-/widget.js.', array( 'status' => 400 ) );
		}
		$origin = self::clean_origin( $m_src[1] );
		$key    = self::clean_key( $m_key[1] );
		if ( '' === $origin ) {
			return new WP_Error( 'gsm_chat_snippet', 'כתובת שרת הצ׳אט חייבת להיות https עם שם מארח תקין.', array( 'status' => 400 ) );
		}
		if ( '' === $key ) {
			return new WP_Error( 'gsm_chat_snippet', 'מפתח הצ׳אט (data-key) אינו בפורמט wc_….', array( 'status' => 400 ) );
		}
		return array( 'origin' => $origin, 'key' => $key );
	}

	/**
	 * Save settings from the admin screen.
	 *
	 * @param array $in Input: snippet?, origin?, key?, enabled, label, where{}.
	 * @return array|WP_Error Saved config.
	 */
	public static function save( $in ) {
		$cur = self::config();
		if ( ! empty( $in['snippet'] ) ) {
			$parsed = self::parse_snippet( $in['snippet'] );
			if ( is_wp_error( $parsed ) ) {
				return $parsed;
			}
			$cur['origin'] = $parsed['origin'];
			$cur['key']    = $parsed['key'];
		}
		if ( isset( $in['label'] ) ) {
			$label        = sanitize_text_field( (string) $in['label'] );
			$cur['label'] = function_exists( 'mb_substr' ) ? mb_substr( $label, 0, 40 ) : substr( $label, 0, 40 );
		}
		if ( isset( $in['where'] ) && is_array( $in['where'] ) ) {
			foreach ( array_keys( self::placements() ) as $k ) {
				$cur['where'][ $k ] = ! empty( $in['where'][ $k ] );
			}
		}
		if ( isset( $in['enabled'] ) ) {
			$cur['enabled'] = (bool) $in['enabled'];
		}
		if ( ! empty( $in['clear'] ) ) {
			$cur['origin']  = '';
			$cur['key']     = '';
			$cur['enabled'] = false;
		}
		if ( $cur['enabled'] && ( '' === $cur['origin'] || '' === $cur['key'] ) ) {
			return new WP_Error( 'gsm_chat_missing', 'כדי להפעיל צריך להדביק קודם את קוד ההטמעה של הצ׳אט.', array( 'status' => 400 ) );
		}
		update_option( self::OPTION, $cur, false );
		return $cur;
	}

	/**
	 * Which placement the current front-end request is: 'app' | 'bridge' | 'site' | ''.
	 *
	 * @return string
	 */
	public static function current_placement() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return '';
		}
		$post = is_singular() ? get_post( get_queried_object_id() ) : null;
		if ( $post && has_shortcode( $post->post_content, 'gas_bridge' ) ) {
			return 'bridge';
		}
		if ( $post && has_shortcode( $post->post_content, 'gas_app' ) ) {
			return 'app';
		}
		$ids = array(
			'bridge' => (int) get_option( 'gsm_bridge_page_id', 0 ),
			'app'    => (int) get_option( 'gsm_app_page_id', 0 ),
		);
		foreach ( $ids as $k => $id ) {
			if ( $id && is_page( $id ) ) {
				return $k;
			}
		}
		return 'site';
	}

	/**
	 * Is the chat shown on the current request?
	 *
	 * @return bool
	 */
	public static function active_here() {
		$c = self::config();
		if ( ! $c['enabled'] || '' === $c['origin'] || '' === $c['key'] ) {
			return false;
		}
		$where = self::current_placement();
		return '' !== $where && ! empty( $c['where'][ $where ] );
	}

	/**
	 * wp_enqueue_scripts: register + enqueue the click-to-load launcher.
	 */
	public static function enqueue() {
		if ( ! self::active_here() ) {
			return;
		}
		$c = self::config();
		wp_enqueue_style( 'gsm-chat', GSM_PLUGIN_URL . 'public/assets/css/chat.css', array(), GSM_VERSION );
		wp_enqueue_script( 'gsm-chat', GSM_PLUGIN_URL . 'public/assets/js/chat.js', array(), GSM_VERSION, true );
		$public = array(
			'origin' => $c['origin'],
			'key'    => $c['key'],
			'label'  => $c['label'],
			'loader' => GSM_PLUGIN_URL . 'public/assets/vendor/botwa-widget.js?ver=' . rawurlencode( GSM_VERSION ),
		);
		wp_add_inline_script( 'gsm-chat', 'window.GSM_CHAT = ' . wp_json_encode( $public, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT ) . ';', 'before' );
	}

	/**
	 * gsm_csp_directives filter: allow the chat server for config (connect-src)
	 * and the chat window (frame-src) — only where the chat is shown.
	 *
	 * @param array $d Directives.
	 * @return array
	 */
	public static function csp( $d ) {
		if ( ! self::active_here() ) {
			return $d;
		}
		$o = self::config()['origin'];
		foreach ( array( 'connect-src', 'frame-src' ) as $k ) {
			$d[ $k ] = trim( ( isset( $d[ $k ] ) ? $d[ $k ] : "'self'" ) . ' ' . $o );
		}
		return $d;
	}
}
