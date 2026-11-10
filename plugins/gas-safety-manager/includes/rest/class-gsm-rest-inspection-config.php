<?php
/**
 * REST controller: Inspection configuration.
 *
 * Holds the two role-based checklists (quarterly = אחמ"ת / long,
 * monthly = lab manager & PI / short) plus notification/cadence rules.
 * Stored in the gsm_inspection_config option.
 *
 *   GET  /gas/v1/inspection-config   (report) — read
 *   POST /gas/v1/inspection-config   (manage) — save
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Inspection_Config
 */
class GSM_REST_Inspection_Config {

	const NS = 'gas/v1';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/inspection-config',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_config' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_config' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
	}

	/**
	 * Default config shape.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
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
		);
	}

	/**
	 * GET /inspection-config
	 *
	 * @return WP_REST_Response
	 */
	public function get_config() {
		$cfg = get_option( 'gsm_inspection_config', array() );
		if ( ! is_array( $cfg ) ) {
			$cfg = array();
		}
		return rest_ensure_response( self::merge_defaults( $cfg ) );
	}

	/**
	 * POST /inspection-config
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function save_config( $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}
		$clean = self::sanitize( $body );
		update_option( 'gsm_inspection_config', $clean );
		return rest_ensure_response( $clean );
	}

	/**
	 * Merge a stored config over defaults.
	 *
	 * @param array $cfg Stored.
	 * @return array
	 */
	private static function merge_defaults( $cfg ) {
		$d                 = self::defaults();
		$out               = $d;
		if ( isset( $cfg['rules'] ) && is_array( $cfg['rules'] ) ) {
			$out['rules'] = array_merge( $d['rules'], $cfg['rules'] );
		}
		if ( isset( $cfg['checklists'] ) && is_array( $cfg['checklists'] ) ) {
			$out['checklists']['quarterly'] = isset( $cfg['checklists']['quarterly'] ) && is_array( $cfg['checklists']['quarterly'] ) ? $cfg['checklists']['quarterly'] : array();
			$out['checklists']['monthly']   = isset( $cfg['checklists']['monthly'] ) && is_array( $cfg['checklists']['monthly'] ) ? $cfg['checklists']['monthly'] : array();
		}
		return $out;
	}

	/**
	 * Sanitize an incoming config.
	 *
	 * @param array $body Raw.
	 * @return array
	 */
	private static function sanitize( $body ) {
		$d     = self::defaults();
		$rules = ( isset( $body['rules'] ) && is_array( $body['rules'] ) ) ? $body['rules'] : array();

		$clean_rules = array(
			'monthlyEveryDays'   => self::clamp_int( $rules, 'monthlyEveryDays', 1, 3650, 30 ),
			'quarterlyEveryDays' => self::clamp_int( $rules, 'quarterlyEveryDays', 1, 3650, 90 ),
			'reminderBeforeDays' => self::clamp_int( $rules, 'reminderBeforeDays', 0, 365, 7 ),
			'notifyPI'           => ! empty( $rules['notifyPI'] ),
			'notifyAhmat'        => ! empty( $rules['notifyAhmat'] ),
			'notifyLabManager'   => ! empty( $rules['notifyLabManager'] ),
			'notifyOnComplete'   => ! empty( $rules['notifyOnComplete'] ),
			'extraEmails'        => array(),
		);
		if ( isset( $rules['extraEmails'] ) && is_array( $rules['extraEmails'] ) ) {
			foreach ( $rules['extraEmails'] as $e ) {
				$email = sanitize_email( (string) $e );
				if ( $email && is_email( $email ) ) {
					$clean_rules['extraEmails'][] = $email;
				}
			}
		}

		$checklists = ( isset( $body['checklists'] ) && is_array( $body['checklists'] ) ) ? $body['checklists'] : array();

		return array(
			'rules'      => $clean_rules,
			'checklists' => array(
				'quarterly' => self::clean_items( isset( $checklists['quarterly'] ) ? $checklists['quarterly'] : array() ),
				'monthly'   => self::clean_items( isset( $checklists['monthly'] ) ? $checklists['monthly'] : array() ),
			),
		);
	}

	/**
	 * Clean a checklist array into [{id,text}, …], dropping empties.
	 *
	 * @param mixed $items Raw.
	 * @return array
	 */
	private static function clean_items( $items ) {
		$out = array();
		if ( ! is_array( $items ) ) {
			return $out;
		}
		$i = 0;
		foreach ( $items as $it ) {
			$i++;
			$text = '';
			$id   = '';
			if ( is_array( $it ) ) {
				$text = isset( $it['text'] ) ? sanitize_text_field( (string) $it['text'] ) : '';
				$id   = isset( $it['id'] ) ? sanitize_text_field( (string) $it['id'] ) : '';
			} else {
				$text = sanitize_text_field( (string) $it );
			}
			$text = trim( $text );
			if ( '' === $text ) {
				continue;
			}
			if ( '' === $id ) {
				$id = 'c' . $i . '_' . substr( md5( $text ), 0, 6 );
			}
			$out[] = array( 'id' => $id, 'text' => $text );
		}
		return $out;
	}

	/**
	 * Clamp an int field.
	 *
	 * @param array  $src Source.
	 * @param string $key Key.
	 * @param int    $min Min.
	 * @param int    $max Max.
	 * @param int    $def Default.
	 * @return int
	 */
	private static function clamp_int( $src, $key, $min, $max, $def ) {
		$v = isset( $src[ $key ] ) ? (int) $src[ $key ] : $def;
		return max( $min, min( $max, $v ) );
	}
}
