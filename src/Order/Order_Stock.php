<?php
/**
 * Order stock decrement.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Centralised "decrement tejcart_products.stock_quantity for every line in
 * an order" routine.
 *
 * The classic Checkout::process() flow already decrements stock inside its
 * own outer transaction, before calling the gateway, so a card decline
 * never burns inventory. Express-checkout flows (PayPal Smart Buttons /
 * Apple Pay / Google Pay / Fastlane) bypass Checkout::process() entirely:
 * they create a pending order, hand off to PayPal, and only learn the
 * outcome at capture time. Without a post-capture decrement those flows
 * leave on-hand stock untouched even though units have shipped.
 *
 * This listener fires on the same status transitions Order_Cart_Cleanup
 * binds to (on-hold, processing, completed) and is idempotent via the
 * {@see self::ORDER_META_KEY} order meta flag, so:
 *
 *   - The Checkout flow (which decrements pre-charge inside its own
 *     transaction and sets the flag itself) sees the listener no-op when
 *     it later fires the processing transition.
 *   - Express + webhook flows that reach processing/on-hold without ever
 *     touching the Checkout decrement get their stock reduced exactly
 *     once.
 *   - Replays of the same status transition (webhook retries, manual
 *     admin re-applies) are safe.
 */
class Order_Stock {

    /**
     * Order meta key used as the once-only guard.
     */
    public const ORDER_META_KEY = '_tejcart_stock_reduced';

    /**
     * Wire up listeners on the post-pending status transitions.
     */
    public function init(): void {
        // Bind to every status that means "the buyer is committed and the
        // merchant should treat the inventory as gone."
        add_action( 'tejcart_order_status_on-hold',    array( $this, 'reduce_for_order' ), 10, 2 );
        add_action( 'tejcart_order_status_processing', array( $this, 'reduce_for_order' ), 10, 2 );
        add_action( 'tejcart_order_status_completed',  array( $this, 'reduce_for_order' ), 10, 2 );

        // F-H3 / #926: bind the reverse transitions too. Order_Refund
        // handles the refund-line-item restock path, but when an admin
        // manually transitions an already-reduced order to cancelled /
        // failed / refunded (no refund line item exists), the previous
        // decrement leaks. restore_for_order is idempotent — guarded
        // by the same _tejcart_stock_reduced meta — so orders that
        // never reduced (e.g. pending -> cancelled before any stock
        // operation) are a no-op.
        add_action( 'tejcart_order_status_cancelled', array( $this, 'restore_for_order' ), 10, 2 );
        add_action( 'tejcart_order_status_failed',    array( $this, 'restore_for_order' ), 10, 2 );
        add_action( 'tejcart_order_status_refunded',  array( $this, 'restore_for_order' ), 10, 2 );

        // Pending-order reaper. Checkout::process schedules a
        // one-shot tejcart_pending_order_reaper event 15 minutes after
        // commit; if the order is still pending with no transaction
        // reference by then, transition to cancelled so the existing
        // restore_for_order listener undoes the inventory decrement.
        add_action( 'tejcart_pending_order_reaper', array( $this, 'reap_pending_order' ), 10, 1 );
    }

    /**
     * Reaper for orders that committed pre-gateway and never reached a
     * terminal status. A PHP fatal / worker kill / request timeout
     * between Checkout's COMMIT and the gateway response would
     * otherwise leave the order in `pending` forever with stock
     * decremented and coupon counters inflated.
     *
     * The `$order_id` named parameter exists for one reason: orphan
     * wp-cron events scheduled with the legacy assoc-array shape
     * `[ 'order_id' => N ]` (before the positional-args fix in
     * Checkout::process) are still in `cron` in wp_options on existing
     * installs. WP_Hook dispatches them via call_user_func_array,
     * which in PHP 8 treats string keys as named parameters — so the
     * legacy payload arrives as `reap_pending_order(order_id: N)`
     * and would fatal with "Unknown named parameter $order_id" if the
     * parameter were not declared here. Once those orphans drain (one
     * fire per stranded order, ~15 minutes after the original commit),
     * the codepath is dormant; new schedules use the positional
     * `$payload = N` shape and the parameter stays at its default.
     *
     * @param int|numeric-string|array<int|string,mixed>|null $payload  Positional scheduled arg.
     * @param int                                              $order_id Absorbs the named-parameter shape from legacy orphan events.
     * @return void
     */
    public function reap_pending_order( $payload = null, int $order_id = 0 ): void {
        if ( $order_id <= 0 ) {
            if ( is_array( $payload ) ) {
                if ( isset( $payload['order_id'] ) ) {
                    $order_id = (int) $payload['order_id'];
                } elseif ( isset( $payload[0] ) ) {
                    $order_id = (int) $payload[0];
                }
            } elseif ( is_int( $payload ) || is_numeric( $payload ) ) {
                $order_id = (int) $payload;
            }
        }
        if ( $order_id <= 0 || ! class_exists( '\\TejCart\\Order\\Order' ) ) {
            return;
        }

        $order = new \TejCart\Order\Order( $order_id );
        if ( ! $order->get_id() ) {
            return;
        }

        // Only reap if the order is still in `pending`. Any other
        // status means either the gateway succeeded (processing /
        // on-hold / completed) or some other path already cancelled /
        // failed it — nothing for us to do.
        if ( 'pending' !== (string) $order->get_status() ) {
            return;
        }

        // Also bail if a gateway reference has been stamped — some
        // gateways (PayPal Smart Buttons) keep the order in `pending`
        // until the buyer completes the wallet flow client-side, and
        // they stamp a `_paypal_order_id` or transaction_id before
        // the redirect. Don't kill an in-flight wallet handoff.
        $tx_id = method_exists( $order, 'get_transaction_id' ) ? (string) $order->get_transaction_id() : '';
        if ( '' !== $tx_id ) {
            return;
        }
        foreach ( array( '_paypal_order_id', '_tejcart_stripe_payment_intent_id', '_authnet_transaction_id' ) as $intent_key ) {
            $stamped = (string) tejcart_get_order_meta( $order_id, $intent_key );
            if ( '' !== $stamped ) {
                return;
            }
        }

        // Transition to cancelled. The existing restore_for_order
        // listener on tejcart_order_status_cancelled will run and
        // undo the stock decrement; Order_Coupon_Rollback's listener
        // on the same status will roll back coupon counters.
        if ( method_exists( $order, 'update_status' ) ) {
            $order->update_status(
                'cancelled',
                __( 'Reaped: order never received a gateway response within the recovery window.', 'tejcart' )
            );
            if ( method_exists( $order, 'save' ) ) {
                $order->save();
            }
        }

        /**
         * Fires after a pending order has been reaped. Listeners can
         * route this to ops alerting so the engineering team can
         * investigate the underlying gateway-call failure that left
         * the order stranded in the first place.
         *
         * @param int $order_id The reaped order id.
         */
        do_action( 'tejcart_pending_order_reaped', $order_id );
    }

    /**
     * Idempotent restore wrapper for status-transition listeners.
     *
     * Delegates to {@see self::restore_decrement()} which is itself
     * idempotent (returns true without DB writes when the order is
     * not currently flagged as reduced). The wrapper exists so
     * `add_action` can carry a public callable.
     *
     * @param int        $order_id
     * @param mixed|null $order    Unused; present so the action signature matches.
     * @return bool
     */
    public function restore_for_order( $order_id, $order = null ): bool {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return false;
        }
        return self::restore_decrement( $order_id );
    }

    /**
     * Idempotent, transaction-managing wrapper around {@see self::reduce_in_transaction()}.
     *
     * Safe to call from any context — opens its own transaction, sets the
     * once-only meta flag, returns true if stock was already reduced.
     *
     * Audit F-006: this opens a bare `START TRANSACTION`. MySQL has no
     * nested transactions, so an outer `START TRANSACTION` is implicitly
     * committed when this one begins. Extension authors must NOT invoke an
     * order status transition (which fires this listener) from inside their
     * own open transaction — doing so would silently commit the outer
     * transaction here. In core this is reached only via already-committed
     * status transitions and short-circuits on `already_reduced`, so there
     * is no live nesting.
     *
     * @param int        $order_id
     * @param mixed|null $order    Unused; present so the action signature matches.
     * @return bool True when stock was reduced (or had already been reduced); false on failure.
     */
    public function reduce_for_order( $order_id, $order = null ): bool {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return false;
        }

        if ( self::already_reduced( $order_id ) ) {
            return true;
        }

        // Re-entry guard. The failure branch below transitions the
        // order to on-hold, which is in our listener list, which calls
        // us again. update_status no-ops on same-status, but the
        // second invocation still hits this method via the action
        // chain. Short-circuit so we don't fire the failure-recovery
        // path twice (which would re-issue the order note and the
        // tejcart_order_stock_reduction_failed action).
        if ( 'yes' === (string) tejcart_get_order_meta( $order_id, '_tejcart_stock_reduce_failed' ) ) {
            return false;
        }

        global $wpdb;
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'START TRANSACTION' );

        $ok = self::reduce_in_transaction( $order_id );

        if ( ! $ok ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->query( 'ROLLBACK' );

            // The listener fires from a tejcart_order_status_<X>
            // transition that has *already* been committed by
            // Order::update_status. If we only return false here, the
            // order is left in `processing` (or whatever destination
            // status fired this listener) with stock NOT decremented —
            // silent oversell. Transition the order back to on-hold so
            // a human reviews it, with a note explaining why. The
            // status flip is wrapped in a guard against re-entry into
            // this same listener: on-hold is in our listener list, but
            // already_reduced is still false (we just rolled back), so
            // a naive transition would loop. We pass a special meta
            // flag that the listener short-circuits on.
            $reentry_guard = '_tejcart_stock_reduce_failed';
            tejcart_update_order_meta( $order_id, $reentry_guard, 'yes' );

            $resolved_order = $order;
            if ( ! is_object( $resolved_order ) && class_exists( '\\TejCart\\Order\\Order' ) ) {
                $resolved_order = new \TejCart\Order\Order( $order_id );
            }
            if ( is_object( $resolved_order )
                && method_exists( $resolved_order, 'get_id' ) && $resolved_order->get_id()
                && method_exists( $resolved_order, 'update_status' )
            ) {
                $resolved_order->update_status(
                    'on-hold',
                    __( 'Stock reduction failed (insufficient inventory). Order placed on hold for manual review.', 'tejcart' )
                );
                if ( method_exists( $resolved_order, 'save' ) ) {
                    $resolved_order->save();
                }
            }

            /**
             * Fires when a stock-decrement-at-status-transition fails
             * and the order is being held for manual review. Listeners
             * can route this to ops alerting (Slack / PagerDuty / email).
             *
             * @param int $order_id The order that failed to decrement.
             */
            do_action( 'tejcart_order_stock_reduction_failed', $order_id );

            return false;
        }

        self::mark_reduced( $order_id );

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $wpdb->query( 'COMMIT' );

        // Clear any prior failure marker now that we've succeeded.
        if ( 'yes' === (string) tejcart_get_order_meta( $order_id, '_tejcart_stock_reduce_failed' ) ) {
            tejcart_update_order_meta( $order_id, '_tejcart_stock_reduce_failed', 'no' );
        }

        return true;
    }

    /**
     * Decrement stock for every line in $order_id, requiring the caller to
     * own the surrounding START TRANSACTION / COMMIT / ROLLBACK.
     *
     * Mirrors the legacy Checkout::decrement_stock_in_transaction() body
     * exactly so callers that already hold a FOR UPDATE lock from
     * Checkout::validate_stock() observe identical semantics.
     *
     * Caller responsibility:
     *   - Open the transaction before calling.
     *   - COMMIT on a true return; ROLLBACK on a false return.
     *   - Call {@see self::mark_reduced()} after COMMIT (or let
     *     {@see self::reduce_for_order()} do both for you).
     *
     * @param int $order_id
     * @return bool
     */
    public static function reduce_in_transaction( int $order_id ): bool {
        global $wpdb;

        $order = Order_Factory::get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $items = $order->get_items();
        if ( empty( $items ) ) {
            return true;
        }

        $products_table = $wpdb->prefix . 'tejcart_products';

        // Sort by product_id so two concurrent reducers acquiring
        // FOR UPDATE locks for an overlapping set of rows always lock in
        // the same order — eliminates the AB/BA deadlock.
        usort(
            $items,
            static function ( $a, $b ) {
                return self::stock_product_id( $a ) <=> self::stock_product_id( $b );
            }
        );

        foreach ( $items as $item ) {
            // Variable-product lines store the PARENT id in product_id and the
            // purchased variation's own id in the line meta; stock lives on the
            // variation row, so lock/decrement that row (and expose the parent
            // for cache invalidation). Simple products resolve to product_id.
            $product_id = self::stock_product_id( $item );
            $parent_id  = self::parent_product_id( $item );
            $quantity   = isset( $item->quantity ) ? (int) $item->quantity : 0;

            if ( ! $product_id || ! $quantity ) {
                continue;
            }

            if ( is_object( $item ) && $parent_id > 0 && $parent_id !== $product_id ) {
                $item->variation_parent_id = $parent_id;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $row = $wpdb->get_row(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "SELECT manage_stock, stock_quantity, backorders FROM {$products_table} WHERE id = %d FOR UPDATE",
                    $product_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( ! $row || empty( $row->manage_stock ) ) {
                continue;
            }

            $backorders_allowed = isset( $row->backorders )
                && in_array( (string) $row->backorders, array( 'yes', 'notify' ), true );

            $new_stock = (int) $row->stock_quantity - $quantity;

            // Backorders allowed → negative on-hand is the documented
            // signal of an open backorder. Permit the decrement and let
            // stock_status remain `instock`/`onbackorder` per the
            // merchant's configuration. Without this, an order taken
            // via Cart::add+Checkout::validate_stock (both of which
            // now honour backorders_allowed) would still fail at
            // capture-time and silently leave the order in
            // `processing` with stock NOT decremented.
            if ( $new_stock < 0 && ! $backorders_allowed ) {
                if ( function_exists( 'tejcart_log' ) ) {
                    tejcart_log(
                        sprintf( 'Stock decrement failed for product #%d (qty %d) on order #%d.', $product_id, $quantity, $order_id ),
                        'warning'
                    );
                }

                /**
                 * Fires when stock is insufficient during order stock decrement.
                 *
                 * @param int $product_id The product ID.
                 * @param int $quantity   The requested quantity.
                 * @param int $order_id   The order ID.
                 */
                do_action( 'tejcart_product_insufficient_stock', $product_id, $quantity, $order_id );

                return false;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "UPDATE {$products_table} SET stock_quantity = %d WHERE id = %d",
                    $new_stock,
                    $product_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // Invalidate the cached product instance so downstream
            // readers in the same request (confirmation email, receipt,
            // low-stock dispatch) AND across requests (shop listings,
            // add-to-cart validation — both cached up to HOUR_IN_SECONDS)
            // see the new stock_quantity rather than the pre-decrement
            // row. Without this, persistent caches show "in stock" for
            // up to an hour after every order capture.
            self::forget_product_cache( $product_id, $item );

            $low_stock_threshold = (int) tejcart_get_setting( 'low_stock_threshold', 5 );

            if ( $new_stock <= $low_stock_threshold ) {
                /**
                 * Fires when a product's stock falls to or below the low-stock threshold.
                 *
                 * @param int $product_id The product ID.
                 * @param int $new_stock  The new stock quantity.
                 */
                do_action( 'tejcart_product_low_stock', $product_id, $new_stock );
            }

            if ( 0 === $new_stock ) {
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->update(
                    $products_table,
                    array( 'stock_status' => 'outofstock' ),
                    array( 'id' => $product_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            } elseif ( $new_stock < 0 && $backorders_allowed ) {
                // Low (backorder oversell): a negative on-hand under an
                // allowed-backorder policy is an open backorder, but the
                // status column was left as 'instock', so storefront
                // badges and the admin list mislabelled it. Flip it to
                // 'onbackorder' so the state is visible.
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->update(
                    $products_table,
                    array( 'stock_status' => 'onbackorder' ),
                    array( 'id' => $product_id ),
                    array( '%s' ),
                    array( '%d' )
                );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            }
        }

        // C-M3: a fresh reduction starts a new stock lifecycle for this
        // order. Clear any partial-restock bookkeeping left over from a
        // previous refund cycle so a later full restock doesn't subtract
        // stale already-restocked quantities and under-restore.
        tejcart_update_order_meta( $order_id, '_tejcart_partial_restocked_qty', array() );

        return true;
    }

    /**
     * Has stock for this order already been reduced?
     */
    public static function already_reduced( int $order_id ): bool {
        return 'yes' === (string) tejcart_get_order_meta( $order_id, self::ORDER_META_KEY );
    }

    /**
     * Set the once-only flag.
     */
    public static function mark_reduced( int $order_id ): void {
        tejcart_update_order_meta( $order_id, self::ORDER_META_KEY, 'yes' );
    }

    /**
     * Reverse a prior {@see self::reduce_in_transaction()} for this order.
     *
     * Used by Checkout::process() when the order/stock reservation has
     * already been COMMITted but the subsequent gateway call returns a
     * failure. Each line item's previously-decremented quantity is added
     * back atomically (single UPDATE ... stock_quantity = stock_quantity + qty),
     * the once-only `_tejcart_stock_reduced` flag is cleared so future
     * status transitions don't see a stale "already reduced" marker, and
     * the OOS → instock toggle is reapplied where the restoration brings
     * the count above zero.
     *
     * @param int $order_id
     * @return bool
     */
    public static function restore_decrement( int $order_id ): bool {
        if ( ! self::already_reduced( $order_id ) ) {
            return true;
        }

        $order = Order_Factory::get_order( $order_id );
        if ( ! $order ) {
            return false;
        }

        $items = $order->get_items();
        if ( empty( $items ) ) {
            tejcart_update_order_meta( $order_id, self::ORDER_META_KEY, 'no' );
            tejcart_update_order_meta( $order_id, '_tejcart_partial_restocked_qty', array() );
            return true;
        }

        global $wpdb;
        $products_table = $wpdb->prefix . 'tejcart_products';

        // Lock-order siblings to reduce_in_transaction()'s usort: same id
        // ordering means a concurrent reducer/restorer never deadlocks.
        usort(
            $items,
            static function ( $a, $b ) {
                return self::stock_product_id( $a ) <=> self::stock_product_id( $b );
            }
        );

        // Audit H-16 (Cart F-002): partial refunds that already
        // restocked via Order_Refund::restock_items() record the qty
        // per order-item in this meta. Subtract so the full-refund
        // path restocks only the remainder — prevents double-restocking.
        $partial_restocked = (array) tejcart_get_order_meta( $order_id, '_tejcart_partial_restocked_qty' );

        foreach ( $items as $item ) {
            // Restore to the SAME row reduce_in_transaction() decremented: the
            // variation row for variable products, product_id otherwise.
            $product_id   = self::stock_product_id( $item );
            $parent_id    = self::parent_product_id( $item );
            $quantity     = isset( $item->quantity ) ? (int) $item->quantity : 0;
            $item_id      = isset( $item->id ) ? (int) $item->id : 0;
            $already_done = isset( $partial_restocked[ $item_id ] ) ? (int) $partial_restocked[ $item_id ] : 0;
            $quantity     = max( 0, $quantity - $already_done );

            if ( ! $product_id || $quantity <= 0 ) {
                continue;
            }

            if ( is_object( $item ) && $parent_id > 0 && $parent_id !== $product_id ) {
                $item->variation_parent_id = $parent_id;
            }

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "UPDATE {$products_table}
                        SET stock_quantity = stock_quantity + %d,
                            stock_status   = CASE WHEN manage_stock = 1 AND stock_quantity + %d > 0
                                                  THEN 'instock' ELSE stock_status END
                      WHERE id = %d AND manage_stock = 1",
                    $quantity,
                    $quantity,
                    $product_id
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            // Mirror the invalidation in reduce_in_transaction() so a
            // restored order's products show the correct stock + status
            // immediately on the next request.
            self::forget_product_cache( $product_id, $item );
        }

        tejcart_update_order_meta( $order_id, self::ORDER_META_KEY, 'no' );

        // C-M3: the full restock above has already netted out any
        // partial refund restocks (via the $partial_restocked subtraction).
        // Clear the bookkeeping so the order leaves the refunded state with
        // a clean slate — otherwise a subsequent reduce/refund cycle would
        // subtract stale already-restocked quantities and under-restore.
        tejcart_update_order_meta( $order_id, '_tejcart_partial_restocked_qty', array() );
        return true;
    }

    /**
     * Resolve the tejcart_products row that actually owns this line's stock.
     *
     * Variable-product order lines store the PARENT product id in
     * `product_id` and the purchased variation's own id in the line meta
     * (`variation_id`). Stock is managed on the variation row, so that is the
     * row to lock, decrement and restore. Simple-product lines have no
     * variation_id and fall back to `product_id`. Keeping this resolution in
     * one place means validate_stock (which locks the variation/leaf id) and
     * the decrement/restore paths all operate on the same row.
     *
     * @param object|array $item Order-item DB row (object) or array.
     * @return int Stock-bearing product id.
     */
    private static function stock_product_id( $item ): int {
        $variation_id = self::read_item_field( $item, 'variation_id' );
        if ( $variation_id > 0 ) {
            return $variation_id;
        }
        return self::parent_product_id( $item );
    }

    /**
     * The parent/catalog product id recorded on the order line.
     *
     * @param object|array $item Order-item DB row (object) or array.
     * @return int
     */
    private static function parent_product_id( $item ): int {
        if ( is_object( $item ) ) {
            return isset( $item->product_id ) ? (int) $item->product_id : 0;
        }
        return isset( $item['product_id'] ) ? (int) $item['product_id'] : 0;
    }

    /**
     * Read an int field from an order-item row, falling back to the field
     * embedded in the line's `meta` JSON (where variation_id is persisted —
     * the order_items table has no dedicated variation_id column).
     *
     * @param object|array $item  Order-item row.
     * @param string       $field Field name.
     * @return int 0 when absent.
     */
    private static function read_item_field( $item, string $field ): int {
        if ( is_object( $item ) ) {
            if ( isset( $item->$field ) ) {
                return (int) $item->$field;
            }
            $meta = $item->meta ?? null;
        } else {
            if ( isset( $item[ $field ] ) ) {
                return (int) $item[ $field ];
            }
            $meta = $item['meta'] ?? null;
        }

        if ( is_string( $meta ) && '' !== $meta ) {
            $decoded = json_decode( $meta, true );
            if ( is_array( $decoded ) && isset( $decoded[ $field ] ) ) {
                return (int) $decoded[ $field ];
            }
        } elseif ( is_array( $meta ) && isset( $meta[ $field ] ) ) {
            return (int) $meta[ $field ];
        }

        return 0;
    }

    /**
     * Forget cached product instances for a stock-changed line item.
     *
     * Best-effort: the Product_Factory class may not have loaded yet on
     * very early bootstrap edges, so we guard the call. For variation
     * line items we also forget the parent product id so any "display
     * stock from parent" code paths refresh.
     */
    private static function forget_product_cache( int $product_id, $item ): void {
        if ( $product_id <= 0 || ! class_exists( '\\TejCart\\Product\\Product_Factory' ) ) {
            return;
        }

        \TejCart\Product\Product_Factory::forget( $product_id );

        // Order_Items can carry a variation_parent_id (variable products
        // store the variation's own id in product_id and the parent
        // separately). The parent's listings/availability views also
        // need to refresh.
        if ( is_object( $item ) && isset( $item->variation_parent_id ) ) {
            $parent_id = (int) $item->variation_parent_id;
            if ( $parent_id > 0 && $parent_id !== $product_id ) {
                \TejCart\Product\Product_Factory::forget( $parent_id );
            }
        } elseif ( is_array( $item ) && isset( $item['variation_parent_id'] ) ) {
            $parent_id = (int) $item['variation_parent_id'];
            if ( $parent_id > 0 && $parent_id !== $product_id ) {
                \TejCart\Product\Product_Factory::forget( $parent_id );
            }
        }
    }
}
