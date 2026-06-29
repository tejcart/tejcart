<?php
/**
 * Evri (formerly Hermes UK) driver.
 *
 * Evri's live rate API requires a B2B portal account and varies by
 * negotiated contract; production integrations therefore use the
 * merchant's negotiated service list and weight bands. The driver is
 * config-driven, mirroring `Royal_Mail_Driver`'s shape.
 *
 * Rate-table schema (JSON object):
 *   {
 *     "TRACKED_24": { "label": "Evri Tracked 24",  "max_grams": 15000,
 *                     "bands": [ {"up_to_grams": 1000, "price_pence": 295}, ... ] },
 *     ...
 *   }
 *
 * Labels & tracking: Evri's label API is contract-gated (B2B portal
 * customers only). `buy_label()` / `track()` deliberately fall through
 * to the abstract throws — use the EasyPost aggregator driver for
 * Evri labels via federated access.
 *
 * @package TejCart\Shipping_Plugin\Carriers\Europe\Evri
 */

namespace TejCart\Shipping_Plugin\Carriers\Europe\Evri;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Evri_Driver extends Abstract_Carrier_Driver {
    public function id(): string {
        return 'evri';
    }

    public function label(): string {
        return 'Evri';
    }

    public function region(): string {
        return 'europe';
    }

    public function credential_fields(): array {
        return array(
            'rate_table' => array(
                'type'        => 'textarea',
                'title'       => __( 'Rate table (JSON)', 'tejcart' ),
                'description' => __( 'Map of service code to weight-band rate card. See plugin docs.', 'tejcart' ),
                'secret'      => false,
            ),
            'domestic_only' => array(
                'type'    => 'checkbox',
                'title'   => __( 'GB destinations only', 'tejcart' ),
                'default' => 'yes',
                'secret'  => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $table_raw = trim( (string) ( $credentials['rate_table'] ?? '' ) );
        if ( '' === $table_raw ) {
            throw new Carrier_Exception( 'Evri: missing rate_table credential.' );
        }

        $table = json_decode( $table_raw, true );
        if ( ! is_array( $table ) ) {
            throw new Carrier_Exception( 'Evri: rate_table contains invalid JSON — check the configuration.' );
        }

        $domestic_only = 'yes' === ( $credentials['domestic_only'] ?? 'yes' );
        $destination   = strtoupper( $request->destination['country'] ?? '' );
        if ( $domestic_only && 'GB' !== $destination ) {
            return array();
        }

        return $this->build_quotes_from_table( $request, $table );
    }

    /**
     * @param array<string,mixed> $table
     * @return Rate_Quote[]
     */
    public function build_quotes_from_table( Rate_Request $request, array $table ): array {
        $weight_grams = 0;
        foreach ( $request->packages as $package ) {
            $weight_grams += $package->weight_grams;
        }

        $quotes = array();
        foreach ( $table as $code => $service ) {
            if ( ! is_array( $service ) ) {
                continue;
            }
            $code = (string) $code;
            if ( '' === $code ) {
                continue;
            }

            $max = isset( $service['max_grams'] ) ? (int) $service['max_grams'] : 0;
            if ( $max > 0 && $weight_grams > $max ) {
                continue;
            }

            $bands = $service['bands'] ?? array();
            if ( ! is_array( $bands ) || array() === $bands ) {
                continue;
            }

            $pence = $this->resolve_band_price( $bands, $weight_grams );
            if ( null === $pence ) {
                continue;
            }

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $code,
                service_label: (string) ( $service['label'] ?? ( 'Evri ' . $code ) ),
                cost_cents:    $pence,
                currency:      'GBP',
                eta_days:      isset( $service['eta_days'] ) ? (int) $service['eta_days'] : null,
                rate_id:       null,
                meta:          array()
            );
        }

        return $quotes;
    }

    /**
     * @param array<int,mixed> $bands
     */
    private function resolve_band_price( array $bands, int $weight_grams ): ?int {
        $best = null;
        foreach ( $bands as $band ) {
            if ( ! is_array( $band ) ) {
                continue;
            }
            $up_to = (int) ( $band['up_to_grams'] ?? 0 );
            $price = (int) ( $band['price_pence'] ?? 0 );
            if ( $up_to <= 0 || $price <= 0 || $weight_grams > $up_to ) {
                continue;
            }
            if ( null === $best || $price < $best ) {
                $best = $price;
            }
        }
        return $best;
    }
}
