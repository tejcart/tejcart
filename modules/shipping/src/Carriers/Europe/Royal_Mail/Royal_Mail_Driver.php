<?php
/**
 * Royal Mail driver.
 *
 * Royal Mail's live rate API (Spotlight / Send Online Rates) is
 * gated behind an OBA contract account and lacks a generic "shop for
 * cheapest service" endpoint — production integrations typically use
 * the merchant's negotiated rate card. This driver therefore takes a
 * declarative rate-table JSON in its credentials and resolves quotes
 * locally without an outbound HTTP call. It conforms to the same
 * `Abstract_Carrier_Driver` contract as the live-API drivers, so the
 * rest of the plugin (caching, settings UI, zone bridging) treats it
 * identically.
 *
 * Rate-table schema (JSON object):
 *   {
 *     "STL1": { "label": "Tracked 24", "max_grams": 2000,
 *               "bands": [ {"up_to_grams": 100, "price_pence": 395},
 *                          {"up_to_grams": 1000, "price_pence": 595} ] },
 *     "TPM48": { ... }
 *   }
 *
 * Labels & tracking: Royal Mail's Shipping API V3 is **contract-gated**
 * (only OBA merchants can call `/shipments/v3/...`). We deliberately
 * leave `buy_label()` and `track()` as the abstract throws so the
 * Label_Service surfaces "this carrier doesn't support label purchase
 * yet" rather than failing silently. Merchants who need labels should
 * use the EasyPost or Shippo drivers, which federate Royal Mail labels
 * via their aggregator accounts.
 *
 * @package TejCart\Shipping_Plugin\Carriers\Europe\Royal_Mail
 */

namespace TejCart\Shipping_Plugin\Carriers\Europe\Royal_Mail;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Royal_Mail_Driver extends Abstract_Carrier_Driver {
    public function id(): string {
        return 'royal_mail';
    }

    public function label(): string {
        return 'Royal Mail';
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
            throw new Carrier_Exception( 'Royal Mail: missing rate_table credential.' );
        }

        $table = json_decode( $table_raw, true );
        if ( ! is_array( $table ) ) {
            throw new Carrier_Exception( 'Royal Mail: rate_table contains invalid JSON — check the configuration.' );
        }

        $domestic_only = 'yes' === ( $credentials['domestic_only'] ?? 'yes' );
        $destination   = strtoupper( $request->destination['country'] ?? '' );
        if ( $domestic_only && 'GB' !== $destination ) {
            return array();
        }

        return $this->build_quotes_from_table( $request, $table );
    }

    /**
     * Compute quotes from a parsed rate table. Public so unit tests
     * can exercise the band-selection logic without parsing JSON.
     *
     * @param array<string,mixed> $table
     * @return Rate_Quote[]
     */
    public function build_quotes_from_table( Rate_Request $request, array $table ): array {
        $weight_grams = 0;
        foreach ( $request->packages as $package ) {
            $weight_grams += $package->weight_grams;
        }

        $quotes = array();
        foreach ( $table as $service_code => $service ) {
            if ( ! is_array( $service ) ) {
                continue;
            }

            $code = (string) $service_code;
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
                service_label: (string) ( $service['label'] ?? ( 'Royal Mail ' . $code ) ),
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
     * Pick the lowest-priced band whose `up_to_grams` is >= weight.
     * Returns null when no band can carry the weight.
     *
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
            if ( $up_to <= 0 || $price <= 0 ) {
                continue;
            }
            if ( $weight_grams > $up_to ) {
                continue;
            }
            if ( null === $best || $price < $best ) {
                $best = $price;
            }
        }
        return $best;
    }
}
