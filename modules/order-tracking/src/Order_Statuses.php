<?php
/**
 * Registers the order-level `shipped` and `delivered` statuses with core.
 *
 * Core ships a fixed set of order statuses (pending → processing → … →
 * completed/refunded). The order-tracking module's headline behaviour —
 * auto-advancing an order to "shipped" when a tracking number is attached,
 * then "delivered" when the carrier confirms — needs two statuses core does
 * not define. Rather than fork core, we extend it through the documented
 * extension points:
 *
 *  - `tejcart_order_statuses`            → makes the slugs valid for
 *                                          {@see \TejCart\Order\Order_Status::is_valid()}
 *                                          and therefore for `Order::update_status()`.
 *  - `tejcart_order_status_labels`       → labels the helper
 *                                          {@see tejcart_get_order_status_label()} uses.
 *  - `tejcart_order_allowed_transitions` → wires the state-machine edges so
 *                                          the transitions are actually reachable.
 *
 * Registering only the labels without the transitions would leave the
 * statuses unreachable (every `update_status()` would be blocked as an
 * invalid transition); registering only the transitions without the
 * statuses would fail `is_valid()`. Both are done here together.
 *
 * @package TejCart\Tier2\Order_Tracking
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Order_Statuses {
    public const SHIPPED   = 'shipped';
    public const DELIVERED = 'delivered';

    public function register(): void {
        add_filter( 'tejcart_order_statuses', array( $this, 'register_statuses' ) );
        add_filter( 'tejcart_order_status_labels', array( $this, 'register_labels' ) );
        add_filter( 'tejcart_order_allowed_transitions', array( $this, 'register_transitions' ) );
    }

    /**
     * Insert `shipped` and `delivered` into the status map, positioned
     * between `processing` and `completed` so admin dropdowns read in the
     * natural fulfilment order.
     *
     * @param array<string,string> $statuses
     * @return array<string,string>
     */
    public function register_statuses( $statuses ): array {
        if ( ! is_array( $statuses ) ) {
            $statuses = array();
        }

        $additions = array(
            self::SHIPPED   => __( 'Shipped', 'tejcart' ),
            self::DELIVERED => __( 'Delivered', 'tejcart' ),
        );

        // Splice after `processing` when present; otherwise append.
        $out = array();
        foreach ( $statuses as $slug => $label ) {
            $out[ $slug ] = $label;
            if ( 'processing' === $slug ) {
                foreach ( $additions as $a_slug => $a_label ) {
                    $out[ $a_slug ] = $a_label;
                }
            }
        }
        foreach ( $additions as $a_slug => $a_label ) {
            if ( ! isset( $out[ $a_slug ] ) ) {
                $out[ $a_slug ] = $a_label;
            }
        }

        return $out;
    }

    /**
     * Mirror the labels into the `tejcart_get_order_status_label()` map so
     * customer-facing strings (account page, emails) read correctly.
     *
     * @param array<string,string> $labels
     * @return array<string,string>
     */
    public function register_labels( $labels ): array {
        if ( ! is_array( $labels ) ) {
            $labels = array();
        }
        $labels[ self::SHIPPED ]   = __( 'Shipped', 'tejcart' );
        $labels[ self::DELIVERED ] = __( 'Delivered', 'tejcart' );
        return $labels;
    }

    /**
     * Wire the state-machine edges. Orders reach `shipped` from any
     * pre-fulfilment status that still holds the goods, then progress to
     * `delivered`/`completed`, and either status can still escalate to a
     * refund/cancellation (returns happen after delivery).
     *
     * @param array<string,string[]> $transitions
     * @return array<string,string[]>
     */
    public function register_transitions( $transitions ): array {
        if ( ! is_array( $transitions ) ) {
            return array();
        }

        // Allow the existing pre-fulfilment statuses to advance to shipped.
        foreach ( array( 'pending', 'processing', 'on-hold' ) as $from ) {
            if ( isset( $transitions[ $from ] ) && is_array( $transitions[ $from ] ) ) {
                if ( ! in_array( self::SHIPPED, $transitions[ $from ], true ) ) {
                    $transitions[ $from ][] = self::SHIPPED;
                }
            }
        }

        // Exits from the two new statuses.
        $transitions[ self::SHIPPED ] = array(
            self::DELIVERED,
            'completed',
            'cancelled',
            'partially-refunded',
            'refunded',
        );
        $transitions[ self::DELIVERED ] = array(
            'completed',
            'partially-refunded',
            'refunded',
        );

        return $transitions;
    }
}
