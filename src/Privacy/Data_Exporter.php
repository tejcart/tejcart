<?php
/**
 * GDPR / CCPA Data Exporter.
 *
 * Integrates TejCart data sources into WordPress's personal-data export tool
 * (Tools → Export Personal Data).
 *
 * @package TejCart\Privacy
 * @since   1.2.0
 */

declare( strict_types=1 );

namespace TejCart\Privacy;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Register exporters for each user-linked data source.
 */
class Data_Exporter {
    /**
     * Register all exporters via wp_privacy_personal_data_exporters.
     */
    public function init(): void {
        add_filter( 'wp_privacy_personal_data_exporters', array( $this, 'register_exporters' ) );
    }

    /**
     * Register each exporter callback with WP's privacy manager.
     *
     * @param array $exporters Existing exporters.
     * @return array
     */
    public function register_exporters( $exporters ): array {
        // Audit #89 / 09 F-010 — `wp_tejcart_customers` row was
        // erased on account deletion (Data_Eraser registers
        // `tejcart-customer-profile`) but never exported. Article 15
        // requires the data subject receive a copy of every personal
        // record about them, so this exporter is paired with the
        // existing eraser.
        $exporters['tejcart-customer-profile'] = array(
            'exporter_friendly_name' => __( 'TejCart Customer Profile', 'tejcart' ),
            'callback'               => array( $this, 'export_customer_profile' ),
        );
        $exporters['tejcart-orders']           = array(
            'exporter_friendly_name' => __( 'TejCart Orders', 'tejcart' ),
            'callback'               => array( $this, 'export_orders' ),
        );
        $exporters['tejcart-addresses']        = array(
            'exporter_friendly_name' => __( 'TejCart Address Book', 'tejcart' ),
            'callback'               => array( $this, 'export_addresses' ),
        );
        $exporters['tejcart-payment-methods']  = array(
            'exporter_friendly_name' => __( 'TejCart Saved Payment Methods', 'tejcart' ),
            'callback'               => array( $this, 'export_payment_methods' ),
        );
        $exporters['tejcart-wishlist']         = array(
            'exporter_friendly_name' => __( 'TejCart Wishlist', 'tejcart' ),
            'callback'               => array( $this, 'export_wishlist' ),
        );
        $exporters['tejcart-recently-viewed']  = array(
            'exporter_friendly_name' => __( 'TejCart Recently Viewed', 'tejcart' ),
            'callback'               => array( $this, 'export_recently_viewed' ),
        );
        $exporters['tejcart-downloads']        = array(
            'exporter_friendly_name' => __( 'TejCart Downloads', 'tejcart' ),
            'callback'               => array( $this, 'export_downloads' ),
        );
        $exporters['tejcart-abandoned-carts']  = array(
            'exporter_friendly_name' => __( 'TejCart Abandoned Carts', 'tejcart' ),
            'callback'               => array( $this, 'export_abandoned_carts' ),
        );
        // Audit #90 / 09 F-011 — _tejcart_saved_cart user_meta is
        // persisted on logout (`Cart_Session::persist_cart_on_logout`)
        // and can carry guest-checkout PII captured before login.
        // GDPR Art. 15 requires this be exportable; the matching eraser
        // lives in Data_Eraser::erase_saved_cart().
        $exporters['tejcart-saved-cart']       = array(
            'exporter_friendly_name' => __( 'TejCart Saved Cart', 'tejcart' ),
            'callback'               => array( $this, 'export_saved_cart' ),
        );

        return $exporters;
    }

    /**
     * Export the `_tejcart_saved_cart` user_meta blob.
     *
     * Persisted by `Cart_Session::persist_cart_on_logout()` on logout
     * so a returning shopper can resume their cart. The blob carries
     * the full session payload (cart line items, applied coupon codes,
     * shipping destination) — none of which is sensitive on its own,
     * but it can capture guest checkout PII (postcode, country) entered
     * before logging in. Article 15 requires the subject receive a
     * copy. Returns `done => true` always — single user_meta row.
     *
     * @param string $email_address Email to search for.
     * @param int    $page          1-based page (unused — single row).
     * @return array{data: array<int, array<string,mixed>>, done: bool}
     */
    public function export_saved_cart( $email_address, $page = 1 ): array {
        unset( $page );

        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return array( 'data' => array(), 'done' => true );
        }

        $saved = get_user_meta( (int) $user->ID, '_tejcart_saved_cart', true );
        if ( ! is_array( $saved ) || empty( $saved ) ) {
            return array( 'data' => array(), 'done' => true );
        }

        $saved_data = isset( $saved['data'] ) && is_array( $saved['data'] ) ? $saved['data'] : array();
        $saved_at   = isset( $saved['saved_at'] ) ? (int) $saved['saved_at'] : 0;
        $cart_items = isset( $saved_data['cart'] ) && is_array( $saved_data['cart'] ) ? $saved_data['cart'] : array();
        $coupons    = isset( $saved_data['coupons'] ) && is_array( $saved_data['coupons'] ) ? $saved_data['coupons'] : array();
        $destination = isset( $saved_data['shipping_destination'] ) && is_array( $saved_data['shipping_destination'] )
            ? $saved_data['shipping_destination']
            : array();

        $line_summary = '';
        foreach ( $cart_items as $line ) {
            if ( ! is_array( $line ) ) {
                continue;
            }
            $pid   = isset( $line['product_id'] ) ? (int) $line['product_id'] : 0;
            $qty   = isset( $line['quantity'] ) ? (int) $line['quantity'] : 0;
            $title = $pid > 0 ? (string) get_the_title( $pid ) : '';
            if ( '' === $title ) {
                $title = '#' . $pid;
            }
            $line_summary .= sprintf( "%s x%d\n", $title, $qty );
        }

        return array(
            'data' => array(
                array(
                    'group_id'    => 'tejcart-saved-cart',
                    'group_label' => __( 'Saved Cart', 'tejcart' ),
                    'item_id'     => 'tejcart-saved-cart-' . (int) $user->ID,
                    'data'        => array(
                        array(
                            'name'  => __( 'Saved at', 'tejcart' ),
                            'value' => $saved_at > 0 ? gmdate( 'Y-m-d H:i:s', $saved_at ) . ' UTC' : '',
                        ),
                        array(
                            'name'  => __( 'Items', 'tejcart' ),
                            'value' => trim( $line_summary ),
                        ),
                        array(
                            'name'  => __( 'Applied coupons', 'tejcart' ),
                            'value' => implode( ', ', array_map( 'strval', array_keys( $coupons ) ) ),
                        ),
                        array(
                            'name'  => __( 'Shipping destination', 'tejcart' ),
                            'value' => wp_json_encode( $destination ),
                        ),
                    ),
                ),
            ),
            'done' => true,
        );
    }

    /**
     * Export the customer-profile row keyed on this email.
     *
     * Pulls the canonical `wp_tejcart_customers` record (first/last
     * name, billing/shipping address blobs) and decrypts the address
     * JSON via `Address_Crypto::decode()` so the export is a
     * cleartext copy of the subject's stored profile. Returns
     * `done => true` always — there is at most one customer row per
     * email (the unique constraint guarantees this).
     *
     * @param string $email_address Email to search for.
     * @param int    $page          1-based page (unused — single row).
     * @return array
     */
    public function export_customer_profile( $email_address, $page = 1 ): array {
        global $wpdb;

        $email = strtolower( trim( (string) $email_address ) );
        if ( '' === $email ) {
            return array( 'data' => array(), 'done' => true );
        }

        $table = $wpdb->prefix . 'tejcart_customers';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, email, first_name, last_name, billing_address, shipping_address
                   FROM {$table}
                  WHERE email = %s
                  LIMIT 1",
                $email
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( ! is_array( $row ) || empty( $row ) ) {
            return array( 'data' => array(), 'done' => true );
        }

        $data = array(
            array(
                'group_id'    => 'tejcart-customer-profile',
                'group_label' => __( 'Customer Profile', 'tejcart' ),
                'item_id'     => 'tejcart-customer-' . $row['id'],
                'data'        => array(
                    array( 'name' => __( 'Email', 'tejcart' ),            'value' => (string) $row['email'] ),
                    array( 'name' => __( 'First name', 'tejcart' ),       'value' => (string) $row['first_name'] ),
                    array( 'name' => __( 'Last name', 'tejcart' ),        'value' => (string) $row['last_name'] ),
                    array( 'name' => __( 'Billing address', 'tejcart' ),  'value' => \TejCart\Customer\Address_Crypto::decode( (string) $row['billing_address'] ) ),
                    array( 'name' => __( 'Shipping address', 'tejcart' ), 'value' => \TejCart\Customer\Address_Crypto::decode( (string) $row['shipping_address'] ) ),
                ),
            ),
        );

        return array( 'data' => $data, 'done' => true );
    }

    /**
     * Export orders belonging to the given email. Paginated by 50.
     *
     * @param string $email_address Email to search for.
     * @param int    $page          1-based page.
     * @return array
     */
    public function export_orders( $email_address, $page = 1 ): array {
        global $wpdb;

        $email  = sanitize_email( (string) $email_address );
        $page   = max( 1, (int) $page );
        $limit  = 50;
        $offset = ( $page - 1 ) * $limit;
        $table  = $wpdb->prefix . 'tejcart_orders';
        $items  = $wpdb->prefix . 'tejcart_order_items';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$table} WHERE customer_email = %s ORDER BY id ASC LIMIT %d OFFSET %d",
            $email,
            $limit,
            $offset
        ), ARRAY_A );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $data = array();
        // H-3: batch-fetch all order_items for the page in a single
        // IN(...) query rather than issuing one SELECT per order. At 50
        // orders / page that's 50× fewer round-trips per export page.
        $items_by_order = array();
        if ( is_array( $orders ) && ! empty( $orders ) ) {
            $order_ids = array_map( static function ( $o ) { return (int) $o['id']; }, $orders );
            $placeholders = implode( ',', array_fill( 0, count( $order_ids ), '%d' ) );
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $batch = $wpdb->get_results( $wpdb->prepare(
                "SELECT order_id, product_name, quantity, unit_price, line_total FROM {$items} WHERE order_id IN ({$placeholders})",
                ...$order_ids
            ), ARRAY_A );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
            if ( is_array( $batch ) ) {
                foreach ( $batch as $line ) {
                    $items_by_order[ (int) $line['order_id'] ][] = $line;
                }
            }
        }

        if ( is_array( $orders ) ) {
            foreach ( $orders as $order ) {
                $line_items   = $items_by_order[ (int) $order['id'] ] ?? array();
                $lines_summary = '';
                if ( is_array( $line_items ) ) {
                    foreach ( $line_items as $line ) {
                        $lines_summary .= sprintf( "%s x%d (%s)\n", $line['product_name'], (int) $line['quantity'], $line['line_total'] );
                    }
                }

                $data[] = array(
                    'group_id'    => 'tejcart-orders',
                    'group_label' => __( 'Orders', 'tejcart' ),
                    'item_id'     => 'tejcart-order-' . $order['id'],
                    'data'        => array(
                        array( 'name' => __( 'Order number', 'tejcart' ), 'value' => $order['order_number'] ),
                        array( 'name' => __( 'Created', 'tejcart' ),      'value' => $order['created_at'] ),
                        array( 'name' => __( 'Status', 'tejcart' ),       'value' => $order['status'] ),
                        array( 'name' => __( 'Total', 'tejcart' ),        'value' => $order['total'] . ' ' . $order['currency'] ),
                        array( 'name' => __( 'Payment method', 'tejcart' ), 'value' => (string) $order['payment_method'] ),
                        array( 'name' => __( 'Line items', 'tejcart' ),   'value' => trim( $lines_summary ) ),
                        array( 'name' => __( 'Billing address', 'tejcart' ),  'value' => \TejCart\Customer\Address_Crypto::decode( (string) $order['billing_address'] ) ),
                        array( 'name' => __( 'Shipping address', 'tejcart' ), 'value' => \TejCart\Customer\Address_Crypto::decode( (string) $order['shipping_address'] ) ),
                    ),
                );
            }
        }

        $done = count( (array) $orders ) < $limit;

        return array(
            'data' => $data,
            'done' => $done,
        );
    }

    /**
     * Export saved address-book entries.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function export_addresses( $email_address, $page = 1 ): array {
        global $wpdb;

        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return array( 'data' => array(), 'done' => true );
        }

        $table = $wpdb->prefix . 'tejcart_addresses';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, label, type, is_default, address FROM {$table} WHERE user_id = %d",
            (int) $user->ID
        ), ARRAY_A );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $data = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $data[] = array(
                    'group_id'    => 'tejcart-addresses',
                    'group_label' => __( 'Address Book', 'tejcart' ),
                    'item_id'     => 'tejcart-address-' . $row['id'],
                    'data'        => array(
                        array( 'name' => __( 'Label', 'tejcart' ),   'value' => (string) $row['label'] ),
                        array( 'name' => __( 'Type', 'tejcart' ),    'value' => (string) $row['type'] ),
                        array( 'name' => __( 'Default', 'tejcart' ), 'value' => $row['is_default'] ? 'yes' : 'no' ),
                        array( 'name' => __( 'Address', 'tejcart' ), 'value' => \TejCart\Customer\Address_Crypto::decode( (string) $row['address'] ) ),
                    ),
                );
            }
        }

        return array( 'data' => $data, 'done' => true );
    }

    /**
     * Export saved payment methods.
     *
     * Only masked metadata is exported — raw vault tokens and encrypted blobs
     * stay internal.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function export_payment_methods( $email_address, $page = 1 ): array {
        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return array( 'data' => array(), 'done' => true );
        }

        $methods = get_user_meta( (int) $user->ID, 'tejcart_saved_payment_methods', true );
        $methods = is_array( $methods ) ? $methods : array();

        $data = array();
        foreach ( $methods as $idx => $method ) {
            if ( ! is_array( $method ) ) {
                continue;
            }
            $data[] = array(
                'group_id'    => 'tejcart-payment-methods',
                'group_label' => __( 'Saved Payment Methods', 'tejcart' ),
                'item_id'     => 'tejcart-pm-' . (string) ( $method['id'] ?? $idx ),
                'data'        => array(
                    array( 'name' => __( 'Brand', 'tejcart' ),      'value' => (string) ( $method['brand'] ?? '' ) ),
                    array( 'name' => __( 'Last 4', 'tejcart' ),     'value' => (string) ( $method['last4'] ?? '' ) ),
                    array( 'name' => __( 'Expiry', 'tejcart' ),     'value' => (string) ( $method['expiry'] ?? '' ) ),
                    array( 'name' => __( 'Label', 'tejcart' ),      'value' => (string) ( $method['label'] ?? '' ) ),
                ),
            );
        }

        return array( 'data' => $data, 'done' => true );
    }

    /**
     * Export wishlist product IDs.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function export_wishlist( $email_address, $page = 1 ): array {
        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return array( 'data' => array(), 'done' => true );
        }

        $items = get_user_meta( (int) $user->ID, '_tejcart_wishlist', true );
        $items = is_array( $items ) ? array_map( 'intval', $items ) : array();

        if ( empty( $items ) ) {
            return array( 'data' => array(), 'done' => true );
        }

        $titles = array();
        foreach ( $items as $pid ) {
            $titles[] = get_the_title( $pid ) ?: (string) $pid;
        }

        $data = array(
            array(
                'group_id'    => 'tejcart-wishlist',
                'group_label' => __( 'Wishlist', 'tejcart' ),
                'item_id'     => 'tejcart-wishlist-' . (int) $user->ID,
                'data'        => array(
                    array( 'name' => __( 'Products', 'tejcart' ), 'value' => implode( ', ', $titles ) ),
                ),
            ),
        );

        return array( 'data' => $data, 'done' => true );
    }

    /**
     * Recently-viewed is cookie-only; we can still surface it when the
     * current request has one for the exported subject.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function export_recently_viewed( $email_address, $page = 1 ): array {
        if ( empty( $_COOKIE['tejcart_recently_viewed'] ) ) {
            return array( 'data' => array(), 'done' => true );
        }

        $raw   = sanitize_text_field( wp_unslash( $_COOKIE['tejcart_recently_viewed'] ) );
        $items = json_decode( $raw, true );
        if ( ! is_array( $items ) ) {
            return array( 'data' => array(), 'done' => true );
        }

        $titles = array();
        foreach ( $items as $pid ) {
            $titles[] = get_the_title( (int) $pid ) ?: (string) $pid;
        }

        return array(
            'data' => array(
                array(
                    'group_id'    => 'tejcart-recently-viewed',
                    'group_label' => __( 'Recently Viewed', 'tejcart' ),
                    'item_id'     => 'tejcart-rv-0',
                    'data'        => array(
                        array( 'name' => __( 'Products', 'tejcart' ), 'value' => implode( ', ', $titles ) ),
                    ),
                ),
            ),
            'done' => true,
        );
    }

    /**
     * Export download grants + log entries.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function export_downloads( $email_address, $page = 1 ): array {
        global $wpdb;

        $user = get_user_by( 'email', (string) $email_address );
        if ( ! $user ) {
            return array( 'data' => array(), 'done' => true );
        }

        $data = array();

        $perms_table = $wpdb->prefix . 'tejcart_download_permissions';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $perms_table ) );
        if ( $exists === $perms_table ) {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $rows = $wpdb->get_results( $wpdb->prepare(
                "SELECT id, order_id, product_id, download_count, access_expires FROM {$perms_table} WHERE user_id = %d",
                (int) $user->ID
            ), ARRAY_A );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            foreach ( (array) $rows as $row ) {
                $data[] = array(
                    'group_id'    => 'tejcart-downloads',
                    'group_label' => __( 'Downloads', 'tejcart' ),
                    'item_id'     => 'tejcart-dl-' . $row['id'],
                    'data'        => array(
                        array( 'name' => __( 'Order', 'tejcart' ),       'value' => (string) $row['order_id'] ),
                        array( 'name' => __( 'Product', 'tejcart' ),     'value' => (string) $row['product_id'] ),
                        array( 'name' => __( 'Downloads used', 'tejcart' ), 'value' => (string) $row['download_count'] ),
                        array( 'name' => __( 'Access expires', 'tejcart' ), 'value' => (string) $row['access_expires'] ),
                    ),
                );
            }
        }

        return array( 'data' => $data, 'done' => true );
    }

    /**
     * Export abandoned-cart snapshots.
     *
     * @param string $email_address Email.
     * @param int    $page          Page.
     * @return array
     */
    public function export_abandoned_carts( $email_address, $page = 1 ): array {
        global $wpdb;

        $email = sanitize_email( (string) $email_address );
        $table = $wpdb->prefix . 'tejcart_abandoned_carts';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
        if ( $exists !== $table ) {
            return array( 'data' => array(), 'done' => true );
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, cart_total, currency, status, created_at, updated_at FROM {$table} WHERE email = %s",
            $email
        ), ARRAY_A );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $data = array();
        foreach ( (array) $rows as $row ) {
            $data[] = array(
                'group_id'    => 'tejcart-abandoned-carts',
                'group_label' => __( 'Abandoned Carts', 'tejcart' ),
                'item_id'     => 'tejcart-ac-' . $row['id'],
                'data'        => array(
                    array(
                        'name'  => __( 'Cart total', 'tejcart' ),
                        // cart_total is stored as integer minor units (BIGINT); convert to major units for human-readable export.
                        'value' => number_format(
                            (int) $row['cart_total'] / max( 1, \TejCart\Money\Currency::multiplier( (string) $row['currency'] ) ),
                            \TejCart\Money\Currency::decimals( (string) $row['currency'] )
                        ) . ' ' . $row['currency'],
                    ),
                    array( 'name' => __( 'Status', 'tejcart' ),    'value' => (string) $row['status'] ),
                    array( 'name' => __( 'Created', 'tejcart' ),   'value' => (string) $row['created_at'] ),
                    array( 'name' => __( 'Updated', 'tejcart' ),   'value' => (string) $row['updated_at'] ),
                ),
            );
        }

        return array( 'data' => $data, 'done' => true );
    }
}
