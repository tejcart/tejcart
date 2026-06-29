<?php
/**
 * Free Shipping method.
 *
 * @package TejCart\Shipping\Shipping_Methods
 */

declare( strict_types=1 );

namespace TejCart\Shipping\Shipping_Methods;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Provides free shipping when availability conditions are met.
 *
 * Settings:
 *  - min_amount (decimal): Minimum order total required.
 *  - requires  (string):  none | min_amount | coupon | both
 */
class Free_Shipping extends Abstract_Shipping_Method {
    /**
     * Method identifier.
     *
     * @var string
     */
    protected $id = 'free_shipping';

    /**
     * Method title.
     *
     * @var string
     */
    protected $title = 'Free Shipping';

    /**
     * Calculate shipping cost.
     *
     * Always returns 0 because this is free shipping.
     *
     * @param mixed $cart Cart instance.
     * @return float
     */
    public function calculate( $cart ) {
        return 0.0;
    }

    /**
     * Check whether free shipping is available for the given cart.
     *
     * Evaluates the "requires" setting:
     *  - none:       always available.
     *  - min_amount: cart subtotal must meet or exceed min_amount.
     *  - coupon:     a free-shipping coupon must be applied.
     *  - both:       both min_amount and coupon conditions must be true.
     *
     * @param mixed $cart Cart instance (may be null).
     * @return bool
     */
    public function is_available( $cart ) {
        if ( ! $this->enabled ) {
            return false;
        }

        $requires   = isset( $this->settings['requires'] ) ? $this->settings['requires'] : 'none';
        // min_amount is stored in the shop (base) currency but compared below
        // against the cart subtotal/discount, which are in the ACTIVE
        // currency. Route it through the same `tejcart_free_shipping_threshold`
        // filter the calculator uses so the currency switcher converts it
        // base→active; otherwise the free-shipping gate is wrong by the FX
        // rate for every non-base currency. Passthrough on a single-currency
        // store.
        $min_amount = isset( $this->settings['min_amount'] ) ? (float) $this->settings['min_amount'] : 0.0;
        $min_amount = (float) apply_filters( 'tejcart_free_shipping_threshold', $min_amount, $cart );

        if ( 'none' === $requires ) {
            return true;
        }

        $meets_min    = true;
        $has_coupon   = false;

        if ( in_array( $requires, array( 'min_amount', 'both' ), true ) ) {
            // Audit M-9 (Cart F-011): use (subtotal - discount) to
            // match Cart_Calculator::calculate_shipping() which
            // evaluates the global threshold against post-discount.
            $cart_subtotal = 0.0;
            $cart_discount = 0.0;

            if ( null !== $cart && is_object( $cart ) && method_exists( $cart, 'get_subtotal' ) ) {
                $cart_subtotal = (float) $cart->get_subtotal();
            }
            if ( null !== $cart && is_object( $cart ) && method_exists( $cart, 'get_discount_total' ) ) {
                try {
                    $cart_discount = (float) $cart->get_discount_total();
                } catch ( \Throwable $e ) {
                    $cart_discount = 0.0;
                }
            }

            $meets_min = ( $cart_subtotal - $cart_discount ) >= $min_amount;
        }

        if ( in_array( $requires, array( 'coupon', 'both' ), true ) ) {
            if ( null !== $cart && is_object( $cart ) && method_exists( $cart, 'get_coupons' ) ) {
                $coupons = $cart->get_coupons();

                foreach ( $coupons as $coupon ) {
                    if ( ! is_array( $coupon ) ) {
                        continue;
                    }
                    if ( ! empty( $coupon['free_shipping'] ) ) {
                        $has_coupon = true;
                        break;
                    }
                    if ( isset( $coupon['discount_type'] ) && 'free_shipping' === $coupon['discount_type'] ) {
                        $has_coupon = true;
                        break;
                    }
                }
            }
        }

        switch ( $requires ) {
            case 'min_amount':
                return $meets_min;

            case 'coupon':
                return $has_coupon;

            case 'both':
                return $meets_min && $has_coupon;

            default:
                return true;
        }
    }
}
