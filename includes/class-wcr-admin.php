<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// WooCommerce → WhatsApp Customers: Customers (dashboard + list), Conversions, Vouchers and Settings tabs.
class WCR_Admin {
    const PAGE = 'wcr-customers';
    const PER_PAGE = 25;
    const WA_ICON = '<svg class="wcr-wa-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M12.04 2C6.58 2 2.13 6.45 2.13 11.91c0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91C21.95 6.45 17.5 2 12.04 2zm5.8 14.02c-.24.69-1.42 1.32-1.96 1.37-.5.05-1.13.07-1.83-.11-.42-.13-.96-.31-1.65-.61-2.9-1.25-4.8-4.17-4.94-4.37-.14-.19-1.18-1.57-1.18-3 0-1.43.75-2.13 1.02-2.42.26-.29.57-.36.77-.36h.55c.18 0 .42-.07.65.5.24.57.82 1.99.89 2.13.07.14.12.31.02.5-.1.19-.14.31-.29.48-.14.17-.3.37-.43.5-.14.14-.29.3-.13.59.17.29.74 1.22 1.59 1.98 1.09.97 2.01 1.27 2.3 1.41.29.14.46.12.63-.07.17-.19.72-.84.91-1.13.19-.29.38-.24.65-.14.26.1 1.69.8 1.98.94.29.14.48.22.55.34.07.12.07.69-.17 1.37z"/></svg>';
    const NOTICES = array(
        'saved'    => array( 'success', 'Settings saved.' ),
        'rebuild'  => array( 'success', 'The customer list is being rebuilt from your orders in the background.' ),
        'recount'  => array( 'success', 'Settings saved, and customer statistics were recalculated for the new order statuses.' ),
        'amount'   => array( 'error', 'The voucher discount must be greater than 0, and a percentage cannot be more than 100%.' ),
        'statuses' => array( 'error', 'Choose at least one order status to count.' ),
    );

    public static function init() {
        add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
        add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
        add_action( 'admin_post_wcr_save_settings', array( __CLASS__, 'save_settings' ) );
        add_action( 'admin_post_wcr_rebuild', array( __CLASS__, 'rebuild' ) );
        add_action( 'wp_ajax_wcr_history', array( __CLASS__, 'ajax_history' ) );
        add_action( 'wp_ajax_wcr_dnc', array( __CLASS__, 'ajax_dnc' ) );
        add_filter( 'plugin_action_links_' . plugin_basename( WCR_FILE ), array( __CLASS__, 'plugin_links' ) );
    }
    public static function plugin_links( $links ) {
        array_unshift( $links, '<a href="' . esc_url( self::url( array( 'tab' => 'settings' ) ) ) . '">Settings</a>' );
        return $links;
    }
    public static function menu() {
        add_submenu_page( 'woocommerce', 'WhatsApp Customers', 'WhatsApp Customers', 'manage_woocommerce', self::PAGE, array( __CLASS__, 'page' ) );
    }
    private static function url( $args = array() ) {
        return add_query_arg( $args, admin_url( 'admin.php?page=' . self::PAGE ) );
    }
    private static function tab() {
        $tab = sanitize_key( $_GET['tab'] ?? '' );
        return in_array( $tab, array( 'conversions', 'vouchers', 'settings' ), true ) ? $tab : 'customers';
    }
    public static function assets( $hook ) {
        if ( false === strpos( $hook, self::PAGE ) ) return;
        wp_enqueue_style( 'wcr-admin', WCR_URL . 'assets/css/admin.css', array(), wcr_asset_ver( 'assets/css/admin.css' ) );
        if ( 'settings' === self::tab() ) {
            wp_enqueue_script( 'wcr-settings', WCR_URL . 'assets/js/settings.js', array(), wcr_asset_ver( 'assets/js/settings.js' ), true );
            wp_localize_script( 'wcr-settings', 'WCRSettings', array( 'sample' => self::sample_values() ) );
            return;
        }
        wp_enqueue_script( 'wcr-admin', WCR_URL . 'assets/js/admin.js', array(), wcr_asset_ver( 'assets/js/admin.js' ), true );
        wp_localize_script( 'wcr-admin', 'WCRAdmin', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ), 'nonce' => wp_create_nonce( 'wcr_admin' ) ) );
    }
    // Sample values for the live template preview.
    private static function sample_values() {
        $c = (object) array( 'id' => 0, 'name' => 'Rahim Uddin', 'last_order_id' => 0, 'last_order_at' => gmdate( 'Y-m-d H:i:s', time() - 32 * DAY_IN_SECONDS ), 'order_count' => 5, 'total_value' => 8500 );
        $values = WCR_WhatsApp::values( $c, null, add_query_arg( WCR_WhatsApp::QUERY_VAR, 'sAmPlE-tOkEn-sAmPlE-tOkEn-sAmPlE-tOkEn-sAmP', home_url( '/' ) ) );
        $values['{last_order_id}'] = '1245';
        $config = WCR_Coupons::config();
        $s = WCR_Settings::get();
        $coupon = (object) array( 'code' => 'RAHIM10X7KQ', 'discount_type' => 'fixed' === $s['coupon_type'] ? 'fixed' : 'percent', 'amount' => max( 0, (float) $s['coupon_amount'] ),
            'max_discount' => is_wp_error( $config ) ? null : $config['max_discount'], 'min_spend' => is_wp_error( $config ) ? null : $config['min_spend'],
            'expires_at' => gmdate( 'Y-m-d H:i:s', time() + max( 1, (int) $s['coupon_expiry_days'] ) * DAY_IN_SECONDS ) );
        return array_merge( $values, array(
            '{coupon_code}' => $coupon->code, '{coupon_discount}' => WCR_Coupons::discount_label( $coupon ), '{coupon_expires}' => WCR_Coupons::expires_label( $coupon ),
            '{coupon_minimum_spend}' => $coupon->min_spend ? WCR_WhatsApp::plain_price( $coupon->min_spend ) : '',
        ) );
    }

    // ---------------------------------------------------------------- Small helpers

    private static function order_link( $order_id ) {
        $order_id = absint( $order_id );
        if ( ! $order_id ) return '—';
        if ( is_callable( array( 'Automattic\WooCommerce\Utilities\OrderUtil', 'get_order_admin_edit_url' ) ) ) $url = \Automattic\WooCommerce\Utilities\OrderUtil::get_order_admin_edit_url( $order_id );
        else $url = admin_url( 'post.php?post=' . $order_id . '&action=edit' );
        return '<a href="' . esc_url( $url ) . '">#' . $order_id . '</a>';
    }
    // A converting order that no longer counts (cancelled / refunded / deleted since): say so instead of overclaiming.
    private static function order_ended( $status ) {
        if ( null === $status ) return ' <span class="wcr-warn">(order deleted since)</span>';
        if ( in_array( $status, WCR_Settings::counted_statuses(), true ) ) return '';
        return ' <span class="wcr-warn">(order now ' . esc_html( wc_get_order_status_name( $status ) ) . ')</span>';
    }
    private static function user_name( $user_id ) {
        static $names = array();
        $user_id = (int) $user_id;
        if ( ! $user_id ) return '';
        if ( ! isset( $names[ $user_id ] ) ) { $u = get_userdata( $user_id ); $names[ $user_id ] = $u ? $u->display_name : 'user #' . $user_id; }
        return $names[ $user_id ];
    }
    private static function ago( $utc ) { return human_time_diff( strtotime( $utc . ' UTC' ) ) . ' ago'; }
    private static function date_html( $utc, $time = false ) {
        if ( ! $utc ) return '—';
        $format = get_option( 'date_format' ) . ( $time ? ' ' . get_option( 'time_format' ) : '' );
        return esc_html( wp_date( $format, strtotime( $utc . ' UTC' ) ) );
    }
    private static function price( $amount ) { return wp_kses_post( wc_price( (float) $amount ) ); }
    private static function pagination( $total, $page ) {
        $pages = (int) ceil( $total / self::PER_PAGE );
        $html = '<div class="tablenav"><div class="tablenav-pages"><span class="displaying-num">' . esc_html( number_format_i18n( $total ) . ' ' . _n( 'item', 'items', $total ) ) . '</span>';
        if ( $pages > 1 ) $html .= '<span class="pagination-links">' . paginate_links( array( 'base' => add_query_arg( 'paged', '%#%' ), 'format' => '', 'current' => $page, 'total' => $pages ) ) . '</span>';
        return $html . '</div></div>';
    }
    private static function badge( $state, $label ) {
        return '<span class="wcr-badge wcr-badge--' . esc_attr( $state ) . '">' . esc_html( $label ) . '</span>';
    }

    // ---------------------------------------------------------------- Page

    public static function page() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) return;
        $tab = self::tab();
        echo '<div class="wrap wcr-wrap"><h1 class="wp-heading-inline">WhatsApp Customers</h1>';
        $notice = sanitize_key( $_GET['wcr_notice'] ?? '' );
        if ( isset( self::NOTICES[ $notice ] ) ) echo '<div class="notice notice-' . esc_attr( self::NOTICES[ $notice ][0] ) . ' is-dismissible"><p>' . esc_html( self::NOTICES[ $notice ][1] ) . '</p></div>';
        $state = WCR_Customers::rebuild_state();
        if ( $state && 'running' === $state['status'] ) {
            echo '<div class="notice notice-info"><p><strong>Building the customer list from your orders:</strong> ' . esc_html( sprintf( '%s of %s orders read.', number_format_i18n( $state['processed'] ), number_format_i18n( $state['total'] ) ) ) . ' Statistics are complete when this finishes; reload to see progress.</p></div>';
        } elseif ( ! $state && 'settings' !== $tab ) {
            echo '<div class="notice notice-warning"><p>The customer list has not been built yet. It starts automatically; if nothing happens, use <a href="' . esc_url( self::url( array( 'tab' => 'settings', 'section' => 'data' ) ) ) . '">Settings → Data → Rebuild</a>.</p></div>';
        }
        echo '<nav class="nav-tab-wrapper wcr-nav">';
        foreach ( array( 'customers' => 'Customers', 'conversions' => 'Conversions', 'vouchers' => 'Vouchers', 'settings' => 'Settings' ) as $key => $label ) {
            echo '<a href="' . esc_url( self::url( 'customers' === $key ? array() : array( 'tab' => $key ) ) ) . '" class="nav-tab' . ( $key === $tab ? ' nav-tab-active' : '' ) . '">' . esc_html( $label ) . '</a>';
        }
        echo '</nav>';
        if ( 'conversions' === $tab ) self::conversions_tab();
        elseif ( 'vouchers' === $tab ) self::vouchers_tab();
        elseif ( 'settings' === $tab ) self::settings_tab();
        else self::customers_tab();
        echo '</div>';
    }

    // ---------------------------------------------------------------- Customers tab

    private static function filters() {
        $view = sanitize_key( $_GET['view'] ?? 'eligible' );
        $date = function ( $key ) { $v = sanitize_text_field( wp_unslash( $_GET[ $key ] ?? '' ) ); return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $v ) ? $v : ''; };
        return array(
            'view'       => isset( WCR_Customers::VIEWS[ $view ] ) ? $view : 'eligible',
            'ctype'      => in_array( $_GET['ctype'] ?? '', array( 'registered', 'guest' ), true ) ? $_GET['ctype'] : '',
            'min_orders' => absint( $_GET['min_orders'] ?? 0 ),
            'min_spent'  => max( 0, (float) ( $_GET['min_spent'] ?? 0 ) ),
            'from'       => $date( 'from' ),
            'to'         => $date( 'to' ),
            's'          => trim( sanitize_text_field( wp_unslash( $_GET['s'] ?? '' ) ) ),
            'orderby'    => isset( WCR_Customers::ORDERBY[ $_GET['orderby'] ?? '' ] ) ? $_GET['orderby'] : 'days',
            'order'      => 'asc' === ( $_GET['order'] ?? '' ) ? 'asc' : 'desc',
            'page'       => max( 1, absint( $_GET['paged'] ?? 1 ) ),
            'per'        => self::PER_PAGE,
        );
    }
    private static function sort_link( $label, $key, $f, $class = '' ) {
        $current = $f['orderby'] === $key;
        $next = $current && 'desc' === $f['order'] ? 'asc' : 'desc';
        $url = add_query_arg( array( 'orderby' => $key, 'order' => $next, 'paged' => false ) );
        $cls = 'manage-column ' . $class . ( $current ? ' sorted ' . $f['order'] : ' sortable desc' );
        return '<th scope="col" class="' . esc_attr( $cls ) . '"><a href="' . esc_url( $url ) . '"><span>' . esc_html( $label ) . '</span><span class="sorting-indicators"><span class="sorting-indicator asc" aria-hidden="true"></span><span class="sorting-indicator desc" aria-hidden="true"></span></span></a></th>';
    }
    // Offer-link opens of the current cycle's contacts: [ customer_id => row ].
    private static function link_stats( $ids ) {
        global $wpdb;
        $ids = array_filter( array_map( 'absint', (array) $ids ) );
        if ( ! $ids ) return array();
        $rows = $wpdb->get_results( 'SELECT customer_id, COUNT(token_hash) AS links, COALESCE(SUM(link_opens),0) AS opens, MAX(last_open_at) AS last_open FROM ' . WCR_DB::contacts_table() . " WHERE status='contacted' AND customer_id IN (" . implode( ',', $ids ) . ') GROUP BY customer_id' );
        $out = array();
        foreach ( $rows as $r ) $out[ (int) $r->customer_id ] = $r;
        return $out;
    }
    private static function customers_tab() {
        $f = self::filters();
        $sum = WCR_Customers::summary();
        $cards = array(
            array( 'all', 'Total customers', $sum->total, 'with a counted order' ),
            array( 'eligible', 'Eligible for reminder', $sum->eligible, $sum->followup ? sprintf( '+ %s follow-ups due', number_format_i18n( $sum->followup ) ) : 'not contacted this cycle' ),
            array( 'contacted', 'Contacted', $sum->contacted, 'this cycle, no new order yet' ),
            array( 'contacted_voucher', 'Contacted with voucher', $sum->contacted_voucher, 'this cycle' ),
            array( 'contacted_plain', 'Contacted without voucher', $sum->contacted_plain, 'this cycle' ),
            array( 'converted', 'Ordered after reminder', $sum->converted, 'customers, all time' ),
        );
        echo '<div class="wcr-cards">';
        foreach ( $cards as $card ) {
            echo '<a class="wcr-card' . ( $f['view'] === $card[0] ? ' is-current' : '' ) . '" href="' . esc_url( self::url( array( 'view' => $card[0] ) ) ) . '"><span class="wcr-card__label">' . esc_html( $card[1] ) . '</span><span class="wcr-card__value">' . esc_html( number_format_i18n( (int) $card[2] ) ) . '</span><span class="wcr-card__note">' . esc_html( $card[3] ) . '</span></a>';
        }
        echo '</div>';

        // Filters (GET, so a filtered list can be bookmarked).
        echo '<form method="get" class="wcr-filters"><input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '">';
        echo '<label><span>Show</span><select name="view">';
        foreach ( WCR_Customers::VIEWS as $key => $label ) echo '<option value="' . esc_attr( $key ) . '"' . selected( $f['view'], $key, false ) . '>' . esc_html( $label ) . '</option>';
        echo '</select></label>';
        echo '<label><span>Customer type</span><select name="ctype"><option value="">All</option><option value="registered"' . selected( $f['ctype'], 'registered', false ) . '>Registered</option><option value="guest"' . selected( $f['ctype'], 'guest', false ) . '>Guest</option></select></label>';
        echo '<label><span>Min. orders</span><input type="number" min="0" step="1" name="min_orders" class="small-text" value="' . esc_attr( $f['min_orders'] ?: '' ) . '"></label>';
        echo '<label><span>Min. total spent</span><input type="number" min="0" step="any" name="min_spent" class="small-text" value="' . esc_attr( $f['min_spent'] ?: '' ) . '"></label>';
        echo '<label><span>Last order from</span><input type="date" name="from" value="' . esc_attr( $f['from'] ) . '"></label>';
        echo '<label><span>to</span><input type="date" name="to" value="' . esc_attr( $f['to'] ) . '"></label>';
        echo '<label class="wcr-filters__search"><span>Search</span><input type="search" name="s" value="' . esc_attr( $f['s'] ) . '" placeholder="Name, phone or email"></label>';
        echo '<div class="wcr-filters__actions"><button type="submit" class="button">Filter</button> <a class="button-link" href="' . esc_url( self::url( array( 'view' => 'all' ) ) ) . '">Reset</a></div></form>';

        list( $rows, $total ) = WCR_Customers::query( $f );
        $coupons = WCR_Coupons::enabled() ? WCR_Coupons::active_for( $rows ) : array();
        $stats = self::link_stats( $rows ? wp_list_pluck( $rows, 'id' ) : array() );
        echo self::pagination( $total, $f['page'] );
        echo '<div class="wcr-table-scroll"><table class="widefat striped wcr-customers"><thead><tr>';
        echo self::sort_link( 'Customer', 'name', $f, 'column-primary' ) . '<th scope="col">WhatsApp / Phone</th><th scope="col">Last order</th>' . self::sort_link( 'Days since', 'days', $f, 'wcr-num' )
            . self::sort_link( 'Orders', 'orders', $f, 'wcr-num' ) . self::sort_link( 'Total spent', 'spent', $f, 'wcr-num' ) . self::sort_link( 'AOV', 'aov', $f, 'wcr-num' )
            . '<th scope="col">Reminder status</th><th scope="col" class="wcr-wa-col">WhatsApp</th><th scope="col" class="wcr-actions"><span class="screen-reader-text">History</span></th></tr></thead><tbody>';
        if ( ! $rows ) echo '<tr><td colspan="10" class="wcr-empty">No customers match these filters.</td></tr>';
        foreach ( $rows as $c ) echo self::row( $c, $coupons[ (int) $c->id ] ?? null, $stats[ (int) $c->id ] ?? null );
        echo '</tbody></table></div>';
        echo self::pagination( $total, $f['page'] );
        echo '<p class="description wcr-footnote">Statistics count orders with status: ' . esc_html( implode( ', ', array_map( 'wc_get_order_status_name', WCR_Settings::counted_statuses() ) ) ) . ', net of refunds. "Contacted" means WhatsApp was opened with the message; this plugin cannot see whether it was sent, delivered or read.</p>';
        echo '<dialog id="wcr-history" class="wcr-dialog" aria-labelledby="wcr-history-title"><div class="wcr-dialog__header"><h2 id="wcr-history-title">Customer history</h2><button type="button" class="wcr-dialog__close" aria-label="Close"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button></div><div class="wcr-dialog__body"></div></dialog>';
    }
    private static function row( $c, $coupon, $stat ) {
        $id = (int) $c->id;
        $days = WCR_Customers::days_since( $c->last_order_at );
        $count = max( 1, (int) $c->order_count );
        $type = $c->user_id ? '<span class="wcr-ctype wcr-ctype--account">Registered</span>' : '<span class="wcr-ctype">Guest</span>';
        $phone = '' !== $c->wa_number ? '<span class="wcr-wa-number">' . esc_html( WCR_WhatsApp::display_number( $c->wa_number ) ) . '</span>' : '<span class="wcr-warn">No valid WhatsApp number</span>';
        if ( $c->phone && preg_replace( '/\D/', '', $c->phone ) !== $c->wa_number ) $phone .= '<br><small class="wcr-muted">' . esc_html( $c->phone ) . '</small>';
        return '<tr data-row="' . $id . '">'
            . '<td class="column-primary"><strong>' . esc_html( $c->name ? $c->name : '—' ) . '</strong> ' . $type . ( $c->email ? '<br><small class="wcr-muted">' . esc_html( $c->email ) . '</small>' : '' ) . '</td>'
            . '<td>' . $phone . '</td>'
            . '<td>' . self::order_link( $c->last_order_id ) . '<br><small>' . self::date_html( $c->last_order_at ) . '</small></td>'
            . '<td class="wcr-num"><strong>' . esc_html( null === $days ? '—' : sprintf( _n( '%s day', '%s days', $days ), number_format_i18n( $days ) ) ) . '</strong></td>'
            . '<td class="wcr-num">' . esc_html( number_format_i18n( (int) $c->order_count ) ) . '</td>'
            . '<td class="wcr-num">' . self::price( $c->total_value ) . '</td>'
            . '<td class="wcr-num">' . self::price( (float) $c->total_value / $count ) . '</td>'
            . '<td>' . self::status_cell( $c, $stat ) . '</td>'
            . '<td class="wcr-wa-col">' . self::wa_cell( $c, $coupon ) . '</td>'
            . '<td class="wcr-actions"><button type="button" class="button button-small wcr-history" data-id="' . $id . '" aria-haspopup="dialog">History</button></td>'
            . '</tr>';
    }
    private static function state_label( $c ) {
        if ( 'contacted' === $c->state ) return (int) $c->cycle_voucher ? 'Contacted with voucher' : 'Contacted without voucher';
        return WCR_Customers::STATES[ $c->state ] ?? ucfirst( $c->state );
    }
    public static function status_cell( $c, $stat ) {
        $s = WCR_Settings::get();
        $lines = array();
        if ( 'not_eligible' === $c->state ) {
            $left = (int) $s['reminder_days'] - (int) WCR_Customers::days_since( $c->last_order_at );
            if ( $left > 0 ) $lines[] = esc_html( sprintf( _n( 'Eligible in %s day', 'Eligible in %s days', $left ), number_format_i18n( $left ) ) );
        }
        if ( 'open_order' === $c->state ) $lines[] = 'Order ' . self::order_link( $c->open_order_id ) . ' is ' . esc_html( wc_get_order_status_name( $c->open_order_status ) );
        if ( $c->last_contact_at ) {
            $l = 'WhatsApp opened ' . self::ago( $c->last_contact_at );
            if ( $c->last_contact_by ) $l .= ' by ' . self::user_name( $c->last_contact_by );
            if ( (int) $c->cycle_contacts > 1 ) $l .= ' (' . (int) $c->cycle_contacts . '×)';
            if ( 'contacted' !== $c->state ) $l .= (int) $c->cycle_voucher ? ' · with voucher' : ' · without voucher';
            $lines[] = esc_html( $l );
            if ( $stat && (int) $stat->links ) $lines[] = '<span class="wcr-open-state' . ( (int) $stat->opens ? ' is-opened' : '' ) . '">' . esc_html( (int) $stat->opens ? sprintf( 'Offer link opened %d× · last %s', $stat->opens, self::ago( $stat->last_open ) ) : 'Offer link not opened yet' ) . '</span>';
        }
        if ( $c->last_converted_order_id && (int) $c->last_converted_order_id === (int) $c->last_order_id ) $lines[] = '<span class="wcr-converted">✓ Ordered after reminder</span>';
        $html = '<div class="wcr-status-cell" data-status-cell="' . (int) $c->id . '">' . self::badge( $c->state, self::state_label( $c ) );
        if ( $lines ) $html .= '<small class="wcr-status-lines">' . implode( '<br>', $lines ) . '</small>';
        if ( $c->last_contact_at && strtotime( $c->last_contact_at . ' UTC' ) > time() - WCR_WhatsApp::UNDO_HOURS * HOUR_IN_SECONDS ) {
            $html .= '<button type="button" class="button-link wcr-undo" data-id="' . (int) $c->id . '">Not sent? Undo</button>';
        }
        return $html . '</div>';
    }
    // Text for the browser confirmation shown before WhatsApp opens for a customer who is not due ('' = none).
    private static function confirm_text( $c ) {
        $s = WCR_Settings::get();
        $name = $c->name ? $c->name : 'This customer';
        switch ( $c->state ) {
            case 'not_eligible': return sprintf( '%s is not due for a reminder yet: last order %d days ago, reminder period %d days. Open WhatsApp anyway?', $name, WCR_Customers::days_since( $c->last_order_at ), $s['reminder_days'] );
            case 'open_order': return sprintf( '%s has an open order (#%d, %s). Open WhatsApp anyway?', $name, $c->open_order_id, wc_get_order_status_name( $c->open_order_status ) );
            case 'contacted': return sprintf( 'WhatsApp was already opened for %s %s%s. Open it again?', $name, self::ago( $c->last_contact_at ), $c->last_contact_by ? ' by ' . self::user_name( $c->last_contact_by ) : '' );
        }
        return '';
    }
    public static function wa_cell( $c, $coupon ) {
        $id = (int) $c->id;
        $html = '<div class="wcr-wa-cell" data-wa-cell="' . $id . '">';
        if ( (int) $c->dnc ) return $html . '<span class="wcr-muted">Do not contact</span></div>';
        if ( '' === $c->wa_number ) return $html . '<span class="wcr-muted">No valid number</span></div>';
        $confirm = self::confirm_text( $c );
        $attrs = 'data-id="' . $id . '"' . ( $confirm ? ' data-confirm="' . esc_attr( $confirm ) . '"' : '' );
        $html .= '<div class="wcr-wa-buttons"><button type="button" class="button button-small wcr-wa" data-type="plain" ' . $attrs . ' title="' . esc_attr( 'Open WhatsApp with the reminder message for ' . WCR_WhatsApp::display_number( $c->wa_number ) ) . '">' . self::WA_ICON . '<span>WhatsApp</span></button>';
        if ( WCR_Coupons::enabled() ) {
            $title = $coupon ? sprintf( 'Open WhatsApp with voucher %s', $coupon->code ) : 'Create a personal voucher and open WhatsApp with it';
            $html .= '<button type="button" class="button button-small wcr-wa wcr-wa--voucher" data-type="voucher" ' . $attrs . ' title="' . esc_attr( $title ) . '">' . self::WA_ICON . '<span>+ Voucher</span></button>';
        }
        $html .= '</div>';
        if ( $coupon ) $html .= '<small class="wcr-voucher-note">Voucher <code>' . esc_html( $coupon->code ) . '</code> · ' . esc_html( WCR_Coupons::discount_label( $coupon ) ) . ' · until ' . esc_html( WCR_Coupons::expires_label( $coupon ) ) . '</small>';
        return $html . '</div>';
    }
    // Fresh cells for one customer, returned by the AJAX actions so the row updates in place.
    public static function cells( $customer_id ) {
        $c = WCR_Customers::get( $customer_id );
        if ( ! $c ) return array();
        $coupons = WCR_Coupons::enabled() ? WCR_Coupons::active_for( array( $c ) ) : array();
        $stats = self::link_stats( array( $c->id ) );
        return array( 'waCell' => self::wa_cell( $c, $coupons[ (int) $c->id ] ?? null ), 'statusCell' => self::status_cell( $c, $stats[ (int) $c->id ] ?? null ) );
    }

    // ---------------------------------------------------------------- History dialog

    private static function guard() {
        if ( ! current_user_can( 'manage_woocommerce' ) || ! check_ajax_referer( 'wcr_admin', 'nonce', false ) ) wp_send_json_error( array( 'message' => 'You are not allowed to do this, or your login has expired. Reload the page and try again.' ), 403 );
    }
    private static function history_html( $c ) {
        global $wpdb;
        $count = max( 1, (int) $c->order_count );
        $dl = array(
            'WhatsApp'      => '' !== $c->wa_number ? esc_html( WCR_WhatsApp::display_number( $c->wa_number ) ) : '<span class="wcr-warn">No valid number</span>',
            'Phone'         => esc_html( $c->phone ? $c->phone : '—' ),
            'Email'         => esc_html( $c->email ? $c->email : '—' ),
            'Customer type' => $c->user_id ? 'Registered (user #' . (int) $c->user_id . ')' : 'Guest',
            'Orders'        => esc_html( number_format_i18n( (int) $c->order_count ) ) . ' · ' . self::price( $c->total_value ) . ' total · ' . self::price( (float) $c->total_value / $count ) . ' AOV',
            'First order'   => self::date_html( $c->first_order_at ),
            'Last order'    => self::order_link( $c->last_order_id ) . ' · ' . self::date_html( $c->last_order_at ),
            'Status'        => self::badge( $c->state, self::state_label( $c ) ),
            'Conversions'   => esc_html( number_format_i18n( (int) $c->conversions ) ),
        );
        $html = '<div class="wcr-history-head"><dl class="wcr-dl">';
        foreach ( $dl as $k => $v ) $html .= '<dt>' . esc_html( $k ) . '</dt><dd>' . $v . '</dd>';
        $html .= '</dl><div class="wcr-dnc-box">' . ( (int) $c->dnc
            ? '<p><strong>Do not contact</strong> since ' . self::date_html( $c->dnc_at ) . '. WhatsApp buttons are hidden for this customer.</p><button type="button" class="button wcr-dnc" data-id="' . (int) $c->id . '" data-on="0">Allow contact again</button>'
            : '<p>If this customer asked not to be messaged, mark them so nobody contacts them again.</p><button type="button" class="button wcr-dnc" data-id="' . (int) $c->id . '" data-on="1">Mark as Do not contact</button>' ) . '</div></div>';
        $contacts = $wpdb->get_results( $wpdb->prepare( 'SELECT k.*, cp.code, cp.status AS coupon_status, cp.expires_at AS coupon_expires, o.status AS order_status FROM ' . WCR_DB::contacts_table() . ' k LEFT JOIN ' . WCR_DB::coupons_table() . ' cp ON cp.id=k.coupon_id LEFT JOIN ' . WCR_DB::orders_table() . ' o ON o.order_id=k.order_id WHERE k.customer_id=%d ORDER BY k.created_at DESC, k.id DESC LIMIT 200', $c->id ) );
        $html .= '<h3>Reminder history</h3>';
        if ( ! $contacts ) return $html . '<p class="wcr-muted">No WhatsApp reminders yet.</p>';
        $html .= '<div class="wcr-table-scroll"><table class="widefat striped wcr-history-table"><thead><tr><th>Date</th><th>Type</th><th>Voucher</th><th>Offer link</th><th>Status</th></tr></thead><tbody>';
        foreach ( $contacts as $k ) {
            $voucher = '—';
            if ( $k->coupon_id && $k->code ) {
                $cs = WCR_Coupons::display_status( (object) array( 'status' => $k->coupon_status, 'expires_at' => $k->coupon_expires ) );
                $voucher = '<code>' . esc_html( $k->code ) . '</code> ' . self::badge( 'coupon-' . $cs, WCR_Coupons::STATUSES[ $cs ] ?? ucfirst( (string) $cs ) );
            }
            $link = $k->token_hash ? ( (int) $k->link_opens ? esc_html( sprintf( 'Opened %d× · last %s', $k->link_opens, self::ago( $k->last_open_at ) ) ) . ( $k->last_device ? '<br><small class="wcr-muted">' . esc_html( $k->last_device ) . '</small>' : '' ) : 'Not opened' ) : '<span class="wcr-muted">No link</span>';
            switch ( $k->status ) {
                case 'converted': $status = self::badge( 'converted', 'Converted' ) . '<br><small>Order ' . self::order_link( $k->order_id ) . ' · ' . self::price( $k->order_value ) . ( (int) $k->coupon_used ? ' · used the voucher' : '' ) . self::order_ended( $k->order_status ) . '</small>'; break;
                case 'closed': $status = self::badge( 'closed', 'Closed' ) . ( $k->order_id ? '<br><small>Cycle ended with order ' . self::order_link( $k->order_id ) . ', not attributed to this reminder</small>' : '' ); break;
                case 'undone': $status = self::badge( 'undone', 'Not sent (undone)' ); break;
                default: $status = self::badge( 'contacted', 'Contacted' ) . '<br><small>Waiting for an order</small>';
            }
            $html .= '<tr><td>' . self::date_html( $k->created_at, true ) . ( $k->created_by ? '<br><small class="wcr-muted">by ' . esc_html( self::user_name( $k->created_by ) ) . '</small>' : '' ) . '</td>'
                . '<td>' . ( 'voucher' === $k->type ? 'With voucher' : 'Without voucher' ) . '</td><td>' . $voucher . '</td><td>' . $link . '</td><td>' . $status . '</td></tr>';
            if ( $k->message ) $html .= '<tr class="wcr-msg-row"><td colspan="5"><details><summary>Message</summary><div class="wcr-wa-bubble">' . esc_html( $k->message ) . '</div></details></td></tr>';
        }
        return $html . '</tbody></table></div>';
    }
    private static function customer_or_fail() {
        $c = WCR_Customers::get( absint( $_POST['id'] ?? 0 ) );
        if ( ! $c ) wp_send_json_error( array( 'message' => 'This customer no longer exists.' ), 404 );
        return $c;
    }
    public static function ajax_history() {
        self::guard();
        $c = self::customer_or_fail();
        wp_send_json_success( array( 'title' => $c->name ? $c->name : 'Customer #' . (int) $c->id, 'html' => self::history_html( $c ) ) );
    }
    public static function ajax_dnc() {
        self::guard();
        $c = self::customer_or_fail();
        global $wpdb;
        $on = ! empty( $_POST['on'] );
        $wpdb->update( WCR_DB::customers_table(), array( 'dnc' => $on ? 1 : 0, 'dnc_at' => $on ? current_time( 'mysql', true ) : null ), array( 'id' => (int) $c->id ) );
        $c = WCR_Customers::get( (int) $c->id );
        wp_send_json_success( array( 'html' => self::history_html( $c ) ) + self::cells( (int) $c->id ) );
    }

    // ---------------------------------------------------------------- Conversions tab

    private static function conversions_tab() {
        global $wpdb;
        $kt = WCR_DB::contacts_table(); $ct = WCR_DB::customers_table(); $cpt = WCR_DB::coupons_table(); $ot = WCR_DB::orders_table();
        // Revenue only from converting orders that still count (not cancelled / refunded / deleted since).
        $in = implode( ',', array_map( function ( $v ) use ( $wpdb ) { return $wpdb->prepare( '%s', $v ); }, WCR_Settings::counted_statuses() ) );
        $sum = $wpdb->get_row( "SELECT COUNT(*) AS sent, COALESCE(SUM(k.status='converted'),0) AS converted, COALESCE(SUM(k.status='contacted'),0) AS waiting,
            COALESCE(SUM(k.type='voucher'),0) AS sent_v, COALESCE(SUM(k.type='voucher' AND k.status='converted'),0) AS conv_v, COALESCE(SUM(k.coupon_used),0) AS coupon_used,
            COALESCE(SUM(CASE WHEN k.status='converted' AND o.status IN ($in) THEN o.net_total END),0) AS revenue
            FROM $kt k LEFT JOIN $ot o ON o.order_id=k.order_id WHERE k.status<>'undone'" );
        $rate = function ( $part, $all ) { return $all ? round( 100 * $part / $all, 1 ) . '%' : '—'; };
        $s = WCR_Settings::get();
        $cards = array(
            array( 'Reminders sent', number_format_i18n( $sum->sent ), sprintf( '%s still waiting for an order', number_format_i18n( $sum->waiting ) ) ),
            array( 'Converted', number_format_i18n( $sum->converted ), $rate( $sum->converted, $sum->sent ) . ' of reminders' ),
            array( 'With voucher', $rate( $sum->conv_v, $sum->sent_v ), sprintf( '%s of %s converted', number_format_i18n( $sum->conv_v ), number_format_i18n( $sum->sent_v ) ) ),
            array( 'Without voucher', $rate( $sum->converted - $sum->conv_v, $sum->sent - $sum->sent_v ), sprintf( '%s of %s converted', number_format_i18n( $sum->converted - $sum->conv_v ), number_format_i18n( $sum->sent - $sum->sent_v ) ) ),
            array( 'Revenue from converted orders', wp_strip_all_tags( wc_price( $sum->revenue ) ), sprintf( '%s orders used the voucher', number_format_i18n( $sum->coupon_used ) ) ),
        );
        echo '<div class="wcr-cards">';
        foreach ( $cards as $card ) echo '<div class="wcr-card"><span class="wcr-card__label">' . esc_html( $card[0] ) . '</span><span class="wcr-card__value">' . esc_html( $card[1] ) . '</span><span class="wcr-card__note">' . esc_html( $card[2] ) . '</span></div>';
        echo '</div><p class="description">A reminder converts when the customer places a new counted order within ' . esc_html( (int) $s['attribution_days'] ) . ' days after it, or uses its voucher. Undone reminders are not counted.</p>';
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $kt WHERE status='converted'" );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT k.*, c.name, c.id AS cid, cp.code, o.status AS order_status FROM $kt k JOIN $ct c ON c.id=k.customer_id LEFT JOIN $cpt cp ON cp.id=k.coupon_id LEFT JOIN $ot o ON o.order_id=k.order_id WHERE k.status='converted' ORDER BY k.converted_at DESC, k.id DESC LIMIT %d, %d", ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) );
        echo self::pagination( $total, $page );
        echo '<div class="wcr-table-scroll"><table class="widefat striped"><thead><tr><th>Customer</th><th>Reminder</th><th>Type</th><th>Voucher</th><th>New order</th><th class="wcr-num">Order value</th><th>Converted</th></tr></thead><tbody>';
        if ( ! $rows ) echo '<tr><td colspan="7" class="wcr-empty">No converted reminders yet.</td></tr>';
        foreach ( $rows as $r ) {
            echo '<tr><td>' . esc_html( $r->name ? $r->name : 'Customer #' . (int) $r->cid ) . '</td><td>' . self::date_html( $r->created_at ) . '</td><td>' . ( 'voucher' === $r->type ? 'With voucher' : 'Without voucher' ) . '</td>'
                . '<td>' . ( $r->code ? '<code>' . esc_html( $r->code ) . '</code><br><small>' . ( (int) $r->coupon_used ? 'used in this order' : 'not used in this order' ) . '</small>' : '—' ) . '</td>'
                . '<td>' . self::order_link( $r->order_id ) . self::order_ended( $r->order_status ) . '</td><td class="wcr-num">' . self::price( $r->order_value ) . '</td><td>' . self::date_html( $r->converted_at ) . '</td></tr>';
        }
        echo '</tbody></table></div>' . self::pagination( $total, $page );
    }

    // ---------------------------------------------------------------- Vouchers tab

    private static function vouchers_tab() {
        global $wpdb;
        $p = WCR_Coupons::performance();
        $cards = array(
            array( 'Generated', number_format_i18n( $p->generated ), sprintf( '%s still active', number_format_i18n( $p->active ) ) ),
            array( 'Sent / shared', number_format_i18n( $p->sent ), 'WhatsApp opened with the voucher' ),
            array( 'Used', number_format_i18n( $p->used ), 'in an order (not cancelled)' ),
            array( 'Expired', number_format_i18n( $p->expired ), 'unused' ),
            array( 'Converted orders', number_format_i18n( $p->converted ), 'counted orders that contain the voucher' ),
            array( 'Revenue generated', wp_strip_all_tags( wc_price( $p->revenue ) ), sprintf( 'discount given: %s', wp_strip_all_tags( wc_price( $p->discount ) ) ) ),
        );
        echo '<div class="wcr-cards">';
        foreach ( $cards as $card ) echo '<div class="wcr-card"><span class="wcr-card__label">' . esc_html( $card[0] ) . '</span><span class="wcr-card__value">' . esc_html( $card[1] ) . '</span><span class="wcr-card__note">' . esc_html( $card[2] ) . '</span></div>';
        echo '</div><p class="description">Revenue counts only orders that actually contain the voucher.</p>';
        $cpt = WCR_DB::coupons_table(); $ct = WCR_DB::customers_table();
        $page = max( 1, absint( $_GET['paged'] ?? 1 ) );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $cpt" );
        $rows = $wpdb->get_results( $wpdb->prepare( "SELECT cp.*, c.name FROM $cpt cp LEFT JOIN $ct c ON c.id=cp.customer_id ORDER BY cp.id DESC LIMIT %d, %d", ( $page - 1 ) * self::PER_PAGE, self::PER_PAGE ) );
        echo self::pagination( $total, $page );
        echo '<div class="wcr-table-scroll"><table class="widefat striped"><thead><tr><th>Code</th><th>Customer</th><th>Discount</th><th>Created</th><th>Valid until</th><th>Status</th><th>Order</th></tr></thead><tbody>';
        if ( ! $rows ) echo '<tr><td colspan="7" class="wcr-empty">No vouchers yet. They are created when you click WhatsApp + Voucher.</td></tr>';
        foreach ( $rows as $r ) {
            $st = WCR_Coupons::display_status( $r );
            echo '<tr><td><code>' . esc_html( $r->code ) . '</code></td><td>' . esc_html( $r->name ? $r->name : '—' ) . '</td><td>' . esc_html( WCR_Coupons::discount_label( $r ) ) . ( $r->min_spend ? '<br><small>min. ' . esc_html( WCR_WhatsApp::plain_price( $r->min_spend ) ) . '</small>' : '' ) . '</td>'
                . '<td>' . self::date_html( $r->created_at ) . ( $r->created_by ? '<br><small class="wcr-muted">by ' . esc_html( self::user_name( $r->created_by ) ) . '</small>' : '' ) . '</td><td>' . self::date_html( $r->expires_at ) . '</td>'
                . '<td>' . self::badge( 'coupon-' . $st, WCR_Coupons::STATUSES[ $st ] ?? ucfirst( $st ) ) . '</td><td>' . ( $r->order_id ? self::order_link( $r->order_id ) . ( null !== $r->discount_total ? '<br><small>' . self::price( $r->discount_total ) . ' off</small>' : '' ) : '—' ) . '</td></tr>';
        }
        echo '</tbody></table></div>' . self::pagination( $total, $page );
    }

    // ---------------------------------------------------------------- Settings tab

    const SECTIONS = array( 'general' => 'Reminders', 'messages' => 'Messages', 'voucher' => 'Voucher', 'data' => 'Privacy & data' );
    private static function section() {
        $section = sanitize_key( $_GET['section'] ?? '' );
        return isset( self::SECTIONS[ $section ] ) ? $section : 'general';
    }
    // Statuses an admin may count: anything except unpaid / failed / cancelled / refunded.
    private static function countable_statuses() {
        $out = array();
        foreach ( wc_get_order_statuses() as $key => $label ) {
            $slug = 0 === strpos( $key, 'wc-' ) ? substr( $key, 3 ) : $key;
            if ( ! in_array( $slug, array( 'pending', 'failed', 'cancelled', 'refunded', 'checkout-draft' ), true ) ) $out[ $slug ] = $label;
        }
        return $out;
    }
    private static function chips( $target ) {
        $html = '<div class="wcr-chips"><span class="description">Insert:</span> ';
        foreach ( WCR_WhatsApp::PLACEHOLDERS as $ph => $desc ) $html .= '<button type="button" class="button button-small wcr-chip" data-target="' . esc_attr( $target ) . '" data-insert="' . esc_attr( $ph ) . '" title="' . esc_attr( $desc ) . '">' . esc_html( $ph ) . '</button>';
        return $html . '</div>';
    }
    private static function settings_tab() {
        $s = WCR_Settings::get();
        $section = self::section();
        echo '<ul class="subsubsub wcr-sections">';
        $links = array();
        foreach ( self::SECTIONS as $key => $label ) $links[] = '<li><a href="' . esc_url( self::url( array( 'tab' => 'settings', 'section' => $key ) ) ) . '"' . ( $key === $section ? ' class="current"' : '' ) . '>' . esc_html( $label ) . '</a></li>';
        echo implode( ' | ', $links ) . '</ul><br class="clear">';
        echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="wcr-settings">';
        wp_nonce_field( 'wcr_save_settings' );
        echo '<input type="hidden" name="action" value="wcr_save_settings"><input type="hidden" name="section" value="' . esc_attr( $section ) . '"><table class="form-table" role="presentation">';
        if ( 'general' === $section ) {
            $presets = '';
            foreach ( array( 7, 15, 30, 45, 60 ) as $d ) $presets .= '<button type="button" class="button button-small wcr-preset" data-target="wcr-days" data-value="' . $d . '">' . $d . ' days</button> ';
            echo '<tr><th scope="row"><label for="wcr-days">Reminder period</label></th><td><input type="number" id="wcr-days" name="reminder_days" min="1" max="365" class="small-text" value="' . esc_attr( $s['reminder_days'] ) . '"> days after the last order <div class="wcr-presets">' . $presets . '</div><p class="description">A customer becomes eligible when this many calendar days (store time) have passed since their last counted order and they have no open order (Pending payment, Processing or On hold).</p></td></tr>';
            echo '<tr><th scope="row">Follow-ups</th><td><label><input type="checkbox" name="followup_enabled" value="1" ' . checked( ! empty( $s['followup_enabled'] ), true, false ) . '> Show contacted customers as <em>Follow-up due</em></label> after <input type="number" name="followup_days" min="1" max="365" class="small-text" value="' . esc_attr( $s['followup_days'] ) . '"> days without a new order<p class="description">Otherwise a contacted customer stays <em>Contacted</em> until they order again. You can always contact someone again after confirming.</p></td></tr>';
            echo '<tr><th scope="row"><label for="wcr-attr">Conversion window</label></th><td><input type="number" id="wcr-attr" name="attribution_days" min="1" max="365" class="small-text" value="' . esc_attr( $s['attribution_days'] ) . '"> days<p class="description">A new counted order within this many days after a reminder marks that reminder <em>Converted</em>. An order that uses the reminder&#8217;s voucher always counts.</p></td></tr>';
            echo '<tr><th scope="row">Counted order statuses</th><td><fieldset>';
            foreach ( self::countable_statuses() as $slug => $label ) echo '<label class="wcr-check"><input type="checkbox" name="counted_statuses[]" value="' . esc_attr( $slug ) . '" ' . checked( in_array( $slug, WCR_Settings::counted_statuses(), true ), true, false ) . '> ' . esc_html( $label ) . '</label>';
            echo '</fieldset><p class="description">Order count, total value, AOV, last order and conversions use only these statuses (recommended: Completed only). Values are net of refunds; fully refunded orders are not counted.</p></td></tr>';
            echo '<tr><th scope="row"><label for="wcr-cc">Default country code</label></th><td><span class="wcr-cc-prefix">+</span><input type="text" id="wcr-cc" name="country_code" value="' . esc_attr( $s['country_code'] ) . '" class="small-text" inputmode="numeric" pattern="[0-9]{1,4}" maxlength="4"><p class="description">For phone numbers without a country code, unless the order has a billing country (e.g. <code>01712345678</code> becomes <code>+8801712345678</code>). Changing it rebuilds the customer list.</p></td></tr>';
            echo '<tr><th scope="row">Offer link opens</th><td><label><input type="radio" name="offer_destination" value="shop" ' . checked( $s['offer_destination'], 'shop', false ) . '> Shop page</label><br><label><input type="radio" name="offer_destination" value="home" ' . checked( $s['offer_destination'], 'home', false ) . '> Home page</label><p class="description">Where <code>{offer_url}</code> takes the customer. Opening it applies their voucher to the cart automatically.</p></td></tr>';
        } elseif ( 'messages' === $section ) {
            echo '<tr><th scope="row"><label for="wcr-tpl-plain">Without voucher</label></th><td><textarea id="wcr-tpl-plain" class="large-text wcr-template" data-preview="wcr-prev-plain" name="template_plain" rows="7" maxlength="2000">' . esc_textarea( $s['template_plain'] ) . '</textarea>' . self::chips( 'wcr-tpl-plain' ) . '<div class="wcr-preview"><span class="wcr-preview__label">Preview with sample data</span><div class="wcr-wa-bubble" id="wcr-prev-plain" aria-live="polite"></div></div></td></tr>';
            echo '<tr><th scope="row"><label for="wcr-tpl-voucher">With voucher</label></th><td><textarea id="wcr-tpl-voucher" class="large-text wcr-template" data-preview="wcr-prev-voucher" name="template_voucher" rows="7" maxlength="2000">' . esc_textarea( $s['template_voucher'] ) . '</textarea>' . self::chips( 'wcr-tpl-voucher' ) . '<div class="wcr-preview"><span class="wcr-preview__label">Preview with sample data</span><div class="wcr-wa-bubble" id="wcr-prev-voucher" aria-live="polite"></div></div><p class="description">Voucher placeholders are empty in the message without a voucher. <code>{offer_url}</code> is a personal link: it shows you whether the customer opened it, and applies their voucher to the cart.</p></td></tr>';
        } elseif ( 'voucher' === $section ) {
            echo '<tr><th scope="row">Vouchers</th><td><label><input type="checkbox" name="voucher_enabled" value="1" ' . checked( ! empty( $s['voucher_enabled'] ), true, false ) . '> Show a <em>WhatsApp + Voucher</em> button</label><p class="description">A unique WooCommerce coupon is created only when you click it, and reused for that customer until it expires or they order again.</p></td></tr>';
            echo '<tr><th scope="row">Discount type</th><td><label><input type="radio" name="coupon_type" value="percent" ' . checked( $s['coupon_type'], 'percent', false ) . '> Percentage discount</label><br><label><input type="radio" name="coupon_type" value="fixed" ' . checked( $s['coupon_type'], 'fixed', false ) . '> Fixed cart discount</label></td></tr>';
            echo '<tr><th scope="row"><label for="wcr-amount">Discount amount</label></th><td><input type="number" id="wcr-amount" name="coupon_amount" min="0" step="any" class="small-text" value="' . esc_attr( $s['coupon_amount'] ) . '"><p class="description">Percent, or an amount in ' . esc_html( get_woocommerce_currency() ) . ' for a fixed discount.</p></td></tr>';
            echo '<tr><th scope="row"><label for="wcr-min">Minimum spend</label></th><td><input type="number" id="wcr-min" name="coupon_min_spend" min="0" step="any" class="small-text" value="' . esc_attr( $s['coupon_min_spend'] ) . '"> <span class="description">Leave empty for none.</span></td></tr>';
            echo '<tr class="wcr-max-row"><th scope="row"><label for="wcr-max">Maximum discount</label></th><td><input type="number" id="wcr-max" name="coupon_max_discount" min="0" step="any" class="small-text" value="' . esc_attr( $s['coupon_max_discount'] ) . '"> <span class="description">Percentage vouchers only. Leave empty for no cap.</span></td></tr>';
            echo '<tr><th scope="row"><label for="wcr-exp">Valid for</label></th><td><input type="number" id="wcr-exp" name="coupon_expiry_days" min="1" max="365" class="small-text" value="' . esc_attr( $s['coupon_expiry_days'] ) . '"> days <span class="description">(until the end of the last day)</span></td></tr>';
            echo '<tr><th scope="row"><label for="wcr-prefix">Code prefix</label></th><td><input type="text" id="wcr-prefix" name="coupon_prefix" maxlength="10" class="small-text" value="' . esc_attr( $s['coupon_prefix'] ) . '"> <label><input type="checkbox" name="coupon_name_in_code" value="1" ' . checked( ! empty( $s['coupon_name_in_code'] ), true, false ) . '> Include the customer&#8217;s first name</label><p class="description">Code = prefix + first name + discount + 4 random characters, e.g. <code>RAHIM10X7KQ</code>. Letters A–Z and digits only.</p></td></tr>';
            echo '<tr><th scope="row"><label for="wcr-limit">Usage limit</label></th><td><input type="number" id="wcr-limit" name="coupon_usage_limit" min="0" max="100" class="small-text" value="' . esc_attr( $s['coupon_usage_limit'] ) . '"> <span class="description">times in total (0 = unlimited until it expires).</span></td></tr>';
            echo '<tr><th scope="row">Restrictions</th><td><label><input type="checkbox" name="coupon_individual" value="1" ' . checked( ! empty( $s['coupon_individual'] ), true, false ) . '> Individual use only (cannot be combined with other coupons)</label><br><label><input type="checkbox" name="coupon_restrict_email" value="1" ' . checked( ! empty( $s['coupon_restrict_email'] ), true, false ) . '> Only for the customer&#8217;s billing email</label><p class="description">The email restriction applies only to customers with an email, and they must check out with that email.</p></td></tr>';
        } else {
            echo '<tr><th scope="row"><label for="wcr-ret">Keep reminder history</label></th><td><input type="number" id="wcr-ret" name="log_retention_days" min="0" max="3650" class="small-text" value="' . esc_attr( $s['log_retention_days'] ) . '"> days <span class="description">(0 = keep forever)</span><p class="description">Older reminder records (including the message text) are deleted daily. Customer statistics are not affected.</p></td></tr>';
            echo '<tr><th scope="row">Uninstall</th><td><label><input type="checkbox" name="delete_on_uninstall" value="1" ' . checked( ! empty( $s['delete_on_uninstall'] ), true, false ) . '> Delete all plugin data when the plugin is deleted</label><p class="description">Customer statistics, reminder history, voucher records and settings. WooCommerce coupons already created stay in WooCommerce.</p></td></tr>';
        }
        echo '</table>';
        submit_button( 'Save changes' );
        echo '</form>';
        if ( 'data' === $section ) {
            $state = WCR_Customers::rebuild_state();
            echo '<hr><h2>Customer list</h2><p>The list is kept up to date automatically as orders change. Rebuild it if it ever looks out of step with your orders.</p>';
            if ( $state ) echo '<p class="description">' . esc_html( 'running' === $state['status'] ? sprintf( 'Rebuilding now: %s of %s orders read.', number_format_i18n( $state['processed'] ), number_format_i18n( $state['total'] ) ) : sprintf( 'Last built: %s (%s orders).', wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $state['finished'] . ' UTC' ) ), number_format_i18n( $state['processed'] ) ) ) . '</p>';
            echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
            wp_nonce_field( 'wcr_rebuild' );
            echo '<input type="hidden" name="action" value="wcr_rebuild"><button type="submit" class="button">Rebuild customer list</button></form>';
            echo '<h2>Privacy</h2><p>Only admins with the <em>manage_woocommerce</em> capability can see this data. Customer records and reminder history are included in WordPress&#8217;s <em>Export / Erase Personal Data</em> tools (by billing email). No payment information and no checkout-field data are stored.</p>';
            echo '<p><strong>Consent:</strong> only message customers who expect to hear from you. Unsolicited WhatsApp messages can get your number reported or banned, and some countries require prior consent. Use <em>Do not contact</em> in a customer&#8217;s history when they ask not to be messaged.</p>';
        }
    }
    public static function save_settings() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'You are not allowed to do this.' );
        check_admin_referer( 'wcr_save_settings' );
        $s = WCR_Settings::get();
        $old = $s;
        $section = isset( self::SECTIONS[ $_POST['section'] ?? '' ] ) ? $_POST['section'] : 'general';
        $int = function ( $key, $min, $max, $default ) { $v = isset( $_POST[ $key ] ) ? (int) $_POST[ $key ] : $default; return min( $max, max( $min, $v ) ); };
        $num = function ( $key ) { $v = isset( $_POST[ $key ] ) ? trim( (string) wp_unslash( $_POST[ $key ] ) ) : ''; return '' === $v ? '' : max( 0, (float) $v ); };
        $back = self::url( array( 'tab' => 'settings', 'section' => $section ) );
        $notice = 'saved';
        if ( 'general' === $section ) {
            $allowed = array_keys( self::countable_statuses() );
            $counted = array_values( array_intersect( array_map( 'sanitize_key', (array) wp_unslash( $_POST['counted_statuses'] ?? array() ) ), $allowed ) );
            if ( ! $counted ) { wp_safe_redirect( add_query_arg( 'wcr_notice', 'statuses', $back ) ); exit; }
            $cc = substr( preg_replace( '/\D/', '', (string) wp_unslash( $_POST['country_code'] ?? '' ) ), 0, 4 );
            $s['reminder_days'] = $int( 'reminder_days', 1, 365, 30 );
            $s['followup_enabled'] = empty( $_POST['followup_enabled'] ) ? 0 : 1;
            $s['followup_days'] = $int( 'followup_days', 1, 365, 30 );
            $s['attribution_days'] = $int( 'attribution_days', 1, 365, 30 );
            $s['counted_statuses'] = $counted;
            $s['country_code'] = '' !== $cc ? $cc : '880';
            $s['offer_destination'] = 'home' === ( $_POST['offer_destination'] ?? '' ) ? 'home' : 'shop';
        } elseif ( 'messages' === $section ) {
            foreach ( array( 'template_plain' => WCR_Settings::DEFAULT_PLAIN, 'template_voucher' => WCR_Settings::DEFAULT_VOUCHER ) as $key => $default ) {
                $t = isset( $_POST[ $key ] ) ? mb_substr( sanitize_textarea_field( wp_unslash( $_POST[ $key ] ) ), 0, 2000 ) : '';
                $s[ $key ] = '' !== trim( $t ) ? $t : $default;
            }
        } elseif ( 'voucher' === $section ) {
            $type = 'fixed' === ( $_POST['coupon_type'] ?? '' ) ? 'fixed' : 'percent';
            $amount = (float) ( $_POST['coupon_amount'] ?? 0 );
            if ( $amount <= 0 || ( 'percent' === $type && $amount > 100 ) ) { wp_safe_redirect( add_query_arg( 'wcr_notice', 'amount', $back ) ); exit; }
            $s['voucher_enabled'] = empty( $_POST['voucher_enabled'] ) ? 0 : 1;
            $s['coupon_type'] = $type;
            $s['coupon_amount'] = $amount;
            $s['coupon_min_spend'] = $num( 'coupon_min_spend' );
            $s['coupon_max_discount'] = $num( 'coupon_max_discount' );
            $s['coupon_expiry_days'] = $int( 'coupon_expiry_days', 1, 365, 7 );
            $s['coupon_prefix'] = substr( strtoupper( preg_replace( '/[^A-Za-z0-9]/', '', (string) wp_unslash( $_POST['coupon_prefix'] ?? '' ) ) ), 0, 10 );
            $s['coupon_name_in_code'] = empty( $_POST['coupon_name_in_code'] ) ? 0 : 1;
            $s['coupon_usage_limit'] = $int( 'coupon_usage_limit', 0, 100, 1 );
            $s['coupon_individual'] = empty( $_POST['coupon_individual'] ) ? 0 : 1;
            $s['coupon_restrict_email'] = empty( $_POST['coupon_restrict_email'] ) ? 0 : 1;
        } else {
            $s['log_retention_days'] = $int( 'log_retention_days', 0, 3650, 365 );
            $s['delete_on_uninstall'] = empty( $_POST['delete_on_uninstall'] ) ? 0 : 1;
        }
        WCR_Settings::update( $s );
        if ( 'general' === $section ) {
            if ( $old['country_code'] !== $s['country_code'] ) { WCR_Customers::start_rebuild(); $notice = 'rebuild'; }
            elseif ( WCR_Settings::counted_statuses() !== array_values( (array) $old['counted_statuses'] ) ) { WCR_Customers::recompute_all(); $notice = 'recount'; }
        }
        wp_safe_redirect( add_query_arg( 'wcr_notice', $notice, $back ) );
        exit;
    }
    public static function rebuild() {
        if ( ! current_user_can( 'manage_woocommerce' ) ) wp_die( 'You are not allowed to do this.' );
        check_admin_referer( 'wcr_rebuild' );
        WCR_Customers::start_rebuild();
        wp_safe_redirect( self::url( array( 'tab' => 'settings', 'section' => 'data', 'wcr_notice' => 'rebuild' ) ) );
        exit;
    }
}
