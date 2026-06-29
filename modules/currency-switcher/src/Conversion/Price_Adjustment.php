<?php
/**
 * Psychological-pricing adjustments applied after FX conversion.
 *
 * @package TejCart\Currency_Switcher\Conversion
 */

declare(strict_types=1);

namespace TejCart\Currency_Switcher\Conversion;

use TejCart\Currency_Switcher\Options;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Applies per-range rounding + charm pricing to a converted price.
 *
 * Six fixed price bands (0–9.99, 10–99.99, 100–499.99, 500–999.99,
 * 1000–9999.99, 10000+). Each band has an admin-configured rounding
 * step (1 / 5 / 10 / 50 / 99 / 100) and a charm ending (.99 / .95 /
 * .49 / none). The band the price falls into wins; bands not marked
 * `enabled` pass through untouched.
 *
 * Settings shape (stored under {@see Options::PRICE_ADJUSTMENT}):
 *   [
 *     [ 'min' => 0,     'max' => 9.99,    'enabled' => true,  'rounding' => 1,  'charm' => 0.99 ],
 *     [ 'min' => 10,    'max' => 99.99,   'enabled' => true,  'rounding' => 5,  'charm' => 0.99 ],
 *     ...
 *   ]
 */
final class Price_Adjustment {
    /**
     * Range catalogue: { min, max, allowed_rounding[], allowed_charm[] }.
     *
     * @return array<int, array{min: float, max: float, allowed_rounding: array<int, int>, allowed_charm: array<int, float>}>
     */
    public static function ranges(): array {
        $charms = array( 0.99, 0.95, 0.49, 0.0 );
        return array(
            array( 'min' => 0.0,     'max' => 9.99,     'allowed_rounding' => array( 1 ),                       'allowed_charm' => $charms ),
            array( 'min' => 10.0,    'max' => 99.99,    'allowed_rounding' => array( 1, 5 ),                    'allowed_charm' => $charms ),
            array( 'min' => 100.0,   'max' => 499.99,   'allowed_rounding' => array( 1, 5 ),                    'allowed_charm' => $charms ),
            array( 'min' => 500.0,   'max' => 999.99,   'allowed_rounding' => array( 1, 5, 10 ),                'allowed_charm' => $charms ),
            array( 'min' => 1000.0,  'max' => 9999.99,  'allowed_rounding' => array( 1, 5, 10, 50 ),            'allowed_charm' => $charms ),
            array( 'min' => 10000.0, 'max' => PHP_FLOAT_MAX, 'allowed_rounding' => array( 1, 5, 10, 50, 99, 100 ), 'allowed_charm' => $charms ),
        );
    }

    /**
     * Apply the configured range rule to a converted price.
     *
     * Returns the input unchanged if no range matches or the matching
     * range is disabled. Always rounds the final value to `$decimals`
     * decimal places so the caller doesn't need to.
     *
     * If `$base_amount_ref` is supplied (the un-adjusted converted
     * amount in the same currency), the result is clamped to never be
     * smaller than that reference. This prevents charm pricing from
     * inflating sub-step prices (e.g. converted 0.10 EUR ceil-stepped
     * to 1.00, then charm-discounted to 0.99 — a 10× markup on what
     * should be 0.10).
     */
    public function apply( float $price, int $decimals = 2, ?float $base_amount_ref = null ): float {
        if ( $price <= 0 ) {
            return round( $price, $decimals );
        }

        $settings = get_option( Options::PRICE_ADJUSTMENT, array() );
        if ( ! is_array( $settings ) || empty( $settings ) ) {
            return round( $price, $decimals );
        }

        foreach ( self::ranges() as $idx => $range ) {
            if ( $price < $range['min'] || $price > $range['max'] ) {
                continue;
            }
            $config = $settings[ $idx ] ?? null;
            if ( ! is_array( $config ) || empty( $config['enabled'] ) ) {
                return round( $price, $decimals );
            }

            $rounding = isset( $config['rounding'] ) ? (int) $config['rounding'] : 0;
            $charm    = isset( $config['charm'] ) ? (float) $config['charm'] : 0.0;

            if ( ! in_array( $rounding, $range['allowed_rounding'], true ) || $rounding <= 0 ) {
                return round( $price, $decimals );
            }

            // Charm endings like .99 / .95 / .49 are meaningless for
            // zero-decimal currencies (JPY, KRW, VND, CLP …) — the
            // final round($result, 0) would strip the fractional part
            // and silently defeat the charm. Suppress it so the admin's
            // rounding-step still applies without a confusing no-op tail.
            if ( 0 === $decimals && $charm > 0.0 && $charm < 1.0 ) {
                $charm = 0.0;
            }

            $adjusted = $this->adjust( $price, $rounding, $charm );

            // Refuse to inflate a price above its already-converted
            // value. The rounding step is meant to nudge prices up to a
            // psychological band; when the input is already inside the
            // band (e.g. price 1.25, rounding step 1, charm .99) the
            // result must never exceed the original — otherwise
            // charm-pricing turns into a silent markup engine.
            if ( null !== $base_amount_ref && $base_amount_ref > 0.0 && $adjusted > $base_amount_ref ) {
                return round( $base_amount_ref, $decimals );
            }

            return round( $adjusted, $decimals );
        }

        return round( $price, $decimals );
    }

    /**
     * Run the rounding + charm algorithm:
     *
     *   if rounding == 99:
     *       p = ceil(p / 100) * 100 - 1                    (e.g. 12 345 -> 12 399)
     *   else:
     *       p = ceil(p / rounding) * rounding              (round up to step)
     *
     *   if 0 < charm < rounding:
     *       p -= (rounding - charm)                        (charm tail)
     */
    public function adjust( float $price, int $rounding, float $charm ): float {
        if ( 99 === $rounding ) {
            $rounded = ( ceil( $price / 100.0 ) * 100.0 ) - 1.0;
        } else {
            $rounded = ceil( $price / $rounding ) * $rounding;
        }

        if ( $charm > 0 && $charm < $rounding ) {
            $rounded -= ( $rounding - $charm );
        }

        return $rounded;
    }
}
