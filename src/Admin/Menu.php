<?php
/**
 * Admin menu registration.
 *
 * @package TejCart\Admin
 */

declare( strict_types=1 );

namespace TejCart\Admin;

use TejCart\Coupon\Coupon;
use TejCart\Product\Product_Factory;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers the TejCart top-level menu and all submenus
 * in the WordPress admin sidebar.
 */
class Menu {
    /**
     * Hook into WordPress.
     *
     * @return void
     */
    public function init() {
        add_action( 'admin_menu', array( $this, 'register_menus' ) );

        // Priority 9999 — runs after every core, module, and addon submenu
        // has registered. We physically reorder $submenu['tejcart'] here
        // rather than relying on the $position argument to add_submenu_page,
        // because WP's position arg has well-known edge cases (the first
        // submenu added always auto-appends and ignores its position, and
        // numeric-key collisions with auto-appended entries produce
        // unpredictable results). The order map below is the canonical IA;
        // see docs/admin-menu-ia.md.
        add_action( 'admin_menu', array( $this, 'reorder_submenu' ), 9999 );

        add_action( 'admin_init', array( $this, 'maybe_handle_product_action' ) );
        add_action( 'admin_init', array( $this, 'maybe_handle_order_action' ) );
        add_action( 'admin_init', array( $this, 'maybe_handle_customer_action' ) );
        add_action( 'admin_init', array( $this, 'maybe_handle_coupon_action' ) );

        add_action( 'admin_head', array( $this, 'hide_payment_method_settings_submenu' ) );
        add_action( 'admin_head', array( $this, 'hide_paypal_manage_submenu' ) );
    }

    /**
     * Canonical sidebar order for TejCart.
     *
     * Mirrors the order operators expect from major ecommerce platforms:
     * home → core resources (Orders, Products, Customers) →
     * marketing (Coupons) → post-purchase lifecycle (Disputes, Returns) →
     * analysis (Reports, Analytics) → extensions (Modules) → Settings last.
     *
     * First-party addon submenu slugs (contributed by plugins under
     * `addon/`) appear between AI Content and Modules — with the
     * "things you've installed" cluster, ahead of the Modules manager
     * UI and Settings. This matches the Shopify "Apps" / "Extensions"
     * placement convention.
     *
     * Any submenu slug not listed here keeps its WordPress-assigned slot
     * after the listed entries (defensive fallback for third-party addons
     * that register their own submenus). Third-party addons should hook
     * the `tejcart_admin_submenu_order` filter to opt into the canonical
     * order at their preferred position rather than relying on the append
     * fallback.
     *
     * @return string[]
     */
    public static function canonical_submenu_order(): array {
        $order = array(
            'tejcart',                          // Dashboard (the parent-slug duplicate WP creates)
            'tejcart-orders',
            'tejcart-products',
            'tejcart-customers',
            'tejcart-coupons',
            'tejcart-gift-cards',               // contributed by modules/gift-cards
            'tejcart-disputes',                 // contributed by modules/disputes
            'tejcart-returns',                  // contributed by modules/returns
            'tejcart-reports',
            'tejcart-analytics',                // contributed by modules/analytics (includes Advanced tab)
            'tejcart-b2b',                      // contributed by modules/b2b (includes Tiers + Quotes tabs)
            'tejcart-sales-channels',           // hub for Meta / TikTok / Amazon channel modules
            'tejcart-loyalty',                  // contributed by modules/loyalty
            'tejcart-referrals',                // contributed by modules/referrals
            'tejcart-experiments',              // contributed by modules/experiments
            'tejcart-ai-content',               // contributed by modules/ai-content-smartsuite
            'tejcart-subscriptions',            // contributed by addon/tejcart-subscriptions
            'tejcart-modules',
            'tejcart-settings',                 // always last (Shipping → Carriers lives under this tab)
        );

        /**
         * Filter the canonical TejCart sidebar order.
         *
         * Use this to insert a third-party addon's submenu slug at a
         * specific position, reorder existing entries, or unpin a slug
         * from the canonical ordering. Note: removing a slug from this
         * array does NOT hide it from the sidebar — it only un-pins its
         * position, after which it lands at the appended-fallback slot.
         * To hide a submenu entirely, call `remove_submenu_page()` on
         * `admin_head`.
         *
         * Callbacks must return an array of slug strings; a non-array
         * return is treated as a no-op and the unfiltered order is used.
         * Non-string / empty-string entries are dropped defensively.
         *
         * @since 1.0.0
         *
         * @param string[] $order Ordered list of submenu slugs.
         * @return string[]
         */
        $filtered = apply_filters( 'tejcart_admin_submenu_order', $order );

        if ( ! is_array( $filtered ) ) {
            return $order;
        }

        $cleaned = array();
        foreach ( $filtered as $slug ) {
            if ( is_string( $slug ) && '' !== $slug ) {
                $cleaned[] = $slug;
            }
        }
        return $cleaned;
    }

    /**
     * Reorder $submenu['tejcart'] to match canonical_submenu_order().
     *
     * Runs on admin_menu priority 9999 so it observes the final state of
     * the submenu array (all modules / addons have registered, but the
     * admin_head hide_* callbacks haven't fired yet — hidden entries are
     * harmless here because remove_submenu_page() preserves keys).
     *
     * Unknown slugs (third-party submenus) keep their relative order and
     * are appended after the canonical entries.
     *
     * @return void
     */
    public function reorder_submenu(): void {
        global $submenu;

        if ( empty( $submenu['tejcart'] ) || ! is_array( $submenu['tejcart'] ) ) {
            return;
        }

        $canonical = self::canonical_submenu_order();
        $by_slug   = array();
        foreach ( $submenu['tejcart'] as $entry ) {
            if ( isset( $entry[2] ) ) {
                $by_slug[ (string) $entry[2] ][] = $entry;
            }
        }

        $reordered = array();
        foreach ( $canonical as $slug ) {
            if ( isset( $by_slug[ $slug ] ) ) {
                foreach ( $by_slug[ $slug ] as $entry ) {
                    $reordered[] = $entry;
                }
                unset( $by_slug[ $slug ] );
            }
        }

        // Append any unknown slugs (third-party submenus, hidden aliases)
        // after the canonical entries, preserving their relative order.
        foreach ( $submenu['tejcart'] as $entry ) {
            $slug = isset( $entry[2] ) ? (string) $entry[2] : '';
            if ( '' !== $slug && isset( $by_slug[ $slug ] ) ) {
                $reordered[] = $entry;
                array_shift( $by_slug[ $slug ] );
                if ( empty( $by_slug[ $slug ] ) ) {
                    unset( $by_slug[ $slug ] );
                }
            }
        }

        $submenu['tejcart'] = array_values( $reordered );
    }

    /**
     * Dispatch product list-row actions (delete, duplicate) on admin_init
     * so the redirect fires before any admin chrome is printed. Running
     * these in the menu callback (render_products) is too late — the admin
     * header has already been sent and wp_safe_redirect() becomes a no-op,
     * leaving the merchant on a blank page.
     *
     * @return void
     */
    public function maybe_handle_product_action() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        $action2 = isset( $_GET['action2'] ) ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : '';
        // phpcs:enable

        if ( 'tejcart-products' !== $page ) {
            return;
        }

        if ( 'export_csv' === $action || 'export_csv' === $action2 ) {
            $this->handle_products_bulk_export();
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;
        if ( ! $product_id ) {
            return;
        }

        if ( 'delete' === $action ) {
            $this->handle_product_delete( $product_id );
            return;
        }

        if ( 'duplicate' === $action ) {
            $this->handle_product_duplicate( $product_id );
        }
    }

    /**
     * Stream a CSV file of the selected products as the admin page's
     * response. Runs on admin_init — WordPress admin chrome has not
     * yet emitted any output, so we can safely send download headers.
     *
     * Silently returns on nonce / capability / empty-selection failure
     * so the page falls through to its normal render with no side
     * effects. Export is one SELECT over the products table plus one
     * JOIN for categories — no per-row hydrations.
     *
     * @return void
     */
    private function handle_products_bulk_export() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bulk-products' ) ) {
            return;
        }
        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
        $ids = isset( $_REQUEST['product_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['product_ids'] ) ) : array();
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( empty( $ids ) ) {
            return;
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'tejcart_products';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, name, sku, type, status, price, sale_price, stock_status, manage_stock, stock_quantity, featured, created_at
                 FROM {$table}
                 WHERE id IN ({$placeholders})
                 ORDER BY id ASC",
                $ids
            ),
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            ARRAY_A
        );

        $taxonomy = \TejCart\Product\Product_Taxonomy::CATEGORY_TAXONOMY;
        $cat_rows = $wpdb->get_results(
            // phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT tr.object_id AS pid, t.name
                 FROM {$wpdb->term_relationships} tr
                 INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
                 INNER JOIN {$wpdb->terms} t         ON t.term_id          = tt.term_id
                 WHERE tt.taxonomy = %s
                   AND tr.object_id IN ({$placeholders})
                 ORDER BY tr.object_id, t.name",
                array_merge( array( $taxonomy ), $ids )
            ),
            // phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            ARRAY_A
        );
        // phpcs:enable

        $categories_by_id = array();
        foreach ( (array) $cat_rows as $row ) {
            $categories_by_id[ (int) $row['pid'] ][] = (string) $row['name'];
        }

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        $filename = 'tejcart-products-' . gmdate( 'Y-m-d' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // Streaming download to php://output; WP_Filesystem does not handle stream wrappers.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( 'php://output', 'w' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, array(
            'ID', 'Name', 'SKU', 'Type', 'Status',
            'Regular price', 'Sale price',
            'Stock status', 'Manage stock', 'Stock quantity',
            'Featured', 'Categories', 'Created at',
        ) );

        foreach ( (array) $rows as $row ) {
            $pid  = (int) $row['id'];
            $cats = isset( $categories_by_id[ $pid ] ) ? implode( ', ', $categories_by_id[ $pid ] ) : '';
            fputcsv( $out, tejcart_csv_sanitize_row( array(
                $pid,
                (string) $row['name'],
                (string) $row['sku'],
                (string) $row['type'],
                (string) $row['status'],
                (string) $row['price'],
                (string) $row['sale_price'],
                (string) $row['stock_status'],
                empty( $row['manage_stock'] ) ? 'no' : 'yes',
                ( '' === (string) $row['stock_quantity'] || null === $row['stock_quantity'] ) ? '' : (string) (int) $row['stock_quantity'],
                empty( $row['featured'] ) ? 'no' : 'yes',
                $cats,
                (string) $row['created_at'],
            ) ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    /**
     * Parallel of maybe_handle_product_action() for the Orders list.
     * Today we only intercept bulk `export_csv`, but the method gives
     * us a landing spot for future before-headers order actions.
     *
     * @return void
     */
    public function maybe_handle_order_action() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        $action2 = isset( $_GET['action2'] ) ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : '';
        // phpcs:enable

        if ( 'tejcart-orders' !== $page ) {
            return;
        }

        if ( 'export_csv' === $action || 'export_csv' === $action2 ) {
            $this->handle_orders_bulk_export();
        }
    }

    /**
     * Stream a CSV of the selected orders. Runs on admin_init so the
     * download headers land before any page output. Silent no-op on
     * nonce / cap / empty-selection failure so the list falls through
     * to its normal render.
     *
     * @return void
     */
    private function handle_orders_bulk_export() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bulk-orders' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
        $ids = isset( $_REQUEST['order_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['order_ids'] ) ) : array();
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( empty( $ids ) ) {
            return;
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'tejcart_orders';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, order_number, status, total, currency, customer_name, customer_email, payment_method, created_at
                 FROM {$table}
                 WHERE id IN ({$placeholders})
                 ORDER BY id ASC",
                $ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        $filename = 'tejcart-orders-' . gmdate( 'Y-m-d' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // Streaming download to php://output; WP_Filesystem does not handle stream wrappers.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( 'php://output', 'w' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, array(
            'Order ID', 'Order #', 'Status', 'Total', 'Currency',
            'Customer name', 'Customer email',
            'Payment method', 'Created at',
        ) );

        foreach ( (array) $rows as $row ) {
            fputcsv( $out, tejcart_csv_sanitize_row( array(
                (int) $row['id'],
                (string) $row['order_number'],
                (string) $row['status'],
                (string) $row['total'],
                (string) $row['currency'],
                (string) $row['customer_name'],
                (string) $row['customer_email'],
                (string) $row['payment_method'],
                (string) $row['created_at'],
            ) ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    /**
     * Parallel of maybe_handle_order_action() for the Customers list.
     * Only handles bulk `export_csv` today.
     *
     * @return void
     */
    public function maybe_handle_customer_action() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        $action2 = isset( $_GET['action2'] ) ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : '';
        // phpcs:enable

        if ( 'tejcart-customers' !== $page ) {
            return;
        }

        if ( 'export_csv' === $action || 'export_csv' === $action2 ) {
            $this->handle_customers_bulk_export();
        }
    }

    /**
     * Stream a CSV of the selected customers on admin_init so the
     * download headers land before any page output. Mirrors
     * handle_orders_bulk_export().
     *
     * @return void
     */
    private function handle_customers_bulk_export() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bulk-customers' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.NonceVerification.Recommended
        $ids = isset( $_REQUEST['customer_ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['customer_ids'] ) ) : array();
        $ids = array_values( array_unique( array_filter( $ids ) ) );
        if ( empty( $ids ) ) {
            return;
        }

        global $wpdb;
        $customers    = $wpdb->prefix . 'tejcart_customers';
        $orders       = $wpdb->prefix . 'tejcart_orders';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT c.id, c.user_id, c.email, c.first_name, c.last_name, c.created_at,
                        COALESCE(SUM(CASE WHEN o.id IS NULL THEN 0 ELSE 1 END), 0) AS order_count,
                        COALESCE(SUM(CASE WHEN o.status = 'completed' THEN o.total ELSE 0 END), 0) AS total_spent
                 FROM {$customers} c
                 LEFT JOIN {$orders} o
                        ON (c.user_id IS NOT NULL AND o.customer_id = c.user_id)
                        OR LOWER(o.customer_email) = LOWER(c.email)
                 WHERE c.id IN ({$placeholders})
                 GROUP BY c.id
                 ORDER BY c.id ASC",
                $ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        $filename = 'tejcart-customers-' . gmdate( 'Y-m-d' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // Streaming download to php://output; WP_Filesystem does not handle stream wrappers.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( 'php://output', 'w' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, array(
            'Customer ID', 'WP user ID', 'Email',
            'First name', 'Last name',
            'Orders', 'Total spent (completed)',
            'Registered',
        ) );

        foreach ( (array) $rows as $row ) {
            fputcsv( $out, tejcart_csv_sanitize_row( array(
                (int) $row['id'],
                null === $row['user_id'] ? '' : (string) (int) $row['user_id'],
                (string) $row['email'],
                (string) $row['first_name'],
                (string) $row['last_name'],
                (int) $row['order_count'],
                (string) $row['total_spent'],
                (string) $row['created_at'],
            ) ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    /**
     * Parallel of maybe_handle_product_action() for the Coupons list.
     * Dispatches bulk actions (export_csv, activate, deactivate, delete)
     * and single row-action deletes on admin_init so the response headers
     * (download / redirect) land before any admin chrome is emitted.
     *
     * @return void
     */
    public function maybe_handle_coupon_action() {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended
        $page    = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
        $action  = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
        $action2 = isset( $_GET['action2'] ) ? sanitize_key( wp_unslash( $_GET['action2'] ) ) : '';
        // phpcs:enable

        if ( 'tejcart-coupons' !== $page ) {
            return;
        }

        // handle_coupon_save() performs its own nonce verification.
        // phpcs:ignore WordPress.Security.NonceVerification.Missing
        if ( 'save' === $action && ! empty( $_POST ) ) {
            $this->handle_coupon_save();
            return;
        }

        $bulk_action = in_array( $action, array( 'export_csv', 'activate', 'deactivate', 'delete' ), true )
            ? $action
            : ( in_array( $action2, array( 'export_csv', 'activate', 'deactivate', 'delete' ), true ) ? $action2 : '' );

        if ( '' !== $bulk_action ) {
            // Each id is coerced through absint() below.
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
            $raw_ids = isset( $_REQUEST['coupon_ids'] ) ? (array) wp_unslash( $_REQUEST['coupon_ids'] ) : array();
            $ids     = array_values( array_unique( array_filter( array_map( 'absint', $raw_ids ) ) ) );
            if ( ! empty( $ids ) ) {
                if ( 'export_csv' === $bulk_action ) {
                    $this->handle_coupons_bulk_export( $ids );
                    return;
                }
                $this->handle_coupons_bulk_state( $bulk_action, $ids );
                return;
            }
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $coupon_id = isset( $_GET['coupon_id'] ) ? absint( $_GET['coupon_id'] ) : 0;
        if ( 'delete' === $action && $coupon_id > 0 ) {
            $this->handle_coupon_delete( $coupon_id );
        }
    }

    /**
     * Stream a CSV of the selected coupons. Mirrors the orders / customers
     * exporters — silent no-op on nonce / cap failure so the list falls
     * through to its normal render.
     *
     * @param int[] $ids Coupon IDs to export.
     * @return void
     */
    private function handle_coupons_bulk_export( array $ids ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bulk-coupons' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        global $wpdb;
        $table        = $wpdb->prefix . 'tejcart_coupons';
        $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT id, code, type, amount_minor_units, percentage_basis_points, usage_count, usage_limit, usage_limit_per_user, minimum_amount_minor_units, maximum_amount_minor_units, status, expires_at, created_at
                 FROM {$table}
                 WHERE id IN ({$placeholders})
                 ORDER BY id ASC",
                $ids
            ),
            ARRAY_A
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

        $shop_currency = (string) get_option( 'tejcart_currency', 'USD' );

        while ( ob_get_level() > 0 ) {
            ob_end_clean();
        }

        $filename = 'tejcart-coupons-' . gmdate( 'Y-m-d' ) . '.csv';
        nocache_headers();
        header( 'Content-Type: text/csv; charset=UTF-8' );
        header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

        // Streaming download to php://output; WP_Filesystem does not handle stream wrappers.
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
        $out = fopen( 'php://output', 'w' );

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
        fwrite( $out, "\xEF\xBB\xBF" );

        fputcsv( $out, array(
            'ID', 'Code', 'Type', 'Amount',
            'Usage count', 'Usage limit', 'Per-customer limit',
            'Minimum amount', 'Maximum amount',
            'Status', 'Expires at', 'Created at',
        ) );

        foreach ( (array) $rows as $row ) {
            // The CSV exposes the same display value the admin UI shows:
            // percent coupons render as a 0–100 decimal, fixed coupons
            // as a major-unit money string in the shop currency.
            if ( 'percentage' === (string) $row['type'] ) {
                $amount_display = null !== $row['percentage_basis_points']
                    ? (string) ( ( (int) $row['percentage_basis_points'] ) / 100 )
                    : '';
            } else {
                $amount_display = null !== $row['amount_minor_units']
                    ? (string) \TejCart\Money\Currency::from_minor_units( (int) $row['amount_minor_units'], $shop_currency )
                    : '';
            }
            $min_display = null !== $row['minimum_amount_minor_units']
                ? (string) \TejCart\Money\Currency::from_minor_units( (int) $row['minimum_amount_minor_units'], $shop_currency )
                : '';
            $max_display = null !== $row['maximum_amount_minor_units']
                ? (string) \TejCart\Money\Currency::from_minor_units( (int) $row['maximum_amount_minor_units'], $shop_currency )
                : '';

            fputcsv( $out, tejcart_csv_sanitize_row( array(
                (int) $row['id'],
                (string) $row['code'],
                (string) $row['type'],
                $amount_display,
                (int) $row['usage_count'],
                null === $row['usage_limit'] || '' === $row['usage_limit'] ? '' : (string) (int) $row['usage_limit'],
                null === $row['usage_limit_per_user'] || '' === $row['usage_limit_per_user'] ? '' : (string) (int) $row['usage_limit_per_user'],
                $min_display,
                $max_display,
                (string) $row['status'],
                empty( $row['expires_at'] ) ? '' : (string) $row['expires_at'],
                (string) $row['created_at'],
            ) ) );
        }

        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
        fclose( $out );
        exit;
    }

    /**
     * Apply a state-mutation bulk action (activate / deactivate / delete)
     * to the selected coupons, then redirect back to the list with a
     * transient notice so the user sees what happened.
     *
     * @param string $action One of activate, deactivate, delete.
     * @param int[]  $ids    Coupon IDs to mutate.
     * @return void
     */
    private function handle_coupons_bulk_state( string $action, array $ids ) {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $nonce = isset( $_REQUEST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) ) : '';
        if ( ! wp_verify_nonce( $nonce, 'bulk-coupons' ) ) {
            return;
        }
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $affected = 0;

        if ( 'delete' === $action ) {
            foreach ( $ids as $id ) {
                $coupon = new Coupon( (int) $id );
                if ( $coupon->get_id() ) {
                    $coupon->delete();
                    $affected++;
                }
            }
        } else {
            global $wpdb;
            $table        = $wpdb->prefix . 'tejcart_coupons';
            $placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
            $status       = 'activate' === $action ? 'active' : 'inactive';
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
            $affected = (int) $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "UPDATE {$table} SET status = %s WHERE id IN ({$placeholders})",
                    array_merge( array( $status ), $ids )
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
        }

        set_transient(
            'tejcart_bulk_coupons_notice_' . get_current_user_id(),
            array( 'action' => $action, 'count' => $affected ),
            60
        );

        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-coupons' ) );
        exit;
    }

    /**
     * Register the main TejCart menu and its submenus.
     *
     * Sidebar ORDER is owned by reorder_submenu() (admin_menu priority 9999),
     * not by the position arg to add_submenu_page — that argument has
     * well-known edge cases in WordPress (the first submenu always
     * auto-appends and ignores its position; numeric collisions produce
     * unpredictable results). See canonical_submenu_order() for the map
     * and docs/admin-menu-ia.md for the rationale.
     *
     * @return void
     */
    public function register_menus() {
        add_menu_page(
            __( 'TejCart', 'tejcart' ),
            __( 'TejCart', 'tejcart' ),
            'manage_options',
            'tejcart',
            array( $this, 'render_dashboard' ),
            'dashicons-cart',
            55
        );

        add_submenu_page(
            'tejcart',
            __( 'Dashboard', 'tejcart' ),
            __( 'Dashboard', 'tejcart' ),
            'manage_options',
            'tejcart',
            array( $this, 'render_dashboard' )
        );

        add_submenu_page(
            'tejcart',
            __( 'Orders', 'tejcart' ),
            __( 'Orders', 'tejcart' ),
            'manage_options',
            'tejcart-orders',
            array( $this, 'render_orders' )
        );

        add_submenu_page(
            'tejcart',
            __( 'Products', 'tejcart' ),
            __( 'Products', 'tejcart' ),
            'manage_options',
            'tejcart-products',
            array( $this, 'render_products' )
        );

        add_submenu_page(
            'tejcart',
            __( 'Customers', 'tejcart' ),
            __( 'Customers', 'tejcart' ),
            'manage_options',
            'tejcart-customers',
            array( $this, 'render_customers' )
        );

        add_submenu_page(
            'tejcart',
            __( 'Coupons', 'tejcart' ),
            __( 'Coupons', 'tejcart' ),
            'manage_options',
            'tejcart-coupons',
            array( $this, 'render_coupons' )
        );

        add_submenu_page(
            'tejcart',
            __( 'Payment Method Settings', 'tejcart' ),
            __( 'Payment Method Settings', 'tejcart' ),
            'manage_options',
            'tejcart-payment-method-settings',
            array( $this, 'render_payment_method_settings' )
        );

        add_submenu_page(
            'tejcart',
            __( 'PayPal Payments', 'tejcart' ),
            __( 'PayPal Payments', 'tejcart' ),
            'manage_options',
            PayPal_Manage_Page::PAGE_SLUG,
            array( $this, 'render_paypal_manage' )
        );

        add_submenu_page(
            'tejcart',
            __( 'Reports', 'tejcart' ),
            __( 'Reports', 'tejcart' ),
            'manage_options',
            'tejcart-reports',
            array( $this, 'render_reports' )
        );

        add_submenu_page(
            'tejcart',
            __( 'Settings', 'tejcart' ),
            __( 'Settings', 'tejcart' ),
            'manage_options',
            'tejcart-settings',
            array( $this, 'render_settings' )
        );
    }

    /**
     * Render reports page.
     */
    public function render_reports(): void {
        $reports = new \TejCart\Admin\Reports();
        $reports->render();
    }

    /**
     * Render the admin dashboard page with summary stats.
     *
     * @return void
     */
    public function render_dashboard() {
        global $wpdb;

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only range toggle.
        $range_raw = isset( $_GET['range'] ) ? sanitize_text_field( wp_unslash( $_GET['range'] ) ) : '30d';
        $ranges    = array(
            '7d'  => array( 'label' => __( 'Last 7 days', 'tejcart' ),  'days' => 7 ),
            '30d' => array( 'label' => __( 'Last 30 days', 'tejcart' ), 'days' => 30 ),
            '90d' => array( 'label' => __( 'Last 90 days', 'tejcart' ), 'days' => 90 ),
            'all' => array( 'label' => __( 'All time', 'tejcart' ),     'days' => 0 ),
        );
        if ( ! isset( $ranges[ $range_raw ] ) ) {
            $range_raw = '30d';
        }
        $range_days = $ranges[ $range_raw ]['days'];

        $now        = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
        $from_ts    = $range_days > 0 ? $now - ( $range_days * DAY_IN_SECONDS ) : 0;
        $prev_from  = $range_days > 0 ? $from_ts - ( $range_days * DAY_IN_SECONDS ) : 0;
        $prev_to    = $range_days > 0 ? $from_ts : 0;
        $range_from = $range_days > 0 ? gmdate( 'Y-m-d H:i:s', $from_ts ) : '1970-01-01 00:00:00';
        $range_to   = gmdate( 'Y-m-d H:i:s', $now );
        $prev_range_from = $range_days > 0 ? gmdate( 'Y-m-d H:i:s', $prev_from ) : '1970-01-01 00:00:00';
        $prev_range_to   = $range_days > 0 ? gmdate( 'Y-m-d H:i:s', $prev_to ) : '1970-01-01 00:00:00';

        $orders_table    = $wpdb->prefix . 'tejcart_orders';
        $items_table     = $wpdb->prefix . 'tejcart_order_items';
        $products_table  = $wpdb->prefix . 'tejcart_products';
        $customers_table = $wpdb->prefix . 'tejcart_customers';
        $shop_currency   = (string) get_option( 'tejcart_currency', 'USD' );

        $live_statuses = "('completed','processing')";

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        $revenue = \TejCart\Money\Currency::from_minor_units( (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COALESCE(SUM(total),0) FROM {$orders_table} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s",
            $range_from, $range_to
        ) ), $shop_currency );
        $orders_count = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$orders_table} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s",
            $range_from, $range_to
        ) );
        $avg_order = $orders_count > 0 ? $revenue / $orders_count : 0.0;
        $new_customers = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM {$customers_table} WHERE created_at BETWEEN %s AND %s",
            $range_from, $range_to
        ) );

        $prev_revenue = 0.0; $prev_orders = 0; $prev_aov = 0.0; $prev_new_customers = 0;
        if ( $range_days > 0 ) {
            $prev_revenue = \TejCart\Money\Currency::from_minor_units( (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COALESCE(SUM(total),0) FROM {$orders_table} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s",
                $prev_range_from, $prev_range_to
            ) ), $shop_currency );
            $prev_orders = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$orders_table} WHERE status IN ('completed','processing') AND created_at BETWEEN %s AND %s",
                $prev_range_from, $prev_range_to
            ) );
            $prev_aov = $prev_orders > 0 ? $prev_revenue / $prev_orders : 0.0;
            $prev_new_customers = (int) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$customers_table} WHERE created_at BETWEEN %s AND %s",
                $prev_range_from, $prev_range_to
            ) );
        }

        // DB-006: Collapse the four per-status order COUNTs and the
        // all-time COUNT into a single row scan via conditional
        // aggregates. Old code = 5 round trips; new code = 1.
        $status_counts_row = $wpdb->get_row(
            "SELECT
                SUM(CASE WHEN status = 'pending'    THEN 1 ELSE 0 END) AS pending_count,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing_count,
                SUM(CASE WHEN status = 'on-hold'    THEN 1 ELSE 0 END) AS on_hold_count,
                SUM(CASE WHEN status = 'failed'     THEN 1 ELSE 0 END) AS failed_count,
                COUNT(*) AS all_time_orders
             FROM {$orders_table}"
        );
        $pending_count    = (int) ( $status_counts_row->pending_count ?? 0 );
        $processing_count = (int) ( $status_counts_row->processing_count ?? 0 );
        $on_hold_count    = (int) ( $status_counts_row->on_hold_count ?? 0 );
        $failed_count     = (int) ( $status_counts_row->failed_count ?? 0 );

        $low_stock_threshold = (int) get_option( 'tejcart_low_stock_threshold', 5 );
        // Same conditional-aggregate trick for the product stock
        // health counts.
        $product_stock_row = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                SUM(CASE WHEN status = 'publish' AND stock_status = 'outofstock' THEN 1 ELSE 0 END) AS out_of_stock_count,
                SUM(CASE WHEN status = 'publish' AND manage_stock = 1 AND stock_quantity <= %d AND stock_quantity > 0 THEN 1 ELSE 0 END) AS low_stock_count,
                COUNT(*) AS all_time_products
             FROM {$products_table}",
            $low_stock_threshold
        ) );
        $out_of_stock_count = (int) ( $product_stock_row->out_of_stock_count ?? 0 );
        $low_stock_count    = (int) ( $product_stock_row->low_stock_count ?? 0 );

        $recent_orders = $wpdb->get_results(
            "SELECT id, order_number, customer_name, customer_email, currency, total, status, created_at
             FROM {$orders_table}
             ORDER BY created_at DESC
             LIMIT 8"
        );
        // Each row's `total` is BIGINT minor units in its own currency
        // (multi-currency safe); convert to a major-unit float in place
        // so the template's `tejcart_price()` call below sees the right
        // number without needing per-template plumbing.
        foreach ( (array) $recent_orders as $r ) {
            if ( isset( $r->total ) ) {
                $row_currency = (string) ( $r->currency ?? $shop_currency );
                $r->total     = \TejCart\Money\Currency::from_minor_units( (int) $r->total, $row_currency );
            }
        }

        $top_products = $wpdb->get_results( $wpdb->prepare(
            "SELECT oi.product_id, p.name AS product_name, p.sku, SUM(oi.quantity) AS qty, SUM(oi.line_total) AS rev
             FROM {$items_table} oi
             INNER JOIN {$orders_table} o ON oi.order_id = o.id
             LEFT JOIN {$products_table} p ON oi.product_id = p.id
             WHERE o.status IN ('completed','processing') AND o.created_at BETWEEN %s AND %s
             GROUP BY oi.product_id
             ORDER BY rev DESC
             LIMIT 5",
            $range_from, $range_to
        ) );
        // line_total aggregate is shop-currency minor units (revenue from
        // multi-currency orders gets summed nominally; same caveat as the
        // pre-migration code, which also assumed shop currency here).
        foreach ( (array) $top_products as $r ) {
            if ( isset( $r->rev ) ) {
                $r->rev = \TejCart\Money\Currency::from_minor_units( (int) $r->rev, $shop_currency );
            }
        }

        $low_stock_items = $wpdb->get_results( $wpdb->prepare(
            "SELECT id, name, sku, stock_quantity, stock_status
             FROM {$products_table}
             WHERE status = 'publish' AND manage_stock = 1 AND stock_quantity <= %d
             ORDER BY stock_quantity ASC
             LIMIT 5",
            $low_stock_threshold
        ) );

        // The order / product all-time totals were folded into the
        // conditional-aggregate queries above (DB-006); only customers
        // still needs its own round-trip.
        $all_time_orders    = (int) ( $status_counts_row->all_time_orders ?? 0 );
        $all_time_products  = (int) ( $product_stock_row->all_time_products ?? 0 );
        $all_time_customers = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$customers_table}" );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        $orders_list_url  = admin_url( 'admin.php?page=tejcart-orders' );
        $products_url     = admin_url( 'admin.php?page=tejcart-products' );
        $reports_url      = admin_url( 'admin.php?page=tejcart-reports' );
        $add_product_url  = admin_url( 'admin.php?page=tejcart-products&action=add' );
        $add_order_url    = admin_url( 'admin.php?page=tejcart-orders&action=new' );
        $add_coupon_url   = admin_url( 'admin.php?page=tejcart-coupons&action=add' );
        $settings_url     = admin_url( 'admin.php?page=tejcart-settings' );
        $system_url       = admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=system-status' );

        $this->render_dashboard_html(
            array(
                'range_raw'          => $range_raw,
                'ranges'             => $ranges,
                'revenue'            => $revenue,
                'orders_count'       => $orders_count,
                'avg_order'          => $avg_order,
                'new_customers'      => $new_customers,
                'prev_revenue'       => $prev_revenue,
                'prev_orders'        => $prev_orders,
                'prev_aov'           => $prev_aov,
                'prev_new_customers' => $prev_new_customers,
                'pending_count'      => $pending_count,
                'processing_count'   => $processing_count,
                'on_hold_count'      => $on_hold_count,
                'failed_count'       => $failed_count,
                'out_of_stock_count' => $out_of_stock_count,
                'low_stock_count'    => $low_stock_count,
                'low_stock_threshold'=> $low_stock_threshold,
                'recent_orders'      => $recent_orders,
                'top_products'       => $top_products,
                'low_stock_items'    => $low_stock_items,
                'all_time_orders'    => $all_time_orders,
                'all_time_products'  => $all_time_products,
                'all_time_customers' => $all_time_customers,
                'orders_list_url'    => $orders_list_url,
                'products_url'       => $products_url,
                'reports_url'        => $reports_url,
                'add_product_url'    => $add_product_url,
                'add_order_url'      => $add_order_url,
                'add_coupon_url'     => $add_coupon_url,
                'settings_url'       => $settings_url,
                'system_url'         => $system_url,
            )
        );
    }

    /**
     * Format a signed percentage delta ("+12.4%", "-3.1%", "—").
     *
     * @param float $current  Current-period value.
     * @param float $previous Previous-period value.
     * @return array{label:string,tone:string}
     */
    private function dashboard_delta( float $current, float $previous ): array {
        if ( $previous <= 0 ) {
            if ( $current > 0 ) {
                return array( 'label' => __( 'New', 'tejcart' ), 'tone' => 'up' );
            }
            return array( 'label' => '—', 'tone' => 'flat' );
        }
        $pct  = ( ( $current - $previous ) / $previous ) * 100;
        $tone = $pct > 0.05 ? 'up' : ( $pct < -0.05 ? 'down' : 'flat' );
        $sign = $pct > 0 ? '+' : '';
        return array(
            'label' => $sign . number_format_i18n( abs( $pct ) < 100 ? round( $pct, 1 ) : round( $pct ), abs( $pct ) < 100 ? 1 : 0 ) . '%',
            'tone'  => $tone,
        );
    }

    /**
     * Render the dashboard markup from precomputed data. Keeps
     * render_dashboard() focused on the data layer and this method
     * on presentation.
     *
     * @param array<string,mixed> $d Precomputed dashboard data.
     * @return void
     */
    private function render_dashboard_html( array $d ): void {
        $range_raw = $d['range_raw'];
        $ranges    = $d['ranges'];

        $rev_delta = $this->dashboard_delta( (float) $d['revenue'], (float) $d['prev_revenue'] );
        $ord_delta = $this->dashboard_delta( (float) $d['orders_count'], (float) $d['prev_orders'] );
        $aov_delta = $this->dashboard_delta( (float) $d['avg_order'], (float) $d['prev_aov'] );
        $cst_delta = $this->dashboard_delta( (float) $d['new_customers'], (float) $d['prev_new_customers'] );

        $pending_url    = admin_url( 'admin.php?page=tejcart-orders&status=pending' );
        $processing_url = admin_url( 'admin.php?page=tejcart-orders&status=processing' );
        $on_hold_url    = admin_url( 'admin.php?page=tejcart-orders&status=on-hold' );
        $failed_url     = admin_url( 'admin.php?page=tejcart-orders&status=failed' );
        $low_stock_url  = admin_url( 'admin.php?page=tejcart-products&stock_status=low' );
        $out_stock_url  = admin_url( 'admin.php?page=tejcart-products&stock_status=outofstock' );

        $has_sales = ! empty( $d['recent_orders'] );

        $status_label = array(
            'pending'    => __( 'Pending payment', 'tejcart' ),
            'processing' => __( 'Processing', 'tejcart' ),
            'on-hold'    => __( 'On hold', 'tejcart' ),
            'completed'  => __( 'Completed', 'tejcart' ),
            'cancelled'  => __( 'Cancelled', 'tejcart' ),
            'refunded'   => __( 'Refunded', 'tejcart' ),
            'failed'     => __( 'Failed', 'tejcart' ),
        );
        ?>
        <div class="wrap tejcart-admin-wrap nxc-list nxc-dashboard">
            <header class="nxc-page-header">
                <div class="nxc-page-header__title">
                    <h1><?php esc_html_e( 'Dashboard', 'tejcart' ); ?></h1>
                    <p class="nxc-page-header__subtitle">
                        <?php
                        echo esc_html( sprintf(
                            /* translators: 1: orders count, 2: products count, 3: customers count */
                            __( 'Welcome back — %1$s orders · %2$s products · %3$s customers to date.', 'tejcart' ),
                            number_format_i18n( (int) $d['all_time_orders'] ),
                            number_format_i18n( (int) $d['all_time_products'] ),
                            number_format_i18n( (int) $d['all_time_customers'] )
                        ) );
                        ?>
                    </p>
                </div>
                <div class="nxc-page-header__actions">
                    <a class="nxc-btn" href="<?php echo esc_url( $d['reports_url'] ); ?>">
                        <svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 4 4 5-6"/></svg>
                        <?php esc_html_e( 'View reports', 'tejcart' ); ?>
                    </a>
                    <a class="nxc-btn nxc-btn--primary" href="<?php echo esc_url( $d['add_product_url'] ); ?>">
                        <svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <?php esc_html_e( 'Add product', 'tejcart' ); ?>
                    </a>
                </div>
            </header>

            <span class="wp-header-end"></span>

            <div class="nxc-toolbar nxc-dashboard-toolbar">
                <ul class="nxc-reports-tabs" role="tablist" aria-label="<?php esc_attr_e( 'Date range', 'tejcart' ); ?>">
                    <?php foreach ( $ranges as $key => $cfg ) :
                        $url    = add_query_arg( array( 'page' => 'tejcart', 'range' => $key ), admin_url( 'admin.php' ) );
                        $active = ( $key === $range_raw );
                        ?>
                        <li>
                            <a href="<?php echo esc_url( $url ); ?>"
                               class="<?php echo $active ? 'is-active' : ''; ?>"
                               <?php echo $active ? 'aria-current="page"' : ''; ?>>
                                <?php echo esc_html( $cfg['label'] ); ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <div class="nxc-stats nxc-stats--dashboard">
                <?php
                $this->render_dashboard_stat(
                    __( 'Revenue', 'tejcart' ),
                    tejcart_price( (float) $d['revenue'] ),
                    $rev_delta
                );
                $this->render_dashboard_stat(
                    __( 'Orders', 'tejcart' ),
                    number_format_i18n( (int) $d['orders_count'] ),
                    $ord_delta
                );
                $this->render_dashboard_stat(
                    __( 'Average order value', 'tejcart' ),
                    tejcart_price( (float) $d['avg_order'] ),
                    $aov_delta
                );
                $this->render_dashboard_stat(
                    __( 'New customers', 'tejcart' ),
                    number_format_i18n( (int) $d['new_customers'] ),
                    $cst_delta
                );
                ?>
            </div>

            <div class="nxc-dashboard-grid">
                <section class="nxc-card nxc-data-card nxc-dashboard-recent">
                    <header class="nxc-data-card__header">
                        <div>
                            <h3><?php esc_html_e( 'Recent orders', 'tejcart' ); ?></h3>
                            <p class="nxc-data-card__subtitle"><?php esc_html_e( 'Latest 8 orders across every status.', 'tejcart' ); ?></p>
                        </div>
                        <a class="nxc-btn nxc-btn--sm" href="<?php echo esc_url( $d['orders_list_url'] ); ?>">
                            <?php esc_html_e( 'View all', 'tejcart' ); ?>
                        </a>
                    </header>
                    <?php if ( $has_sales ) : ?>
                        <table class="wp-list-table widefat nxc-dashboard-orders-table">
                            <thead>
                                <tr>
                                    <th><?php esc_html_e( 'Order', 'tejcart' ); ?></th>
                                    <th><?php esc_html_e( 'Customer', 'tejcart' ); ?></th>
                                    <th><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                                    <th><?php esc_html_e( 'Date', 'tejcart' ); ?></th>
                                    <th class="nxc-col-right"><?php esc_html_e( 'Total', 'tejcart' ); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ( $d['recent_orders'] as $o ) :
                                $order_url = admin_url( 'admin.php?page=tejcart-orders&action=view&order_id=' . (int) $o->id );
                                $number    = '' !== trim( (string) $o->order_number ) ? $o->order_number : ( '#' . (int) $o->id );
                                $name      = trim( (string) $o->customer_name ) !== '' ? $o->customer_name : __( '(Guest)', 'tejcart' );
                                $status    = (string) $o->status;
                                $s_label   = $status_label[ $status ] ?? ucfirst( str_replace( array( '-', '_' ), ' ', $status ) );
                                $ts        = strtotime( (string) $o->created_at );
                                // Render the total in the order's own currency
                                // (totals are multi-currency); without this the
                                // shop-default symbol would be used for orders
                                // placed in another currency.
                                $row_currency = strtoupper( trim( (string) ( $o->currency ?? '' ) ) );
                                if ( '' === $row_currency || ! \TejCart\Money\Currency::is_valid_shape( $row_currency ) ) {
                                    $row_currency = (string) tejcart_get_currency();
                                }
                                ?>
                                <tr>
                                    <td><a class="nxc-order-number" href="<?php echo esc_url( $order_url ); ?>"><?php echo esc_html( $number ); ?></a></td>
                                    <td>
                                        <div class="nxc-customer">
                                            <?php
                                            $avatar_html = ! empty( $o->customer_email ) && function_exists( 'get_avatar' )
                                                ? get_avatar( $o->customer_email, 28, '', $name, array( 'class' => 'nxc-customer__avatar' ) )
                                                : sprintf(
                                                    '<span class="nxc-customer__avatar nxc-customer__avatar--initial" aria-hidden="true">%s</span>',
                                                    esc_html( function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $name, 0, 1 ) ) : strtoupper( substr( $name, 0, 1 ) ) )
                                                );
                                            echo wp_kses_post( $avatar_html );
                                            ?>
                                            <span class="nxc-customer__text">
                                                <span class="nxc-customer__name"><?php echo esc_html( $name ); ?></span>
                                                <?php if ( ! empty( $o->customer_email ) ) : ?>
                                                    <span class="nxc-customer__email"><?php echo esc_html( $o->customer_email ); ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </td>
                                    <td>
                                        <span class="nxc-status nxc-status--<?php echo esc_attr( $status ); ?>">
                                            <span class="nxc-status__dot"></span>
                                            <span class="nxc-status__label"><?php echo esc_html( $s_label ); ?></span>
                                        </span>
                                    </td>
                                    <td><span class="nxc-date"><?php echo esc_html( $ts ? date_i18n( get_option( 'date_format', 'Y-m-d' ), $ts ) : (string) $o->created_at ); ?></span></td>
                                    <td class="nxc-col-right"><span class="nxc-price"><?php echo wp_kses_post( tejcart_price( (float) $o->total, $row_currency ) ); ?></span></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php else : ?>
                        <div class="nxc-data-card__empty">
                            <?php esc_html_e( 'No orders yet. When customers check out, they will show up here.', 'tejcart' ); ?>
                        </div>
                    <?php endif; ?>
                </section>

                <aside class="nxc-dashboard-side">
                    <section class="nxc-card nxc-dashboard-action-card">
                        <header class="nxc-data-card__header">
                            <div>
                                <h3><?php esc_html_e( 'Needs attention', 'tejcart' ); ?></h3>
                                <p class="nxc-data-card__subtitle"><?php esc_html_e( 'Open work across orders and stock.', 'tejcart' ); ?></p>
                            </div>
                        </header>
                        <ul class="nxc-action-list">
                            <?php
                            $this->render_action_row(
                                __( 'Pending payment', 'tejcart' ),
                                (int) $d['pending_count'],
                                'pending',
                                $pending_url
                            );
                            $this->render_action_row(
                                __( 'Processing', 'tejcart' ),
                                (int) $d['processing_count'],
                                'processing',
                                $processing_url
                            );
                            $this->render_action_row(
                                __( 'On hold', 'tejcart' ),
                                (int) $d['on_hold_count'],
                                'on-hold',
                                $on_hold_url
                            );
                            $this->render_action_row(
                                __( 'Failed', 'tejcart' ),
                                (int) $d['failed_count'],
                                'failed',
                                $failed_url
                            );
                            $this->render_action_row(
                                __( 'Out of stock', 'tejcart' ),
                                (int) $d['out_of_stock_count'],
                                'out',
                                $out_stock_url
                            );
                            $this->render_action_row(
                                __( 'Low stock', 'tejcart' ),
                                (int) $d['low_stock_count'],
                                'low',
                                $low_stock_url
                            );
                            ?>
                        </ul>
                    </section>

                    <section class="nxc-card nxc-dashboard-action-card">
                        <header class="nxc-data-card__header">
                            <div>
                                <h3><?php esc_html_e( 'Quick actions', 'tejcart' ); ?></h3>
                            </div>
                        </header>
                        <div class="nxc-quick-grid">
                            <a class="nxc-quick-tile" href="<?php echo esc_url( $d['add_product_url'] ); ?>">
                                <svg class="nxc-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                                <span><?php esc_html_e( 'Add product', 'tejcart' ); ?></span>
                            </a>
                            <a class="nxc-quick-tile" href="<?php echo esc_url( $d['add_order_url'] ); ?>">
                                <svg class="nxc-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7h18M6 7v13a1 1 0 0 0 1 1h10a1 1 0 0 0 1-1V7M9 7V5a3 3 0 0 1 6 0v2"/></svg>
                                <span><?php esc_html_e( 'New order', 'tejcart' ); ?></span>
                            </a>
                            <a class="nxc-quick-tile" href="<?php echo esc_url( $d['add_coupon_url'] ); ?>">
                                <svg class="nxc-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0L3 13V3h10l7.6 7.6a2 2 0 0 1 0 2.8z"/><circle cx="8" cy="8" r="1.5"/></svg>
                                <span><?php esc_html_e( 'Create coupon', 'tejcart' ); ?></span>
                            </a>
                            <a class="nxc-quick-tile" href="<?php echo esc_url( $d['reports_url'] ); ?>">
                                <svg class="nxc-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 3v18h18"/><path d="M7 15l4-4 4 4 5-6"/></svg>
                                <span><?php esc_html_e( 'Reports', 'tejcart' ); ?></span>
                            </a>
                            <a class="nxc-quick-tile" href="<?php echo esc_url( $d['settings_url'] ); ?>">
                                <svg class="nxc-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                    <circle cx="12" cy="12" r="3"/>
                                    <path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3
                                              H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9c.1.6.5 1 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>
                                </svg>
                                <span><?php esc_html_e( 'Settings', 'tejcart' ); ?></span>
                            </a>
                            <a class="nxc-quick-tile" href="<?php echo esc_url( $d['system_url'] ); ?>">
                                <svg class="nxc-icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
                                <span><?php esc_html_e( 'System health', 'tejcart' ); ?></span>
                            </a>
                        </div>
                    </section>
                </aside>
            </div>

            <div class="nxc-data-grid nxc-data-grid--2 nxc-dashboard-secondary">
                <section class="nxc-card nxc-data-card">
                    <header class="nxc-data-card__header">
                        <div>
                            <h3><?php esc_html_e( 'Top products', 'tejcart' ); ?></h3>
                            <p class="nxc-data-card__subtitle"><?php esc_html_e( 'By revenue in the selected range.', 'tejcart' ); ?></p>
                        </div>
                        <a class="nxc-btn nxc-btn--sm" href="<?php echo esc_url( add_query_arg( array( 'page' => 'tejcart-reports', 'tab' => 'products' ), admin_url( 'admin.php' ) ) ); ?>">
                            <?php esc_html_e( 'See all', 'tejcart' ); ?>
                        </a>
                    </header>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Units', 'tejcart' ); ?></th>
                                <th class="nxc-col-right"><?php esc_html_e( 'Revenue', 'tejcart' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( ! empty( $d['top_products'] ) ) :
                            foreach ( $d['top_products'] as $p ) :
                                $name = trim( (string) ( $p->product_name ?? '' ) ) !== '' ? $p->product_name : __( '(Deleted product)', 'tejcart' );
                                ?>
                                <tr>
                                    <td>
                                        <div class="nxc-product-cell">
                                            <span class="nxc-product-name"><?php echo esc_html( $name ); ?></span>
                                            <?php if ( ! empty( $p->sku ) ) : ?>
                                                <span class="nxc-sku"><?php echo esc_html( $p->sku ); ?></span>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                    <td><?php echo esc_html( number_format_i18n( (int) $p->qty ) ); ?></td>
                                    <td class="nxc-col-right"><span class="nxc-price"><?php echo wp_kses_post( tejcart_price( (float) $p->rev ) ); ?></span></td>
                                </tr>
                            <?php endforeach;
                        else : ?>
                            <tr class="no-items"><td class="nxc-data-card__empty" colspan="3"><?php esc_html_e( 'No product sales in this range yet.', 'tejcart' ); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>

                <section class="nxc-card nxc-data-card">
                    <header class="nxc-data-card__header">
                        <div>
                            <h3><?php esc_html_e( 'Low-stock alerts', 'tejcart' ); ?></h3>
                            <p class="nxc-data-card__subtitle">
                                <?php
                                echo esc_html( sprintf(
                                    /* translators: %d: low-stock threshold */
                                    __( 'Products at or below the %d-unit threshold.', 'tejcart' ),
                                    (int) $d['low_stock_threshold']
                                ) );
                                ?>
                            </p>
                        </div>
                        <a class="nxc-btn nxc-btn--sm" href="<?php echo esc_url( $d['products_url'] ); ?>">
                            <?php esc_html_e( 'Products', 'tejcart' ); ?>
                        </a>
                    </header>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th><?php esc_html_e( 'Product', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'SKU', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Stock', 'tejcart' ); ?></th>
                                <th><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if ( ! empty( $d['low_stock_items'] ) ) :
                            foreach ( $d['low_stock_items'] as $item ) :
                                $qty = (int) $item->stock_quantity;
                                $tone = $qty <= 0 ? 'out' : 'low';
                                $lbl  = $qty <= 0 ? __( 'Out of stock', 'tejcart' ) : __( 'Low stock', 'tejcart' );
                                $edit = admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . (int) $item->id );
                                ?>
                                <tr>
                                    <td><a class="nxc-product-name" href="<?php echo esc_url( $edit ); ?>"><?php echo esc_html( $item->name ); ?></a></td>
                                    <td><?php echo $item->sku ? '<span class="nxc-sku">' . esc_html( $item->sku ) . '</span>' : '<span class="nxc-muted">—</span>'; ?></td>
                                    <td><?php echo esc_html( number_format_i18n( $qty ) ); ?></td>
                                    <td>
                                        <span class="nxc-stock nxc-stock--<?php echo esc_attr( $tone ); ?>">
                                            <span class="nxc-stock__dot"></span>
                                            <span class="nxc-stock__label"><?php echo esc_html( $lbl ); ?></span>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach;
                        else : ?>
                            <tr class="no-items"><td class="nxc-data-card__empty" colspan="4"><?php esc_html_e( 'All tracked products are above the low-stock threshold.', 'tejcart' ); ?></td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </section>
            </div>
        </div>
        <?php
    }

    /**
     * Render a single KPI card with a trend badge.
     *
     * @param string $label Human label.
     * @param string $value Pre-formatted value.
     * @param array{label:string,tone:string} $delta Trend badge.
     */
    private function render_dashboard_stat( string $label, string $value, array $delta ): void {
        ?>
        <div class="nxc-stat-card nxc-stat-card--dash">
            <div class="nxc-stat-card__label"><?php echo esc_html( $label ); ?></div>
            <div class="nxc-stat-card__value"><?php echo wp_kses_post( $value ); ?></div>
            <div class="nxc-stat-card__trend nxc-trend nxc-trend--<?php echo esc_attr( $delta['tone'] ); ?>">
                <?php if ( 'up' === $delta['tone'] ) : ?>
                    <svg class="nxc-icon" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="7 17 12 12 17 17"/><polyline points="7 12 12 7 17 12"/></svg>
                <?php elseif ( 'down' === $delta['tone'] ) : ?>
                    <svg class="nxc-icon" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="7 7 12 12 17 7"/><polyline points="7 12 12 17 17 12"/></svg>
                <?php else : ?>
                    <svg class="nxc-icon" viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/></svg>
                <?php endif; ?>
                <span><?php echo esc_html( $delta['label'] ); ?></span>
                <span class="nxc-trend__hint"><?php esc_html_e( 'vs prior period', 'tejcart' ); ?></span>
            </div>
        </div>
        <?php
    }

    /**
     * Render a row in the "Needs attention" action list.
     */
    private function render_action_row( string $label, int $count, string $tone, string $url ): void {
        $zero = ( 0 === $count );
        ?>
        <li class="nxc-action-row <?php echo $zero ? 'is-zero' : ''; ?>">
            <a href="<?php echo esc_url( $url ); ?>">
                <span class="nxc-action-row__label">
                    <span class="nxc-dot nxc-dot--<?php echo esc_attr( $tone ); ?>" aria-hidden="true"></span>
                    <?php echo esc_html( $label ); ?>
                </span>
                <span class="nxc-action-row__count"><?php echo esc_html( number_format_i18n( $count ) ); ?></span>
            </a>
        </li>
        <?php
    }

    /**
     * Render the products list table page or add/edit form.
     *
     * @return void
     */
    public function render_products() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $section = isset( $_GET['section'] ) ? sanitize_text_field( wp_unslash( $_GET['section'] ) ) : '';

        if ( 'attributes' === $section ) {
            ( new \TejCart\Product\Global_Attributes() )->render_page();
            return;
        }

        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action     = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $product_id = isset( $_GET['product_id'] ) ? absint( $_GET['product_id'] ) : 0;

        if ( in_array( $action, array( 'save', 'generate_variations', 'delete', 'duplicate' ), true ) ) {
            $action = 'list';
        }

        if ( 'add' === $action || 'edit' === $action ) {
            ( new Product_Form() )->render( $product_id );
            return;
        }

        $table = new Products_Table();
        $table->prepare_items();

        $pagination  = (array) $table->get_pagination_args();
        $total_items = (int) ( $pagination['total_items'] ?? 0 );
        $add_url     = admin_url( 'admin.php?page=tejcart-products&action=add' );
        $import_url  = admin_url( 'admin.php?page=tejcart-settings&tab=advanced&section=import-export' );
        ?>
        <div class="wrap tejcart-admin-wrap nxc-list nxc-products">
            <header class="nxc-page-header">
                <div class="nxc-page-header__title">
                    <h1><?php esc_html_e( 'Products', 'tejcart' ); ?></h1>
                    <p class="nxc-page-header__subtitle">
                        <?php
                        if ( $total_items > 0 ) {
                            echo esc_html( sprintf(
                                /* translators: %s: total product count */
                                _n( 'Manage your product catalog · %s product', 'Manage your product catalog · %s products', $total_items, 'tejcart' ),
                                number_format_i18n( $total_items )
                            ) );
                        } else {
                            esc_html_e( 'Manage your product catalog.', 'tejcart' );
                        }
                        ?>
                    </p>
                </div>
                <div class="nxc-page-header__actions">
                    <a class="nxc-btn" href="<?php echo esc_url( $import_url ); ?>">
                        <svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg>
                        <?php esc_html_e( 'Import products', 'tejcart' ); ?>
                    </a>
                    <a class="nxc-btn nxc-btn--primary" href="<?php echo esc_url( $add_url ); ?>">
                        <svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <?php esc_html_e( 'Add product', 'tejcart' ); ?>
                    </a>
                </div>
            </header>

            <span class="wp-header-end"></span>

            <?php

            // SEC-027: read flash messages from per-user transient
            // instead of $_GET so they don't leak into URL history.
            $product_notice = get_transient( 'tejcart_admin_notice_' . get_current_user_id() );
            if ( is_array( $product_notice ) && ! empty( $product_notice['message'] ) ) {
                delete_transient( 'tejcart_admin_notice_' . get_current_user_id() );
                $notice_type    = isset( $product_notice['type'] ) ? (string) $product_notice['type'] : 'success';
                $notice_message = (string) $product_notice['message'];
                printf(
                    '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                    esc_attr( $notice_type ),
                    esc_html( $notice_message )
                );
            }
            $bulk_notice = get_transient( 'tejcart_bulk_products_notice_' . get_current_user_id() );
            if ( is_array( $bulk_notice ) && ! empty( $bulk_notice['count'] ) ) {
                delete_transient( 'tejcart_bulk_products_notice_' . get_current_user_id() );
                $action_labels = array(
                    'delete'      => __( 'deleted', 'tejcart' ),
                    'publish'     => __( 'published', 'tejcart' ),
                    'draft'       => __( 'moved to draft', 'tejcart' ),
                    'feature_on'  => __( 'marked as featured', 'tejcart' ),
                    'feature_off' => __( 'cleared featured flag', 'tejcart' ),
                    'instock'     => __( 'set to in stock', 'tejcart' ),
                    'outofstock'  => __( 'set to out of stock', 'tejcart' ),
                    'duplicate'   => __( 'duplicated', 'tejcart' ),
                );
                $label = $action_labels[ $bulk_notice['action'] ] ?? __( 'updated', 'tejcart' );
                echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
                    /* translators: 1: number of products, 2: action label */
                    esc_html( _n( '%1$d product %2$s.', '%1$d products %2$s.', (int) $bulk_notice['count'], 'tejcart' ) ),
                    (int) $bulk_notice['count'],
                    esc_html( $label )
                ) . '</p></div>';
            }
            ?>

            <form method="get" class="nxc-form" id="nxc-products-form">
                <input type="hidden" name="page" value="tejcart-products" />
                <?php

                // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filter forwarding for the products list view.
                if ( isset( $_GET['filter'] ) ) {
                    printf(
                        '<input type="hidden" name="filter" value="%s" />',
                        esc_attr( sanitize_key( wp_unslash( $_GET['filter'] ) ) )
                    );
                }
                // phpcs:enable WordPress.Security.NonceVerification.Recommended
                ?>

                <div class="nxc-toolbar">
                    <?php

                    $table->views();
                    $table->search_box( __( 'Search products', 'tejcart' ), 'nxc-product-search' );
                    ?>
                </div>

                <div class="nxc-card">
                    <?php $table->display(); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle the product delete action.
     *
     * @param int $product_id Product ID to delete.
     * @return void
     */
    private function handle_product_delete( $product_id ) {
        check_admin_referer( 'tejcart_delete_product_' . $product_id );

        if ( ! tejcart_can( \TejCart\Core\Capabilities::DELETE_PRODUCTS ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'tejcart' ) );
        }

        $product = Product_Factory::get_product( $product_id );
        if ( $product ) {
            $product->delete();
        }

        // SEC-027: stash flash message in a per-user transient instead
        // of leaking through the URL.
        set_transient(
            'tejcart_admin_notice_' . get_current_user_id(),
            array( 'type' => 'success', 'message' => __( 'Product deleted.', 'tejcart' ) ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products' ) );
        exit;
    }

    /**
     * Clone an existing product as a new draft, preserving meta, gallery
     * references, variations (for variable parents), bundled-item lists,
     * and cross-product link meta. SKU is cleared — unique-SKU enforcement
     * (Task 9) would otherwise refuse the insert.
     *
     * @param int $product_id Source product ID.
     * @return void
     */
    private function handle_product_duplicate( $product_id ) {
        check_admin_referer( 'tejcart_duplicate_product_' . $product_id );

        if ( ! tejcart_can( \TejCart\Core\Capabilities::EDIT_PRODUCTS ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'tejcart' ) );
        }

        $new_id = Product_Factory::duplicate( (int) $product_id );
        if ( $new_id <= 0 ) {
            wp_safe_redirect( admin_url( 'admin.php?page=tejcart-products&error=duplicate_failed' ) );
            exit;
        }

        wp_safe_redirect(
            admin_url( 'admin.php?page=tejcart-products&action=edit&product_id=' . $new_id . '&duplicated=1' )
        );
        exit;
    }

    /**
     * Render the orders list table page.
     *
     * @return void
     */
    public function render_orders() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : '';

        if ( in_array( $action, array( 'view', 'edit', 'new' ), true ) ) {
            $order_admin = new Order_Admin();
            $order_admin->dispatch( $action );
            return;
        }

        $table = new Orders_Table();
        $table->prepare_items();

        $pagination  = (array) $table->get_pagination_args();
        $total_items = (int) ( $pagination['total_items'] ?? 0 );
        $add_url     = admin_url( 'admin.php?page=tejcart-orders&action=new' );
        ?>
        <div class="wrap tejcart-admin-wrap nxc-list nxc-orders">
            <header class="nxc-page-header">
                <div class="nxc-page-header__title">
                    <h1><?php esc_html_e( 'Orders', 'tejcart' ); ?></h1>
                    <p class="nxc-page-header__subtitle">
                        <?php
                        if ( $total_items > 0 ) {
                            echo esc_html( sprintf(
                                /* translators: %s: total order count */
                                _n( 'Track and manage customer orders · %s order', 'Track and manage customer orders · %s orders', $total_items, 'tejcart' ),
                                number_format_i18n( $total_items )
                            ) );
                        } else {
                            esc_html_e( 'Track and manage customer orders.', 'tejcart' );
                        }
                        ?>
                    </p>
                </div>
                <div class="nxc-page-header__actions">
                    <a class="nxc-btn nxc-btn--primary" href="<?php echo esc_url( $add_url ); ?>">
                        <svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <?php esc_html_e( 'Add order', 'tejcart' ); ?>
                    </a>
                </div>
            </header>

            <span class="wp-header-end"></span>

            <form method="get" class="nxc-form" id="nxc-orders-form">
                <input type="hidden" name="page" value="tejcart-orders" />

                <div class="nxc-toolbar">
                    <?php

                    $table->views();
                    $table->search_box( __( 'Search orders', 'tejcart' ), 'nxc-order-search' );
                    ?>
                </div>

                <div class="nxc-card">
                    <?php $table->display(); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render the customers list page or detail view.
     *
     * @return void
     */
    public function render_customers() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $action = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $customer_id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;

        if ( 'view' === $action && $customer_id > 0 ) {
            $this->render_customer_detail( $customer_id );
            return;
        }

        $this->render_customers_list();
    }

    /**
     * Render the customers list table.
     *
     * @return void
     */
    protected function render_customers_list() {
        $table = new Customers_Table();
        $table->prepare_items();

        $pagination  = (array) $table->get_pagination_args();
        $total_items = (int) ( $pagination['total_items'] ?? 0 );
        ?>
        <div class="wrap tejcart-admin-wrap nxc-list nxc-customers">
            <header class="nxc-page-header">
                <div class="nxc-page-header__title">
                    <h1><?php esc_html_e( 'Customers', 'tejcart' ); ?></h1>
                    <p class="nxc-page-header__subtitle">
                        <?php
                        if ( $total_items > 0 ) {
                            echo esc_html( sprintf(
                                /* translators: %s: total customer count */
                                _n( 'View and manage your customer base · %s customer', 'View and manage your customer base · %s customers', $total_items, 'tejcart' ),
                                number_format_i18n( $total_items )
                            ) );
                        } else {
                            esc_html_e( 'View and manage your customer base.', 'tejcart' );
                        }
                        ?>
                    </p>
                </div>
            </header>

            <span class="wp-header-end"></span>

            <form method="get" class="nxc-form" id="nxc-customers-form">
                <input type="hidden" name="page" value="tejcart-customers" />

                <div class="nxc-toolbar">
                    <?php $table->search_box( __( 'Search customers', 'tejcart' ), 'nxc-customer-search' ); ?>
                </div>

                <div class="nxc-card">
                    <?php $table->display(); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render the customer detail page.
     *
     * @param int $customer_id The customer ID to display.
     * @return void
     */
    protected function render_customer_detail( $customer_id ) {
        global $wpdb;

        $customers_table = $wpdb->prefix . 'tejcart_customers';
        $orders_table    = $wpdb->prefix . 'tejcart_orders';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $customer = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$customers_table} WHERE id = %d", $customer_id ) );

        if ( $customer ) {
            foreach ( array( 'billing_address', 'shipping_address' ) as $field ) {
                // Coalesce once: a SQL NULL column makes the is_string() guard
                // pass on the '' fallback while json_decode() still receives
                // null, which is a fatal TypeError under strict_types. Decode
                // the same value we tested.
                $raw     = $customer->{$field} ?? '';
                $decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;
                if ( ! is_array( $decoded ) ) {
                    continue;
                }
                foreach ( $decoded as $k => $v ) {
                    $prefix = ( 'billing_address' === $field ) ? 'billing_' : 'shipping_';
                    $prop   = $prefix . $k;
                    if ( ! isset( $customer->{$prop} ) ) {
                        $customer->{$prop} = is_scalar( $v ) ? (string) $v : '';
                    }
                }
            }
        }

        if ( ! $customer ) {
            ?>
            <div class="wrap tejcart-admin-wrap">
                <div class="tejcart-page-header">
                    <div class="tejcart-page-header-content">
                        <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-customers' ) ); ?>" class="tejcart-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back to Customers', 'tejcart' ); ?></a>
                        <h1><?php esc_html_e( 'Customer Not Found', 'tejcart' ); ?></h1>
                    </div>
                </div>
                <div class="tejcart-card">
                    <div class="tejcart-empty-state">
                        <span class="dashicons dashicons-businessman"></span>
                        <p><?php esc_html_e( 'The requested customer could not be found.', 'tejcart' ); ?></p>
                    </div>
                </div>
            </div>
            <?php
            return;
        }

        $lookup_user_id = (int) ( $customer->user_id ?? 0 );
        $lookup_email   = (string) $customer->email;

        // Cap the customer order history shown on the detail page. A
        // wholesale buyer with thousands of orders would otherwise OOM
        // the admin request; the totals query below still spans every
        // order so the lifetime-spend figure remains accurate.
        $orders_limit = (int) apply_filters( 'tejcart_admin_customer_orders_limit', 100 );
        if ( $orders_limit <= 0 ) {
            $orders_limit = 100;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        $orders = $wpdb->get_results( $wpdb->prepare(
            "SELECT * FROM {$orders_table} WHERE customer_id = %d OR LOWER(customer_email) = LOWER(%s) ORDER BY created_at DESC LIMIT %d",
            $lookup_user_id,
            $lookup_email,
            $orders_limit
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter
        // Mirror the customers-list query (Menu.php:503-504): COUNT(*) spans
        // every order so the stat reconciles with the order-history table
        // below, but the spend total stays restricted to 'completed' so
        // refunded / failed rows don't inflate lifetime value. MAX(created_at)
        // is unrestricted on purpose — last-activity is more useful to a CS
        // agent than last-completed.
        $shop_currency_local = (string) get_option( 'tejcart_currency', 'USD' );
        $totals = $wpdb->get_row( $wpdb->prepare(
            "SELECT
                COUNT(*) AS order_count,
                COALESCE( SUM( CASE WHEN status = 'completed' THEN total ELSE 0 END ), 0 ) AS total_spent_minor,
                SUM( CASE WHEN status = 'completed' THEN 1 ELSE 0 END ) AS completed_count,
                MAX( created_at ) AS last_order_at
             FROM {$orders_table}
             WHERE customer_id = %d OR LOWER(customer_email) = LOWER(%s)",
            $lookup_user_id,
            $lookup_email
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter

        $order_count     = $totals ? (int) $totals->order_count : 0;
        $completed_count = $totals ? (int) $totals->completed_count : 0;
        $total_spent     = $totals
            ? \TejCart\Money\Currency::from_minor_units( (int) $totals->total_spent_minor, $shop_currency_local )
            : 0.0;
        $last_order_at   = $totals && ! empty( $totals->last_order_at ) ? (string) $totals->last_order_at : '';

        $full_name        = trim( (string) $customer->first_name . ' ' . (string) $customer->last_name );
        $display_name     = '' !== $full_name ? $full_name : (string) $customer->email;
        $registered_iso   = isset( $customer->created_at ) ? gmdate( 'c', (int) strtotime( (string) $customer->created_at ) ) : '';
        $registered_label = isset( $customer->created_at )
            ? date_i18n( get_option( 'date_format' ), strtotime( (string) $customer->created_at ) )
            : '';
        $last_order_label = '' !== $last_order_at
            ? date_i18n( get_option( 'date_format' ), strtotime( $last_order_at ) )
            : '';
        $last_order_iso   = '' !== $last_order_at ? gmdate( 'c', (int) strtotime( $last_order_at ) ) : '';

        $orders_url = admin_url( 'admin.php?page=tejcart-orders&customer_id=' . (int) $customer->id );

        $avatar_email = '' !== (string) $customer->email ? (string) $customer->email : (string) $lookup_user_id;
        $avatar_html  = function_exists( 'get_avatar' )
            ? get_avatar( $avatar_email, 56, '', $display_name, array( 'class' => 'tejcart-customer-avatar-img' ) )
            : '';

        $billing_props  = array( 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode', 'billing_country' );
        $shipping_props = array( 'shipping_address_1', 'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode', 'shipping_country' );

        $has_billing = false;
        foreach ( $billing_props as $bp ) {
            if ( isset( $customer->$bp ) && '' !== (string) $customer->$bp ) {
                $has_billing = true;
                break;
            }
        }

        $has_shipping = false;
        foreach ( $shipping_props as $sp ) {
            if ( isset( $customer->$sp ) && '' !== (string) $customer->$sp ) {
                $has_shipping = true;
                break;
            }
        }

        // Strip the prefix difference so "billing_city=Berlin" compares equal to "shipping_city=Berlin".
        $shipping_matches_billing = $has_billing && $has_shipping
            && ( $this->normalize_address_blob( $customer, $billing_props ) === $this->normalize_address_blob( $customer, $shipping_props ) );

        ?>
        <div class="wrap tejcart-admin-wrap">
            <div class="tejcart-page-header">
                <div class="tejcart-page-header-content">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-customers' ) ); ?>" class="tejcart-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back to Customers', 'tejcart' ); ?></a>
                    <h1>
                        <?php
                        /* translators: %s: customer display name. */
                        printf( esc_html__( 'Customer · %s', 'tejcart' ), esc_html( $display_name ) );
                        ?>
                    </h1>
                    <p class="tejcart-page-subtitle">
                        <?php
                        if ( '' !== $registered_label ) {
                            /* translators: 1: registration date, 2: internal customer id. */
                            printf( esc_html__( 'Joined %1$s · Customer ID #%2$d', 'tejcart' ), esc_html( $registered_label ), (int) $customer->id );
                        } else {
                            /* translators: %d: internal customer id. */
                            printf( esc_html__( 'Customer ID #%d', 'tejcart' ), (int) $customer->id );
                        }
                        ?>
                    </p>
                    <?php
                    /**
                     * Fires in the customer-detail header, after the subtitle.
                     *
                     * Lets extensions render per-customer admin actions
                     * (e.g. a "verify email" button for double opt-in).
                     *
                     * @param int $user_id The customer's WordPress user ID (0 if guest-only).
                     */
                    do_action( 'tejcart_admin_customer_actions', $lookup_user_id );
                    ?>
                    <ul class="tejcart-order-meta-strip tejcart-customer-meta-strip" aria-label="<?php esc_attr_e( 'Customer summary', 'tejcart' ); ?>">
                        <li>
                            <span class="dashicons dashicons-cart" aria-hidden="true"></span>
                            <span class="tejcart-meta-label"><?php esc_html_e( 'Orders', 'tejcart' ); ?></span>
                            <span class="tejcart-order-meta-value"><?php echo esc_html( number_format_i18n( $order_count ) ); ?></span>
                        </li>
                        <li>
                            <span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
                            <span class="tejcart-meta-label"><?php esc_html_e( 'Lifetime spend', 'tejcart' ); ?></span>
                            <span class="tejcart-order-meta-value"><?php echo wp_kses_post( tejcart_price( $total_spent, $shop_currency_local ) ); ?></span>
                            <?php if ( $completed_count !== $order_count ) : ?>
                                <span class="tejcart-meta-hint">
                                    <?php
                                    /* translators: %s: number of completed orders. */
                                    printf( esc_html__( '(from %s completed)', 'tejcart' ), esc_html( number_format_i18n( $completed_count ) ) );
                                    ?>
                                </span>
                            <?php endif; ?>
                        </li>
                        <li>
                            <span class="dashicons dashicons-clock" aria-hidden="true"></span>
                            <span class="tejcart-meta-label"><?php esc_html_e( 'Last order', 'tejcart' ); ?></span>
                            <?php if ( '' !== $last_order_label ) : ?>
                                <time class="tejcart-order-meta-value" datetime="<?php echo esc_attr( $last_order_iso ); ?>"><?php echo esc_html( $last_order_label ); ?></time>
                            <?php else : ?>
                                <span class="tejcart-order-meta-value">—</span>
                            <?php endif; ?>
                        </li>
                        <?php if ( '' !== $registered_label ) : ?>
                            <li>
                                <span class="dashicons dashicons-id" aria-hidden="true"></span>
                                <span class="tejcart-meta-label"><?php esc_html_e( 'Registered', 'tejcart' ); ?></span>
                                <time class="tejcart-order-meta-value" datetime="<?php echo esc_attr( $registered_iso ); ?>"><?php echo esc_html( $registered_label ); ?></time>
                            </li>
                        <?php endif; ?>
                    </ul>
                </div>
                <div class="tejcart-page-header-actions">
                    <?php if ( '' !== (string) $customer->email ) : ?>
                        <a href="mailto:<?php echo esc_attr( $customer->email ); ?>" class="button"><span class="dashicons dashicons-email-alt"></span> <?php esc_html_e( 'Email customer', 'tejcart' ); ?></a>
                    <?php endif; ?>
                    <?php if ( $order_count > 0 ) : ?>
                        <a href="<?php echo esc_url( $orders_url ); ?>" class="button"><span class="dashicons dashicons-list-view"></span> <?php esc_html_e( 'View orders', 'tejcart' ); ?></a>
                    <?php endif; ?>
                </div>
            </div>

            <div class="tejcart-customer-header tejcart-card">
                <div class="tejcart-card-body">
                    <div class="tejcart-customer-profile">
                        <div class="tejcart-customer-avatar">
                            <?php
                            if ( '' !== $avatar_html ) {
                                echo wp_kses_post( $avatar_html );
                            } else {
                                echo '<span class="dashicons dashicons-businessman" aria-hidden="true"></span>';
                            }
                            ?>
                        </div>
                        <div class="tejcart-customer-info">
                            <h2><?php echo esc_html( $display_name ); ?></h2>
                            <?php if ( '' !== (string) $customer->email ) : ?>
                                <p><a href="mailto:<?php echo esc_attr( $customer->email ); ?>"><?php echo esc_html( $customer->email ); ?></a></p>
                            <?php endif; ?>
                            <?php if ( $lookup_user_id > 0 ) : ?>
                                <p class="tejcart-customer-info-meta">
                                    <a href="<?php echo esc_url( admin_url( 'user-edit.php?user_id=' . $lookup_user_id ) ); ?>">
                                        <span class="dashicons dashicons-admin-users" aria-hidden="true"></span> <?php esc_html_e( 'WordPress user profile', 'tejcart' ); ?>
                                    </a>
                                </p>
                            <?php else : ?>
                                <p class="tejcart-customer-info-meta tejcart-customer-info-meta--muted">
                                    <span class="dashicons dashicons-businessperson" aria-hidden="true"></span> <?php esc_html_e( 'Guest customer', 'tejcart' ); ?>
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Addresses -->
            <div class="tejcart-address-grid">
                <div class="tejcart-card">
                    <div class="tejcart-card-header"><h3><?php esc_html_e( 'Billing address', 'tejcart' ); ?></h3></div>
                    <div class="tejcart-card-body">
                        <?php if ( $has_billing ) : ?>
                            <address>
                                <?php echo wp_kses_post( $this->format_customer_address( $customer, 'billing' ) ); ?>
                            </address>
                        <?php else : ?>
                            <div class="tejcart-empty-state tejcart-empty-state--inline">
                                <span class="dashicons dashicons-location-alt" aria-hidden="true"></span>
                                <p><?php esc_html_e( 'No billing address on file.', 'tejcart' ); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="tejcart-card">
                    <div class="tejcart-card-header">
                        <h3><?php esc_html_e( 'Shipping address', 'tejcart' ); ?></h3>
                        <?php if ( $shipping_matches_billing ) : ?>
                            <span class="tejcart-pill tejcart-pill--muted"><?php esc_html_e( 'Same as billing', 'tejcart' ); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="tejcart-card-body">
                        <?php if ( $has_shipping ) : ?>
                            <address>
                                <?php echo wp_kses_post( $this->format_customer_address( $customer, 'shipping' ) ); ?>
                            </address>
                        <?php else : ?>
                            <div class="tejcart-empty-state tejcart-empty-state--inline">
                                <span class="dashicons dashicons-location-alt" aria-hidden="true"></span>
                                <p><?php esc_html_e( 'No shipping address on file.', 'tejcart' ); ?></p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Order History -->
            <div class="tejcart-card tejcart-section-gap">
                <div class="tejcart-card-header">
                    <h3><?php esc_html_e( 'Order history', 'tejcart' ); ?></h3>
                    <?php if ( $order_count > $orders_limit && count( $orders ) >= $orders_limit ) : ?>
                        <span class="tejcart-card-header-hint">
                            <?php
                            /* translators: 1: number of orders shown, 2: total order count. */
                            printf( esc_html__( 'Showing the most recent %1$s of %2$s orders.', 'tejcart' ), esc_html( number_format_i18n( $orders_limit ) ), esc_html( number_format_i18n( $order_count ) ) );
                            ?>
                        </span>
                    <?php endif; ?>
                </div>
                <div class="tejcart-card-body tejcart-card-body--table">
                    <table class="wp-list-table widefat striped tejcart-customer-orders-table">
                        <thead>
                            <tr>
                                <th scope="col"><?php esc_html_e( 'Order #', 'tejcart' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Date', 'tejcart' ); ?></th>
                                <th scope="col"><?php esc_html_e( 'Status', 'tejcart' ); ?></th>
                                <th scope="col" class="tejcart-col-num"><?php esc_html_e( 'Total', 'tejcart' ); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if ( $orders ) : ?>
                                <?php foreach ( $orders as $order ) :
                                    $order_status   = (string) ( $order->status ?? '' );
                                    $order_currency = (string) ( $order->currency ?? $shop_currency_local );
                                    $order_total    = \TejCart\Money\Currency::from_minor_units( (int) $order->total, $order_currency );
                                    $order_date_iso = ! empty( $order->created_at ) ? gmdate( 'c', (int) strtotime( (string) $order->created_at ) ) : '';
                                    $order_date_lbl = ! empty( $order->created_at ) ? date_i18n( get_option( 'date_format' ), strtotime( (string) $order->created_at ) ) : '—';
                                ?>
                                    <tr>
                                        <td>
                                            <a class="tejcart-customer-order-link" href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-orders&action=view&id=' . (int) $order->id ) ); ?>">
                                                #<?php echo esc_html( (string) $order->id ); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <?php if ( '' !== $order_date_iso ) : ?>
                                                <time datetime="<?php echo esc_attr( $order_date_iso ); ?>"><?php echo esc_html( $order_date_lbl ); ?></time>
                                            <?php else : ?>
                                                <span>—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ( '' !== $order_status ) : ?>
                                                <span class="tejcart-status-badge tejcart-status-<?php echo esc_attr( $order_status ); ?>">
                                                    <?php echo esc_html( ucfirst( str_replace( '_', ' ', $order_status ) ) ); ?>
                                                </span>
                                            <?php else : ?>
                                                <span>—</span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="tejcart-col-num"><?php echo wp_kses_post( tejcart_price( $order_total, $order_currency ) ); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="tejcart-empty-state tejcart-empty-state--inline">
                                            <span class="dashicons dashicons-cart" aria-hidden="true"></span>
                                            <p><?php esc_html_e( 'No orders found for this customer.', 'tejcart' ); ?></p>
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Build a normalised, comparable representation of a customer address
     * (lowercased, prefix-stripped, joined). Used to detect when the
     * shipping address mirrors the billing address so the UI can collapse
     * the duplicate into a "Same as billing" pill.
     *
     * @param object   $customer Customer row from `wp_tejcart_customers`.
     * @param string[] $props    Address property names (prefix-included).
     */
    private function normalize_address_blob( $customer, array $props ): string {
        $parts = array();
        foreach ( $props as $prop ) {
            $value = isset( $customer->$prop ) ? (string) $customer->$prop : '';
            // Drop the prefix so "billing_city" and "shipping_city" align on the same key.
            $key      = preg_replace( '/^(billing|shipping)_/', '', $prop );
            $parts[ (string) $key ] = strtolower( trim( $value ) );
        }
        ksort( $parts );
        return implode( '|', array_map(
            static fn( $k, $v ): string => $k . '=' . $v,
            array_keys( $parts ),
            array_values( $parts )
        ) );
    }

    /**
     * Render a customer's billing or shipping address as escaped HTML
     * lines suitable for an `<address>` block.
     *
     * @param object $customer Customer row from `wp_tejcart_customers`.
     * @param string $kind     Either 'billing' or 'shipping'.
     */
    private function format_customer_address( $customer, string $kind ): string {
        $prefix = ( 'shipping' === $kind ) ? 'shipping_' : 'billing_';
        $lines  = array();

        $addr1 = (string) ( $customer->{$prefix . 'address_1'} ?? '' );
        if ( '' !== $addr1 ) {
            $lines[] = esc_html( $addr1 );
        }
        $addr2 = (string) ( $customer->{$prefix . 'address_2'} ?? '' );
        if ( '' !== $addr2 ) {
            $lines[] = esc_html( $addr2 );
        }
        $city_state_zip = array_filter( array(
            (string) ( $customer->{$prefix . 'city'} ?? '' ),
            (string) ( $customer->{$prefix . 'state'} ?? '' ),
            (string) ( $customer->{$prefix . 'postcode'} ?? '' ),
        ), static fn( $v ): bool => '' !== $v );
        if ( $city_state_zip ) {
            $lines[] = esc_html( implode( ', ', $city_state_zip ) );
        }
        $country = (string) ( $customer->{$prefix . 'country'} ?? '' );
        if ( '' !== $country ) {
            $lines[] = esc_html( $country );
        }

        return implode( '<br>', $lines );
    }

    /**
     * Render the coupons management page.
     *
     * Handles list view, add/edit form, save, and delete actions.
     *
     * @return void
     */
    public function render_coupons() {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action routing only.
        $action    = isset( $_GET['action'] ) ? sanitize_text_field( wp_unslash( $_GET['action'] ) ) : 'list';
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended
        $coupon_id = isset( $_GET['coupon_id'] ) ? absint( $_GET['coupon_id'] ) : 0;

        if ( in_array( $action, array( 'save', 'delete', 'export_csv', 'activate', 'deactivate' ), true ) ) {
            $action = 'list';
        }

        if ( 'add' === $action || 'edit' === $action ) {
            $this->render_coupon_form( $coupon_id );
            return;
        }

        $this->render_coupons_list();
    }

    /**
     * Render the coupons list table.
     *
     * Mirrors render_products() / render_orders() — native WP_List_Table
     * drives search, sort, pagination and bulk actions; only the SaaS
     * chrome (.nxc-list) is custom. Coupons_Table supplies the columns,
     * status views and empty state.
     *
     * @return void
     */
    private function render_coupons_list() {
        $table = new Coupons_Table();
        $table->prepare_items();

        $pagination  = (array) $table->get_pagination_args();
        $total_items = (int) ( $pagination['total_items'] ?? 0 );
        $add_url     = admin_url( 'admin.php?page=tejcart-coupons&action=add' );
        ?>
        <div class="wrap tejcart-admin-wrap nxc-list nxc-coupons">
            <header class="nxc-page-header">
                <div class="nxc-page-header__title">
                    <h1><?php esc_html_e( 'Coupons', 'tejcart' ); ?></h1>
                    <p class="nxc-page-header__subtitle">
                        <?php
                        if ( $total_items > 0 ) {
                            echo esc_html( sprintf(
                                /* translators: %s: total coupon count */
                                _n( 'Create and manage discount codes · %s coupon', 'Create and manage discount codes · %s coupons', $total_items, 'tejcart' ),
                                number_format_i18n( $total_items )
                            ) );
                        } else {
                            esc_html_e( 'Create and manage discount codes.', 'tejcart' );
                        }
                        ?>
                    </p>
                </div>
                <div class="nxc-page-header__actions">
                    <a class="nxc-btn nxc-btn--primary" href="<?php echo esc_url( $add_url ); ?>">
                        <svg class="nxc-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14M5 12h14"/></svg>
                        <?php esc_html_e( 'Add coupon', 'tejcart' ); ?>
                    </a>
                </div>
            </header>

            <span class="wp-header-end"></span>

            <?php

            // SEC-027: read flash messages from per-user transient
            // instead of $_GET so they don't leak into URL history.
            $coupon_notice = get_transient( 'tejcart_admin_notice_' . get_current_user_id() );
            if ( is_array( $coupon_notice ) && ! empty( $coupon_notice['message'] ) ) {
                delete_transient( 'tejcart_admin_notice_' . get_current_user_id() );
                $notice_type    = isset( $coupon_notice['type'] ) ? (string) $coupon_notice['type'] : 'success';
                $notice_message = (string) $coupon_notice['message'];
                printf(
                    '<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
                    esc_attr( $notice_type ),
                    esc_html( $notice_message )
                );
            }
            $bulk_notice = get_transient( 'tejcart_bulk_coupons_notice_' . get_current_user_id() );
            if ( is_array( $bulk_notice ) && ! empty( $bulk_notice['count'] ) ) {
                delete_transient( 'tejcart_bulk_coupons_notice_' . get_current_user_id() );
                $action_labels = array(
                    'delete'     => __( 'deleted', 'tejcart' ),
                    'activate'   => __( 'marked active', 'tejcart' ),
                    'deactivate' => __( 'marked inactive', 'tejcart' ),
                );
                $label = $action_labels[ $bulk_notice['action'] ] ?? __( 'updated', 'tejcart' );
                echo '<div class="notice notice-success is-dismissible"><p>' . sprintf(
                    /* translators: 1: number of coupons, 2: action label */
                    esc_html( _n( '%1$d coupon %2$s.', '%1$d coupons %2$s.', (int) $bulk_notice['count'], 'tejcart' ) ),
                    (int) $bulk_notice['count'],
                    esc_html( $label )
                ) . '</p></div>';
            }
            ?>

            <form method="get" class="nxc-form" id="nxc-coupons-form">
                <input type="hidden" name="page" value="tejcart-coupons" />
                <?php

                // phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only status-filter forwarding for the coupons list view.
                if ( isset( $_GET['coupon_status'] ) ) {
                    printf(
                        '<input type="hidden" name="coupon_status" value="%s" />',
                        esc_attr( sanitize_key( wp_unslash( $_GET['coupon_status'] ) ) )
                    );
                }
                // phpcs:enable WordPress.Security.NonceVerification.Recommended
                ?>

                <div class="nxc-toolbar">
                    <?php
                    $table->views();
                    $table->search_box( __( 'Search coupons', 'tejcart' ), 'nxc-coupon-search' );
                    ?>
                </div>

                <div class="nxc-card">
                    <?php $table->display(); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Render the add/edit coupon form.
     *
     * @param int $coupon_id Coupon ID to edit, or 0 for new.
     * @return void
     */
    private function render_coupon_form( $coupon_id ) {
        $coupon  = $coupon_id ? new Coupon( $coupon_id ) : new Coupon();
        $is_edit = (bool) $coupon->get_id();
        $title   = $is_edit
            ? __( 'Edit Coupon', 'tejcart' )
            : __( 'Add Coupon', 'tejcart' );
        ?>
        <div class="wrap tejcart-admin-wrap nxc-list nxc-coupons">
            <header class="nxc-page-header">
                <div class="nxc-page-header__title">
                    <a href="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-coupons' ) ); ?>" class="tejcart-back-link"><span class="dashicons dashicons-arrow-left-alt2"></span> <?php esc_html_e( 'Back to Coupons', 'tejcart' ); ?></a>
                    <h1><?php echo esc_html( $title ); ?></h1>
                    <p class="nxc-page-header__subtitle">
                        <?php
                        echo esc_html(
                            $is_edit
                                ? __( 'Update this discount code and its restrictions.', 'tejcart' )
                                : __( 'Create a new discount code customers can redeem at checkout.', 'tejcart' )
                        );
                        ?>
                    </p>
                </div>
            </header>

            <span class="wp-header-end"></span>

            <?php
            // phpcs:ignore WordPress.Security.NonceVerification.Recommended
            $save_error = isset( $_GET['error'] ) ? sanitize_key( wp_unslash( $_GET['error'] ) ) : '';
            if ( '' !== $save_error ) {
                $error_messages = array(
                    'duplicate_code' => __( 'A coupon with that code already exists. Choose a different code.', 'tejcart' ),
                    'empty_code'     => __( 'Coupon code is required.', 'tejcart' ),
                    'save_failed'    => __( 'Coupon could not be saved. Please try again.', 'tejcart' ),
                );
                if ( isset( $error_messages[ $save_error ] ) ) {
                    echo '<div class="notice notice-error is-dismissible"><p>' . esc_html( $error_messages[ $save_error ] ) . '</p></div>';
                }
            }
            ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin.php?page=tejcart-coupons&action=save' ) ); ?>">
                <?php wp_nonce_field( 'tejcart_save_coupon', 'tejcart_coupon_nonce' ); ?>

                <?php if ( $is_edit ) : ?>
                    <input type="hidden" name="coupon_id" value="<?php echo esc_attr( $coupon->get_id() ); ?>" />
                <?php endif; ?>

                <!-- SECTION: Coupon Code -->
                <div class="tejcart-form-section">
                    <div class="tejcart-form-section-header">
                        <h2><span class="dashicons dashicons-tag"></span> <?php esc_html_e( 'Coupon Code', 'tejcart' ); ?></h2>
                        <p><?php esc_html_e( 'The code customers enter at checkout.', 'tejcart' ); ?></p>
                    </div>
                    <div class="tejcart-form-section-body">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="coupon_code"><?php esc_html_e( 'Code', 'tejcart' ); ?><span class="tejcart-required" aria-hidden="true">*</span></label></th>
                                <td>
                                    <input type="text" id="coupon_code" name="coupon_code" class="regular-text" value="<?php echo esc_attr( $coupon->get_code() ); ?>" required placeholder="<?php esc_attr_e( 'e.g. SUMMER25', 'tejcart' ); ?>" />
                                    <button type="button" class="button" onclick="document.getElementById('coupon_code').value = 'TEJCART-' + Math.random().toString(36).substring(2, 8).toUpperCase();">
                                        <?php esc_html_e( 'Generate Code', 'tejcart' ); ?>
                                    </button>
                                    <p class="description"><?php esc_html_e( 'Codes are not case-sensitive. Letters, numbers, dashes and underscores are recommended.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="coupon_status"><?php esc_html_e( 'Status', 'tejcart' ); ?></label></th>
                                <td>
                                    <select id="coupon_status" name="coupon_status">
                                        <option value="active" <?php selected( $coupon->get_status(), 'active' ); ?>><?php esc_html_e( 'Active — customers can use this coupon', 'tejcart' ); ?></option>
                                        <option value="inactive" <?php selected( $coupon->get_status(), 'inactive' ); ?>><?php esc_html_e( 'Inactive — coupon is disabled', 'tejcart' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- SECTION: Discount -->
                <div class="tejcart-form-section">
                    <div class="tejcart-form-section-header">
                        <h2><span class="dashicons dashicons-money-alt"></span> <?php esc_html_e( 'Discount', 'tejcart' ); ?></h2>
                        <p><?php esc_html_e( 'Choose the type of discount and its value.', 'tejcart' ); ?></p>
                    </div>
                    <div class="tejcart-form-section-body">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="coupon_type"><?php esc_html_e( 'Type', 'tejcart' ); ?></label></th>
                                <td>
                                    <select id="coupon_type" name="coupon_type" onchange="document.getElementById('amount_row').style.display = this.value === 'free_shipping' ? 'none' : '';">
                                        <option value="percentage" <?php selected( $coupon->get_type(), 'percentage' ); ?>><?php esc_html_e( 'Percentage — % off the cart subtotal', 'tejcart' ); ?></option>
                                        <option value="fixed" <?php selected( $coupon->get_type(), 'fixed' ); ?>><?php esc_html_e( 'Fixed — flat amount off', 'tejcart' ); ?></option>
                                        <option value="free_shipping" <?php selected( $coupon->get_type(), 'free_shipping' ); ?>><?php esc_html_e( 'Free Shipping — waives shipping cost', 'tejcart' ); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="amount_row" <?php echo 'free_shipping' === $coupon->get_type() ? 'style="display:none;"' : ''; ?>>
                                <th scope="row"><label for="coupon_amount"><?php esc_html_e( 'Amount', 'tejcart' ); ?></label></th>
                                <td>
                                    <input type="number" id="coupon_amount" name="coupon_amount" class="regular-text" value="<?php echo esc_attr( $coupon->get_amount() ); ?>" step="0.01" min="0" placeholder="0" />
                                    <p class="description"><?php esc_html_e( 'For percentage coupons, use a value between 0 and 100.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- SECTION: Usage Restrictions -->
                <div class="tejcart-form-section">
                    <div class="tejcart-form-section-header">
                        <h2><span class="dashicons dashicons-admin-settings"></span> <?php esc_html_e( 'Usage Restrictions', 'tejcart' ); ?></h2>
                        <p><?php esc_html_e( 'Limit how many times this coupon can be redeemed and by whom.', 'tejcart' ); ?></p>
                    </div>
                    <div class="tejcart-form-section-body">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><label for="usage_limit"><?php esc_html_e( 'Total Usage Limit', 'tejcart' ); ?></label></th>
                                <td>
                                    <input type="number" id="usage_limit" name="usage_limit" class="regular-text" value="<?php echo esc_attr( null !== $coupon->get_usage_limit() ? $coupon->get_usage_limit() : '' ); ?>" min="0" step="1" placeholder="<?php esc_attr_e( 'Unlimited', 'tejcart' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Maximum number of times this coupon can be used across all customers. Leave blank for unlimited.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="usage_limit_per_user"><?php esc_html_e( 'Per-Customer Limit', 'tejcart' ); ?></label></th>
                                <td>
                                    <input type="number" id="usage_limit_per_user" name="usage_limit_per_user" class="regular-text" value="<?php echo esc_attr( null !== $coupon->get_usage_limit_per_user() ? $coupon->get_usage_limit_per_user() : '' ); ?>" min="0" step="1" placeholder="<?php esc_attr_e( 'Unlimited', 'tejcart' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Maximum number of times a single customer can use this coupon.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="minimum_amount"><?php esc_html_e( 'Minimum Order Total', 'tejcart' ); ?></label></th>
                                <td>
                                    <input type="number" id="minimum_amount" name="minimum_amount" class="regular-text" value="<?php echo esc_attr( null !== $coupon->get_minimum_amount() ? $coupon->get_minimum_amount() : '' ); ?>" step="0.01" min="0" placeholder="<?php esc_attr_e( 'No minimum', 'tejcart' ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="maximum_amount"><?php esc_html_e( 'Maximum Order Total', 'tejcart' ); ?></label></th>
                                <td>
                                    <input type="number" id="maximum_amount" name="maximum_amount" class="regular-text" value="<?php echo esc_attr( null !== $coupon->get_maximum_amount() ? $coupon->get_maximum_amount() : '' ); ?>" step="0.01" min="0" placeholder="<?php esc_attr_e( 'No maximum', 'tejcart' ); ?>" />
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="expires_at"><?php esc_html_e( 'Expiry Date', 'tejcart' ); ?></label></th>
                                <td>
                                    <input type="date" id="expires_at" name="expires_at" value="<?php echo esc_attr( $coupon->get_expires_at() ? gmdate( 'Y-m-d', strtotime( $coupon->get_expires_at() ) ) : '' ); ?>" />
                                    <p class="description"><?php esc_html_e( 'Leave blank for no expiration.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Individual Use', 'tejcart' ); ?></th>
                                <td>
                                    <label class="tejcart-toggle">
                                        <input type="checkbox" id="coupon_individual_use" name="coupon_individual_use" value="1" <?php checked( $coupon->is_individual_use() ); ?> />
                                        <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                        <span class="tejcart-toggle-label"><?php esc_html_e( 'Cannot be combined with any other coupon', 'tejcart' ); ?></span>
                                    </label>
                                    <p class="description"><?php esc_html_e( 'Applies only to newly-applied coupons. Carts that already have this coupon stacked with others keep working until the customer removes a coupon.', 'tejcart' ); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Exclude Sale Items', 'tejcart' ); ?></th>
                                <td>
                                    <label class="tejcart-toggle">
                                        <input type="checkbox" id="coupon_exclude_sale_items" name="coupon_exclude_sale_items" value="1" <?php checked( $coupon->excludes_sale_items() ); ?> />
                                        <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                        <span class="tejcart-toggle-label"><?php esc_html_e( 'Do not apply this coupon to items already on sale', 'tejcart' ); ?></span>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <!-- SECTION: Auto Apply -->
                <div class="tejcart-form-section">
                    <div class="tejcart-form-section-header">
                        <h2><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Automatic Application', 'tejcart' ); ?></h2>
                        <p><?php esc_html_e( 'Apply this coupon automatically without requiring a code.', 'tejcart' ); ?></p>
                    </div>
                    <div class="tejcart-form-section-body">
                        <table class="form-table" role="presentation">
                            <tr>
                                <th scope="row"><?php esc_html_e( 'Auto Apply', 'tejcart' ); ?></th>
                                <td>
                                    <?php
                                    $auto_codes  = (array) get_option( 'tejcart_auto_apply_coupons', array() );
                                    $is_auto_set = in_array( strtolower( (string) $coupon->get_code() ), array_map( 'strtolower', $auto_codes ), true );
                                    ?>
                                    <label class="tejcart-toggle">
                                        <input type="checkbox" id="coupon_auto_apply" name="coupon_auto_apply" value="1" <?php checked( $is_auto_set ); ?> />
                                        <span class="tejcart-toggle-track"><span class="tejcart-toggle-knob"></span></span>
                                        <span class="tejcart-toggle-label"><?php esc_html_e( 'Automatically apply this coupon to every eligible cart', 'tejcart' ); ?></span>
                                    </label>
                                </td>
                            </tr>
                        </table>
                    </div>
                </div>

                <div class="tejcart-form-footer">
                    <?php submit_button( $is_edit ? __( 'Update Coupon', 'tejcart' ) : __( 'Add Coupon', 'tejcart' ), 'primary', 'submit', false ); ?>
                </div>
            </form>
        </div>
        <?php
    }

    /**
     * Handle the coupon save (add/update) POST request.
     *
     * @return void
     */
    private function handle_coupon_save() {
        if ( ! isset( $_POST['tejcart_coupon_nonce'] )
             || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['tejcart_coupon_nonce'] ) ), 'tejcart_save_coupon' )
        ) {
            wp_die( esc_html__( 'Security check failed.', 'tejcart' ) );
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'tejcart' ) );
        }

        $coupon_id = isset( $_POST['coupon_id'] ) ? absint( $_POST['coupon_id'] ) : 0;
        $coupon    = $coupon_id ? new Coupon( $coupon_id ) : new Coupon();

        $submitted_code = isset( $_POST['coupon_code'] )
            ? strtolower( trim( sanitize_text_field( wp_unslash( $_POST['coupon_code'] ) ) ) )
            : '';

        if ( '' === $submitted_code ) {
            $redirect = $coupon_id
                ? admin_url( 'admin.php?page=tejcart-coupons&action=edit&coupon_id=' . $coupon_id . '&error=empty_code' )
                : admin_url( 'admin.php?page=tejcart-coupons&action=new&error=empty_code' );
            wp_safe_redirect( $redirect );
            exit;
        }

        global $wpdb;
        $coupons_table = $wpdb->prefix . 'tejcart_coupons';
        // $coupons_table is composed from $wpdb->prefix and a constant suffix.
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $existing_id = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare(
                "SELECT id FROM {$coupons_table} WHERE code = %s LIMIT 1",
                $submitted_code
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        if ( $existing_id && $existing_id !== $coupon_id ) {
            $redirect = $coupon_id
                ? admin_url( 'admin.php?page=tejcart-coupons&action=edit&coupon_id=' . $coupon_id . '&error=duplicate_code' )
                : admin_url( 'admin.php?page=tejcart-coupons&action=new&error=duplicate_code' );
            wp_safe_redirect( $redirect );
            exit;
        }

        $coupon->set_code( $submitted_code );
        $coupon->set_type( isset( $_POST['coupon_type'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_type'] ) ) : 'fixed' );
        $coupon->set_amount( isset( $_POST['coupon_amount'] ) ? floatval( wp_unslash( $_POST['coupon_amount'] ) ) : 0.0 );
        $coupon->set_usage_limit( isset( $_POST['usage_limit'] ) && '' !== $_POST['usage_limit'] ? absint( wp_unslash( $_POST['usage_limit'] ) ) : null );
        $coupon->set_usage_limit_per_user( isset( $_POST['usage_limit_per_user'] ) && '' !== $_POST['usage_limit_per_user'] ? absint( wp_unslash( $_POST['usage_limit_per_user'] ) ) : null );
        $coupon->set_minimum_amount( isset( $_POST['minimum_amount'] ) && '' !== $_POST['minimum_amount'] ? floatval( wp_unslash( $_POST['minimum_amount'] ) ) : null );
        $coupon->set_maximum_amount( isset( $_POST['maximum_amount'] ) && '' !== $_POST['maximum_amount'] ? floatval( wp_unslash( $_POST['maximum_amount'] ) ) : null );
        $coupon->set_status( isset( $_POST['coupon_status'] ) ? sanitize_text_field( wp_unslash( $_POST['coupon_status'] ) ) : 'active' );
        $coupon->set_individual_use( ! empty( $_POST['coupon_individual_use'] ) );
        $coupon->set_exclude_sale_items( ! empty( $_POST['coupon_exclude_sale_items'] ) );

        $expires = isset( $_POST['expires_at'] ) ? sanitize_text_field( wp_unslash( $_POST['expires_at'] ) ) : '';
        $coupon->set_expires_at( ! empty( $expires ) ? $expires . ' 23:59:59' : null );

        if ( ! $coupon->save() ) {
            $redirect = $coupon_id
                ? admin_url( 'admin.php?page=tejcart-coupons&action=edit&coupon_id=' . $coupon_id . '&error=save_failed' )
                : admin_url( 'admin.php?page=tejcart-coupons&action=new&error=save_failed' );
            wp_safe_redirect( $redirect );
            exit;
        }

        $auto_codes = array_map( 'strtolower', (array) get_option( 'tejcart_auto_apply_coupons', array() ) );
        $code_lc    = strtolower( (string) $coupon->get_code() );
        $auto_codes = array_values( array_diff( $auto_codes, array( $code_lc ) ) );
        if ( ! empty( $_POST['coupon_auto_apply'] ) && '' !== $code_lc ) {
            $auto_codes[] = $code_lc;
        }
        update_option( 'tejcart_auto_apply_coupons', array_values( array_unique( $auto_codes ) ) );

        // SEC-027: stash flash message in a per-user transient instead
        // of leaking through the URL.
        set_transient(
            'tejcart_admin_notice_' . get_current_user_id(),
            array( 'type' => 'success', 'message' => __( 'Coupon saved.', 'tejcart' ) ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-coupons' ) );
        exit;
    }

    /**
     * Handle the coupon delete action.
     *
     * @param int $coupon_id Coupon ID to delete.
     * @return void
     */
    private function handle_coupon_delete( $coupon_id ) {
        check_admin_referer( 'tejcart_delete_coupon_' . $coupon_id );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'You do not have permission to perform this action.', 'tejcart' ) );
        }

        $coupon = new Coupon( $coupon_id );
        $coupon->delete();

        // SEC-027: stash flash message in a per-user transient instead
        // of leaking through the URL.
        set_transient(
            'tejcart_admin_notice_' . get_current_user_id(),
            array( 'type' => 'success', 'message' => __( 'Coupon deleted.', 'tejcart' ) ),
            MINUTE_IN_SECONDS
        );
        wp_safe_redirect( admin_url( 'admin.php?page=tejcart-coupons' ) );
        exit;
    }

    /**
     * Render the settings page.
     *
     * @return void
     */
    public function render_settings() {
        /**
         * Fires to render the settings page.
         * Hooked by Settings_Page::render().
         */
        do_action( 'tejcart_settings_page' );
    }

    /**
     * Render the Payment Method Settings page for a specific gateway.
     *
     * @return void
     */
    public function render_payment_method_settings() {
        $settings = new Payment_Method_Settings();
        $settings->render();
    }

    /**
     * Render the unified PayPal Manage page.
     *
     * @return void
     */
    public function render_paypal_manage() {
        $page = new PayPal_Manage_Page();
        $page->render();
    }

    /**
     * Hide the PayPal Manage submenu entry from the sidebar.
     *
     * Same rationale as hide_payment_method_settings_submenu().
     *
     * @return void
     */
    public function hide_paypal_manage_submenu(): void {
        remove_submenu_page( 'tejcart', PayPal_Manage_Page::PAGE_SLUG );
    }

    /**
     * Hide the Payment Method Settings submenu entry from the sidebar.
     *
     * Runs on `admin_head`, which fires after WordPress's capability check
     * (`user_can_access_admin_page()`) has already passed but before the menu
     * HTML is rendered by `menu-header.php`. Calling `remove_submenu_page()`
     * during `admin_menu` would strip the entry from the `$submenu` global
     * before the access check runs, which would cause direct URLs such as
     * `admin.php?page=tejcart-payment-method-settings` to incorrectly return
     * "Sorry, you are not allowed to access this page.".
     *
     * @return void
     */
    public function hide_payment_method_settings_submenu(): void {
        remove_submenu_page( 'tejcart', 'tejcart-payment-method-settings' );
    }
}
