<?php
/**
 * Sales counter: keeps the `total_sales` column on wp_tejcart_products
 * in sync with completed orders so reports and the "Best sellers" sort
 * reflect reality.
 *
 * Previously `total_sales` was only ever read (and defaulted to 0 on
 * product creation) — no writer existed, which made the column a dead
 * field. This class listens for the order status transitioning to
 * `completed` and increments each line item's product by its quantity.
 *
 * @package TejCart\Product
 */

declare( strict_types=1 );

namespace TejCart\Product;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Increments `total_sales` when orders complete.
 */
class Sales_Counter {
    /**
     * Meta key used to mark an order as already counted so retries /
     * manual status cycles don't double-count sales.
     */
    public const COUNTED_META = '_tejcart_sales_counted';

    /**
     * Action Scheduler hook used to defer the per-line-item UPDATE off
     * the synchronous capture / status-change response. The handler
     * ({@see run_deferred_record_sale()}) reloads the order via
     * `tejcart_get_order()` and re-enters the same {@see record_sale()}
     * body the legacy sync path used. AS dedupes on
     * (hook, args, group="tejcart") at insert, so concurrent status
     * changes for the same order collapse to a single recompute job
     * — and the order-meta `COUNTED_META` guard above makes a second
     * AS replay (e.g. retry after a worker crash) a no-op even on
     * the rare cases where AS dedup misses.
     */
    public const RECORD_HOOK = 'tejcart_sales_counter_record';

    /**
     * Meta key used to mark an order as already reversed so cycling
     * status flips (refunded → reopened-as-completed → re-refunded)
     * don't subtract twice. Paired with {@see COUNTED_META} — the
     * counted flag stays set after reversal so a re-promotion to
     * `completed` would short-circuit; clearing both flags is the
     * documented manual reset path.
     */
    public const REVERSED_META = '_tejcart_sales_reversed';

    /**
     * Action Scheduler hook for the deferred reversal — mirror of
     * {@see RECORD_HOOK} for the cancel/fail/refund side (N-H4).
     */
    public const REVERSE_HOOK = 'tejcart_sales_counter_reverse';

    /**
     * Register listeners.
     *
     * @return void
     */
    public function init(): void {
        // Listen for both status transitions PayPal-captured orders
        // can land in. The `record_sale` entry point is the deferral
        // wrapper; the existing per-line-item UPDATE body now lives
        // in private {@see do_record_sale()}.
        add_action( 'tejcart_order_status_processing', array( $this, 'record_sale' ), 10, 2 );
        add_action( 'tejcart_order_status_completed', array( $this, 'record_sale' ), 10, 2 );

        // N-H4: mirror F-H3 (stock restore). When the order leaves the
        // success branch — cancelled, failed, or refunded — undo the
        // sales-counter increment so "Best sellers" sort and revenue
        // reports stay truthful. Idempotent via REVERSED_META.
        add_action( 'tejcart_order_status_cancelled', array( $this, 'reverse_sale' ), 10, 2 );
        add_action( 'tejcart_order_status_failed',    array( $this, 'reverse_sale' ), 10, 2 );
        add_action( 'tejcart_order_status_refunded',  array( $this, 'reverse_sale' ), 10, 2 );

        // Action Scheduler handlers for the deferred work.
        add_action( self::RECORD_HOOK,  array( $this, 'run_deferred_record_sale' ),  10, 1 );
        add_action( self::REVERSE_HOOK, array( $this, 'run_deferred_reverse_sale' ), 10, 1 );
    }

    /**
     * Status-change listener.
     *
     * Defers to Action Scheduler by default. Per-line-item UPDATEs on
     * `tejcart_products.total_sales` add 3-5 ms to the synchronous
     * capture response on a typical 3-5 line order; the value
     * (best-sellers admin sort, reports) is not read by the customer-
     * facing receipt response, so deferring is a clean perf win.
     *
     * Falls through to synchronous {@see record_sale()} when:
     *  - the `tejcart_sales_counter_async_enabled` filter is set false, OR
     *  - `Action_Scheduler::schedule_single()` returns false (hard
     *    failure / wp-cron refused). Idempotent COUNTED_META guard
     *    means a duplicated run under the
     *    "scheduling-returned-false-but-was-actually-queued" race
     *    silently no-ops.
     *
     * @param int   $order_id
     * @param mixed $order    Order object (sync path uses this directly).
     * @return void
     */
    public function record_sale( $order_id, $order ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 || ! is_object( $order ) ) {
            return;
        }

        if ( self::is_async_enabled() ) {
            $scheduled = \TejCart\Core\Action_Scheduler::instance()->schedule_single(
                time(),
                self::RECORD_HOOK,
                array( $order_id )
            );
            if ( $scheduled ) {
                return;
            }
            // schedule_single returned false. Could be a benign
            // "already scheduled" race or a hard scheduling failure;
            // we can't distinguish from the public API. Run sync as
            // the safe choice — COUNTED_META guarantees idempotency
            // so a duplicated increment never produces wrong totals.
        }

        $this->do_record_sale( $order_id, $order );
    }

    /**
     * Action Scheduler callback. Reloads the order via
     * `tejcart_get_order()` (object instances cannot be serialised
     * through AS) and re-enters the existing {@see record_sale()}
     * body. Pinned as a separate method so the AS callback signature
     * is `(int $order_id)` — matching what we scheduled above.
     *
     * @param int $order_id
     * @return void
     */
    public function run_deferred_record_sale( $order_id ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        $order = function_exists( 'tejcart_get_order' ) ? tejcart_get_order( $order_id ) : null;
        if ( ! is_object( $order ) ) {
            // Order disappeared between schedule and run (deleted via
            // admin, refunded then purged, etc.). Nothing to count.
            return;
        }

        $this->do_record_sale( $order_id, $order );
    }

    /**
     * Filter-gated kill switch. Defaults `true`; merchants who depend
     * on the legacy synchronous increment (e.g. integrations that read
     * `total_sales` immediately after the status change in the same
     * request) can opt out per-site:
     *
     *     add_filter( 'tejcart_sales_counter_async_enabled', '__return_false' );
     *
     * @return bool
     */
    private static function is_async_enabled(): bool {
        if ( ! function_exists( 'apply_filters' ) ) {
            return true;
        }
        return (bool) apply_filters( 'tejcart_sales_counter_async_enabled', true );
    }

    /**
     * Increment `total_sales` for each line item on the order.
     *
     * Idempotent: the first call stamps an order meta flag so subsequent
     * status flips (e.g. completed → refunded → completed) don't inflate
     * the counter. Private — callers go through {@see record_sale()}
     * (deferral wrapper) or {@see run_deferred_record_sale()} (AS handler).
     *
     * @param int   $order_id
     * @param mixed $order
     * @return void
     */
    private function do_record_sale( int $order_id, $order ): void {
        if ( $order_id <= 0 || ! is_object( $order ) ) {
            return;
        }

        if ( is_callable( array( $order, 'get_meta' ) )
            && ! empty( $order->get_meta( self::COUNTED_META ) )
        ) {
            return;
        }

        if ( ! is_callable( array( $order, 'get_items' ) ) ) {
            return;
        }

        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        foreach ( (array) $order->get_items() as $item ) {
            $product_id = is_callable( array( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
            $quantity   = is_callable( array( $item, 'get_quantity' ) ) ? max( 1, (int) $item->get_quantity() ) : 1;
            if ( $product_id <= 0 ) {
                continue;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$products_table} SET total_sales = total_sales + %d WHERE id = %d",
                $quantity,
                $product_id
            ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            wp_cache_delete( 'tejcart_product_' . $product_id, 'tejcart' );

            /**
             * Fires after a product's total_sales counter has been
             * incremented.
             *
             * @param int $product_id
             * @param int $delta      Units added to total_sales.
             * @param int $order_id
             */
            do_action( 'tejcart_product_sales_recorded', $product_id, $quantity, $order_id );
        }

        if ( is_callable( array( $order, 'update_meta' ) ) ) {
            $order->update_meta( self::COUNTED_META, 1 );
        }
    }

    /**
     * Status-change listener for cancel / fail / refund.
     *
     * Defers to Action Scheduler when async is enabled (mirroring
     * {@see record_sale()}). Idempotent through REVERSED_META — even if
     * the dispatcher fires the action twice for the same status (e.g.
     * the order is repeatedly toggled), only the first reversal
     * decrements the counter.
     *
     * @param int   $order_id
     * @param mixed $order
     * @return void
     */
    public function reverse_sale( $order_id, $order ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 || ! is_object( $order ) ) {
            return;
        }

        if ( self::is_async_enabled() ) {
            $scheduled = \TejCart\Core\Action_Scheduler::instance()->schedule_single(
                time(),
                self::REVERSE_HOOK,
                array( $order_id )
            );
            if ( $scheduled ) {
                return;
            }
        }

        $this->do_reverse_sale( $order_id, $order );
    }

    /**
     * Action Scheduler callback for {@see reverse_sale()}.
     *
     * @param int $order_id
     * @return void
     */
    public function run_deferred_reverse_sale( $order_id ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        $order = function_exists( 'tejcart_get_order' ) ? tejcart_get_order( $order_id ) : null;
        if ( ! is_object( $order ) ) {
            return;
        }

        $this->do_reverse_sale( $order_id, $order );
    }

    /**
     * Decrement `total_sales` for each line item on the order.
     *
     * Pre-conditions:
     *  - The order must have been counted (COUNTED_META truthy).
     *  - The order must not have been reversed already (REVERSED_META falsy).
     *
     * Both flags are required because (a) we never decrement an order
     * that was cancelled before completion (would underflow the counter
     * for that product); (b) the reversed flag prevents repeated
     * cancelled→failed transitions from double-subtracting.
     *
     * @param int   $order_id
     * @param mixed $order
     * @return void
     */
    private function do_reverse_sale( int $order_id, $order ): void {
        if ( $order_id <= 0 || ! is_object( $order ) ) {
            return;
        }

        if ( ! is_callable( array( $order, 'get_meta' ) ) ) {
            return;
        }

        // Never counted → nothing to reverse.
        if ( empty( $order->get_meta( self::COUNTED_META ) ) ) {
            return;
        }
        // Already reversed → idempotent no-op.
        if ( ! empty( $order->get_meta( self::REVERSED_META ) ) ) {
            return;
        }

        if ( ! is_callable( array( $order, 'get_items' ) ) ) {
            return;
        }

        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        foreach ( (array) $order->get_items() as $item ) {
            $product_id = is_callable( array( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
            $quantity   = is_callable( array( $item, 'get_quantity' ) ) ? max( 1, (int) $item->get_quantity() ) : 1;
            if ( $product_id <= 0 ) {
                continue;
            }

            // GREATEST(0, ...) clamp guards against the (theoretical)
            // case where total_sales was manually edited below the
            // line-item quantity since we counted.
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( $wpdb->prepare(
                "UPDATE {$products_table} SET total_sales = GREATEST(0, total_sales - %d) WHERE id = %d",
                $quantity,
                $product_id
            ) );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            wp_cache_delete( 'tejcart_product_' . $product_id, 'tejcart' );

            /**
             * Fires after a product's total_sales counter has been
             * decremented (cancel / fail / refund reversal).
             *
             * @param int $product_id
             * @param int $delta      Units removed from total_sales.
             * @param int $order_id
             */
            do_action( 'tejcart_product_sales_reversed', $product_id, $quantity, $order_id );
        }

        if ( is_callable( array( $order, 'update_meta' ) ) ) {
            $order->update_meta( self::REVERSED_META, 1 );
        }
    }
}
