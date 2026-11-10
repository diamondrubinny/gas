<?php
/**
 * REST controller: Equipment.
 *
 * Registers /gas/v1/equipment endpoints. Controllers are thin: they read the
 * request, delegate to GSM_Equipment_Service, and shape the response. All
 * authorization is via permission_callback (GSM_Auth). No SQL here.
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Equipment
 */
class GSM_REST_Equipment {

	const NS = 'gas/v1';

	/**
	 * Register routes. Called on rest_api_init.
	 */
	public function register_routes() {
		// Collection: list (GET) + create (POST).
		register_rest_route(
			self::NS,
			'/equipment',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_items' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
					'args'                => array(
						'search'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'building' => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'status'   => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'page'     => array(
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'per_page' => array(
							'default'           => 50,
							'sanitize_callback' => 'absint',
						),
						'orderby'  => array( 'sanitize_callback' => 'sanitize_text_field' ),
						'order'    => array( 'sanitize_callback' => 'sanitize_text_field' ),
					),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_edit' ),
				),
			)
		);

		// "My equipment" — items where the current user is PI / lab manager /
		// maintenance / contact (matched by their WP name or email).
		register_rest_route(
			self::NS,
			'/equipment/mine',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_mine' ),
				'permission_callback' => array( 'GSM_Auth', 'require_report' ),
			)
		);

		// Auto panel numbering for a group of equipment.
		register_rest_route(
			self::NS,
			'/equipment/assign-panels',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'assign_panels' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);

		// Contact library (people referenced on equipment) + bulk ops.
		register_rest_route(
			self::NS,
			'/contacts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_contacts' ),
				'permission_callback' => array( 'GSM_Auth', 'require_report' ),
			)
		);
		register_rest_route(
			self::NS,
			'/contacts/assign',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'assign_contact' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/contacts/replace',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'replace_contact' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);

		// Single item: get (GET) + update (PUT/PATCH) + delete (DELETE).
		register_rest_route(
			self::NS,
			'/equipment/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_report' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_edit' ),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);

		// Public read by QR token (for the bridge page). Token = authorization.
		register_rest_route(
			self::NS,
			'/equipment/by-token/(?P<token>[a-f0-9]{32})',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_by_token' ),
				'permission_callback' => '__return_true', // token itself is the gate
			)
		);
	}

	/**
	 * Normalize a string for case/space-insensitive matching.
	 *
	 * @param string $s Raw.
	 * @return string
	 */
	private static function norm( $s ) {
		$s = mb_strtolower( trim( (string) $s ) );
		return (string) preg_replace( '/\s+/u', ' ', $s );
	}

	/**
	 * GET /equipment/mine — equipment linked to the current user by name/email.
	 *
	 * @return WP_REST_Response
	 */
	public function list_mine() {
		$user    = wp_get_current_user();
		$name    = isset( $user->display_name ) ? (string) $user->display_name : '';
		$email   = strtolower( trim( isset( $user->user_email ) ? (string) $user->user_email : '' ) );
		$toks = array();
		foreach ( preg_split( '/\s+/u', trim( $name ) ) as $tok ) {
			$tok = self::norm( $tok );
			if ( mb_strlen( $tok ) >= 2 ) {
				$toks[ $tok ] = 1;
			}
		}
		$toks    = array_keys( $toks );
		$need    = min( 2, count( $toks ) ); // multi-word name needs >= 2 words; single name needs 1.
		$has_any = ( '' !== $email ) || ! empty( $toks );

		// Fields checked in priority order; first hit wins the role label.
		$fields = array( 'חוקר ראשי' => 'pi_name', 'מנהל מעבדה' => 'lab_managers', 'תחזוקה' => 'maint_staff', 'איש קשר' => 'contacts' );

		$items = array();
		if ( $has_any ) {
			$all = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'name', 'order' => 'ASC' ) );
			foreach ( (array) $all as $e ) {
				$role = '';
				foreach ( $fields as $label => $field ) {
					$val = self::norm( isset( $e[ $field ] ) ? $e[ $field ] : '' );
					if ( '' === $val ) {
						continue;
					}
					$hit = false;
					if ( '' !== $email && false !== mb_strpos( $val, $email ) ) {
						$hit = true; // email is distinctive — substring is fine.
					} elseif ( $toks ) {
						// A name matches only by WHOLE words (so "דן" never matches
						// "ירדן"); a multi-word name needs >= 2 of its words present,
						// separating people who merely share one name.
						$words   = array_flip( (array) preg_split( '/[^\p{L}\p{N}@._-]+/u', $val, -1, PREG_SPLIT_NO_EMPTY ) );
						$matched = 0;
						foreach ( $toks as $t ) {
							if ( isset( $words[ $t ] ) ) {
								$matched++;
							}
						}
						$hit = ( $need > 0 && $matched >= $need );
					}
					if ( $hit ) {
						$role = $label;
						break;
					}
				}
				if ( '' !== $role ) {
					$items[] = array(
						'id'             => (int) $e['id'],
						'asset_code'     => isset( $e['asset_code'] ) ? $e['asset_code'] : '',
						'name'           => isset( $e['name'] ) ? $e['name'] : '',
						'gas_type'       => isset( $e['gas_type'] ) ? $e['gas_type'] : '',
						'building'       => isset( $e['building'] ) ? $e['building'] : '',
						'room'           => isset( $e['room'] ) ? $e['room'] : '',
						'status'         => isset( $e['status'] ) ? $e['status'] : '',
						'next_quarterly' => isset( $e['next_quarterly'] ) ? $e['next_quarterly'] : '',
						'my_role'        => $role,
					);
				}
			}
		}
		return rest_ensure_response( array( 'items' => $items, 'count' => count( $items ) ) );
	}

	/**
	 * Role field map (role key => equipment column).
	 *
	 * @return array
	 */
	private static function role_fields() {
		return array( 'pi' => 'pi_name', 'lab' => 'lab_managers', 'maint' => 'maint_staff' );
	}

	/**
	 * Split a delimited people field into trimmed names.
	 *
	 * @param string $raw Raw value.
	 * @return array
	 */
	private static function split_names( $raw ) {
		$parts = preg_split( '/[,;\/\n]+/u', (string) $raw );
		$out   = array();
		foreach ( (array) $parts as $p ) {
			$p = trim( (string) $p );
			if ( '' !== $p ) {
				$out[] = $p;
			}
		}
		return $out;
	}

	/**
	 * POST /equipment/assign-panels — sequential panel numbers for a group.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function assign_panels( $req ) {
		$body      = $req->get_json_params();
		$body      = is_array( $body ) ? $body : array();
		$building  = isset( $body['building'] ) ? sanitize_text_field( (string) $body['building'] ) : '';
		$faculty   = isset( $body['faculty'] ) ? sanitize_text_field( (string) $body['faculty'] ) : '';
		$prefix    = isset( $body['prefix'] ) ? sanitize_text_field( (string) $body['prefix'] ) : '';
		$start     = isset( $body['start'] ) ? max( 0, (int) $body['start'] ) : 1;
		$overwrite = ! empty( $body['overwrite'] );

		$all     = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'name', 'order' => 'ASC' ) );
		$updated = 0;
		$skipped = 0;
		$n       = $start;
		foreach ( (array) $all as $e ) {
			if ( '' !== $building && (string) ( isset( $e['building'] ) ? $e['building'] : '' ) !== $building ) {
				continue;
			}
			if ( '' !== $faculty && (string) ( isset( $e['faculty'] ) ? $e['faculty'] : '' ) !== $faculty ) {
				continue;
			}
			$has = '' !== trim( (string) ( isset( $e['panel_number'] ) ? $e['panel_number'] : '' ) );
			if ( $has && ! $overwrite ) {
				$skipped++;
				continue;
			}
			$res = GSM_Equipment_Repo::update( (int) $e['id'], array( 'panel_number' => $prefix . $n ) );
			if ( ! is_wp_error( $res ) ) {
				$updated++;
				$n++;
			}
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'equipment', 0, '', 'מספור פאנל אוטומטי: עודכנו ' . $updated . ' פריטים' );
		}
		return rest_ensure_response( array( 'updated' => $updated, 'skipped' => $skipped ) );
	}

	/**
	 * GET /contacts — distinct people on equipment, by role, with counts.
	 *
	 * @return WP_REST_Response
	 */
	public function list_contacts() {
		$all    = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'name', 'order' => 'ASC' ) );
		$labels = array( 'pi' => 'חוקר ראשי', 'lab' => 'מנהל מעבדה', 'maint' => 'תחזוקה' );
		$map    = array();
		foreach ( (array) $all as $e ) {
			foreach ( self::role_fields() as $role => $field ) {
				foreach ( self::split_names( isset( $e[ $field ] ) ? $e[ $field ] : '' ) as $nm ) {
					$key = $role . '|' . $nm;
					if ( ! isset( $map[ $key ] ) ) {
						$map[ $key ] = array( 'role' => $role, 'role_label' => $labels[ $role ], 'name' => $nm, 'count' => 0 );
					}
					$map[ $key ]['count']++;
				}
			}
		}
		$out = array_values( $map );
		usort(
			$out,
			function ( $a, $b ) {
				return $b['count'] - $a['count'];
			}
		);
		return rest_ensure_response( $out );
	}

	/**
	 * POST /contacts/assign — set/add a person on matching equipment.
	 * Body: { role, name, building?, faculty? }. PI is set; lab/maint are added.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function assign_contact( $req ) {
		$body     = $req->get_json_params();
		$body     = is_array( $body ) ? $body : array();
		$role     = isset( $body['role'] ) ? (string) $body['role'] : '';
		$name     = isset( $body['name'] ) ? trim( sanitize_text_field( (string) $body['name'] ) ) : '';
		$building = isset( $body['building'] ) ? sanitize_text_field( (string) $body['building'] ) : '';
		$faculty  = isset( $body['faculty'] ) ? sanitize_text_field( (string) $body['faculty'] ) : '';
		$fields   = self::role_fields();
		if ( ! isset( $fields[ $role ] ) || '' === $name ) {
			return new WP_Error( 'gsm_contact_bad', __( 'חסר תפקיד או שם.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$field   = $fields[ $role ];
		$all     = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'name', 'order' => 'ASC' ) );
		$updated = 0;
		foreach ( (array) $all as $e ) {
			if ( '' !== $building && (string) ( isset( $e['building'] ) ? $e['building'] : '' ) !== $building ) {
				continue;
			}
			if ( '' !== $faculty && (string) ( isset( $e['faculty'] ) ? $e['faculty'] : '' ) !== $faculty ) {
				continue;
			}
			$cur = (string) ( isset( $e[ $field ] ) ? $e[ $field ] : '' );
			if ( 'pi' === $role ) {
				$newval = $name; // PI is a single value — set it.
			} else {
				$names = self::split_names( $cur );
				if ( in_array( $name, $names, true ) ) {
					continue; // already present.
				}
				$names[] = $name;
				$newval  = implode( ', ', $names );
			}
			if ( $newval === $cur ) {
				continue;
			}
			$res = GSM_Equipment_Repo::update( (int) $e['id'], array( $field => $newval ) );
			if ( ! is_wp_error( $res ) ) {
				$updated++;
			}
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'equipment', 0, '', 'שיוך איש קשר (' . $role . '): ' . $name . ' ל-' . $updated . ' פריטים' );
		}
		return rest_ensure_response( array( 'updated' => $updated ) );
	}

	/**
	 * POST /contacts/replace — rename/remove a person across all equipment.
	 * Body: { role, from, to }. Empty "to" removes the person.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function replace_contact( $req ) {
		$body   = $req->get_json_params();
		$body   = is_array( $body ) ? $body : array();
		$role   = isset( $body['role'] ) ? (string) $body['role'] : '';
		$from   = isset( $body['from'] ) ? trim( sanitize_text_field( (string) $body['from'] ) ) : '';
		$to     = isset( $body['to'] ) ? trim( sanitize_text_field( (string) $body['to'] ) ) : '';
		$fields = self::role_fields();
		if ( ! isset( $fields[ $role ] ) || '' === $from ) {
			return new WP_Error( 'gsm_contact_bad', __( 'חסר תפקיד או שם מקור.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		$field   = $fields[ $role ];
		$all     = GSM_Equipment_Repo::list( array( 'limit' => 100000, 'offset' => 0, 'orderby' => 'name', 'order' => 'ASC' ) );
		$updated = 0;
		foreach ( (array) $all as $e ) {
			$cur = (string) ( isset( $e[ $field ] ) ? $e[ $field ] : '' );
			if ( '' === $cur ) {
				continue;
			}
			$names   = self::split_names( $cur );
			$changed = false;
			$result  = array();
			foreach ( $names as $nm ) {
				if ( $nm === $from ) {
					$changed = true;
					if ( '' !== $to && ! in_array( $to, $result, true ) && ! in_array( $to, $names, true ) ) {
						$result[] = $to;
					} elseif ( '' !== $to && ! in_array( $to, $result, true ) ) {
						$result[] = $to;
					}
				} else {
					$result[] = $nm;
				}
			}
			if ( $changed ) {
				$newval = implode( ', ', array_values( array_unique( $result ) ) );
				$res    = GSM_Equipment_Repo::update( (int) $e['id'], array( $field => $newval ) );
				if ( ! is_wp_error( $res ) ) {
					$updated++;
				}
			}
		}
		if ( class_exists( 'GSM_Audit' ) ) {
			GSM_Audit::log( 'update', 'equipment', 0, '', 'החלפת איש קשר (' . $role . '): ' . $from . ' → ' . ( '' !== $to ? $to : '(הוסר)' ) . ' ב-' . $updated . ' פריטים' );
		}
		return rest_ensure_response( array( 'updated' => $updated ) );
	}

	/**
	 * GET /equipment
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response
	 */
	public function list_items( $req ) {
		$page     = max( 1, (int) $req->get_param( 'page' ) );
		// Capability-gated internal tool (sticker sheet / full registry view),
		// so allow a large page. Still bounded to keep the query sane.
		$per_page = min( 2000, max( 1, (int) $req->get_param( 'per_page' ) ) );

		$result = GSM_Equipment_Service::list(
			array(
				'search'   => (string) $req->get_param( 'search' ),
				'building' => (string) $req->get_param( 'building' ),
				'status'   => (string) $req->get_param( 'status' ),
				'limit'    => $per_page,
				'offset'   => ( $page - 1 ) * $per_page,
				'orderby'  => (string) $req->get_param( 'orderby' ),
				'order'    => (string) $req->get_param( 'order' ),
			)
		);

		$response = rest_ensure_response( $result['items'] );
		$response->header( 'X-WP-Total', (int) $result['total'] );
		$response->header( 'X-WP-TotalPages', (int) ceil( $result['total'] / $per_page ) );
		return $response;
	}

	/**
	 * GET /equipment/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $req ) {
		$item = GSM_Equipment_Service::get( (int) $req['id'] );
		if ( is_wp_error( $item ) ) {
			return $item;
		}
		return rest_ensure_response( $item );
	}

	/**
	 * GET /equipment/by-token/{token} — public bridge read.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_by_token( $req ) {
		$row = GSM_Equipment_Repo::get_by_token( (string) $req['token'] );
		if ( ! $row ) {
			return new WP_Error(
				'gsm_not_found',
				__( 'Equipment not found.', 'gas-safety-manager' ),
				array( 'status' => 404 )
			);
		}
		// Public view: expose a safe subset (no internal notes / monday_id).
		$public = array(
			'id'            => (int) $row['id'],
			'name'          => $row['name'],
			'gas_type'      => $row['gas_type'],
			'category'      => $row['category'],
			'faculty'       => $row['faculty'],
			'building'      => $row['building'],
			'floor'         => $row['floor'],
			'room'          => $row['room'],
			'location'      => $row['location'],
			'panel_number'  => $row['panel_number'],
			'work_pressure' => $row['work_pressure'],
			'status'        => $row['status'],
			'pi_name'       => $row['pi_name'],
			'lab_managers'  => $row['lab_managers'],
			'maint_staff'   => $row['maint_staff'],
			'phone'         => $row['phone'],
			'next_quarterly'=> $row['next_quarterly'],
			'last_quarterly'=> $row['last_quarterly'],
		);
		return rest_ensure_response( $public );
	}

	/**
	 * POST /equipment
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $req ) {
		$input = $req->get_json_params();
		if ( empty( $input ) ) {
			$input = $req->get_params();
		}
		$id = GSM_Equipment_Service::create( $input );
		if ( is_wp_error( $id ) ) {
			return $id;
		}
		$item = GSM_Equipment_Service::get( $id );
		$response = rest_ensure_response( $item );
		$response->set_status( 201 );
		return $response;
	}

	/**
	 * PUT/PATCH /equipment/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $req ) {
		$input = $req->get_json_params();
		if ( empty( $input ) ) {
			$input = $req->get_params();
		}
		$ok = GSM_Equipment_Service::update( (int) $req['id'], $input );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		$item = GSM_Equipment_Service::get( (int) $req['id'] );
		return rest_ensure_response( $item );
	}

	/**
	 * DELETE /equipment/{id}
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $req ) {
		$ok = GSM_Equipment_Service::delete( (int) $req['id'] );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return rest_ensure_response( array( 'deleted' => true, 'id' => (int) $req['id'] ) );
	}
}
