<?php
// Run: php tests/csv-reader-test.php plugins/gas-safety-manager
define( 'ABSPATH', '/tmp/' );
require $argv[1] . '/includes/class-gsm-spreadsheet.php';
$fail = 0;
$t = function ( $name, $ok, $extra = '' ) use ( &$fail ) { echo ( $ok ? 'PASS ' : 'FAIL ' ) . $name . ( $ok ? '' : ' — ' . $extra ) . "\n"; if ( ! $ok ) { $fail++; } };
$read = function ( $content ) {
	$f = tempnam( sys_get_temp_dir(), 'csv' );
	file_put_contents( $f, $content );
	$r = GSM_Spreadsheet::read( $f, 'csv' );
	unlink( $f );
	return $r;
};
// read() returns { headers: [...], rows: [[...], ...], truncated }. Rebuild a grid (header first).
$rows = function ( $r ) { return is_array( $r ) && isset( $r['headers'], $r['rows'] ) ? array_merge( array( $r['headers'] ), $r['rows'] ) : array(); };

// 1. Multi-line quoted cell stays one cell (was split into two rows).
$r = $read( "asset_code,name,contacts\r\nG-1,Panel,\"Dan 050\r\nRuth 052\"\r\nG-2,Other,x\r\n" );
$g = $rows( $r );
$t( 'multi-line quoted cell = one row', is_array( $r ) && 3 === count( $g ) && "Dan 050\nRuth 052" === $g[1][2] && 'G-2' === $g[2][0], json_encode( $r, JSON_UNESCAPED_UNICODE ) );
// 2. Hebrew UTF-8 with BOM, quotes and commas inside quotes.
$r = $read( "\xEF\xBB\xBFמספר,שם\nG-3,\"בניין 1, חדר \"\"א\"\"\"\n" );
$g = $rows( $r );
$t( 'UTF-8 BOM + escaped quotes + comma in cell', 'מספר' === $g[0][0] && 'בניין 1, חדר "א"' === $g[1][1], json_encode( $g, JSON_UNESCAPED_UNICODE ) );
// 3. Semicolon delimiter (Excel in some locales).
$g = $rows( $read( "a;b;c\n1;2;3\n" ) );
$t( 'semicolon delimiter detected', 3 === count( $g[1] ) && '3' === $g[1][2], json_encode( $g ) );
// 4. Blank lines ignored.
$g = $rows( $read( "a,b\n\n1,2\n\n" ) );
$t( 'blank lines ignored', 2 === count( $g ), json_encode( $g ) );
// 5. Windows-1255 Hebrew converted.
$g = $rows( $read( iconv( 'UTF-8', 'CP1255', "שם,חדר\nפאנל,12\n" ) ) );
$t( 'Windows-1255 Hebrew converted', 'פאנל' === $g[1][0], json_encode( $g, JSON_UNESCAPED_UNICODE ) );
// 6. Backslash is data, not an escape (RFC 4180).
$g = $rows( $read( "p\n\"C:\\\\dir\\\\\"\n" ) );
$t( 'backslash kept as data', 'C:\\\\dir\\\\' === $g[1][0], json_encode( $g ) );
// 7. XLSX: cells without the optional "r" attribute are read in order.
if ( class_exists( 'ZipArchive' ) ) {
	$x = tempnam( sys_get_temp_dir(), 'xl' );
	$z = new ZipArchive();
	$z->open( $x, ZipArchive::OVERWRITE );
	$z->addFromString( '[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>' );
	$z->addFromString( 'xl/workbook.xml', '<?xml version="1.0"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="S" sheetId="1" r:id="rId1"/></sheets></workbook>' );
	$z->addFromString( 'xl/_rels/workbook.xml.rels', '<?xml version="1.0"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>' );
	$z->addFromString( 'xl/worksheets/sheet1.xml', '<?xml version="1.0"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>'
		. '<row><c t="inlineStr"><is><t>a</t></is></c><c t="inlineStr"><is><t>b</t></is></c><c r="D1" t="inlineStr"><is><t>d</t></is></c></row>'
		. '<row><c t="inlineStr"><is><t>1</t></is></c><c t="inlineStr"><is><t>2</t></is></c><c t="inlineStr"><is><t>3</t></is></c><c t="inlineStr"><is><t>4</t></is></c></row>'
		. '</sheetData></worksheet>' );
	$z->close();
	$r = GSM_Spreadsheet::read( $x, 'xlsx' );
	unlink( $x );
	$g = $rows( $r );
	$t( 'XLSX cells without r attribute kept in order', isset( $g[0][0], $g[0][1], $g[0][3], $g[1][3] ) && 'a' === $g[0][0] && 'b' === $g[0][1] && 'd' === $g[0][3] && '4' === $g[1][3], json_encode( $g ) );
}
echo ( $fail ? $fail . ' failed' : 'all passed' ) . "\n";
exit( $fail ? 1 : 0 );
