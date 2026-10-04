<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Personal vouchers. A voucher is created only when the admin clicks [WhatsApp + Voucher], never just because a
// customer became eligible, and is reused for further voucher messages in the same reminder cycle while it is valid.
// WooCommerce does the discount maths, minimum spend, usage limit, expiry and individual-use checks; this plugin's
// coupons table records status (active / used / expired) and the order that used it.
class WCR_Coupons {
    const META          = '_wcr_coupon_id';
    const CODE_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; // no 0/O, 1/I/L: easy to read out and type
    const SESSION_KEY   = 'wcr_offer_coupon'; // WC session: voucher to apply once the cart qualifies
    const STATUSES      = array( 'active' => 'Active', 'used' => 'Used', 'expired' => 'Expired' );

    private static $cache = array();
    private static $busy = false;

    public static function init() {
        add_filter( 'woocommerce_coupon_get_amount', array( __CLASS__, 'dynamic_amount' ), 10, 2 );
        add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'maybe_apply' ), 20 );
    }
    public static function enabled() { $s = WCR_Settings::get(); return ! empty( $s['voucher_enabled'] ); }

    // ---------------------------------------------------------------- Records

    public static function get( $id ) {
        global $wpdb;
        $id = (int) $id;
        if ( ! array_key_exists( $id, self::$cache ) ) self::$cache[ $id ] = $id ? $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WCR_DB::coupons_table() . ' WHERE id=%d', $id ) ) : null;
        return self::$cache[ $id ];
    }
    private static function forget( $id ) { unset( self::$cache[ (int) $id ] ); }
    public static function by_code( $code ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WCR_DB::coupons_table() . ' WHERE code=%s', wc_strtoupper( (string) $code ) ) );
    }
    public static function is_expired( $row ) { return strtotime( $row->expires_at . ' UTC' ) <= time(); }
    public static function is_usable( $row ) { return $row && 'active' === $row->status && ! self::is_expired( $row ); }
    public static function display_status( $row ) { return 'active' === $row->status && self::is_expired( $row ) ? 'expired' : $row->status; }
    // The voucher of the current cycle that can still be used: [ customer_id => row ] for a page of customers.
    public static function active_for( $customers ) {
        global $wpdb;
        $ids = array(); $cycles = array();
        foreach ( (array) $customers as $c ) { $ids[] = (int) $c->id; $cycles[ (int) $c->id ] = $c->last_order_at; }
        if ( ! $ids ) return array();
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WCR_DB::coupons_table() . " WHERE customer_id IN (" . implode( ',', $ids ) . ") AND status='active' AND expires_at > %s ORDER BY id DESC", current_time( 'mysql', true ) ) );
        $out = array();
        foreach ( $rows as $r ) if ( ! isset( $out[ (int) $r->customer_id ] ) && $r->cycle_start === $cycles[ (int) $r->customer_id ] ) $out[ (int) $r->customer_id ] = $r;
        return $out;
    }
    public static function discount_label( $row ) {
        if ( 'fixed' === $row->discount_type ) return WCR_WhatsApp::plain_price( $row->amount );
        $pct = rtrim( rtrim( number_format( (float) $row->amount, 2, '.', '' ), '0' ), '.' ) . '%';
        return $row->max_discount ? sprintf( '%s (up to %s)', $pct, WCR_WhatsApp::plain_price( $row->max_discount ) ) : $pct;
    }
    public static function expires_label( $row ) {
        return wp_date( get_option( 'date_format' ), strtotime( $row->expires_at . ' UTC' ) );
    }

    // ---------------------------------------------------------------- Create

    // Voucher settings, validated. Returns the config or WP_Error.
    public static function config() {
        $s = WCR_Settings::get();
        $type = 'fixed' === $s['coupon_type'] ? 'fixed' : 'percent';
        $amount = (float) $s['coupon_amount'];
        if ( $amount <= 0 ) return new WP_Error( 'amount', 'Set a voucher discount greater than 0 in the settings.' );
        if ( 'percent' === $type && $amount > 100 ) return new WP_Error( 'amount', 'A percentage voucher cannot be more than 100%.' );
        $min = '' === (string) $s['coupon_min_spend'] ? null : max( 0, (float) $s['coupon_min_spend'] );
        $max = '' === (string) $s['coupon_max_discount'] || 'percent' !== $type ? null : max( 0, (float) $s['coupon_max_discount'] ); // a cap only makes sense for percentages
        return array(
            'type' => $type, 'amount' => $amount, 'min_spend' => $min ? $min : null, 'max_discount' => $max ? $max : null,
            'expiry_days' => min( 365, max( 1, (int) $s['coupon_expiry_days'] ) ), 'usage_limit' => max( 0, (int) $s['coupon_usage_limit'] ),
            'individual' => ! empty( $s['coupon_individual'] ), 'restrict_email' => ! empty( $s['coupon_restrict_email'] ),
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
    // The current cycle's valid voucher, or a new one.
    public static function for_customer( $c ) {
        $active = self::active_for( array( $c ) );
        return isset( $active[ (int) $c->id ] ) ? $active[ (int) $c->id ] : self::create( $c );
    }
    public static function create( $c ) {
        global $wpdb;
        $config = self::config();
        if ( is_wp_error( $config ) ) return $config;
        $ct = WCR_DB::coupons_table();
        $now = current_time( 'mysql', true );
        // Valid until the end of the last day, store time.
        $expires = ( new DateTimeImmutable( 'today', wp_timezone() ) )->modify( '+' . $config['expiry_days'] . ' days' )->setTime( 23, 59, 59 );
        $expires_utc = $expires->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
        $ok = false;
        for ( $try = 0; $try < 5 && ! $ok; $try++ ) {
            $code = self::new_code( $c, $config );
            if ( wc_get_coupon_id_by_code( $code ) ) continue;
            $suppress = $wpdb->suppress_errors( true );
            $ok = $wpdb->insert( $ct, array(
                'customer_id' => (int) $c->id, 'code' => $code, 'discount_type' => $config['type'], 'amount' => $config['amount'],
                'min_spend' => $config['min_spend'], 'max_discount' => $config['max_discount'], 'usage_limit' => $config['usage_limit'], 'individual_use' => $config['individual'] ? 1 : 0,
                'cycle_start' => $c->last_order_at, 'expires_at' => $expires_utc, 'status' => 'active', 'created_at' => $now, 'created_by' => get_current_user_id() ?: null,
            ) );
            $wpdb->suppress_errors( $suppress );
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
            $coupon->set_date_expires( $expires->getTimestamp() );
            if ( $config['restrict_email'] && is_email( $c->email ) ) $coupon->set_email_restrictions( array( $c->email ) );
            $coupon->set_description( sprintf( 'WhatsApp reminder voucher for %s. Created by WhatsApp Marketing.', $c->name ? $c->name : 'customer #' . (int) $c->id ) );
            $coupon->update_meta_data( self::META, $id );
            $wc_id = $coupon->save();
        } catch ( Exception $e ) {
            $wc_id = 0;
        }
        if ( ! $wc_id ) { $wpdb->delete( $ct, array( 'id' => $id ) ); return new WP_Error( 'wc', 'WooCommerce could not create the voucher.' ); }
        $wpdb->update( $ct, array( 'wc_coupon_id' => $wc_id ), array( 'id' => $id ) );
        self::forget( $id );
        return self::get( $id );
    }

    // ---------------------------------------------------------------- Status from orders

    // Live order statuses that hold a voucher (WooCommerce counts coupon usage from these).
    private static function holding_statuses() {
        return array_unique( array_merge( array( 'pending', 'processing', 'on-hold', 'completed' ), WCR_Settings::counted_statuses() ) );
    }
    public static function sync_order( $order ) {
        global $wpdb;
        $ct = WCR_DB::coupons_table();
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
            $wpdb->query( $wpdb->prepare( "UPDATE $ct SET status='used', used_at=%s, order_id=%d, discount_total=%s WHERE id=%d AND status IN ('active','expired')", current_time( 'mysql', true ), $order->get_id(), wc_format_decimal( $discount ), $row->id ) );
            self::forget( $row->id );
        }
    }
    public static function order_gone( $order_id ) {
        global $wpdb;
        foreach ( $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WCR_DB::coupons_table() . " WHERE order_id=%d AND status='used'", $order_id ) ) as $row ) self::release( $row );
    }
    private static function release( $row ) {
        global $wpdb;
        $wpdb->update( WCR_DB::coupons_table(), array( 'status' => self::is_expired( $row ) ? 'expired' : 'active', 'used_at' => null, 'order_id' => null, 'discount_total' => null ), array( 'id' => (int) $row->id ) );
        self::forget( $row->id );
    }
    // Daily.
    public static function expire_due() {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . WCR_DB::coupons_table() . " SET status='expired' WHERE status='active' AND expires_at <= %s", current_time( 'mysql', true ) ) );
        self::$cache = array();
    }

    // ---------------------------------------------------------------- Cart

    private static function cart_basis( $cart = null ) {
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
    // Offer link opened: remember the voucher in this browser's session; it is applied as soon as the cart qualifies.
    public static function offer_opened( $coupon_id ) {
        $row = self::get( $coupon_id );
        if ( ! function_exists( 'WC' ) || ! WC()->session ) return;
        // Guests on a new device have no WooCommerce session yet; start one so the voucher (and notices) persist.
        if ( ! WC()->session->has_session() ) WC()->session->set_customer_session_cookie( true );
        if ( ! self::is_usable( $row ) ) {
            wc_add_notice( 'The voucher in this link has expired or has already been used.', 'notice' );
            return;
        }
        WC()->session->set( self::SESSION_KEY, (int) $row->id );
        $cart = WC()->cart;
        if ( $cart && ! $cart->is_empty() ) $cart->calculate_totals();
        if ( WC()->session->get( self::SESSION_KEY ) ) {
            $min = $row->min_spend ? sprintf( ' on orders of %s or more', WCR_WhatsApp::plain_price( $row->min_spend ) ) : '';
            wc_add_notice( esc_html( sprintf( 'Your voucher %1$s (%2$s off%3$s) will be applied automatically to your cart.', $row->code, self::discount_label( $row ), $min ) ), 'notice' );
        }
    }
    public static function maybe_apply( $cart ) {
        if ( self::$busy || ! function_exists( 'WC' ) || ! WC()->session ) return;
        $id = (int) WC()->session->get( self::SESSION_KEY );
        if ( ! $id ) return;
        $row = self::get( $id );
        if ( ! self::is_usable( $row ) ) { WC()->session->set( self::SESSION_KEY, null ); return; }
        if ( $cart->is_empty() ) return;
        $code = wc_format_coupon_code( $row->code );
        if ( $cart->has_discount( $code ) ) { WC()->session->set( self::SESSION_KEY, null ); return; }
        if ( $row->min_spend && self::cart_basis( $cart ) < (float) $row->min_spend ) return; // wait until the cart qualifies
        self::$busy = true;
        $notices = wc_get_notices();
        $ok = $cart->apply_coupon( $code );
        wc_set_notices( $notices ); // WooCommerce's own message is replaced by ours below
        if ( $ok ) $cart->calculate_totals();
        self::$busy = false;
        // Applied, or refused for another reason (e.g. email restriction, another individual-use coupon): stop trying.
        WC()->session->set( self::SESSION_KEY, null );
        $rest = ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || WC()->is_rest_api_request();
        if ( $ok && ! $rest ) wc_add_notice( esc_html( sprintf( 'Voucher %1$s applied: %2$s off.', $row->code, self::discount_label( $row ) ) ), 'success' );
    }

    // ---------------------------------------------------------------- Reports

    public static function performance() {
        global $wpdb;
        $ct = WCR_DB::coupons_table(); $kt = WCR_DB::contacts_table(); $ot = WCR_DB::orders_table();
        $in = implode( ',', array_map( function ( $v ) use ( $wpdb ) { return $wpdb->prepare( '%s', $v ); }, WCR_Settings::counted_statuses() ) );
        $now = current_time( 'mysql', true );
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS generated, COALESCE(SUM(status='used'),0) AS used, COALESCE(SUM(status='expired' OR (status='active' AND expires_at <= %s)),0) AS expired,
            COALESCE(SUM(status='active' AND expires_at > %s),0) AS active, COALESCE(SUM(CASE WHEN status='used' THEN discount_total END),0) AS discount FROM $ct", $now, $now ) );
        $row->sent = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT coupon_id) FROM $kt WHERE coupon_id IS NOT NULL AND status<>'undone'" );
        $conv = $wpdb->get_row( "SELECT COUNT(*) AS orders, COALESCE(SUM(o.net_total),0) AS revenue FROM $ct c JOIN $ot o ON o.order_id=c.order_id WHERE c.status='used' AND o.status IN ($in)" );
        $row->converted = (int) $conv->orders;
        $row->revenue = (float) $conv->revenue;
        return $row;
    }
}
