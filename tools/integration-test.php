<?php
// End-to-end test on a THROWAWAY WordPress + WooCommerce site (HPOS on, this plugin active, store timezone
// Asia/Dhaka, currency BDT, default country BD). Copy this file to the site root and run: php integration-test.php
// It DELETES ALL ORDERS and empties this plugin's tables, so it refuses to run unless DB_NAME contains "test".
define( 'DOING_AJAX', true );
$_SERVER['HTTP_HOST'] = 'wcr.test'; $_SERVER['REQUEST_URI'] = '/';
require __DIR__ . '/wp-load.php';
if ( false === stripos( DB_NAME, 'test' ) ) { echo "Refusing to run: this test deletes all orders. Use a throwaway site whose database name contains 'test'.\n"; exit( 1 ); }
global $wpdb;
class WCRDie extends Exception {}
add_filter( 'wp_die_ajax_handler', function () { return function () { throw new WCRDie(); }; } );
wp_set_current_user( 1 );
require_once ABSPATH . 'wp-admin/includes/admin.php'; // loaded on real admin pages

$fail = 0; $pass = 0;
function check( $label, $actual, $expected ) {
    global $fail, $pass;
    if ( $actual == $expected ) { $pass++; echo "ok   $label\n"; return; }
    $fail++; echo "FAIL $label: got " . var_export( $actual, true ) . ', expected ' . var_export( $expected, true ) . "\n";
}
function ajax( $action, $post ) {
    $_POST = $_REQUEST = $post + array( 'nonce' => wp_create_nonce( 'wcr_admin' ) );
    ob_start();
    try { do_action( 'wp_ajax_' . $action ); } catch ( WCRDie $e ) {}
    return json_decode( ob_get_clean(), true );
}
function make_order( $phone, $first, $days_ago, $total, $status = 'completed', $email = '', $coupon = null, $user = 0 ) {
    $o = wc_create_order( array( 'customer_id' => $user ) );
    $o->set_billing_first_name( $first ); $o->set_billing_last_name( 'Test' );
    $o->set_billing_phone( $phone ); $o->set_billing_email( $email ); $o->set_billing_country( 'BD' );
    $o->set_date_created( time() - (int) round( $days_ago * DAY_IN_SECONDS ) );
    if ( $coupon ) { $item = new WC_Order_Item_Coupon(); $item->set_code( $coupon ); $item->set_discount( 100 ); $o->add_item( $item ); }
    $o->set_total( $total );
    $o->save();
    $o->update_status( $status );
    WCR_Customers::process_queue();
    return $o;
}
function cust( $phone ) {
    global $wpdb;
    $id = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . WCR_DB::customers_table() . ' WHERE customer_key=%s', 'p:' . $phone ) );
    return $id ? WCR_Customers::get( $id ) : null;
}

// Start from no orders and empty plugin tables (the test may be re-run).
foreach ( wc_get_orders( array( "limit" => -1, "type" => "shop_order", "status" => array_keys( wc_get_order_statuses() ) + array( 99 => "trash" ) ) ) as $old ) $old->delete( true );
WCR_Customers::process_queue();
foreach ( array( WCR_DB::customers_table(), WCR_DB::orders_table(), WCR_DB::contacts_table(), WCR_DB::coupons_table() ) as $t ) $wpdb->query( "TRUNCATE $t" );
check( 'HPOS active', Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(), true );

echo "\n-- statistics\n";
make_order( '01712345678', 'Rahim', 60, 2000 );
$r2 = make_order( '+880 1712-345678', 'Rahim', 40, 3000 );
make_order( '01712345678', 'Rahim', 5, 999, 'cancelled' ); // not counted
$r = cust( '8801712345678' );
check( 'phone formats merge into one customer', (int) $r->order_count, 2 );
check( 'total value', (float) $r->total_value, 5000 );
check( 'last order id', (int) $r->last_order_id, $r2->get_id() );
check( 'days since', WCR_Customers::days_since( $r->last_order_at ), 40 );
check( 'state eligible', $r->state, 'eligible' );

make_order( '01812345678', 'Karim', 18, 4200 );
check( 'recent customer not eligible', cust( '8801812345678' )->state, 'not_eligible' );

make_order( '01912345678', 'Rina', 45, 1500 );
make_order( '01912345678', 'Rina', 2, 800, 'processing' );
$rina = cust( '8801912345678' );
check( 'open processing order blocks', $rina->state, 'open_order' );
check( 'processing order not counted', (int) $rina->order_count, 1 );

make_order( '01612345678', 'Emon', 50, 1000 );
make_order( '01612345678', 'Emon', 10, 500, 'pending' );
check( 'stale pending order does not block', cust( '8801612345678' )->state, 'eligible' );
make_order( '01512345678', 'Fresh', 50, 1000 );
make_order( '01512345678', 'Fresh', 1, 500, 'pending' );
check( 'recent pending order blocks', cust( '8801512345678' )->state, 'open_order' );

$f = make_order( '01312345678', 'Faruk', 31, 1000 );
wc_create_refund( array( 'order_id' => $f->get_id(), 'amount' => 300, 'reason' => 'test' ) );
WCR_Customers::process_queue();
check( 'partial refund is net', (float) cust( '8801312345678' )->total_value, 700 );

$g = make_order( '', 'Guest', 35, 1200, 'completed', 'guest@example.com' );
$guest = $wpdb->get_row( "SELECT * FROM " . WCR_DB::customers_table() . " WHERE customer_key='e:guest@example.com'" );
check( 'email-only guest identified', (int) $guest->order_count, 1 );
check( 'guest has no WhatsApp number', $guest->wa_number, '' );
check( 'guest user_id is NULL', $guest->user_id, null );

make_order( '01412345678', 'Cancel', 50, 1000, 'cancelled' );
check( 'customer with only cancelled orders is hidden', (int) cust( '8801412345678' )->order_count, 0 );

echo "\n-- list, filters, summary\n";
$base = array( 'view' => 'all', 'ctype' => '', 'min_orders' => 0, 'min_spent' => 0, 'from' => '', 'to' => '', 's' => '', 'orderby' => 'days', 'order' => 'desc', 'page' => 1, 'per' => 25 );
list( $rows, $total ) = WCR_Customers::query( $base );
check( 'all customers with counted orders', $total, 7 );
check( 'sorted by most days first', $rows[0]->name, 'Emon Test' );
list( , $total ) = WCR_Customers::query( array( 'view' => 'eligible' ) + $base );
check( 'eligible count', $total, 4 ); // Rahim, Emon, Faruk, Guest
list( $rows, $total ) = WCR_Customers::query( array( 's' => '1712' ) + $base );
check( 'search by phone digits', $total . ':' . $rows[0]->name, '1:Rahim Test' );
list( , $total ) = WCR_Customers::query( array( 'min_spent' => 2000 ) + $base );
check( 'min spent filter', $total, 2 );
list( , $total ) = WCR_Customers::query( array( 'min_orders' => 2 ) + $base );
check( 'min orders filter', $total, 1 );
list( , $total ) = WCR_Customers::query( array( 'ctype' => 'guest' ) + $base );
check( 'guest filter', $total, 7 );
$sum = WCR_Customers::summary();
check( 'summary total / eligible', $sum->total . '/' . $sum->eligible, '7/4' );
echo "\n-- only due customers can be contacted\n";
$k = cust( '8801812345678' ); // Karim: 18 days, not eligible
$res = ajax( 'wcr_contact', array( 'id' => $k->id, 'type' => 'plain' ) );
check( 'not eligible: contact refused', $res['success'], false );
$res = ajax( 'wcr_voucher_create', array( 'id' => $k->id, 'amount' => 10 ) );
check( 'not eligible: Add Voucher refused', $res['success'], false );
$cells = WCR_Admin::cells( $k->id );
check( 'not eligible: no WhatsApp or Add Voucher button', false === strpos( $cells['waCell'], 'data-type=' ) && false === strpos( $cells['voucherCell'], 'wcr-voucher-add' ), true );
$cells = WCR_Admin::cells( $r->id );
check( 'eligible: WhatsApp and Add Voucher buttons, no voucher button yet', false !== strpos( $cells['waCell'], 'data-type="plain"' ) && false === strpos( $cells['waCell'], 'data-type="voucher"' ) && false !== strpos( $cells['voucherCell'], 'wcr-voucher-add' ), true );

echo "\n-- admin-created voucher, validity from sending\n";
$res = ajax( 'wcr_contact', array( 'id' => $r->id, 'type' => 'voucher' ) );
check( 'voucher message without a voucher refused', $res['success'], false );
$res = ajax( 'wcr_voucher_create', array( 'id' => $r->id, 'type' => 'percent', 'amount' => 10, 'valid_days' => 7, 'usage_limit' => 1, 'individual' => 1 ) );
check( 'Add Voucher ok', $res['success'], true );
$coupon = $wpdb->get_row( 'SELECT * FROM ' . WCR_DB::coupons_table() . ' WHERE customer_id=' . (int) $r->id );
check( 'voucher code format', (bool) preg_match( '/^RAHIM10[A-Z2-9]{4}$/', $coupon->code ), true );
check( 'generated, no end date before sending', $coupon->status . '/' . var_export( $coupon->expires_at, true ), 'generated/NULL' );
$wc = new WC_Coupon( $coupon->code );
check( 'WooCommerce coupon', $wc->get_discount_type() . ' ' . $wc->get_amount() . ' limit ' . $wc->get_usage_limit() . ' indiv ' . (int) $wc->get_individual_use() . ' expires ' . var_export( $wc->get_date_expires(), true ), 'percent 10 limit 1 indiv 1 expires NULL' );
check( 'WhatsApp + Voucher button appears', false !== strpos( $res['data']['waCell'], 'data-type="voucher"' ), true );
$res = ajax( 'wcr_voucher_create', array( 'id' => $r->id, 'amount' => 5 ) );
check( 'second voucher refused (one per customer)', $res['success'], false );
$res = ajax( 'wcr_contact', array( 'id' => $r->id, 'type' => 'voucher' ) );
check( 'voucher contact ok', $res['success'], true );
$coupon = WCR_Coupons::get( $coupon->id );
$end = ( new DateTimeImmutable( 'today +7 days', wp_timezone() ) )->format( 'Y-m-d' ) . ' 23:59';
check( 'sent: valid until end of day 7 after sending', $coupon->status . ' ' . wp_date( 'Y-m-d H:i', strtotime( $coupon->expires_at . ' UTC' ) ), 'sent ' . $end );
$wc = new WC_Coupon( $coupon->code );
check( 'WooCommerce coupon got the same end date', wp_date( 'Y-m-d H:i', $wc->get_date_expires()->getTimestamp() ), $end );
$msg = rawurldecode( substr( $res['data']['url'], strpos( $res['data']['url'], '?text=' ) + 6 ) );
check( 'message: code, 10% and "Valid until"', false !== strpos( $msg, $coupon->code ) && false !== strpos( $msg, '10% discount' ) && false !== strpos( $msg, 'Valid until' ), true );
preg_match( '/wcr-offer=([A-Za-z0-9_-]{43})/', $msg, $m );
check( 'offer link token stored as hash', (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . WCR_DB::contacts_table() . ' WHERE token_hash=%s', hash( 'sha256', $m[1] ?? '' ) ) ), true );
$r = cust( '8801712345678' );
check( 'state contacted with voucher', $r->state . '/' . $r->cycle_voucher, 'contacted/1' );
$res = ajax( 'wcr_contact', array( 'id' => $r->id, 'type' => 'plain' ) );
check( 'contacted (not yet follow-up): refused', $res['success'], false );
check( 'contacted: no WhatsApp buttons', false === strpos( WCR_Admin::cells( $r->id )['waCell'], 'data-type=' ), true );
// Follow-up 31 days later: the voucher is sent again and keeps its first end date.
$wpdb->query( 'UPDATE ' . WCR_DB::customers_table() . ' SET last_contact_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY) WHERE id=' . (int) $r->id );
check( 'follow-up due', WCR_Customers::get( $r->id )->state, 'followup' );
$res = ajax( 'wcr_contact', array( 'id' => $r->id, 'type' => 'voucher' ) );
check( 'follow-up voucher contact ok', $res['success'], true );
check( 're-send keeps the first end date', WCR_Coupons::get( $coupon->id )->expires_at, $coupon->expires_at );
list( , $total ) = WCR_Customers::query( array( 'view' => 'contacted_voucher' ) + $base );
check( 'contacted-with-voucher filter', $total, 1 );

echo "\n-- conversion via voucher\n";
$new = make_order( '01712345678', 'Rahim', 0, 2500, 'processing', '', $coupon->code );
check( 'processing order: open, not converted yet', cust( '8801712345678' )->state, 'open_order' );
check( 'voucher marked used at processing', WCR_Coupons::get( $coupon->id )->status, 'used' );
$new->update_status( 'completed' ); WCR_Customers::process_queue();
$r = cust( '8801712345678' );
check( 'cycle reset to new order', (int) $r->last_order_id . '/' . $r->state . '/' . (int) $r->cycle_contacts, $new->get_id() . '/not_eligible/0' );
check( 'customer conversions', (int) $r->conversions . '/' . (int) $r->last_converted_order_id, '1/' . $new->get_id() );
$conv = $wpdb->get_results( 'SELECT status, coupon_used, order_id FROM ' . WCR_DB::contacts_table() . ' WHERE customer_id=' . (int) $r->id . ' ORDER BY id' );
check( 'the latest voucher contact converted', implode( ',', wp_list_pluck( $conv, 'status' ) ), 'closed,converted' );
check( 'coupon_used flag', (int) $conv[1]->coupon_used, 1 );
check( 'order count now 3', (int) $r->order_count, 3 );
$perf = WCR_Coupons::performance();
check( 'voucher performance', $perf->generated . '/' . $perf->sent . '/' . $perf->used . '/' . $perf->converted . '/' . $perf->revenue, '1/1/1/1/2500' );

echo "\n-- no end date, revoke, attribution window\n";
$fa = cust( '8801312345678' ); // Faruk: 31 days, eligible
$res = ajax( 'wcr_voucher_create', array( 'id' => $fa->id, 'type' => 'fixed', 'amount' => 200, 'valid_days' => '', 'usage_limit' => 0 ) );
check( 'voucher with no end date and no usage limit', $res['success'], true );
$res = ajax( 'wcr_contact', array( 'id' => $fa->id, 'type' => 'voucher' ) );
$msg = rawurldecode( substr( $res['data']['url'], strpos( $res['data']['url'], '?text=' ) + 6 ) );
$fc = $wpdb->get_row( 'SELECT * FROM ' . WCR_DB::coupons_table() . ' WHERE customer_id=' . (int) $fa->id );
check( 'still no end date after sending', $fc->status . '/' . var_export( $fc->expires_at, true ), 'sent/NULL' );
check( 'message has no "Valid until" and no double spaces', false === strpos( $msg, 'Valid until' ) && false === strpos( $msg, '  ' ) && false === strpos( $msg, " \n" ), true );
$res = ajax( 'wcr_voucher_revoke', array( 'id' => $fa->id ) );
check( 'revoke ok', $res['success'] && 'revoked' === WCR_Coupons::get( $fc->id )->status, true );
$wc = new WC_Coupon( $fc->code );
check( 'revoked WooCommerce coupon is expired', $wc->get_date_expires() && $wc->get_date_expires()->getTimestamp() < time(), true );
$wpdb->query( 'UPDATE ' . WCR_DB::contacts_table() . ' SET created_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 40 DAY) WHERE customer_id=' . (int) $fa->id );
make_order( '01312345678', 'Faruk', 0, 1000 );
check( 'order 40 days after contact: closed, not converted', $wpdb->get_var( 'SELECT status FROM ' . WCR_DB::contacts_table() . ' WHERE customer_id=' . (int) $fa->id ), 'closed' );
check( 'no conversion counted', (int) cust( '8801312345678' )->conversions, 0 );

echo "\n-- follow-up, undo, dnc, unsent voucher revoked on a new order\n";
$e = cust( '8801612345678' );
ajax( 'wcr_contact', array( 'id' => $e->id, 'type' => 'plain' ) );
$wpdb->query( 'UPDATE ' . WCR_DB::customers_table() . ' SET last_contact_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY) WHERE id=' . (int) $e->id );
check( 'follow-up due after 30 days', WCR_Customers::get( $e->id )->state, 'followup' );
$wpdb->query( 'UPDATE ' . WCR_DB::customers_table() . ' SET last_contact_at = UTC_TIMESTAMP() WHERE id=' . (int) $e->id );
$res = ajax( 'wcr_undo', array( 'id' => $e->id ) );
check( 'undo ok', $res['success'], true );
check( 'undo returns customer to eligible', WCR_Customers::get( $e->id )->state, 'eligible' );
$res = ajax( 'wcr_dnc', array( 'id' => $e->id, 'on' => 1 ) );
check( 'dnc on', WCR_Customers::get( $e->id )->state, 'dnc' );
$res = ajax( 'wcr_contact', array( 'id' => $e->id, 'type' => 'plain' ) );
check( 'dnc blocks contact', $res['success'], false );
ajax( 'wcr_dnc', array( 'id' => $e->id, 'on' => 0 ) );
$res = ajax( 'wcr_contact', array( 'id' => $guest->id, 'type' => 'plain' ) );
check( 'no number blocks contact', $res['success'], false );
ajax( 'wcr_voucher_create', array( 'id' => $e->id, 'amount' => 15, 'valid_days' => 5 ) );
$ev = $wpdb->get_row( 'SELECT * FROM ' . WCR_DB::coupons_table() . ' WHERE customer_id=' . (int) $e->id );
make_order( '01612345678', 'Emon', 0, 700 );
$ev = WCR_Coupons::get( $ev->id );
check( 'unsent voucher revoked automatically on the new order', $ev->status . '/' . (int) $ev->revoked_by . '/' . var_export( $ev->active_customer_id, true ), 'revoked/0/NULL' );
$res = ajax( 'wcr_history', array( 'id' => $r->id ) );
check( 'history renders reminders and vouchers', $res['success'] && false !== strpos( $res['data']['html'], 'Converted' ) && false !== strpos( $res['data']['html'], '<h3>Vouchers</h3>' ), true );

echo "\n-- order changes\n";
$new->update_status( 'cancelled' ); WCR_Customers::process_queue();
$r = cust( '8801712345678' );
check( 'cancelling the order releases the voucher (back to Sent)', WCR_Coupons::get( $coupon->id )->status, 'sent' );
check( 'cancelled order leaves the statistics', (int) $r->order_count . '/' . (int) $r->last_order_id, '2/' . $r2->get_id() );
$res = ajax( 'wcr_history', array( 'id' => $r->id ) );
check( 'history flags the cancelled converting order', false !== strpos( $res['data']['html'], 'order now Cancelled' ), true );
$d = make_order( '01012345678', 'Delete', 40, 500 );
$d->delete( true ); WCR_Customers::process_queue();
check( 'deleted order removes customer without history', cust( '8801012345678' ), null );
$o = wc_get_order( $g->get_id() ); $o->delete( false ); WCR_Customers::process_queue();
check( 'trashed order leaves the statistics', (int) $wpdb->get_var( "SELECT COUNT(*) FROM " . WCR_DB::customers_table() . " WHERE customer_key='e:guest@example.com'" ), 0 );

echo "\n-- rebuild gives the same statistics\n";
$snap = function () use ( $wpdb ) { return $wpdb->get_results( 'SELECT customer_key, order_count, total_value, last_order_id, open_order_id FROM ' . WCR_DB::customers_table() . ' WHERE order_count>0 ORDER BY customer_key', ARRAY_A ); };
$before = $snap();
$wpdb->query( 'UPDATE ' . WCR_DB::customers_table() . ' SET order_count=0, total_value=0, last_order_id=NULL, open_order_id=NULL' );
WCR_Customers::start_rebuild();
for ( $i = 0; $i < 50 && 'running' === WCR_Customers::rebuild_state()['status']; $i++ ) WCR_Customers::rebuild_batch();
check( 'rebuild finished', WCR_Customers::rebuild_state()['status'], 'done' );
check( 'rebuild matches live sync', $snap(), $before );

echo "\n-- privacy\n";
make_order( '01112345678', 'Priv', 40, 900, 'completed', 'priv@example.com' );
$exp = WCR_Privacy::export( 'priv@example.com' );
check( 'export finds the customer', count( $exp['data'] ) >= 1, true );
$er = WCR_Privacy::erase( 'priv@example.com' );
check( 'erase removes it', $er['items_removed'] && ! cust( '8801112345678' ), true );

echo "\n-- storefront: offer popup and auto-apply\n";
wc_load_cart();
WC()->cart->empty_cart();
$coupon = WCR_Coupons::get( $coupon->id ); // Rahim's voucher: Sent again after the cancelled order, valid 7 days
$data = WCR_Storefront::popup_data( $coupon->id );
check( 'popup, empty cart: code, terms, valid until, not applied', $data['state'] . '/' . $data['code'] . '/' . $data['discount'] . '/' . ( '' !== $data['validUntil'] ? 'date' : 'none' ) . '/' . var_export( $data['applied'], true ), 'offer/' . $coupon->code . '/10%/date/false' );
check( 'popup greets by first name', $data['name'], 'Rahim' );
WCR_Storefront::offer_opened( $coupon->id );
check( 'link opened: voucher waits, popup pending', (int) WC()->session->get( WCR_Storefront::APPLY_KEY ) . '/' . (int) WC()->session->get( WCR_Storefront::POPUP_KEY ), $coupon->id . '/' . $coupon->id );
$p = new WC_Product_Simple(); $p->set_name( 'Pencil box' ); $p->set_regular_price( 1000 ); $p->set_status( 'publish' ); $p->save();
WC()->cart->add_to_cart( $p->get_id(), 1 ); WC()->cart->calculate_totals();
check( 'voucher applied when an item is added', WC()->cart->has_discount( wc_format_coupon_code( $coupon->code ) ), true );
$_POST = $_REQUEST = array();
ob_start(); try { do_action( 'wc_ajax_wcr_offer_popup' ); } catch ( WCRDie $e ) {} $res = json_decode( ob_get_clean(), true );
check( 'popup endpoint: applied, discount and new total', $res['data']['applied'] . '/' . wp_strip_all_tags( $res['data']['discountAmount'] ) . '/' . wp_strip_all_tags( $res['data']['total'] ), '1/' . WCR_WhatsApp::plain_price( 100 ) . '/' . WCR_WhatsApp::plain_price( 900 ) );
ob_start(); try { do_action( 'wc_ajax_wcr_offer_popup' ); } catch ( WCRDie $e ) {} $res = json_decode( ob_get_clean(), true );
check( 'popup shown only once', $res['data'], null );
check( 'revoked voucher: popup says unavailable', WCR_Storefront::popup_data( $fc->id )['state'], 'unavailable' );
WC()->cart->empty_cart();
$p->delete( true );

echo "\n-- admin pages render without notices\n";
$errors = array();
set_error_handler( function ( $no, $str, $file, $line ) use ( &$errors ) { $errors[] = "$str ($file:$line)"; return true; } );
foreach ( array( array(), array( 'view' => 'all', 'orderby' => 'aov', 'order' => 'asc' ), array( 'tab' => 'conversions' ), array( 'tab' => 'vouchers' ),
    array( 'tab' => 'settings' ), array( 'tab' => 'settings', 'section' => 'messages' ), array( 'tab' => 'settings', 'section' => 'voucher' ), array( 'tab' => 'settings', 'section' => 'data' ) ) as $get ) {
    $_GET = $get + array( 'page' => 'whatsapp-marketing' );
    ob_start(); WCR_Admin::page(); $html = ob_get_clean();
    check( 'render ' . ( $get ? http_build_query( $get ) : 'customers' ), strlen( $html ) > 500, true );
}
restore_error_handler();
check( 'no PHP notices while rendering', $errors, array() );

echo "\n$pass passed, $fail failed\n";
exit( $fail ? 1 : 0 );
