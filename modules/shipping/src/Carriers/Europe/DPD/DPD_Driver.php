<?php
/**
 * DPD UK driver.
 *
 * DPD's live shipping API (api.dpd.co.uk) requires a contracted account
 * plus a two-step GeoSession authentication flow that is gated behind
 * the merchant's commercial agreement. As with Evri/Royal Mail, the
 * common production pattern is a config-driven rate card based on the
 * merchant's negotiated services. The driver therefore mirrors the
 * Evri / Royal Mail shape.
 *
 * Labels & tracking: DPD's `api.dpd.co.uk` ship/track endpoints require
 * the GeoSession + contracted-account flow; we leave `buy_label()` /
 * `track()` as the abstract throws so the Label_Service surfaces a
 * clear "this carrier doesn't support label purchase yet" message.
 * Use EasyPost or Shippo for federated DPD label access.
 *
 * @package TejCart\Shipping_Plugin\Carriers\Europe\DPD
 */

namespace TejCart\Shipping_Plugin\Carriers\Europe\DPD;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DPD_Driver extends Abstract_Carrier_Driver {
    public function id(): string {
        return 'dpd';
    }

    public function label(): string {
        return 'DPD';
    }

    public function region(): string {
        return 'europe';
    }

    public function credential_fields(): array {
        return array(
            'rate_table' => array(
                'type'        => 'textarea',
                'title'       => __( 'Rate table (JSON)', 'tejcart' ),
                'description' => __( 'Map of network code to weight-band rate card. See plugin docs.', 'tejcart' ),
                'secret'      => false,
            ),
            'allowed_countries' => array(
                'type'        => 'text',
                'title'       => __( 'Allowed destination countries', 'tejcart' ),
                'description' => __( 'Comma-separated ISO-3166 codes (e.g. "GB,IE,FR,DE"). Leave blank for all.', 'tejcart' ),
                'default'     => 'GB',
                'secret'      => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $table_raw = trim( (string) ( $credentials['rate_table'] ?? '' ) );
        if ( '' === $table_raw ) {
            throw new Carrier_Exception( 'DPD: missing rate_table credential.' );
        }

        $table = json_decode( $table_raw, true );
        if ( ! is_array( $table ) ) {
            throw new Carrier_Exception( 'DPD: rate_table contains invalid JSON — check the configuration.' );
        }

        $allowed = $this->parse_allowed_countries( (string) ( $credentials['allowed_countries'] ?? '' ) );
        $dest    = strtoupper( $request->destination['country'] ?? '' );
        if ( array() !== $allowed && ! in_array( $dest, $allowed, true ) ) {
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
                service_label: (string) ( $service['label'] ?? ( 'DPD ' . $code ) ),
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
     * @return array<int,string>
     */
    private function parse_allowed_countries( string $raw ): array {
        $raw = trim( $raw );
        if ( '' === $raw ) {
            return array();
        }
        $parts = preg_split( '/[,\s]+/', $raw ) ?: array();
        $out   = array();
        foreach ( $parts as $part ) {
            $cc = strtoupper( trim( $part ) );
            if ( '' !== $cc ) {
                $out[] = $cc;
            }
        }
        return $out;
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
