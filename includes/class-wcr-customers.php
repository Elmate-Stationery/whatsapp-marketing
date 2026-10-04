<?php
if ( ! defined( 'ABSPATH' ) ) exit;

// Customer statistics and reminder cycles.
//
// Identity is phone-first: the normalized WhatsApp number of the billing phone, else the billing email, else the
// user ID. Each order is mirrored into the orders table (via WC_Order, so HPOS and legacy storage both work) and the
// customer's statistics are recomputed from that table with indexed SQL.
//
// Cycle: a customer's cycle starts at their last counted order. When a newer counted order arrives, the cycle closes:
// the contact that led to it is marked Converted (order within the attribution window after the contact, or the order
// used that contact's voucher), the other contacts are Closed, and the contact fields reset for the new cycle.
class WCR_Customers {
    const BATCH        = 200;
    const REBUILD_HOOK = 'wcr_rebuild_batch';
    const PENDING_GRACE_DAYS = 7; // a Pending payment order older than this no longer counts as open
    const STATES = array(
        'eligible'     => 'Eligible',
        'followup'     => 'Follow-up due',
        'contacted'    => 'Contacted',
        'not_eligible' => 'Not eligible',
        'open_order'   => 'Open order',
        'dnc'          => 'Do not contact',
    );

    private static $queue = array();
    private static $queue_hooked = false;

    public static function init() {
        add_action( 'woocommerce_after_order_object_save', array( __CLASS__, 'on_order_saved' ) );
        add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'queue' ) );
        add_action( 'woocommerce_order_refunded', array( __CLASS__, 'queue' ) );
        add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'on_refund_deleted' ), 10, 2 );
        foreach ( array( 'woocommerce_trash_order', 'woocommerce_untrash_order', 'woocommerce_delete_order' ) as $hook ) add_action( $hook, array( __CLASS__, 'queue' ) );
        // Legacy (post) order storage.
        foreach ( array( 'wp_trash_post', 'untrashed_post', 'before_delete_post' ) as $hook ) add_action( $hook, array( __CLASS__, 'queue_post' ) );
        add_action( self::REBUILD_HOOK, array( __CLASS__, 'rebuild_batch' ) );
        add_action( 'init', array( __CLASS__, 'maybe_start_rebuild' ), 20 );
    }

    // ---------------------------------------------------------------- Order sync

    public static function on_order_saved( $order ) {
        if ( $order instanceof WC_Order ) self::queue( $order->get_id() );
    }
    public static function on_refund_deleted( $refund_id, $order_id ) {
        self::queue( $order_id );
    }
    public static function queue_post( $post_id ) {
        if ( 'shop_order' === get_post_type( $post_id ) ) self::queue( $post_id );
    }
    // Orders are saved several times per request (status, totals, meta); each is synced once, at the end of it.
    public static function queue( $order_id ) {
        $order_id = absint( $order_id );
        if ( ! $order_id ) return;
        self::$queue[ $order_id ] = true;
        if ( ! self::$queue_hooked ) { self::$queue_hooked = true; add_action( 'shutdown', array( __CLASS__, 'process_queue' ), 5 ); }
    }
    public static function process_queue() {
        $ids = array_keys( self::$queue );
        self::$queue = array();
        foreach ( $ids as $id ) {
            try { self::sync_order_id( $id ); }
            catch ( Throwable $e ) { error_log( sprintf( 'WhatsApp Retention: could not sync order %d: %s', $id, $e->getMessage() ) ); }
        }
    }
    public static function sync_order_id( $order_id ) {
        $order = wc_get_order( $order_id );
        if ( $order instanceof WC_Order && 'shop_order' === $order->get_type() ) { self::sync_order( $order ); return; }
        global $wpdb;
        $existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . WCR_DB::orders_table() . ' WHERE order_id=%d', $order_id ) );
        if ( $existing ) self::remove_order( $existing, true );
        WCR_Coupons::order_gone( $order_id );
    }

    // Customer key for an order: phone first, then email, then user ID ('' = cannot be identified).
    public static function key_for_order( $order ) {
        $wa = WCR_WhatsApp::number( $order->get_billing_phone(), $order->get_billing_country() );
        if ( '' !== $wa ) return 'p:' . $wa;
        $email = strtolower( trim( (string) $order->get_billing_email() ) );
        if ( is_email( $email ) ) return 'e:' . $email;
        return $order->get_customer_id() ? 'u:' . (int) $order->get_customer_id() : '';
    }
    private static function order_name( $order ) {
        $name = trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() );
        if ( '' === $name && $order->get_customer_id() ) { $user = get_userdata( $order->get_customer_id() ); if ( $user ) $name = $user->display_name; }
        return mb_substr( $name, 0, 255 );
    }

    // Mirrors one order and (when $live) refreshes its customer's statistics. Rebuild batches pass $live=false and
    // recompute everything once at the end.
    public static function sync_order( $order, $live = true ) {
        global $wpdb;
        $ot = WCR_DB::orders_table();
        $id = $order->get_id();
        $status = $order->get_status();
        $existing = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ot WHERE order_id=%d", $id ) );
        $key = in_array( $status, array( 'trash', 'checkout-draft', 'auto-draft', 'draft' ), true ) ? '' : self::key_for_order( $order );
        if ( '' === $key ) {
            if ( $existing ) self::remove_order( $existing, $live );
            WCR_Coupons::order_gone( $id );
            return;
        }
        $created = $order->get_date_created();
        $created_at = $created ? gmdate( 'Y-m-d H:i:s', $created->getTimestamp() ) : current_time( 'mysql', true );
        $net = max( 0, (float) $order->get_total() - (float) $order->get_total_refunded() );
        $customer_id = self::customer_for( $key, $order, $created_at );
        if ( ! $customer_id ) return;
        $wpdb->replace( $ot, array(
            'order_id' => $id, 'customer_id' => $customer_id, 'status' => $status, 'net_total' => wc_format_decimal( $net ),
            'created_at' => $created_at, 'synced_at' => current_time( 'mysql', true ),
        ) );
        WCR_Coupons::sync_order( $order );
        if ( ! $live ) return;
        self::recompute( $customer_id );
        if ( $existing && (int) $existing->customer_id !== $customer_id ) {
            self::recompute( (int) $existing->customer_id );
            self::maybe_delete_customer( (int) $existing->customer_id );
        }
    }
    private static function remove_order( $row, $live ) {
        global $wpdb;
        $wpdb->delete( WCR_DB::orders_table(), array( 'order_id' => (int) $row->order_id ) );
        if ( ! $live ) return;
        self::recompute( (int) $row->customer_id );
        self::maybe_delete_customer( (int) $row->customer_id );
    }
    // Finds or creates the customer row; its name / phone / email come from their most recent order.
    private static function customer_for( $key, $order, $created_at ) {
        global $wpdb;
        $ct = WCR_DB::customers_table();
        $now = current_time( 'mysql', true );
        $details = array(
            'name'      => self::order_name( $order ),
            'phone'     => mb_substr( (string) $order->get_billing_phone(), 0, 100 ),
            'wa_number' => WCR_WhatsApp::number( $order->get_billing_phone(), $order->get_billing_country() ),
            'email'     => mb_substr( strtolower( trim( (string) $order->get_billing_email() ) ), 0, 320 ),
        );
        $user_id = (int) $order->get_customer_id();
        $row = $wpdb->get_row( $wpdb->prepare( "SELECT id, details_at, user_id FROM $ct WHERE customer_key=%s", $key ) );
        if ( ! $row ) {
            // INSERT IGNORE: two requests creating the same customer at once end up with one row.
            // (user_id is written literally: prepare() would turn NULL into '' and guests into user 0.)
            $wpdb->query( $wpdb->prepare(
                "INSERT IGNORE INTO $ct (customer_key, user_id, name, phone, wa_number, email, details_at, created_at, updated_at) VALUES (%s, " . ( $user_id ? $user_id : 'NULL' ) . ', %s, %s, %s, %s, %s, %s, %s)',
                $key, $details['name'], $details['phone'], $details['wa_number'], $details['email'], $created_at, $now, $now
            ) );
            return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $ct WHERE customer_key=%s", $key ) );
        }
        $update = array();
        if ( null === $row->details_at || $created_at >= $row->details_at ) $update = $details + array( 'details_at' => $created_at );
        if ( $user_id && ( ! $row->user_id || isset( $update['details_at'] ) ) ) $update['user_id'] = $user_id; // once registered, stays registered
        if ( $update ) $wpdb->update( $ct, $update + array( 'updated_at' => $now ), array( 'id' => (int) $row->id ) );
        return (int) $row->id;
    }
    private static function maybe_delete_customer( $customer_id ) {
        global $wpdb;
        if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . WCR_DB::orders_table() . ' WHERE customer_id=%d LIMIT 1', $customer_id ) ) ) return;
        if ( $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . WCR_DB::contacts_table() . ' WHERE customer_id=%d LIMIT 1', $customer_id ) ) ) return;
        $wpdb->delete( WCR_DB::customers_table(), array( 'id' => $customer_id ) );
    }

    // ---------------------------------------------------------------- Statistics

    private static function in_list( $values ) {
        global $wpdb;
        return implode( ',', array_map( function ( $v ) use ( $wpdb ) { return $wpdb->prepare( '%s', $v ); }, $values ) );
    }
    // Latest open order placed after the last counted order; non-pending ones first (a pending order may be stale).
    private static function open_order( $customer_id, $after ) {
        global $wpdb;
        $open = WCR_Settings::open_statuses();
        if ( ! $open ) return null;
        $sql = 'SELECT order_id, status, created_at FROM ' . WCR_DB::orders_table() . ' WHERE customer_id=%d AND status IN (' . self::in_list( $open ) . ')';
        $args = array( $customer_id );
        if ( $after ) { $sql .= ' AND created_at > %s'; $args[] = $after; }
        return $wpdb->get_row( $wpdb->prepare( $sql . " ORDER BY (status<>'pending') DESC, created_at DESC LIMIT 1", $args ) );
    }
    public static function recompute( $customer_id ) {
        global $wpdb;
        $ct = WCR_DB::customers_table(); $ot = WCR_DB::orders_table();
        $c = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $ct WHERE id=%d", $customer_id ) );
        if ( ! $c ) return;
        $in = self::in_list( WCR_Settings::counted_statuses() );
        $agg = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(net_total),0) AS total, MIN(created_at) AS first_at FROM $ot WHERE customer_id=%d AND status IN ($in)", $customer_id ) );
        $last = $wpdb->get_row( $wpdb->prepare( "SELECT order_id, created_at, net_total FROM $ot WHERE customer_id=%d AND status IN ($in) ORDER BY created_at DESC, order_id DESC LIMIT 1", $customer_id ) );
        $open = self::open_order( $customer_id, $last ? $last->created_at : null );
        $data = array(
            'order_count' => (int) $agg->n, 'total_value' => $agg->total, 'first_order_at' => $agg->first_at,
            'last_order_id' => $last ? (int) $last->order_id : null, 'last_order_at' => $last ? $last->created_at : null,
            'open_order_id' => $open ? (int) $open->order_id : null, 'open_order_status' => $open ? $open->status : null, 'open_order_at' => $open ? $open->created_at : null,
            'updated_at' => current_time( 'mysql', true ),
        );
        // A newer counted order: close the reminder cycle and start a new one from this order.
        if ( $last && ( null === $c->last_order_at || $last->created_at > $c->last_order_at ) ) {
            $winner = self::close_cycle( $c, $last );
            $data += array( 'last_contact_at' => null, 'last_contact_by' => null, 'last_contact_type' => null, 'cycle_contacts' => 0, 'cycle_voucher' => 0 );
            if ( $winner ) $data += array( 'conversions' => (int) $c->conversions + 1, 'last_converted_order_id' => (int) $last->order_id, 'last_converted_at' => current_time( 'mysql', true ) );
        }
        $wpdb->update( $ct, $data, array( 'id' => $customer_id ) );
    }
    // Closes the open contacts of a cycle when the order $last arrives. Returns the converted contact, if any.
    private static function close_cycle( $c, $last ) {
        global $wpdb;
        $kt = WCR_DB::contacts_table();
        $contacts = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $kt WHERE customer_id=%d AND status='contacted' ORDER BY created_at DESC, id DESC", $c->id ) );
        if ( ! $contacts ) return null;
        $order = wc_get_order( (int) $last->order_id );
        $codes = $order ? array_map( 'wc_strtolower', $order->get_coupon_codes() ) : array();
        $s = WCR_Settings::get();
        $window = max( 1, (int) $s['attribution_days'] ) * DAY_IN_SECONDS;
        $order_ts = strtotime( $last->created_at . ' UTC' );
        $winner = null; $by_coupon = false;
        // 1) The order used the voucher sent in a contact: that contact converted, whatever the window.
        foreach ( $contacts as $k ) {
            if ( ! $k->coupon_id || strtotime( $k->created_at . ' UTC' ) > $order_ts ) continue;
            $coupon = WCR_Coupons::get( $k->coupon_id );
            if ( $coupon && in_array( wc_strtolower( $coupon->code ), $codes, true ) ) { $winner = $k; $by_coupon = true; break; }
        }
        // 2) Otherwise the latest contact before the order, if the order came within the attribution window.
        if ( ! $winner ) {
            foreach ( $contacts as $k ) {
                $ts = strtotime( $k->created_at . ' UTC' );
                if ( $ts > $order_ts ) continue; // contacted after this order was placed: not what caused it
                if ( $order_ts - $ts <= $window ) $winner = $k;
                break;
            }
        }
        $now = current_time( 'mysql', true );
        $converted = null;
        foreach ( $contacts as $k ) {
            if ( $winner && (int) $k->id === (int) $winner->id ) {
                $n = $wpdb->query( $wpdb->prepare( "UPDATE $kt SET status='converted', closed_at=%s, converted_at=%s, order_id=%d, order_value=%s, coupon_used=%d WHERE id=%d AND status='contacted'", $now, $now, $last->order_id, $last->net_total, $by_coupon ? 1 : 0, $k->id ) );
                if ( 1 === $n ) $converted = $k;
            } else {
                $wpdb->query( $wpdb->prepare( "UPDATE $kt SET status='closed', closed_at=%s, order_id=%d WHERE id=%d AND status='contacted'", $now, $last->order_id, $k->id ) );
            }
        }
        return $converted;
    }
    // Contact fields of the current cycle, from its open contacts (after an Undo).
    public static function refresh_contact_fields( $customer_id ) {
        global $wpdb;
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT created_at, created_by, type FROM ' . WCR_DB::contacts_table() . " WHERE customer_id=%d AND status='contacted' ORDER BY created_at DESC, id DESC", $customer_id ) );
        $voucher = 0;
        foreach ( $rows as $r ) if ( 'voucher' === $r->type ) $voucher = 1;
        $wpdb->update( WCR_DB::customers_table(), array(
            'last_contact_at' => $rows ? $rows[0]->created_at : null, 'last_contact_by' => $rows ? $rows[0]->created_by : null, 'last_contact_type' => $rows ? $rows[0]->type : null,
            'cycle_contacts' => count( $rows ), 'cycle_voucher' => $voucher,
        ), array( 'id' => $customer_id ) );
    }

    // All customers at once, in SQL (after a rebuild or a change of counted statuses). Does not record conversions.
    public static function recompute_all() {
        global $wpdb;
        $ct = WCR_DB::customers_table(); $ot = WCR_DB::orders_table(); $kt = WCR_DB::contacts_table();
        $in = self::in_list( WCR_Settings::counted_statuses() );
        $now = current_time( 'mysql', true );
        $wpdb->query( "UPDATE $ct c LEFT JOIN (SELECT customer_id, COUNT(*) AS n, SUM(net_total) AS t, MIN(created_at) AS f, MAX(created_at) AS l FROM $ot WHERE status IN ($in) GROUP BY customer_id) a ON a.customer_id=c.id
            SET c.order_count=COALESCE(a.n,0), c.total_value=COALESCE(a.t,0), c.first_order_at=a.f, c.last_order_at=a.l, c.last_order_id=NULL, c.open_order_id=NULL, c.open_order_status=NULL, c.open_order_at=NULL" );
        $wpdb->query( "UPDATE $ct c JOIN (SELECT o.customer_id, MAX(o.order_id) AS id FROM $ot o JOIN (SELECT customer_id, MAX(created_at) AS l FROM $ot WHERE status IN ($in) GROUP BY customer_id) m ON m.customer_id=o.customer_id AND m.l=o.created_at WHERE o.status IN ($in) GROUP BY o.customer_id) x ON x.customer_id=c.id SET c.last_order_id=x.id" );
        $open = WCR_Settings::open_statuses();
        if ( $open ) {
            foreach ( $wpdb->get_col( "SELECT DISTINCT customer_id FROM $ot WHERE status IN (" . self::in_list( $open ) . ')' ) as $cid ) {
                $last_at = $wpdb->get_var( $wpdb->prepare( "SELECT last_order_at FROM $ct WHERE id=%d", $cid ) );
                $o = self::open_order( (int) $cid, $last_at );
                if ( $o ) $wpdb->update( $ct, array( 'open_order_id' => (int) $o->order_id, 'open_order_status' => $o->status, 'open_order_at' => $o->created_at ), array( 'id' => (int) $cid ) );
            }
        }
        // Contacts made before the (possibly new) last order belong to a cycle that has ended.
        $wpdb->query( $wpdb->prepare( "UPDATE $kt k JOIN $ct c ON c.id=k.customer_id SET k.status='closed', k.closed_at=%s WHERE k.status='contacted' AND c.last_order_at IS NOT NULL AND k.created_at < c.last_order_at", $now ) );
        $wpdb->query( "UPDATE $ct SET last_contact_at=NULL, last_contact_by=NULL, last_contact_type=NULL, cycle_contacts=0, cycle_voucher=0 WHERE last_contact_at IS NOT NULL AND last_order_at IS NOT NULL AND last_contact_at < last_order_at" );
        $wpdb->query( "DELETE c FROM $ct c LEFT JOIN $ot o ON o.customer_id=c.id LEFT JOIN $kt k ON k.customer_id=c.id WHERE o.order_id IS NULL AND k.id IS NULL" );
    }

    // ---------------------------------------------------------------- Rebuild (background)

    public static function maybe_start_rebuild() {
        if ( get_option( 'wcr_needs_rebuild' ) ) self::start_rebuild();
    }
    public static function rebuild_state() {
        $state = get_option( 'wcr_rebuild' );
        return is_array( $state ) ? $state : null;
    }
    // Re-reads every order in batches of 200. Orders deleted meanwhile are dropped at the end (not re-synced).
    public static function start_rebuild() {
        if ( ! function_exists( 'as_enqueue_async_action' ) ) return;
        delete_option( 'wcr_needs_rebuild' );
        $result = wc_get_orders( array( 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'limit' => 1, 'paginate' => true, 'return' => 'ids' ) );
        update_option( 'wcr_rebuild', array( 'status' => 'running', 'started' => current_time( 'mysql', true ), 'page' => 1, 'processed' => 0, 'total' => (int) $result->total, 'finished' => null ), false );
        as_unschedule_all_actions( self::REBUILD_HOOK, array(), 'wcr' );
        as_enqueue_async_action( self::REBUILD_HOOK, array(), 'wcr' );
    }
    public static function rebuild_batch() {
        global $wpdb;
        $state = self::rebuild_state();
        if ( ! $state || 'running' !== $state['status'] ) return;
        $orders = wc_get_orders( array( 'type' => 'shop_order', 'status' => array_keys( wc_get_order_statuses() ), 'limit' => self::BATCH, 'paged' => (int) $state['page'], 'orderby' => 'ID', 'order' => 'ASC' ) );
        foreach ( $orders as $order ) {
            try { self::sync_order( $order, false ); }
            catch ( Throwable $e ) { error_log( sprintf( 'WhatsApp Retention: could not read order %d: %s', $order->get_id(), $e->getMessage() ) ); }
        }
        $state['processed'] += count( $orders );
        $state['page']++;
        if ( count( $orders ) < self::BATCH ) {
            $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . WCR_DB::orders_table() . ' WHERE synced_at < %s', $state['started'] ) );
            self::recompute_all();
            $state['status'] = 'done';
            $state['finished'] = current_time( 'mysql', true );
        } else {
            as_enqueue_async_action( self::REBUILD_HOOK, array(), 'wcr' );
        }
        update_option( 'wcr_rebuild', $state, false );
    }

    // ---------------------------------------------------------------- Queries

    // Date boundaries in the site's time zone: "N days since" counts calendar days, so a customer becomes eligible
    // at midnight (store time) on day N.
    public static function day_boundary( $days_ago ) {
        $today = new DateTimeImmutable( 'today', wp_timezone() );
        return $today->modify( '-' . max( 0, (int) $days_ago - 1 ) . ' days' )->setTimezone( new DateTimeZone( 'UTC' ) )->format( 'Y-m-d H:i:s' );
    }
    // SQL conditions (on alias c) for each state; every customer matches exactly one.
    public static function state_conditions() {
        global $wpdb;
        $s = WCR_Settings::get();
        $open = $wpdb->prepare( "(c.open_order_id IS NOT NULL AND (c.open_order_status<>'pending' OR c.open_order_at >= %s))", gmdate( 'Y-m-d H:i:s', time() - self::PENDING_GRACE_DAYS * DAY_IN_SECONDS ) );
        $follow = ! empty( $s['followup_enabled'] ) ? $wpdb->prepare( 'c.last_contact_at < %s', self::day_boundary( $s['followup_days'] ) ) : '1=0';
        $cut = self::day_boundary( $s['reminder_days'] );
        return array(
            'dnc'          => 'c.dnc=1',
            'open_order'   => "c.dnc=0 AND $open",
            'followup'     => "c.dnc=0 AND NOT $open AND c.last_contact_at IS NOT NULL AND $follow",
            'contacted'    => "c.dnc=0 AND NOT $open AND c.last_contact_at IS NOT NULL AND NOT ($follow)",
            'eligible'     => "c.dnc=0 AND NOT $open AND c.last_contact_at IS NULL AND " . $wpdb->prepare( 'c.last_order_at < %s', $cut ),
            'not_eligible' => "c.dnc=0 AND NOT $open AND c.last_contact_at IS NULL AND " . $wpdb->prepare( 'c.last_order_at >= %s', $cut ),
        );
    }
    public static function state_case() {
        $sql = 'CASE';
        foreach ( self::state_conditions() as $state => $cond ) $sql .= " WHEN $cond THEN '$state'";
        return $sql . " ELSE 'not_eligible' END";
    }
    public static function get( $customer_id ) {
        global $wpdb;
        return $wpdb->get_row( $wpdb->prepare( 'SELECT c.*, ' . self::state_case() . ' AS state FROM ' . WCR_DB::customers_table() . ' c WHERE c.id=%d', $customer_id ) );
    }
    public static function summary() {
        global $wpdb;
        $cond = self::state_conditions();
        return $wpdb->get_row( 'SELECT COUNT(*) AS total, COALESCE(SUM(' . $cond['eligible'] . '),0) AS eligible, COALESCE(SUM(' . $cond['followup'] . '),0) AS followup,
            COALESCE(SUM(c.last_contact_at IS NOT NULL),0) AS contacted, COALESCE(SUM(c.last_contact_at IS NOT NULL AND c.cycle_voucher=1),0) AS contacted_voucher,
            COALESCE(SUM(c.last_contact_at IS NOT NULL AND c.cycle_voucher=0),0) AS contacted_plain, COALESCE(SUM(c.conversions>0),0) AS converted
            FROM ' . WCR_DB::customers_table() . ' c WHERE c.order_count>0' );
    }
    const VIEWS = array(
        'all' => 'All customers', 'eligible' => 'Eligible for reminder', 'followup' => 'Follow-up due', 'not_eligible' => 'Not yet eligible', 'open_order' => 'Has an open order',
        'contacted' => 'Already contacted', 'contacted_voucher' => 'Contacted with voucher', 'contacted_plain' => 'Contacted without voucher',
        'converted' => 'Ordered after reminder', 'dnc' => 'Do not contact', 'no_phone' => 'No valid WhatsApp number',
    );
    const ORDERBY = array( 'days' => 'c.last_order_at', 'orders' => 'c.order_count', 'spent' => 'c.total_value', 'aov' => '(c.total_value/c.order_count)', 'name' => 'c.name' );
    // $f: view, ctype, min_orders, min_spent, from, to (Y-m-d, store time), s, orderby, order, page, per.
    public static function query( $f ) {
        global $wpdb;
        $ct = WCR_DB::customers_table();
        $cond = self::state_conditions();
        $where = array( 'c.order_count>0' );
        switch ( $f['view'] ) {
            case 'eligible': case 'followup': case 'not_eligible': case 'open_order': case 'dnc': $where[] = $cond[ $f['view'] ]; break;
            case 'contacted': $where[] = 'c.last_contact_at IS NOT NULL'; break;
            case 'contacted_voucher': $where[] = 'c.last_contact_at IS NOT NULL AND c.cycle_voucher=1'; break;
            case 'contacted_plain': $where[] = 'c.last_contact_at IS NOT NULL AND c.cycle_voucher=0'; break;
            case 'converted': $where[] = 'c.conversions>0'; break;
            case 'no_phone': $where[] = "c.wa_number=''"; break;
        }
        if ( 'registered' === $f['ctype'] ) $where[] = 'c.user_id IS NOT NULL';
        if ( 'guest' === $f['ctype'] ) $where[] = 'c.user_id IS NULL';
        if ( $f['min_orders'] ) $where[] = $wpdb->prepare( 'c.order_count >= %d', $f['min_orders'] );
        if ( $f['min_spent'] ) $where[] = $wpdb->prepare( 'c.total_value >= %f', $f['min_spent'] );
        $tz = wp_timezone(); $utc = new DateTimeZone( 'UTC' );
        if ( $f['from'] ) $where[] = $wpdb->prepare( 'c.last_order_at >= %s', ( new DateTimeImmutable( $f['from'], $tz ) )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
        if ( $f['to'] ) $where[] = $wpdb->prepare( 'c.last_order_at < %s', ( new DateTimeImmutable( $f['to'], $tz ) )->modify( '+1 day' )->setTimezone( $utc )->format( 'Y-m-d H:i:s' ) );
        if ( '' !== $f['s'] ) {
            $q = '%' . $wpdb->esc_like( $f['s'] ) . '%';
            $digits = preg_replace( '/\D/', '', $f['s'] );
            $where[] = $wpdb->prepare( '(c.name LIKE %s OR c.email LIKE %s OR c.phone LIKE %s', $q, $q, $q ) . ( strlen( $digits ) >= 4 ? $wpdb->prepare( ' OR c.wa_number LIKE %s', '%' . $digits . '%' ) : '' ) . ')';
        }
        $where = 'WHERE ' . implode( ' AND ', $where );
        $total = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $ct c $where" );
        $col = self::ORDERBY[ $f['orderby'] ] ?? self::ORDERBY['days'];
        $dir = 'asc' === $f['order'] ? 'ASC' : 'DESC';
        if ( 'days' === $f['orderby'] || ! isset( self::ORDERBY[ $f['orderby'] ] ) ) $dir = 'ASC' === $dir ? 'DESC' : 'ASC'; // most days = oldest last order
        $rows = $wpdb->get_results( $wpdb->prepare( 'SELECT c.*, ' . self::state_case() . " AS state FROM $ct c $where ORDER BY $col $dir, c.id DESC LIMIT %d, %d", ( $f['page'] - 1 ) * $f['per'], $f['per'] ) );
        return array( $rows, $total );
    }
    // Calendar days between a UTC datetime and today, in the store's time zone.
    public static function days_since( $utc ) {
        if ( ! $utc ) return null;
        $tz = wp_timezone();
        $day = ( new DateTimeImmutable( $utc, new DateTimeZone( 'UTC' ) ) )->setTimezone( $tz )->setTime( 0, 0 );
        return (int) $day->diff( new DateTimeImmutable( 'today', $tz ) )->format( '%r%a' );
    }
}
