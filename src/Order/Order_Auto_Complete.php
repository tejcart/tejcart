<?php
/**
 * Auto-promote orders to `completed` when every line item is virtual /
 * downloadable — orders where `processing` and `completed` carry no
 * fulfilment difference.
 *
 * Without this, the canonical `Order_Completed` email never fires on
 * digital-only orders: PayPal capture lands `processing` (see
 * src/Gateways/PayPal/PayPal_Webhook.php:822,946) and no code path in
 * core moves an order from there to `completed` without admin action.
 * Buyers of downloadable goods receive only `order_processing` and any
 * download flow gated on `completed` status silently fails.
 *
 * Filterable via `tejcart_auto_complete_order` so merchants can opt
 * out per-order or globally; default behaviour is opt-in via the
 * line-item check (a single physical line shuts off the promotion).
 *
 * Audit reference: #1342 / audit 06 F-H1.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Order_Auto_Complete {
    /**
     * Hook into the order pipeline.
     */
    public function init(): void {
        // Run AFTER the other tejcart_order_status_processing
        // listeners (Order_Stock at 10, Sales_Counter at 10,
        // cart cleanup at 5) so the auto-promote sees a fully
        // committed `processing` state before transitioning.
        add_action( 'tejcart_order_status_processing', array( $this, 'maybe_auto_complete' ), 20, 2 );
    }

    /**
     * Promote the order to `completed` when every line item is
     * virtual / downloadable.
     *
     * @param int         $order_id
     * @param Order|mixed $order
     */
    public function maybe_auto_complete( $order_id, $order = null ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        if ( ! is_object( $order ) ) {
            if ( ! function_exists( 'tejcart_get_order' ) ) {
                return;
            }
            $order = tejcart_get_order( $order_id );
        }
        if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) ) {
            return;
        }

        // Defensive: only act when the order is actually `processing`.
        // Re-entrant listeners or filters could have moved it on by
        // the time we run.
        if ( 'processing' !== $order->get_status() ) {
            return;
        }

        if ( ! method_exists( $order, 'get_items' ) ) {
            return;
        }
        $items = $order->get_items();
        if ( ! is_array( $items ) || array() === $items ) {
            return;
        }

        $all_virtual = true;
        foreach ( $items as $item ) {
            $product_id = is_object( $item ) && isset( $item->variation_id ) && (int) $item->variation_id > 0
                ? (int) $item->variation_id
                : ( is_object( $item ) && isset( $item->product_id ) ? (int) $item->product_id : 0 );
            if ( $product_id <= 0 ) {
                $all_virtual = false;
                break;
            }
            if ( ! function_exists( 'tejcart_get_product' ) ) {
                return;
            }
            $product = tejcart_get_product( $product_id );
            if ( ! is_object( $product ) || ! method_exists( $product, 'is_virtual' ) ) {
                $all_virtual = false;
                break;
            }
            if ( ! $product->is_virtual() ) {
                $all_virtual = false;
                break;
            }
        }

        /**
         * Filter whether the order should auto-complete on the
         * processing → completed promotion.
         *
         * Default: true when every line item is virtual /
         * downloadable. Return false to skip.
         *
         * @param bool  $should_complete Whether to promote.
         * @param int   $order_id        Order ID.
         * @param Order $order           Order object.
         */
        $should_complete = (bool) apply_filters( 'tejcart_auto_complete_order', $all_virtual, $order_id, $order );

        if ( ! $should_complete ) {
            return;
        }

        if ( method_exists( $order, 'update_status' ) ) {
            $order->update_status( 'completed', __( 'Auto-completed: all items virtual / downloadable.', 'tejcart' ) );
        }
    }
}
