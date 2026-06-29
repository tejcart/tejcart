<?php
/**
 * PayPal webhook reconciliation poller.
 *
 * Listens on the daily `tejcart_webhook_reconcile_run` action (registered
 * by Action_Scheduler) and polls PayPal for orders the inbound webhook
 * handler may have missed. When a delivery fails past PayPal's retry
 * budget the local order is stuck in `pending` forever; this catch-up
 * sweep restores them to the correct status using the same downstream
 * actions as the live webhook (so existing fulfilment hooks fire once,
 * idempotently).
 *
 * Bounded by:
 *  - Status: only orders currently `pending`
 *  - Window: only orders created in the last `tejcart_paypal_reconcile_window_days`
 *    days (default 7)
 *  - Cap: at most `tejcart_paypal_reconcile_max_orders` per run (default 200)
 *  - Filter: only orders whose payment_method begins with `paypal`
 *
 * @package TejCart\Gateways\PayPal
 */

declare( strict_types=1 );

namespace TejCart\Gateways\PayPal;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * PayPal reconciliation poller.
 *
 * The class is intentionally `final` — gateways are expected to register
 * their own listener on `tejcart_webhook_reconcile_run` rather than
 * subclassing this one.
 */
final class PayPal_Reconciler {

    /**
     * Wire the daily reconciliation listener. Called from the feature
     * registration map in TejCart::register_features.
     */
    public function init(): void {
        add_action( 'tejcart_webhook_reconcile_run', array( $this, 'run' ) );
    }

    /**
     * Sweep pending PayPal orders and confirm their state against
     * PayPal. Safe to call directly (e.g. from `wp tejcart paypal:reconcile`).
     */
    public function run(): void {
        global $wpdb;
        if ( ! is_object( $wpdb ) ) {
            return;
        }

        /**
         * Filter the look-back window for reconciliation. Outside this
         * window PayPal has already auto-voided / settled the order and
         * a missed webhook is irrecoverable via the polling API.
         *
         * @since 1.0.2
         *
         * @param int $days Default 7.
         */
        $window_days = (int) apply_filters( 'tejcart_paypal_reconcile_window_days', 7 );
        $window_days = max( 1, min( 30, $window_days ) );

        /**
         * Per-run cap so a long-stuck install doesn't try to poll
         * thousands of orders in one cron tick.
         *
         * @since 1.0.2
         *
         * @param int $max Default 200.
         */
        $max = (int) apply_filters( 'tejcart_paypal_reconcile_max_orders', 200 );
        $max = max( 1, min( 1000, $max ) );

        $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $window_days * DAY_IN_SECONDS ) );

        $table = $wpdb->prefix . 'tejcart_orders';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery,WordPress.DB.SlowDBQuery,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT id, status, payment_method, created_at FROM {$table}
                 WHERE status = %s
                   AND payment_method LIKE %s
                   AND created_at >= %s
                 ORDER BY created_at DESC
                 LIMIT %d",
                \TejCart\Order\Order_Status::PENDING,
                'paypal%',
                $cutoff,
                $max
            ),
            ARRAY_A
        );

        if ( empty( $rows ) ) {
            return;
        }

        $checked = 0;
        $updated = 0;
        $failed  = 0;

        // Audit L-31 (PPCP F-025): cap consecutive failures so a
        // single broken credential doesn't burn the daily API budget
        // iterating over every pending order.
        $max_consecutive_failures = 5;
        $consecutive_failures     = 0;

        foreach ( $rows as $row ) {
            $order_id = (int) $row['id'];
            $outcome  = $this->reconcile_one( $order_id );
            $checked++;
            if ( 'updated' === $outcome ) {
                $updated++;
                $consecutive_failures = 0;
            } elseif ( 'error' === $outcome ) {
                $failed++;
                $consecutive_failures++;
                if ( $consecutive_failures >= $max_consecutive_failures ) {
                    if ( function_exists( 'tejcart_log' ) ) {
                        tejcart_log( sprintf( 'PayPal reconcile: stopping after %d consecutive failures (likely credential issue).', $consecutive_failures ), 'warning' );
                    }
                    break;
                }
            } else {
                $consecutive_failures = 0;
            }
        }

        if ( function_exists( 'tejcart_log' ) ) {
            tejcart_log(
                sprintf(
                    'PayPal webhook reconcile: checked=%d, updated=%d, errors=%d (window=%dd, cap=%d).',
                    $checked,
                    $updated,
                    $failed,
                    $window_days,
                    $max
                ),
                'info'
            );
        }

        /**
         * Fires at the end of a successful reconciliation sweep so
         * monitoring / metrics layers can record the run.
         *
         * @since 1.0.2
         *
         * @param array{checked:int,updated:int,errors:int} $stats
         */
        do_action(
            'tejcart_paypal_reconcile_completed',
            array(
                'checked' => $checked,
                'updated' => $updated,
                'errors'  => $failed,
            )
        );
    }

    /**
     * Poll PayPal for a single TejCart order and, if PayPal reports the
     * order as COMPLETED / APPROVED while the local order is still
     * pending, drive the appropriate status transition.
     *
     * Returns:
     *  - 'updated' if the local status changed
     *  - 'noop'    if PayPal and TejCart agree (or no PayPal order id)
     *  - 'error'   if the lookup or update failed
     */
    private function reconcile_one( int $order_id ): string {
        if ( $order_id <= 0 ) {
            return 'noop';
        }

        $paypal_order_id = (string) tejcart_get_order_meta( $order_id, '_paypal_order_id' );
        if ( '' === $paypal_order_id ) {
            return 'noop';
        }

        $tejcart = function_exists( 'tejcart' ) ? tejcart() : null;
        if ( ! is_object( $tejcart ) ) {
            return 'error';
        }

        $gateways = $tejcart->gateways();
        if ( ! is_object( $gateways ) || ! method_exists( $gateways, 'get_gateway' ) ) {
            return 'error';
        }

        // Audit #13 / 05 F-5 — the registry's gateway id is
        // `tejcart_paypal`, not `paypal`. The previous lookup always
        // returned null, then the fallback constructed
        // `new PayPal_API( null )` which TypeErrors against the typed
        // constructor — so the daily reconciler crashed on every run.
        // Look up by the real id, and fall back to the shared API
        // accessor (which never receives null) when the gateway is
        // missing OR does not expose `get_api()`.
        $gateway = $gateways->get_gateway( 'tejcart_paypal' );
        if ( is_object( $gateway ) && method_exists( $gateway, 'get_api' ) ) {
            $api = $gateway->get_api();
        } else {
            $api = PayPal_Gateway::get_shared_api();
        }

        if ( ! is_object( $api ) || ! method_exists( $api, 'get_order_details' ) ) {
            return 'error';
        }

        $details = $api->get_order_details( $paypal_order_id );
        if ( is_wp_error( $details ) ) {
            return 'error';
        }

        if ( ! is_array( $details ) || empty( $details['status'] ) ) {
            return 'error';
        }

        $paypal_status = strtoupper( (string) $details['status'] );

        // Only act on the two non-pending PayPal states we know how to
        // map. Refunds / disputes go through their own webhook channels.
        if ( 'COMPLETED' !== $paypal_status && 'VOIDED' !== $paypal_status ) {
            return 'noop';
        }

        $order_factory = '\\TejCart\\Order\\Order_Factory';
        if ( ! class_exists( $order_factory ) || ! method_exists( $order_factory, 'get' ) ) {
            return 'error';
        }
        $order = $order_factory::get( $order_id );
        if ( ! is_object( $order ) ) {
            return 'error';
        }

        if ( ! method_exists( $order, 'update_status' ) ) {
            return 'error';
        }

        $note = sprintf(
            /* translators: %s: PayPal order id */
            __( 'Status reconciled from PayPal (%s) by the catch-up sweep.', 'tejcart' ),
            $paypal_order_id
        );

        if ( 'COMPLETED' === $paypal_status ) {
            // P-H1: mirror PayPal_Webhook::handle_capture_completed —
            // record `_paypal_capture_id` and fire `tejcart_payment_complete`
            // so orders recovered by the sweep can be refunded (the
            // PayPal_Refund_Capture trait bails on an empty capture id)
            // and fulfilment listeners run exactly once.
            $capture_id = '';
            if ( isset( $details['purchase_units'][0]['payments']['captures'][0]['id'] ) ) {
                $capture_id = (string) $details['purchase_units'][0]['payments']['captures'][0]['id'];
            }
            if ( '' !== $capture_id ) {
                $recorded = PayPal_Gateway::record_transaction_meta( $order_id, $capture_id );
                if ( is_wp_error( $recorded ) ) {
                    // Capture id already owned by another order — never promote
                    // to processing (the order would be un-refundable). Hold
                    // for manual review, consistent with the live capture path.
                    $order->update_status(
                        \TejCart\Order\Order_Status::ON_HOLD,
                        __( 'PayPal capture id collision during reconciliation — held for manual review.', 'tejcart' )
                    );
                    return 'error';
                }
            }

            // Mirror the express AJAX path's pre-promotion hook so listeners
            // that create child records on `tejcart_checkout_order_processed`
            // (e.g. Subscriptions) also run for sweep-recovered orders, which
            // by definition never completed the AJAX hop that would have fired
            // it. Gated on the signed express flag to avoid a double-fire.
            if ( 'yes' === (string) tejcart_get_order_meta( $order_id, '_paypal_express' )
                && PayPal_AJAX::express_flag_signature_valid( $order_id )
            ) {
                /** This duplicates the action documented at Checkout::process(). */
                do_action( 'tejcart_checkout_order_processed', (int) $order_id, array() );
            }

            $order->update_status( \TejCart\Order\Order_Status::PROCESSING, $note );

            /**
             * Fires when a PayPal payment is completed. Mirrors the live
             * capture path so listeners run for reconciled orders too.
             *
             * @param int    $order_id   Order ID.
             * @param object $order      Order object.
             * @param string $capture_id PayPal capture (transaction) ID.
             */
            do_action( 'tejcart_payment_complete', $order_id, $order, $capture_id );

            return 'updated';
        }

        // VOIDED → CANCELLED.
        $order->update_status( \TejCart\Order\Order_Status::CANCELLED, $note );

        // P-M2: release the stock reservation on cancellation, matching
        // the cancel_order / orphan-sweep paths which fire this action so
        // Stock_Reservation::release_for_order returns the held stock.
        do_action( 'tejcart_restore_stock_for_order', $order_id, $order );

        return 'updated';
    }
}
