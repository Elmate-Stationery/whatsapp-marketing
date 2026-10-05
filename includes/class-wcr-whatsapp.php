<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// WhatsApp click-to-chat (same method as Checkout Tracker): the admin's click opens a blank tab, the server builds the
// wa.me link with the filled-in message and records the contact, and the tab goes to WhatsApp. The admin presses Send
// in WhatsApp; nothing is ever sent automatically, and a contact means "WhatsApp opened", not "message delivered".
//
// {offer_url} is a personal link (?wcr-offer=<token>) that counts the customer's opens, applies their voucher to the
// cart, and redirects to the shop. Only a SHA-256 hash of the token is stored.
class WCR_WhatsApp {
    const QUERY_VAR          = 'wcr-offer';
    const MAX_MESSAGE_LENGTH = 2000;
    const UNDO_HOURS         = 24;
    const PLACEHOLDERS = array(
        '{customer_name}'         => 'Customer name',
        '{first_name}'            => 'Customer first name',
        '{site_name}'             => 'Your store name',
        '{last_order_id}'         => 'Last order number',
        '{last_order_date}'       => 'Last order date',
        '{days_since_last_order}' => 'Days since the last order',
        '{order_count}'           => 'Number of orders',
        '{total_order_value}'     => 'Total order value',
        '{aov}'                   => 'Average order value',
        '{offer_url}'             => 'Personal shop link (applies the voucher, counts opens)',
        '{coupon_code}'           => 'Voucher code',
        '{coupon_discount}'       => 'e.g. "10%", "10% (up to ৳500)" or "৳200"',
        '{coupon_validity}'       => '"Valid until 12 October 2026." (empty when the voucher has no end date)',
        '{coupon_expires}'        => 'Voucher end date (empty when it has none)',
        '{coupon_minimum_spend}'  => 'Voucher minimum spend (empty when there is none)',
    );
    // Link-preview fetchers (WhatsApp, Facebook, Telegram, ...) must not count as the customer opening the link.
    const PREVIEW_AGENTS = '/bot|crawl|spider|slurp|preview|facebookexternalhit|facebookcatalog|whatsapp|telegram|slack|discord|skypeuri|linkedin|embedly|pinterest|vkshare|google-pagerenderer|headless/i';

    public static function init() {
        add_action( 'wp_ajax_wcr_contact', array( __CLASS__, 'ajax_contact' ) );
        add_action( 'wp_ajax_wcr_undo', array( __CLASS__, 'ajax_undo' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_offer' ), 1 );
    }

    // ---------------------------------------------------------------- Numbers

    // "017XX-XXXXXX", "17XXXXXXXX", "+880 17XX..." or "0088017..." → "88017XXXXXXXX". Numbers without + or 00 get
    // $calling_code unless they already start with it. Returns '' when the result is not a plausible number.
    public static function normalize( $phone, $calling_code ) {
        $phone  = trim( (string) $phone );
        $digits = preg_replace( '/\D/', '', $phone );
        if ( '' === $digits ) return '';
        if ( 0 === strpos( $phone, '+' ) ) {
            // already international
        } elseif ( 0 === strpos( $digits, '00' ) ) {
            $digits = substr( $digits, 2 );
        } else {
            $cc = preg_replace( '/\D/', '', (string) $calling_code );
            $has_cc = '' !== $cc && 0 === strpos( $digits, $cc ) && strlen( $digits ) - strlen( $cc ) >= 8;
            if ( ! $has_cc ) {
                $national = ltrim( $digits, '0' );
                if ( strlen( $national ) < 7 ) return ''; // too short to be a phone number, even with the code added
                $digits = $cc . $national;
            }
        }
        $len = strlen( $digits );
        return ( $len >= 8 && $len <= 15 ) ? $digits : '';
    }
    // Uses the calling code of the order's billing country, else the default country code from settings.
    public static function number( $phone, $country = '' ) {
        return self::normalize( $phone, self::calling_code( $country ) );
    }
    private static function calling_code( $country ) {
        static $cache = array();
        $country = strtoupper( (string) $country );
        if ( ! isset( $cache[ $country ] ) ) {
            $code = '';
            if ( preg_match( '/^[A-Z]{2}$/', $country ) && function_exists( 'WC' ) && WC()->countries ) $code = preg_replace( '/\D/', '', (string) WC()->countries->get_country_calling_code( $country ) );
            if ( '' === $code ) { $s = WCR_Settings::get(); $code = preg_replace( '/\D/', '', (string) $s['country_code'] ); }
            $cache[ $country ] = $code;
        }
        return $cache[ $country ];
    }
    public static function display_number( $wa ) {
        return '' === $wa ? '' : '+' . $wa;
    }

    // ---------------------------------------------------------------- Messages

    public static function plain_price( $amount ) {
        $html = wc_price( (float) $amount );
        return trim( str_replace( "\xC2\xA0", ' ', html_entity_decode( wp_strip_all_tags( $html ), ENT_QUOTES, 'UTF-8' ) ) );
    }
    public static function first_name( $name ) {
        $name = trim( (string) $name );
        return '' === $name ? '' : strtok( $name, ' ' );
    }
    // Placeholder values for a customer (and voucher). Only names, order statistics, the voucher and the store name
    // are used, never addresses or other order data.
    public static function values( $c, $coupon = null, $offer_url = '' ) {
        $order = $c->last_order_id ? wc_get_order( (int) $c->last_order_id ) : null;
        $name = trim( (string) $c->name );
        $count = (int) $c->order_count;
        return array(
            '{customer_name}'         => '' !== $name ? $name : 'there',
            '{first_name}'            => '' !== $name ? self::first_name( $name ) : 'there',
            '{site_name}'             => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
            '{last_order_id}'         => $order ? $order->get_order_number() : (string) $c->last_order_id,
            '{last_order_date}'       => $c->last_order_at ? wp_date( get_option( 'date_format' ), strtotime( $c->last_order_at . ' UTC' ) ) : '',
            '{days_since_last_order}' => (string) (int) WCR_Customers::days_since( $c->last_order_at ),
            '{order_count}'           => (string) $count,
            '{total_order_value}'     => self::plain_price( $c->total_value ),
            '{aov}'                   => self::plain_price( $count ? (float) $c->total_value / $count : 0 ),
            '{offer_url}'             => $offer_url,
            '{coupon_code}'           => $coupon ? $coupon->code : '',
            '{coupon_discount}'       => $coupon ? WCR_Coupons::discount_label( $coupon ) : '',
            '{coupon_validity}'       => $coupon ? WCR_Coupons::validity_sentence( $coupon ) : '',
            '{coupon_expires}'        => $coupon && ! empty( $coupon->expires_at ) ? WCR_Coupons::expires_label( $coupon ) : '',
            '{coupon_minimum_spend}'  => $coupon && $coupon->min_spend ? self::plain_price( $coupon->min_spend ) : '',
        );
    }
    public static function template( $type ) {
        $s = WCR_Settings::get();
        $template = 'voucher' === $type ? (string) $s['template_voucher'] : (string) $s['template_plain'];
        if ( '' === trim( $template ) ) $template = 'voucher' === $type ? WCR_Settings::DEFAULT_VOUCHER : WCR_Settings::DEFAULT_PLAIN;
        return $template;
    }
    public static function message( $c, $type, $coupon = null, $offer_url = '' ) {
        $message = strtr( self::template( $type ), self::values( $c, $coupon, $offer_url ) );
        // Empty placeholders (e.g. {coupon_validity} without an end date) leave no double or trailing spaces.
        $message = preg_replace( array( '/[ \t]{2,}/', '/[ \t]+$/m' ), array( ' ', '' ), $message );
        return mb_substr( trim( $message ), 0, self::MAX_MESSAGE_LENGTH );
    }

    // ---------------------------------------------------------------- Admin actions

    private static function guard() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'wcr_admin', 'nonce', false ) ) wp_send_json_error( array( 'message' => 'You are not allowed to do this, or your login has expired. Reload the page and try again.' ), 403 );
    }
    private static function customer_or_fail() {
        $c = WCR_Customers::get( absint( $_POST['id'] ?? 0 ) );
        if ( ! $c || (int) $c->order_count < 1 ) wp_send_json_error( array( 'message' => 'This customer no longer exists.' ), 404 );
        return $c;
    }
    // Customers who can be contacted now: WhatsApp buttons and Add Voucher are shown (and accepted) only for these.
    public static function is_due( $c ) {
        return '' !== $c->wa_number && WCR_Customers::is_due( $c );
    }
    // [WhatsApp] / [WhatsApp + Voucher]. [WhatsApp + Voucher] sends the customer's current voucher (added by the admin);
    // its validity period starts with the first send.
    public static function ajax_contact() {
        self::guard();
        $c = self::customer_or_fail();
        $type = 'voucher' === ( $_POST['type'] ?? '' ) ? 'voucher' : 'plain';
        if ( (int) $c->dnc ) wp_send_json_error( array( 'message' => 'This customer is marked Do not contact. Remove the mark in their history first.' ), 400 );
        if ( '' === $c->wa_number ) wp_send_json_error( array( 'message' => 'This customer has no valid WhatsApp number.' ), 400 );
        if ( ! self::is_due( $c ) ) wp_send_json_error( array( 'message' => 'This customer is not due for a reminder. Reload the page to see their current status.' ), 409 );
        $coupon = null;
        if ( 'voucher' === $type ) {
            if ( ! WCR_Coupons::enabled() ) wp_send_json_error( array( 'message' => 'Vouchers are turned off in the settings.' ), 400 );
            $current = WCR_Coupons::current_for( array( $c->id ) );
            $coupon = $current[ (int) $c->id ] ?? null;
            if ( ! WCR_Coupons::is_usable( $coupon ) ) wp_send_json_error( array( 'message' => 'This customer has no valid voucher. Add a voucher first.' ), 400 );
            $coupon = WCR_Coupons::mark_sent( $coupon );
        }
        global $wpdb;
        $offer = ''; $hash = null;
        if ( false !== strpos( self::template( $type ), '{offer_url}' ) ) {
            $token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
            $hash = hash( 'sha256', $token );
            $offer = add_query_arg( self::QUERY_VAR, $token, home_url( '/' ) );
        }
        $message = self::message( $c, $type, $coupon, $offer );
        $now = current_time( 'mysql', true );
        $wpdb->insert( WCR_DB::contacts_table(), array(
            'customer_id' => (int) $c->id, 'anchor_order_id' => $c->last_order_id, 'created_at' => $now, 'created_by' => get_current_user_id(),
            'type' => $type, 'channel' => 'whatsapp', 'coupon_id' => $coupon ? (int) $coupon->id : null, 'wa_number' => $c->wa_number, 'message' => $message, 'token_hash' => $hash,
        ) );
        WCR_Customers::record_contact( $c, $type, 'whatsapp' );
        $url = 'https://wa.me/' . $c->wa_number . '?text=' . rawurlencode( $message );
        wp_send_json_success( array( 'url' => $url ) + WCR_Admin::cells( (int) $c->id ) );
    }
    // "Not sent": WhatsApp was opened but the admin did not send the message. Only the latest contact, within 24 hours.
    public static function ajax_undo() {
        self::guard();
        $c = self::customer_or_fail();
        global $wpdb;
        $kt = WCR_DB::contacts_table();
        $k = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $kt WHERE customer_id=%d AND status='contacted' ORDER BY created_at DESC, id DESC LIMIT 1", $c->id ) );
        if ( ! $k || strtotime( $k->created_at . ' UTC' ) < time() - self::UNDO_HOURS * HOUR_IN_SECONDS ) wp_send_json_error( array( 'message' => 'There is no recent contact to undo.' ), 400 );
        if ( 'email' === $k->channel ) wp_send_json_error( array( 'message' => 'The last contact was an email, which has already been sent and cannot be undone.' ), 400 );
        $wpdb->query( $wpdb->prepare( "UPDATE $kt SET status='undone', closed_at=%s WHERE id=%d AND status='contacted'", current_time( 'mysql', true ), $k->id ) );
        WCR_Customers::refresh_contact_fields( (int) $c->id );
        wp_send_json_success( WCR_Admin::cells( (int) $c->id ) );
    }

    // ---------------------------------------------------------------- Offer link (storefront)

    public static function device_label( $agent ) {
        $agent = (string) $agent;
        $os = 'Unknown device';
        foreach ( array( 'iPhone' => 'iPhone', 'iPad' => 'iPad', 'Android' => 'Android', 'Windows' => 'Windows', 'Macintosh' => 'Mac', 'CrOS' => 'ChromeOS', 'Linux' => 'Linux' ) as $needle => $label ) {
            if ( false !== stripos( $agent, $needle ) ) { $os = $label; break; }
        }
        $browser = '';
        foreach ( array( 'Edg/' => 'Edge', 'OPR/' => 'Opera', 'SamsungBrowser' => 'Samsung Internet', 'Firefox/' => 'Firefox', 'FxiOS' => 'Firefox', 'CriOS' => 'Chrome', 'Chrome/' => 'Chrome', 'Safari/' => 'Safari' ) as $needle => $label ) {
            if ( false !== stripos( $agent, $needle ) ) { $browser = $label; break; }
        }
        return $browser ? $os . ' · ' . $browser : $os;
    }
    private static function is_preview_request( $agent ) {
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'GET' !== strtoupper( $_SERVER['REQUEST_METHOD'] ) ) return true;
        return '' === $agent || (bool) preg_match( self::PREVIEW_AGENTS, $agent );
    }
    public static function destination() {
        $s = WCR_Settings::get();
        $shop = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : '';
        return 'home' === $s['offer_destination'] || ! $shop ? home_url( '/' ) : $shop;
    }
    // ?wcr-offer=<token>: count the open, apply the voucher (if still valid) and go to the shop.
    public static function maybe_offer() {
        if ( ! isset( $_GET[ self::QUERY_VAR ] ) ) return;
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();
        if ( ! headers_sent() ) header( 'X-Robots-Tag: noindex, nofollow' );
        $destination = self::destination();
        $token = (string) wp_unslash( $_GET[ self::QUERY_VAR ] );
        $agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '';
        if ( self::is_preview_request( $agent ) || ! preg_match( '/^[A-Za-z0-9_-]{43}$/', $token ) ) { wp_safe_redirect( $destination ); exit; }
        global $wpdb;
        $kt = WCR_DB::contacts_table();
        $k = $wpdb->get_row( $wpdb->prepare( "SELECT id, coupon_id, channel, type FROM $kt WHERE token_hash=%s", hash( 'sha256', $token ) ) );
        if ( $k ) {
            // Staff testing a link are not counted as the customer opening it.
            if ( ! current_user_can( 'manage_woocommerce' ) ) {
                $now = current_time( 'mysql', true );
                $wpdb->query( $wpdb->prepare( "UPDATE $kt SET link_opens=link_opens+1, first_open_at=COALESCE(first_open_at,%s), last_open_at=%s, last_device=%s WHERE id=%d", $now, $now, self::device_label( $agent ), $k->id ) );
            }
            if ( $k->coupon_id ) WCR_Storefront::offer_opened( (int) $k->coupon_id );
            $destination = self::with_utm( $destination, $k );
        }
        wp_safe_redirect( $destination );
        exit;
    }
    // UTM tags on the page the offer link leads to, so WooCommerce's Order Attribution records the order's origin
    // (Orders screen → Origin, and WooCommerce's reports): utm_source = whatsapp / email, utm_content = voucher / reminder.
    public static function with_utm( $url, $contact ) {
        $s = WCR_Settings::get();
        if ( empty( $s['utm_enabled'] ) ) return $url;
        $email = 'email' === $contact->channel;
        return add_query_arg( array(
            'utm_source'   => $email ? 'email' : 'whatsapp',
            'utm_medium'   => $email ? 'email' : 'messaging',
            'utm_campaign' => rawurlencode( '' !== trim( (string) $s['utm_campaign'] ) ? (string) $s['utm_campaign'] : 'winback' ),
            'utm_content'  => 'voucher' === $contact->type ? 'voucher' : 'reminder',
        ), $url );
    }
}
