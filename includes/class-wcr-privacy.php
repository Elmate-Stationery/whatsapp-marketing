<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// WordPress personal data export / erase (Tools → Export / Erase Personal Data, by billing email), the daily removal
// of old reminder records, and suggested privacy policy text.
class WCR_Privacy {
    public static function init() {
        add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
        add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
        add_action( 'admin_init', array( __CLASS__, 'policy_text' ) );
    }
    public static function register_exporter( $exporters ) {
        $exporters['wcr'] = array( 'exporter_friendly_name' => 'WhatsApp reminders', 'callback' => array( __CLASS__, 'export' ) );
        return $exporters;
    }
    public static function register_eraser( $erasers ) {
        $erasers['wcr'] = array( 'eraser_friendly_name' => 'WhatsApp reminders', 'callback' => array( __CLASS__, 'erase' ) );
        return $erasers;
    }
    private static function customers( $email ) {
        global $wpdb;
        $email = strtolower( trim( (string) $email ) );
        if ( ! is_email( $email ) ) return array();
        return $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WCR_DB::customers_table() . ' WHERE email=%s OR customer_key=%s', $email, 'e:' . $email ) );
    }
    public static function export( $email, $page = 1 ) {
        global $wpdb;
        $items = array();
        foreach ( self::customers( $email ) as $c ) {
            $items[] = array( 'group_id' => 'wcr-customer', 'group_label' => 'WhatsApp reminder profile', 'item_id' => 'wcr-customer-' . $c->id, 'data' => array(
                array( 'name' => 'Name', 'value' => (string) $c->name ),
                array( 'name' => 'Phone', 'value' => (string) $c->phone ),
                array( 'name' => 'WhatsApp number', 'value' => WCR_WhatsApp::display_number( $c->wa_number ) ),
                array( 'name' => 'Orders counted', 'value' => (string) $c->order_count ),
                array( 'name' => 'Do not contact', 'value' => (int) $c->dnc ? 'Yes' : 'No' ),
            ) );
            $contacts = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM ' . WCR_DB::contacts_table() . ' WHERE customer_id=%d ORDER BY id', $c->id ) );
            foreach ( $contacts as $k ) {
                $items[] = array( 'group_id' => 'wcr-contacts', 'group_label' => 'WhatsApp reminders', 'item_id' => 'wcr-contact-' . $k->id, 'data' => array(
                    array( 'name' => 'Date (UTC)', 'value' => $k->created_at ),
                    array( 'name' => 'Type', 'value' => 'voucher' === $k->type ? 'With voucher' : 'Without voucher' ),
                    array( 'name' => 'Message', 'value' => (string) $k->message ),
                    array( 'name' => 'Link opens', 'value' => (string) $k->link_opens ),
                    array( 'name' => 'Status', 'value' => $k->status ),
                ) );
            }
        }
        return array( 'data' => $items, 'done' => true );
    }
    public static function erase( $email, $page = 1 ) {
        global $wpdb;
        $removed = 0;
        foreach ( self::customers( $email ) as $c ) {
            $removed += (int) $wpdb->delete( WCR_DB::contacts_table(), array( 'customer_id' => (int) $c->id ) );
            $wpdb->delete( WCR_DB::coupons_table(), array( 'customer_id' => (int) $c->id ) );
            $wpdb->delete( WCR_DB::orders_table(), array( 'customer_id' => (int) $c->id ) );
            $removed += (int) $wpdb->delete( WCR_DB::customers_table(), array( 'id' => (int) $c->id ) );
        }
        $messages = $removed ? array( 'WhatsApp reminder records were removed. If the customer\'s orders still exist, their statistics are rebuilt from those orders when the orders next change.' ) : array();
        return array( 'items_removed' => $removed > 0, 'items_retained' => false, 'messages' => $messages, 'done' => true );
    }
    // Daily: reminder records older than the retention period. Contacts of a still-open cycle are kept, so a customer
    // is never shown as not contacted while their contact is recent enough to matter.
    public static function purge_old() {
        global $wpdb;
        $s = WCR_Settings::get();
        $days = (int) $s['log_retention_days'];
        if ( $days < 1 ) return;
        $cut = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
        $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . WCR_DB::contacts_table() . " WHERE created_at < %s AND status<>'contacted' LIMIT 5000", $cut ) );
    }
    public static function policy_text() {
        if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) return;
        wp_add_privacy_policy_content( 'Customer Campaigns', '<p>When you place an order, we use your name, phone number, email and order history (number of orders, order totals and dates) to remind you about our store on WhatsApp after a period without orders. Our staff open WhatsApp with a prepared message and send it themselves; messages are never sent automatically. We record when we contacted you, the message, any voucher we offered, and whether you opened the link in the message. You can ask us not to contact you, or to export or erase this data.</p>' );
    }
}
