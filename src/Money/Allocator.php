<?php
/**
 * Weighted money allocation via the largest-remainder method.
 *
 * Splits a single rounded total across N line items so the per-line
 * allocations sum to the total exactly, with no float arithmetic. This
 * is the canonical helper for proportional tax, order-level percentage
 * discounts, and multi-line refund splits.
 *
 * Used by Cart_Calculator, Order_Refund, and tax providers; see
 * `docs/money-representation.md` §2.3 for the design rationale and
 * cross-vendor references (Stripe Tax, Avalara, TaxJar).
 *
 * @package TejCart\Money
 */

declare( strict_types = 1 );

namespace TejCart\Money;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Largest-remainder (Hamilton method) money allocator.
 *
 * Algorithm:
 *   1. Compute each slot's exact share as floor(weight * total / sum_of_weights).
 *   2. Track the integer remainder (weight * total) mod sum_of_weights per slot.
 *   3. The shortfall against the input total is the number of remainders > 0.
 *   4. Distribute one extra minor unit to the slots with the largest remainders,
 *      tie-breaking by slot index ascending so the result is deterministic.
 *
 * Properties:
 *   - Sum of returned Monies equals the input total exactly (no drift).
 *   - Deterministic for a given (total, weights) pair.
 *   - Stable under negative totals (refunds): signs are preserved per slot.
 *   - Slots with zero weight get Money::zero(currency) and never get
 *     distributed remainder, so a $0 line never accidentally absorbs tax.
 */
final class Allocator {

    /**
     * Allocate the total across weighted slots.
     *
     * @param Money $total   The amount to split.
     * @param int[] $weights Non-negative integer weights, one per slot.
     *                       Typically line subtotals in minor units.
     * @return Money[]       Indexed array of Money, one per slot, same currency.
     *
     * @throws \InvalidArgumentException If weights is empty, contains a
     *                                   negative value, or sums to zero
     *                                   while total is non-zero.
     */
    public static function allocate( Money $total, array $weights ): array {
        $count = count( $weights );
        if ( 0 === $count ) {
            throw new \InvalidArgumentException( 'Cannot allocate across zero slots' );
        }

        $sum = 0;
        foreach ( $weights as $i => $w ) {
            if ( ! is_int( $w ) ) {
                throw new \InvalidArgumentException(
                    sprintf( 'Weight at index %d must be integer', (int) $i )
                );
            }
            if ( $w < 0 ) {
                throw new \InvalidArgumentException(
                    sprintf( 'Weight at index %d is negative', (int) $i )
                );
            }
            $sum += $w;
        }

        $currency   = $total->currency();
        $total_minor = $total->as_minor_units();

        // Degenerate case — all weights zero. If the total is also zero
        // we return zero-Money per slot (typical "no items to tax against
        // a $0 cart"). If the total is non-zero, the caller has asked us
        // to split something across nothing, which is meaningless.
        if ( 0 === $sum ) {
            if ( 0 !== $total_minor ) {
                throw new \InvalidArgumentException(
                    'Cannot allocate non-zero total across zero-sum weights'
                );
            }
            $zero = Money::zero( $currency );
            return array_fill( 0, $count, $zero );
        }

        // Sign handling — split |total| and reapply the sign per slot so
        // refunds/credits allocate the same way as charges. Largest-
        // remainder on a negative numerator with PHP's intdiv truncates
        // toward zero, which would distribute the remainder against the
        // intended direction; absolute value + reapply keeps the contract.
        $sign      = $total_minor < 0 ? -1 : 1;
        $abs_total = abs( $total_minor );

        $base       = array();
        $remainders = array();
        $allocated  = 0;

        foreach ( $weights as $i => $w ) {
            if ( 0 === $w ) {
                $base[ $i ]       = 0;
                $remainders[ $i ] = 0;
                continue;
            }
            $numerator        = $abs_total * $w;
            $base[ $i ]       = intdiv( $numerator, $sum );
            $remainders[ $i ] = $numerator - ( $base[ $i ] * $sum );
            $allocated       += $base[ $i ];
        }

        $shortfall = $abs_total - $allocated;

        if ( $shortfall > 0 ) {
            // Distribute the shortfall to the slots with the largest
            // remainders, tie-breaking by index ascending. Pre-sort by
            // (-remainder, index) so the first $shortfall entries are
            // exactly the recipients. usort is stable in PHP 8+, but we
            // index-tie-break explicitly so the contract is independent
            // of sort stability.
            $order = array();
            foreach ( $remainders as $i => $r ) {
                if ( $r > 0 ) {
                    $order[] = array( $i, $r );
                }
            }
            usort(
                $order,
                static function ( array $a, array $b ): int {
                    if ( $a[1] !== $b[1] ) {
                        return $b[1] <=> $a[1];
                    }
                    return $a[0] <=> $b[0];
                }
            );
            $give = min( $shortfall, count( $order ) );
            for ( $k = 0; $k < $give; $k++ ) {
                $base[ $order[ $k ][0] ] += 1;
            }
        }

        $out = array();
        foreach ( $base as $i => $minor ) {
            $out[ $i ] = Money::from_minor_units( $sign * $minor, $currency );
        }

        return $out;
    }
}
