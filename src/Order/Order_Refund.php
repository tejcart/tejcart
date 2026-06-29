<?php
/**
 * Order Refund data object.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Represents a single refund record.
 *
 * Refunds are persisted as one row per refund in
 * {prefix}tejcart_order_refunds. A UNIQUE KEY on transaction_ref makes
 * gateway-driven refunds idempotent: replaying the same external
 * reference (e.g. PayPal capture refund id) cannot create a duplicate.
 */
class Order_Refund {
    /** @var int */
    public $id = 0;

    /** @var int */
    public $order_id = 0;

    /**
     * External transaction reference (gateway refund id). Optional but
     * REQUIRED to be unique when present.
     *
     * @var string
     */
    public $transaction_ref = '';

    /** @var float */
    public $amount = 0.0;

    /** @var string */
    public $reason = '';

    /**
     * Refunded line items: [{order_item_id, quantity, amount}].
     *
     * @var array
     */
    public $items = array();

    /** @var string */
    public $date = '';

    /** @var int */
    public $refunded_by = 0;

    /**
     * @param array $data Optional. Refund data to populate properties.
     */
    public function __construct( $data = array() ) {
        if ( ! empty( $data ) ) {
            $this->id              = isset( $data['id'] ) ? (int) $data['id'] : 0;
            $this->order_id        = isset( $data['order_id'] ) ? absint( $data['order_id'] ) : 0;
            $this->transaction_ref = isset( $data['transaction_ref'] ) ? sanitize_text_field( $data['transaction_ref'] ) : '';
            $this->amount          = isset( $data['amount'] ) ? (float) $data['amount'] : 0.0;
            $this->reason          = isset( $data['reason'] ) ? sanitize_text_field( $data['reason'] ) : '';
            $this->items           = isset( $data['items'] ) && is_array( $data['items'] ) ? $data['items'] : array();
            $this->date            = isset( $data['date'] ) ? sanitize_text_field( $data['date'] ) : '';
            $this->refunded_by     = isset( $data['refunded_by'] ) ? absint( $data['refunded_by'] ) : 0;
        }
    }

    /**
     * Table name resolved at call time.
     */
    private static function table(): string {
        global $wpdb;
        return $wpdb->prefix . 'tejcart_order_refunds';
    }

    /**
     * Insert the refund row idempotently.
     *
     * If a transaction_ref is provided and a row with that reference
     * already exists, the existing row is loaded into $this and the
     * call returns true (duplicate suppressed). Stock is NOT restocked
     * a second time on duplicate inserts.
     *
     * @return bool True on insert OR duplicate-detected; false on hard failure.
     */
    public function save() {
        global $wpdb;

        if ( ! $this->order_id ) {
            return false;
        }

        if ( empty( $this->date ) ) {
            $this->date = current_time( 'mysql' );
        }

        if ( ! $this->refunded_by ) {
            $this->refunded_by = get_current_user_id();
        }

        $table = self::table();

        // Wrap insert + restock in a single transaction so a partial failure
        // (refund row recorded but restock interrupted) cannot leave the
        // products table out of sync with the refund ledger. The UNIQUE KEY
        // on transaction_ref still guarantees that concurrent webhook
        // replays for the same gateway refund id only execute restock once.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->query( 'START TRANSACTION' );

        try {
            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            // amount is integer minor units in the parent order's
            // currency. Callers pass float (major units) for back-compat
            // — Currency::to_minor_units does the banker's-rounded
            // conversion so KWD/BHD/OMR's third minor digit is preserved
            // and the storage column matches what the customer is
            // actually charged.
            $amount_minor = $this->resolve_amount_minor();

            // Consolidated base-currency mirror of the refund amount, using
            // the parent order's stamped fx_rate, so the Refunds report and
            // net-revenue aggregate in the store base currency. Identity for
            // single-currency stores.
            $fx_order      = Order_Factory::get_order( $this->order_id );
            $txn_currency  = $fx_order ? (string) $fx_order->get_currency() : self::order_currency( $this->order_id );
            $base_currency = ( $fx_order && method_exists( $fx_order, 'get_base_currency' ) ) ? $fx_order->get_base_currency() : $txn_currency;
            $fx_rate       = ( $fx_order && method_exists( $fx_order, 'get_fx_rate' ) ) ? $fx_order->get_fx_rate() : 1.0;
            $base_amount_minor = \TejCart\Money\Currency::to_base_minor( $amount_minor, $txn_currency, $base_currency, $fx_rate );

            $inserted = $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "INSERT IGNORE INTO {$table}
                        (order_id, transaction_ref, amount, base_amount, reason, items, refunded_by, created_at)
                     VALUES (%d, %s, %d, %d, %s, %s, %d, %s)",
                    $this->order_id,
                    $this->transaction_ref !== '' ? $this->transaction_ref : null,
                    $amount_minor,
                    $base_amount_minor,
                    $this->reason,
                    wp_json_encode( $this->items ),
                    $this->refunded_by,
                    $this->date
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            if ( false === $inserted ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( 'ROLLBACK' );
                return false;
            }

            if ( 0 === (int) $inserted && '' !== $this->transaction_ref ) {
                // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                $wpdb->query( 'COMMIT' );

                $existing = self::get_by_transaction_ref( $this->transaction_ref );
                if ( $existing ) {
                    $this->id          = $existing->id;
                    $this->order_id    = $existing->order_id;
                    $this->amount      = $existing->amount;
                    $this->reason      = $existing->reason;
                    $this->items       = $existing->items;
                    $this->date        = $existing->date;
                    $this->refunded_by = $existing->refunded_by;
                }
                return true;
            }

            $this->id = (int) $wpdb->insert_id;

            $this->restock_items();

            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'COMMIT' );

            return true;
        } catch ( \Throwable $e ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query( 'ROLLBACK' );
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log( 'Order_Refund::save failed: ' . $e->getMessage(), 'error' );
            }
            return false;
        }
    }

    /**
     * Restore stock for each refunded line item.
     *
     * Increments {prefix}tejcart_products.stock_quantity by the refunded
     * quantity in a single atomic UPDATE per item. Skips products that
     * do not manage stock. Also releases the proportional slice of any
     * order-bound reservation row so the inventory window between refund
     * and TTL prune does not under-count available stock.
     */
    protected function restock_items(): void {
        if ( empty( $this->items ) || ! is_array( $this->items ) ) {
            return;
        }

        global $wpdb;

        $order = Order_Factory::get_order( $this->order_id );
        if ( ! $order ) {
            return;
        }

        $product_map = array();
        foreach ( $order->get_items() as $item ) {
            $iid = isset( $item->id ) ? (int) $item->id : 0;
            $pid = isset( $item->product_id ) ? (int) $item->product_id : 0;
            if ( $iid && $pid ) {
                $product_map[ $iid ] = $pid;
            }
        }

        $products_table = $wpdb->prefix . 'tejcart_products';

        $items = $this->items;
        usort(
            $items,
            static function ( $a, $b ) use ( $product_map ) {
                $a_id = $product_map[ (int) ( $a['order_item_id'] ?? 0 ) ] ?? 0;
                $b_id = $product_map[ (int) ( $b['order_item_id'] ?? 0 ) ] ?? 0;
                return $a_id <=> $b_id;
            }
        );

        $reservations = new \TejCart\Cart\Stock_Reservation();

        foreach ( $items as $line ) {
            $iid = isset( $line['order_item_id'] ) ? (int) $line['order_item_id'] : 0;
            $qty = isset( $line['quantity'] ) ? (int) $line['quantity'] : 0;

            if ( ! $iid || $qty <= 0 || empty( $product_map[ $iid ] ) ) {
                continue;
            }

            $pid = $product_map[ $iid ];

            // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $wpdb->query(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare(
                    "UPDATE {$products_table}
                        SET stock_quantity = stock_quantity + %d,
                            stock_status   = CASE WHEN manage_stock = 1 AND stock_quantity + %d > 0
                                                  THEN 'instock' ELSE stock_status END
                      WHERE id = %d AND manage_stock = 1",
                    $qty,
                    $qty,
                    $pid
                )
            );
            // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

            $reservations->release_quantity_for_order( (int) $this->order_id, $pid, $qty );

            // Audit H-16 (Cart F-002): record that this item-line has
            // been restocked by a partial refund so
            // Order_Stock::restore_decrement() (which fires on full-
            // refund status transition) only restocks the REMAINING
            // quantity and doesn't double-add what we already restored.
            $restocked_map = (array) tejcart_get_order_meta( (int) $this->order_id, '_tejcart_partial_restocked_qty' );
            $restocked_map[ $iid ] = ( $restocked_map[ $iid ] ?? 0 ) + $qty;
            tejcart_update_order_meta( (int) $this->order_id, '_tejcart_partial_restocked_qty', $restocked_map );

            /**
             * Fires after a refunded line item has been restocked.
             *
             * @param int $product_id Product ID.
             * @param int $qty        Quantity restored.
             * @param int $order_id   Order ID.
             */
            do_action( 'tejcart_refund_restocked_item', $pid, $qty, $this->order_id );
        }
    }

    /**
     * Hydrate an Order_Refund from a DB row.
     *
     * The DB column is BIGINT minor units in the parent order's
     * currency. We convert to a major-unit float for the back-compat
     * `$amount` property; callers that need exact integer cents should
     * use {@see get_amount_money()} or {@see get_amount_minor()}.
     */
    private static function from_row( $row ): self {
        $items = array();
        if ( ! empty( $row->items ) ) {
            $decoded = json_decode( $row->items, true );
            if ( is_array( $decoded ) ) {
                $items = $decoded;
            }
        }

        $order_id      = (int) $row->order_id;
        $minor         = (int) $row->amount;
        $currency      = self::order_currency( $order_id );
        $multi         = max( 1, (int) \TejCart\Money\Currency::multiplier( $currency ) );
        $amount_major  = $minor / $multi;

        $instance = new self( array(
            'id'              => (int) $row->id,
            'order_id'        => $order_id,
            'transaction_ref' => (string) ( $row->transaction_ref ?? '' ),
            'amount'          => $amount_major,
            'reason'          => (string) $row->reason,
            'items'           => $items,
            'date'            => (string) $row->created_at,
            'refunded_by'     => (int) $row->refunded_by,
        ) );
        $instance->amount_minor   = $minor;
        $instance->amount_currency = $currency;
        return $instance;
    }

    /**
     * Cached integer-minor-units mirror of $amount when hydrated from
     * the DB. Stays null on freshly-constructed refunds (the float
     * $amount is the only input then; resolved on save).
     *
     * @var int|null
     */
    private ?int $amount_minor = null;

    /**
     * Currency the cached $amount_minor is denominated in.
     *
     * @var string|null
     */
    private ?string $amount_currency = null;

    /**
     * Look up the parent order's currency once, defaulting to the shop
     * currency if the order row can't be resolved (e.g. read on a not-
     * yet-saved refund or against a deleted order).
     */
    private static function order_currency( int $order_id ): string {
        if ( $order_id > 0 ) {
            $order = Order_Factory::get_order( $order_id );
            if ( $order ) {
                $c = (string) $order->get_currency();
                if ( '' !== $c ) {
                    return strtoupper( $c );
                }
            }
        }
        if ( function_exists( 'tejcart_get_currency' ) ) {
            return strtoupper( (string) tejcart_get_currency() );
        }
        if ( function_exists( 'get_option' ) ) {
            return strtoupper( (string) get_option( 'tejcart_currency', 'USD' ) );
        }
        return 'USD';
    }

    /**
     * Resolve the refund amount as integer minor units, preferring the
     * mirror stored on hydration over a fresh conversion from the float.
     */
    private function resolve_amount_minor(): int {
        if ( null !== $this->amount_minor ) {
            return $this->amount_minor;
        }
        $currency = self::order_currency( (int) $this->order_id );
        return (int) \TejCart\Money\Currency::to_minor_units( (float) $this->amount, $currency );
    }

    /**
     * Refund amount as Money in the parent order's currency.
     */
    public function get_amount_money(): \TejCart\Money\Money {
        $minor    = $this->resolve_amount_minor();
        $currency = $this->amount_currency ?? self::order_currency( (int) $this->order_id );
        return \TejCart\Money\Money::from_minor_units( $minor, $currency );
    }

    /**
     * Raw integer minor units of this refund. Use in arithmetic where
     * float precision would matter (refund caps, ledger reconciliation).
     */
    public function get_amount_minor(): int {
        return $this->resolve_amount_minor();
    }

    /**
     * Look up a refund by external transaction reference.
     */
    public static function get_by_transaction_ref( string $transaction_ref ): ?self {
        if ( '' === $transaction_ref ) {
            return null;
        }

        global $wpdb;
        $table = self::table();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $row = $wpdb->get_row(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE transaction_ref = %s LIMIT 1",
                $transaction_ref
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return $row ? self::from_row( $row ) : null;
    }

    /**
     * Get all refunds for an order, newest first.
     *
     * @param int $order_id Order ID.
     * @return Order_Refund[]
     */
    public static function get_refunds( $order_id ) {
        global $wpdb;

        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return array();
        }

        $table = self::table();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT * FROM {$table} WHERE order_id = %d ORDER BY created_at DESC, id DESC",
                $order_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        $refunds = array();
        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $refunds[] = self::from_row( $row );
            }
        }

        return $refunds;
    }

    /**
     * Count refund rows already persisted for an order.
     *
     * Used by gateway adapters to build a monotonic refund-attempt counter
     * for their idempotency keys: two legitimate same-amount partial refunds
     * on the same order produced the same key under the old
     * `refund_<order_id>_<amount_minor>` scheme and Stripe / Auth.Net
     * returned the cached first response — the second refund silently
     * no-op'd while the merchant believed it succeeded.
     */
    public static function count_for_order( $order_id ): int {
        global $wpdb;

        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return 0;
        }

        $table = self::table();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $count = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE order_id = %d",
                $order_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $count;
    }

    /**
     * Get total amount refunded for an order via a single SQL aggregate.
     *
     * @param int $order_id Order ID.
     * @return float
     */
    public static function get_total_refunded( $order_id ) {
        $minor = self::get_total_refunded_minor( (int) $order_id );
        if ( 0 === $minor ) {
            return 0.0;
        }
        $currency = self::order_currency( (int) $order_id );
        $multi    = max( 1, (int) \TejCart\Money\Currency::multiplier( $currency ) );
        return $minor / $multi;
    }

    /**
     * Total refunded for an order in integer minor units. SUM aggregates
     * a BIGINT column so the result is exact — no float drift across N
     * partial refunds. Preferred over {@see get_total_refunded()} in any
     * arithmetic context.
     */
    public static function get_total_refunded_minor( int $order_id ): int {
        global $wpdb;

        $order_id = absint( $order_id );
        if ( ! $order_id ) {
            return 0;
        }

        $table = self::table();

        // phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $sum = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare(
                "SELECT COALESCE(SUM(amount), 0) FROM {$table} WHERE order_id = %d",
                $order_id
            )
        );
        // phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter

        return (int) $sum;
    }

    /**
     * Remaining refundable amount in integer minor units, against the
     * order's stored currency.
     *
     * Use this anywhere you need to compare a proposed refund amount
     * against what the order can still take — controllers, gateway
     * adapters, REST endpoints, admin handlers. L-2 (see review):
     * three call sites used to do this with a `+ 0.0001` float
     * tolerance and slightly different error messages; now they all
     * route through this helper for a single source of truth.
     *
     * @param int    $order_id   Order ID.
     * @param string $currency   ISO 4217 (uppercase).
     * @param float  $order_total Order total (major units).
     * @return int Minor units; never negative.
     */
    public static function remaining_refundable_minor( int $order_id, string $currency, float $order_total ): int {
        $consumed_minor = self::get_total_refunded_minor( $order_id );
        $allowed_minor  = (int) \TejCart\Money\Currency::to_minor_units( $order_total, $currency );
        return max( 0, $allowed_minor - $consumed_minor );
    }
}
