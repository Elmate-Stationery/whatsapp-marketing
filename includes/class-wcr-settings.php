<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// All settings live in one option. get() always returns every key (stored values over defaults).
class WCR_Settings {
    const OPTION = 'wcr_settings';
    const DEFAULT_PLAIN   = "Hello {customer_name}, we miss you! It's been {days_since_last_order} days since your last order at {site_name}. Come back and check out our latest products.\n\n{offer_url}";
    const DEFAULT_VOUCHER = "Hello {customer_name}, we miss you! Here's a special {coupon_discount} discount for your next order at {site_name}. Use code {coupon_code}. {coupon_validity}\n\nShop now: {offer_url}";
    // Default of 1.0.0; saved, unedited copies are upgraded to DEFAULT_VOUCHER.
    const LEGACY_VOUCHER  = "Hello {customer_name}, we miss you! Here's a special {coupon_discount} discount for your next order at {site_name}. Use code {coupon_code}. Valid until {coupon_expires}.\n\nShop now: {offer_url}";
    // Orders in these statuses are "open": the customer is mid-purchase, so they are not reminded.
    const OPEN_STATUSES = array( 'pending', 'processing', 'on-hold' );

    private static $cache = null;

    public static function defaults() {
        return array(
            'reminder_days'      => 30,
            'followup_enabled'   => 1,
            'followup_days'      => 30,
            'attribution_days'   => 30,
            'counted_statuses'   => array( 'completed' ),
            'country_code'       => '880',
            'offer_destination'  => 'shop',
            'offer_popup'        => 1,
            'template_plain'     => self::DEFAULT_PLAIN,
            'template_voucher'   => self::DEFAULT_VOUCHER,
            'voucher_enabled'    => 1,
            'coupon_type'        => 'percent',
            'coupon_amount'      => 10,
            'coupon_min_spend'   => '',
            'coupon_max_discount'=> '',
            'coupon_expiry_days' => 7,
            'coupon_prefix'      => '',
            'coupon_name_in_code'=> 1,
            'coupon_usage_limit' => 1,
            'coupon_individual'  => 1,
            'coupon_restrict_email' => 0,
            'log_retention_days' => 365,
            'delete_on_uninstall'=> 0,
        );
    }
    public static function get() {
        if ( null === self::$cache ) {
            $stored = get_option( self::OPTION );
            self::$cache = array_merge( self::defaults(), is_array( $stored ) ? $stored : array() );
        }
        return self::$cache;
    }
    public static function update( $settings ) {
        update_option( self::OPTION, $settings );
        self::$cache = null;
    }
    // Statuses (without the wc- prefix) whose orders count towards order count, total value, AOV and last order.
    public static function counted_statuses() {
        $s = self::get();
        $list = array_values( array_filter( array_map( 'sanitize_key', (array) $s['counted_statuses'] ) ) );
        return $list ? $list : array( 'completed' );
    }
    public static function open_statuses() {
        return array_values( array_diff( self::OPEN_STATUSES, self::counted_statuses() ) );
    }
}
