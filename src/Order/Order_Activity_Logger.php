<?php
/**
 * Order Activity Logger.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Subscribes to high-impact order lifecycle events and writes them into
 * the order timeline as internal notes, so the admin order detail page
 * shows a complete activity log — created, paid, refunded, disputed,
 * email sent, etc.
 *
 * Status-change notes are already written by {@see Order::update_status()};
 * this class covers events that happen between status changes
 * (transactional metadata, gateway-side outcomes, dispatched emails) and
 * events that the gateway path emits as actions.
 */
class Order_Activity_Logger {

    /**
     * Wire up listeners. Safe to call multiple times — the static guard
     * keeps duplicate add_action() calls out of the WP filter table.
     */
    public function init(): void {
        static $registered = false;
        if ( $registered ) {
            return;
        }
        $registered = true;

        // "Order created" notes are written inline in Order_Factory::create()
        // so the timeline entry survives even when this listener can't be
        // resolved (e.g. when a sibling filters out the feature binding).
        // We intentionally do not also subscribe to tejcart_new_order here
        // to avoid double-writing the row.
        add_action( 'tejcart_payment_failed', array( $this, 'log_payment_failed' ), 20, 3 );
        add_action( 'tejcart_paypal_dispute_created', array( $this, 'log_dispute_created' ), 20, 3 );
        add_action( 'tejcart_paypal_dispute_resolved', array( $this, 'log_dispute_resolved' ), 20, 4 );
        add_action( 'tejcart_refund_inconsistency', array( $this, 'log_refund_inconsistency' ), 20, 3 );
        add_action( 'tejcart_email_sent', array( $this, 'log_email_sent' ), 20, 4 );
        add_action( 'tejcart_checkout_payment_failed', array( $this, 'log_checkout_payment_failed' ), 20, 2 );
        add_action( 'tejcart_order_status_transition_conflict', array( $this, 'log_status_conflict' ), 20, 5 );
        add_action( 'tejcart_product_insufficient_stock', array( $this, 'log_insufficient_stock' ), 20, 3 );
        add_action( 'tejcart_refund_restocked_item', array( $this, 'log_refund_restocked' ), 20, 3 );
    }

    /**
     * "Order created" entry, fired by Order_Factory after a successful
     * INSERT.
     *
     * @param int        $order_id Order ID.
     * @param Order|null $order    Order object.
     */
    public function log_order_created( $order_id, $order = null ): void {
        $order = $this->resolve_order( $order_id, $order );
        if ( ! $order ) {
            return;
        }

        $method = (string) $order->get_payment_method_title();
        $total  = function_exists( 'tejcart_price' )
            ? wp_strip_all_tags( (string) tejcart_price( $order->get_total(), (string) $order->get_currency() ) )
            : (string) $order->get_total();

        $order->add_note(
            sprintf(
                /* translators: 1: order total, 2: payment method title. */
                __( 'Order created. Total: %1$s. Payment method: %2$s.', 'tejcart' ),
                $total,
                '' !== $method ? $method : __( 'unknown', 'tejcart' )
            )
        );
    }

    /**
     * "Payment failed" entry, fired by gateway webhooks.
     *
     * @param int        $order_id       Order ID.
     * @param Order|null $order          Order object.
     * @param string     $transaction_id Optional gateway transaction id.
     */
    public function log_payment_failed( $order_id, $order = null, $transaction_id = '' ): void {
        $order = $this->resolve_order( $order_id, $order );
        if ( ! $order ) {
            return;
        }

        $tx = is_string( $transaction_id ) ? $transaction_id : '';

        $order->add_note(
            '' !== $tx
                ? sprintf(
                    /* translators: %s: gateway transaction ID. */
                    __( 'Payment failed (transaction %s).', 'tejcart' ),
                    $tx
                )
                : __( 'Payment failed.', 'tejcart' )
        );
    }

    /**
     * "Checkout payment failed" entry, fired when the synchronous
     * checkout flow rejects a payment.
     *
     * @param int                       $order_id       Order ID.
     * @param array|\WP_Error|string    $payment_result Gateway result or error.
     */
    public function log_checkout_payment_failed( $order_id, $payment_result = null ): void {
        $order = $this->resolve_order( $order_id, null );
        if ( ! $order ) {
            return;
        }

        $reason = '';
        if ( is_wp_error( $payment_result ) ) {
            $reason = (string) $payment_result->get_error_message();
        } elseif ( is_string( $payment_result ) ) {
            $reason = $payment_result;
        } elseif ( is_array( $payment_result ) && isset( $payment_result['message'] ) ) {
            $reason = (string) $payment_result['message'];
        }

        $order->add_note(
            '' !== $reason
                ? sprintf(
                    /* translators: %s: payment failure reason. */
                    __( 'Checkout payment failed: %s', 'tejcart' ),
                    $reason
                )
                : __( 'Checkout payment failed.', 'tejcart' )
        );
    }

    /**
     * "Dispute opened" entry, fired by the PayPal webhook handler.
     *
     * @param int    $order_id   Order ID.
     * @param string $dispute_id PayPal dispute id.
     */
    public function log_dispute_created( $order_id, $dispute_id = '', $resource = null ): void {
        $order = $this->resolve_order( $order_id, null );
        if ( ! $order ) {
            return;
        }

        $order->add_note(
            sprintf(
                /* translators: %s: PayPal dispute ID. */
                __( 'PayPal dispute opened (ID %s).', 'tejcart' ),
                (string) $dispute_id
            )
        );
    }

    /**
     * "Dispute resolved" entry, fired by the PayPal webhook handler.
     *
     * @param int    $order_id   Order ID.
     * @param string $dispute_id PayPal dispute id.
     * @param string $outcome    Outcome string (e.g. "BUYER_FAVOR").
     */
    public function log_dispute_resolved( $order_id, $dispute_id = '', $outcome = '', $resource = null ): void {
        $order = $this->resolve_order( $order_id, null );
        if ( ! $order ) {
            return;
        }

        $order->add_note(
            sprintf(
                /* translators: 1: PayPal dispute ID, 2: outcome. */
                __( 'PayPal dispute %1$s resolved (%2$s).', 'tejcart' ),
                (string) $dispute_id,
                '' !== $outcome ? (string) $outcome : __( 'unknown outcome', 'tejcart' )
            )
        );
    }

    /**
     * "Refund inconsistency" entry, fired when the gateway accepted a
     * refund but the local Order_Refund INSERT failed.
     *
     * @param int    $order_id   Order ID.
     * @param float  $amount     Amount refunded at the gateway.
     * @param string $gateway_id Gateway slug.
     */
    public function log_refund_inconsistency( $order_id, $amount = 0.0, $gateway_id = '' ): void {
        $order = $this->resolve_order( $order_id, null );
        if ( ! $order ) {
            return;
        }

        $order->add_note(
            sprintf(
                /* translators: 1: refund amount, 2: gateway slug. */
                __( 'Refund inconsistency: gateway %2$s refunded %1$s but the local refund record could not be saved. Reconcile manually.', 'tejcart' ),
                number_format( (float) $amount, class_exists( '\\TejCart\\Money\\Currency' ) && method_exists( $order, 'get_currency' ) ? \TejCart\Money\Currency::decimals( (string) $order->get_currency() ) : 2, '.', '' ),
                (string) $gateway_id
            )
        );
    }

    /**
     * "Email sent" entry, fired by Abstract_Email::send().
     *
     * @param string     $email_id  Email identifier (subclass slug).
     * @param string     $recipient Recipient address.
     * @param string     $subject   Subject line.
     * @param Order|null $order     Order context, if the email is order-scoped.
     */
    public function log_email_sent( $email_id, $recipient = '', $subject = '', $order = null ): void {
        if ( ! $order instanceof Order || ! $order->get_id() ) {
            return;
        }

        $order->add_note(
            sprintf(
                /* translators: 1: email subject line, 2: recipient address. */
                __( 'Email "%1$s" sent to %2$s.', 'tejcart' ),
                (string) $subject,
                (string) $recipient
            )
        );
    }

    /**
     * "Status conflict" entry, fired when a status transition lost a
     * race against a concurrent writer.
     *
     * @param int    $order_id            Order ID.
     * @param string $expected_old_status Expected previous status.
     * @param string $attempted           Attempted new status.
     * @param string $current             Live status now.
     */
    public function log_status_conflict( $order_id, $expected_old_status = '', $attempted = '', $current = '', $order = null ): void {
        $order = $this->resolve_order( $order_id, $order );
        if ( ! $order ) {
            return;
        }

        $order->add_note(
            sprintf(
                /* translators: 1: attempted status, 2: actual current status. */
                __( 'Status transition to "%1$s" was discarded — concurrent writer already moved the order to "%2$s".', 'tejcart' ),
                (string) $attempted,
                (string) $current
            )
        );
    }

    /**
     * "Stock decrement failed" entry, fired when a product can't be
     * reduced to fulfil the order (insufficient stock).
     *
     * @param int $product_id Product ID.
     * @param int $quantity   Requested quantity.
     * @param int $order_id   Order ID.
     */
    public function log_insufficient_stock( $product_id, $quantity = 0, $order_id = 0 ): void {
        $order = $this->resolve_order( $order_id, null );
        if ( ! $order ) {
            return;
        }

        $order->add_note(
            sprintf(
                /* translators: 1: product ID, 2: requested quantity. */
                __( 'Stock decrement failed for product #%1$d (qty %2$d). Insufficient stock.', 'tejcart' ),
                (int) $product_id,
                (int) $quantity
            )
        );
    }

    /**
     * "Refunded item restocked" entry, fired by Order_Refund when a
     * line-item refund returns inventory to the product.
     *
     * @param int $product_id Product ID.
     * @param int $quantity   Quantity restocked.
     * @param int $order_id   Order ID.
     */
    public function log_refund_restocked( $product_id, $quantity = 0, $order_id = 0 ): void {
        $order = $this->resolve_order( $order_id, null );
        if ( ! $order ) {
            return;
        }

        $order->add_note(
            sprintf(
                /* translators: 1: product ID, 2: quantity restocked. */
                __( 'Refunded item restocked: product #%1$d × %2$d.', 'tejcart' ),
                (int) $product_id,
                (int) $quantity
            )
        );
    }

    /**
     * Resolve an Order object from either an explicit instance or an ID.
     */
    private function resolve_order( $order_id, $order ): ?Order {
        if ( $order instanceof Order && $order->get_id() ) {
            return $order;
        }

        $order_id = (int) $order_id;
        if ( ! $order_id ) {
            return null;
        }

        $hydrated = new Order( $order_id );
        return $hydrated->get_id() ? $hydrated : null;
    }
}
