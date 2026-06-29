<?php
/**
 * Box-packing helper.
 *
 * Real 3D bin-packing (e.g. py3dbp, BoxesJS) is overkill for most stores
 * and prohibitively complex without a real geometry library. We ship a
 * pragmatic two-mode packer:
 *
 *   - "per_item": one Package per item-unit (the default — preserves
 *     per-item dimensions, which is what carriers actually price on).
 *   - "weight_tier": consolidate items into uniform boxes by total weight,
 *     using a merchant-defined ladder of (max_grams → box dimensions).
 *
 * The chosen mode is filterable so high-volume merchants can replace
 * either with a domain-specific algorithm.
 *
 * @package TejCart\Shipping_Plugin\Core
 */

namespace TejCart\Shipping_Plugin\Core;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class Box_Packer {
    /**
     * Pack a list of "atomic" packages (one per item-unit) using the
     * configured strategy. Strategy is read from the
     * `tejcart_shipping_packer_strategy` filter (default: per_item).
     *
     * @param Package[] $packages
     * @return Package[]
     */
    public function pack( array $packages, string $driver_id = '' ): array {
        if ( array() === $packages ) {
            return $packages;
        }

        /**
         * Filter the box-packing strategy.
         *
         * @param string    $strategy   "per_item" | "weight_tier" | custom.
         * @param Package[] $packages
         * @param string    $driver_id
         */
        $strategy = (string) apply_filters( 'tejcart_shipping_packer_strategy', 'per_item', $packages, $driver_id );

        return match ( $strategy ) {
            'weight_tier' => $this->weight_tier( $packages, $driver_id ),
            'per_item'    => $packages,
            default       => $this->custom( $strategy, $packages, $driver_id ),
        };
    }

    /**
     * Weight-tier packing — a merchant supplies tiers via the
     * `tejcart_shipping_weight_tiers` filter as an array of
     * `[max_grams, length_mm, height_mm, depth_mm]` rows; items are
     * consolidated into the smallest tier whose max_grams is >= total
     * remaining weight, repeatedly, until packed.
     *
     * @param Package[] $packages
     * @return Package[]
     */
    private function weight_tier( array $packages, string $driver_id ): array {
        $tiers = apply_filters( 'tejcart_shipping_weight_tiers', array(
            // Default ladder mirrors USPS Priority Flat Rate sizes.
            array( 'max_grams' => 1_000,  'length_mm' => 220, 'height_mm' => 140, 'depth_mm' => 40 ),
            array( 'max_grams' => 5_000,  'length_mm' => 280, 'height_mm' => 220, 'depth_mm' => 140 ),
            array( 'max_grams' => 30_000, 'length_mm' => 305, 'height_mm' => 305, 'depth_mm' => 152 ),
        ), $packages, $driver_id );

        if ( ! is_array( $tiers ) || array() === $tiers ) {
            // Misconfigured filter — the merchant asked for weight-tier packing
            // but supplied no tiers. Without a log line they see "carrier
            // returns no rates" with no clue why. Throttle so a stuck
            // checkout doesn't flood the log.
            $this->log_misconfiguration( $driver_id, 'tejcart_shipping_weight_tiers returned an empty list' );
            return $packages;
        }

        usort( $tiers, static fn ( $a, $b ): int => (int) ( $a['max_grams'] ?? 0 ) <=> (int) ( $b['max_grams'] ?? 0 ) );

        $total_weight = 0;
        $total_value  = 0;
        foreach ( $packages as $p ) {
            $total_weight += $p->weight_grams;
            $total_value  += $p->declared_value_cents;
        }

        $boxes        = array();
        $largest_tier = end( $tiers );
        $largest_max  = (int) ( $largest_tier['max_grams'] ?? 0 );
        $remaining    = $total_weight;
        $value_left   = $total_value;

        while ( $remaining > 0 ) {
            $tier = null;
            foreach ( $tiers as $candidate ) {
                if ( $remaining <= (int) ( $candidate['max_grams'] ?? 0 ) ) {
                    $tier = $candidate;
                    break;
                }
            }
            $tier ??= $largest_tier;
            if ( null === $tier ) {
                break;
            }

            $box_weight = min( $remaining, (int) ( $tier['max_grams'] ?? $largest_max ) );
            $box_value  = $total_weight > 0 ? (int) round( $total_value * ( $box_weight / $total_weight ) ) : 0;
            if ( $box_value > $value_left ) {
                $box_value = $value_left;
            }

            $boxes[]    = new Package(
                weight_grams: $box_weight,
                length_mm: (int) ( $tier['length_mm'] ?? 0 ),
                height_mm: (int) ( $tier['height_mm'] ?? 0 ),
                depth_mm: (int) ( $tier['depth_mm'] ?? 0 ),
                declared_value_cents: $box_value
            );
            $remaining  -= $box_weight;
            $value_left -= $box_value;
        }

        return array() === $boxes ? $packages : $boxes;
    }

    /**
     * Throttled log helper for packer misconfiguration. One line per
     * driver+reason+hour so a wedged checkout cannot flood the log file.
     */
    private function log_misconfiguration( string $driver_id, string $reason ): void {
        if ( ! function_exists( 'tejcart_log' ) ) {
            return;
        }
        $bucket = 'tejcart_ship_pack_misconfig_' . md5( $driver_id . '|' . $reason . '|' . gmdate( 'YmdH' ) );
        if ( false !== get_transient( $bucket ) ) {
            return;
        }
        set_transient( $bucket, 1, HOUR_IN_SECONDS );
        tejcart_log(
            sprintf(
                'Box_Packer: %s for driver "%s" — falling back to per-item packing.',
                $reason,
                '' !== $driver_id ? $driver_id : '(unknown)'
            ),
            'warning'
        );
    }

    /**
     * @param Package[] $packages
     * @return Package[]
     */
    private function custom( string $strategy, array $packages, string $driver_id ): array {
        /**
         * Custom packer hook. Listeners receive the original atomic
         * package list and must return a Package[]. Return the input
         * unchanged to opt out.
         *
         * @param Package[] $packages
         * @param string    $strategy
         * @param string    $driver_id
         */
        $custom = apply_filters( 'tejcart_shipping_pack_custom', $packages, $strategy, $driver_id );
        if ( ! is_array( $custom ) || array() === $custom ) {
            return $packages;
        }
        $clean = array();
        foreach ( $custom as $p ) {
            if ( $p instanceof Package ) {
                $clean[] = $p;
            }
        }
        return array() === $clean ? $packages : $clean;
    }
}
