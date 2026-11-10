<?php
// Run: php tests/csv-injection-test.php plugins/gas-safety-manager
define( 'ABSPATH', '/tmp/' );
require $argv[1] . '/includes/class-gsm-security.php';
$cases = array(
	array( '=HYPERLINK("http://evil","x")', "'=HYPERLINK(\"http://evil\",\"x\")" ),
	array( '+cmd|\' /C calc\'!A0', "'+cmd|' /C calc'!A0" ),
	array( '-2+3+cmd|x', "'-2+3+cmd|x" ),
	array( '@SUM(A1)', "'@SUM(A1)" ),
	array( "\t=1+1", "'\t=1+1" ),
	array( "\r=1+1", "'\r=1+1" ),
	array( '-5', '-5' ),
	array( '+972501234567', '+972501234567' ),
	array( '3.14', '3.14' ),
	array( 'דליפת גז בחדר 101', 'דליפת גז בחדר 101' ),
	array( 'a=b', 'a=b' ),
	array( '', '' ),
	array( null, '' ),
	array( 42, '42' ),
);
$fail = 0;
foreach ( $cases as $i => $c ) {
	$got = GSM_Security::csv_cell( $c[0] );
	if ( $got !== $c[1] ) { $fail++; echo 'FAIL #' . $i . ': ' . var_export( $c[0], true ) . ' => ' . var_export( $got, true ) . "\n"; }
}
echo ( count( $cases ) - $fail ) . ' passed, ' . $fail . " failed\n";
exit( $fail ? 1 : 0 );
