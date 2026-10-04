<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) exit;
wp_clear_scheduled_hook( 'wcr_daily' );
if ( function_exists( 'as_unschedule_all_actions' ) ) as_unschedule_all_actions( 'wcr_rebuild_batch', array(), 'wcr' );
// Data is removed only when the admin chose so under Settings → Privacy & data. Vouchers already created stay in
// WooCommerce (orders reference them) and expire on their own date.
$wcr_settings = get_option( 'wcr_settings' );
if ( empty( $wcr_settings['delete_on_uninstall'] ) ) return;
global $wpdb;
foreach ( array( 'wcr_customers', 'wcr_orders', 'wcr_contacts', 'wcr_coupons' ) as $wcr_table ) $wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . $wcr_table );
foreach ( array( 'wcr_settings', 'wcr_db_version', 'wcr_rebuild', 'wcr_needs_rebuild' ) as $wcr_option ) delete_option( $wcr_option );
