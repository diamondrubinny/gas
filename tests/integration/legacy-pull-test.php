<?php
/**
 * Pull from the old system: settings validation, check (dry run), pull,
 * idempotent re-pull, mapping of all three shapes, quiet bulk mode, errors.
 * Starts its own mock of the old server (tests/integration/fixtures).
 * Run: wp eval-file tests/integration/legacy-pull-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
// The mock runs on 127.0.0.1: allow internal destinations in THIS process only.
define( 'GSM_ALLOW_INTERNAL_WEBHOOKS', true );

global $wpdb;
$p    = $wpdb->prefix . 'gas_';
$fail = 0;
$t    = function ( $name, $ok, $extra = '' ) use ( &$fail ) {
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok || '' === $extra ? '' : ' — ' . $extra ) . "\n";
	if ( ! $ok ) {
		$fail++;
	}
};
$rest = function ( $method, $route, $body = null ) {
	$r = new WP_REST_Request( $method, $route );
	if ( null !== $body ) {
		$r->set_header( 'content-type', 'application/json' );
		$r->set_body( wp_json_encode( $body ) );
	}
	return rest_do_request( $r );
};

// --- Mock server --------------------------------------------------------
$tag    = strtoupper( wp_generate_password( 4, false ) );
$tag    = preg_replace( '/[^A-Z0-9]/', 'X', $tag );
$port   = 18000 + wp_rand( 0, 999 );
$router = dirname( __FILE__ ) . '/fixtures/legacy-mock-router.php';
$tmp    = sys_get_temp_dir();
@unlink( $tmp . '/gsm-legacy-hooks.log' );
@unlink( $tmp . '/gsm-legacy-requests.log' );
$proc = proc_open( array( PHP_BINARY, '-S', '127.0.0.1:' . $port, $router ), array( 1 => array( 'file', '/dev/null', 'w' ), 2 => array( 'file', '/dev/null', 'w' ) ), $pipes, null, array( 'GSM_MOCK_TAG' => $tag ) );
for ( $i = 0; $i < 50; $i++ ) {
	$s = @fsockopen( '127.0.0.1', $port );
	if ( $s ) {
		fclose( $s );
		break;
	}
	usleep( 100000 );
}
$base = 'http://127.0.0.1:' . $port;

$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];
wp_set_current_user( $admin );

$saved_opts = array(
	'gsm_legacy'       => get_option( 'gsm_legacy', null ),
	'gsm_legacy_last'  => get_option( 'gsm_legacy_last', null ),
	'gsm_integrations' => get_option( 'gsm_integrations', null ),
	'gsm_comm'         => get_option( 'gsm_comm', null ),
);

try {
	// --- Settings validation --------------------------------------------
	$e = GSM_Legacy::save( array( 'base' => 'http://api.safety-tech.dev' ) );
	$t( 'save: http is rejected', is_wp_error( $e ) );
	$e = GSM_Legacy::save( array( 'base' => 'https://10.0.0.5' ) );
	$t( 'save: IP address is rejected', is_wp_error( $e ) );
	$e = GSM_Legacy::save( array( 'base' => 'https://api.safety-tech.dev', 'token' => 'short' ) );
	$t( 'save: malformed token is rejected', is_wp_error( $e ) );
	$ok = GSM_Legacy::save( array( 'base' => 'https://api.safety-tech.dev/portal?x=1', 'token' => 'abcdEFGH1234-_.~zz' ) );
	$t( 'save: valid base + token, base cleaned to origin', ! is_wp_error( $ok ) && 'https://api.safety-tech.dev' === $ok['base'] && $ok['configured'] );
	$t( 'save: token masked in the public config', ! is_wp_error( $ok ) && false === strpos( wp_json_encode( $ok ), 'abcdEFGH1234' ) );
	$ok = GSM_Legacy::save( array( 'base' => 'https://other-host.example.com' ) );
	$t( 'save: changing the host wipes the token', ! is_wp_error( $ok ) && ! $ok['configured'] && '' === GSM_Legacy::config()['token'] );
	$ok = GSM_Legacy::save( array( 'token' => 'https://api.safety-tech.dev/?bi=equipment&format=csv&token=PastedLinkToken_0123' ) );
	$t( 'save: a pasted BI link gives base + token', ! is_wp_error( $ok ) && 'https://api.safety-tech.dev' === $ok['base'] && 'PastedLinkToken_0123' === GSM_Legacy::config()['token'], is_wp_error( $ok ) ? $ok->get_error_message() : wp_json_encode( $ok ) );
	$e = GSM_Legacy::save( array( 'token' => 'https://api.safety-tech.dev/?bi=equipment' ) );
	$t( 'save: a link without a token is rejected', is_wp_error( $e ) );
	GSM_Legacy::save( array( 'base' => 'https://other-host.example.com' ) );
	$t( 'GET /legacy never contains the token', false === strpos( wp_json_encode( $rest( 'GET', '/gas/v1/legacy' )->get_data() ), 'abcdEFGH' ) );

	// Daily sync schedule follows the settings.
	GSM_Legacy::save( array( 'base' => 'https://api.safety-tech.dev', 'token' => 'abcdEFGH1234-_.~zz', 'auto' => true ) );
	$t( 'auto on: daily event scheduled', (bool) wp_next_scheduled( GSM_Legacy::CRON ) );
	GSM_Legacy::save( array( 'auto' => false ) );
	$t( 'auto off: daily event cleared', ! wp_next_scheduled( GSM_Legacy::CRON ) );

	// Permissions.
	$uid = wp_insert_user( array( 'user_login' => 'lp-' . strtolower( $tag ), 'user_email' => 'lp-' . strtolower( $tag ) . '@example.test', 'user_pass' => wp_generate_password(), 'role' => 'gsm_lab_manager' ) );
	wp_set_current_user( $uid );
	$t( 'non-manager: GET /legacy is 403', 403 === $rest( 'GET', '/gas/v1/legacy' )->get_status() );
	$t( 'non-manager: POST /legacy/pull is 403', 403 === $rest( 'POST', '/gas/v1/legacy/pull' )->get_status() );
	wp_set_current_user( $admin );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $uid );

	// Point at the mock (IP base is not allowed through save(): set directly).
	update_option( 'gsm_legacy', array( 'base' => $base, 'token' => 'GOOD-TOKEN-0123456789', 'auto' => false ), false );

	// Webhook + urgent alert observers.
	update_option( 'gsm_integrations', array( 'webhookUrl' => $base . '/hook', 'webhookSecret' => '', 'webhookEvents' => array() ), false );
	$comm = get_option( 'gsm_comm', array() );
	$comm = is_array( $comm ) ? $comm : array();
	update_option( 'gsm_comm', array_merge( $comm, array( 'alert_urgent' => true ) ), false );
	$mails = 0;
	add_filter( 'pre_wp_mail', function () use ( &$mails ) { $mails++; return true; } );

	// --- Check (dry run) --------------------------------------------------
	$cnt = function () use ( $wpdb, $p, $tag ) {
		return array(
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}equipment WHERE monday_id LIKE %s", '9100' . $tag . '%' ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}faults WHERE monday_id LIKE %s", '9200' . $tag . '%' ) ),
			(int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$p}inspections WHERE monday_id LIKE %s", '9300' . $tag . '%' ) ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}audit WHERE action = 'legacy'" ),
			(int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}restore_points" ),
		);
	};
	$before = $cnt();
	$r      = $rest( 'POST', '/gas/v1/legacy/check' );
	$d      = $r->get_data();
	$t( 'check: 200', 200 === $r->get_status(), wp_json_encode( $d ) );
	$t( 'check: writes nothing', $cnt() === $before, wp_json_encode( array( $before, $cnt() ) ) );
	$eq = $d['sets']['equipment'];
	$t( 'check: raw Monday shape — 2 valid, 1 without id', 3 === $eq['fetched'] && 2 === $eq['valid'] && 1 === $eq['no_id'] && 2 === $eq['would_create'], wp_json_encode( $eq ) );
	$t( 'check: unknown column reported, known-ignored not', in_array( 'עמודה מוזרה', $eq['unknown'], true ) && ! in_array( 'תקלות פתוחות', $eq['unknown'], true ), wp_json_encode( $eq['unknown'] ) );
	$t( 'check: Hebrew-title shape (faults) recognized', 2 === $d['sets']['faults']['valid'] && 1 === $d['sets']['faults']['unlinked'], wp_json_encode( $d['sets']['faults'] ) );
	$t( 'check: column-id shape (inspections) recognized', 1 === $d['sets']['inspections']['valid'] && 0 === $d['sets']['inspections']['unlinked'], wp_json_encode( $d['sets']['inspections'] ) );
	$t( 'check: audit — 1 new, 1 duplicate, 1 too old, 1 invalid', array( 'new' => 1, 'duplicates' => 1, 'too_old' => 1, 'invalid' => 1 ) === $d['sets']['audit']['audit'], wp_json_encode( $d['sets']['audit']['audit'] ) );

	// --- Pull --------------------------------------------------------------
	$own_audit_before = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$p}audit" );
	$alerts_before    = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}comm_log WHERE kind = 'alert'" );
	$r = $rest( 'POST', '/gas/v1/legacy/pull' );
	$d = $r->get_data();
	$t( 'pull: 200 + restore point', 200 === $r->get_status() && ! empty( $d['restore_point'] ), wp_json_encode( $d ) );
	$c = $cnt();
	$t( 'pull: created 2 equipment, 2 faults (1 skipped: no equipment)…', 2 === $c[0] && 1 === $c[1] && 1 === $c[2], wp_json_encode( $c ) );
	$t( 'pull: counts reported', 2 === $d['counts']['equipment']['created'] && 1 === $d['counts']['faults']['created'] && 1 === $d['counts']['faults']['skipped'] && 1 === $d['counts']['inspections']['created'], wp_json_encode( $d['counts'] ) );
	$t( 'pull: audit archive — 1 entry added', 1 === $c[3] - $before[3] && 1 === $d['counts']['audit']['new'] );
	$t( 'pull: a restore point was taken', $c[4] === $before[4] + 1 );

	$a = GSM_Equipment_Repo::get_by_monday_id( '9100' . $tag . '1' );
	$t( 'equipment fields mapped', $a && 'חנקן' === $a['gas_type'] && 'פיזיקה' === $a['building'] && '214' === $a['room'] && '3' === $a['panel_number'] && 'פרופ׳ כהן' === $a['pi_name'] && 'רון, רות' === $a['lab_managers'], wp_json_encode( $a ) );
	$t( 'equipment status + dates converted', $a && 'active' === $a['status'] && '2027-01-15' === $a['next_quarterly'] && '2026-10-15' === $a['last_quarterly'], $a ? $a['status'] . ' ' . $a['next_quarterly'] . ' ' . $a['last_quarterly'] : '' );
	$b2 = GSM_Equipment_Repo::get_by_monday_id( '9100' . $tag . '2' );
	$t( 'equipment B status "בתחזוקה" -> maintenance', $b2 && 'maintenance' === $b2['status'] );
	$t( 'purchase date + manual link pulled', $a && '2021-03-09' === $a['purchase_date'] && 'https://example.com/manual.pdf' === $a['manual_url'], $a ? $a['purchase_date'] . ' ' . $a['manual_url'] : '' );
	$t( 'non-https manual link dropped, row still imported', $b2 && '' === $b2['manual_url'] );
	$t( 'new equipment has a QR token', $a && '' !== $a['qr_token'] );

	$f = GSM_Faults_Repo::get_by_monday_id( '9200' . $tag . '1' );
	$t( 'fault linked via first name of a multi-link', $f && (int) $f['equipment_id'] === (int) $a['id'] );
	$t( 'fault status / priority / date converted', $f && 'in_progress' === $f['status'] && 'urgent' === $f['priority'] && '2026-10-20' === $f['opened_date'] && 'ריח של גז ליד הפאנל' === $f['description'] && 'דנה' === $f['reporter'], wp_json_encode( $f ) );

	$in = GSM_Inspections_Repo::get_by_monday_id( '9300' . $tag . '1' );
	$t( 'inspection linked via the name in its title', $in && (int) $in['equipment_id'] === (int) $b2['id'] );
	$t( 'inspection type / result / inspector', $in && 'quarterly' === $in['type'] && 'defects' === $in['result'] && 'יוסי' === $in['inspector'] && '2026-11-01' === $in['inspection_date'] );
	$items = $in ? GSM_Inspections_Repo::get_items( (int) $in['id'] ) : array();
	$res   = wp_list_pluck( $items, 'item_result' );
	$t( 'checklist parsed (✓ ✗ — [תקין], note line skipped)', array( 'ok', 'defect', 'na', 'ok' ) === array_values( $res ), wp_json_encode( $items ) );

	// Quiet bulk mode.
	$t( 'no urgent-fault alert for the imported urgent fault', 0 === $mails && $alerts_before === (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$p}comm_log WHERE kind = 'alert'" ), (string) $mails );
	$t( 'no webhook calls during the pull', ! file_exists( $tmp . '/gsm-legacy-hooks.log' ) );
	$new_audit = $wpdb->get_results( $wpdb->prepare( "SELECT action, entity, item_name FROM {$p}audit WHERE id > %d AND action <> 'legacy'", $own_audit_before ), ARRAY_A );
	$t( 'one summary audit entry per pull, no per-row entries', 2 === count( $new_audit ) && 'import' === $new_audit[0]['action'] && 'import' === $new_audit[1]['action'], wp_json_encode( $new_audit ) );
	$last = get_option( 'gsm_legacy_last' );
	$t( 'last run recorded (ok, manual)', is_array( $last ) && $last['ok'] && 'manual' === $last['mode'] );

	// Positive control: outside bulk mode the same fault DOES alert.
	$ctl = GSM_Faults_Service::create( array( 'equipment_id' => (int) $a['id'], 'description' => 'control ' . $tag, 'priority' => 'urgent' ) );
	$t( 'control: a normal urgent fault still alerts', $mails > 0 || file_exists( $tmp . '/gsm-legacy-hooks.log' ), (string) $mails );
	if ( ! is_wp_error( $ctl ) ) {
		$wpdb->delete( $p . 'faults', array( 'id' => (int) $ctl ) );
	}

	// --- Re-pull is idempotent ---------------------------------------------
	$wpdb->update( $p . 'equipment', array( 'building' => 'שונה ב-WP' ), array( 'id' => (int) $a['id'] ) );
	$r  = $rest( 'POST', '/gas/v1/legacy/pull' );
	$d2 = $r->get_data();
	$c2 = $cnt();
	$t( 're-pull: no duplicates', $c2[0] === 2 && $c2[1] === 1 && $c2[2] === 1 && $c2[3] === $c[3], wp_json_encode( $c2 ) );
	$t( 're-pull: everything updated, nothing created', 0 === $d2['counts']['equipment']['created'] && 2 === $d2['counts']['equipment']['updated'] && 0 === $d2['counts']['audit']['new'], wp_json_encode( $d2['counts'] ) );
	$t( 're-pull: old system wins for mapped fields', 'פיזיקה' === GSM_Equipment_Repo::get( (int) $a['id'] )['building'] );
	$t( 're-pull: QR token unchanged', GSM_Equipment_Repo::get( (int) $a['id'] )['qr_token'] === $a['qr_token'] );
	$t( 're-pull: empty old values do not blank fields', '' !== (string) GSM_Equipment_Repo::get( (int) $b2['id'] )['gas_type'] );

	// --- Errors --------------------------------------------------------------
	update_option( 'gsm_legacy', array( 'base' => $base, 'token' => 'WRONG-TOKEN-0123456789', 'auto' => false ), false );
	$snaps = $cnt()[4];
	$r     = $rest( 'POST', '/gas/v1/legacy/pull' );
	$t( 'bad token: clear error, no token in the message', 502 === $r->get_status() && 'gsm_legacy_auth' === $r->get_data()['code'] && false === strpos( wp_json_encode( $r->get_data() ), 'WRONG-TOKEN' ) );
	$t( 'bad token: nothing written, no restore point', $cnt()[4] === $snaps );
	$last = get_option( 'gsm_legacy_last' );
	$t( 'bad token: last run recorded as failed', is_array( $last ) && ! $last['ok'] && '' !== $last['error'] );

	update_option( 'gsm_legacy', array( 'base' => $base, 'token' => 'BROKEN-JSON-012345678', 'auto' => false ), false );
	$r = $rest( 'POST', '/gas/v1/legacy/check' );
	$t( 'invalid JSON: error', 'gsm_legacy_json' === $r->get_data()['code'] );
	update_option( 'gsm_legacy', array( 'base' => $base, 'token' => 'ODD-SHAPE-0123456789', 'auto' => false ), false );
	$r = $rest( 'POST', '/gas/v1/legacy/pull' );
	$t( 'unrecognized shape: error, nothing written', 'gsm_legacy_shape' === $r->get_data()['code'] && $cnt()[4] === $snaps, wp_json_encode( $r->get_data() ) );

	update_option( 'gsm_legacy', array( 'base' => $base, 'token' => 'GOOD-TOKEN-0123456789', 'auto' => false ), false );
	set_transient( GSM_Legacy::LOCK, 1, 60 );
	$r = $rest( 'POST', '/gas/v1/legacy/pull' );
	$t( 'concurrent run refused (409)', 409 === $r->get_status() );
	delete_transient( GSM_Legacy::LOCK );

	// Without the test override, an internal base is blocked (SSRF guard).
	$t( 'SSRF guard: GET to 127.0.0.1 blocked without the override', ! GSM_Security::is_public_destination( $base ) );

	// Requests reached the mock with the token only in the query string.
	$log = (string) @file_get_contents( $tmp . '/gsm-legacy-requests.log' );
	$t( 'requests used ?bi=<set>&format=json', false !== strpos( $log, 'bi=equipment' ) && false !== strpos( $log, 'format=json' ) );
} finally {
	// Cleanup.
	foreach ( array( 'faults', 'inspections' ) as $tb ) {
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$p}{$tb} WHERE monday_id LIKE %s", ( 'faults' === $tb ? '9200' : '9300' ) . $tag . '%' ) );
		foreach ( $ids as $id ) {
			if ( 'inspections' === $tb ) {
				$wpdb->delete( $p . 'inspection_items', array( 'inspection_id' => (int) $id ) );
			}
			$wpdb->delete( $p . $tb, array( 'id' => (int) $id ) );
		}
	}
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}equipment WHERE monday_id LIKE %s", '9100' . $tag . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$p}audit WHERE action = 'legacy' AND summary LIKE %s", '%' . $wpdb->esc_like( $tag ) . '%' ) );
	foreach ( $wpdb->get_col( "SELECT id FROM {$p}restore_points WHERE label = 'לפני משיכה מהאתר הישן'" ) as $rp ) {
		GSM_Restore_Service::delete( (int) $rp );
	}
	foreach ( $saved_opts as $k => $v ) {
		if ( null === $v ) {
			delete_option( $k );
		} else {
			update_option( $k, $v, false );
		}
	}
	wp_clear_scheduled_hook( GSM_Legacy::CRON );
	delete_transient( GSM_Legacy::LOCK );
	if ( is_resource( $proc ) ) {
		proc_terminate( $proc );
		proc_close( $proc );
	}
	@unlink( $tmp . '/gsm-legacy-hooks.log' );
	@unlink( $tmp . '/gsm-legacy-requests.log' );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
