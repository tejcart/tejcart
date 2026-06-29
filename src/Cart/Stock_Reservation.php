<?php
/**
 * Stock reservation system.
 *
 * @package TejCart\Cart
 */

declare( strict_types=1 );

namespace TejCart\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Holds stock for products that are sitting in customer carts so a
 * second customer cannot oversell the same units.
 *
 * Reservations are stored row-per (product_id, session_id) in
 * {prefix}tejcart_stock_reservations. Updates are atomic via
 * INSERT ... ON DUPLICATE KEY UPDATE so concurrent add-to-cart calls
 * for the same product never clobber each other, and there is no
 * single hot row / option blob bottleneck.
 *
 * Effective available stock for product P, ignoring the current
 * session, is:
 *
 *     stock_quantity - SUM(qty WHERE product_id = P
 *                              AND expires_at > NOW()
 *                              AND session_id != current)
 */
class Stock_Reservation {
    const TTL = 1800;

    /**
     * Effective reservation TTL in seconds, filterable.
     *
     * Merchants running flash sales typically want a shorter window
     * (e.g. 5 minutes) so abandoned carts release inventory faster;
     * stores with slow checkouts may want longer (e.g. 1 hour).
     *
     * @return int
     */
    public static function get_ttl(): int {
        $ttl = (int) apply_filters( 'tejcart_stock_reservation_ttl', self::TTL );
        return $ttl > 0 ? $ttl : self::TTL;
    }

    /**
     * Wire up cart hooks.
     */
    public function init(): void {
        add_filter( 'tejcart_add_to_cart_validation', array( $this, 'validate' ), 10, 4 );

        // Audit #45 / 02 M-3 — priority 1 (was 99) so the reservation
        // write happens BEFORE any other `tejcart_add_to_cart`
        // listener can interpose. Minimises the race window between
        // Cart::add()'s line-insert and the reservation row landing.
        // A PHP fatal between the two would still leave the line
        // without a reservation, but the checkout-time
        // validate_stock FOR UPDATE in Checkout::process_payment
        // catches the resulting oversell. The audit's full fix
        // (call try_reserve from inside Cart::add and roll the line
        // back on failure) is deferred — it requires changes to the
        // Cart's public add() contract.
        add_action( 'tejcart_add_to_cart',            array( $this, 'on_add' ),    1, 5 );
        add_action( 'tejcart_before_remove_cart_item', array( $this, 'on_remove' ), 10, 2 );
        add_action( 'tejcart_cart_item_quantity_updated', array( $this, 'on_quantity_changed' ), 10, 4 );
        add_action( 'tejcart_cart_emptied',           array( $this, 'release_session' ) );
        add_action( 'tejcart_restore_stock_for_order', array( $this, 'release_for_order' ), 10, 2 );
        add_action( 'tejcart_order_status_completed',  array( $this, 'release_session' ) );
        add_action( 'tejcart_order_status_processing', array( $this, 'release_session' ) );
        // Release per-order reservations on terminal states too.
        add_action( 'tejcart_order_status_cancelled',  array( $this, 'release_for_order' ), 10, 2 );
        add_action( 'tejcart_order_status_failed',     array( $this, 'release_for_order' ), 10, 2 );

        // Prune expired reservations off the hot path. The WHERE clauses in
        // try_reserve()/reserved_by_others() already exclude expired rows,
        // so this is purely a table-size cleanup and can run on a cron.
        add_action( 'tejcart_stock_reservation_prune', array( __CLASS__, 'prune_expired' ) );
        add_action( 'init', array( __CLASS__, 'maybe_schedule_prune' ) );
    }

    /**
     * Schedule the global prune job once per install.
     *
     * Idempotent: wp_next_scheduled() short-circuits if it's already on the
     * cron table. The job itself is bounded ({@see prune_expired}) so it
     * is safe to run on default WP-Cron at high traffic.
     */
    public static function maybe_schedule_prune(): void {
        if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
            return;
        }
        if ( ! wp_next_scheduled( 'tejcart_stock_reservation_prune' ) ) {
            wp_schedule_event( time() + 300, 'hourly', 'tejcart_stock_reservation_prune' );
        }
    }

    /**
     * Bulk-delete expired reservation rows.
     *
     * Bounded with LIMIT so a backed-up cron run on a busy store cannot
     * lock the table for seconds. Loops until no more expired rows exist
     * or the safety cap is reached.
     */
    public static function prune_expired(): void {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_stock_reservations';
        $now   = current_time( 'mysql', true );
        $batch = (int) apply_filters( 'tejcart_stock_reservation_prune_batch', 5000 );
        if ( $batch <= 0 ) {
            $batch = 5000;
        }

        $iterations = 0;
        do {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $deleted = (int) $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "DELETE FROM {$table} WHERE expires_at <= %s LIMIT %d",
                    $now,
                    $batch
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $iterations++;
        } while ( $deleted === $batch && $iterations < 20 );
    }

    /**
     * Fully-qualified table name.
     */
    private function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tejcart_stock_reservations';
    }

    /**
     * Block add-to-cart when reserved-by-others would exceed stock.
     *
     * Returns a WP_Error (with code `stock_unavailable`) when the block
     * fires, so the buyer sees a specific reason instead of the
     * generic "could not be added" notice. An upstream WP_Error is
     * passed through unchanged.
     *
     * @param bool|\WP_Error $valid      Current validation state.
     * @param int            $product_id Product ID.
     * @param int            $quantity   Quantity being added.
     * @param array          $data       Item data.
     * @return bool|\WP_Error
     */
    public function validate( $valid, $product_id, $quantity, $data ) {
        if ( is_wp_error( $valid ) ) {
            return $valid;
        }
        if ( ! $valid ) {
            return false;
        }

        $product = \TejCart\Product\Product_Factory::get_product( (int) $product_id );
        if ( ! $product ) {
            return (bool) $valid;
        }

        if ( $product instanceof \TejCart\Product\Product_Types\Bundle_Product ) {
            foreach ( $this->expand_bundle_items( $product, (int) $quantity ) as $inner ) {
                if ( ! $inner['manage_stock'] ) {
                    continue;
                }
                $available = $inner['stock'] - $this->reserved_by_others( $inner['product_id'] );
                if ( $available < $inner['quantity'] ) {
                    $inner_product = \TejCart\Product\Product_Factory::get_product( (int) $inner['product_id'] );
                    $inner_name    = ( $inner_product && method_exists( $inner_product, 'get_name' ) )
                        ? (string) $inner_product->get_name()
                        : '';
                    return $this->stock_block_error( $inner_name );
                }
            }
            return (bool) $valid;
        }

        if ( ! $product->get_manage_stock() ) {
            return (bool) $valid;
        }

        $stock     = (int) $product->get_stock_quantity();
        $reserved  = $this->reserved_by_others( (int) $product_id );
        $available = $stock - $reserved;

        if ( $available < (int) $quantity ) {
            $name = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
            return $this->stock_block_error( $name );
        }

        return (bool) $valid;
    }

    /**
     * Build the customer-facing WP_Error for a stock block.
     *
     * Covers both "merchant set stock to 0" and "all units held by other
     * shoppers' carts" with one message — distinguishing them would leak
     * other buyers' cart state and confuses the customer either way.
     */
    private function stock_block_error( string $product_name ): \WP_Error {
        $msg = '' !== $product_name
            ? sprintf(
                /* translators: %s: product name */
                __( 'Sorry, %s is unavailable right now (out of stock or held in another shopper\'s cart). Please try again in a few minutes.', 'tejcart' ),
                $product_name
            )
            : __( 'Sorry, this product is unavailable right now (out of stock or held in another shopper\'s cart). Please try again in a few minutes.', 'tejcart' );

        return new \WP_Error( 'stock_unavailable', $msg );
    }

    /**
     * Expand a bundle into its inner stock-tracked items at the requested
     * outer quantity.
     *
     * @param \TejCart\Product\Product_Types\Bundle_Product $bundle Bundle product.
     * @param int                                          $qty    Outer bundle quantity.
     * @return array<int, array{product_id:int, quantity:int, stock:int, manage_stock:bool}>
     */
    private function expand_bundle_items( $bundle, int $qty ): array {
        $expanded = array();
        if ( $qty <= 0 ) {
            return $expanded;
        }

        foreach ( $bundle->get_bundled_items() as $item ) {
            $inner = \TejCart\Product\Product_Factory::get_product( (int) ( $item['product_id'] ?? 0 ) );
            if ( ! $inner ) {
                continue;
            }
            $inner_qty = max( 1, (int) ( $item['quantity'] ?? 1 ) ) * $qty;
            $expanded[] = array(
                'product_id'   => (int) $inner->get_id(),
                'quantity'     => $inner_qty,
                'stock'        => (int) $inner->get_stock_quantity(),
                'manage_stock' => (bool) $inner->get_manage_stock(),
            );
        }

        return $expanded;
    }

    /**
     * Persist a reservation when an item is added to the cart.
     *
     * Uses try_reserve() which performs the stock check and the upsert
     * inside a single transaction with FOR UPDATE locks, closing the
     * race window between validate() and on_add() where two concurrent
     * adds for the same product could both pass validation.
     *
     * If the reservation fails (someone else won the race), the cart
     * line we just added is rolled back so the customer doesn't see a
     * silent oversell at checkout.
     *
     * Hook signature: ($cart_item_key, $product_id, $quantity, $data, $cart).
     *
     * @param string                 $cart_item_key Cart line key.
     * @param int                    $product_id    Product ID.
     * @param int                    $quantity      Quantity added.
     * @param array                  $data          Extra item data.
     * @param Cart|null              $cart          Cart instance, for rollback on race loss.
     */
    public function on_add( $cart_item_key, $product_id, $quantity, $data, $cart = null ): void {
        $pid     = (int) $product_id;
        $qty     = (int) $quantity;
        $session = $this->session_id();

        if ( $pid <= 0 || $qty <= 0 || '' === $session ) {
            return;
        }

        $product = \TejCart\Product\Product_Factory::get_product( $pid );
        if ( ! $product ) {
            return;
        }

        if ( $product instanceof \TejCart\Product\Product_Types\Bundle_Product ) {
            // Track each child reservation that succeeded so a
            // later child losing the race can roll the previous siblings
            // back. Without this, child A's row stays held until TTL even
            // though child B refused the reservation and the buyer's cart
            // line was rolled back, starving the next bundle add-to-cart.
            $reserved_children = array();
            foreach ( $this->expand_bundle_items( $product, $qty ) as $inner ) {
                if ( ! $inner['manage_stock'] ) {
                    continue;
                }
                if ( ! $this->try_reserve( $inner['product_id'], $inner['quantity'], $session, $inner['stock'] ) ) {
                    foreach ( $reserved_children as $prev ) {
                        $this->decrement_reservation( $prev['product_id'], $prev['quantity'], $session );
                    }
                    $this->rollback_cart_line( $cart, $cart_item_key, $product );
                    return;
                }
                $reserved_children[] = array(
                    'product_id' => $inner['product_id'],
                    'quantity'   => $inner['quantity'],
                );
            }
            return;
        }

        if ( ! $product->get_manage_stock() ) {
            return;
        }

        $stock = (int) $product->get_stock_quantity();
        if ( ! $this->try_reserve( $pid, $qty, $session, $stock ) ) {
            $this->rollback_cart_line( $cart, $cart_item_key, $product );
        }
    }

    /**
     * Effective GET_LOCK acquisition timeout in seconds, filterable.
     *
     * Five seconds matches the previous practical FOR UPDATE wait for hot
     * SKUs without being long enough to wedge a PHP-FPM worker behind a
     * runaway peer.
     */
    private function lock_timeout_seconds(): int {
        $timeout = (int) apply_filters( 'tejcart_stock_reservation_lock_timeout', 5 );
        return $timeout > 0 ? $timeout : 5;
    }

    /**
     * Atomically reserve stock for a single (product, session).
     *
     * Serialises concurrent reservations of the **same** product via a
     * MySQL/MariaDB named lock (`GET_LOCK('tejcart_res_<id>')`) rather
     * than a `SELECT … FOR UPDATE` against the reservation index range.
     * The previous transactional FOR UPDATE took an InnoDB row-range
     * lock that, on a flash-sale hot SKU with thousands of concurrent
     * add-to-carts, queued all attempts behind a single lock and timed
     * the tail out as false "sold out". Named locks are per-key, so
     * different products don't contend at all, and the connection-scoped
     * release-on-close guarantees no leak if PHP dies mid-flight.
     *
     * Backorders: products that allow backorders skip the cap entirely;
     * the reservation row is still recorded for visibility and so the
     * customer's hold expires on the same TTL as everything else.
     *
     * Audit F-003 (multi-writer caveat): MySQL `GET_LOCK` is node-scoped.
     * On a Galera/PXC cluster or Aurora multi-writer that spreads writes
     * across nodes, two concurrent add-to-carts for the same SKU landing on
     * different nodes will NOT serialise here, so the cart-level cap is
     * best-effort on those topologies. This is intentionally not a hard
     * guarantee: the authoritative oversell guard is the
     * `SELECT ... FOR UPDATE` in {@see Order_Stock::reduce_in_transaction()},
     * which Galera certifies at commit and is cluster-consistent — so a
     * cart reservation may briefly over-promise but an order can never
     * finalise an oversell. See docs/observability.md.
     *
     * @param int    $product_id Product ID.
     * @param int    $qty        Quantity to add to the reservation.
     * @param string $session    Session token (see session_id()).
     * @param int    $stock      Authoritative on-hand stock_quantity.
     * @return bool True if reserved (or backorder allowed), false on race loss / DB error.
     */
    private function try_reserve( int $product_id, int $qty, string $session, int $stock ): bool {
        global $wpdb;

        $expires = gmdate( 'Y-m-d H:i:s', time() + self::get_ttl() );
        $now     = current_time( 'mysql', true );
        $table   = $this->table();
        $lock    = 'tejcart_res_' . $product_id;

        $product   = \TejCart\Product\Product_Factory::get_product( $product_id );
        $unlimited = $product
            && method_exists( $product, 'backorders_allowed' )
            && $product->backorders_allowed();

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $got_lock = (int) $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
            $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, $this->lock_timeout_seconds() )
        );

        if ( 1 !== $got_lock ) {
            // GET_LOCK returned 0 (timeout) or NULL (error). Treat both as
            // a race loss so the cart line is rolled back rather than
            // letting a silent oversell slip through under contention.
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( 'Stock reservation: GET_LOCK(%s) returned %d.', $lock, $got_lock ),
                    'warning'
                );
            }
            return false;
        }

        try {
            if ( ! $unlimited ) {
                // Expired rows are filtered out by the WHERE clause; bulk
                // pruning runs on the hourly cron. With the named lock
                // held no other connection can write reservation rows for
                // this product, so a plain SELECT here sees a consistent
                // view without needing FOR UPDATE.
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
                $sql_reserved    = $wpdb->prepare( "SELECT COALESCE(SUM(qty), 0) FROM {$table} WHERE product_id = %d AND session_id != %s AND expires_at > %s", $product_id, $session, $now );
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; safe to execute.
                $reserved_others = (int) $wpdb->get_var( $sql_reserved );

                if ( $reserved_others + $qty > $stock ) {
                    return false;
                }
            }

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- custom-table query; table identifier is a controlled internal value; runtime values bound via prepare().
            $sql_insert = $wpdb->prepare( "INSERT INTO {$table} (product_id, session_id, qty, expires_at) VALUES (%d, %s, %d, %s) ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), expires_at = VALUES(expires_at)", $product_id, $session, $qty, $expires );
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,PluginCheck.Security.DirectDB.UnescapedDBParameter -- prepared above; safe to execute.
            $wpdb->query( $sql_insert );

            return true;
        } catch ( \Throwable $e ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Stock reservation failed: ' . $e->getMessage(), 'error' );
            }
            return false;
        } finally {
            // RELEASE_LOCK is connection-scoped, so the lock would auto-
            // release on connection close even if we threw — but the
            // long-lived FPM worker model means the same connection
            // services the next request, so we MUST release explicitly.
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
            $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
                $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock )
            );
        }
    }

    /**
     * Roll back a cart line that we couldn't reserve stock for, and surface
     * a "sold out" notice so the customer isn't left with a phantom item
     * that quietly disappears at checkout.
     *
     * @param Cart|null $cart          Cart instance.
     * @param string    $cart_item_key Cart line key to remove.
     * @param object    $product       Product instance, for the notice text.
     */
    private function rollback_cart_line( $cart, string $cart_item_key, $product ): void {
        $name = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
        $msg  = $name
            ? sprintf(
                /* translators: %s: product name */
                __( 'Sorry, %s just sold out and could not be added to your cart.', 'tejcart' ),
                $name
            )
            : __( 'Sorry, that item just sold out and could not be added to your cart.', 'tejcart' );

        if ( $cart && method_exists( $cart, 'add_notice' ) ) {
            $cart->add_notice( $msg, 'error' );
        }

        if ( $cart && method_exists( $cart, 'remove' ) ) {
            $cart->remove( $cart_item_key );
        }

        /**
         * Fires when an atomic stock reservation lost a race with another
         * shopper and the cart line had to be rolled back.
         *
         * Listeners can attach the message to a notice queue, push it to a
         * toast, or log/alert on it.
         *
         * @param string $message       Customer-facing sold-out message.
         * @param object $product       Product that sold out.
         * @param string $cart_item_key Cart line key that was rolled back.
         */
        do_action( 'tejcart_stock_reservation_failed', $msg, $product, $cart_item_key );

        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf(
                    'Stock reservation race lost for product #%d; cart line %s rolled back.',
                    method_exists( $product, 'get_id' ) ? (int) $product->get_id() : 0,
                    $cart_item_key
                ),
                'warning'
            );
        }
    }

    /**
     * Adjust the held reservation when a cart line's quantity changes.
     *
     * Without this, a customer who adds 10 units and then drops to 2 keeps
     * 8 units locked away from other shoppers until the TTL expires.
     *
     * Increases use try_reserve() so the bump is rejected (and the cart line
     * reverted) if the extra units would oversell. Decreases just trim the
     * stored qty.
     *
     * Hook signature: ($cart_item_key, $new_qty, $previous_qty, $cart).
     *
     * @param string    $cart_item_key Cart line key.
     * @param int       $new_qty       New quantity.
     * @param int       $previous_qty  Quantity before the update.
     * @param Cart|null $cart          Cart instance, used to revert on race loss.
     */
    public function on_quantity_changed( $cart_item_key, $new_qty, $previous_qty, $cart = null ): void {
        $delta = (int) $new_qty - (int) $previous_qty;
        if ( 0 === $delta ) {
            return;
        }

        $session = $this->session_id();
        if ( '' === $session ) {
            return;
        }

        $item = ( $cart && method_exists( $cart, 'get_item' ) ) ? $cart->get_item( $cart_item_key ) : null;
        if ( ! $item || ! method_exists( $item, 'get_product_id' ) ) {
            return;
        }

        $pid     = (int) $item->get_product_id();
        $product = \TejCart\Product\Product_Factory::get_product( $pid );
        if ( ! $product ) {
            return;
        }

        if ( $product instanceof \TejCart\Product\Product_Types\Bundle_Product ) {
            foreach ( $this->expand_bundle_items( $product, abs( $delta ) ) as $inner ) {
                if ( ! $inner['manage_stock'] ) {
                    continue;
                }
                if ( $delta > 0 ) {
                    if ( ! $this->try_reserve( $inner['product_id'], $inner['quantity'], $session, $inner['stock'] ) ) {
                        $this->revert_quantity( $cart, $cart_item_key, (int) $previous_qty, $product );
                        return;
                    }
                } else {
                    $this->decrement_reservation( $inner['product_id'], $inner['quantity'], $session );
                }
            }
            return;
        }

        if ( ! $product->get_manage_stock() ) {
            return;
        }

        if ( $delta > 0 ) {
            $stock = (int) $product->get_stock_quantity();
            if ( ! $this->try_reserve( $pid, $delta, $session, $stock ) ) {
                $this->revert_quantity( $cart, $cart_item_key, (int) $previous_qty, $product );
            }
        } else {
            $this->decrement_reservation( $pid, abs( $delta ), $session );
        }
    }

    /**
     * Subtract qty from a (product, session) reservation row, clamped at zero.
     */
    private function decrement_reservation( int $product_id, int $qty, string $session ): void {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "UPDATE {$this->table()}
                    SET qty = GREATEST(0, qty - %d)
                  WHERE product_id = %d AND session_id = %s",
                $qty,
                $product_id,
                $session
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Roll back a quantity bump that lost the stock race by snapping the
     * cart line back to its previous quantity and surfacing a notice.
     */
    private function revert_quantity( $cart, string $cart_item_key, int $previous_qty, $product ): void {
        if ( $cart && method_exists( $cart, 'get_item' ) ) {
            $item = $cart->get_item( $cart_item_key );
            if ( $item && method_exists( $item, 'set_quantity' ) ) {
                $item->set_quantity( max( 1, $previous_qty ) );
            }
        }

        $name = method_exists( $product, 'get_name' ) ? (string) $product->get_name() : '';
        $msg  = $name
            ? sprintf(
                /* translators: %s: product name */
                __( 'Sorry, only the previous quantity of %s is still available.', 'tejcart' ),
                $name
            )
            : __( 'Sorry, the requested quantity is no longer available.', 'tejcart' );

        if ( $cart && method_exists( $cart, 'add_notice' ) ) {
            $cart->add_notice( $msg, 'warning' );
        }

        /** @see rollback_cart_line() — same hook, different cause. */
        do_action( 'tejcart_stock_reservation_failed', $msg, $product, $cart_item_key );
    }

    /**
     * Release a reservation when an item is removed from the cart.
     *
     * Hook signature: ($cart_item_key, $item).
     */
    public function on_remove( $cart_item_key, $item = null ): void {
        if ( ! $item || ! method_exists( $item, 'get_product_id' ) ) {
            return;
        }

        global $wpdb;
        $session = $this->session_id();
        $pid     = (int) $item->get_product_id();

        $product = \TejCart\Product\Product_Factory::get_product( $pid );

        if ( $product instanceof \TejCart\Product\Product_Types\Bundle_Product ) {
            $qty = is_callable( array( $item, 'get_quantity' ) ) ? (int) $item->get_quantity() : 1;
            foreach ( $this->expand_bundle_items( $product, $qty ) as $inner ) {
                if ( ! $inner['manage_stock'] ) {
                    continue;
                }
                // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( $wpdb->prepare(
                    "UPDATE {$this->table()}
                       SET qty = GREATEST(0, qty - %d)
                     WHERE product_id = %d AND session_id = %s",
                    $inner['quantity'],
                    $inner['product_id'],
                    $session
                ) );
                // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            }
            return;
        }

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->delete(
            $this->table(),
            array(
                'product_id' => $pid,
                'session_id' => $session,
            ),
            array( '%d', '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Reserve stock against an Order rather than a cart session.
     *
     * Used by PayPal Express, where the buyer never goes through the cart
     * page: a `pending` order is minted directly from a single product (or
     * the live cart) and must hold its inventory until the wallet sheet
     * either captures or is abandoned. The reservation is keyed by a
     * synthetic `order_<id>` session token so it lives independently of the
     * buyer's regular cart_session_key (which keeps holding their other
     * cart items).
     *
     * Returns true when every line was reserved successfully, false when
     * any line lost the race against another shopper.
     *
     * @param int   $order_id
     * @param array $items List of [product_id, quantity, ...] entries.
     */
    public function reserve_for_order( int $order_id, array $items ): bool {
        if ( $order_id <= 0 || empty( $items ) ) {
            return true;
        }

        $session = self::order_session_token( $order_id );

        foreach ( $items as $item ) {
            $pid = (int) ( is_array( $item ) ? ( $item['product_id'] ?? 0 ) : 0 );
            $qty = (int) ( is_array( $item ) ? ( $item['quantity']   ?? 0 ) : 0 );
            if ( $pid <= 0 || $qty <= 0 ) {
                continue;
            }
            $product = \TejCart\Product\Product_Factory::get_product( $pid );
            if ( ! $product || ! method_exists( $product, 'get_manage_stock' ) || ! $product->get_manage_stock() ) {
                continue;
            }
            $stock = (int) $product->get_stock_quantity();
            if ( ! $this->try_reserve( $pid, $qty, $session, $stock ) ) {
                // F-CCM-009: one item lost the race — release every reservation already
                // established in this loop so that stock is not artificially locked against
                // other shoppers until the TTL expires (mirrors the cart-level rollback in
                // try_reserve). A single DELETE by session_id frees all previously-reserved
                // rows atomically.
                $this->release_for_order( $order_id );
                return false;
            }
        }

        return true;
    }

    /**
     * Release every reservation tagged with the order-session token.
     *
     * Hook signature: ($order_id, $order = null).
     */
    public function release_for_order( $order_id, $order = null ): void {
        $oid = (int) $order_id;
        if ( $oid <= 0 ) {
            return;
        }

        global $wpdb;
        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->delete(
            $this->table(),
            array( 'session_id' => self::order_session_token( $oid ) ),
            array( '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Decrement an order-bound reservation by a partial quantity, deleting
     * the row when it reaches zero.
     *
     * Called from Order_Refund::restock_items() so a partial refund frees the
     * proportional slice of reserved stock at the same moment the products
     * table is restocked. Without this the inventory window between refund
     * and TTL prune (default 30 minutes) under-counts available stock by the
     * refunded quantity. Order-bound reservations live under
     * order_session_token($order_id) — see reserve_for_order().
     */
    public function release_quantity_for_order( int $order_id, int $product_id, int $qty ): void {
        if ( $order_id <= 0 || $product_id <= 0 || $qty <= 0 ) {
            return;
        }

        global $wpdb;
        $table   = $this->table();
        $session = self::order_session_token( $order_id );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        // Decrement first; if it underflows to <= 0 the cleanup DELETE drops
        // the row. Doing this in two statements is fine because both predicates
        // include the unique (session_id, product_id) tuple, so concurrent
        // partial refunds on disjoint rows do not contend, and the same-row
        // race converges (both decrements land, the first DELETE wins, the
        // second is a no-op against an empty rowset).
        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "UPDATE {$table}
                    SET qty = GREATEST(qty - %d, 0)
                  WHERE session_id = %s
                    AND product_id = %d",
                $qty,
                $session,
                $product_id
            )
        );

        $wpdb->query(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "DELETE FROM {$table}
                  WHERE session_id = %s
                    AND product_id = %d
                    AND qty <= 0",
                $session,
                $product_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Synthetic session-id used for order-bound reservations. Stays stable
     * across the order's lifetime so `reserve_for_order` and
     * `release_for_order` always agree on the row.
     */
    public static function order_session_token( int $order_id ): string {
        return 'order_' . $order_id;
    }

    /**
     * Drop every reservation tagged with the current session.
     */
    public function release_session(): void {
        global $wpdb;

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->delete(
            $this->table(),
            array( 'session_id' => $this->session_id() ),
            array( '%s' )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
    }

    /**
     * Sum of active reservations for a product, excluding the current session.
     *
     * Expired rows are excluded by the WHERE clause; bulk cleanup happens on
     * the `tejcart_stock_reservation_prune` hourly cron so this method is a
     * single index-scan SELECT regardless of how many expired rows exist.
     */
    public function reserved_by_others( int $product_id ): int {
        global $wpdb;

        $table = $this->table();
        $now   = current_time( 'mysql', true );

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sum = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COALESCE(SUM(qty), 0)
                   FROM {$table}
                  WHERE product_id = %d
                    AND session_id != %s
                    AND expires_at > %s",
                $product_id,
                $this->session_id(),
                $now
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $sum;
    }

    /**
     * Stable session token for the current customer.
     */
    private function session_id(): string {
        if ( is_user_logged_in() ) {
            return 'u_' . get_current_user_id();
        }

        if ( ! empty( $_COOKIE['tejcart_session'] ) ) {
            return 's_' . sanitize_text_field( wp_unslash( $_COOKIE['tejcart_session'] ) );
        }

        // Route through Rate_Limiter::get_client_ip() so the
        // trusted-proxy gate applies — bucketing under a raw REMOTE_ADDR
        // behind a misconfigured load balancer collapses all guests onto
        // a single IP bucket (audit 04 L-6).
        $ip = '';
        if ( class_exists( '\\TejCart\\Security\\Rate_Limiter' )
            && method_exists( '\\TejCart\\Security\\Rate_Limiter', 'get_client_ip' )
        ) {
            $ip = (string) \TejCart\Security\Rate_Limiter::get_client_ip();
        }
        if ( '' === $ip && isset( $_SERVER['REMOTE_ADDR'] ) ) {
            $ip = sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) );
        }
        return 'ip_' . md5( $ip );
    }
}
