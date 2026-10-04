<?php
// Checks the pure logic (phone normalization, calendar-day arithmetic) without WordPress: `php tools/selftest.php`.
// Everything that touches the database is tested on a real WooCommerce site.
define( 'ABSPATH', __DIR__ . '/' );
define( 'DAY_IN_SECONDS', 86400 );
function wp_timezone() { return new DateTimeZone( 'Asia/Dhaka' ); }
require __DIR__ . '/../includes/class-wcr-settings.php';
require __DIR__ . '/../includes/class-wcr-whatsapp.php';
require __DIR__ . '/../includes/class-wcr-customers.php';

$fail = 0;
function check( $label, $actual, $expected ) {
    global $fail;
    if ( $actual === $expected ) { echo "ok   $label\n"; return; }
    $fail++;
    echo "FAIL $label: got " . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) . "\n";
}

// Requirement §8 examples, default country code 880.
check( 'local with dash', WCR_WhatsApp::normalize( '017XX-XXXXXX', '880' ), '' ); // X is not a digit
check( 'local 11 digits', WCR_WhatsApp::normalize( '01712-345678', '880' ), '8801712345678' );
check( 'local without 0', WCR_WhatsApp::normalize( '1712345678', '880' ), '8801712345678' );
check( 'already has cc', WCR_WhatsApp::normalize( '8801712345678', '880' ), '8801712345678' );
check( 'plus format', WCR_WhatsApp::normalize( '+880 1712-345678', '880' ), '8801712345678' );
check( '00 prefix', WCR_WhatsApp::normalize( '008801712345678', '880' ), '8801712345678' );
check( 'other country +', WCR_WhatsApp::normalize( '+44 7700 900123', '880' ), '447700900123' );
check( 'empty', WCR_WhatsApp::normalize( '', '880' ), '' );
check( 'too short', WCR_WhatsApp::normalize( '12345', '880' ), '' );
check( 'too long', WCR_WhatsApp::normalize( '+1234567890123456', '880' ), '' );

// Calendar days in store time (Asia/Dhaka, UTC+6).
$tz = wp_timezone(); $utc = new DateTimeZone( 'UTC' );
$at = function ( $local ) use ( $tz, $utc ) { return ( new DateTimeImmutable( $local, $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ); };
$today = new DateTimeImmutable( 'today', $tz );
check( 'days: today', WCR_Customers::days_since( $at( $today->format( 'Y-m-d' ) . ' 23:00' ) ), 0 );
check( 'days: yesterday late', WCR_Customers::days_since( $at( $today->modify( '-1 day' )->format( 'Y-m-d' ) . ' 23:59' ) ), 1 );
check( 'days: 30 days ago early', WCR_Customers::days_since( $at( $today->modify( '-30 days' )->format( 'Y-m-d' ) . ' 00:30' ) ), 30 );

// Eligibility boundary: last order < day_boundary(30) ⇔ at least 30 calendar days ago.
$cut = WCR_Customers::day_boundary( 30 );
check( '30 days ago is eligible', $at( $today->modify( '-30 days' )->format( 'Y-m-d' ) . ' 23:59' ) < $cut, true );
check( '29 days ago is not', $at( $today->modify( '-29 days' )->format( 'Y-m-d' ) . ' 00:00' ) < $cut, false );

echo $fail ? "\n$fail failed\n" : "\nall passed\n";
exit( $fail ? 1 : 0 );
