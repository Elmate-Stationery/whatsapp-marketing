<?php
/**
 * Plugin Name: WhatsApp Marketing
 * Description: Finds customers who have not ordered again for a set period and lets you remind them on WhatsApp, with or without a personal voucher. Messages are never sent automatically.
 * Version: 1.1.0
 * Author: Elmate Stationery
 * Author URI: https://elmatestationery.com
 * Requires at least: 6.4
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * WC requires at least: 7.2
 * Text Domain: whatsapp-marketing
 */
if ( ! defined( 'ABSPATH' ) ) exit;

// Safe loading: every check below stops only this plugin (with an admin notice and a server error-log line) instead
// of letting a PHP fatal error take down the whole site.

if ( defined( 'WCR_FILE' ) || class_exists( 'WCR_DB', false ) ) {
    add_action( 'admin_notices', function () {
        if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-error"><p><strong>WhatsApp Marketing</strong> is installed more than once. Under Plugins, deactivate and delete the extra copy.</p></div>';
    } );
    return;
}

define( 'WCR_VERSION', '1.1.0' );
define( 'WCR_FILE', __FILE__ );
define( 'WCR_DIR', plugin_dir_path( __FILE__ ) );
define( 'WCR_URL', plugin_dir_url( __FILE__ ) );

function wcr_boot_error( $message ) {
    error_log( 'WhatsApp Marketing disabled itself: ' . $message );
    add_action( 'admin_notices', function () use ( $message ) {
        if ( current_user_can( 'activate_plugins' ) ) echo '<div class="notice notice-error"><p><strong>WhatsApp Marketing</strong> is not running: ' . esc_html( $message ) . '</p></div>';
    } );
}

// Asset version = plugin version + file modified time, so browsers and cache plugins pick up every change to a file.
function wcr_asset_ver( $path ) {
    $mtime = @filemtime( WCR_DIR . $path );
    return $mtime ? WCR_VERSION . '.' . $mtime : WCR_VERSION;
}

if ( version_compare( PHP_VERSION, '7.4', '<' ) ) {
    wcr_boot_error( sprintf( 'it needs PHP 7.4 or newer, and this server runs PHP %s. Ask your host to upgrade PHP.', PHP_VERSION ) );
    return;
}
$wcr_classes = array(
    'class-wcr-settings'  => 'WCR_Settings',
    'class-wcr-db'        => 'WCR_DB',
    'class-wcr-customers' => 'WCR_Customers',
    'class-wcr-whatsapp'  => 'WCR_WhatsApp',
    'class-wcr-coupons'   => 'WCR_Coupons',
    'class-wcr-storefront' => 'WCR_Storefront',
    'class-wcr-admin'     => 'WCR_Admin',
    'class-wcr-privacy'   => 'WCR_Privacy',
);
$wcr_missing = array();
foreach ( $wcr_classes as $wcr_file => $wcr_class ) {
    if ( ! is_readable( WCR_DIR . 'includes/' . $wcr_file . '.php' ) ) $wcr_missing[] = 'includes/' . $wcr_file . '.php';
    elseif ( class_exists( $wcr_class, false ) ) { wcr_boot_error( sprintf( 'another plugin already uses the class name %s. Deactivate the conflicting plugin.', $wcr_class ) ); return; }
}
if ( $wcr_missing ) {
    wcr_boot_error( 'files are missing, so the upload was probably incomplete: ' . implode( ', ', $wcr_missing ) . '. Upload the complete plugin folder again.' );
    return;
}
try {
    foreach ( array_keys( $wcr_classes ) as $wcr_file ) require_once WCR_DIR . 'includes/' . $wcr_file . '.php';
} catch ( Throwable $e ) {
    wcr_boot_error( sprintf( 'a plugin file could not be loaded (%s in %s line %d).', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
    return;
}

// WooCommerce is mandatory. "Requires Plugins" (WordPress 6.5+) already blocks activation without it; this check also
// covers older WordPress, and stops activation (the plugin stays inactive) when WooCommerce is missing or too old.
function wcr_activate() {
    if ( ! class_exists( 'WooCommerce' ) ) {
        wp_die( '<strong>WhatsApp Marketing</strong> requires WooCommerce. Install and activate WooCommerce first, then activate WhatsApp Marketing.', 'WooCommerce required', array( 'back_link' => true ) );
    }
    if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '7.2', '<' ) ) {
        wp_die( esc_html( sprintf( 'WhatsApp Marketing requires WooCommerce 7.2 or newer. This site runs WooCommerce %s.', WC_VERSION ) ), 'WooCommerce update required', array( 'back_link' => true ) );
    }
    WCR_DB::install();
}
register_activation_hook( __FILE__, 'wcr_activate' );
register_deactivation_hook( __FILE__, array( 'WCR_DB', 'deactivate' ) );

// Orders are only read through wc_get_orders() / WC_Order, so HPOS and legacy storage both work. The storefront part
// (applying a voucher from an offer link) uses the cart API, which the Cart and Checkout blocks share.
add_action( 'before_woocommerce_init', function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WCR_FILE, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', WCR_FILE, true );
    }
} );

add_action( 'plugins_loaded', function () {
    // WooCommerce went away while this plugin was active (e.g. its folder was deleted): deactivate this plugin too.
    if ( ! class_exists( 'WooCommerce' ) ) {
        add_action( 'admin_init', function () {
            if ( ! current_user_can( 'activate_plugins' ) ) return;
            deactivate_plugins( plugin_basename( WCR_FILE ) );
            add_action( 'admin_notices', function () {
                echo '<div class="notice notice-error"><p><strong>WhatsApp Marketing</strong> was deactivated because it requires WooCommerce. Activate WooCommerce, then activate WhatsApp Marketing again.</p></div>';
            } );
        } );
        return;
    }
    if ( defined( 'WC_VERSION' ) && version_compare( WC_VERSION, '7.2', '<' ) ) {
        wcr_boot_error( sprintf( 'it needs WooCommerce 7.2 or newer, and this site runs WooCommerce %s.', WC_VERSION ) );
        return;
    }
    try {
        WCR_DB::maybe_upgrade();
        WCR_Customers::init();
        WCR_WhatsApp::init();
        WCR_Coupons::init();
        WCR_Storefront::init();
        WCR_Admin::init();
        WCR_Privacy::init();
        add_action( 'init', array( 'WCR_DB', 'schedule' ) );
        add_action( 'wcr_daily', array( 'WCR_DB', 'daily' ) );
    } catch ( Throwable $e ) {
        wcr_boot_error( sprintf( 'it failed to start (%s in %s line %d). Please send this message to your developer.', $e->getMessage(), basename( $e->getFile() ), $e->getLine() ) );
    }
} );
