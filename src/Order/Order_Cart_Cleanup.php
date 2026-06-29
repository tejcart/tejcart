<?php
/**
 * Order cart cleanup.
 *
 * @package TejCart\Order
 */

declare( strict_types=1 );

namespace TejCart\Order;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Empties the buyer's cart whenever an order transitions into a paid state,
 * regardless of which code path drove the transition.
 *
 * Historically each gateway emptied the cart
 * inside its own synchronous success path. That left two race windows:
 *
 *   1. Buyer closes the tab before the synchronous return (PayPal popup, 3DS
 *      redirect). Webhook arrives in a different request and the cart still
 *      contains the items; the next visit still sees them.
 *
 *   2. Async APMs (Klarna, ACH, iDEAL, …) emptied the cart at "processing" /
 *      "on-hold", *before* the bank confirmed. If settlement later failed
 *      the buyer was left with neither cart nor confirmed order.
 *
 * The fix is a single listener on tejcart_order_status_processing (and
 * _completed) that:
 *
 *   - Empties the request-scoped cart when one is available (covers the
 *     synchronous success paths uniformly so individual gateways no longer
 *     have to duplicate the call).
 *   - Falls back to the cart_session_key recorded on the order at creation
 *     so an async webhook arriving in a different process can still
 *     destroy the buyer's persisted session row.
 *
 * Re-runs are idempotent — calling empty_cart() on an empty cart and
 * deleting an already-removed session row are both no-ops.
 */
class Order_Cart_Cleanup {

    public const ORDER_META_KEY = '_tejcart_cart_session_key';

    public function init(): void {
        // Capture the buyer's session key at order creation so the
        // listener below can clear the persisted cart even when the
        // webhook callback fires in a different request.
        add_action( 'tejcart_new_order', array( $this, 'capture_cart_session_key' ), 5, 2 );

        // Fire on every transition that means "the cart is no longer
        // needed". The state machine already short-circuits same-status
        // transitions, so listing all three hooks is safe and covers the
        // common gateway lanes:
        //
        //   - on-hold     — COD, Bank Transfer, Check, Stripe manual
        //                   capture, PayPal authorize-only.
        //   - processing  — every captured/paid gateway lane.
        //   - completed   — gateways that move pending→completed directly
        //                   (digital-only orders).
        //
        // Wiring on-hold here lets the offline / manual-capture
        // gateways drop their inline $cart->empty_cart() calls, removing
        // the per-gateway duplication that originally caused Issue #2.
        // We deliberately do NOT bind to cancelled/failed/refunded so the
        // buyer can still retry payment from their cart on those flows.
        add_action( 'tejcart_order_status_on-hold',    array( $this, 'clear_for_order' ), 5, 2 );
        add_action( 'tejcart_order_status_processing', array( $this, 'clear_for_order' ), 5, 2 );
        add_action( 'tejcart_order_status_completed',  array( $this, 'clear_for_order' ), 5, 2 );
    }

    /**
     * Persist the current buyer's cart session_key on the order so the
     * post-payment listener can target it from an async context.
     *
     * @param int   $order_id
     * @param Order $order
     */
    public function capture_cart_session_key( $order_id, $order = null ): void {
        if ( ! function_exists( 'tejcart' ) ) {
            return;
        }
        $cart = tejcart()->cart();
        if ( ! $cart || ! method_exists( $cart, 'get_session_key' ) ) {
            return;
        }
        $key = (string) $cart->get_session_key();
        if ( '' === $key ) {
            return;
        }
        tejcart_update_order_meta( (int) $order_id, self::ORDER_META_KEY, $key );
    }

    /**
     * Drain any cart associated with the given order.
     *
     * @param int   $order_id
     * @param Order $order
     */
    public function clear_for_order( $order_id, $order = null ): void {
        if ( function_exists( 'tejcart' ) ) {
            $tejcart = tejcart();
            if ( $tejcart && method_exists( $tejcart, 'cart' ) ) {
                $cart = $tejcart->cart();
                if ( $cart ) {
                    // Prefer the hard-delete path so the
                    // persisted session row goes away too. Falls back to
                    // empty_cart() when a custom Cart implementation does
                    // not expose destroy_session().
                    if ( method_exists( $cart, 'destroy_session' ) ) {
                        $cart->destroy_session();
                    } elseif ( method_exists( $cart, 'empty_cart' ) ) {
                        $cart->empty_cart();
                    }
                }
            }
        }

        $stored_key = (string) tejcart_get_order_meta( (int) $order_id, self::ORDER_META_KEY );
        if ( '' === $stored_key ) {
            return;
        }

        global $wpdb;
        $table = $wpdb->prefix . 'tejcart_sessions';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->delete( $table, array( 'session_key' => $stored_key ), array( '%s' ) );

        if ( function_exists( 'wp_cache_delete' ) ) {
            wp_cache_delete( 'sess_' . $stored_key, 'tejcart_cart_sessions' );
        }

        // One-shot — clear the marker so a later refund/cancel doesn't try
        // to re-destroy a session row that no longer exists.
        tejcart_update_order_meta( (int) $order_id, self::ORDER_META_KEY, '' );
    }
}
