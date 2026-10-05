<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Personal vouchers, created by the admin with [Add Voucher] (dialog pre-filled from the voucher settings), never just
// because a customer became eligible. A customer has at most one current voucher (unique active_customer_id), which
// [WhatsApp + Voucher] sends. Lifecycle: generated → sent → used, or expired / revoked.
//
// Validity: N days counted from the day it is first sent (sent 5 Oct, 7 days → valid until the end of 12 Oct, store
// time); sending again keeps that date. 0 / empty = no end date. Until it is sent a voucher has no end date at all.
//
// WooCommerce does the discount maths, minimum spend, usage limit, expiry and individual-use checks; this plugin's
// coupons table records the status and the order that used it.
class WCR_Coupons {
    const META          = '_wcr_coupon_id';
    const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L: easy to read out and type
    const OPEN          = array( 'generated', 'sent' );
    const STATUSES      = array( 'generated' => 'Generated', 'sent' => 'Sent', 'used' => 'Used', 'expired' => 'Expired', 'revoked' => 'Revoked' );

    private static $cache = array();

    public static function init() {
        add_filter( 'woocommerce_coupon_get_amount', array( __CLASS__, 'dynamic_amount' ), 10, 2 );
    }
    public static function enabled() { $s = WCR_Settings::get(); return ! empty( $s['voucher_enabled'] ); }

    // ---------------------------------------------------------------- Records

    private static function table() { return WCR_DB::coupons_table(); }
    public static function get( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! array_key_exists( $id, self::$cache ) ) self::$cache[ $id ] = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id=%d', $id ) ) : null;
        return self::$cache[ $id ];
    }
    private static function forget( $id ) { unset( self::$cache[ (int) $id ] ); }
    public static function by_code( $code ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE code=%s', wc_strtoupper( (string) $code ) ) );
    }
    public static function is_expired( $row ) { return ! empty( $row->expires_at ) && strtotime( $row->expires_at . ' UTC' ) <= time(); }
    public static function is_usable( $row ) { return $row && in_array( $row->status, self::OPEN, true ) && ! self::is_expired( $row ); }
    // As shown to admins: an open voucher past its date shows Expired even before the daily task marks it.
    public static function display_status( $row ) { return in_array( $row->status, self::OPEN, true ) && self::is_expired( $row ) ? 'expired' : $row->status; }
    // Current voucher per customer: [ customer_id => row ] for a page of customer IDs.
    public static function current_for( $customer_ids ) {
        global $wpdb;
        $ids = array_filter( array_map( 'absint', (array) $customer_ids ) );
        if ( ! $ids ) return array();
        self::expire_due();
        $out = array();
        foreach ( $wpdb->get_results( 'SELECT * FROM ' . self::table() . ' WHERE active_customer_id IN (' . implode( ',', $ids ) . ')' ) as $r ) $out[ (int) $r->customer_id ] = $r;
        return $out;
    }
    public static function discount_label( $row ) {
        if ( 'fixed' === $row->discount_type ) return WCR_WhatsApp::plain_price( $row->amount );
        $pct = rtrim( rtrim( number_format( (float) $row->amount, 2, '.', '' ), '0' ), '.' ) . '%';
        return $row->max_discount ? sprintf( '%s (up to %s)', $pct, WCR_WhatsApp::plain_price( $row->max_discount ) ) : $pct;
    }
    // End date, or what it will be: "12 October 2026" / "7 days after sending" / "No end date".
    public static function expires_label( $row ) {
        if ( ! empty( $row->expires_at ) ) return wp_date( get_option( 'date_format' ), strtotime( $row->expires_at . ' UTC' ) );
        return (int) $row->valid_days ? sprintf( _n( '%d day after sending', '%d days after sending', (int) $row->valid_days ), (int) $row->valid_days ) : 'No end date';
    }
    // {coupon_validity}: "Valid until 12 October 2026." or '' when the voucher has no end date.
    public static function validity_sentence( $row ) {
        return ! empty( $row->expires_at ) ? sprintf( 'Valid until %s.', wp_date( get_option( 'date_format' ), strtotime( $row->expires_at . ' UTC' ) ) ) : '';
    }
    public static function terms_label( $row ) {
        $parts = array( self::discount_label( $row ) . ' off' );
        if ( $row->min_spend ) $parts[] = 'min. ' . WCR_WhatsApp::plain_price( $row->min_spend );
        $parts[] = ! empty( $row->expires_at ) ? 'until ' . self::expires_label( $row ) : strtolower( self::expires_label( $row ) );
        return implode( ' · ', $parts );
    }

    // ---------------------------------------------------------------- Create / send / revoke

    // Defaults for the Add Voucher dialog, from the voucher settings.
    public static function defaults() {
        $s = WCR_Settings::get();
        return array(
            'type' => 'fixed' === $s['coupon_type'] ? 'fixed' : 'percent', 'amount' => $s['coupon_amount'], 'min_spend' => $s['coupon_min_spend'], 'max_discount' => $s['coupon_max_discount'],
            'valid_days' => (int) $s['coupon_expiry_days'] ? (int) $s['coupon_expiry_days'] : '', 'usage_limit' => (int) $s['coupon_usage_limit'],
            'individual' => ! empty( $s['coupon_individual'] ) ? 1 : 0, 'restrict_email' => ! empty( $s['coupon_restrict_email'] ) ? 1 : 0,
        );
    }
    // Validates one voucher's values (dialog input); returns the clean config or WP_Error.
    public static function clean_config( $in ) {
        $num = function ( $key ) use ( $in ) { $v = isset( $in[ $key ] ) ? trim( (string) wp_unslash( $in[ $key ] ) ) : ''; return '' === $v ? null : (float) $v; };
        $type = ( isset( $in['type'] ) && 'fixed' === $in['type'] ) ? 'fixed' : 'percent';
        $amount = $num( 'amount' ); $min = $num( 'min_spend' ); $max = $num( 'max_discount' ); $days = $num( 'valid_days' ); $limit = $num( 'usage_limit' );
        if ( ! $amount || $amount <= 0 ) return new WP_Error( 'amount', 'Enter a discount greater than 0.' );
        if ( 'percent' === $type && $amount > 100 ) return new WP_Error( 'amount', 'A percentage discount cannot be more than 100%.' );
        if ( null !== $min && $min < 0 ) return new WP_Error( 'min', 'The minimum spend cannot be negative.' );
        if ( null !== $max && $max < 0 ) return new WP_Error( 'max', 'The maximum discount cannot be negative.' );
        if ( null !== $days && ( $days < 0 || $days > 365 || floor( $days ) != $days ) ) return new WP_Error( 'days', 'Validity must be a whole number of days from 0 to 365 (0 or empty = no end date).' );
        if ( null !== $limit && ( $limit < 0 || $limit > 100 || floor( $limit ) != $limit ) ) return new WP_Error( 'limit', 'The usage limit must be a whole number from 0 to 100 (0 = unlimited).' );
        $dp = wc_get_price_decimals();
        return array(
            'type' => $type, 'amount' => round( $amount, 'percent' === $type ? 2 : $dp ),
            'min_spend' => $min ? round( $min, $dp ) : null,
            'max_discount' => ( 'percent' === $type && $max ) ? round( $max, $dp ) : null, // a cap only makes sense for percentages
            'valid_days' => (int) $days, 'usage_limit' => null === $limit ? 1 : (int) $limit,
            'individual' => ! empty( $in['individual'] ), 'restrict_email' => ! empty( $in['restrict_email'] ),
        );
    }
    // e.g. prefix "" + "RAHIM" + "10" + "X7KQ" = RAHIM10X7KQ. Names without Latin letters (e.g. Bangla) are left out.
    private static function new_code( $c, $config ) {
        $s = WCR_Settings::get();
        $prefix = strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) $s['coupon_prefix'] ) );
        $name = ! empty( $s['coupon_name_in_code'] ) ? substr( strtoupper( preg_replace( '/[^A-Za-z]/', '', remove_accents( WCR_WhatsApp::first_name( $c->name ) ) ) ), 0, 8 ) : '';
        $amount = preg_replace( '/\D/', '', (string) round( $config['amount'] ) );
        $base = substr( $prefix . $name . $amount, 0, 26 );
        if ( '' === $prefix . $name ) $base = 'WR' . $base;
        $rand = '';
        for ( $i = 0; $i < 4; $i++ ) $rand .= self::CODE_ALPHABET[ random_int( 0, strlen( self::CODE_ALPHABET ) - 1 ) ];
        return $base . $rand;
    }
    // One current voucher per customer is enforced by the unique active_customer_id column, so two simultaneous
    // clicks can never create two.
    public static function create( $c, $config ) {
        global $wpdb;
        $ct = self::table();
        $now = current_time( 'mysql', true );
        self::expire_due(); // frees the slot of a voucher past its date but not yet marked
        $ok = false;
        for ( $try = 0; $try < 5 && ! $ok; $try++ ) {
            $code = self::new_code( $c, $config );
            if ( wc_get_coupon_id_by_code( $code ) ) continue;
            $suppress = $wpdb->suppress_errors( true );
            $ok = $wpdb->insert( $ct, array(
                'customer_id' => (int) $c->id, 'active_customer_id' => (int) $c->id, 'code' => $code, 'discount_type' => $config['type'], 'amount' => $config['amount'],
                'min_spend' => $config['min_spend'], 'max_discount' => $config['max_discount'], 'usage_limit' => $config['usage_limit'], 'individual_use' => $config['individual'] ? 1 : 0,
                'restrict_email' => $config['restrict_email'] ? 1 : 0, 'valid_days' => $config['valid_days'], 'cycle_start' => $c->last_order_at,
                'status' => 'generated', 'created_at' => $now, 'created_by' => get_current_user_id() ?: null,
            ) );
            $error = $wpdb->last_error;
            $wpdb->suppress_errors( $suppress );
            if ( ! $ok && false !== stripos( $error, 'active_customer_id' ) ) return new WP_Error( 'active', 'This customer already has a voucher. Revoke it first to create a new one.' );
        }
        if ( ! $ok ) return new WP_Error( 'code', 'Could not create a unique voucher code. Please try again.' );
        $id = (int) $wpdb->insert_id;
        try {
            $capped = 'percent' === $config['type'] && $config['max_discount'];
            $coupon = new WC_Coupon();
            $coupon->set_code( $code );
            // A capped percentage is a fixed cart discount recalculated in dynamic_amount(); the stored amount is the cap.
            $coupon->set_discount_type( 'percent' === $config['type'] && ! $capped ? 'percent' : 'fixed_cart' );
            $coupon->set_amount( $capped ? $config['max_discount'] : $config['amount'] );
            if ( $config['min_spend'] ) $coupon->set_minimum_amount( $config['min_spend'] );
            $coupon->set_usage_limit( $config['usage_limit'] );
            $coupon->set_individual_use( $config['individual'] );
            if ( $config['restrict_email'] && is_email( $c->email ) ) $coupon->set_email_restrictions( array( $c->email ) );
            $coupon->set_description( sprintf( 'WhatsApp reminder voucher for %s. Created by WhatsApp Marketing.', $c->name ? $c->name : 'customer #' . (int) $c->id ) );
            $coupon->update_meta_data( self::META, $id );
            $wc_id = $coupon->save(); // no end date until it is sent
        } catch ( Exception $e ) {
            $wc_id = 0;
        }
        if ( ! $wc_id ) { $wpdb->delete( $ct, array( 'id' => $id ) ); return new WP_Error( 'wc', 'WooCommerce could not create the voucher.' ); }
        $wpdb->update( $ct, array( 'wc_coupon_id' => $wc_id ), array( 'id' => $id ) );
        self::forget( $id );
        return self::get( $id );
    }
    // End of the Nth day after today (store time): the end date a voucher gets when it is first sent today.
    private static function end_if_sent_today( $row ) {
        return ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+' . (int) $row->valid_days . ' days' )->setTime( 23, 59, 59 );
    }
    // The voucher as it will be once sent (end date filled in), without saving anything: for message texts that are
    // built before the send succeeds (email).
    public static function as_sent( $row ) {
        $copy = clone $row;
        if ( (int) $copy->valid_days && empty( $copy->expires_at ) ) $copy->expires_at = self::end_if_sent_today( $copy )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        return $copy;
    }
    // First send: status Sent, and the validity period starts (end of the Nth day after today, store time).
    public static function mark_sent( $row ) {
        global $wpdb;
        $row = self::get( $row->id ); // the saved record, not an as_sent() copy
        $now = current_time( 'mysql', true );
        $data = array( 'status' => 'sent' );
        if ( ! $row->sent_at ) $data['sent_at'] = $now;
        if ( (int) $row->valid_days && empty( $row->expires_at ) ) {
            $end = self::end_if_sent_today( $row );
            $data['expires_at'] = $end->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
            $coupon = new WC_Coupon( (int) $row->wc_coupon_id );
            if ( $coupon->get_id() ) { $coupon->set_date_expires( $end->getTimestamp() ); $coupon->save(); }
        }
        $wpdb->update( self::table(), $data, array( 'id' => (int) $row->id ) );
        self::forget( $row->id );
        return self::get( $row->id );
    }
    public static function revoke( $row, $reason = '', $by = null ) {
        global $wpdb;
        // revoked_by 0 = revoked automatically (prepare() cannot write NULL).
        $n = $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='revoked', active_customer_id=NULL, revoked_at=%s, revoked_by=%d, revoke_reason=%s WHERE id=%d AND status IN ('generated','sent')",
            current_time( 'mysql', true ), null === $by ? get_current_user_id() : (int) $by, mb_substr( (string) $reason, 0, 100 ), $row->id ) );
        self::forget( $row->id );
        if ( 1 !== $n ) return false;
        self::close_wc_coupon( $row );
        return true;
    }
    // The WooCommerce coupon itself is expired too, so it stays unusable even without this plugin.
    private static function close_wc_coupon( $row ) {
        if ( ! $row || ! $row->wc_coupon_id ) return;
        $coupon = new WC_Coupon( (int) $row->wc_coupon_id );
        if ( $coupon->get_id() ) { $coupon->set_date_expires( time() - MINUTE_IN_SECONDS ); $coupon->save(); }
    }
    // The customer ordered again (new reminder cycle): a voucher never sent is revoked; a sent one stays valid (the
    // customer was promised it) but is no longer the current voucher, so a new one can be added in the new cycle.
    public static function on_new_cycle( $customer_id ) {
        global $wpdb;
        $ct = self::table();
        foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $ct WHERE active_customer_id=%d AND status='generated'", $customer_id ) ) as $row ) self::revoke( $row, 'Not sent before the customer ordered again', 0 );
        $wpdb->query( $wpdb->prepare( "UPDATE $ct SET active_customer_id=NULL WHERE active_customer_id=%d", $customer_id ) );
        self::$cache = array();
    }

    // ---------------------------------------------------------------- Status from orders

    // Live order statuses that hold a voucher (WooCommerce counts coupon usage from these).
    private static function holding_statuses() {
        return array_unique( array_merge( array( 'pending', 'processing', 'on-hold', 'completed' ), WCR_Settings::counted_statuses() ) );
    }
    public static function sync_order( $order ) {
        global $wpdb;
        $ct = self::table();
        $codes = array_map( 'wc_strtolower', $order->get_coupon_codes() );
        $live = in_array( $order->get_status(), self::holding_statuses(), true );
        // Vouchers this order held but no longer does (cancelled, failed, refunded, coupon removed) can be used again.
        foreach ( $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $ct WHERE order_id=%d AND status='used'", $order->get_id() ) ) as $row ) {
            if ( ! $live || ! in_array( wc_strtolower( $row->code ), $codes, true ) ) self::release( $row );
        }
        if ( ! $live || ! $codes ) return;
        $incl = 'incl' === get_option( 'woocommerce_tax_display_cart' );
        foreach ( $order->get_items( 'coupon' ) as $item ) {
            $row = self::by_code( $item->get_code() );
            if ( ! $row || ( 'used' === $row->status && (int) $row->order_id === $order->get_id() ) ) continue;
            $discount = (float) $item->get_discount() + ( $incl ? (float) $item->get_discount_tax() : 0 );
            $wpdb->query( $wpdb->prepare( "UPDATE $ct SET status='used', used_at=%s, order_id=%d, discount_total=%s, active_customer_id=NULL WHERE id=%d AND status IN ('generated','sent','expired')", current_time( 'mysql', true ), $order->get_id(), wc_format_decimal( $discount ), $row->id ) );
            self::forget( $row->id );
        }
    }
    public static function order_gone( $order_id ) {
        global $wpdb;
        foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . self::table() . " WHERE order_id=%d AND status='used'", $order_id ) ) as $row ) self::release( $row );
    }
    // Back to what it was before the order. It becomes the current voucher again if it belongs to the customer's
    // current cycle and they have no newer one (the unique slot refuses a second).
    private static function release( $row ) {
        global $wpdb;
        $status = self::is_expired( $row ) ? 'expired' : ( $row->sent_at ? 'sent' : 'generated' );
        $wpdb->update( self::table(), array( 'status' => $status, 'used_at' => null, 'order_id' => null, 'discount_total' => null ), array( 'id' => (int) $row->id ) );
        $cycle = $wpdb->get_var( $wpdb->prepare( 'SELECT last_order_at FROM ' . WCR_DB::customers_table() . ' WHERE id=%d', $row->customer_id ) );
        if ( 'expired' !== $status && $cycle === $row->cycle_start ) {
            $suppress = $wpdb->suppress_errors( true );
            $wpdb->update( self::table(), array( 'active_customer_id' => (int) $row->customer_id ), array( 'id' => (int) $row->id ) );
            $wpdb->suppress_errors( $suppress );
        }
        self::forget( $row->id );
    }
    // Daily, and before vouchers are listed or created.
    public static function expire_due() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET status='expired', active_customer_id=NULL WHERE status IN ('generated','sent') AND expires_at IS NOT NULL AND expires_at <= %s", current_time( 'mysql', true ) ) );
        self::$cache = array();
    }

    // ---------------------------------------------------------------- Cart

    public static function cart_basis( $cart = null ) {
        $cart = $cart ? $cart : ( function_exists( 'WC' ) ? WC()->cart : null );
        return $cart ? (float) $cart->get_displayed_subtotal() : 0.0;
    }
    // Percentage with a maximum: min( % of the current cart, cap ), recalculated on every cart change.
    public static function dynamic_amount( $amount, $coupon ) {
        $id = (int) $coupon->get_meta( self::META );
        if ( ! $id ) return $amount;
        $row = self::get( $id );
        if ( ! $row || 'percent' !== $row->discount_type || ! $row->max_discount ) return $amount;
        if ( 'used' === $row->status && null !== $row->discount_total ) return (float) $row->discount_total; // e.g. order recalculated in admin
        $basis = self::cart_basis();
        if ( $basis <= 0 ) return $amount;
        return (float) wc_format_decimal( min( $basis * (float) $row->amount / 100, (float) $row->max_discount ), wc_get_price_decimals() );
    }

    // ---------------------------------------------------------------- Reports

    public static function performance() {
        global $wpdb;
        self::expire_due();
        $ct = self::table(); $ot = WCR_DB::orders_table();
        $in = implode( ',', array_map( function ( $v ) use ( $wpdb ) { return $wpdb->prepare( '%s', $v ); }, WCR_Settings::counted_statuses() ) );
        $row = $wpdb->get_row( "SELECT COUNT(*) AS generated, COALESCE(SUM(status='generated'),0) AS unsent, COALESCE(SUM(sent_at IS NOT NULL),0) AS sent, COALESCE(SUM(status='used'),0) AS used,
            COALESCE(SUM(status='expired'),0) AS expired, COALESCE(SUM(status='revoked'),0) AS revoked, COALESCE(SUM(CASE WHEN status='used' THEN discount_total END),0) AS discount FROM $ct" );
        $conv = $wpdb->get_row( "SELECT COUNT(*) AS orders, COALESCE(SUM(o.net_total),0) AS revenue FROM $ct c JOIN $ot o ON o.order_id=c.order_id WHERE c.status='used' AND o.status IN ($in)" );
        $row->converted = (int) $conv->orders;
        $row->revenue = (float) $conv->revenue;
        return $row;
    }
}
