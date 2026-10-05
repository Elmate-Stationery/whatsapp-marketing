<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Reminder emails. Sent one at a time by the admin ([Email] / [Email + Voucher]) through wp_mail(), so the site's own
// mail setup (e.g. an SMTP plugin) is used. An email is a contact in the same reminder cycle as WhatsApp.
//
// The template is a fixed, mobile-friendly HTML layout (tables + inline styles, as email clients need) whose texts,
// colours, logo and footer are edited under Settings → Email. Every text supports the message placeholders.
// Each email carries an unsubscribe link (and List-Unsubscribe headers) that sets the customer's "Do not email" flag.
class WCR_Email {
    const UNSUB_VAR = 'wcr-unsub';
    const FONT      = "'Hind Siliguri','Noto Sans Bengali',Kalpurush,SolaimanLipi,'Segoe UI',Arial,sans-serif";
    // Texts that may span several lines (textarea in the settings).
    const MULTILINE = array( 'email_intro', 'email_body_plain', 'email_body_voucher', 'email_voucher_terms', 'email_closing', 'email_signoff', 'email_footer_reason', 'email_footer_address' );

    private static $error = '';

    public static function init() {
        add_action( 'wp_ajax_wcr_email', array( __CLASS__, 'ajax_send' ) );
        add_action( 'wp_ajax_wcr_email_preview', array( __CLASS__, 'ajax_preview' ) );
        add_action( 'wp_ajax_wcr_email_test', array( __CLASS__, 'ajax_test' ) );
        add_action( 'wp_ajax_wcr_customer_search', array( __CLASS__, 'ajax_customer_search' ) );
        add_action( 'template_redirect', array( __CLASS__, 'maybe_unsubscribe' ), 1 );
    }

    // Default template: Bangla, for Elmate Stationery.
    public static function defaults() {
        return array(
            'email_enabled'         => 1,
            'email_from_name'       => '',
            'email_from_address'    => '',
            'email_reply_to'        => '',
            'email_logo_id'         => 0,
            'email_header_bg'       => '#ffffff',
            'email_primary'         => '#006eb6',
            'email_voucher_color'   => '#d93025',
            'email_footer_bg'       => '#0b2e4f',
            'email_subject_plain'   => '{first_name}, অনেকদিন দেখা নেই! এলমেট স্টেশনারিতে আপনার জন্য নতুন অনেক কিছু',
            'email_subject_voucher' => '{first_name}, আপনার জন্য বিশেষ প্রত্যাবর্তন উপহার: {coupon_discount} ছাড়!',
            'email_greeting'        => 'প্রিয় {customer_name},',
            'email_intro'           => 'অনেকদিন হলো এলমেট স্টেশনারিতে আপনার পদচিহ্ন পড়েনি। আমাদের মনে আছে সেই আনন্দের দিনটির কথা, যখন আপনার সংগ্রহে যুক্ত হয়েছিল আপনার প্রিয় এই অর্ডারটি—',
            'email_last_order_label'=> 'আপনার শেষ অর্ডার:',
            'email_last_order_note' => 'আশা করি, এলমেট স্টেশনারির সেই অভিজ্ঞতা আপনার মুখে হাসি ফুটিয়েছিল।',
            'email_body_plain'      => 'সেই পুরোনো আস্থা নিয়ে আবারও ঘুরে আসুন আমাদের নতুন কালেকশনে। কারণ এলমেট স্টেশনারিতে আমরা বিশ্বাস করি, আপনার কেনাকাটার অভিজ্ঞতা হওয়া উচিত সবসময়ই বিশেষ।',
            'email_body_voucher'    => 'সেই পুরোনো আস্থা আর নতুন কিছু পাওয়ার আনন্দকে আবারও ফিরিয়ে আনতে আপনার জন্য আমরা সাজিয়েছি একটি বিশেষ সারপ্রাইজ। কারণ এলমেট স্টেশনারিতে আমরা বিশ্বাস করি, আপনার কেনাকাটার অভিজ্ঞতা হওয়া উচিত সবসময়ই বিশেষ।',
            'email_voucher_title'   => '🎁 বিশেষ প্রত্যাবর্তন উপহার: {coupon_discount} অতিরিক্ত ছাড়!',
            'email_voucher_line'    => 'আপনার পরবর্তী অর্ডারে যেকোনো কিছুতে কুপনটি ব্যবহার করুন:',
            'email_voucher_terms'   => "ন্যূনতম অর্ডার: {coupon_minimum_spend}\n*অফারটির মেয়াদ {coupon_expires} পর্যন্ত",
            'email_closing'         => 'পছন্দের নতুন খাতা, কলম, আর্ট সাপ্লাই কিংবা অফিস স্টেশনারি—যেটাই হোক না কেন, এখনই আবারও শুরু হোক আপনার এলমেট স্টেশনারি শপিং যাত্রা।',
            'email_highlight'       => 'হাজারো পণ্য অপেক্ষা করছে আপনার ঠিকানায় পৌঁছানোর জন্য। 🛒',
            'email_button'          => 'শপিং শুরু করুন',
            'email_signoff'         => 'আবারও স্বাগতম এলমেট স্টেশনারির প্রিয় আঙিনায়। 💙',
            'email_team'            => '— এলমেট স্টেশনারি টিম',
            'email_facebook'        => '',
            'email_instagram'       => '',
            'email_youtube'         => '',
            'email_footer_name'     => 'এলমেট স্টেশনারি',
            'email_footer_tagline'  => 'elmatestationery.com',
            'email_footer_address'  => '',
            'email_footer_reason'   => 'আপনি এই ইমেইলটি পেয়েছেন কারণ আপনি এলমেট স্টেশনারি থেকে এক বা একাধিক অর্ডার করেছিলেন।',
            'email_unsub_label'     => 'আর ইমেইল পেতে না চাইলে আনসাবস্ক্রাইব করুন',
        );
    }
    public static function enabled() { $s = WCR_Settings::get(); return ! empty( $s['email_enabled'] ); }
    // A valid address that has not unsubscribed.
    public static function can_email( $c ) { return is_email( (string) $c->email ) && ! (int) $c->dnc_email; }
    public static function is_due( $c ) { return self::enabled() && WCR_Customers::is_due( $c ) && self::can_email( $c ); }

    // ---------------------------------------------------------------- Rendering

    // "Pencil Box, Gel Pen + 2" from the customer's last counted order.
    public static function last_order_items( $c ) {
        $order = $c->last_order_id ? wc_get_order( (int) $c->last_order_id ) : null;
        if ( ! $order ) return '';
        $names = array();
        foreach ( $order->get_items() as $item ) $names[] = $item->get_name();
        if ( ! $names ) return '';
        return implode( ', ', array_slice( $names, 0, 2 ) ) . ( count( $names ) > 2 ? ' + ' . ( count( $names ) - 2 ) : '' );
    }
    public static function logo_url( $s ) {
        $id = (int) $s['email_logo_id'] ? (int) $s['email_logo_id'] : (int) get_theme_mod( 'custom_logo' );
        $url = $id ? wp_get_attachment_image_url( $id, 'medium' ) : '';
        return $url ? $url : '';
    }
    // [ 'subject', 'html', 'text' ] for a customer. $s: settings (the saved ones unless given, e.g. for the preview).
    public static function render( $c, $type, $coupon, $offer_url, $unsub_url, $s = null, $values = null ) {
        $s = $s ? $s : WCR_Settings::get();
        if ( null === $values ) $values = WCR_WhatsApp::values( $c, $coupon, $offer_url ) + array( '{last_order_items}' => self::last_order_items( $c ) );
        $fill = function ( $key ) use ( $s, $values ) { return trim( preg_replace( '/[ \t]{2,}/', ' ', strtr( (string) $s[ $key ], $values ) ) ); };
        $html_text = function ( $key ) use ( $fill ) { return nl2br( esc_html( $fill( $key ) ), false ); };
        $color = function ( $key, $default ) use ( $s ) { $c = sanitize_hex_color( (string) $s[ $key ] ); return $c ? $c : $default; };
        $primary = $color( 'email_primary', '#006eb6' ); $vcol = $color( 'email_voucher_color', '#d93025' );
        $header = $color( 'email_header_bg', '#ffffff' ); $footer = $color( 'email_footer_bg', '#0b2e4f' );
        $voucher = 'voucher' === $type && $coupon;
        $site = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
        $subject = $fill( $voucher ? 'email_subject_voucher' : 'email_subject_plain' );
        // Voucher terms: a line whose placeholder is empty (no minimum spend, no end date) is left out.
        $terms = array();
        foreach ( preg_split( '/\r\n|\n/', (string) $s['email_voucher_terms'] ) as $line ) {
            preg_match_all( '/\{[a-z_]+\}/', $line, $m );
            $empty = false;
            foreach ( $m[0] as $ph ) if ( isset( $values[ $ph ] ) && '' === (string) $values[ $ph ] ) $empty = true;
            $line = trim( strtr( $line, $values ) );
            if ( ! $empty && '' !== $line ) $terms[] = $line;
        }
        $p = 'margin:0;font-size:15px;line-height:1.75;color:#1d2327;';
        $row = function ( $inner, $pad = '20px 32px 0', $align = 'left' ) { return '<tr><td align="' . $align . '" style="padding:' . $pad . ';text-align:' . $align . ';">' . $inner . '</td></tr>'; };
        $logo = self::logo_url( $s );
        $h = '<!DOCTYPE html><html lang="bn"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="x-apple-disable-message-reformatting"><title>' . esc_html( $subject ) . '</title></head>'
            . '<body style="margin:0;padding:0;background:#eef1f4;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;">' . esc_html( mb_substr( $fill( 'email_intro' ), 0, 110 ) ) . '</div>'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background:#eef1f4;"><tr><td align="center" style="padding:24px 12px;">'
            . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width:600px;background:#ffffff;border-radius:12px;overflow:hidden;font-family:' . self::FONT . ';color:#1d2327;">';
        $h .= '<tr><td align="center" style="background:' . $header . ';padding:20px 24px;border-bottom:3px solid ' . $primary . ';"><a href="' . esc_url( home_url( '/' ) ) . '" style="text-decoration:none;">'
            . ( $logo ? '<img src="' . esc_url( $logo ) . '" alt="' . esc_attr( $site ) . '" style="display:block;max-height:64px;max-width:240px;height:auto;border:0;">' : '<span style="font-size:24px;font-weight:700;color:' . $primary . ';">' . esc_html( $site ) . '</span>' )
            . '</a></td></tr>';
        $h .= $row( '<h1 style="margin:0 0 12px;font-size:22px;line-height:1.4;color:' . $primary . ';">' . esc_html( $fill( 'email_greeting' ) ) . '</h1><p style="' . $p . '">' . $html_text( 'email_intro' ) . '</p>', '28px 32px 0' );
        $items = (string) $values['{last_order_items}'];
        if ( '' !== $items && '' !== trim( (string) $s['email_last_order_label'] ) ) {
            $h .= $row( '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td style="background:#eef8f0;border-left:4px solid #2e7d32;border-radius:4px;padding:12px 16px;">'
                . '<p style="margin:0;font-size:15px;line-height:1.6;color:#1d2327;"><strong style="color:#2e7d32;">📦 ' . esc_html( $fill( 'email_last_order_label' ) ) . '</strong> ' . esc_html( $items ) . '</p>'
                . ( '' !== $fill( 'email_last_order_note' ) ? '<p style="margin:4px 0 0;font-size:13px;line-height:1.6;color:#646970;">' . esc_html( $fill( 'email_last_order_note' ) ) . '</p>' : '' )
                . '</td></tr></table>' );
        }
        $h .= $row( '<p style="' . $p . '">' . $html_text( $voucher ? 'email_body_voucher' : 'email_body_plain' ) . '</p>', '20px 32px 0', 'center' );
        if ( $voucher ) {
            $h .= $row( '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr><td align="center" style="border:2px dashed ' . $vcol . ';border-radius:10px;padding:18px 16px;background:#fff8f7;text-align:center;">'
                . '<p style="margin:0 0 6px;font-size:18px;line-height:1.5;font-weight:700;color:' . $vcol . ';">' . esc_html( $fill( 'email_voucher_title' ) ) . '</p>'
                . '<p style="margin:0 0 14px;font-size:13px;line-height:1.6;color:#50575e;">' . esc_html( $fill( 'email_voucher_line' ) ) . '</p>'
                . '<span style="display:inline-block;background:' . $vcol . ';color:#ffffff;font-family:Consolas,Monaco,monospace;font-size:22px;font-weight:700;letter-spacing:2px;padding:10px 26px;border-radius:6px;">' . esc_html( $coupon->code ) . '</span>'
                . ( $terms ? '<p style="margin:12px 0 0;font-size:12px;line-height:1.7;color:' . $vcol . ';">' . implode( '<br>', array_map( 'esc_html', $terms ) ) . '</p>' : '' )
                . '</td></tr></table>' );
        }
        $h .= $row( '<p style="' . $p . '">' . $html_text( 'email_closing' ) . '</p>' . ( '' !== $fill( 'email_highlight' ) ? '<p style="margin:14px 0 0;font-size:17px;line-height:1.6;font-weight:700;color:' . $primary . ';">' . esc_html( $fill( 'email_highlight' ) ) . '</p>' : '' ), '20px 32px 0', 'center' );
        if ( $offer_url && '' !== $fill( 'email_button' ) ) {
            $h .= $row( '<a href="' . esc_url( $offer_url ) . '" style="display:inline-block;background:' . $primary . ';color:#ffffff;text-decoration:none;font-size:17px;font-weight:700;line-height:1.2;padding:15px 44px;border-radius:30px;">' . esc_html( $fill( 'email_button' ) ) . '</a>', '26px 32px 0', 'center' );
        }
        $h .= $row( '<p style="margin:0;font-size:13px;line-height:1.7;color:#50575e;">' . $html_text( 'email_signoff' ) . ( '' !== $fill( 'email_team' ) ? '<br><strong style="color:#1d2327;">' . esc_html( $fill( 'email_team' ) ) . '</strong>' : '' ) . '</p>', '22px 32px 30px', 'center' );
        $social = array();
        foreach ( array( 'email_facebook' => 'Facebook', 'email_instagram' => 'Instagram', 'email_youtube' => 'YouTube' ) as $key => $label ) {
            if ( '' !== trim( (string) $s[ $key ] ) ) $social[] = '<a href="' . esc_url( $s[ $key ] ) . '" style="color:#ffffff;font-weight:700;text-decoration:none;">' . $label . '</a>';
        }
        $foot = ( $social ? '<p style="margin:0 0 14px;font-size:14px;">' . implode( ' &nbsp;|&nbsp; ', $social ) . '</p>' : '' )
            . ( '' !== $fill( 'email_footer_name' ) ? '<p style="margin:0;font-size:15px;font-weight:700;">' . esc_html( $fill( 'email_footer_name' ) ) . '</p>' : '' )
            . ( '' !== $fill( 'email_footer_tagline' ) ? '<p style="margin:2px 0 0;font-size:13px;opacity:.9;">' . esc_html( $fill( 'email_footer_tagline' ) ) . '</p>' : '' )
            . '<p style="margin:16px 0 0;padding-top:14px;border-top:1px solid rgba(255,255,255,.25);font-size:12px;line-height:1.7;opacity:.85;">' . $html_text( 'email_footer_reason' ) . ( '' !== $fill( 'email_footer_address' ) ? '<br>' . $html_text( 'email_footer_address' ) : '' ) . '</p>'
            . ( $unsub_url ? '<p style="margin:10px 0 0;font-size:12px;"><a href="' . esc_url( $unsub_url ) . '" style="color:#ffffff;text-decoration:underline;">' . esc_html( $fill( 'email_unsub_label' ) ) . '</a></p>' : '' );
        $h .= '<tr><td align="center" style="background:' . $footer . ';color:#ffffff;padding:24px 32px;text-align:center;font-family:' . self::FONT . ';">' . $foot . '</td></tr>';
        $h .= '</table></td></tr></table></body></html>';

        // Plain-text version (shown by clients without HTML, and stored in the reminder history).
        $t = array( $fill( 'email_greeting' ), '', $fill( 'email_intro' ) );
        if ( '' !== $items ) $t[] = $fill( 'email_last_order_label' ) . ' ' . $items;
        $t[] = ''; $t[] = $fill( $voucher ? 'email_body_voucher' : 'email_body_plain' );
        if ( $voucher ) { $t[] = ''; $t[] = $fill( 'email_voucher_title' ); $t[] = $fill( 'email_voucher_line' ) . ' ' . $coupon->code; foreach ( $terms as $line ) $t[] = $line; }
        $t[] = ''; $t[] = $fill( 'email_closing' );
        if ( $offer_url ) { $t[] = ''; $t[] = $fill( 'email_button' ) . ': ' . $offer_url; }
        $t[] = ''; $t[] = $fill( 'email_signoff' ); $t[] = $fill( 'email_team' );
        if ( $unsub_url ) { $t[] = ''; $t[] = $fill( 'email_unsub_label' ) . ': ' . $unsub_url; }
        return array( 'subject' => $subject, 'html' => $h, 'text' => trim( implode( "\n", $t ) ) );
    }

    // ---------------------------------------------------------------- Sending

    // Sends one email; returns true or false (the reason is in self::$error).
    public static function deliver( $to, $mail, $unsub_url = '' ) {
        $s = WCR_Settings::get();
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );
        $from = sanitize_email( (string) $s['email_from_address'] );
        if ( $from ) $headers[] = 'From: ' . ( '' !== trim( (string) $s['email_from_name'] ) ? '"' . str_replace( array( '"', "\r", "\n" ), '', $s['email_from_name'] ) . '" ' : '' ) . '<' . $from . '>';
        $reply = sanitize_email( (string) $s['email_reply_to'] );
        if ( $reply ) $headers[] = 'Reply-To: ' . $reply;
        if ( $unsub_url ) { $headers[] = 'List-Unsubscribe: <' . esc_url_raw( $unsub_url ) . '>'; $headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click'; }
        self::$error = '';
        $alt = function ( $phpmailer ) use ( $mail ) { $phpmailer->AltBody = $mail['text']; };
        $fail = function ( $error ) { self::$error = is_wp_error( $error ) ? $error->get_error_message() : 'unknown error'; };
        add_action( 'phpmailer_init', $alt );
        add_action( 'wp_mail_failed', $fail );
        $ok = wp_mail( $to, $mail['subject'], $mail['html'], $headers );
        remove_action( 'phpmailer_init', $alt );
        remove_action( 'wp_mail_failed', $fail );
        if ( ! $ok && '' === self::$error ) self::$error = 'WordPress could not send the email. Check the site\'s mail (SMTP) settings.';
        return (bool) $ok;
    }
    private static function guard() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'wcr_admin', 'nonce', false ) ) wp_send_json_error( array( 'message' => 'You are not allowed to do this, or your login has expired. Reload the page and try again.' ), 403 );
    }
    // [Email] / [Email + Voucher]: only for customers who are due and have a subscribed email address.
    public static function ajax_send() {
        self::guard();
        global $wpdb;
        $c = WCR_Customers::get( absint( $_POST['id'] ?? 0 ) );
        if ( ! $c || (int) $c->order_count < 1 ) wp_send_json_error( array( 'message' => 'This customer no longer exists.' ), 404 );
        $type = 'voucher' === ( $_POST['type'] ?? '' ) ? 'voucher' : 'plain';
        if ( ! self::enabled() ) wp_send_json_error( array( 'message' => 'Emails are turned off in the settings.' ), 400 );
        if ( ! self::can_email( $c ) ) wp_send_json_error( array( 'message' => (int) $c->dnc_email ? 'This customer unsubscribed from emails.' : 'This customer has no valid email address.' ), 400 );
        if ( ! WCR_Customers::is_due( $c ) ) wp_send_json_error( array( 'message' => 'This customer is not due for a reminder. Reload the page to see their current status.' ), 409 );
        $coupon = null;
        if ( 'voucher' === $type ) {
            $current = WCR_Coupons::current_for( array( $c->id ) );
            $coupon = $current[ (int) $c->id ] ?? null;
            if ( ! WCR_Coupons::is_usable( $coupon ) ) wp_send_json_error( array( 'message' => 'This customer has no valid voucher. Add a voucher first.' ), 400 );
            $coupon = WCR_Coupons::as_sent( $coupon ); // the end date it will have once sent, for the email text
        }
        $token = rtrim( strtr( base64_encode( random_bytes( 32 ) ), '+/', '-_' ), '=' );
        $offer = add_query_arg( WCR_WhatsApp::QUERY_VAR, $token, home_url( '/' ) );
        $unsub = self::unsub_url( $c->id );
        $mail = self::render( $c, $type, $coupon, $offer, $unsub );
        $ok = self::deliver( $c->email, $mail, $unsub );
        $now = current_time( 'mysql', true );
        $wpdb->insert( WCR_DB::contacts_table(), array(
            'customer_id' => (int) $c->id, 'anchor_order_id' => $c->last_order_id, 'created_at' => $now, 'created_by' => get_current_user_id(), 'type' => $type, 'channel' => 'email',
            'coupon_id' => $coupon ? (int) $coupon->id : null, 'email_to' => $c->email, 'subject' => mb_substr( $mail['subject'], 0, 255 ), 'message' => $mail['text'],
            'token_hash' => $ok ? hash( 'sha256', $token ) : null, 'status' => $ok ? 'contacted' : 'failed', 'error' => $ok ? null : mb_substr( self::$error, 0, 255 ),
        ) );
        if ( ! $ok ) wp_send_json_error( array( 'message' => 'The email could not be sent: ' . self::$error ) + WCR_Admin::cells( (int) $c->id ), 500 );
        if ( $coupon ) WCR_Coupons::mark_sent( $coupon );
        WCR_Customers::record_contact( $c, $type, 'email' );
        wp_send_json_success( array( 'message' => sprintf( 'Email sent to %s.', $c->email ) ) + WCR_Admin::cells( (int) $c->id ) );
    }

    // ---------------------------------------------------------------- Preview and test (settings)

    // Settings as posted from the (unsaved) settings form, over the saved ones.
    private static function posted_settings() {
        $s = WCR_Settings::get();
        foreach ( self::defaults() as $key => $default ) {
            if ( ! isset( $_POST[ $key ] ) ) continue;
            $s[ $key ] = self::clean_field( $key, wp_unslash( $_POST[ $key ] ) );
        }
        return $s;
    }
    public static function clean_field( $key, $value ) {
        if ( in_array( $key, array( 'email_header_bg', 'email_primary', 'email_voucher_color', 'email_footer_bg' ), true ) ) { $c = sanitize_hex_color( (string) $value ); return $c ? $c : self::defaults()[ $key ]; }
        if ( in_array( $key, array( 'email_facebook', 'email_instagram', 'email_youtube' ), true ) ) return esc_url_raw( trim( (string) $value ) );
        if ( in_array( $key, array( 'email_from_address', 'email_reply_to' ), true ) ) return sanitize_email( (string) $value );
        if ( in_array( $key, array( 'email_enabled', 'email_logo_id' ), true ) ) return absint( $value );
        return mb_substr( in_array( $key, self::MULTILINE, true ) ? sanitize_textarea_field( (string) $value ) : sanitize_text_field( (string) $value ), 0, 1000 );
    }
    const TEST_MAX_RECIPIENTS = 5;
    const TEST_PAUSE          = 10;                // seconds between test sends, per admin
    const TEST_META           = 'wcr_test_email';  // user meta: the admin's last test recipients
    const SAMPLE_TOKEN        = 'sAmPlE-tOkEn-sAmPlE-tOkEn-sAmPlE-tOkEn-sAmP'; // offer link of previews / tests: lands on the shop, not counted

    // Data for the preview and test emails: a sample customer, or a real one ($customer_id) for their name, last-order
    // product, order count and days. The voucher is always a sample (nothing is created) and the offer and unsubscribe
    // links are test links.
    private static function sample( $customer_id = 0 ) {
        $c = $customer_id ? WCR_Customers::get( $customer_id ) : null;
        if ( ! $c ) $c = (object) array( 'id' => 0, 'name' => 'Najmul Hasan', 'email' => '', 'last_order_id' => 0, 'last_order_at' => gmdate( 'Y-m-d H:i:s', time() - 45 * DAY_IN_SECONDS ), 'order_count' => 3, 'total_value' => 4200 );
        $d = WCR_Coupons::defaults();
        $coupon = (object) array( 'id' => 0, 'code' => '2NDTIME5', 'discount_type' => $d['type'], 'amount' => max( 0, (float) $d['amount'] ), 'max_discount' => 'percent' === $d['type'] && (float) $d['max_discount'] ? (float) $d['max_discount'] : null, 'min_spend' => (float) $d['min_spend'] ? (float) $d['min_spend'] : null, 'valid_days' => (int) $d['valid_days'], 'expires_at' => null, 'wc_coupon_id' => 0, 'sent_at' => null );
        $coupon = WCR_Coupons::as_sent( $coupon );
        $offer = add_query_arg( WCR_WhatsApp::QUERY_VAR, self::SAMPLE_TOKEN, home_url( '/' ) );
        if ( $c->id ) {
            $values = WCR_WhatsApp::values( $c, $coupon, $offer ) + array( '{last_order_items}' => self::last_order_items( $c ) );
        } else {
            $values = WCR_WhatsApp::values( $c, $coupon, $offer ) + array( '{last_order_items}' => 'মেঘের ওপর বাড়ি' );
            $values['{last_order_id}'] = '1245';
        }
        return array( $c, $coupon, $offer, $values );
    }
    private static function test_mail( $type, $customer_id ) {
        list( $c, $coupon, $offer, $values ) = self::sample( $customer_id );
        return self::render( $c, $type, 'voucher' === $type ? $coupon : null, $offer, home_url( '/?' . self::UNSUB_VAR . '=test' ), self::posted_settings(), $values );
    }
    public static function ajax_preview() {
        self::guard();
        $type = 'voucher' === ( $_POST['preview_type'] ?? 'voucher' ) ? 'voucher' : 'plain';
        $mail = self::test_mail( $type, absint( $_POST['customer_id'] ?? 0 ) );
        wp_send_json_success( array( 'subject' => $mail['subject'], 'html' => $mail['html'] ) );
    }
    // Comma-separated test recipients: up to 5 valid addresses, or a WP_Error.
    public static function parse_recipients( $raw ) {
        $list = array_values( array_unique( array_filter( array_map( 'trim', explode( ',', str_replace( ';', ',', (string) $raw ) ) ) ) ) );
        if ( ! $list ) return new WP_Error( 'empty', 'Enter at least one email address.' );
        if ( count( $list ) > self::TEST_MAX_RECIPIENTS ) return new WP_Error( 'many', sprintf( 'Enter at most %d addresses.', self::TEST_MAX_RECIPIENTS ) );
        foreach ( $list as $address ) if ( ! is_email( $address ) ) return new WP_Error( 'invalid', sprintf( '"%s" is not a valid email address.', $address ) );
        return array_map( 'sanitize_email', $list );
    }
    // [Send test email]: the current (unsaved) format to the test addresses only. Nothing is recorded, no voucher is
    // created, and a chosen customer is never emailed.
    public static function ajax_test() {
        self::guard();
        $to = self::parse_recipients( wp_unslash( $_POST['to'] ?? '' ) );
        if ( is_wp_error( $to ) ) wp_send_json_error( array( 'message' => $to->get_error_message() ), 400 );
        $user = get_current_user_id();
        $wait = (int) get_transient( 'wcr_test_mail_' . $user ) - time();
        if ( $wait > 0 ) wp_send_json_error( array( 'message' => sprintf( 'Please wait %d seconds before sending another test email.', $wait ) ), 429 );
        set_transient( 'wcr_test_mail_' . $user, time() + self::TEST_PAUSE, self::TEST_PAUSE );
        update_user_meta( $user, self::TEST_META, implode( ', ', $to ) );
        $type = 'voucher' === ( $_POST['test_type'] ?? 'voucher' ) ? 'voucher' : 'plain';
        $mail = self::test_mail( $type, absint( $_POST['customer_id'] ?? 0 ) );
        $mail['subject'] = '[Test] ' . $mail['subject'];
        if ( ! self::deliver( $to, $mail ) ) { delete_transient( 'wcr_test_mail_' . $user ); wp_send_json_error( array( 'message' => 'The test email could not be sent: ' . self::$error ), 500 ); }
        wp_send_json_success( array( 'message' => sprintf( 'Test email (%1$s) sent to %2$s.', 'voucher' === $type ? 'with voucher' : 'without voucher', implode( ', ', $to ) ) ) );
    }
    // Customer picker for the test email: up to 10 matches by name, phone or email.
    public static function ajax_customer_search() {
        self::guard();
        global $wpdb;
        $q = trim( sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ) );
        if ( mb_strlen( $q ) < 2 ) wp_send_json_success( array() );
        $like = '%' . $wpdb->esc_like( $q ) . '%';
        $digits = preg_replace( '/\D/', '', $q );
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT id, name, email, wa_number, last_order_at FROM ' . WCR_DB::customers_table() . ' WHERE order_count>0 AND (name LIKE %s OR email LIKE %s OR phone LIKE %s' . ( strlen( $digits ) >= 4 ? $wpdb->prepare( ' OR wa_number LIKE %s', '%' . $digits . '%' ) : '' ) . ') ORDER BY last_order_at DESC LIMIT 10', $like, $like, $like ) );
        $out = array();
        foreach ( $rows as $r ) {
            $out[] = array( 'id' => (int) $r->id, 'label' => ( $r->name ? $r->name : 'Customer #' . (int) $r->id ) . ' · ' . ( $r->email ? $r->email : WCR_WhatsApp::display_number( $r->wa_number ) ) . ' · ' . sprintf( _n( '%d day ago', '%d days ago', (int) WCR_Customers::days_since( $r->last_order_at ) ), (int) WCR_Customers::days_since( $r->last_order_at ) ) );
        }
        wp_send_json_success( $out );
    }

    // ---------------------------------------------------------------- Unsubscribe

    private static function unsub_sig( $customer_id ) {
        return substr( hash_hmac( 'sha256', 'wcr-unsub|' . (int) $customer_id, wp_salt( 'auth' ) ), 0, 32 );
    }
    public static function unsub_url( $customer_id ) {
        return add_query_arg( self::UNSUB_VAR, (int) $customer_id . '.' . self::unsub_sig( $customer_id ), home_url( '/' ) );
    }
    // Customer ID from an unsubscribe value, or 0 when it is not genuine.
    public static function parse_unsub( $value ) {
        if ( ! preg_match( '/^(\d+)\.([a-f0-9]{32})$/', (string) $value, $m ) ) return 0;
        return hash_equals( self::unsub_sig( (int) $m[1] ), $m[2] ) ? (int) $m[1] : 0;
    }
    public static function unsubscribe( $customer_id ) {
        global $wpdb;
        $wpdb->query( $wpdb->prepare( 'UPDATE ' . WCR_DB::customers_table() . ' SET dnc_email=1, dnc_email_at=COALESCE(dnc_email_at,%s) WHERE id=%d', current_time( 'mysql', true ), $customer_id ) );
    }
    // GET shows a confirmation button (link scanners in mail systems open links, so a plain GET never unsubscribes);
    // POST unsubscribes, including the one-click POST from the mail client's own Unsubscribe button (RFC 8058).
    public static function maybe_unsubscribe() {
        if ( ! isset( $_GET[ self::UNSUB_VAR ] ) ) return;
        if ( ! defined( 'DONOTCACHEPAGE' ) ) define( 'DONOTCACHEPAGE', true );
        nocache_headers();
        if ( ! headers_sent() ) header( 'X-Robots-Tag: noindex, nofollow' );
        $site = esc_html( wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ) );
        if ( 'test' === $_GET[ self::UNSUB_VAR ] ) wp_die( '<p>This is a test email, so nothing was changed. In real reminder emails this link unsubscribes the customer.</p><p>এটি একটি টেস্ট ইমেইল। আসল ইমেইলে এই লিংক দিয়ে আনসাবস্ক্রাইব করা যায়।</p>', 'Test email', array( 'response' => 200 ) );
        $id = self::parse_unsub( wp_unslash( $_GET[ self::UNSUB_VAR ] ) );
        if ( ! $id ) wp_die( '<p>This unsubscribe link is not valid.</p><p>আনসাবস্ক্রাইব লিংকটি সঠিক নয়।</p>', 'Unsubscribe', array( 'response' => 400 ) );
        if ( isset( $_SERVER['REQUEST_METHOD'] ) && 'POST' === strtoupper( $_SERVER['REQUEST_METHOD'] ) ) {
            self::unsubscribe( $id );
            if ( isset( $_POST['List-Unsubscribe'] ) ) { status_header( 200 ); exit; }
            wp_die( '<p>You will no longer receive reminder emails from ' . $site . '.</p><p>আপনি আর ' . $site . ' থেকে রিমাইন্ডার ইমেইল পাবেন না।</p>', 'Unsubscribed', array( 'response' => 200 ) );
        }
        wp_die( '<p>Stop receiving reminder emails from ' . $site . '?</p><p>আপনি কি ' . $site . ' থেকে রিমাইন্ডার ইমেইল পাওয়া বন্ধ করতে চান?</p><form method="post"><p><button type="submit" class="button" style="padding:10px 22px;font-size:16px;cursor:pointer;">Unsubscribe / আনসাবস্ক্রাইব</button></p></form>', 'Unsubscribe', array( 'response' => 200 ) );
    }
}
