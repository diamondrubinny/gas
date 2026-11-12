<?php
/**
 * REST controller: reCAPTCHA settings (manager only).
 *
 *   GET  /gas/v1/captcha        settings (secret never returned)
 *   POST /gas/v1/captcha        save
 *   POST /gas/v1/captcha/test   { token } real verification with the saved keys
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Captcha
 */
class GSM_REST_Captcha {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		$perm = array( 'GSM_Auth', 'require_manage' );
		register_rest_route(
			self::NS,
			'/captcha',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get' ),
					'permission_callback' => $perm,
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save' ),
					'permission_callback' => $perm,
				),
			)
		);
		register_rest_route(
			self::NS,
			'/captcha/test',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test' ),
				'permission_callback' => $perm,
			)
		);
	}

	/**
	 * GET /captcha
	 *
	 * @return WP_REST_Response
	 */
	public function get() {
		return rest_ensure_response( GSM_Captcha::public_config() );
	}

	/**
	 * POST /captcha
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function save( $req ) {
		$b  = (array) $req->get_json_params();
		$in = array();
		foreach ( array( 'site_key', 'secret' ) as $k ) {
			if ( isset( $b[ $k ] ) && is_string( $b[ $k ] ) ) {
				$in[ $k ] = $b[ $k ];
			}
		}
		foreach ( array( 'enabled', 'enforce', 'login', 'bridge', 'clear' ) as $k ) {
			if ( isset( $b[ $k ] ) ) {
				$in[ $k ] = (bool) $b[ $k ];
			}
		}
		if ( isset( $b['min_score'] ) && is_numeric( $b['min_score'] ) ) {
			$in['min_score'] = (float) $b['min_score'];
		}
		$out = GSM_Captcha::save( $in );
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'settings', 0, 'reCAPTCHA', 'עודכנו הגדרות reCAPTCHA: ' . ( $out['enabled'] ? ( $out['enforce'] ? 'פעיל, מצב אכיפה' : 'פעיל, מצב ניטור' ) : 'כבוי' ) );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /captcha/test
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function test( $req ) {
		$b   = (array) $req->get_json_params();
		$out = GSM_Captcha::test( isset( $b['token'] ) && is_string( $b['token'] ) ? $b['token'] : '' );
		return is_wp_error( $out ) ? $out : rest_ensure_response( $out );
	}
}
