<?php
/**
 * Tax_Calculator — extracted from Cart_Calculator (#1202 first slice).
 *
 * Owns the pure per-line tax math: given a cart, the cart's running
 * subtotal/discount totals, and a default tax rate, compute the line-
 * weighted tax amount that respects product-level tax classes when a
 * Tax_Manager is supplied.
 *
 * This is the first step of the Cart_Calculator god-class refactor
 * (1,424 LOC / 33 methods). Full extraction of `calculate_tax` and the
 * customer-address helpers is tracked in #1244.
 *
 * Pure / dependency-injected: zero direct global access. Cart_Calculator
 * holds the request state and delegates the math here.
 *
 * @package TejCart\Cart
 */

declare( strict_types = 1 );

namespace TejCart\Cart;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Tax_Calculator {

    /**
     * Compute total tax across all cart lines, weighting each line by
     * the cart-level discount factor and applying per-product tax
     * classes when a Tax_Manager is provided.
     *
     * Returns the total tax amount in major units (float). Cent-integer
     * conversion is the caller's responsibility.
     *
     * @param Cart|object                         $cart           Cart (or any duck-typed object exposing `get_items()`).
     * @param float                               $subtotal       Cart subtotal (major units).
     * @param float                               $discount_total Cart discount total (major units).
     * @param float                               $tax_rate_pct   Default tax rate (percent).
     * @param \TejCart\Tax\Tax_Manager|null       $tax_manager    Optional manager for per-class lookups.
     * @param string                              $country        ISO country code for class lookups.
     * @param string                              $state          State / region code.
     */
    public static function per_line_tax(
        $cart,
        float $subtotal,
        float $discount_total,
        float $tax_rate_pct,
        $tax_manager = null,
        string $country = '',
        string $state = '',
        string $currency = ''
    ): float {
        if ( ! is_object( $cart ) || ! method_exists( $cart, 'get_items' ) ) {
            return 0.0;
        }

        $factor  = $subtotal > 0 ? ( $subtotal - $discount_total ) / $subtotal : 0.0;
        $default = $tax_rate_pct / 100;
        $total   = 0.0;

        foreach ( $cart->get_items() as $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_line_total' ) ) {
                continue;
            }

            $line_total = (float) $item->get_line_total();
            $taxable    = $line_total * $factor;

            $rate = $default;

            if ( $tax_manager && method_exists( $item, 'get_product' ) ) {
                $product = $item->get_product();
                if ( is_object( $product ) && method_exists( $product, 'get_tax_class' ) ) {
                    $class = (string) $product->get_tax_class();
                    if ( '' !== $class ) {
                        $class_rate = $tax_manager->get_tax_rate( $country, $state, $class );
                        if ( ! empty( $class_rate ) && isset( $class_rate['rate'] ) ) {
                            $rate = (float) $class_rate['rate'] / 100;
                        }
                    }
                }
            }

            // Audit H-10: pass the cart currency through so per-line
            // rounding honours JPY (0 decimals) / KWD/BHD/OMR (3
            // decimals) — the bare 1-arg form hardcoded 2.
            $total += \TejCart\Tax\Tax_Manager::round_tax( $taxable * $rate, $currency );
        }

        return (float) $total;
    }

    /**
     * Per-line inclusive-tax split honouring product tax classes.
     *
     * The tax-INCLUSIVE companion to {@see self::per_line_tax()}. Given the
     * gross (tax-laden) subtotal and discount, it strips the embedded tax
     * per line at each line's resolved class rate, so a mixed-rate cart
     * (e.g. a 20% standard item beside a 5% reduced one) reports the
     * correct blended tax instead of one store-wide rate.
     *
     * Exactness: the per-line gross and discount are derived by allocating
     * the *authoritative* gross Money totals across line weights via
     * {@see \TejCart\Money\Allocator}, so the per-line parts sum back to the
     * inputs with zero float drift, and every strip / portion uses Money's
     * banker's-rounded integer division (so `gross == net + tax` per line).
     *
     * Returns null when the cart shape is unusable, when there are no
     * lines, or when *every* line resolves to $default_rate_bp. In those
     * cases the caller keeps its existing single-rate whole-cart strip, so
     * behaviour is byte-identical for the common single-tax-class case.
     *
     * @param Cart|object                   $cart            Cart exposing get_items().
     * @param \TejCart\Money\Money           $subtotal_gross  Gross (tax-laden) subtotal.
     * @param \TejCart\Money\Money           $discount_gross  Gross (tax-laden) discount total.
     * @param int                            $default_rate_bp Store rate in basis points (fallback for unclassified lines).
     * @param \TejCart\Tax\Tax_Manager|null  $tax_manager     Manager for per-class lookups.
     * @param string                         $country         ISO country code for class lookups.
     * @param string                         $state           State / region code.
     * @return array{tax:\TejCart\Money\Money,net_subtotal:\TejCart\Money\Money,net_discount:\TejCart\Money\Money}|null
     */
    public static function per_line_inclusive(
        $cart,
        \TejCart\Money\Money $subtotal_gross,
        \TejCart\Money\Money $discount_gross,
        int $default_rate_bp,
        $tax_manager = null,
        string $country = '',
        string $state = ''
    ): ?array {
        if ( $default_rate_bp < 0 || ! is_object( $cart ) || ! method_exists( $cart, 'get_items' ) ) {
            return null;
        }

        $currency = $subtotal_gross->currency();
        $weights  = array();
        $rates    = array();
        $diverges = false;

        foreach ( $cart->get_items() as $item ) {
            if ( ! is_object( $item ) || ! method_exists( $item, 'get_line_total' ) ) {
                // Unexpected shape — fall back to the safe single-rate strip.
                return null;
            }

            // Weights are relative only: the Allocator distributes the
            // authoritative gross Money against them, so any float drift in
            // a weight cannot unbalance the per-line sums.
            $line_minor = (int) round(
                (float) $item->get_line_total() * \TejCart\Money\Currency::multiplier( $currency ),
                0,
                PHP_ROUND_HALF_EVEN
            );
            $weights[] = max( 0, $line_minor );

            $rate_bp = $default_rate_bp;
            if ( $tax_manager && method_exists( $item, 'get_product' ) ) {
                $product = $item->get_product();
                if ( is_object( $product ) && method_exists( $product, 'get_tax_class' ) ) {
                    $class = (string) $product->get_tax_class();
                    if ( '' !== $class ) {
                        $class_rate = $tax_manager->get_tax_rate( $country, $state, $class );
                        if ( ! empty( $class_rate ) && isset( $class_rate['rate'] ) ) {
                            $rate_bp = (int) round( (float) $class_rate['rate'] * 100, 0, PHP_ROUND_HALF_EVEN );
                        }
                    }
                }
            }
            if ( $rate_bp < 0 ) {
                $rate_bp = 0;
            }
            if ( $rate_bp !== $default_rate_bp ) {
                $diverges = true;
            }
            $rates[] = $rate_bp;
        }

        // No per-line class divergence — let the caller keep its exact,
        // already-tested single-rate strip.
        if ( empty( $weights ) || ! $diverges ) {
            return null;
        }

        // Allocator cannot split a non-zero total across zero-sum weights.
        if ( 0 === array_sum( $weights ) ) {
            return null;
        }

        $gross_subs = \TejCart\Money\Allocator::allocate( $subtotal_gross, $weights );
        $gross_disc = \TejCart\Money\Allocator::allocate( $discount_gross, $weights );

        $tax          = \TejCart\Money\Money::zero( $currency );
        $net_subtotal = \TejCart\Money\Money::zero( $currency );
        $net_discount = \TejCart\Money\Money::zero( $currency );

        foreach ( $rates as $i => $rate_bp ) {
            $line_gross    = $gross_subs[ $i ];
            $line_discount = $gross_disc[ $i ];
            $taxable_gross = $line_gross->subtract( $line_discount );

            $tax          = $tax->add( $taxable_gross->inclusive_tax_portion( $rate_bp ) );
            $net_subtotal = $net_subtotal->add( $line_gross->strip_inclusive_tax( $rate_bp ) );
            $net_discount = $net_discount->add( $line_discount->strip_inclusive_tax( $rate_bp ) );
        }

        return array(
            'tax'          => $tax,
            'net_subtotal' => $net_subtotal,
            'net_discount' => $net_discount,
        );
    }
}
