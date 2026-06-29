<?php
/**
 * Coupon usage rollback for failed / cancelled / refunded orders.
 *
 * @package TejCart\Coupon
 */

declare( strict_types=1 );

namespace TejCart\Coupon;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Listens for terminal-state transitions on orders that consumed
 * a coupon and decrements the coupon's global + per-user usage counters,
 * idempotently.
 *
 * Without this, a buyer that placed an order with `LAUNCH50`, then had
 * payment fail (gateway decline, insufficient funds, fraud rejection) or
 * had the order refunded, would permanently consume one of the coupon's
 * `usage_limit` slots — eventually exhausting it for legitimate buyers.
 *
 * Idempotency is enforced by a `_tejcart_coupon_rollback_done` order meta
 * marker so multiple status flips (failed → refunded, cancelled → failed)
 * can't double-decrement.
 */
class Order_Coupon_Rollback {

    public const ORDER_META_KEY            = '_tejcart_coupon_rollback_done';
    public const PARTIAL_ROLLBACK_META_KEY = '_tejcart_coupon_partial_rollback_done';

    public function init(): void {
        add_action( 'tejcart_order_status_failed',    array( $this, 'rollback' ), 10, 2 );
        add_action( 'tejcart_order_status_cancelled', array( $this, 'rollback' ), 10, 2 );
        add_action( 'tejcart_order_status_refunded',  array( $this, 'rollback' ), 10, 2 );

        // F-H4 + F-L10 (#927, #960): partial refunds that don't flip the
        // order to `refunded` previously left the coupon usage counters
        // inflated. The proration filter lets merchants choose a policy:
        //   'none'          (default — back-compat, no rollback)
        //   'full'          (always release one usage per coupon)
        //   'proportional'  (release only when cumulative_refunds reach
        //                    the order total — effectively a full refund)
        add_action( 'tejcart_partial_refund_created', array( $this, 'on_partial_refund' ), 10, 3 );
    }

    /**
     * @param int   $order_id
     * @param mixed $order
     */
    public function rollback( $order_id, $order = null ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        if ( '1' === (string) tejcart_get_order_meta( $order_id, self::ORDER_META_KEY ) ) {
            return;
        }

        if ( ! is_object( $order ) || ! method_exists( $order, 'get_coupon_code' ) ) {
            $order = function_exists( 'tejcart_get_order' ) ? tejcart_get_order( $order_id ) : null;
        }
        if ( ! is_object( $order ) ) {
            return;
        }

        $codes = $this->resolve_codes( $order );
        if ( empty( $codes ) ) {
            // Mark anyway so we don't keep re-resolving on subsequent flips.
            tejcart_update_order_meta( $order_id, self::ORDER_META_KEY, '1' );
            return;
        }

        $email = method_exists( $order, 'get_customer_email' )
            ? (string) $order->get_customer_email()
            : '';

        foreach ( $codes as $code ) {
            $code = trim( (string) $code );
            if ( '' === $code ) {
                continue;
            }
            $coupon = Coupon::get_by_code( $code );
            if ( ! $coupon instanceof Coupon ) {
                continue;
            }
            $coupon->decrement_usage();
            if ( '' !== $email ) {
                $coupon->release_usage_for_user( $email );
            }
        }

        tejcart_update_order_meta( $order_id, self::ORDER_META_KEY, '1' );
    }

    /**
     * Partial-refund handler. Reads the merchant-chosen proration policy
     * and, if appropriate, decrements coupon usage.
     *
     * @param int    $order_id
     * @param object $refund  Order_Refund row (or array).
     * @param object $order
     */
    public function on_partial_refund( $order_id, $refund = null, $order = null ): void {
        $order_id = (int) $order_id;
        if ( $order_id <= 0 ) {
            return;
        }

        /**
         * Coupon proration policy on partial refunds.
         *
         * @param string $policy   one of: 'none', 'full', 'proportional'.
         * @param int    $order_id
         * @param mixed  $refund
         * @param mixed  $order
         */
        $policy = (string) apply_filters( 'tejcart_partial_refund_coupon_proration', 'none', $order_id, $refund, $order );

        if ( 'none' === $policy ) {
            return;
        }

        // Idempotency: each Order_Refund row can drive at most one
        // rollback. Once we've decremented for this partial refund we
        // record its ID against the order meta. The full-refund path
        // uses a separate ORDER_META_KEY so the two are independent.
        $refund_id = 0;
        if ( is_object( $refund ) && method_exists( $refund, 'get_id' ) ) {
            $refund_id = (int) $refund->get_id();
        } elseif ( is_array( $refund ) && isset( $refund['id'] ) ) {
            $refund_id = (int) $refund['id'];
        }

        $existing = (array) tejcart_get_order_meta( $order_id, self::PARTIAL_ROLLBACK_META_KEY );
        if ( $refund_id > 0 && in_array( $refund_id, $existing, true ) ) {
            return;
        }

        if ( ! is_object( $order ) || ! method_exists( $order, 'get_coupon_code' ) ) {
            $order = function_exists( 'tejcart_get_order' ) ? tejcart_get_order( $order_id ) : null;
        }
        if ( ! is_object( $order ) ) {
            return;
        }

        if ( 'proportional' === $policy ) {
            // Proportional means "release one usage only when cumulative
            // refunds reach the order total" — practically that's the
            // same trigger as `refunded` status, but partial refunds
            // can accumulate without ever flipping status. Compute the
            // refunded-so-far minor units against the order total.
            //
            // Audit H-15 (Cart F-001): `get_total_in_minor` does not
            // exist on Order — always fell through to the multiplier
            // fallback. `get_total_refunded_minor` is a STATIC on
            // Order_Refund, not an instance method on Order — always
            // evaluated to 0. Net effect: proportional policy never
            // rolled back, even when cumulative refunds reached the
            // order total. Both are now wired correctly.
            // F-CCM-010: derive the order total in minor units directly from the
            // Money VO rather than doing a float get_total() × multiplier round-trip.
            // The float path works in practice but crosses an unnecessary float boundary
            // (see docs/money-representation.md — "stay in minor units throughout").
            $order_currency = method_exists( $order, 'get_currency' ) ? (string) $order->get_currency() : '';
            if ( method_exists( $order, 'get_total_money' ) ) {
                $order_total_minor = $order->get_total_money()->as_minor_units();
            } else {
                // Fallback for any future Order implementation that lacks get_total_money().
                $total_major       = method_exists( $order, 'get_total' ) ? (float) $order->get_total() : 0.0;
                $multiplier        = '' !== $order_currency
                    ? \TejCart\Money\Currency::multiplier( $order_currency )
                    : 100;
                $order_total_minor = (int) round( $total_major * $multiplier, 0, PHP_ROUND_HALF_EVEN );
            }

            $refunded_so_far_minor = \TejCart\Order\Order_Refund::get_total_refunded_minor( $order_id );
            if ( $order_total_minor <= 0 || $refunded_so_far_minor < $order_total_minor ) {
                // Record we've seen this refund (so we don't keep
                // rechecking on subsequent fires) but don't roll back.
                if ( $refund_id > 0 ) {
                    $existing[] = $refund_id;
                    tejcart_update_order_meta( $order_id, self::PARTIAL_ROLLBACK_META_KEY, $existing );
                }
                return;
            }
        }

        $codes = $this->resolve_codes( $order );
        if ( empty( $codes ) ) {
            if ( $refund_id > 0 ) {
                $existing[] = $refund_id;
                tejcart_update_order_meta( $order_id, self::PARTIAL_ROLLBACK_META_KEY, $existing );
            }
            return;
        }

        $email = method_exists( $order, 'get_customer_email' )
            ? (string) $order->get_customer_email()
            : '';

        foreach ( $codes as $code ) {
            $code = trim( (string) $code );
            if ( '' === $code ) {
                continue;
            }
            $coupon = Coupon::get_by_code( $code );
            if ( ! $coupon instanceof Coupon ) {
                continue;
            }
            $coupon->decrement_usage();
            if ( '' !== $email ) {
                $coupon->release_usage_for_user( $email );
            }
        }

        if ( $refund_id > 0 ) {
            $existing[] = $refund_id;
            tejcart_update_order_meta( $order_id, self::PARTIAL_ROLLBACK_META_KEY, $existing );
        }
    }

    /**
     * Pull the list of coupon codes off an order. Some orders carry a single
     * `coupon_code` column, some carry a comma-separated list, and Tier-2
     * Advanced Coupons stores an array on order meta — handle all three.
     *
     * @param object $order
     * @return string[]
     */
    private function resolve_codes( $order ): array {
        $codes = array();

        if ( method_exists( $order, 'get_coupon_code' ) ) {
            $raw = (string) $order->get_coupon_code();
            if ( '' !== $raw ) {
                foreach ( preg_split( '/\s*,\s*/', $raw ) as $piece ) {
                    if ( '' !== $piece ) {
                        $codes[] = $piece;
                    }
                }
            }
        }

        $meta_codes = tejcart_get_order_meta( (int) $order->get_id(), '_tejcart_coupon_codes' );
        if ( is_array( $meta_codes ) ) {
            foreach ( $meta_codes as $piece ) {
                if ( is_string( $piece ) && '' !== $piece ) {
                    $codes[] = $piece;
                }
            }
        }

        // Audit #46 / 02 M-4 — coupon codes are matched case-
        // insensitively elsewhere in the codebase (Coupon::find_by_code
        // lower-cases before lookup). Without folding here, a code
        // recorded as 'SUMMER10' on get_coupon_code() AND 'summer10'
        // in the meta list passed through `array_unique` as two
        // distinct entries, then both rollback codepaths fired
        // against the same coupon row — net `usage_count` went two
        // steps down for one applied use.
        $codes = array_map( 'strtolower', $codes );
        return array_values( array_unique( $codes ) );
    }
}
