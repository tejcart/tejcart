<?php
/**
 * Order Manager.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides order querying, counting, deletion, and refund processing.
 */
class Order_Manager {
    /**
     * Query orders with filtering and pagination.
     *
     * @param array $args {
     *     Optional. Query arguments.
     *
     *     @type string $status         Filter by order status.
     *     @type string $customer_email Filter by customer email.
     *     @type int    $customer_id    Filter by customer ID.
     *     @type string $date_from      Filter orders created on or after this date (Y-m-d).
     *     @type string $date_to        Filter orders created on or before this date (Y-m-d).
     *     @type int    $per_page       Number of orders per page. Default 20.
     *     @type int    $page           Page number. Default 1.
     *     @type string $orderby        Column to sort by. Default 'created_at'.
     *     @type string $order          Sort direction. Default 'DESC'.
     * }
     * @return Order[] Array of Order objects.
     */
    public function get_orders( $args = array() ) {
        global $wpdb;

        $defaults = array(
            'status'         => '',
            'customer_email' => '',
            'customer_id'    => 0,
            'date_from'      => '',
            'date_to'        => '',
            'per_page'       => 20,
            'page'           => 1,
            'orderby'        => 'created_at',
            'order'          => 'DESC',
        );

        $args  = wp_parse_args( $args, $defaults );
        $table = $wpdb->prefix . 'tejcart_orders';

        $where  = array( '1=1' );
        $values = array();

        if ( ! empty( $args['status'] ) ) {
            $where[]  = 'status = %s';
            $values[] = $args['status'];
        }

        if ( ! empty( $args['customer_email'] ) ) {
            $where[]  = 'customer_email = %s';
            $values[] = $args['customer_email'];
        }

        if ( ! empty( $args['customer_id'] ) ) {
            $where[]  = 'customer_id = %d';
            $values[] = absint( $args['customer_id'] );
        }

        if ( ! empty( $args['date_from'] ) ) {
            $where[]  = 'created_at >= %s';
            $values[] = $args['date_from'] . ' 00:00:00';
        }

        if ( ! empty( $args['date_to'] ) ) {
            $where[]  = 'created_at <= %s';
            $values[] = $args['date_to'] . ' 23:59:59';
        }

        $allowed_orderby = array( 'id', 'created_at', 'updated_at', 'total', 'status', 'order_number' );
        $orderby         = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'created_at';
        $order           = strtoupper( $args['order'] ) === 'ASC' ? 'ASC' : 'DESC';

        $per_page = absint( $args['per_page'] );
        $page     = absint( $args['page'] );
        $offset   = ( $page - 1 ) * $per_page;

        $where_clause = implode( ' AND ', $where );

        $sql = "SELECT * FROM {$table} WHERE {$where_clause} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d";
        $values[] = $per_page;
        $values[] = $offset;

        if ( count( $values ) > 0 ) {
            // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $prepared = $wpdb->prepare( $sql, $values );
        } else {
            $prepared = $sql;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $rows = $wpdb->get_results( $prepared, ARRAY_A );

        $orders = array();

        if ( is_array( $rows ) ) {
            foreach ( $rows as $row ) {
                $orders[] = Order::from_data( $row );
            }
        }

        return $orders;
    }

    /**
     * Count orders, optionally filtered by status.
     *
     * @param string $status Optional. Status to filter by.
     * @return int Order count.
     */
    public function get_order_count( $status = '' ) {
        global $wpdb;

        $table = $wpdb->prefix . 'tejcart_orders';

        if ( ! empty( $status ) ) {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $count = $wpdb->get_var(
                // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
                $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE status = %s", $status )
            );
        } else {
            // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
            $count = $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );
        }

        return (int) $count;
    }

    /**
     * Get all registered order statuses.
     *
     * @return array Associative array of slug => label.
     */
    public function get_order_statuses() {
        // The `tejcart_order_statuses` filter is now applied inside
        // Order_Status::get_statuses() so that validation, transitions and
        // this selection list all see the identical extended set. Delegating
        // here (rather than re-applying the filter) avoids running listeners
        // twice — which would double-append for any non-idempotent callback.
        return Order_Status::get_statuses();
    }

    /**
     * Delete an order and its related items and meta.
     *
     * @param int $id Order ID.
     * @return bool True on success, false on failure.
     */
    public function delete_order( $id ) {
        global $wpdb;

        $id = absint( $id );

        if ( ! $id ) {
            return false;
        }

        $items_table = $wpdb->prefix . 'tejcart_order_items';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->delete( $items_table, array( 'order_id' => $id ), array( '%d' ) );

        $meta_table = $wpdb->prefix . 'tejcart_order_meta';
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
        $wpdb->delete( $meta_table, array( 'order_id' => $id ), array( '%d' ) );

        $order = new Order( $id );

        if ( ! $order->get_id() ) {
            return false;
        }

        return $order->delete();
    }

    /**
     * Process a refund for an order.
     *
     * The gateway call and the local refund record must move together.
     * If the gateway succeeds (money leaves the merchant account) and
     * the local INSERT then fails, every subsequent cap check at line
     * {@see Order_Refund::get_total_refunded()} will under-report the
     * already-refunded total, and a second well-meaning refund attempt
     * will pass the cap check and double-refund the customer.
     *
     * Defenses applied here:
     *
     * 1. **Per-order advisory lock** via `GET_LOCK("tejcart_refund:{id}")`
     *    serialises concurrent refund requests for the same order so
     *    the cap re-read inside the lock observes any other in-flight
     *    refund's effect.
     *
     * 2. **Reconciliation marker** — if the gateway returns success but
     *    the local INSERT fails, we set order meta
     *    `_tejcart_refund_inconsistent = 1` and fire the
     *    `tejcart_refund_inconsistency` action. Operators see the flag
     *    in the admin and can reconcile manually; future refunds on
     *    that order are blocked until the flag is cleared.
     *
     * @param int    $order_id      Order ID.
     * @param float  $amount        Refund amount.
     * @param string $reason        Optional. Reason for the refund.
     * @param bool   $update_status Optional. When true (default), the order
     *                              status is auto-flipped to `refunded`
     *                              once the cumulative refunded amount
     *                              reaches the order total. Pass false from
     *                              callers (e.g. the admin form's "Mark
     *                              Refunded" toggle) that want to record a
     *                              full refund without flipping status.
     * @return bool True on success, false on failure.
     */
    public function process_refund( $order_id, $amount, $reason = '', $update_status = true ) {
        global $wpdb;

        $order_id = absint( $order_id );
        $amount   = (float) $amount;

        if ( ! $order_id || $amount <= 0 ) {
            return false;
        }

        $order = new Order( $order_id );

        if ( ! $order->get_id() ) {
            return false;
        }

        // Per-currency precision normalisation. The previous
        // `round((float) $amount, 2)` at entry truncated three-decimal
        // refunds (KWD/BHD/OMR/TND): a 12.345 KWD refund became 12.35,
        // off by 5 mils. With #1146 the PayPal-side now sends the
        // correct three-decimal value to the gateway, but the local
        // Order_Refund row and the cap-check below were stuck at 2 dp
        // until this normalisation moved to the currency-aware helper.
        $order_currency = strtoupper( (string) $order->get_currency() );
        $decimals       = 2;
        if ( class_exists( '\\TejCart\\Money\\Currency' ) && '' !== $order_currency ) {
            $decimals = max( 0, (int) \TejCart\Money\Currency::decimals( $order_currency ) );
        }
        $amount = round( $amount, $decimals );

        if ( $amount <= 0 ) {
            return false;
        }

        if ( $order->get_meta( '_tejcart_refund_inconsistent' ) ) {
            return $this->fail_refund(
                $order,
                __( 'A previous refund left this order in an inconsistent state. Reconcile it manually before refunding again.', 'tejcart' )
            );
        }

        // Refuse to refund an order that never captured funds (pending /
        // on-hold / failed / cancelled). PayPal-family captures only land
        // a `_paypal_capture_id` once the payment completes, so refunding a
        // `pending` order would round-trip to the gateway only to be
        // rejected with a confusing "No capture ID" error. Reject up front
        // with a clear log line instead. Gateway-initiated refunds (webhook
        // replays) record directly via Order_Refund and never reach here.
        if ( ! Order_Status::is_refundable( (string) $order->get_status() ) ) {
            return $this->fail_refund(
                $order,
                sprintf(
                    /* translators: %s: order status slug. */
                    __( 'Order status "%s" has no captured payment to refund.', 'tejcart' ),
                    (string) $order->get_status()
                ),
                'warning'
            );
        }

        /**
         * N-M6: veto a refund before any gateway round-trip.
         *
         * Sibling to `tejcart_pre_status_change` (F-L7). Returning false
         * — or a WP_Error — short-circuits process_refund() so audit /
         * fraud / capability listeners can block double-refunds,
         * currency-mismatch, or permission-failed refunds without
         * incurring a gateway API call.
         *
         * @param bool         $allow    Default true.
         * @param int          $order_id Order ID being refunded.
         * @param float        $amount   Refund amount (order currency).
         * @param string       $reason   Optional reason text.
         * @param \TejCart\Order\Order $order The order being refunded.
         */
        $gate = apply_filters( 'tejcart_pre_refund', true, $order_id, $amount, $reason, $order );
        if ( false === $gate || is_wp_error( $gate ) ) {
            $gate_reason = is_wp_error( $gate )
                ? $gate->get_error_message()
                : __( 'A refund guard (tejcart_pre_refund) blocked this refund.', 'tejcart' );
            return $this->fail_refund( $order, $gate_reason, 'warning' );
        }

        $lock_held = $this->acquire_refund_lock( $order_id );

        // Fail closed: the per-order advisory lock is the ONLY thing
        // serialising concurrent refunds of the same order (two admin tabs,
        // admin + webhook, admin + REST). If we proceed without it, both
        // callers read the same get_total_refunded() below, both pass the
        // cap-check, and both call the gateway — money leaves twice. A
        // double refund is unrecoverable; a blocked refund is just a retry.
        if ( ! $lock_held ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf(
                        'Refund aborted on order %d: could not acquire the per-order refund lock (another refund may be in progress). Retry shortly.',
                        $order_id
                    ),
                    'error'
                );
            }
            return false;
        }

        try {
            $already_refunded = (float) Order_Refund::get_total_refunded( $order_id );
            $order_total      = (float) $order->get_total();

            // Cap-check in integer minor units against the order's
            // currency. The previous `> $order_total + 0.0001` float
            // tolerance was unsafe in three-decimal currencies (KWD,
            // BHD, OMR) and meaningless in zero-decimal currencies
            // (JPY, KRW). Integer comparison is exact across the matrix.
            $order_currency  = strtoupper( (string) $order->get_currency() );
            $proposed_total  = \TejCart\Money\Currency::to_minor_units( $already_refunded + $amount, $order_currency );
            $allowed_total   = \TejCart\Money\Currency::to_minor_units( $order_total, $order_currency );

            if ( $proposed_total > $allowed_total ) {
                return $this->fail_refund(
                    $order,
                    __( 'The refund amount exceeds the order’s remaining refundable balance.', 'tejcart' ),
                    'warning'
                );
            }

            $gateway_call_succeeded = false;
            $gateway_refund_id      = '';
            $gateway_id             = $order->get_payment_method();
            $gateway_title          = '';
            if ( $gateway_id && function_exists( 'tejcart' ) ) {
                $gateway = tejcart()->gateways()->get_gateway( $gateway_id );
                if ( $gateway ) {
                    if ( method_exists( $gateway, 'get_method_title' ) ) {
                        $gateway_title = (string) $gateway->get_method_title();
                    } elseif ( property_exists( $gateway, 'method_title' ) ) {
                        $gateway_title = (string) $gateway->method_title;
                    }
                    if ( method_exists( $gateway, 'process_refund' ) ) {
                        $gateway_result = $gateway->process_refund( $order_id, $amount, $reason );
                        if ( is_wp_error( $gateway_result ) || false === $gateway_result ) {
                            $gateway_label = '' !== $gateway_title ? $gateway_title : (string) $gateway_id;
                            $gateway_error = is_wp_error( $gateway_result )
                                ? $gateway_result->get_error_message()
                                : __( 'the payment gateway rejected the refund', 'tejcart' );
                            return $this->fail_refund(
                                $order,
                                sprintf(
                                    /* translators: 1: gateway name, 2: error message returned by the gateway. */
                                    __( 'Gateway %1$s declined the refund: %2$s', 'tejcart' ),
                                    $gateway_label,
                                    $gateway_error
                                )
                            );
                        }
                        // Gateways may return the external refund ID as a string
                        // (preferred) or simply `true`. The string lets us
                        // capture the gateway's own refund reference on the
                        // local row so future reconciliation/idempotency works.
                        if ( is_string( $gateway_result ) && '' !== $gateway_result ) {
                            $gateway_refund_id = $gateway_result;
                        }
                        $gateway_call_succeeded = true;
                    }
                }
            }

            $refund = new Order_Refund( array(
                'order_id'        => $order_id,
                'amount'          => $amount,
                'reason'          => sanitize_text_field( $reason ),
                'transaction_ref' => $gateway_refund_id,
            ) );

            if ( ! $refund->save() ) {
                if ( $gateway_call_succeeded ) {
                    $order->update_meta( '_tejcart_refund_inconsistent', 1 );
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            sprintf(
                                'Refund inconsistency on order %d: gateway %s refunded %.2f %s (refund ID: %s) but local INSERT failed.',
                                $order_id,
                                (string) $gateway_id,
                                (float) $amount,
                                (string) $order->get_currency(),
                                '' !== $gateway_refund_id ? $gateway_refund_id : 'n/a'
                            ),
                            'error'
                        );
                    }
                    /**
                     * Fires when a refund's gateway call succeeded but
                     * the local refund record could not be saved.
                     *
                     * Listeners should alert operators (Slack, email,
                     * monitoring) so the reconciliation marker can be
                     * cleared by hand.
                     *
                     * @param int    $order_id   Order ID.
                     * @param float  $amount     Refund amount that already
                     *                           moved at the gateway.
                     * @param string $gateway_id Gateway that processed it.
                     */
                    do_action( 'tejcart_refund_inconsistency', $order_id, $amount, (string) $gateway_id );
                }
                return false;
            }

            // "Fully refunded" check uses integer minor units too —
            // see comment on the cap check above. Below the full-refund
            // threshold we transition to `partially-refunded` so external
            // systems (BI, accounting, fulfilment exports) can tell that
            // money has partly been returned. Without this state, partial
            // refunds previously left the order stuck at `processing` /
            // `completed` indefinitely.
            if ( $update_status ) {
                if ( $proposed_total >= $allowed_total ) {
                    $order->update_status( 'refunded', __( 'Order fully refunded.', 'tejcart' ) );
                } elseif ( $proposed_total > 0
                    && \TejCart\Order\Order_Status::is_valid( \TejCart\Order\Order_Status::PARTIALLY_REFUNDED )
                    && in_array(
                        (string) $order->get_status(),
                        array( \TejCart\Order\Order_Status::PROCESSING, \TejCart\Order\Order_Status::COMPLETED, \TejCart\Order\Order_Status::PARTIALLY_REFUNDED ),
                        true
                    )
                ) {
                    $order->update_status(
                        \TejCart\Order\Order_Status::PARTIALLY_REFUNDED,
                        __( 'Order partially refunded.', 'tejcart' )
                    );
                }
            }

            /**
             * Fires when a refund is created.
             *
             * @param int                  $order_id Order ID.
             * @param float                $amount   Refund amount.
             * @param string               $reason   Reason for the refund.
             * @param \TejCart\Order\Order $order    The order object.
             */
            do_action( 'tejcart_refund_created', $order_id, $amount, $reason, $order );

            /**
             * Fires immediately after a refund row is persisted, with the
             * full Order_Refund object. Subscribers (e.g. the Returns RMA
             * bridge) use this to deterministically link a freshly-created
             * refund to whatever caller triggered process_refund — float
             * amount-matching is unsafe under concurrency.
             *
             * Listeners run synchronously inside the same request that
             * called process_refund; the Order_Refund row has already been
             * INSERTed but the order's "fully refunded" status update has
             * also already happened above.
             *
             * @param \TejCart\Order\Order_Refund $refund   Just-persisted row.
             * @param int                         $order_id Order ID.
             * @param string                      $reason   Refund reason as supplied.
             */
            do_action( 'tejcart_order_refund_created', $refund, $order_id, $reason );

            $formatted_amount = function_exists( 'tejcart_price' )
                ? wp_strip_all_tags( (string) tejcart_price( $amount, $order->get_currency() ) )
                : sprintf( '%.' . ( class_exists( '\\TejCart\\Money\\Currency' ) ? \TejCart\Money\Currency::decimals( (string) $order->get_currency() ) : 2 ) . 'f %s', $amount, $order->get_currency() );

            $note_via = '';
            if ( $gateway_call_succeeded ) {
                $gateway_label = '' !== $gateway_title ? $gateway_title : (string) $gateway_id;
                if ( '' !== $gateway_refund_id ) {
                    $note_via = sprintf(
                        /* translators: 1: gateway name, 2: gateway refund ID. */
                        __( ' via %1$s (refund ID: %2$s)', 'tejcart' ),
                        $gateway_label,
                        $gateway_refund_id
                    );
                } elseif ( '' !== $gateway_label ) {
                    $note_via = sprintf(
                        /* translators: %s: gateway name. */
                        __( ' via %s', 'tejcart' ),
                        $gateway_label
                    );
                }
            }

            if ( ! empty( $reason ) ) {
                $note = sprintf(
                    /* translators: 1: refund amount with currency, 2: optional gateway clause, 3: reason */
                    __( 'Refund of %1$s processed%2$s. Reason: %3$s', 'tejcart' ),
                    $formatted_amount,
                    $note_via,
                    $reason
                );
            } else {
                $note = sprintf(
                    /* translators: 1: refund amount with currency, 2: optional gateway clause */
                    __( 'Refund of %1$s processed%2$s.', 'tejcart' ),
                    $formatted_amount,
                    $note_via
                );
            }

            $order->add_note( $note );

            return true;
        } finally {
            $this->release_refund_lock( $order_id, $lock_held );
        }
    }

    /**
     * Record a refund failure so the reason reaches the merchant.
     *
     * Refund failures used to return a bare `false`, which left the admin
     * "Refund failed" notice pointing at an order note and a log entry that
     * were never written. Centralising the failure here logs the reason and
     * adds an order note, so the "check the order notes and the TejCart log"
     * guidance the merchant is shown actually holds true.
     *
     * @param Order  $order   The order being refunded.
     * @param string $message Human-readable failure reason.
     * @param string $level   Log severity (default 'error').
     * @return false Always false, so callers can `return $this->fail_refund( … );`.
     */
    private function fail_refund( Order $order, string $message, string $level = 'error' ): bool {
        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf( 'Refund failed on order %d: %s', $order->get_id(), $message ),
                $level
            );
        }

        $order->add_note(
            sprintf(
                /* translators: %s: reason the refund could not be processed. */
                __( 'Refund failed: %s', 'tejcart' ),
                $message
            )
        );

        return false;
    }

    /**
     * Acquire the per-order MySQL advisory lock that serialises every
     * refund path (full and partial). The 5-second timeout is generous —
     * a normal refund call returns in well under a second; a 5-second
     * blocked acquire usually means another worker is mid-gateway, in
     * which case we'd rather block than risk a double-refund.
     *
     * @param int $order_id
     * @return bool True when the lock is held by this process; false on
     *              acquisition failure (timeout or DB error). Callers MUST
     *              fail closed on false — proceeding without the lock loses
     *              the serialisation guarantee and risks a double-refund.
     */
    private function acquire_refund_lock( int $order_id ): bool {
        global $wpdb;

        $lock_name = 'tejcart_refund_' . $order_id;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
        $lock_result = $wpdb->get_var(
            // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
            $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock_name, 5 )
        );

        return ( '1' === (string) $lock_result || 1 === $lock_result );
    }

    /**
     * Release the lock acquired via {@see acquire_refund_lock()}.
     *
     * @param int  $order_id
     * @param bool $held When false (acquire returned false) we skip the
     *                   RELEASE_LOCK call so we never release a lock we
     *                   don't own.
     */
    private function release_refund_lock( int $order_id, bool $held ): void {
        if ( ! $held ) {
            return;
        }
        global $wpdb;
        $lock_name = 'tejcart_refund_' . $order_id;
        // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery
        $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
    }

    /**
     * Process a partial (line-item) refund for an order.
     *
     * Validates each item exists in the order, ensures totals do not exceed
     * original amounts, creates a refund record, calls the gateway's
     * process_refund, updates item quantities, and fires actions.
     *
     * @param int    $order_id Order ID.
     * @param array  $items    Array of [{order_item_id, quantity, amount}].
     * @param string $reason   Optional. Reason for the refund.
     * @return bool|\WP_Error True on success, WP_Error on failure.
     */
    public function process_partial_refund( $order_id, $items, $reason = '', $auto_tax = true ) {
        global $wpdb;

        $order_id = absint( $order_id );

        if ( ! $order_id || empty( $items ) || ! is_array( $items ) ) {
            return new \WP_Error( 'invalid_args', __( 'Invalid refund arguments.', 'tejcart' ) );
        }

        $order = new Order( $order_id );

        if ( ! $order->get_id() ) {
            return new \WP_Error( 'invalid_order', __( 'Order not found.', 'tejcart' ) );
        }

        // Refuse to refund any order that already carries the
        // reconciliation marker — process_refund's "gateway succeeded
        // but local INSERT failed" recovery path. Lifting the marker is
        // an operator-driven manual reconciliation step.
        if ( $order->get_meta( '_tejcart_refund_inconsistent' ) ) {
            return new \WP_Error(
                'refund_inconsistent',
                __( 'This order has an unresolved refund inconsistency. Please reconcile manually before issuing further refunds.', 'tejcart' )
            );
        }

        // Mirror process_refund(): orders that never captured funds
        // (pending / on-hold / failed / cancelled) have nothing to return.
        if ( ! Order_Status::is_refundable( (string) $order->get_status() ) ) {
            return new \WP_Error(
                'not_refundable',
                __( 'This order has no captured payment to refund.', 'tejcart' )
            );
        }

        // Validate every refunded line item and compute the total
        // BEFORE acquiring the lock. Per-line bounds (qty / amount) are
        // immutable for the order's lifetime so they don't need to be
        // re-read inside the lock; only the cumulative cap does.
        $order_items       = $order->get_items();
        $order_items_by_id = array();

        foreach ( $order_items as $item ) {
            $item_id = isset( $item->id ) ? (int) $item->id : 0;
            if ( $item_id ) {
                $order_items_by_id[ $item_id ] = $item;
            }
        }

        $refund_total  = 0.0;
        $refund_items  = array();

        // Derive currency once; used for per-line minor-unit cap comparison.
        $order_currency = strtoupper( (string) $order->get_currency() );
        // F-CCM-003: round to the currency's own precision (0 for JPY, 2 for
        // USD/EUR, 3 for KWD/BHD/OMR) rather than a hardcoded 2 dp that
        // truncated 3-decimal currencies. See issue #2592.
        $currency_decimals = max( 0, (int) \TejCart\Money\Currency::decimals( $order_currency ) );

        foreach ( $items as $item_data ) {
            // Use a signed cast (not absint) so the synthetic sentinel ids
            // survive: 0 = shipping line, -1 = tax line. absint() would fold
            // -1 into 1 and collide with a real order item.
            $item_id  = isset( $item_data['order_item_id'] ) ? (int) $item_data['order_item_id'] : 0;
            $qty      = isset( $item_data['quantity'] ) ? absint( $item_data['quantity'] ) : 0;
            $amount   = isset( $item_data['amount'] ) ? (float) $item_data['amount'] : 0.0;

            // Synthetic lines carry only a money amount and no stock: shipping
            // (id 0) and tax (id -1). They bypass the per-item qty / line-total
            // validation but are still summed into the refund total and saved
            // so later refunds can see how much shipping / tax was returned.
            // Restock (Order_Refund::restock_items) ignores non-positive ids.
            if ( $item_id <= 0 ) {
                if ( 0 !== $item_id && -1 !== $item_id ) {
                    return new \WP_Error( 'invalid_item', __( 'Invalid order item ID.', 'tejcart' ) );
                }
                if ( $amount < 0.0 ) {
                    $amount = 0.0;
                }
                $refund_total  += $amount;
                $refund_items[] = array(
                    'order_item_id' => $item_id,
                    'quantity'      => 0,
                    'amount'        => round( $amount, $currency_decimals ),
                );
                continue;
            }

            if ( ! isset( $order_items_by_id[ $item_id ] ) ) {
                return new \WP_Error(
                    'item_not_found',
                    sprintf(
                        /* translators: %d: order item ID */
                        __( 'Order item #%d not found in this order.', 'tejcart' ),
                        $item_id
                    )
                );
            }

            $original_item        = $order_items_by_id[ $item_id ];
            $original_qty         = isset( $original_item->quantity ) ? (int) $original_item->quantity : 0;
            // line_total is stored as BIGINT integer minor units; cast directly to int.
            $original_total_minor = isset( $original_item->line_total ) ? (int) $original_item->line_total : 0;

            if ( $qty > $original_qty ) {
                return new \WP_Error(
                    'quantity_exceeded',
                    sprintf(
                        /* translators: 1: order item ID, 2: original quantity */
                        __( 'Refund quantity for item #%1$d exceeds original quantity of %2$d.', 'tejcart' ),
                        $item_id,
                        $original_qty
                    )
                );
            }

            // Compare in integer minor units — comparing the caller's
            // major-unit float $amount against the BIGINT minor-unit column
            // directly (as the old code did) always failed for any non-zero
            // amount (e.g. 10.0 > 1000 = false), so the cap never fired.
            $amount_minor = \TejCart\Money\Currency::to_minor_units( $amount, $order_currency );
            if ( $amount_minor > $original_total_minor ) {
                return new \WP_Error(
                    'amount_exceeded',
                    sprintf(
                        /* translators: %d: order item ID */
                        __( 'Refund amount for item #%d exceeds the original line total.', 'tejcart' ),
                        $item_id
                    )
                );
            }

            $refund_total += $amount;

            $refund_items[] = array(
                'order_item_id' => $item_id,
                'quantity'      => $qty,
                'amount'        => round( $amount, $currency_decimals ),
            );
        }

        $refund_total = round( $refund_total, $currency_decimals );

        // Now acquire the per-order advisory lock. From this point on
        // the refund flow exactly mirrors process_refund: re-read the
        // cumulative refunded amount inside the lock, call the gateway,
        // and only then write the local row. Without this, two
        // concurrent partial-refund admin tabs both passed a
        // pre-lock cap check, both saved local rows, then both called
        // the gateway — money out twice. See review finding C-3.
        $lock_held = $this->acquire_refund_lock( $order_id );

        // Fail closed (mirrors process_refund): without the lock held, two
        // concurrent partial refunds both pass the cap-check and both hit
        // the gateway, paying the buyer twice. Abort and let the admin retry
        // rather than risk a double payout.
        if ( ! $lock_held ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf(
                        'Partial refund aborted on order %d: could not acquire the per-order refund lock (another refund may be in progress). Retry shortly.',
                        $order_id
                    ),
                    'error'
                );
            }
            return new \WP_Error(
                'refund_locked',
                __( 'Could not start the refund because another refund for this order is in progress. Please retry in a moment.', 'tejcart' )
            );
        }

        try {
            $already_refunded = (float) Order_Refund::get_total_refunded( $order_id );
            $order_total      = (float) $order->get_total();

            // Industry-standard tax handling: also refund the tax that was
            // charged on the items being refunded, allocated pro-rata on the
            // refunded item subtotal, so the buyer is made whole (item + its
            // tax) and the order only reaches "fully refunded" once the whole
            // charged amount — tax included — has been returned. Clamped to
            // the order's remaining refundable balance so rounding can never
            // push the cumulative refund past the order total. (Shipping is
            // refunded explicitly via its own line, never auto-taxed.)
            $order_subtotal_major = (float) $order->get_subtotal();
            $order_tax_major      = (float) $order->get_tax_total();
            // Skipped when $auto_tax is false: the admin refund form supplies
            // an explicit, merchant-controlled tax line (synthetic id -1), so
            // adding proportional tax here too would double-charge the refund.
            if ( $auto_tax && $order_tax_major > 0.0 && $order_subtotal_major > 0.0 && $refund_total > 0.0 ) {
                $proportional_tax = round(
                    $order_tax_major * ( $refund_total / $order_subtotal_major ),
                    $currency_decimals
                );
                $headroom = round( $order_total - $already_refunded - $refund_total, $currency_decimals );
                if ( $headroom < 0.0 ) {
                    $headroom = 0.0;
                }
                if ( $proportional_tax > $headroom ) {
                    $proportional_tax = $headroom;
                }
                if ( $proportional_tax > 0.0 ) {
                    $refund_total = round( $refund_total + $proportional_tax, $currency_decimals );
                }
            }

            // Cap-check in integer minor units against the order's currency.
            // Mirror process_refund (M-1, see review doc): the legacy
            // `> $order_total + 0.0001` float tolerance is meaningless in
            // zero-decimal currencies (JPY, KRW) and looser than one minor
            // unit in three-decimal currencies (KWD, BHD, OMR). Integer
            // comparison is exact across the full ISO-4217 matrix.
            // $order_currency already set before the per-line foreach above.
            $proposed_minor = \TejCart\Money\Currency::to_minor_units( $already_refunded + $refund_total, $order_currency );
            $allowed_minor  = \TejCart\Money\Currency::to_minor_units( $order_total, $order_currency );

            if ( $proposed_minor > $allowed_minor ) {
                return new \WP_Error(
                    'total_exceeded',
                    __( 'Total refund amount exceeds the order total.', 'tejcart' )
                );
            }

            // Gateway BEFORE local row.
            // The gateway is the source of truth for "money has actually
            // moved." If we save the local refund row first and the
            // gateway then errors, the order's cap re-evaluation
            // (Order_Refund::get_total_refunded) inflates the
            // already_refunded total without the merchant ever having
            // refunded the buyer.
            $gateway_call_succeeded = false;
            $gateway_refund_id      = '';
            $gateway_id             = $order->get_payment_method();

            if ( $gateway_id && function_exists( 'tejcart' ) ) {
                $gateway = tejcart()->gateways()->get_gateway( $gateway_id );
                if ( $gateway && method_exists( $gateway, 'process_refund' ) ) {
                    $gateway_result = $gateway->process_refund( $order_id, $refund_total, $reason );
                    if ( is_wp_error( $gateway_result ) ) {
                        return $gateway_result;
                    }
                    if ( false === $gateway_result ) {
                        return new \WP_Error(
                            'gateway_refund_failed',
                            __( 'The payment gateway rejected the refund.', 'tejcart' )
                        );
                    }
                    if ( is_string( $gateway_result ) && '' !== $gateway_result ) {
                        $gateway_refund_id = $gateway_result;
                    }
                    $gateway_call_succeeded = true;
                }
            }

            $refund = new Order_Refund( array(
                'order_id'        => $order_id,
                'amount'          => $refund_total,
                'reason'          => sanitize_text_field( $reason ),
                'items'           => $refund_items,
                'transaction_ref' => $gateway_refund_id,
            ) );

            $saved = $refund->save();

            if ( ! $saved ) {
                if ( $gateway_call_succeeded ) {
                    $order->update_meta( '_tejcart_refund_inconsistent', 1 );
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log(
                            sprintf(
                                'Partial-refund inconsistency on order %d: gateway %s refunded %.2f %s (refund ID: %s) but local INSERT failed.',
                                $order_id,
                                (string) $gateway_id,
                                (float) $refund_total,
                                (string) $order->get_currency(),
                                '' !== $gateway_refund_id ? $gateway_refund_id : 'n/a'
                            ),
                            'error'
                        );
                    }
                    /**
                     * Fires when a partial refund's gateway call
                     * succeeded but the local refund record could not
                     * be saved. Mirrors process_refund's
                     * `tejcart_refund_inconsistency` action so a single
                     * monitoring listener covers both paths.
                     *
                     * @param int    $order_id   Order ID.
                     * @param float  $amount     Refund amount that already
                     *                           moved at the gateway.
                     * @param string $gateway_id Gateway that processed it.
                     */
                    do_action( 'tejcart_refund_inconsistency', $order_id, $refund_total, (string) $gateway_id );
                }
                return new \WP_Error( 'refund_save_failed', __( 'Failed to save refund record.', 'tejcart' ) );
            }

            $items_table = $wpdb->prefix . 'tejcart_order_items';

            // Audit #26 / 08 #5 — hoist the refunds list once before the
            // loops below. The previous code called
            // `Order_Refund::get_refunds( $order_id )` inside
            // `get_item_total_refunded_qty()` on every iteration, which
            // ran a fresh SELECT against tejcart_order_refunds per
            // call — up to 2 * K reads inside the per-order advisory
            // lock for a K-item refund. The new helper
            // `get_item_total_refunded_qty_from()` takes the
            // materialised list directly.
            $all_refunds = Order_Refund::get_refunds( $order_id );

            // Two-phase fully-refunded check. The first loop only walks the
            // items in *this* refund — it can zero out item quantities and
            // tentatively flag the order if any of those items still has
            // unrefunded quantity left. The second loop then verifies the
            // entire order (so an order with items NOT touched by this
            // refund but still partially refunded from a prior call is
            // correctly seen as not-yet-fully-refunded).
            $order_is_fully_refunded = true;

            foreach ( $refund_items as $refund_item ) {
                $item_id = $refund_item['order_item_id'];

                if ( ! isset( $order_items_by_id[ $item_id ] ) ) {
                    continue;
                }

                $original_item = $order_items_by_id[ $item_id ];
                $original_qty  = isset( $original_item->quantity ) ? (int) $original_item->quantity : 0;

                $total_qty_refunded = $this->get_item_total_refunded_qty_from( $all_refunds, $item_id );

                if ( $total_qty_refunded >= $original_qty ) {
                    // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
                    $wpdb->update(
                        $items_table,
                        array( 'quantity' => 0 ),
                        array( 'id' => $item_id, 'order_id' => $order_id ),
                        array( '%d' ),
                        array( '%d', '%d' )
                    );
                } else {
                    $order_is_fully_refunded = false;
                }
            }

            if ( $order_is_fully_refunded ) {
                foreach ( $order_items_by_id as $item_id => $item ) {
                    $original_qty       = isset( $item->quantity ) ? (int) $item->quantity : 0;
                    $total_qty_refunded = $this->get_item_total_refunded_qty_from( $all_refunds, $item_id );

                    if ( $total_qty_refunded < $original_qty ) {
                        $order_is_fully_refunded = false;
                        break;
                    }
                }
            }

            // Render the amount in the order's currency (e.g. "€95.36")
            // rather than a bare float, matching process_refund()'s note.
            // Explicit currency keeps it correct in admin and under a
            // multi-currency switcher. Falls back to "<amount> <code>" when
            // the formatter isn't loaded.
            $refund_total_display = function_exists( 'tejcart_price' )
                ? wp_strip_all_tags( (string) tejcart_price( $refund_total, $order_currency ) )
                : sprintf( '%.' . $currency_decimals . 'f %s', $refund_total, $order_currency );

            // Count only real product lines for the note; the synthetic
            // shipping (id 0) and tax (id -1) lines are surfaced separately so
            // the note doesn't claim "3 line items" for a single product whose
            // shipping and tax were also returned.
            $real_item_count = 0;
            $included_extras = array();
            foreach ( $refund_items as $ri ) {
                $rid = isset( $ri['order_item_id'] ) ? (int) $ri['order_item_id'] : 0;
                $amt = isset( $ri['amount'] ) ? (float) $ri['amount'] : 0.0;
                if ( $rid > 0 ) {
                    ++$real_item_count;
                } elseif ( 0 === $rid && $amt > 0.0 ) {
                    $included_extras[] = __( 'shipping', 'tejcart' );
                } elseif ( -1 === $rid && $amt > 0.0 ) {
                    $included_extras[] = __( 'tax', 'tejcart' );
                }
            }

            if ( $real_item_count > 0 ) {
                $note = sprintf(
                    /* translators: 1: refund amount, 2: number of product line items */
                    _n(
                        'Partial refund of %1$s for %2$d line item processed.',
                        'Partial refund of %1$s for %2$d line items processed.',
                        $real_item_count,
                        'tejcart'
                    ),
                    $refund_total_display,
                    $real_item_count
                );
            } else {
                $note = sprintf(
                    /* translators: %s: refund amount */
                    __( 'Refund of %s processed.', 'tejcart' ),
                    $refund_total_display
                );
            }

            if ( ! empty( $included_extras ) ) {
                $note .= ' ' . sprintf(
                    /* translators: %s: comma-separated list of extra refunded components, e.g. "shipping, tax" */
                    __( 'Includes %s.', 'tejcart' ),
                    implode( ', ', $included_extras )
                );
            }

            if ( ! empty( $reason ) ) {
                $note .= ' ' . sprintf(
                    /* translators: %s: refund reason */
                    __( 'Reason: %s', 'tejcart' ),
                    $reason
                );
            }

            $order->add_note( $note );

            /**
             * Fires when a partial refund is created.
             *
             * @param int                         $order_id     Order ID.
             * @param \TejCart\Order\Order_Refund $refund       The refund object.
             * @param \TejCart\Order\Order        $order        The order object.
             */
            do_action( 'tejcart_partial_refund_created', $order_id, $refund, $order );

            /**
             * Mirror of process_refund's `tejcart_order_refund_created`
             * so subscribers (Returns RMA bridge, etc.) see partial
             * refunds without separate plumbing.
             *
             * @param \TejCart\Order\Order_Refund $refund   Just-persisted row.
             * @param int                         $order_id Order ID.
             * @param string                      $reason   Refund reason as supplied.
             */
            do_action( 'tejcart_order_refund_created', $refund, $order_id, $reason );

            // Flip the whole order to "refunded" only when the cumulative
            // refunded AMOUNT (incl tax + shipping) has reached the order
            // total — not merely when every line's quantity was refunded.
            // An items-only refund that leaves tax or shipping outstanding
            // stays partially refunded. ($order_is_fully_refunded above still
            // zeroes fully-refunded line quantities for the item display.)
            unset( $order_is_fully_refunded );
            $order_total_minor    = \TejCart\Money\Currency::to_minor_units( (float) $order->get_total(), $order_currency );
            $total_refunded_minor = \TejCart\Money\Currency::to_minor_units( (float) Order_Refund::get_total_refunded( $order_id ), $order_currency );
            if ( $total_refunded_minor >= $order_total_minor ) {
                $order->update_status( 'refunded', __( 'Order fully refunded.', 'tejcart' ) );
            }

            return true;
        } finally {
            $this->release_refund_lock( $order_id, $lock_held );
        }
    }

    /**
     * Sum the refunded quantity for one order-item across a pre-materialised
     * refunds list. Hoisting the SELECT out of the per-item loop is the
     * audit-#26 / 08-#5 fix — previously this method (then named
     * `get_item_total_refunded_qty`) was called twice per item inside the
     * refund lock, each time issuing a fresh
     * `SELECT * FROM tejcart_order_refunds`.
     *
     * @param array<int, object> $refunds Order's refund rows (`Order_Refund::get_refunds( $order_id )` output).
     * @param int                $item_id Order item ID.
     */
    private function get_item_total_refunded_qty_from( array $refunds, int $item_id ): int {
        $total_refunded = 0;
        foreach ( $refunds as $refund ) {
            if ( ! is_array( $refund->items ) ) {
                continue;
            }
            foreach ( $refund->items as $refund_item ) {
                if ( isset( $refund_item['order_item_id'] ) && (int) $refund_item['order_item_id'] === (int) $item_id ) {
                    $total_refunded += isset( $refund_item['quantity'] ) ? (int) $refund_item['quantity'] : 0;
                }
            }
        }
        return $total_refunded;
    }

}
