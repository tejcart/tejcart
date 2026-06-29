<?php
/**
 * Delhivery driver (India direct).
 *
 * Auth: `Authorization: Token <api_token>` header. The `kinko/v1/invoice/charges`
 * endpoint returns the freight quote for a single mode (Surface "S" or
 * Express "E") per call, so the driver issues both calls in sequence
 * and merges the results into one quote per mode.
 *
 * API reference: https://track.delhivery.com/api-docs
 *
 * @package TejCart\Shipping_Plugin\Carriers\APAC\Delhivery
 */

namespace TejCart\Shipping_Plugin\Carriers\APAC\Delhivery;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Delhivery_Driver extends Abstract_Carrier_Driver {
    public const RATES_URL_LIVE = 'https://track.delhivery.com/api/kinko/v1/invoice/charges/.json';
    public const RATES_URL_TEST = 'https://staging-express.delhivery.com/api/kinko/v1/invoice/charges/.json';
    public const SHIP_URL_LIVE  = 'https://track.delhivery.com/api/cmu/create.json';
    public const SHIP_URL_TEST  = 'https://staging-express.delhivery.com/api/cmu/create.json';
    public const TRACK_URL_LIVE = 'https://track.delhivery.com/api/v1/packages/json/?waybill=';
    public const TRACK_URL_TEST = 'https://staging-express.delhivery.com/api/v1/packages/json/?waybill=';

    private const MODE_LABELS = array(
        'S' => 'Delhivery Surface',
        'E' => 'Delhivery Express',
    );

    public function id(): string {
        return 'delhivery';
    }

    public function label(): string {
        return 'Delhivery';
    }

    public function region(): string {
        return 'apac';
    }

    public function credential_fields(): array {
        return array(
            'api_token' => array(
                'type'   => 'password',
                'title'  => __( 'API token', 'tejcart' ),
                'secret' => true,
            ),
            'client_name' => array(
                'type'        => 'text',
                'title'       => __( 'Client (warehouse) name', 'tejcart' ),
                'description' => __( 'Registered Delhivery warehouse name (used for the "name" query parameter).', 'tejcart' ),
                'secret'      => false,
            ),
            'environment' => array(
                'type'    => 'select',
                'title'   => __( 'Environment', 'tejcart' ),
                'options' => array( 'live' => 'Live', 'test' => 'Staging' ),
                'default' => 'test',
                'secret'  => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $api_token = trim( (string) ( $credentials['api_token'] ?? '' ) );
        if ( '' === $api_token ) {
            throw new Carrier_Exception( 'Delhivery: missing api_token credential.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $base    = $is_test ? self::RATES_URL_TEST : self::RATES_URL_LIVE;

        $quotes = array();

        foreach ( array( 'S', 'E' ) as $mode ) {
            try {
                $url = $base . '?' . http_build_query( $this->build_query_params( $request, $credentials, $mode ) );
                $response = $this->http->request( 'GET', $url, array(
                    'headers' => array(
                        'Authorization' => 'Token ' . $api_token,
                        'Accept'        => 'application/json',
                    ),
                ) );
            } catch ( Carrier_Exception $e ) {
                continue;
            }

            if ( $response['status'] >= 400 ) {
                continue;
            }

            $quote = $this->parse_mode_response( $response['body'], $mode );
            if ( null !== $quote ) {
                $quotes[] = $quote;
            }
        }

        return $quotes;
    }

    /**
     * @param array<string,string> $credentials
     * @return array<string,string|int>
     */
    public function build_query_params( Rate_Request $request, array $credentials, string $mode ): array {
        $package = $request->packages[0];

        return array(
            'md'    => $mode,
            'cgm'   => $package->weight_grams,
            'o_pin' => (string) ( $request->origin['postcode'] ?? '' ),
            'd_pin' => (string) ( $request->destination['postcode'] ?? '' ),
            'ss'    => 'Delivered',
            'pt'    => ! empty( $request->meta['cod'] ) ? 'COD' : 'Pre-paid',
            'name'  => (string) ( $credentials['client_name'] ?? '' ),
        );
    }

    public function parse_mode_response( string $body, string $mode ): ?Rate_Quote {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) || array() === $decoded ) {
            return null;
        }

        $first = is_array( $decoded[0] ?? null ) ? $decoded[0] : $decoded;
        $total = $first['total_amount'] ?? null;
        if ( null === $total ) {
            return null;
        }

        // INR is 2-decimal today; route through Currency::to_minor_units()
        // for consistency with the rest of the codebase and banker's
        // rounding semantics.
        $cents = \TejCart\Money\Currency::to_minor_units( $total, 'INR' );

        return new Rate_Quote(
            carrier_id:    $this->id(),
            service_code:  $mode,
            service_label: self::MODE_LABELS[ $mode ] ?? ( 'Delhivery ' . $mode ),
            cost_cents:    $cents,
            currency:      'INR',
            // No ETA: the invoice/charges API returns cost only, and
            // Delhivery's public API surface has no transit-time/TAT
            // endpoint. (The "Expected Date of Delivery" API exists but is
            // gated behind a logged-in Delhivery One merchant account.)
            eta_days:      null,
            rate_id:       null,
            meta:          array( 'zone' => (string) ( $first['zonal_cl'] ?? '' ) )
        );
    }

    /**
     * Delhivery's CMU (Create Manifest Upload) endpoint accepts JSON
     * inside a form-encoded body (`format=json&data=...`). Returns the
     * waybill (tracking number) and a label URL.
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $token = trim( (string) ( $credentials['api_token'] ?? '' ) );
        if ( '' === $token ) {
            throw new Carrier_Exception( 'Delhivery: missing api_token credential.' );
        }
        $mode = '' === $rate_id ? 'S' : strtoupper( $rate_id );
        if ( ! in_array( $mode, array( 'S', 'E' ), true ) ) {
            throw new Carrier_Exception( 'Delhivery: rate_id must be "S" (surface) or "E" (express).' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = $is_test ? self::SHIP_URL_TEST : self::SHIP_URL_LIVE;

        $shipment = array(
            'name'              => (string) ( $credentials['__to_name'] ?? 'Recipient' ),
            'add'               => (string) ( $credentials['__to_line1'] ?? '' ),
            'pin'               => (string) ( $credentials['__to_zip'] ?? '' ),
            'city'              => (string) ( $credentials['__to_city'] ?? '' ),
            'state'             => (string) ( $credentials['__to_state'] ?? '' ),
            'country'           => (string) ( $credentials['__to_country'] ?? 'India' ),
            'phone'             => (string) ( $credentials['__to_phone'] ?? '0000000000' ),
            'order'             => (string) ( $credentials['__order_ref'] ?? ( 'TEJ' . time() ) ),
            'payment_mode'      => 'Prepaid',
            'return_pin'        => (string) ( $credentials['__from_zip'] ?? '' ),
            'return_city'       => (string) ( $credentials['__from_city'] ?? '' ),
            'return_phone'      => (string) ( $credentials['__from_phone'] ?? '0000000000' ),
            'return_add'        => (string) ( $credentials['__from_line1'] ?? '' ),
            'return_state'      => (string) ( $credentials['__from_state'] ?? '' ),
            'return_country'    => 'India',
            'products_desc'     => 'Goods',
            'hsn_code'          => '',
            'cod_amount'        => '0',
            'order_date'        => null,
            'total_amount'      => (string) ( $credentials['__order_total'] ?? '0' ),
            'seller_add'        => (string) ( $credentials['__from_line1'] ?? '' ),
            'seller_name'       => (string) ( $credentials['__from_name'] ?? 'Shipper' ),
            'seller_inv'        => '',
            'quantity'          => '1',
            'waybill'           => '',
            'shipment_width'    => (string) ( $credentials['__width_cm'] ?? '10' ),
            'shipment_height'   => (string) ( $credentials['__height_cm'] ?? '10' ),
            'weight'            => (string) ( ( (float) ( $credentials['__weight_kg'] ?? 1 ) ) * 1000 ),
            'commodity_value'   => (string) ( $credentials['__order_total'] ?? '0' ),
            'shipping_mode'     => 'S' === $mode ? 'Surface' : 'Express',
            'address_type'      => 'home',
        );

        $payload = wp_json_encode( array(
            'shipments' => array( $shipment ),
            'pickup_location' => array(
                'name'    => (string) ( $credentials['__pickup_location'] ?? 'Primary' ),
                'add'     => (string) ( $credentials['__from_line1'] ?? '' ),
                'city'    => (string) ( $credentials['__from_city'] ?? '' ),
                'pin_code' => (string) ( $credentials['__from_zip'] ?? '' ),
                'country' => 'India',
                'phone'   => (string) ( $credentials['__from_phone'] ?? '0000000000' ),
            ),
        ) );

        $response = $this->http->request( 'POST', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Token ' . $token,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/x-www-form-urlencoded',
            ),
            'body' => 'format=json&data=' . rawurlencode( (string) $payload ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Delhivery: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_label_response( $response['body'] );
    }

    public function parse_label_response( string $body ): Label {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Delhivery: malformed CMU response.' );
        }
        if ( ! ( $decoded['success'] ?? false ) ) {
            $remarks = '';
            $first   = $decoded['packages'][0] ?? array();
            if ( is_array( $first ) && isset( $first['remarks'] ) && is_array( $first['remarks'] ) ) {
                $remarks = implode( '; ', array_map( 'strval', $first['remarks'] ) );
            }
            throw new Carrier_Exception( 'Delhivery: CMU rejected — ' . esc_html( $remarks ) );
        }
        $package = $decoded['packages'][0] ?? array();
        if ( ! is_array( $package ) ) {
            throw new Carrier_Exception( 'Delhivery: CMU response missing packages.' );
        }
        $waybill = (string) ( $package['waybill'] ?? '' );
        if ( '' === $waybill ) {
            throw new Carrier_Exception( 'Delhivery: CMU response missing waybill.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $waybill,
            // Delhivery returns the label as a separate /packing_slip/ call;
            // we expose the canonical URL pattern so callers can render it.
            label_url: 'https://track.delhivery.com/api/p/packing_slip?wbns=' . rawurlencode( $waybill ),
            label_format: 'PDF',
            cost_cents: 0,
            currency: 'INR'
        );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $token = trim( (string) ( $credentials['api_token'] ?? '' ) );
        if ( '' === $token ) {
            throw new Carrier_Exception( 'Delhivery: missing api_token credential.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'Delhivery: tracking_number required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = ( $is_test ? self::TRACK_URL_TEST : self::TRACK_URL_LIVE ) . rawurlencode( $tracking_number );

        $response = $this->http->request( 'GET', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Token ' . $token,
                'Accept'        => 'application/json',
            ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Delhivery: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Delhivery: malformed tracking response.' );
        }
        $shipment = $decoded['ShipmentData'][0]['Shipment'] ?? array();
        if ( ! is_array( $shipment ) ) {
            return new Tracking( $this->id(), $tracking_number, Tracking::STATUS_UNKNOWN );
        }
        $current = $shipment['Status'] ?? array();
        $status  = $this->map_status( (string) ( $current['Status'] ?? '' ) );

        $events = array();
        $hist   = $shipment['Scans'] ?? array();
        if ( is_array( $hist ) ) {
            foreach ( $hist as $entry ) {
                $detail = $entry['ScanDetail'] ?? array();
                if ( ! is_array( $detail ) ) {
                    continue;
                }
                $events[] = array(
                    'timestamp'   => isset( $detail['ScanDateTime'] ) ? (int) strtotime( (string) $detail['ScanDateTime'] ) : 0,
                    'status'      => $this->map_status( (string) ( $detail['Scan'] ?? '' ) ),
                    'description' => (string) ( $detail['Instructions'] ?? '' ),
                    'location'    => (string) ( $detail['ScannedLocation'] ?? '' ),
                );
            }
        }

        return new Tracking( $this->id(), $tracking_number, $status, $events );
    }

    private function map_status( string $raw ): string {
        $u = strtoupper( $raw );
        return match ( true ) {
            'DELIVERED' === $u, str_contains( $u, 'DELIVERED' )                       => Tracking::STATUS_DELIVERED,
            str_contains( $u, 'OUT FOR DELIVERY' )                                    => Tracking::STATUS_OUT_FOR_DELIVERY,
            'IN TRANSIT' === $u, 'IN-TRANSIT' === $u, str_contains( $u, 'TRANSIT' )   => Tracking::STATUS_IN_TRANSIT,
            'MANIFESTED' === $u, str_contains( $u, 'PICKUP' )                         => Tracking::STATUS_PRE_TRANSIT,
            'RTO' === $u, str_contains( $u, 'RTO' ), str_contains( $u, 'RETURN' )     => Tracking::STATUS_RETURNED,
            'DTO' === $u, 'LOST' === $u, str_contains( $u, 'EXCEPTION' ),
            str_contains( $u, 'UNDELIVERED' )                                         => Tracking::STATUS_EXCEPTION,
            default                                                                   => Tracking::STATUS_UNKNOWN,
        };
    }
}
