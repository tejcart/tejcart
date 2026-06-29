<?php
/**
 * Australia Post direct driver.
 *
 * Uses the Postage Assessment Calculator (PAC) API. Authentication is
 * a single AUTH-KEY header issued by Australia Post on developer
 * registration. Domestic and international shipments hit different
 * endpoints with different query-parameter shapes.
 *
 * API reference: https://developers.auspost.com.au/apis/pac
 *
 * @package TejCart\Shipping_Plugin\Carriers\APAC\Australia_Post
 */

namespace TejCart\Shipping_Plugin\Carriers\APAC\Australia_Post;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Australia_Post_Driver extends Abstract_Carrier_Driver {
    public const DOMESTIC_URL      = 'https://digitalapi.auspost.com.au/postage/parcel/domestic/service.json';
    public const INTERNATIONAL_URL = 'https://digitalapi.auspost.com.au/postage/parcel/international/service.json';

    public function id(): string {
        return 'australia_post';
    }

    public function label(): string {
        return 'Australia Post';
    }

    public function region(): string {
        return 'apac';
    }

    public function credential_fields(): array {
        return array(
            'auth_key' => array(
                'type'        => 'password',
                'title'       => __( 'AUTH-KEY', 'tejcart' ),
                'description' => __( 'API key issued by Australia Post developer portal.', 'tejcart' ),
                'secret'      => true,
            ),
            'account_number' => array(
                'type'        => 'text',
                'title'       => __( 'Account number', 'tejcart' ),
                'description' => __( 'Australia Post eParcel / StarTrack charge account. Required to buy labels and pull tracking (rate quoting only needs the AUTH-KEY).', 'tejcart' ),
                'secret'      => false,
            ),
            'environment' => array(
                'type'        => 'select',
                'title'       => __( 'Environment', 'tejcart' ),
                'description' => __( 'Australia Post PAC uses one endpoint for both modes; the AUTH-KEY itself selects live vs. test. This field is informational.', 'tejcart' ),
                'options'     => array( 'live' => 'Live', 'test' => 'Test' ),
                'default'     => 'test',
                'secret'      => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $auth_key = trim( (string) ( $credentials['auth_key'] ?? '' ) );
        if ( '' === $auth_key ) {
            throw new Carrier_Exception( 'Australia Post: missing auth_key credential.' );
        }

        $is_domestic = 'AU' === strtoupper( $request->destination['country'] ?? '' );
        $url         = $this->build_request_url( $request, $is_domestic );

        try {
            $response = $this->http->request( 'GET', $url, array(
                'headers' => array(
                    'AUTH-KEY' => $auth_key,
                    'Accept'   => 'application/json',
                ),
            ) );
        } catch ( Carrier_Exception $e ) {
            return array();
        }

        if ( $response['status'] >= 400 ) {
            return array();
        }

        return $this->parse_rates_response( $response['body'] );
    }

    public function build_request_url( Rate_Request $request, bool $is_domestic ): string {
        $package = $request->packages[0];

        $weight_kg = round( $package->weight_grams / 1000.0, 3 );

        if ( $is_domestic ) {
            $params = array(
                'from_postcode' => $request->origin['postcode'] ?? '',
                'to_postcode'   => $request->destination['postcode'] ?? '',
                'length'        => round( $package->length_mm / 10.0, 1 ),
                'width'         => round( $package->depth_mm / 10.0, 1 ),
                'height'        => round( $package->height_mm / 10.0, 1 ),
                'weight'        => $weight_kg,
            );
            return self::DOMESTIC_URL . '?' . http_build_query( $params );
        }

        $params = array(
            'country_code' => strtoupper( $request->destination['country'] ?? '' ),
            'weight'       => $weight_kg,
        );
        return self::INTERNATIONAL_URL . '?' . http_build_query( $params );
    }

    /**
     * @return Rate_Quote[]
     */
    public function parse_rates_response( string $body ): array {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $services = $decoded['services']['service'] ?? array();
        if ( ! is_array( $services ) ) {
            return array();
        }
        if ( isset( $services['code'] ) ) {
            $services = array( $services );
        }

        $quotes = array();
        foreach ( $services as $service ) {
            if ( ! is_array( $service ) ) {
                continue;
            }
            $code  = (string) ( $service['code'] ?? '' );
            $name  = (string) ( $service['name'] ?? '' );
            $price = (string) ( $service['price'] ?? '' );
            if ( '' === $code || '' === $price ) {
                continue;
            }

            // AUD is a 2-decimal currency so ×100 happens to be right
            // today, but route through Currency::to_minor_units() for
            // consistency with the rest of the codebase and to stay
            // safe if Australia Post ever quotes in a non-2-decimal
            // currency for an international destination.
            $cents = \TejCart\Money\Currency::to_minor_units( $price, 'AUD' );

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $code,
                service_label: '' !== $name ? $name : ( 'Australia Post ' . $code ),
                cost_cents:    $cents,
                currency:      'AUD',
                // No ETA: the PAC rate response carries no machine-readable
                // delivery estimate. Structured delivery dates require the
                // separate Delivery Choices (DCE) "DeliveryDates" API, a
                // different product gated behind an eParcel/credit account.
                eta_days:      null,
                rate_id:       null,
                meta:          array()
            );
        }

        return $quotes;
    }

    /**
     * Australia Post Shipping & Tracking API endpoints — note these
     * require the merchant's StarTrack/eParcel contract; the public PAC
     * key used for rates is NOT sufficient.
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $api_key = trim( (string) ( $credentials['auth_key'] ?? '' ) );
        $account = trim( (string) ( $credentials['account_number'] ?? '' ) );
        if ( '' === $api_key || '' === $account ) {
            throw new Carrier_Exception( 'Australia Post: missing auth_key or account_number credential.' );
        }
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'Australia Post: rate_id (product_id) required.' );
        }

        $idem    = (string) ( $credentials['__idempotency_key'] ?? '' );

        $response = $this->http->request( 'POST', 'https://digitalapi.auspost.com.au/shipping/v1/shipments', array(
            'driver_id' => $this->id(),
            'headers'   => array_filter( array(
                'AUTH-KEY'      => $api_key,
                'Account-Number' => $account,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'Idempotency-Key' => '' === $idem ? null : $idem,
            ) ),
            'body' => wp_json_encode( array(
                'shipments' => array(
                    array(
                        'shipment_reference' => 'tejcart-' . substr( $idem, 0, 16 ),
                        'sender_references'  => array( 'tejcart' ),
                        'from' => array(
                            'name'         => (string) ( $credentials['__from_name'] ?? 'Shipper' ),
                            'lines'        => array( (string) ( $credentials['__from_line1'] ?? '' ) ),
                            'suburb'       => (string) ( $credentials['__from_city'] ?? '' ),
                            'state'        => (string) ( $credentials['__from_state'] ?? '' ),
                            'postcode'     => (string) ( $credentials['__from_zip'] ?? '' ),
                            'country'      => 'AU',
                            'phone'        => '0000000000',
                        ),
                        'to' => array(
                            'name'     => (string) ( $credentials['__to_name'] ?? 'Recipient' ),
                            'lines'    => array( (string) ( $credentials['__to_line1'] ?? '' ) ),
                            'suburb'   => (string) ( $credentials['__to_city'] ?? '' ),
                            'state'    => (string) ( $credentials['__to_state'] ?? '' ),
                            'postcode' => (string) ( $credentials['__to_zip'] ?? '' ),
                            'country'  => (string) ( $credentials['__to_country'] ?? 'AU' ),
                            'phone'    => '0000000000',
                        ),
                        'items' => array(
                            array(
                                'product_id' => $rate_id,
                                'length'     => (float) ( $credentials['__length_cm'] ?? 10 ),
                                'width'      => (float) ( $credentials['__width_cm'] ?? 10 ),
                                'height'     => (float) ( $credentials['__height_cm'] ?? 10 ),
                                'weight'     => (float) ( $credentials['__weight_kg'] ?? 1 ),
                            ),
                        ),
                    ),
                ),
            ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Australia Post: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_label_response( $response['body'] );
    }

    public function parse_label_response( string $body ): Label {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Australia Post: malformed ship response.' );
        }
        $shipment = $decoded['shipments'][0] ?? array();
        if ( ! is_array( $shipment ) ) {
            throw new Carrier_Exception( 'Australia Post: ship response missing shipments.' );
        }

        $tracking  = (string) ( $shipment['items'][0]['tracking_details']['article_id'] ?? $shipment['shipment_id'] ?? '' );
        $label_url = (string) ( $shipment['shipment_summary']['labels_url'] ?? $shipment['label_url'] ?? '' );
        $cost_raw  = $shipment['shipment_summary']['total_cost_ex_gst'] ?? null;
        if ( null === $cost_raw || ( is_numeric( $cost_raw ) && (float) $cost_raw <= 0 ) ) {
            throw new Carrier_Exception( 'Australia Post: ship response missing or zero cost — the label may not have been charged correctly.' );
        }
        $cost = (string) $cost_raw;

        if ( '' === $tracking ) {
            throw new Carrier_Exception( 'Australia Post: ship response missing tracking number.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $tracking,
            label_url: $label_url,
            label_format: 'PDF',
            cost_cents: \TejCart\Money\Currency::to_minor_units( $cost, 'AUD' ),
            currency: 'AUD',
            meta: array( 'shipment_id' => (string) ( $shipment['shipment_id'] ?? '' ) )
        );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $api_key = trim( (string) ( $credentials['auth_key'] ?? '' ) );
        $account = trim( (string) ( $credentials['account_number'] ?? '' ) );
        if ( '' === $api_key || '' === $account ) {
            throw new Carrier_Exception( 'Australia Post: missing credentials.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'Australia Post: tracking_number required.' );
        }

        $url = 'https://digitalapi.auspost.com.au/shipping/v1/track?tracking_ids=' . rawurlencode( $tracking_number );
        $response = $this->http->request( 'GET', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'AUTH-KEY'       => $api_key,
                'Account-Number' => $account,
                'Accept'         => 'application/json',
            ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Australia Post: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Australia Post: malformed tracking response.' );
        }
        $result = $decoded['tracking_results'][0] ?? array();
        $status = $this->map_status( (string) ( $result['status'] ?? '' ) );

        $events  = array();
        $tevents = $result['trackable_items'][0]['events'] ?? array();
        if ( is_array( $tevents ) ) {
            foreach ( $tevents as $event ) {
                if ( ! is_array( $event ) ) {
                    continue;
                }
                $events[] = array(
                    'timestamp'   => isset( $event['date'] ) ? (int) strtotime( (string) $event['date'] ) : 0,
                    'status'      => $this->map_status( (string) ( $event['description'] ?? '' ) ),
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
            str_contains( $u, 'DELIVERED' )    => Tracking::STATUS_DELIVERED,
            str_contains( $u, 'WITH COURIER' ),
            str_contains( $u, 'OUT FOR DELIVERY' ) => Tracking::STATUS_OUT_FOR_DELIVERY,
            str_contains( $u, 'TRANSIT' ),
            str_contains( $u, 'PROCESSED' )    => Tracking::STATUS_IN_TRANSIT,
            str_contains( $u, 'LODGE' ),
            str_contains( $u, 'AWAITING' )     => Tracking::STATUS_PRE_TRANSIT,
            str_contains( $u, 'RETURN' )       => Tracking::STATUS_RETURNED,
            str_contains( $u, 'EXCEPTION' ),
            str_contains( $u, 'UNDELIVERED' )  => Tracking::STATUS_EXCEPTION,
            default                            => Tracking::STATUS_UNKNOWN,
        };
    }
}
