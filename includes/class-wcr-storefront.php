<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Storefront side of a voucher offer link (?wcr-offer=<token>, handled in WCR_WhatsApp::maybe_offer):
// - the voucher waits in the customer's WooCommerce session and is applied as soon as the cart qualifies;
// - an offer popup on the page the link leads to;
// - "voucher applied" messages for the Cart / Checkout blocks, which do not show WooCommerce's normal notices.
//
// Cache safety: the shop / home page may be served from a page cache (e.g. LiteSpeed Cache), so nothing personal is
// ever written into the page. The link sets a flag cookie (value "1", no data); the page's script sees it, deletes it
// and fetches the popup through an uncached WooCommerce AJAX request that reads the voucher from the session.
class WCR_Storefront {
    const APPLY_KEY   = 'wcr_offer_coupon'; // WC session: voucher to apply once the cart qualifies
    const POPUP_KEY   = 'wcr_offer_popup';  // WC session: voucher whose popup is still to be shown
    const NOTICES_KEY = 'wcr_notices';      // WC session: messages for the Cart / Checkout blocks
    const COOKIE      = 'wcr_offer';

    private static $busy = false;
    private static $quiet = false; // while the link is being opened: the popup tells the customer, not a notice

    public static function init() {
        add_action( 'woocommerce_after_calculate_totals', array( __CLASS__, 'maybe_apply' ), 20 );
        add_action( 'wc_ajax_wcr_offer_popup', array( __CLASS__, 'ajax_popup' ) );
        add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
        if ( did_action( 'woocommerce_blocks_loaded' ) ) self::register_store_api_data(); else add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api_data' ) );
    }
    public static function popup_enabled() { $s = WCR_Settings::get(); return ! empty( $s['offer_popup'] ); }

    // ---------------------------------------------------------------- Link opened

    public static function offer_opened( $coupon_id ) {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) return;
        // Guests on a new device have no WooCommerce session yet; start one so the voucher persists.
        if ( ! WC()->session->has_session() ) WC()->session->set_customer_session_cookie( true );
        $row = WCR_Coupons::get( $coupon_id );
        $popup = self::popup_enabled();
        if ( $popup ) {
            WC()->session->set( self::POPUP_KEY, (int) $coupon_id );
            self::flag_cookie();
        }
        if ( ! WCR_Coupons::is_usable( $row ) ) {
            if ( ! $popup ) wc_add_notice( 'The voucher in this link has expired or is no longer available.', 'notice' );
            return;
        }
        WC()->session->set( self::APPLY_KEY, (int) $row->id );
        $cart = WC()->cart;
        self::$quiet = $popup;
        if ( $cart && ! $cart->is_empty() ) $cart->calculate_totals();
        self::$quiet = false;
        if ( ! $popup && WC()->session->get( self::APPLY_KEY ) ) {
            $min = $row->min_spend ? sprintf( ' on orders of %s or more', WCR_WhatsApp::plain_price( $row->min_spend ) ) : '';
            wc_add_notice( esc_html( sprintf( 'Your voucher %1$s (%2$s off%3$s) will be applied automatically to your cart.', $row->code, WCR_Coupons::discount_label( $row ), $min ) ), 'notice' );
        }
    }
    private static function flag_cookie() {
        if ( headers_sent() ) return;
        setcookie( self::COOKIE, '1', array( 'expires' => time() + 10 * MINUTE_IN_SECONDS, 'path' => COOKIEPATH ? COOKIEPATH : '/', 'domain' => COOKIE_DOMAIN ? COOKIE_DOMAIN : '', 'secure' => is_ssl(), 'httponly' => false, 'samesite' => 'Lax' ) );
    }

    // ---------------------------------------------------------------- Apply when the cart qualifies

    public static function maybe_apply( $cart ) {
        if ( self::$busy || ! function_exists( 'WC' ) || ! WC()->session ) return;
        $id = (int) WC()->session->get( self::APPLY_KEY );
        if ( ! $id ) return;
        $row = WCR_Coupons::get( $id );
        if ( ! WCR_Coupons::is_usable( $row ) ) { WC()->session->set( self::APPLY_KEY, null ); return; }
        if ( $cart->is_empty() ) return;
        $code = wc_format_coupon_code( $row->code );
        if ( $cart->has_discount( $code ) ) { WC()->session->set( self::APPLY_KEY, null ); return; }
        if ( $row->min_spend && WCR_Coupons::cart_basis( $cart ) < (float) $row->min_spend ) return; // wait until the cart qualifies
        self::$busy = true;
        $notices = wc_get_notices();
        $ok = $cart->apply_coupon( $code );
        wc_set_notices( $notices ); // WooCommerce's own message is replaced by ours below
        if ( $ok ) $cart->calculate_totals();
        self::$busy = false;
        // Applied, or refused for another reason (e.g. email restriction, another individual-use coupon): stop trying.
        WC()->session->set( self::APPLY_KEY, null );
        if ( $ok && ! self::$quiet ) self::notify( sprintf( 'Voucher %1$s applied: %2$s off.', $row->code, WCR_Coupons::discount_label( $row ) ) );
    }
    // WooCommerce notice on classic pages; for the Cart / Checkout blocks (Store API requests) the message travels in
    // the cart response and assets/js/offer.js shows it.
    private static function notify( $message ) {
        if ( ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || WC()->is_rest_api_request() ) {
            $queue = (array) WC()->session->get( self::NOTICES_KEY, array() );
            $queue[] = $message;
            WC()->session->set( self::NOTICES_KEY, array_slice( $queue, -5 ) );
        } else {
            wc_add_notice( esc_html( $message ), 'success' );
        }
    }
    public static function register_store_api_data() {
        if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) || ! class_exists( '\Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema' ) ) return;
        woocommerce_store_api_register_endpoint_data( array(
            'endpoint'        => \Automattic\WooCommerce\StoreApi\Schemas\V1\CartSchema::IDENTIFIER,
            'namespace'       => 'wcr',
            'data_callback'   => array( __CLASS__, 'store_api_data' ),
            'schema_callback' => function () { return array( 'notices' => array( 'description' => 'WhatsApp voucher messages', 'type' => 'array', 'items' => array( 'type' => 'string' ), 'context' => array( 'view', 'edit' ), 'readonly' => true ) ); },
            'schema_type'     => ARRAY_A,
        ) );
    }
    public static function store_api_data() {
        $queue = ( function_exists( 'WC' ) && WC()->session ) ? (array) WC()->session->get( self::NOTICES_KEY, array() ) : array();
        if ( $queue ) WC()->session->set( self::NOTICES_KEY, array() );
        return array( 'notices' => array_values( $queue ) );
    }

    // ---------------------------------------------------------------- Popup

    // ?wc-ajax=wcr_offer_popup (POST, uncached): the popup for the voucher whose link this browser just opened, once.
    public static function ajax_popup() {
        if ( ! function_exists( 'WC' ) || ! WC()->session ) wp_send_json_success( null );
        $id = (int) WC()->session->get( self::POPUP_KEY );
        if ( ! $id ) wp_send_json_success( null );
        WC()->session->set( self::POPUP_KEY, null );
        wp_send_json_success( self::popup_data( $id ) );
    }
    // Always computed from the current cart and WooCommerce's own discount calculation.
    public static function popup_data( $coupon_id ) {
        $row = WCR_Coupons::get( $coupon_id );
        if ( ! WCR_Coupons::is_usable( $row ) ) return array( 'state' => 'unavailable' );
        $c = WCR_Customers::get( (int) $row->customer_id );
        $data = array(
            'state' => 'offer', 'name' => $c ? WCR_WhatsApp::first_name( $c->name ) : '', 'code' => $row->code, 'discount' => WCR_Coupons::discount_label( $row ),
            'minSpend' => $row->min_spend ? WCR_WhatsApp::plain_price( $row->min_spend ) : '', 'validUntil' => ! empty( $row->expires_at ) ? WCR_Coupons::expires_label( $row ) : '',
            'cart' => '', 'applied' => false,
        );
        $cart = WC()->cart;
        if ( ! $cart || $cart->is_empty() ) return $data;
        $cart->calculate_totals(); // applies the voucher if the cart qualifies
        $subtotal = WCR_Coupons::cart_basis( $cart );
        $code = wc_format_coupon_code( $row->code );
        $data['cart'] = WCR_WhatsApp::plain_price( $subtotal );
        if ( $cart->has_discount( $code ) ) {
            $discount = (float) $cart->get_coupon_discount_amount( $code, 'incl' !== get_option( 'woocommerce_tax_display_cart' ) );
            $data += array( 'discountAmount' => WCR_WhatsApp::plain_price( $discount ), 'total' => WCR_WhatsApp::plain_price( max( 0, $subtotal - $discount ) ) );
            $data['applied'] = true;
        } else {
            $data['needMore'] = $row->min_spend ? WCR_WhatsApp::plain_price( max( 0, (float) $row->min_spend - $subtotal ) ) : '';
        }
        return $data;
    }
    // The script is the same for every visitor (safe to cache); it only acts when the flag cookie is present.
    public static function assets() {
        if ( ! function_exists( 'is_woocommerce' ) ) return;
        if ( ! ( is_front_page() || is_home() || is_woocommerce() || is_cart() || is_checkout() ) || is_order_received_page() ) return;
        wp_enqueue_style( 'wcr-offer', WCR_URL . 'assets/css/offer.css', array(), wcr_asset_ver( 'assets/css/offer.css' ) );
        wp_enqueue_script( 'wcr-offer', WCR_URL . 'assets/js/offer.js', array(), wcr_asset_ver( 'assets/js/offer.js' ), true );
        wp_localize_script( 'wcr-offer', 'WCROffer', array( 'endpoint' => WC_AJAX::get_endpoint( 'wcr_offer_popup' ), 'cookie' => self::COOKIE, 'cookiePath' => COOKIEPATH ? COOKIEPATH : '/' ) );
    }
}
