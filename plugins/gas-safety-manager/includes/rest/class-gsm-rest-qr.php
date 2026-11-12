<?php
/**
 * QR + scan REST controller.
 *
 * Public endpoints for the bridge page (scanning a QR opens equipment info):
 *   - GET  /qr/{token}   public equipment view + logs a scan
 *   - POST /scans        explicit scan log (optional)
 *
 * The token is the authorization — long, random, unguessable, so there is no
 * IDOR risk from incrementing an ID. Legacy Monday QR codes still resolve via
 * the by-monday-id fallback.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_QR
 */
class GSM_REST_QR {

	const NS = 'gas/v1';

	/**
	 * Register QR routes.
	 */
	public function register_routes() {
		// Public bridge read by token (32 hex) OR legacy monday id (digits).
		register_rest_route(
			self::NS,
			'/qr/(?P<code>[a-f0-9]{32}|\d+)',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'bridge' ),
				'permission_callback' => '__return_true',
			)
		);

		// Scan log (public — field users).
		register_rest_route(
			self::NS,
			'/scans',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'log_scan' ),
				'permission_callback' => '__return_true',
			)
		);

		// Prepare for sticker printing: ensure every item has a QR token AND
		// a human-facing asset_code. Manage-only. Safe to call repeatedly.
		register_rest_route(
			self::NS,
			'/qr/prepare',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'prepare_stickers' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);

		// PUBLIC fault report from the bridge (no login — anyone who scanned
		// the QR can report). The qr_token is the authorization. Anti-spam is
		// enforced in the handler (honeypot + time-trap + per-IP rate limit).
		register_rest_route(
			self::NS,
			'/report-fault',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'report_fault' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * POST /report-fault — public fault report tied to a scanned QR token.
	 *
	 * Body: { token, description, reporter, priority, hp, ts }
	 *   - token     : the equipment qr_token (authorization; prevents IDOR)
	 *   - hp         : honeypot — must be empty (bots fill it)
	 *   - ts         : client timestamp (ms) when the form opened (time-trap)
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function report_fault( $req ) {
		$body = $req->get_json_params();
		if ( ! is_array( $body ) ) {
			$body = array();
		}

		// Honeypot: a filled hidden field means a bot. Pretend success, do nothing.
		if ( ! empty( $body['hp'] ) ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		// Time-trap: a real person needs a couple of seconds to fill the form.
		if ( isset( $body['ts'] ) && is_numeric( $body['ts'] ) ) {
			// Millisecond clock: time() * 1000 floors to the second and wrongly
			// rejected real people who submitted 2-3 s after opening the form.
			$elapsed = (int) round( microtime( true ) * 1000 ) - (int) $body['ts'];
			if ( $elapsed >= 0 && $elapsed < 2000 ) {
				return new WP_Error( 'gsm_too_fast', __( 'שליחה מהירה מדי. נסו שוב.', 'gas-safety-manager' ), array( 'status' => 429 ) );
			}
		}

		// Per-IP rate limit: max 5 reports / 10 minutes.
		$ip_hash = GSM_Auth::hash_ip( GSM_Auth::client_ip() );
		$rk      = 'gsm_rf_' . $ip_hash;
		$count   = (int) get_transient( $rk );
		if ( $count >= 5 ) {
			return new WP_Error( 'gsm_rate', __( 'יותר מדי דיווחים. נסו שוב מאוחר יותר.', 'gas-safety-manager' ), array( 'status' => 429 ) );
		}

		// Bot check (optional).
		$cap = GSM_Captcha::check( 'bridge', isset( $body['captcha'] ) && is_string( $body['captcha'] ) ? $body['captcha'] : '', 'report_fault' );
		if ( is_wp_error( $cap ) ) {
			return $cap;
		}

		// Resolve equipment by token (authorization). Legacy monday_id allowed.
		$token = isset( $body['token'] ) ? sanitize_text_field( (string) $body['token'] ) : '';
		$row   = $token ? GSM_Equipment_Repo::get_by_token( $token ) : null;
		if ( ! $row && $token && ctype_digit( $token ) ) {
			$row = GSM_Equipment_Repo::get_by_monday_id( $token );
		}
		if ( ! $row ) {
			return new WP_Error( 'gsm_not_found', __( 'הציוד לא נמצא.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}

		$description = isset( $body['description'] ) ? trim( (string) $body['description'] ) : '';
		if ( '' === $description ) {
			return new WP_Error( 'gsm_validation', __( 'תיאור התקלה חובה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		// Length caps (defensive).
		$description = function_exists( 'mb_substr' ) ? mb_substr( $description, 0, 2000 ) : substr( $description, 0, 2000 );
		$reporter    = isset( $body['reporter'] ) ? (string) $body['reporter'] : '';
		$reporter    = function_exists( 'mb_substr' ) ? mb_substr( $reporter, 0, 120 ) : substr( $reporter, 0, 120 );
		$priority    = isset( $body['priority'] ) ? (string) $body['priority'] : 'normal';

		$res = GSM_Faults_Service::create(
			array(
				'equipment_id' => (int) $row['id'],
				'description'  => $description,
				'reporter'     => $reporter,
				'priority'     => $priority,
				'status'       => 'open',
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}

		// Count this report toward the rate limit only on success.
		set_transient( $rk, $count + 1, 10 * MINUTE_IN_SECONDS );

		return rest_ensure_response( array( 'ok' => true, 'id' => (int) $res ) );
	}

	/**
	 * POST /qr/prepare — backfill QR tokens and asset codes across all
	 * equipment so the sticker sheet never has a blank label. Idempotent:
	 * only items that are actually missing a value are touched.
	 *
	 * @return WP_REST_Response
	 */
	public function prepare_stickers() {
		$rows           = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'id', 'order' => 'ASC' ) );
		$token_assigned = 0;
		$asset_assigned = 0;

		foreach ( (array) $rows as $row ) {
			$id     = (int) $row['id'];
			$update = array();

			if ( empty( $row['qr_token'] ) ) {
				$update['qr_token'] = GSM_Equipment_Service::generate_token();
				$token_assigned++;
			}

			if ( empty( $row['asset_code'] ) ) {
				// Preserve Monday's number; allocate G-#### only when there
				// is no Monday number to preserve.
				$update['asset_code'] = GSM_Equipment_Service::allocate_asset_code(
					array( 'monday_id' => isset( $row['monday_id'] ) ? $row['monday_id'] : '' )
				);
				$asset_assigned++;
			}

			if ( ! empty( $update ) ) {
				GSM_Equipment_Repo::update( $id, $update );
			}
		}

		return rest_ensure_response(
			array(
				'total'          => count( (array) $rows ),
				'token_assigned' => $token_assigned,
				'asset_assigned' => $asset_assigned,
			)
		);
	}

	/**
	 * GET /qr/{code} — resolve equipment, log the scan, return safe fields.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function bridge( $req ) {
		$code = (string) $req['code'];

		// Public, unauthenticated: cap lookups per IP, and much harder cap on
		// lookups that MISS — legacy numeric Monday IDs are not secret, so
		// misses are what an enumeration attempt looks like.
		if ( GSM_Rate_Limit::too_many( 'qr-lookup', 60, 300 ) ) {
			return GSM_Rate_Limit::error();
		}
		$miss_key = 'gsm_rl_' . md5( 'qr-miss|' . GSM_Rate_Limit::client_ip() );
		if ( (int) get_transient( $miss_key ) >= 20 ) {
			return GSM_Rate_Limit::error();
		}

		// Try token first (new), then Monday ID (legacy printed labels).
		$row = GSM_Equipment_Repo::get_by_token( $code );
		if ( ! $row && ctype_digit( $code ) ) {
			$row = GSM_Equipment_Repo::get_by_monday_id( $code );
		}
		if ( ! $row ) {
			set_transient( $miss_key, (int) get_transient( $miss_key ) + 1, 600 );
			return new WP_Error(
				'gsm_not_found',
				__( 'הציוד לא נמצא. ייתכן שקוד ה-QR אינו מעודכן.', 'gas-safety-manager' ),
				array( 'status' => 404 )
			);
		}

		// Log the scan (privacy: only a hashed IP).
		self::record_scan( (int) $row['id'] );

		$cfg  = get_option( 'gsm_bridge_config', array() );
		$cfg  = is_array( $cfg ) ? $cfg : array();
		$cfg  = class_exists( 'GSM_REST_Tools' ) ? array_merge( GSM_REST_Tools::bridge_defaults(), $cfg ) : $cfg;
		$show = function ( $key ) use ( $cfg ) {
			return ! isset( $cfg[ $key ] ) || false !== $cfg[ $key ];
		};

		// Open faults + open defects for context on the bridge page.
		$open_faults  = GSM_Faults_Repo::count( array( 'equipment_id' => (int) $row['id'], 'status' => 'open' ) );
		$open_defects = GSM_Inspections_Repo::count_open_defects( (int) $row['id'] );

		return rest_ensure_response(
			array(
				'id'             => (int) $row['id'],
				'name'           => $row['name'],
				'asset_code'     => isset( $row['asset_code'] ) ? $row['asset_code'] : '',
				'gas_type'       => $row['gas_type'],
				'category'       => $row['category'],
				'faculty'        => $row['faculty'],
				'building'       => $row['building'],
				'floor'          => $row['floor'],
				'room'           => $row['room'],
				'location'       => $row['location'],
				'panel_number'   => $row['panel_number'],
				'work_pressure'  => $row['work_pressure'],
				'status'         => $row['status'],
				// Contact data only when the manager chose to show it on the
				// public page ("עיצוב עמוד סריקה"): hiding it in the UI alone
				// would still expose it to anyone calling this API.
				'pi_name'        => $show( 'showPI' ) ? $row['pi_name'] : '',
				'lab_managers'   => $show( 'showLabManager' ) ? $row['lab_managers'] : '',
				'maint_staff'    => $show( 'showMaintenance' ) ? $row['maint_staff'] : '',
				'phone'          => $show( 'showPhone' ) ? $row['phone'] : '',
				'next_quarterly' => $row['next_quarterly'],
				'last_quarterly' => $row['last_quarterly'],
				'open_faults'    => (int) $open_faults,
				'open_defects'   => (int) $open_defects,
				'qr_token'       => $row['qr_token'],
			)
		);
	}

	/**
	 * POST /scans — explicit scan log by equipment_id (optional path).
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function log_scan( $req ) {
		$body = $req->get_json_params();
		$eid  = isset( $body['equipment_id'] ) ? (int) $body['equipment_id'] : 0;
		if ( $eid ) {
			self::record_scan( $eid );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Insert a scan row with a hashed IP (no raw IP stored — Amendment 13).
	 *
	 * @param int $equipment_id Equipment ID.
	 * @return void
	 */
	private static function record_scan( $equipment_id ) {
		global $wpdb;
		$table   = $wpdb->prefix . 'gas_scans';
		$user_id = get_current_user_id();
		$wpdb->insert(
			$table,
			array(
				'equipment_id' => (int) $equipment_id,
				'scanned_at'   => current_time( 'mysql' ),
				'user_id'      => $user_id ? (int) $user_id : null,
				'ip_hash'      => GSM_Auth::hash_ip( GSM_Auth::client_ip() ),
			),
			array( '%d', '%s', '%d', '%s' )
		);
	}
}
