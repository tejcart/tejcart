<?php
/**
 * Weight-Based shipping method.
 *
 * @package TejCart\Shipping\Shipping_Methods
 */

declare( strict_types=1 );

namespace TejCart\Shipping\Shipping_Methods;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Calculates shipping cost based on total cart weight matched against
 * configured rate brackets.
 *
 * Settings:
 *   - rates: array of {weight_from, weight_to, cost}
 */
class Weight_Based extends Abstract_Shipping_Method {
    /**
     * Method identifier.
     *
     * @var string
     */
    protected $id = 'weight_based';

    /**
     * Method title.
     *
     * @var string
     */
    protected $title = 'Weight-Based Shipping';

    /**
     * Calculate shipping cost based on total cart weight.
     *
     * Sums the weight of all cart items (weight * quantity) and finds the
     * matching rate bracket. Requires the product weight field to be populated.
     *
     * @param mixed $cart Cart instance.
     * @return float Shipping cost.
     */
    public function calculate( $cart ) {
        $total_weight = 0.0;

        if ( is_object( $cart ) && method_exists( $cart, 'get_items' ) ) {
            foreach ( $cart->get_items() as $item ) {
                $product = null;

                if ( method_exists( $item, 'get_product' ) ) {
                    $product = $item->get_product();
                }

                if ( ! $product ) {
                    continue;
                }

                // C-M2: not every product type exposes get_weight()
                // (mirror Flat_Rate's get_meta() guard). Calling it on a
                // type without the method fatals on PHP 8.2.
                $weight   = method_exists( $product, 'get_weight' ) ? (float) $product->get_weight() : 0.0;
                $quantity = method_exists( $item, 'get_quantity' ) ? (int) $item->get_quantity() : 1;

                $total_weight += $weight * $quantity;
            }
        }

        $rates = isset( $this->settings['rates'] ) && is_array( $this->settings['rates'] )
            ? $this->settings['rates']
            : array();

        // C-M1: track the genuinely highest bracket (the one with the
        // largest finite weight_to) so an over-max cart is charged the
        // top tier rather than whichever bracket happened to have the
        // largest weight_from — which could be a cheaper mid-range tier.
        $highest_bracket_cost = null;
        $highest_bracket_to   = -INF;

        foreach ( $rates as $rate ) {
            $weight_from = isset( $rate['weight_from'] ) ? (float) $rate['weight_from'] : 0.0;
            $weight_to   = isset( $rate['weight_to'] ) ? (float) $rate['weight_to'] : 0.0;
            $cost        = isset( $rate['cost'] ) ? (float) $rate['cost'] : 0.0;

            // C-M1: ranges are inclusive on BOTH bounds. The previous
            // exclusive upper bound (< weight_to) left a gap exactly at a
            // bracket boundary, and combined with the over-max fallback
            // below could undercharge. A weight_to of 0 (or less) means
            // "no upper limit" (the open-ended top bracket).
            $in_bracket = ( $total_weight >= $weight_from ) && ( $weight_to <= 0.0 || $total_weight <= $weight_to );

            if ( $in_bracket ) {
                return $this->round_cost( $cost );
            }

            // An open-ended bracket (weight_to <= 0) is effectively the
            // highest possible tier; treat it as such for the fallback.
            $effective_to = $weight_to <= 0.0 ? INF : $weight_to;
            if ( $effective_to > $highest_bracket_to ) {
                $highest_bracket_to   = $effective_to;
                $highest_bracket_cost = $cost;
            }
        }

        if ( null !== $highest_bracket_cost ) {
            return $this->round_cost( $highest_bracket_cost );
        }

        return 0.0;
    }

    /**
     * Determine whether this method is available for the given cart.
     *
     * Available only when at least one item has a weight and there are
     * configured rate brackets.
     *
     * @param mixed $cart Cart instance (may be null).
     * @return bool
     */
    public function is_available( $cart ) {
        if ( ! $this->enabled ) {
            return false;
        }

        $rates = isset( $this->settings['rates'] ) && is_array( $this->settings['rates'] )
            ? $this->settings['rates']
            : array();

        return ! empty( $rates );
    }
}
