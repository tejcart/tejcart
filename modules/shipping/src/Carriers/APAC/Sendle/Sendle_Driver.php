<?php
/**
 * Sendle driver (Australia + United States).
 *
 * Auth: HTTP Basic with the Sendle ID as username and the API key as
 * password. The `/quote` endpoint accepts sender/receiver suburb +
 * postcode + country plus weight/dimensions, and returns an array of
 * plans each with a price and ETA window.
 *
 * API reference: https://developers.sendle.com/reference
 *
 * @package TejCart\Shipping_Plugin\Carriers\APAC\Sendle
 */

namespace TejCart\Shipping_Plugin\Carriers\APAC\Sendle;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Sendle_Driver extends Abstract_Carrier_Driver {
    public const QUOTE_URL_LIVE = 'https://api.sendle.com/api/quote';
    public const QUOTE_URL_TEST = 'https://sandbox.sendle.com/api/quote';
    public const ORDER_URL_LIVE = 'https://api.sendle.com/api/orders';
    public const ORDER_URL_TEST = 'https://sandbox.sendle.com/api/orders';
    public const TRACK_URL_LIVE = 'https://api.sendle.com/api/tracking/';
    public const TRACK_URL_TEST = 'https://sandbox.sendle.com/api/tracking/';

    public function id(): string {
        return 'sendle';
    }

    public function label(): string {
        return 'Sendle';
    }

    public function region(): string {
        return 'apac';
    }

    public function credential_fields(): array {
        return array(
            'sendle_id' => array(
                'type'        => 'text',
                'title'       => __( 'Sendle ID', 'tejcart' ),
                'description' => __( 'Your Sendle account ID (used as the HTTP Basic username).', 'tejcart' ),
                'secret'      => false,
            ),
            'api_key' => array(
                'type'   => 'password',
                'title'  => __( 'API key', 'tejcart' ),
                'secret' => true,
            ),
            'environment' => array(
                'type'    => 'select',
                'title'   => __( 'Environment', 'tejcart' ),
                'options' => array( 'live' => 'Live', 'test' => 'Sandbox' ),
                'default' => 'test',
                'secret'  => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $sendle_id = trim( (string) ( $credentials['sendle_id'] ?? '' ) );
        $api_key   = trim( (string) ( $credentials['api_key'] ?? '' ) );
        if ( '' === $sendle_id || '' === $api_key ) {
            throw new Carrier_Exception( 'Sendle: missing sendle_id or api_key credential.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = ( $is_test ? self::QUOTE_URL_TEST : self::QUOTE_URL_LIVE )
            . '?' . http_build_query( $this->build_query_params( $request ) );

        try {
            $response = $this->http->request( 'GET', $url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $sendle_id . ':' . $api_key ),
                    'Accept'        => 'application/json',
                ),
            ) );
        } catch ( Carrier_Exception $e ) {
            return array();
        }

        if ( $response['status'] >= 400 ) {
            return array();
        }

        return $this->parse_rates_response( $response['body'], $request->currency );
    }

    /**
     * @return array<string,string|int|float>
     */
    public function build_query_params( Rate_Request $request ): array {
        $package = $request->packages[0];

        return array(
            'pickup_suburb'           => (string) ( $request->origin['city'] ?? '' ),
            'pickup_postcode'         => (string) ( $request->origin['postcode'] ?? '' ),
            'pickup_country'          => (string) ( $request->origin['country'] ?? '' ),
            'delivery_suburb'         => (string) ( $request->destination['city'] ?? '' ),
            'delivery_postcode'       => (string) ( $request->destination['postcode'] ?? '' ),
            'delivery_country'        => (string) ( $request->destination['country'] ?? '' ),
            'weight_value'            => round( $package->weight_grams / 1000.0, 3 ),
            'weight_units'            => 'kg',
            'dimension_units'         => 'cm',
            'dimension_length'        => round( $package->length_mm / 10.0, 1 ),
            'dimension_width'         => round( $package->depth_mm / 10.0, 1 ),
            'dimension_height'        => round( $package->height_mm / 10.0, 1 ),
        );
    }

    /**
     * @return Rate_Quote[]
     */
    public function parse_rates_response( string $body, string $fallback_currency = 'AUD' ): array {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $quotes = array();
        foreach ( $decoded as $plan ) {
            if ( ! is_array( $plan ) ) {
                continue;
            }

            $plan_name = (string) ( $plan['plan_name'] ?? '' );
            $price     = $plan['quote']['gross']['amount'] ?? null;
            $currency  = strtoupper( (string) ( $plan['quote']['gross']['currency'] ?? $fallback_currency ) );

            if ( '' === $plan_name || null === $price ) {
                continue;
            }

            // Currency comes from the Sendle response per plan. Use the
            // currency-aware multiplier so JPY (×1) and KWD/BHD/OMR
            // (×1000) convert correctly across the ISO 4217 matrix.
            $cents = \TejCart\Money\Currency::to_minor_units( $price, $currency );

            $eta = null;
            if ( isset( $plan['eta']['days_range'][1] ) ) {
                $eta = (int) $plan['eta']['days_range'][1];
            } elseif ( isset( $plan['eta']['business_days'] ) ) {
                $eta = (int) $plan['eta']['business_days'];
            }

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $plan_name,
                service_label: 'Sendle ' . $plan_name,
                cost_cents:    $cents,
                currency:      $currency,
                eta_days:      $eta,
                rate_id:       null,
                meta:          array()
            );
        }

        return $quotes;
    }

    /**
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $sid = trim( (string) ( $credentials['sendle_id'] ?? '' ) );
        $key = trim( (string) ( $credentials['api_key'] ?? '' ) );
        if ( '' === $sid || '' === $key ) {
            throw new Carrier_Exception( 'Sendle: missing sendle_id or api_key credential.' );
        }
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'Sendle: rate_id (plan_name) required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = $is_test ? self::ORDER_URL_TEST : self::ORDER_URL_LIVE;
        $idem    = (string) ( $credentials['__idempotency_key'] ?? '' );

        $response = $this->http->request( 'POST', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array_filter( array(
                'Authorization'  => 'Basic ' . base64_encode( $sid . ':' . $key ),
                'Content-Type'   => 'application/json',
                'Accept'         => 'application/json',
                'Idempotency-Key' => '' === $idem ? null : $idem,
            ) ),
            'body' => wp_json_encode( array(
                'pickup_date'   => gmdate( 'Y-m-d', time() + DAY_IN_SECONDS ),
                'description'   => 'Goods',
                'weight'        => array( 'value' => (string) ( $credentials['__weight_kg'] ?? '1' ), 'units' => 'kg' ),
                'sender' => array(
                    'contact' => array( 'name' => (string) ( $credentials['__from_name'] ?? 'Shipper' ) ),
                    'address' => array(
                        'address_line1' => (string) ( $credentials['__from_line1'] ?? '' ),
                        'suburb'        => (string) ( $credentials['__from_city'] ?? '' ),
                        'state_name'    => (string) ( $credentials['__from_state'] ?? '' ),
                        'postcode'      => (string) ( $credentials['__from_zip'] ?? '' ),
                        'country'       => (string) ( $credentials['__from_country'] ?? 'AU' ),
                    ),
                ),
                'receiver' => array(
                    'contact' => array(
                        'name'  => (string) ( $credentials['__to_name'] ?? 'Recipient' ),
                        'email' => (string) ( $credentials['__to_email'] ?? '' ),
                    ),
                    'address' => array(
                        'address_line1' => (string) ( $credentials['__to_line1'] ?? '' ),
                        'suburb'        => (string) ( $credentials['__to_city'] ?? '' ),
                        'state_name'    => (string) ( $credentials['__to_state'] ?? '' ),
                        'postcode'      => (string) ( $credentials['__to_zip'] ?? '' ),
                        'country'       => (string) ( $credentials['__to_country'] ?? 'AU' ),
                    ),
                ),
                'plan_name' => $rate_id,
            ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Sendle: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_label_response( $response['body'] );
    }

    public function parse_label_response( string $body ): Label {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Sendle: malformed order response.' );
        }

        $tracking  = (string) ( $decoded['sendle_reference'] ?? $decoded['order_id'] ?? '' );
        $label_url = (string) ( $decoded['labels'][0]['url'] ?? $decoded['label_url'] ?? '' );
        $cost_str  = (string) ( $decoded['price']['gross']['amount'] ?? '0' );
        $currency  = strtoupper( (string) ( $decoded['price']['gross']['currency'] ?? 'AUD' ) );

        if ( '' === $tracking ) {
            throw new Carrier_Exception( 'Sendle: order response missing reference.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $tracking,
            label_url: $label_url,
            label_format: 'PDF',
            cost_cents: \TejCart\Money\Currency::to_minor_units( $cost_str, $currency ),
            currency: $currency,
            meta: array( 'order_id' => (string) ( $decoded['order_id'] ?? '' ) )
        );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $sid = trim( (string) ( $credentials['sendle_id'] ?? '' ) );
        $key = trim( (string) ( $credentials['api_key'] ?? '' ) );
        if ( '' === $sid || '' === $key ) {
            throw new Carrier_Exception( 'Sendle: missing credentials.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'Sendle: tracking_number required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = ( $is_test ? self::TRACK_URL_TEST : self::TRACK_URL_LIVE ) . rawurlencode( $tracking_number );

        $response = $this->http->request( 'GET', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode( $sid . ':' . $key ),
                'Accept'        => 'application/json',
            ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Sendle: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Sendle: malformed tracking response.' );
        }

        $status = $this->map_status( (string) ( $decoded['state'] ?? '' ) );
        $events = array();
        if ( isset( $decoded['tracking_events'] ) && is_array( $decoded['tracking_events'] ) ) {
            foreach ( $decoded['tracking_events'] as $event ) {
                if ( ! is_array( $event ) ) {
                    continue;
                }
                $events[] = array(
                    'timestamp'   => isset( $event['scan_time'] ) ? (int) strtotime( (string) $event['scan_time'] ) : 0,
                    'status'      => $this->map_status( (string) ( $event['event_type'] ?? '' ) ),
                    'description' => (string) ( $event['description'] ?? '' ),
                    'location'    => (string) ( $event['location'] ?? '' ),
                );
            }
        }

        return new Tracking( $this->id(), $tracking_number, $status, $events );
    }

    private function map_status( string $raw ): string {
        $u = strtoupper( $raw );
        return match ( true ) {
            'DELIVERED' === $u                                                          => Tracking::STATUS_DELIVERED,
            'ATTEMPTED_DELIVERY' === $u, str_contains( $u, 'OUT FOR DELIVERY' )         => Tracking::STATUS_OUT_FOR_DELIVERY,
            'PICKED_UP' === $u, 'IN_TRANSIT' === $u, str_contains( $u, 'TRANSIT' )      => Tracking::STATUS_IN_TRANSIT,
            'BOOKED' === $u, 'PAYMENT_PENDING' === $u                                   => Tracking::STATUS_PRE_TRANSIT,
            'RETURN_TO_SENDER' === $u, str_contains( $u, 'RETURN' )                     => Tracking::STATUS_RETURNED,
            'CANCELLED' === $u, 'LOST' === $u, 'EXCEPTION' === $u                       => Tracking::STATUS_EXCEPTION,
            default                                                                     => Tracking::STATUS_UNKNOWN,
        };
    }
}
