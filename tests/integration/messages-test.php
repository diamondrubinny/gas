<?php
/**
 * Field messages + feedback: permissions, isolation between users,
 * validation, rate limit, retention, privacy anonymization, uninstall list.
 * Run: wp eval-file tests/integration/messages-test.php
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 1 );
}
global $wpdb;
$tb   = $wpdb->prefix . 'gas_messages';
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
$tag    = strtolower( wp_generate_password( 6, false ) );
$admins = get_users( array( 'role' => 'administrator', 'number' => 1, 'fields' => 'ID' ) );
$admin  = (int) $admins[0];
$mk     = function ( $n, $role ) use ( $tag ) {
	return wp_insert_user( array( 'user_login' => $n . '-' . $tag, 'user_email' => $n . '-' . $tag . '@example.test', 'user_pass' => wp_generate_password(), 'role' => $role, 'display_name' => 'Msg ' . $n . ' ' . $tag ) );
};
$a = $mk( 'ma', 'gsm_ahmat' );
$b = $mk( 'mb', 'gsm_pi' );

try {
	$t( 'DB v10+: table exists', (int) get_option( 'gsm_db_version' ) >= 10 && null !== $wpdb->get_var( "SELECT COUNT(*) FROM {$tb}" ) );

	wp_set_current_user( 0 );
	$t( 'logged out: list and post refused (401)', 401 === $rest( 'GET', '/gas/v1/field-messages' )->get_status() && 401 === $rest( 'POST', '/gas/v1/feedback', array( 'text' => 'x' ) )->get_status() );

	// --- Field messages -----------------------------------------------------
	wp_set_current_user( $a );
	$t( 'empty message rejected', 400 === $rest( 'POST', '/gas/v1/field-messages', array( 'text' => "  \n " ) )->get_status() );
	$r   = $rest( 'POST', '/gas/v1/field-messages', array( 'text' => "דליפה ליד פאנל 3 {$tag}\n<script>alert(1)</script>", 'status' => 'read', 'user_id' => $admin ) );
	$fid = (int) $r->get_data()['id'];
	$row = GSM_Messages::get( 'field', $fid );
	$t( 'field message stored: author = sender, unread, tags stripped', 200 === $r->get_status() && (int) $row['user_id'] === $a && 'unread' === $row['status'] && false === strpos( $row['text'], '<script' ) && false !== strpos( $row['text'], 'דליפה' ), wp_json_encode( $row ) );
	$t( 'text capped at 2000 chars', 2000 >= mb_strlen( GSM_Messages::get( 'field', (int) $rest( 'POST', '/gas/v1/field-messages', array( 'text' => str_repeat( 'א', 5000 ) ) )->get_data()['id'] )['text'] ) );
	$mine = $rest( 'GET', '/gas/v1/field-messages' )->get_data();
	$t( 'sender sees own messages, no manager data', 2 === count( $mine['items'] ) && false === $mine['manage'] && ! isset( $mine['counts'] ) );
	$t( 'sender cannot mark read / delete / clear (403)', 403 === $rest( 'POST', '/gas/v1/field-messages/' . $fid . '/read' )->get_status() && 403 === $rest( 'DELETE', '/gas/v1/field-messages/' . $fid )->get_status() && 403 === $rest( 'POST', '/gas/v1/field-messages/clear', array() )->get_status() );

	wp_set_current_user( $b );
	$other = $rest( 'GET', '/gas/v1/field-messages' )->get_data();
	$t( 'another user does not see them', 0 === count( $other['items'] ) );

	wp_set_current_user( $admin );
	$all = $rest( 'GET', '/gas/v1/field-messages' )->get_data();
	$ids = wp_list_pluck( $all['items'], 'id' );
	$t( 'manager sees all + unread count', in_array( $fid, $ids, true ) && $all['manage'] && $all['counts']['field_unread'] >= 2 );
	$t( 'mark read', 200 === $rest( 'POST', '/gas/v1/field-messages/' . $fid . '/read' )->get_status() && 'read' === GSM_Messages::get( 'field', $fid )['status'] && '' !== GSM_Messages::get( 'field', $fid )['handled_by'] );
	$t( 'feedback route cannot touch a field message (404)', 404 === $rest( 'POST', '/gas/v1/feedback/' . $fid . '/status', array( 'status' => 'done' ) )->get_status() && 404 === $rest( 'DELETE', '/gas/v1/feedback/' . $fid )->get_status() );

	// --- Feedback -------------------------------------------------------------
	wp_set_current_user( $b );
	$r    = $rest( 'POST', '/gas/v1/feedback', array( 'text' => 'כפתור הייצוא לא עובד ' . $tag, 'title' => 'ייצוא ' . $tag, 'type' => 'nope', 'priority' => 'critical', 'area' => 'דוחות', 'status' => 'done' ) );
	$bid  = (int) $r->get_data()['id'];
	$row  = GSM_Messages::get( 'feedback', $bid );
	$t( 'feedback stored: open, invalid type -> bug, priority kept', 'open' === $row['status'] && 'bug' === $row['fb_type'] && 'critical' === $row['priority'] && 'דוחות' === $row['area'] );
	$t( 'author cannot change status (403)', 403 === $rest( 'POST', '/gas/v1/feedback/' . $bid . '/status', array( 'status' => 'done' ) )->get_status() );
	wp_set_current_user( $a );
	$t( 'another user does not see the feedback', 0 === count( $rest( 'GET', '/gas/v1/feedback' )->get_data()['items'] ) );
	wp_set_current_user( $admin );
	$t( 'invalid status rejected', 400 === $rest( 'POST', '/gas/v1/feedback/' . $bid . '/status', array( 'status' => 'hacked' ) )->get_status() );
	$t( 'manager sets in_progress', 200 === $rest( 'POST', '/gas/v1/feedback/' . $bid . '/status', array( 'status' => 'in_progress' ) )->get_status() && 'in_progress' === GSM_Messages::get( 'feedback', $bid )['status'] );
	$t( 'active feedback counted', $rest( 'GET', '/gas/v1/feedback' )->get_data()['counts']['feedback_active'] >= 1 );
	wp_set_current_user( $b );
	$own = $rest( 'GET', '/gas/v1/feedback' )->get_data()['items'];
	$t( 'author sees the new status', 1 === count( $own ) && 'in_progress' === $own[0]['status'] && true === $own[0]['mine'] );

	// --- Rate limit -------------------------------------------------------------
	set_transient( 'gsm_msg_rate_' . $b, GSM_Messages::RATE_PER_HR, HOUR_IN_SECONDS );
	$t( 'rate limit (429)', 429 === $rest( 'POST', '/gas/v1/field-messages', array( 'text' => 'x' ) )->get_status() );
	delete_transient( 'gsm_msg_rate_' . $b );

	// --- Privacy: the author's name is found and anonymized ---------------------
	wp_set_current_user( $admin );
	$name = 'Msg ma ' . $tag;
	$scan = GSM_Privacy_Requests::process( array( $name ), false, 'X' );
	$lbl  = wp_list_pluck( $scan['locations'], 'label' );
	$t( 'privacy scan covers messages', in_array( 'הודעות ומשוב — כותב', $lbl, true ), implode( ' | ', $lbl ) );
	GSM_Privacy_Requests::process( array( $name ), true, 'משתמש נמחק #T' );
	$t( 'privacy anonymize replaces the author', 'משתמש נמחק #T' === GSM_Messages::get( 'field', $fid )['author'] );

	// --- Retention: only handled, old rows ----------------------------------------
	$old = gmdate( 'Y-m-d H:i:s', strtotime( '-30 months' ) );
	$wpdb->update( $tb, array( 'created_at' => $old ), array( 'id' => $fid ) );          // read + old  -> deleted
	$wpdb->update( $tb, array( 'created_at' => $old ), array( 'id' => $bid ) );          // in_progress + old -> kept
	$t( 'retention target registered with a default', isset( GSM_Retention::targets()['messages'], GSM_Retention::defaults()['messages'] ) );
	GSM_Retention::run();
	$t( 'retention: old handled message deleted, old active feedback kept', null === GSM_Messages::get( 'field', $fid ) && null !== GSM_Messages::get( 'feedback', $bid ) );

	// --- Manager delete / clear -----------------------------------------------------
	$t( 'manager deletes feedback', 200 === $rest( 'DELETE', '/gas/v1/feedback/' . $bid )->get_status() && null === GSM_Messages::get( 'feedback', $bid ) );
	$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$tb} WHERE kind = 'field' AND user_id = %d", $a ) );
	$t( 'one unread field message still there', 1 === $left );
	$t( 'uninstall drops the table', false !== strpos( (string) file_get_contents( GSM_PLUGIN_DIR . 'uninstall.php' ), "'messages'" ) );
} finally {
	wp_set_current_user( $admin );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$tb} WHERE user_id IN (%d, %d)", $a, $b ) );
	require_once ABSPATH . 'wp-admin/includes/user.php';
	wp_delete_user( $a );
	wp_delete_user( $b );
	$wpdb->query( "DELETE FROM {$wpdb->prefix}gas_audit WHERE entity = 'message'" );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
if ( $fail ) {
	exit( 1 );
}
