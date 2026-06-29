<?php
/**
 * Shippo aggregator driver.
 *
 * Shippo is a multi-carrier aggregator (USPS, UPS, FedEx, DHL,
 * Canada Post, Royal Mail, plus regional carriers). Auth is a single
 * `ShippoToken` API key in the Authorization header.
 *
 * API reference: https://goshippo.com/docs/reference
 *
 * @package TejCart\Shipping_Plugin\Carriers\Aggregators\Shippo
 */

namespace TejCart\Shipping_Plugin\Carriers\Aggregators\Shippo;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Address_Validation_Result;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shippo_Driver extends Abstract_Carrier_Driver {
    public const ENDPOINT          = 'https://api.goshippo.com/shipments/';
    public const TRANSACTIONS_API  = 'https://api.goshippo.com/transactions/';
    public const TRACKING_API      = 'https://api.goshippo.com/tracks/';

    public function id(): string {
        return 'shippo';
    }

    public function label(): string {
        return 'Shippo';
    }

    public function region(): string {
        return 'aggregator';
    }

    public function credential_fields(): array {
        return array(
            'api_token' => array(
                'type'        => 'password',
                'title'       => __( 'API token', 'tejcart' ),
                'description' => __( 'Live or test Shippo token (begins with "shippo_live_" or "shippo_test_").', 'tejcart' ),
                'secret'      => true,
            ),
            'environment' => array(
                'type'        => 'select',
                'title'       => __( 'Environment', 'tejcart' ),
                'description' => __( 'Shippo uses one endpoint; the token prefix selects mode. This field is informational and reminds you which token is active.', 'tejcart' ),
                'options'     => array( 'live' => 'Live', 'test' => 'Test' ),
                'default'     => 'test',
                'secret'      => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $token = trim( (string) ( $credentials['api_token'] ?? '' ) );
        if ( '' === $token ) {
            throw new Carrier_Exception( 'Shippo: missing api_token credential.' );
        }

        try {
            $response = $this->http->request( 'POST', self::ENDPOINT, array(
                'headers' => array(
                    'Authorization' => 'ShippoToken ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body' => wp_json_encode( $this->build_shipment_payload( $request ) ),
            ) );
        } catch ( Carrier_Exception $e ) {
            return array();
        }

        if ( $response['status'] >= 400 ) {
            return array();
        }

        return $this->parse_rates_response( $response['body'] );
    }

    /**
     * Build the Shippo /shipments POST body from a Rate_Request.
     *
     * @return array<string,mixed>
     */
    public function build_shipment_payload( Rate_Request $request ): array {
        // Shippo's /shipments endpoint accepts a `parcels` array natively
        // and quotes the multi-piece shipment as a whole. Sending only
        // packages[0] (the pre-fix behaviour) silently under-quoted every
        // cart that packed into more than one box.
        $parcels = array();
        foreach ( $request->packages as $package ) {
            $parcels[] = array(
                'length'        => (string) ( $package->length_mm / 10.0 ),
                'width'         => (string) ( $package->depth_mm / 10.0 ),
                'height'        => (string) ( $package->height_mm / 10.0 ),
                'distance_unit' => 'cm',
                'weight'        => (string) ( $package->weight_grams / 1000.0 ),
                'mass_unit'     => 'kg',
            );
        }

        return array(
            'address_from' => array(
                'country' => $request->origin['country'] ?? '',
                'state'   => $request->origin['state'] ?? '',
                'city'    => $request->origin['city'] ?? '',
                'zip'     => $request->origin['postcode'] ?? '',
                'street1' => $request->origin['line1'] ?? '',
            ),
            'address_to' => array(
                'country' => $request->destination['country'] ?? '',
                'state'   => $request->destination['state'] ?? '',
                'city'    => $request->destination['city'] ?? '',
                'zip'     => $request->destination['postcode'] ?? '',
                'street1' => $request->destination['line1'] ?? '',
            ),
            'parcels' => $parcels,
            'async'   => false,
        );
    }

    /**
     * Parse the Shippo shipment response into Rate_Quote objects.
     *
     * @return Rate_Quote[]
     */
    public function parse_rates_response( string $body ): array {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $rates = $decoded['rates'] ?? array();
        if ( ! is_array( $rates ) ) {
            return array();
        }

        $quotes = array();
        foreach ( $rates as $rate ) {
            if ( ! is_array( $rate ) ) {
                continue;
            }

            $service_token = (string) ( $rate['servicelevel']['token'] ?? '' );
            $service_name  = (string) ( $rate['servicelevel']['name'] ?? '' );
            $provider      = (string) ( $rate['provider'] ?? '' );
            $amount        = (string) ( $rate['amount'] ?? '' );

            if ( '' === $service_token || '' === $amount ) {
                continue;
            }

            // Shippo rate responses carry currency per-rate. Use the
            // currency-aware multiplier so JPY (×1) and KWD/BHD/OMR
            // (×1000) convert correctly across the ISO 4217 matrix.
            $rate_currency = strtoupper( (string) ( $rate['currency'] ?? 'USD' ) );
            $cents         = \TejCart\Money\Currency::to_minor_units( $amount, $rate_currency );

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $service_token,
                service_label: trim( $provider . ' ' . $service_name ),
                cost_cents:    $cents,
                currency:      $rate_currency,
                eta_days:      isset( $rate['estimated_days'] ) ? (int) $rate['estimated_days'] : null,
                rate_id:       isset( $rate['object_id'] ) ? (string) $rate['object_id'] : null,
                meta:          array( 'provider' => $provider ),
            );
        }

        return $quotes;
    }

    /**
     * Buy a label by creating a Shippo transaction against a rate object.
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $token = trim( (string) ( $credentials['api_token'] ?? '' ) );
        if ( '' === $token ) {
            throw new Carrier_Exception( 'Shippo: missing api_token credential.' );
        }
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'Shippo: rate_id is required.' );
        }

        $idem = (string) ( $credentials['__idempotency_key'] ?? '' );

        $response = $this->http->request( 'POST', self::TRANSACTIONS_API, array(
            'driver_id' => $this->id(),
            'headers'   => array_filter( array(
                'Authorization'   => 'ShippoToken ' . $token,
                'Content-Type'    => 'application/json',
                'Accept'          => 'application/json',
                'Idempotency-Key' => '' === $idem ? null : $idem,
            ) ),
            'body' => wp_json_encode( array(
                'rate'           => $rate_id,
                'label_file_type' => $credentials['__label_format'] ?? 'PDF',
                'async'          => false,
            ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Shippo: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_label_response( $response['body'] );
    }

    public function parse_label_response( string $body ): Label {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Shippo: malformed transaction response.' );
        }

        $status = strtoupper( (string) ( $decoded['status'] ?? '' ) );
        if ( 'SUCCESS' !== $status ) {
            $msgs = array();
            if ( isset( $decoded['messages'] ) && is_array( $decoded['messages'] ) ) {
                foreach ( $decoded['messages'] as $m ) {
                    if ( is_array( $m ) && isset( $m['text'] ) ) {
                        $msgs[] = (string) $m['text'];
                    }
                }
            }
            throw new Carrier_Exception( 'Shippo: transaction not successful — ' . esc_html( implode( '; ', $msgs ) ) );
        }

        $tracking  = (string) ( $decoded['tracking_number'] ?? '' );
        $label_url = (string) ( $decoded['label_url'] ?? '' );
        $cost_str  = (string) ( $decoded['rate']['amount'] ?? '0' );
        $currency  = strtoupper( (string) ( $decoded['rate']['currency'] ?? 'USD' ) );
        $format    = strtoupper( (string) ( $decoded['label_file_type'] ?? 'PDF' ) );

        if ( '' === $label_url || '' === $tracking ) {
            throw new Carrier_Exception( 'Shippo: response missing label_url or tracking_number.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $tracking,
            label_url: $label_url,
            label_format: $format,
            cost_cents: \TejCart\Money\Currency::to_minor_units( $cost_str, $currency ),
            currency: $currency,
            meta: array(
                'transaction_id' => (string) ( $decoded['object_id'] ?? '' ),
                'rate_id'        => (string) ( $decoded['rate']['object_id'] ?? $decoded['rate'] ?? '' ),
            )
        );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $token = trim( (string) ( $credentials['api_token'] ?? '' ) );
        if ( '' === $token ) {
            throw new Carrier_Exception( 'Shippo: missing api_token credential.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'Shippo: tracking_number required.' );
        }

        $carrier = (string) ( $credentials['__carrier'] ?? 'shippo' );

        $response = $this->http->request(
            'GET',
            self::TRACKING_API . rawurlencode( $carrier ) . '/' . rawurlencode( $tracking_number ),
            array(
                'driver_id' => $this->id(),
                'headers'   => array(
                    'Authorization' => 'ShippoToken ' . $token,
                    'Accept'        => 'application/json',
                ),
            )
        );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Shippo: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    /**
     * Validate via Shippo's /addresses/?validate endpoint.
     *
     * @param array<string,string> $address
     * @param array<string,string> $credentials
     */
    public function validate_address( array $address, array $credentials ): Address_Validation_Result {
        $token = trim( (string) ( $credentials['api_token'] ?? '' ) );
        if ( '' === $token ) {
            throw new Carrier_Exception( 'Shippo: missing api_token credential.' );
        }

        $payload = array(
            'street1'  => $address['line1'] ?? '',
            'city'     => $address['city'] ?? '',
            'state'    => $address['state'] ?? '',
            'zip'      => $address['postcode'] ?? '',
            'country'  => $address['country'] ?? '',
            'validate' => true,
        );

        $response = $this->http->request( 'POST', 'https://api.goshippo.com/addresses/', array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'ShippoToken ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( $payload ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Shippo: validate_address failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_address_response( $response['body'] );
    }

    public function parse_address_response( string $body ): Address_Validation_Result {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Shippo: malformed address response.' );
        }

        $valid    = $decoded['validation_results']['is_valid'] ?? false;
        $messages = array();
        if ( isset( $decoded['validation_results']['messages'] ) && is_array( $decoded['validation_results']['messages'] ) ) {
            foreach ( $decoded['validation_results']['messages'] as $m ) {
                if ( is_array( $m ) && isset( $m['text'] ) ) {
                    $messages[] = (string) $m['text'];
                }
            }
        }

        return new Address_Validation_Result(
            is_deliverable: (bool) $valid,
            is_residential: (bool) ( $decoded['is_residential'] ?? false ),
            corrected: array(
                'line1'    => (string) ( $decoded['street1'] ?? '' ),
                'city'     => (string) ( $decoded['city'] ?? '' ),
                'state'    => (string) ( $decoded['state'] ?? '' ),
                'postcode' => (string) ( $decoded['zip'] ?? '' ),
                'country'  => (string) ( $decoded['country'] ?? '' ),
            ),
            messages: $messages
        );
    }

    /**
     * Refund a Shippo transaction. Shippo voids run async; we POST to
     * /refunds/ with the transaction object id.
     *
     * @param array<string,string> $credentials
     */
    public function void_label( string $shipment_token, array $credentials ): void {
        $token = trim( (string) ( $credentials['api_token'] ?? '' ) );
        if ( '' === $token ) {
            throw new Carrier_Exception( 'Shippo: missing api_token credential.' );
        }
        if ( '' === $shipment_token ) {
            throw new Carrier_Exception( 'Shippo: shipment_token required.' );
        }

        $response = $this->http->request( 'POST', 'https://api.goshippo.com/refunds/', array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'ShippoToken ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( array( 'transaction' => $shipment_token, 'async' => false ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Shippo: void_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Shippo: malformed tracking response.' );
        }

        $status_raw = (string) ( $decoded['tracking_status']['status'] ?? '' );
        $status     = $this->map_tracking_status( $status_raw );

        $events = array();
        $hist   = $decoded['tracking_history'] ?? array();
        if ( is_array( $hist ) ) {
            foreach ( $hist as $h ) {
                if ( ! is_array( $h ) ) {
                    continue;
                }
                $location_parts = array_filter( array(
                    (string) ( $h['location']['city'] ?? '' ),
                    (string) ( $h['location']['state'] ?? '' ),
                    (string) ( $h['location']['country'] ?? '' ),
                ) );
                $events[] = array(
                    'timestamp'   => isset( $h['status_date'] ) ? (int) strtotime( (string) $h['status_date'] ) : 0,
                    'status'      => $this->map_tracking_status( (string) ( $h['status'] ?? '' ) ),
                    'description' => (string) ( $h['status_details'] ?? '' ),
                    'location'    => implode( ', ', $location_parts ),
                );
            }
        }

        $eta = isset( $decoded['eta'] ) && '' !== $decoded['eta']
            ? (int) strtotime( (string) $decoded['eta'] )
            : null;
        if ( 0 === $eta ) {
            $eta = null;
        }

        return new Tracking(
            carrier_id: $this->id(),
            tracking_number: $tracking_number,
            status: $status,
            events: $events,
            estimated_delivery: $eta
        );
    }

    private function map_tracking_status( string $status ): string {
        return match ( strtoupper( $status ) ) {
            'PRE_TRANSIT'      => Tracking::STATUS_PRE_TRANSIT,
            'TRANSIT'          => Tracking::STATUS_IN_TRANSIT,
            'DELIVERED'        => Tracking::STATUS_DELIVERED,
            'RETURNED'         => Tracking::STATUS_RETURNED,
            'FAILURE'          => Tracking::STATUS_EXCEPTION,
            default            => Tracking::STATUS_UNKNOWN,
        };
    }
}
