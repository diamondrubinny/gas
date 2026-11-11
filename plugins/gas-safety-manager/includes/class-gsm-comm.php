<?php
/**
 * Communication engine — reminders, alerts, broadcast (email + optional WhatsApp).
 *
 * Config-driven and safe by default: email goes through wp_mail() (the admin
 * configures SMTP with any standard WP SMTP plugin — no SMTP secret is stored
 * here). WhatsApp is OFF unless an endpoint is configured; its token lives in
 * an option, is never sent to the browser, and is never logged.
 *
 * @package GasSafetyManager
 * @author  Diamond Rubinny
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class GSM_Comm
 */
class GSM_Comm {

	const OPT      = 'gsm_comm';
	const CRON     = 'gsm_daily_reminders';
	const LAST_RUN = 'gsm_comm_last_reminder';

	/**
	 * Default config.
	 *
	 * @return array
	 */
	public static function defaults() {
		return array(
			'from_name'         => get_bloginfo( 'name' ),
			'reminder_enabled'  => false,
			'alert_urgent'      => true,
			'whatsapp_enabled'  => false,
			'whatsapp_url'      => '',
			'whatsapp_token'    => '',
			'whatsapp_alert_to' => '',
		);
	}

	/**
	 * Full config (incl. secret). Server-side only.
	 *
	 * @return array
	 */
	public static function config() {
		$c = get_option( self::OPT, array() );
		return array_merge( self::defaults(), is_array( $c ) ? $c : array() );
	}

	/**
	 * Public config for the browser — secrets masked.
	 *
	 * @return array
	 */
	public static function public_config() {
		$c = self::config();
		unset( $c['whatsapp_token'] );
		$c['whatsapp_has_token'] = '' !== (string) self::config()['whatsapp_token'];
		return $c;
	}

	/**
	 * Save config (keeps the stored token when the client sends none).
	 *
	 * @param array $in Input.
	 * @return array public config.
	 */
	public static function save( $in ) {
		$cur = self::config();
		$in  = is_array( $in ) ? $in : array();
		// WhatsApp gateway: https only (the bearer token travels with each call).
		if ( isset( $in['whatsapp_url'] ) && '' !== trim( (string) $in['whatsapp_url'] ) && ! preg_match( '#^https://#i', trim( (string) $in['whatsapp_url'] ) ) ) {
			$in['whatsapp_url'] = '';
		}
		$out = array(
			'from_name'         => isset( $in['from_name'] ) ? sanitize_text_field( (string) $in['from_name'] ) : $cur['from_name'],
			'reminder_enabled'  => ! empty( $in['reminder_enabled'] ),
			'alert_urgent'      => ! empty( $in['alert_urgent'] ),
			'whatsapp_enabled'  => ! empty( $in['whatsapp_enabled'] ),
			'whatsapp_url'      => isset( $in['whatsapp_url'] ) ? esc_url_raw( (string) $in['whatsapp_url'] ) : $cur['whatsapp_url'],
			'whatsapp_alert_to' => isset( $in['whatsapp_alert_to'] ) ? self::norm_phone( (string) $in['whatsapp_alert_to'] ) : $cur['whatsapp_alert_to'],
			// Only overwrite the token when a non-empty new one is provided.
			'whatsapp_token'    => ( isset( $in['whatsapp_token'] ) && '' !== trim( (string) $in['whatsapp_token'] ) ) ? trim( (string) $in['whatsapp_token'] ) : $cur['whatsapp_token'],
		);
		// Explicit request to wipe the stored token.
		if ( ! empty( $in['whatsapp_clear'] ) ) {
			$out['whatsapp_token'] = '';
		}
		// The stored token must never follow the URL to a different host: when
		// the host changes, keep the token only if a new one was entered now.
		$new_token = isset( $in['whatsapp_token'] ) && '' !== trim( (string) $in['whatsapp_token'] );
		if ( ! $new_token && '' !== $cur['whatsapp_token']
			&& GSM_Security::url_host( $out['whatsapp_url'] ) !== GSM_Security::url_host( $cur['whatsapp_url'] ) ) {
			$out['whatsapp_token'] = '';
		}
		update_option( self::OPT, $out );
		self::sync_cron();
		return self::public_config();
	}

	/**
	 * Schedule / unschedule the daily reminder cron to match config.
	 */
	public static function sync_cron() {
		$enabled = self::config()['reminder_enabled'];
		$next    = wp_next_scheduled( self::CRON );
		if ( $enabled && ! $next ) {
			wp_schedule_event( time() + 3600, 'daily', self::CRON );
		} elseif ( ! $enabled && $next ) {
			wp_unschedule_event( $next, self::CRON );
		}
	}

	// --- sending ------------------------------------------------------------

	/**
	 * Send an email via wp_mail.
	 *
	 * @param string|array $to      Recipient(s).
	 * @param string       $subject Subject.
	 * @param string       $body    Plain body.
	 * @return bool
	 */
	public static function send_email( $to, $subject, $body ) {
		$from    = self::config()['from_name'];
		$headers = array();
		if ( $from ) {
			$headers[] = 'From: ' . $from . ' <' . get_bloginfo( 'admin_email' ) . '>';
		}
		return (bool) wp_mail( $to, $subject, $body, $headers );
	}

	/**
	 * Send a WhatsApp message through the configured webhook (if enabled).
	 *
	 * @param string $phone Destination phone.
	 * @param string $msg   Message.
	 * @return bool
	 */
	public static function send_whatsapp( $phone, $msg ) {
		$c = self::config();
		if ( empty( $c['whatsapp_enabled'] ) || '' === $c['whatsapp_url'] || '' === $c['whatsapp_token'] ) {
			return false;
		}
		$phone = self::norm_phone( $phone );
		if ( '' === $phone ) {
			return false;
		}
		$args = array(
			'timeout'  => 8,
			'blocking' => true,
			'headers'  => array(
				'Content-Type'  => 'application/json',
				'Authorization' => 'Bearer ' . $c['whatsapp_token'],
			),
			'body'     => wp_json_encode( array( 'to' => $phone, 'message' => $msg ) ),
		);
		// Integrators can adapt to their WhatsApp API shape.
		$args = apply_filters( 'gsm_whatsapp_request', $args, $phone, $msg, $c );
		$res  = GSM_Security::outbound_post( $c['whatsapp_url'], $args );
		$ok   = ! is_wp_error( $res ) && (int) wp_remote_retrieve_response_code( $res ) < 300;
		return $ok;
	}

	/**
	 * Normalize an Israeli phone to international digits (best effort).
	 *
	 * @param string $p Raw.
	 * @return string
	 */
	public static function norm_phone( $p ) {
		$p = preg_replace( '/\D+/', '', (string) $p );
		if ( '' === $p ) {
			return '';
		}
		if ( 0 === strpos( $p, '972' ) ) {
			return $p;
		}
		if ( 0 === strpos( $p, '0' ) ) {
			return '972' . substr( $p, 1 );
		}
		return $p;
	}

	// --- audiences ----------------------------------------------------------

	/**
	 * Emails of all managers (gsm_manager + site admins).
	 *
	 * @return array
	 */
	public static function manager_emails() {
		$emails = array();
		foreach ( get_users( array( 'role__in' => array( 'gsm_manager', 'administrator' ), 'fields' => array( 'ID', 'user_email' ) ) ) as $u ) {
			if ( ! empty( $u->user_email ) && GSM_Auth::is_active( (int) $u->ID ) ) {
				$emails[] = $u->user_email;
			}
		}
		return array_values( array_unique( array_filter( $emails ) ) );
	}

	/**
	 * Notify all managers by email (+ WhatsApp alert number if set).
	 *
	 * @param string $subject Subject.
	 * @param string $body    Body.
	 * @return void
	 */
	public static function notify_managers( $subject, $body ) {
		$to = self::manager_emails();
		if ( $to ) {
			$ok = self::send_email( $to, $subject, $body );
			self::log( 'alert', 'email', implode( ',', $to ), $subject, $ok ? 'sent' : 'failed' );
		}
		$wa = self::config()['whatsapp_alert_to'];
		if ( $wa ) {
			$ok = self::send_whatsapp( $wa, $subject . "\n" . $body );
			self::log( 'alert', 'whatsapp', $wa, $subject, $ok ? 'sent' : 'failed' );
		}
	}

	/**
	 * Alert on a new urgent fault.
	 *
	 * @param int    $equipment_id Equipment id.
	 * @param string $desc         Fault description.
	 * @return void
	 */
	public static function on_urgent_fault( $equipment_id, $desc ) {
		if ( empty( self::config()['alert_urgent'] ) ) {
			return;
		}
		$subject = '[בטיחות גזים] תקלה דחופה חדשה';
		$body    = "דווחה תקלה דחופה חדשה.\nמזהה ציוד: " . (int) $equipment_id . "\nתיאור: " . wp_strip_all_tags( (string) $desc ) . "\n\nהודעה אוטומטית מהמערכת.";
		self::notify_managers( $subject, $body );
	}

	/**
	 * Broadcast a message to an audience (email + optional WhatsApp per user).
	 *
	 * @param string $audience all|managers|lab_managers|pi|ahmat.
	 * @param string $subject  Subject.
	 * @param string $message  Message.
	 * @return array { email, whatsapp }
	 */
	public static function broadcast( $audience, $subject, $message ) {
		$roles = array(
			'managers'     => array( 'gsm_manager' ),
			'lab_managers' => array( 'gsm_lab_manager' ),
			'pi'           => array( 'gsm_pi' ),
			'ahmat'        => array( 'gsm_ahmat' ),
			'all'          => array( 'gsm_manager', 'gsm_lab_manager', 'gsm_pi', 'gsm_ahmat' ),
		);
		$want  = isset( $roles[ $audience ] ) ? $roles[ $audience ] : $roles['all'];
		$users = get_users( array( 'role__in' => $want ) );
		$em    = 0;
		$wa    = 0;
		foreach ( $users as $u ) {
			if ( ! GSM_Auth::is_active( $u ) ) {
				continue; // Deactivated accounts receive nothing.
			}
			if ( ! empty( $u->user_email ) && self::send_email( $u->user_email, $subject, $message ) ) {
				$em++;
			}
			$phone = (string) get_user_meta( $u->ID, 'gsm_phone', true );
			if ( $phone && self::send_whatsapp( $phone, $subject . "\n" . $message ) ) {
				$wa++;
			}
		}
		self::log( 'broadcast', 'email', $audience, $subject, 'sent', 'n=' . $em );
		if ( $wa ) {
			self::log( 'broadcast', 'whatsapp', $audience, $subject, 'sent', 'n=' . $wa );
		}
		return array( 'email' => $em, 'whatsapp' => $wa );
	}

	// --- reminders (cron) ---------------------------------------------------

	/**
	 * Daily reminder job: digest of upcoming/overdue quarterly inspections.
	 *
	 * @return void
	 */
	public static function run_reminders() {
		if ( empty( self::config()['reminder_enabled'] ) ) {
			return;
		}
		// Run at most once per calendar day.
		$today = gmdate( 'Y-m-d' );
		if ( get_option( self::LAST_RUN ) === $today ) {
			return;
		}
		update_option( self::LAST_RUN, $today );

		$cfg    = get_option( 'gsm_inspection_config', array() );
		$before = ( is_array( $cfg ) && isset( $cfg['rules']['reminderBeforeDays'] ) ) ? max( 0, (int) $cfg['rules']['reminderBeforeDays'] ) : 7;
		$limit  = gmdate( 'Y-m-d', time() + $before * DAY_IN_SECONDS );

		global $wpdb;
		$t = $wpdb->prefix . 'gas_equipment';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT asset_code, name, building, pi_name, next_quarterly FROM {$t} WHERE next_quarterly IS NOT NULL AND next_quarterly <> '' AND next_quarterly <= %s ORDER BY next_quarterly ASC", $limit ), ARRAY_A );
		if ( empty( $rows ) ) {
			return;
		}
		$lines = array();
		foreach ( $rows as $r ) {
			$state   = ( $r['next_quarterly'] < $today ) ? 'באיחור' : 'מתקרב';
			$lines[] = sprintf( '• %s — %s (%s) · %s · PI: %s · יעד: %s', $r['asset_code'], $r['name'], $r['building'], $state, $r['pi_name'], $r['next_quarterly'] );
		}
		$subject = sprintf( '[בטיחות גזים] %d ביקורות רבעוניות לטיפול', count( $lines ) );
		$body    = "להלן ציוד שהביקורת הרבעונית שלו מתקרבת או באיחור:\n\n" . implode( "\n", $lines ) . "\n\nהודעה אוטומטית יומית מהמערכת.";
		self::notify_managers( $subject, $body );
	}

	// --- log ----------------------------------------------------------------

	/**
	 * Write a communication log row.
	 *
	 * @param string $kind      reminder|alert|broadcast.
	 * @param string $channel   email|whatsapp.
	 * @param string $recipient Recipient summary.
	 * @param string $subject   Subject.
	 * @param string $status    sent|failed.
	 * @param string $ref       Extra ref.
	 * @return void
	 */
	public static function log( $kind, $channel, $recipient, $subject, $status, $ref = '' ) {
		global $wpdb;
		$wpdb->insert(
			$wpdb->prefix . 'gas_comm_log',
			array(
				'kind'       => substr( (string) $kind, 0, 20 ),
				'channel'    => substr( (string) $channel, 0, 20 ),
				'recipient'  => substr( (string) $recipient, 0, 255 ),
				'subject'    => substr( (string) $subject, 0, 255 ),
				'status'     => substr( (string) $status, 0, 20 ),
				'ref'        => substr( (string) $ref, 0, 255 ),
				'created_at' => current_time( 'mysql' ),
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
	}

	/**
	 * Recent log rows.
	 *
	 * @param int $limit Max.
	 * @return array
	 */
	public static function recent_log( $limit = 50 ) {
		global $wpdb;
		$t = $wpdb->prefix . 'gas_comm_log';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT kind, channel, recipient, subject, status, ref, created_at FROM {$t} ORDER BY id DESC LIMIT %d", max( 1, (int) $limit ) ), ARRAY_A );
		return $rows ? $rows : array();
	}
}
