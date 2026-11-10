<?php
/**
 * REST controller: Migration.
 *
 * A one-time ingest endpoint used to import equipment from Monday.com via the
 * existing portal (browser Console), so no Monday API secret ever touches
 * WordPress.
 *
 * Security model:
 *   - Authorized by a single-use-ish "migration key" the admin generates and
 *     stores in an option. The key is sent in the X-GSM-Migrate header.
 *   - The key is compared with hash_equals (timing-safe).
 *   - Migration is idempotent: rows are matched by monday_id, so re-running
 *     updates instead of duplicating.
 *   - The admin disables migration (clears the key) when done.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Migration
 */
class GSM_REST_Migration {

	const NS = 'gas/v1';

	/**
	 * Register migration routes.
	 */
	public function register_routes() {
		// Allow the portal's cross-origin request (CORS) for migration only.
		add_action( 'rest_api_init', array( $this, 'send_cors_headers' ), 15 );

		register_rest_route(
			self::NS,
			'/migrate/equipment',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'ingest_equipment' ),
				'permission_callback' => array( $this, 'check_key' ),
			)
		);

		// Status endpoint: how many rows migrated so far.
		register_rest_route(
			self::NS,
			'/migrate/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'status' ),
				'permission_callback' => array( $this, 'check_key' ),
			)
		);
	}

	/**
	 * Permission: valid migration key in header, and migration enabled.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return bool|WP_Error
	 */
	public function check_key( $req ) {
		$stored = (string) get_option( 'gsm_migration_key', '' );
		if ( '' === $stored ) {
			return new WP_Error(
				'gsm_migration_disabled',
				__( 'Migration is not enabled. Generate a key in the admin first.', 'gas-safety-manager' ),
				array( 'status' => 403 )
			);
		}
		$provided = (string) $req->get_header( 'x-gsm-migrate' );
		if ( '' === $provided || ! hash_equals( $stored, $provided ) ) {
			return new WP_Error(
				'gsm_migration_forbidden',
				__( 'Invalid migration key.', 'gas-safety-manager' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * POST /migrate/equipment
	 *
	 * Accepts a batch: { items: [ { ...equipment fields, monday_id }, ... ] }
	 * Upserts each by monday_id. Returns per-item results.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function ingest_equipment( $req ) {
		$body  = $req->get_json_params();
		$items = isset( $body['items'] ) && is_array( $body['items'] ) ? $body['items'] : array();

		if ( empty( $items ) ) {
			return new WP_Error(
				'gsm_migration_empty',
				__( 'No items in payload.', 'gas-safety-manager' ),
				array( 'status' => 400 )
			);
		}

		// Cap batch size to keep requests bounded.
		if ( count( $items ) > 200 ) {
			return new WP_Error(
				'gsm_migration_too_big',
				__( 'Batch too large (max 200). Split into smaller batches.', 'gas-safety-manager' ),
				array( 'status' => 400 )
			);
		}

		$created = 0;
		$updated = 0;
		$skipped = 0;
		$errors  = array();

		foreach ( $items as $i => $raw ) {
			$monday_id = isset( $raw['monday_id'] ) ? sanitize_text_field( (string) $raw['monday_id'] ) : '';
			if ( '' === $monday_id ) {
				$skipped++;
				$errors[] = array( 'index' => $i, 'error' => 'missing monday_id' );
				continue;
			}

			$existing = GSM_Equipment_Repo::get_by_monday_id( $monday_id );

			if ( $existing ) {
				// Update existing (idempotent re-run). Keep its qr_token.
				$res = GSM_Equipment_Service::update( (int) $existing['id'], $raw );
				if ( is_wp_error( $res ) ) {
					$errors[] = array( 'index' => $i, 'monday_id' => $monday_id, 'error' => $res->get_error_message() );
				} else {
					$updated++;
				}
			} else {
				// Create new. Service assigns a fresh QR token.
				$res = GSM_Equipment_Service::create( $raw );
				if ( is_wp_error( $res ) ) {
					$errors[] = array( 'index' => $i, 'monday_id' => $monday_id, 'error' => $res->get_error_message() );
				} else {
					$created++;
				}
			}
		}

		return rest_ensure_response(
			array(
				'created' => $created,
				'updated' => $updated,
				'skipped' => $skipped,
				'errors'  => $errors,
				'total_in_batch' => count( $items ),
			)
		);
	}

	/**
	 * GET /migrate/status — total equipment rows now in WP.
	 *
	 * @return WP_REST_Response
	 */
	public function status() {
		$total = GSM_Equipment_Repo::count( array() );
		return rest_ensure_response( array( 'equipment_total' => (int) $total ) );
	}

	/**
	 * Send permissive CORS headers for the migration endpoints only, so the
	 * existing portal (a different origin) can POST equipment during the
	 * one-time import. Scoped to /migrate/* and only while a key is set.
	 */
	public function send_cors_headers() {
		// Only act on our migration routes.
		$route = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		if ( false === strpos( $route, '/gas/v1/migrate' ) ) {
			return;
		}
		// Only while migration is enabled (a key exists).
		if ( '' === (string) get_option( 'gsm_migration_key', '' ) ) {
			return;
		}

		$origin = isset( $_SERVER['HTTP_ORIGIN'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$allowed = array(
			'https://app.safety-tech.dev',
			'https://s.safety-tech.dev',
			'https://safety-qr-app.pages.dev',
		);
		if ( in_array( $origin, $allowed, true ) ) {
			header( 'Access-Control-Allow-Origin: ' . $origin );
			header( 'Access-Control-Allow-Methods: POST, GET, OPTIONS' );
			header( 'Access-Control-Allow-Headers: Content-Type, X-GSM-Migrate' );
			header( 'Access-Control-Allow-Credentials: true' );
			header( 'Vary: Origin' );
		}

		// Answer the preflight OPTIONS request immediately.
		if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'OPTIONS' === $_SERVER['REQUEST_METHOD'] ) {
			status_header( 200 );
			exit;
		}
	}
}
