<?php
/**
 * Low Stock Alert handler.
 *
 * Monitors product stock levels after orders are saved and fires
 * low-stock / out-of-stock actions when thresholds are reached.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Checks stock levels after an order is saved and sends alerts.
 */
class Low_Stock_Alert {
    /**
     * Product IDs that crossed the low-stock threshold during the current
     * `check_stock()` invocation. Flushed as a single digest email so a
     * multi-item order doesn't blast the admin with one mail per line.
     *
     * @var array<int, array{id:int, stock:int}>
     */
    private $pending_low = array();

    /**
     * Product IDs that went out of stock during the current
     * `check_stock()` invocation.
     *
     * @var int[]
     */
    private $pending_out = array();

    /**
     * Register hooks.
     *
     * @return void
     */
    public function init() {
        add_action( 'tejcart_after_order_object_save', array( $this, 'check_stock' ) );

        add_action( 'tejcart_product_low_stock', array( $this, 'queue_low_stock' ) );
        add_action( 'tejcart_product_out_of_stock', array( $this, 'queue_out_of_stock' ) );
        add_action( 'tejcart_product_out_of_stock', array( $this, 'update_stock_status' ) );

        // When a variation's stock changes, the parent Variable_Product's
        // memoised variations array is stale; drop both factory caches so
        // the next get_product() returns a fresh instance with current stock.
        add_action( 'tejcart_product_stock_changed', array( __CLASS__, 'invalidate_variation_parent_cache' ), 5, 1 );

        // The refund-restock path doesn't go through decrement_stock(), so
        // hook it here too — the cache shape we're invalidating is identical
        // (parent Variable_Product memoised children + factory entries).
        add_action( 'tejcart_refund_restocked_item', array( __CLASS__, 'invalidate_variation_parent_cache' ), 5, 1 );
    }

    /**
     * Drop the parent's cached Variable_Product instance whenever a child
     * variation's stock_quantity is mutated.
     *
     * Without this, a subsequent `Variable_Product::get_variations()` call
     * within the same request returns the previously memoised array of
     * Variation instances built from the pre-mutation rows, so the shop
     * surface keeps showing "still available" units that were just sold.
     *
     * Fires `tejcart_variation_stock_changed` for listeners that want a
     * variation-typed event (search indexers, JSON-LD invalidation, etc.)
     * without having to detect the parent relationship themselves.
     *
     * @param int $product_id ID of the product whose stock just changed.
     */
    public static function invalidate_variation_parent_cache( $product_id ): void {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return;
        }

        $product = \TejCart\Product\Product_Factory::get_product( $product_id );
        if ( ! $product instanceof \TejCart\Product\Product_Types\Variation ) {
            return;
        }

        $parent_id = (int) $product->get_parent_id();
        if ( $parent_id <= 0 ) {
            return;
        }

        \TejCart\Product\Product_Factory::forget( $parent_id );
        \TejCart\Product\Product_Factory::forget( $product_id );

        /**
         * Fires after a variation's stock has changed and the parent
         * Variable_Product cache has been busted.
         *
         * @param int $variation_id Variation product ID.
         * @param int $parent_id    Parent Variable_Product ID.
         */
        do_action( 'tejcart_variation_stock_changed', $product_id, $parent_id );
    }

    /**
     * Check stock levels for every item in the order and decrement accordingly.
     *
     * @param mixed $order The order object.
     * @return void
     */
    public function check_stock( $order ) {
        if ( ! $order || ! is_callable( array( $order, 'get_items' ) ) ) {
            return;
        }

        $this->pending_low = array();
        $this->pending_out = array();

        $items     = $order->get_items();
        $threshold = absint( get_option( 'tejcart_low_stock_threshold', 5 ) );

        foreach ( $items as $item ) {
            $product_id = is_callable( array( $item, 'get_product_id' ) )
                ? $item->get_product_id()
                : 0;

            if ( ! $product_id ) {
                continue;
            }

            $ordered_qty = is_callable( array( $item, 'get_quantity' ) )
                ? (int) $item->get_quantity()
                : 1;

            $product = \TejCart\Product\Product_Factory::get_product( (int) $product_id );
            if ( $product instanceof \TejCart\Product\Product_Types\Bundle_Product ) {
                foreach ( $product->get_bundled_items() as $inner_def ) {
                    $inner_id  = (int) ( $inner_def['product_id'] ?? 0 );
                    $inner_qty = max( 1, (int) ( $inner_def['quantity'] ?? 1 ) ) * $ordered_qty;
                    if ( $inner_id > 0 ) {
                        $this->decrement_product_stock( $inner_id, $inner_qty, $threshold );
                    }
                }
                continue;
            }

            $this->decrement_product_stock( (int) $product_id, $ordered_qty, $threshold );
        }

        $this->flush_digest();
    }

    /**
     * Record a low-stock event for the pending digest.
     *
     * Bound to the `tejcart_product_low_stock` action so third-party
     * subscribers still see the per-product signal while the admin inbox
     * gets a single summary mail.
     *
     * @param int $product_id Product that crossed the low-stock threshold.
     * @return void
     */
    public function queue_low_stock( $product_id ) {
        $product_id = (int) $product_id;
        if ( $product_id <= 0 ) {
            return;
        }

        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $stock = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT stock_quantity FROM {$products_table} WHERE id = %d",
            $product_id
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $this->pending_low[ $product_id ] = array(
            'id'    => $product_id,
            'stock' => $stock,
        );
    }

    /**
     * Record an out-of-stock event for the pending digest.
     *
     * @param int $product_id
     * @return void
     */
    public function queue_out_of_stock( $product_id ) {
        $product_id = (int) $product_id;
        if ( $product_id > 0 ) {
            $this->pending_out[ $product_id ] = $product_id;

            unset( $this->pending_low[ $product_id ] );
        }
    }

    /**
     * Send the queued low/out-of-stock events as one digest email per
     * order. Falls back to per-product emails (legacy behaviour) when
     * the digest option is disabled.
     *
     * @return void
     */
    private function flush_digest(): void {
        $digest = apply_filters(
            'tejcart_low_stock_digest_enabled',
            (bool) get_option( 'tejcart_low_stock_digest', true )
        );

        if ( ! $digest ) {
            foreach ( array_keys( $this->pending_low ) as $id ) {
                $this->send_low_stock_email( $id );
            }
            foreach ( $this->pending_out as $id ) {
                $this->send_out_of_stock_email( $id );
            }
            $this->pending_low = array();
            $this->pending_out = array();
            return;
        }

        if ( empty( $this->pending_low ) && empty( $this->pending_out ) ) {
            return;
        }

        $this->send_digest_email( $this->pending_low, $this->pending_out );

        $this->pending_low = array();
        $this->pending_out = array();
    }

    /**
     * Build and send a single summary email covering every stock event
     * that fired during this order save.
     *
     * @param array<int, array{id:int, stock:int}> $low
     * @param int[]                                $out
     */
    private function send_digest_email( array $low, array $out ): void {
        $low_ids = array_values( array_map( 'intval', array_keys( $low ) ) );
        $out_ids = array_values( array_map( 'intval', $out ) );

        if ( empty( $low_ids ) && empty( $out_ids ) ) {
            return;
        }

        // 06 F-M3 — previously hand-built a plain-text body and called
        // `wp_mail()` directly, bypassing Abstract_Email's HTML template,
        // marker headers (so the Tier-2 email log couldn't see it),
        // preheader, per-message Content-Type, and admin enable/disable
        // controls. Routed through Low_Stock_Digest_Email so the digest
        // behaves like every other transactional email. The
        // `tejcart_low_stock_alert_recipient` filter is preserved for
        // integrations that redirect the alert to a different address.
        $recipient = (string) apply_filters(
            'tejcart_low_stock_alert_recipient',
            get_option( 'admin_email' )
        );

        $email = new \TejCart\Email\Emails\Low_Stock_Digest_Email();
        $email->trigger( $low_ids, $out_ids, $recipient );
    }

    /**
     * Atomically decrement a single product's stock and fire the
     * out-of-stock / low-stock signals if the new level crosses a
     * threshold.
     *
     * @param int $product_id Product to deduct from.
     * @param int $qty        Amount to deduct.
     * @param int $threshold  Low-stock threshold.
     */
    private function decrement_product_stock( int $product_id, int $qty, int $threshold ): void {
        if ( $product_id <= 0 || $qty <= 0 ) {
            return;
        }

        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( $wpdb->prepare(
            "UPDATE {$products_table} SET stock_quantity = GREATEST(0, stock_quantity - %d) WHERE id = %d AND manage_stock = 1",
            $qty,
            $product_id
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        if ( 0 === (int) $wpdb->rows_affected ) {
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $new_stock = (int) $wpdb->get_var( $wpdb->prepare(
            "SELECT stock_quantity FROM {$products_table} WHERE id = %d",
            $product_id
        ) );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        wp_cache_delete( 'tejcart_product_' . $product_id, 'tejcart' );

        /**
         * Fires after a product's stock_quantity has been atomically updated.
         *
         * Webhook subscribers map this to the `product.stock_changed` event,
         * and inventory integrations can resync downstream systems.
         *
         * @param int $product_id  Product ID.
         * @param int $new_stock   Stock quantity after the deduction.
         * @param int $delta       Units deducted (always positive).
         */
        do_action( 'tejcart_product_stock_changed', $product_id, $new_stock, $qty );

        if ( 0 === $new_stock ) {
            do_action( 'tejcart_product_out_of_stock', $product_id );
        } elseif ( $new_stock <= $threshold ) {
            do_action( 'tejcart_product_low_stock', $product_id );
        }
    }

    /**
     * Send a low-stock notification email to the admin.
     *
     * @param int $product_id The product post ID.
     * @return void
     */
    public function send_low_stock_email( $product_id ) {
        $email = new \TejCart\Email\Emails\Low_Stock_Alert_Email();
        $email->trigger( $product_id );
    }

    /**
     * Send an out-of-stock notification email to the admin.
     *
     * 06 F-M1 — previously built the body with a bare `sprintf` and called
     * `wp_mail()` directly, bypassing Abstract_Email's templating,
     * marker headers (so the email log couldn't see it), preheader,
     * per-message Content-Type, and admin enable/disable controls.
     * Routed through Out_Of_Stock_Email so the alert behaves like every
     * other transactional email.
     *
     * @param int $product_id The product post ID.
     * @return void
     */
    public function send_out_of_stock_email( $product_id ) {
        $email = new \TejCart\Email\Emails\Out_Of_Stock_Email();
        $email->trigger( (int) $product_id );
    }

    /**
     * Set the product stock status to 'outofstock' when quantity reaches zero.
     *
     * @param int $product_id The product post ID.
     * @return void
     */
    public function update_stock_status( $product_id ) {
        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->update(
            $products_table,
            array( 'stock_status' => 'outofstock' ),
            array( 'id' => $product_id ),
            array( '%s' ),
            array( '%d' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }
}
