<?php
/**
 * Stand-alone tests for Kivun_Board_ICS_Parser. Run: php tests/test-ics-parser.php
 */

define( 'KIVUN_BOARD_TESTING', true );
require __DIR__ . '/../kivun-outlook-board/includes/class-ics-parser.php';

$failures = 0;
$tz       = new DateTimeZone( 'Asia/Jerusalem' );
$parser   = new Kivun_Board_ICS_Parser( $tz );

function check( $label, $expected, $actual ) {
	global $failures;
	if ( $expected === $actual ) {
		echo "ok   - $label\n";
		return;
	}
	$failures++;
	echo "FAIL - $label\n     expected: " . var_export( $expected, true ) . "\n     actual:   " . var_export( $actual, true ) . "\n";
}

function ts( $s ) {
	return ( new DateTimeImmutable( $s, new DateTimeZone( 'Asia/Jerusalem' ) ) )->getTimestamp();
}

function starts( $events ) {
	return array_map(
		function ( $e ) {
			return ( new DateTimeImmutable( '@' . $e['start'] ) )->setTimezone( new DateTimeZone( 'Asia/Jerusalem' ) )->format( 'Y-m-d H:i' );
		},
		$events
	);
}

function cal( $body ) {
	return "BEGIN:VCALENDAR\r\nVERSION:2.0\r\nPRODID:Microsoft Exchange Server 2010\r\n"
		. "BEGIN:VTIMEZONE\r\nTZID:Israel Standard Time\r\nBEGIN:STANDARD\r\nDTSTART:16010101T020000\r\nTZOFFSETFROM:+0300\r\nTZOFFSETTO:+0200\r\nEND:STANDARD\r\nEND:VTIMEZONE\r\n"
		. str_replace( "\n", "\r\n", trim( $body ) ) . "\r\nEND:VCALENDAR\r\n";
}

$from = ts( '2026-10-01 00:00' );
$to   = ts( '2026-11-15 00:00' );

// 1. Simple event with Windows TZID, folded summary, escaped chars.
$ics = cal(
	<<<ICS
BEGIN:VEVENT
UID:one
SUMMARY:קורס הנדסת\, קומות
 - מתקדם
LOCATION:יועץ משה
DTSTART;TZID=Israel Standard Time:20261011T180000
DTEND;TZID=Israel Standard Time:20261011T220000
BEGIN:VALARM
TRIGGER:-PT15M
ACTION:DISPLAY
DESCRIPTION:Reminder
END:VALARM
END:VEVENT
ICS
);
$ev = $parser->parse( $ics, $from, $to );
check( 'single: count', 1, count( $ev ) );
check( 'single: summary unfolded + unescaped', 'קורס הנדסת, קומות- מתקדם', $ev[0]['title'] );
check( 'single: start', ts( '2026-10-11 18:00' ), $ev[0]['start'] );
check( 'single: end', ts( '2026-10-11 22:00' ), $ev[0]['end'] );
check( 'single: location', 'יועץ משה', $ev[0]['location'] );

// 2. Weekly series (Sun+Tue) with EXDATE, a moved instance and a cancelled instance; crosses DST end (2026-10-25).
$ics = cal(
	<<<ICS
BEGIN:VEVENT
UID:series
SUMMARY:מכינה
LOCATION:גבעת רם
RRULE:FREQ=WEEKLY;UNTIL=20261103T150000Z;INTERVAL=1;BYDAY=SU,TU;WKST=SU
EXDATE;TZID=Israel Standard Time:20261013T163000
DTSTART;TZID=Israel Standard Time:20261004T163000
DTEND;TZID=Israel Standard Time:20261004T213000
END:VEVENT
BEGIN:VEVENT
UID:series
RECURRENCE-ID;TZID=Israel Standard Time:20261018T163000
SUMMARY:מכינה (הוזז)
LOCATION:גבעת רם
DTSTART;TZID=Israel Standard Time:20261019T090000
DTEND;TZID=Israel Standard Time:20261019T120000
END:VEVENT
BEGIN:VEVENT
UID:series
RECURRENCE-ID;TZID=Israel Standard Time:20261020T163000
STATUS:CANCELLED
SUMMARY:מכינה
DTSTART;TZID=Israel Standard Time:20261020T163000
DTEND;TZID=Israel Standard Time:20261020T213000
END:VEVENT
ICS
);
$ev = $parser->parse( $ics, $from, $to );
check(
	'weekly: instances, exdate, override, cancel, DST',
	array( '2026-10-04 16:30', '2026-10-06 16:30', '2026-10-11 16:30', '2026-10-19 09:00', '2026-10-25 16:30', '2026-10-27 16:30', '2026-11-01 16:30', '2026-11-03 16:30' ),
	starts( $ev )
);
check( 'weekly: override title', 'מכינה (הוזז)', $ev[3]['title'] );
check( 'weekly: duration kept after DST', 5 * 3600, $ev[4]['end'] - $ev[4]['start'] );

// 3. COUNT + INTERVAL=2 weekly.
$ics = cal(
	<<<ICS
BEGIN:VEVENT
UID:biweekly
SUMMARY:סדנה
DTSTART;TZID=Asia/Jerusalem:20261001T090000
DURATION:PT2H
RRULE:FREQ=WEEKLY;INTERVAL=2;COUNT=3
END:VEVENT
ICS
);
$ev = $parser->parse( $ics, $from, $to );
check( 'biweekly count', array( '2026-10-01 09:00', '2026-10-15 09:00', '2026-10-29 09:00' ), starts( $ev ) );
check( 'duration property', 7200, $ev[0]['end'] - $ev[0]['start'] );

// 4. COUNT counts instances before the window too.
$ics = cal(
	<<<ICS
BEGIN:VEVENT
UID:daily
SUMMARY:יומי
DTSTART:20260928T060000Z
DTEND:20260928T070000Z
RRULE:FREQ=DAILY;COUNT=5
END:VEVENT
ICS
);
$ev = $parser->parse( $ics, $from, $to );
check( 'daily count spans window start (UTC times)', array( '2026-10-01 09:00', '2026-10-02 09:00' ), starts( $ev ) );

// 5. Monthly: second Tuesday, and last weekday via BYSETPOS.
$ics = cal(
	<<<ICS
BEGIN:VEVENT
UID:m1
SUMMARY:חודשי
DTSTART;TZID=Israel Standard Time:20260908T100000
DTEND;TZID=Israel Standard Time:20260908T110000
RRULE:FREQ=MONTHLY;BYDAY=2TU
END:VEVENT
BEGIN:VEVENT
UID:m2
SUMMARY:סוף חודש
DTSTART;TZID=Israel Standard Time:20260930T100000
DTEND;TZID=Israel Standard Time:20260930T110000
RRULE:FREQ=MONTHLY;BYDAY=SU,MO,TU,WE,TH;BYSETPOS=-1
END:VEVENT
ICS
);
$ev = $parser->parse( $ics, $from, $to );
check( 'monthly byday ordinal + bysetpos', array( '2026-10-13 10:00', '2026-10-29 10:00', '2026-11-10 10:00' ), starts( $ev ) );

// 6. All-day event and unknown TZID falls back to the default zone.
$ics = cal(
	<<<ICS
BEGIN:VEVENT
UID:allday
SUMMARY:חג
DTSTART;VALUE=DATE:20261012
DTEND;VALUE=DATE:20261013
END:VEVENT
BEGIN:VEVENT
UID:custom
SUMMARY:זמן מותאם
DTSTART;TZID="Customized Time Zone":20261014T080000
DTEND;TZID="Customized Time Zone":20261014T090000
CLASS:PRIVATE
END:VEVENT
ICS
);
$ev = $parser->parse( $ics, $from, $to );
check( 'all-day flag', true, $ev[0]['all_day'] );
check( 'all-day span', ts( '2026-10-13 00:00' ) - ts( '2026-10-12 00:00' ), $ev[0]['end'] - $ev[0]['start'] );
check( 'unknown tzid -> default zone', ts( '2026-10-14 08:00' ), $ev[1]['start'] );
check( 'private flag', true, $ev[1]['private'] );

// 7. Outlook display-name TZID and an event outside the window.
$ics = cal(
	<<<ICS
BEGIN:VEVENT
UID:disp
SUMMARY:תצוגה
DTSTART;TZID="(UTC+02:00) Jerusalem":20261020T080000
DTEND;TZID="(UTC+02:00) Jerusalem":20261020T090000
END:VEVENT
BEGIN:VEVENT
UID:old
SUMMARY:ישן
DTSTART;TZID=Asia/Jerusalem:20250101T080000
DTEND;TZID=Asia/Jerusalem:20250101T090000
END:VEVENT
ICS
);
$ev = $parser->parse( $ics, $from, $to );
check( 'display-name tzid + window filter', array( '2026-10-20 08:00' ), starts( $ev ) );

// 8. Long-running daily series stays bounded and correct.
$ics = cal(
	<<<ICS
BEGIN:VEVENT
UID:long
SUMMARY:ארוך
DTSTART;TZID=Asia/Jerusalem:20150101T080000
DTEND;TZID=Asia/Jerusalem:20150101T090000
RRULE:FREQ=DAILY;BYDAY=MO,WE
END:VEVENT
ICS
);
$ev = $parser->parse( $ics, ts( '2026-10-11 00:00' ), ts( '2026-10-18 00:00' ) );
check( 'daily byday long series', array( '2026-10-12 08:00', '2026-10-14 08:00' ), starts( $ev ) );

// 9. Subject split into the title and the details only the week view shows.
require __DIR__ . '/../kivun-outlook-board/includes/class-sync.php';
check( 'split: no details', array( 'סדנת אנגלית', array() ), Kivun_Board_Sync::split_title( 'סדנת אנגלית' ) );
check( 'split: details', array( 'סדנת אנגלית', array( 'מימון: משרד העבודה', '40 שעות' ) ), Kivun_Board_Sync::split_title( 'סדנת אנגלית @ מימון: משרד העבודה @ 40 שעות' ) );
check( 'split: no spaces, empty parts dropped', array( 'סדנה', array( '40 שעות' ) ), Kivun_Board_Sync::split_title( 'סדנה@@40 שעות@ ' ) );
check( 'split: details never become the title', array( '', array( 'מימון' ) ), Kivun_Board_Sync::split_title( '@ מימון' ) );

echo $failures ? "\n$failures FAILED\n" : "\nAll tests passed\n";
exit( $failures ? 1 : 0 );
