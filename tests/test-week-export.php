<?php
/**
 * Stand-alone tests for Kivun_Board_Week_Export (the Excel file of [kivun_week]).
 * Run: php tests/test-week-export.php
 */

define( 'KIVUN_BOARD_TESTING', true );
require __DIR__ . '/../kivun-outlook-board/includes/class-week-export.php';

$failures = 0;

function check( $label, $expected, $actual ) {
	global $failures;
	if ( $expected === $actual ) {
		echo "ok   - $label\n";
		return;
	}
	$failures++;
	echo "FAIL - $label\n     expected: " . var_export( $expected, true ) . "\n     actual:   " . var_export( $actual, true ) . "\n";
}

/**
 * Reads the archive back with a decoder written from the ZIP spec, so the test does not
 * depend on ZipArchive (and checks the sizes and CRCs the writer stored).
 *
 * @param string $zip Archive.
 * @return string[]|string Files by name, or an error message.
 */
function unzip( $zip ) {
	$eocd = strrpos( $zip, "PK\x05\x06" );
	if ( false === $eocd ) {
		return 'no end of central directory';
	}
	$end   = unpack( 'Vsig/vdisk/vcdisk/vcount/vtotal/Vsize/Voffset/vcomment', substr( $zip, $eocd, 22 ) );
	$files = array();
	$pos   = $end['offset'];
	for ( $i = 0; $i < $end['count']; $i++ ) {
		$c = unpack( 'Vsig/vmade/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen/vclen/vdisk/viattr/Vxattr/Voffset', substr( $zip, $pos, 46 ) );
		if ( 0x02014b50 !== $c['sig'] ) {
			return "bad central header $i";
		}
		$name = substr( $zip, $pos + 46, $c['nlen'] );
		$l    = unpack( 'Vsig/vneed/vflags/vmethod/vtime/vdate/Vcrc/Vcsize/Vusize/vnlen/vxlen', substr( $zip, $c['offset'], 30 ) );
		if ( 0x04034b50 !== $l['sig'] || $l['crc'] !== $c['crc'] || $l['csize'] !== $c['csize'] ) {
			return "bad local header for $name";
		}
		$data = substr( $zip, $c['offset'] + 30 + $l['nlen'] + $l['xlen'], $c['csize'] );
		$data = 8 === $c['method'] ? gzinflate( $data ) : $data;
		if ( strlen( $data ) !== $c['usize'] || ( crc32( $data ) & 0xffffffff ) !== $c['crc'] ) {
			return "bad size or crc for $name";
		}
		$files[ $name ] = $data;
		$pos           += 46 + $c['nlen'] + $c['xlen'] + $c['clen'];
	}
	return $files;
}

$event = function ( $time, $title, $details = array() ) {
	return array(
		'time'    => $time,
		'title'   => $title,
		'details' => $details,
	);
};

$week = array(
	'title'    => 'מה הכיוון השבוע?',
	'subtitle' => 'לו״ז שבועי',
	'range'    => '01.11 – 05.11.2026',
	'rooms'    => array(
		array(
			'name' => 'מחשבים',
			'sub'  => '',
		),
		array(
			'name' => 'הר חוצבים',
			'sub'  => 'קומה 0',
		),
	),
	'days'     => array(),
	'colors'   => array(
		'red'  => '#df2950',
		'dark' => '#2e2e31',
		'sky'  => '#5cc8f0',
	),
);
foreach ( array( 'ראשון', 'שני', 'שלישי', 'רביעי', 'חמישי' ) as $i => $name ) {
	$week['days'][] = array(
		'date'       => sprintf( '2026-11-%02d', $i + 1 ),
		'day_name'   => $name,
		'date_label' => sprintf( '%02d.11', $i + 1 ),
		'heb'        => 0 === $i ? 'כ׳ בחשוון' : '',
		'cells'      => array( array(), array() ),
	);
}
$week['days'][0]['cells'][0] = array(
	$event( '08:00 – 12:00', 'סדנת אנגלית', array( 'מימון: משרד העבודה', '40 שעות' ) ),
	$event( '13:00 – 15:00', '=HYPERLINK("x") & <b>' ),
);
$week['days'][2]['cells'][1] = array( $event( 'כל היום', "בקרה\x0B\x01 שנתית" ) );

$xlsx  = Kivun_Board_Week_Export::build( $week );
$files = unzip( $xlsx );
check( 'zip: readable', true, is_array( $files ) );
if ( ! is_array( $files ) ) {
	echo "     $files\n";
	$files = array();
}
check(
	'zip: parts',
	array( '[Content_Types].xml', '_rels/.rels', 'xl/_rels/workbook.xml.rels', 'xl/styles.xml', 'xl/workbook.xml', 'xl/worksheets/sheet1.xml' ),
	( function ( $names ) {
		sort( $names );
		return $names;
	} )( array_keys( $files ) )
);

foreach ( $files as $name => $xml ) {
	$doc = new DOMDocument();
	check( "xml: $name is well formed", true, @$doc->loadXML( $xml ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
}

$sheet = isset( $files['xl/worksheets/sheet1.xml'] ) ? simplexml_load_string( $files['xl/worksheets/sheet1.xml'] ) : null;
$cells = array();
if ( $sheet ) {
	foreach ( $sheet->sheetData->row as $row ) {
		foreach ( $row->c as $c ) {
			$text = '';
			foreach ( $c->is->xpath( './/*[local-name()="t"]' ) as $t ) {
				$text .= (string) $t;
			}
			$cells[ (string) $c['r'] ] = $text;
		}
	}
}

check( 'sheet: right to left', '1', $sheet ? (string) $sheet->sheetViews->sheetView['rightToLeft'] : null );
check( 'sheet: title', 'מה הכיוון השבוע?', $cells['A1'] ?? null );
check( 'sheet: subtitle and dates', 'לו״ז שבועי · 01.11 – 05.11.2026', $cells['A2'] ?? null );
check( 'sheet: heading merged over the rooms', array( 'A1:C1', 'A2:C2' ), $sheet ? array_map( 'strval', $sheet->xpath( '//*[local-name()="mergeCell"]/@ref' ) ) : null );
check( 'sheet: room header with sub', "הר חוצבים\nקומה 0", $cells['C3'] ?? null );
check( 'sheet: day with Hebrew date', "יום ראשון\n01.11 · כ׳ בחשוון", $cells['A4'] ?? null );
check( 'sheet: day without Hebrew date', "יום חמישי\n05.11", $cells['A8'] ?? null );
check( 'sheet: classes with details, blank line between', "08:00 – 12:00\nסדנת אנגלית\nמימון: משרד העבודה\n40 שעות\n\n13:00 – 15:00\n=HYPERLINK(\"x\") & <b>", $cells['B4'] ?? null );
check( 'sheet: free room', 'פנוי', $cells['C4'] ?? null );
check( 'sheet: control characters dropped', "כל היום\nבקרה שנתית", $cells['C6'] ?? null );
check( 'sheet: 5 days below the 3 heading rows', 8, $sheet ? count( $sheet->sheetData->row ) : null );
check( 'sheet: text is never a formula', 0, $sheet ? count( $sheet->xpath( '//*[local-name()="f"]' ) ) : null );
check( 'workbook: Hebrew sheet name', true, false !== strpos( $files['xl/workbook.xml'] ?? '', 'name="לו״ז שבועי"' ) );
check( 'styles: colors from the settings', true, false !== strpos( $files['xl/styles.xml'] ?? '', 'FF2E2E31' ) && false !== strpos( $files['xl/styles.xml'] ?? '', 'FF5CC8F0' ) );

check( 'filename', 'לוח שבועי 01.11-05.11.2026.xlsx', Kivun_Board_Week_Export::filename( $week ) );
check( 'columns: A, Z, AA, AZ', array( 'A', 'Z', 'AA', 'AZ' ), array_map( array( 'Kivun_Board_Week_Export', 'column' ), array( 0, 25, 26, 51 ) ) );

// No rooms configured: only the day column, nothing merged.
$empty          = $week;
$empty['rooms'] = array();
$files          = unzip( Kivun_Board_Week_Export::build( $empty ) );
check( 'no rooms: still a valid sheet', true, is_array( $files ) && false !== simplexml_load_string( $files['xl/worksheets/sheet1.xml'] ) );
check( 'no rooms: nothing merged', false, is_array( $files ) && false !== strpos( $files['xl/worksheets/sheet1.xml'], 'mergeCell' ) );

echo $failures ? "\n$failures FAILED\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
