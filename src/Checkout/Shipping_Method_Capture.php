<?php
/**
 * Captures the chosen shipping method during checkout submission
 * and persists it to the resulting order.
 *
 * @package TejCart\Checkout
 */

declare( strict_types=1 );

namespace TejCart\Checkout;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Bridges the checkout form's `tejcart_shipping_method` radio with
 * the cart session and the order's meta storage.
 *
 * Without this class the picker added to the checkout template would
 * be discarded after submission.
 */
class Shipping_Method_Capture {
    /**
     * Hook into the checkout pipeline.
     */
    public function init(): void {
        add_action( 'tejcart_checkout_validation',     array( $this, 'capture_to_cart' ), 5, 1 );
        add_action( 'tejcart_checkout_update_order_meta', array( $this, 'save_to_order' ), 10, 2 );
    }

    /**
     * Persist the posted shipping method on the cart session before
     * totals get recalculated.
     *
     * The `tejcart_shipping_method` value is injected into `$posted_data`
     * by {@see \TejCart\Checkout\Checkout::process()} after the checkout
     * nonce has already been verified, so this method never touches
     * `$_POST` directly.
     *
     * @param array $posted_data Sanitized POST data.
     */
    public function capture_to_cart( $posted_data ): void {
        if ( empty( $posted_data['tejcart_shipping_method'] ) ) {
            return;
        }

        $method = sanitize_text_field( $posted_data['tejcart_shipping_method'] );

        if ( '' === $method ) {
            return;
        }

        // Audit #3 / 02 H-2 — previously this branch silently rolled
        // back the cart's chosen method to '' and added a notice when
        // the POSTed address rejected it. Cart_Calculator then fell
        // back to the "cheapest" default, charging the buyer a
        // different shipping total than the order summary advertised,
        // and Shipping_Method_Capture::save_to_order() early-returned
        // so the order had no `_shipping_method` meta at all. The
        // fix: persist the buyer's POSTed selection unchanged. The
        // validator (Checkout_Validator::validate_shipping_method)
        // does its own address-aware availability check using the
        // same POST-first address resolution, and raises a hard
        // `shipping_method_invalid` error that propagates back
        // through Checkout::process(). One source of truth for the
        // rejection — and a visible error path rather than a silent
        // mid-submit method swap.
        if ( ! $this->method_is_available( $method, $posted_data ) ) {
            if ( function_exists( 'tejcart_log' ) ) {
                tejcart_log(
                    sprintf( 'Shipping_Method_Capture: method "%s" is not available for the POSTed address; validator will reject.', $method ),
                    'info'
                );
            }
            return;
        }

        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( $cart && method_exists( $cart, 'set_chosen_shipping_method' ) ) {
            $cart->set_chosen_shipping_method( $method );
        }
    }

    /**
     * Test whether the posted shipping method id is one of the methods
     * still available for the submitted address. Returns true on the
     * "no manager" path so we don't reject orders on sites that haven't
     * configured shipping at all.
     *
     * @param string $method      Posted method id.
     * @param array  $posted_data Sanitized POST data.
     */
    private function method_is_available( string $method, array $posted_data ): bool {
        if ( ! class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) {
            return true;
        }

        $manager = new \TejCart\Shipping\Shipping_Manager();
        if ( ! method_exists( $manager, 'get_available_methods' ) ) {
            return true;
        }

        $country  = isset( $posted_data['shipping_country'] ) ? (string) $posted_data['shipping_country'] : '';
        if ( '' === $country ) {
            $country = isset( $posted_data['billing_country'] ) ? (string) $posted_data['billing_country'] : '';
        }
        $state    = isset( $posted_data['shipping_state'] )    ? (string) $posted_data['shipping_state']    : '';
        $postcode = isset( $posted_data['shipping_postcode'] ) ? (string) $posted_data['shipping_postcode'] : '';

        $cart      = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        $available = $manager->get_available_methods( $country, $state, $cart, $postcode );

        foreach ( (array) $available as $instance ) {
            $candidate = '';
            if ( is_object( $instance ) && method_exists( $instance, 'get_id' ) ) {
                $candidate = (string) $instance->get_id();
            }
            if ( $candidate === $method ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Store the chosen shipping method on the order so it can be
     * displayed on receipts, packing slips, and the admin order view.
     *
     * @param int   $order_id    Order ID.
     * @param array $posted_data Sanitized POST data.
     */
    public function save_to_order( $order_id, $posted_data ): void {
        $cart   = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        $method = '';

        if ( $cart && method_exists( $cart, 'get_chosen_shipping_method' ) ) {
            $method = (string) $cart->get_chosen_shipping_method();
        }
        if ( '' === $method && ! empty( $posted_data['tejcart_shipping_method'] ) ) {
            $method = sanitize_text_field( $posted_data['tejcart_shipping_method'] );
        }
        if ( '' === $method ) {
            return;
        }

        if ( class_exists( '\\TejCart\\Order\\Order' ) ) {
            $order = new \TejCart\Order\Order( (int) $order_id );
            if ( $order->get_id() && method_exists( $order, 'update_meta' ) ) {
                $order->update_meta( '_shipping_method', $method );
            }
        }
    }
}
