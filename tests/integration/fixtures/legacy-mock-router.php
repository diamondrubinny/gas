<?php
/**
 * Mock of the old system's BI export (api.safety-tech.dev) for tests.
 * Run: php -S 127.0.0.1:PORT tests/integration/fixtures/legacy-mock-router.php
 *
 * GET /?bi=<set>&format=json&token=<token>
 *   token GOOD-TOKEN-0123456789 -> data, each set in a different shape
 *   token BROKEN-JSON-012345678 -> invalid JSON
 *   token ODD-SHAPE-0123456789  -> JSON with no recognizable rows
 *   anything else               -> 401
 * POST /hook -> records the webhook call in hooks.log next to this file.
 */

$dir = sys_get_temp_dir();
if ( 'POST' === $_SERVER['REQUEST_METHOD'] && 0 === strpos( $_SERVER['REQUEST_URI'], '/hook' ) ) {
	file_put_contents( $dir . '/gsm-legacy-hooks.log', date( 'c' ) . "\n", FILE_APPEND );
	echo 'ok';
	return true;
}
file_put_contents( $dir . '/gsm-legacy-requests.log', $_SERVER['REQUEST_URI'] . "\n", FILE_APPEND );

$token = isset( $_GET['token'] ) ? $_GET['token'] : '';
$set   = isset( $_GET['bi'] ) ? $_GET['bi'] : '';
$tag   = getenv( 'GSM_MOCK_TAG' ) ?: 'T';

if ( 'BROKEN-JSON-012345678' === $token ) {
	header( 'Content-Type: application/json' );
	echo '{"items": [ {"id": 1, ';
	return true;
}
if ( 'ODD-SHAPE-0123456789' === $token ) {
	header( 'Content-Type: application/json' );
	echo json_encode( array( 'ok' => true, 'count' => 3 ) );
	return true;
}
if ( 'GOOD-TOKEN-0123456789' !== $token ) {
	http_response_code( 401 );
	header( 'Content-Type: text/plain' );
	echo 'Unauthorized';
	return true;
}

header( 'Content-Type: application/json; charset=utf-8' );
$cv = function ( $id, $title, $text ) {
	return array( 'id' => $id, 'text' => $text, 'value' => null, 'column' => array( 'title' => $title ) );
};

switch ( $set ) {
	case 'equipment':
		// Raw Monday items (column_values).
		echo json_encode(
			array(
				'items' => array(
					array(
						'id'            => '9100' . $tag . '1',
						'name'          => 'מנדף גזים ' . $tag . ' A',
						'column_values' => array(
							$cv( 'text_mm3r2tt6', 'סוג הגז', 'חנקן' ),
							$cv( 'text_mm3r30xb', 'בניין', 'פיזיקה' ),
							$cv( 'text_mm3r2m6d', 'קומה', '2' ),
							$cv( 'text_mm2w71wv', "מס' חדר", '214' ),
							$cv( 'numeric_mm3snp0z', 'מספר פאנל', '3.0' ),
							$cv( 'board_relation_mm3ssfj6', 'חוקר ראשי (PI)', 'פרופ׳ כהן' ),
							$cv( 'board_relation_mm3sy4q0', 'מנהל מעבדה', 'רון, רות' ),
							$cv( 'color_mm2p6nk5', 'סטטוס', 'פעיל' ),
							$cv( 'date_mm3sjryd', 'תאריך ביקורת הבאה', '2027-01-15' ),
							$cv( 'date_mm2v49b4', 'ביקורת רבעונית', '15/10/2026' ),
							$cv( 'text_mm2pk11n', 'תקלות פתוחות', '1' ),
							$cv( 'text_zz_odd', 'עמודה מוזרה', 'x' ),
						),
					),
					array(
						'id'            => '9100' . $tag . '2',
						'name'          => 'ארון גזים ' . $tag . ' B',
						'column_values' => array(
							$cv( 'text_mm3r2tt6', 'סוג הגז', 'ארגון' ),
							$cv( 'color_mm2p6nk5', 'סטטוס', 'בתחזוקה' ),
							$cv( 'text_mm3rzbd5', 'הערות', '' ),
						),
					),
					array(
						'id'            => '',
						'name'          => 'בלי מזהה',
						'column_values' => array(),
					),
				),
			),
			JSON_UNESCAPED_UNICODE
		);
		break;
	case 'faults':
		// Flat objects keyed by Hebrew titles.
		echo json_encode(
			array(
				array(
					'id'          => '9200' . $tag . '1',
					'שם התקלה'    => 'דליפה',
					'ציוד'        => 'מנדף גזים ' . $tag . ' A, ציוד אחר',
					'מדווח'       => 'דנה',
					'תיאור תקלה'  => 'ריח של גז ליד הפאנל',
					'סטטוס'       => 'בטיפול',
					'עדיפות'      => 'דחופה',
					'תאריך פתיחה' => '2026-10-20',
				),
				array(
					'id'         => '9200' . $tag . '2',
					'שם התקלה'   => 'נורית תקולה',
					'ציוד'       => 'ציוד שלא קיים',
					'סטטוס'      => 'טופל',
					'עדיפות'     => 'נמוכה',
				),
			),
			JSON_UNESCAPED_UNICODE
		);
		break;
	case 'inspections':
		// Flat objects keyed by Monday column id.
		echo json_encode(
			array(
				'data' => array(
					array(
						'id'                      => '9300' . $tag . '1',
						'name'                    => 'ביקורת רבעונית - ארון גזים ' . $tag . ' B - 01.11.2026',
						'date_mm2pwq6d'           => '2026-11-01',
						'text_mm2pn2ct'           => 'יוסי',
						'color_mm2v1xt7'          => 'רבעונית',
						'color_mm2p37w3'          => 'ליקויים',
						'text_mm2p5b8d'           => 'נמצא ליקוי אחד',
						'text_mm2prgap'           => "✓ שלמות צנרת\n✗ שילוט חסר\n— מצלמה\n📝 הערה חופשית\nבדיקת לחץ [תקין]",
					),
				),
			),
			JSON_UNESCAPED_UNICODE
		);
		break;
	case 'audit':
		$recent = gmdate( 'Y-m-d', time() - 86400 ) . 'T08:30:00Z'; // Fixed per day, like a real log entry.
		echo json_encode(
			array(
				'entries' => array(
					array( 'timestamp' => $recent, 'user' => 'manager@example.test', 'action' => 'update', 'item' => 'מנדף ' . $tag, 'details' => 'עודכן סטטוס ' . $tag ),
					array( 'timestamp' => $recent, 'user' => 'manager@example.test', 'action' => 'update', 'item' => 'מנדף ' . $tag, 'details' => 'עודכן סטטוס ' . $tag ),
					array( 'timestamp' => '2010-01-01T00:00:00Z', 'user' => 'old@example.test', 'action' => 'create', 'item' => 'ישן', 'details' => 'ישן מאוד' ),
					array( 'timestamp' => 'not a date', 'user' => 'x', 'action' => 'x', 'item' => 'x', 'details' => 'x' ),
				),
			),
			JSON_UNESCAPED_UNICODE
		);
		break;
	default:
		http_response_code( 400 );
		echo json_encode( array( 'error' => 'unknown set' ) );
}
return true;
