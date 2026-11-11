<?php
/**
 * REST controller: User management (manager-only).
 *
 * Manage the staff who use the gas system — WordPress users carrying a gas
 * role. Create, change role, activate/deactivate, and send a password reset.
 * No PINs or password hashes are ever exposed (WordPress handles passwords).
 *
 *   GET    /gas/v1/users
 *   POST   /gas/v1/users              { name, email, role, password? }
 *   PUT    /gas/v1/users/{id}         { name?, role?, active? }
 *   POST   /gas/v1/users/{id}/reset   — email a password-reset link
 *
 * @package GasSafetyManager
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_REST_Users
 */
class GSM_REST_Users {

	const NS = 'gas/v1';

	/**
	 * role_key => WordPress role slug (assignable from the app).
	 *
	 * @return array<string,string>
	 */
	public static function role_map() {
		return array(
			'manager'     => 'gsm_manager',
			'lab_manager' => 'gsm_lab_manager',
			'pi'          => 'gsm_pi',
			'ahmat'       => 'gsm_ahmat',
		);
	}

	/**
	 * Is this a user the gas app manages? Only accounts holding a gas role
	 * (and not a WordPress administrator) may be edited or reset from the app;
	 * other site users (editors, shop managers, subscribers…) are off-limits.
	 *
	 * @param WP_User $user User.
	 * @return bool
	 */
	public static function is_app_user( $user ) {
		if ( ! $user instanceof WP_User || user_can( $user, 'administrator' ) ) {
			return false;
		}
		return (bool) array_intersect( (array) $user->roles, array_values( self::role_map() ) );
	}

	/**
	 * role_key => Hebrew label.
	 *
	 * @return array<string,string>
	 */
	public static function role_labels() {
		return array(
			'manager'     => 'מנהל',
			'lab_manager' => 'מנהל מעבדה',
			'pi'          => 'חוקר ראשי',
			'ahmat'       => 'אחמ"ת',
			'user'        => 'משתמש',
		);
	}

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NS,
			'/users',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_users' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_user' ),
					'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)',
			array(
				'methods'             => WP_REST_Server::EDITABLE,
				'callback'            => array( $this, 'update_user' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
		register_rest_route(
			self::NS,
			'/users/(?P<id>\d+)/reset',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'reset_password' ),
				'permission_callback' => array( 'GSM_Auth', 'require_manage' ),
			)
		);
	}

	/**
	 * Shape a user for the API.
	 *
	 * @param WP_User $u User.
	 * @return array
	 */
	private function shape( $u ) {
		$rk     = GSM_Auth::role_key( $u );
		$labels = self::role_labels();
		$last   = get_user_meta( $u->ID, 'gsm_last_login', true );
		return array(
			'id'         => (int) $u->ID,
			'name'       => $u->display_name,
			'email'      => $u->user_email,
			'role_key'   => $rk,
			'role_label' => isset( $labels[ $rk ] ) ? $labels[ $rk ] : $rk,
			'is_admin'   => user_can( $u, 'administrator' ),
			'active'     => GSM_Auth::is_active( $u ),
			'last_login' => $last ? $last : '',
		);
	}

	/**
	 * GET /users — list staff (gas roles + administrators).
	 *
	 * @return WP_REST_Response
	 */
	public function list_users() {
		$roles = array_values( self::role_map() );
		$roles[] = 'administrator';
		$users = get_users(
			array(
				'role__in' => $roles,
				'orderby'  => 'display_name',
				'order'    => 'ASC',
				'number'   => 1000,
			)
		);
		$out = array();
		foreach ( $users as $u ) {
			$out[] = $this->shape( $u );
		}
		return rest_ensure_response( $out );
	}

	/**
	 * POST /users — create a staff user.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_user( $req ) {
		$body  = $req->get_json_params();
		$name  = isset( $body['name'] ) ? sanitize_text_field( (string) $body['name'] ) : '';
		$email = isset( $body['email'] ) ? sanitize_email( (string) $body['email'] ) : '';
		$rkey  = isset( $body['role'] ) ? (string) $body['role'] : '';
		$pass  = isset( $body['password'] ) ? (string) $body['password'] : '';

		$map = self::role_map();
		if ( '' === $name || '' === $email || ! isset( $map[ $rkey ] ) ) {
			return new WP_Error( 'gsm_user_invalid', __( 'שם, אימייל ותפקיד חובה.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'gsm_user_email', __( 'אימייל לא תקין.', 'gas-safety-manager' ), array( 'status' => 400 ) );
		}
		if ( email_exists( $email ) || username_exists( $email ) ) {
			return new WP_Error( 'gsm_user_exists', __( 'משתמש עם אימייל זה כבר קיים.', 'gas-safety-manager' ), array( 'status' => 409 ) );
		}

		$send_reset = false;
		if ( '' === $pass ) {
			$pass       = wp_generate_password( 16, true );
			$send_reset = true;
		}

		$uid = wp_insert_user(
			array(
				'user_login'   => $email,
				'user_email'   => $email,
				'display_name' => $name,
				'first_name'   => $name,
				'user_pass'    => $pass,
				'role'         => $map[ $rkey ],
			)
		);
		if ( is_wp_error( $uid ) ) {
			return new WP_Error( 'gsm_user_create', $uid->get_error_message(), array( 'status' => 400 ) );
		}
		update_user_meta( $uid, 'gsm_active', '1' );

		if ( $send_reset ) {
			$this->send_reset_email( get_user_by( 'id', $uid ) );
		}

		return rest_ensure_response( $this->shape( get_user_by( 'id', $uid ) ) );
	}

	/**
	 * PUT /users/{id} — update name / role / active.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_user( $req ) {
		$id   = (int) $req['id'];
		$user = get_user_by( 'id', $id );
		if ( ! $user ) {
			return new WP_Error( 'gsm_user_404', __( 'משתמש לא נמצא.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		// Never let the app modify a WordPress administrator.
		if ( user_can( $user, 'administrator' ) ) {
			return new WP_Error( 'gsm_user_admin', __( 'לא ניתן לשנות מנהל אתר (WordPress) מכאן.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}
		// Only gas-app users: other site accounts are not the app's to change.
		if ( ! self::is_app_user( $user ) ) {
			return new WP_Error( 'gsm_user_foreign', __( 'משתמש זה אינו משתמש של מערכת הגזים.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}
		// Don't let a manager change their own role/active (anti-lockout).
		if ( $id === get_current_user_id() ) {
			return new WP_Error( 'gsm_user_self', __( 'לא ניתן לשנות את החשבון שלך מכאן.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}

		$body = $req->get_json_params();

		if ( isset( $body['name'] ) && '' !== trim( (string) $body['name'] ) ) {
			wp_update_user( array( 'ID' => $id, 'display_name' => sanitize_text_field( (string) $body['name'] ) ) );
		}
		if ( isset( $body['role'] ) ) {
			$map = self::role_map();
			$rk  = (string) $body['role'];
			if ( isset( $map[ $rk ] ) ) {
				// Swap only the gas role; any other role the account has stays.
				foreach ( array_values( $map ) as $gas_role ) {
					if ( $gas_role !== $map[ $rk ] && in_array( $gas_role, (array) $user->roles, true ) ) {
						$user->remove_role( $gas_role );
					}
				}
				if ( ! in_array( $map[ $rk ], (array) $user->roles, true ) ) {
					$user->add_role( $map[ $rk ] );
				}
			}
		}
		if ( isset( $body['active'] ) ) {
			$active = ! empty( $body['active'] );
			update_user_meta( $id, 'gsm_active', $active ? '1' : '0' );
			if ( ! $active && class_exists( 'WP_Session_Tokens' ) ) {
				// Log the user out everywhere, immediately.
				WP_Session_Tokens::get_instance( $id )->destroy_all();
			}
		}

		return rest_ensure_response( $this->shape( get_user_by( 'id', $id ) ) );
	}

	/**
	 * POST /users/{id}/reset — email a password reset link.
	 *
	 * @param WP_REST_Request $req Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function reset_password( $req ) {
		$id   = (int) $req['id'];
		$user = get_user_by( 'id', $id );
		if ( ! $user ) {
			return new WP_Error( 'gsm_user_404', __( 'משתמש לא נמצא.', 'gas-safety-manager' ), array( 'status' => 404 ) );
		}
		if ( ! self::is_app_user( $user ) ) {
			return new WP_Error( 'gsm_user_foreign', __( 'משתמש זה אינו משתמש של מערכת הגזים.', 'gas-safety-manager' ), array( 'status' => 403 ) );
		}
		$ok = $this->send_reset_email( $user );
		if ( is_wp_error( $ok ) ) {
			return $ok;
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Build + send a password-reset email.
	 *
	 * @param WP_User $user User.
	 * @return true|WP_Error
	 */
	private function send_reset_email( $user ) {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return new WP_Error( 'gsm_reset_key', __( 'יצירת קישור איפוס נכשלה.', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}
		$reset_url = network_site_url( 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login ), 'login' );
		$site      = get_bloginfo( 'name' );
		$subject   = sprintf( /* translators: %s site name */ __( '[%s] הגדרת סיסמה למערכת הגזים', 'gas-safety-manager' ), $site );
		$msg       = __( 'שלום,', 'gas-safety-manager' ) . "\n\n"
			. __( 'נוצר עבורך חשבון במערכת ניהול הגזים. להגדרת הסיסמה:', 'gas-safety-manager' ) . "\n"
			. $reset_url . "\n\n"
			. __( 'אם לא ציפית למייל זה, התעלם ממנו.', 'gas-safety-manager' );
		$sent = wp_mail( $user->user_email, $subject, $msg );
		if ( ! $sent ) {
			return new WP_Error( 'gsm_reset_mail', __( 'שליחת המייל נכשלה. ודא שהוגדר שרת דוא"ל (SMTP).', 'gas-safety-manager' ), array( 'status' => 500 ) );
		}
		return true;
	}
}
