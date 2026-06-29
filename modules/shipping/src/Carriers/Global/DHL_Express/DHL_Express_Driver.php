<?php
/**
 * DHL Express direct driver (MyDHL API).
 *
 * Authentication is HTTP Basic with the API key/secret issued by DHL
 * to your MyDHL account. International rate request returns one
 * product per service line.
 *
 * API reference: https://developer.dhl.com/api-reference/dhl-express-mydhl-api
 *
 * @package TejCart\Shipping_Plugin\Carriers\Global\DHL_Express
 */

namespace TejCart\Shipping_Plugin\Carriers\Global\DHL_Express;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class DHL_Express_Driver extends Abstract_Carrier_Driver {
    public const RATES_URL_LIVE     = 'https://express.api.dhl.com/mydhlapi/rates';
    public const RATES_URL_TEST     = 'https://express.api.dhl.com/mydhlapi/test/rates';
    public const SHIPMENTS_URL_LIVE = 'https://express.api.dhl.com/mydhlapi/shipments';
    public const SHIPMENTS_URL_TEST = 'https://express.api.dhl.com/mydhlapi/test/shipments';
    public const TRACK_URL_LIVE     = 'https://express.api.dhl.com/mydhlapi/shipments/';
    public const TRACK_URL_TEST     = 'https://express.api.dhl.com/mydhlapi/test/shipments/';

    private const SERVICE_LABELS = array(
        'P' => 'DHL Express Worldwide',
        'U' => 'DHL Express Worldwide',
        'D' => 'DHL Express Worldwide Doc',
        'N' => 'DHL Express Domestic',
        'T' => 'DHL Express 12:00',
        'Y' => 'DHL Express 09:00',
        'K' => 'DHL Express 9:00',
        'L' => 'DHL Express 12:00',
        'G' => 'DHL Domestic Economy Select',
        'W' => 'DHL Economy Select',
    );

    public function id(): string {
        return 'dhl_express';
    }

    public function label(): string {
        return 'DHL Express';
    }

    public function region(): string {
        return 'global';
    }

    public function credential_fields(): array {
        return array(
            'api_key' => array(
                'type'   => 'text',
                'title'  => __( 'API key (username)', 'tejcart' ),
                'secret' => false,
            ),
            'api_secret' => array(
                'type'   => 'password',
                'title'  => __( 'API secret (password)', 'tejcart' ),
                'secret' => true,
            ),
            'account_number' => array(
                'type'        => 'text',
                'title'       => __( 'Account number', 'tejcart' ),
                'description' => __( 'Nine-digit DHL Express account number.', 'tejcart' ),
                'secret'      => false,
            ),
            'environment' => array(
                'type'    => 'select',
                'title'   => __( 'Environment', 'tejcart' ),
                'options' => array( 'live' => 'Live', 'test' => 'Test' ),
                'default' => 'test',
                'secret'  => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $api_key    = trim( (string) ( $credentials['api_key'] ?? '' ) );
        $api_secret = trim( (string) ( $credentials['api_secret'] ?? '' ) );
        if ( '' === $api_key || '' === $api_secret ) {
            throw new Carrier_Exception( 'DHL Express: missing api_key or api_secret credential.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = $is_test ? self::RATES_URL_TEST : self::RATES_URL_LIVE;

        try {
            $response = $this->http->request( 'POST', $url, array(
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body' => wp_json_encode( $this->build_rates_payload( $request, $credentials ) ),
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
     * @param array<string,string> $credentials
     * @return array<string,mixed>
     */
    public function build_rates_payload( Rate_Request $request, array $credentials ): array {
        $account_number = (string) ( $credentials['account_number'] ?? '' );

        // DHL Express MyDHL API quotes a multi-piece shipment by passing
        // every box in `packages` and the aggregate value in
        // `monetaryAmount.declaredValue`. Quoting only packages[0] under-
        // declares both weight and customs value on every multi-box cart.
        $packages         = array();
        $declared_total_c = 0;
        foreach ( $request->packages as $package ) {
            $packages[]        = array(
                'weight'     => round( $package->weight_grams / 1000.0, 2 ),
                'dimensions' => array(
                    'length' => round( $package->length_mm / 10.0, 1 ),
                    'width'  => round( $package->depth_mm / 10.0, 1 ),
                    'height' => round( $package->height_mm / 10.0, 1 ),
                ),
            );
            $declared_total_c += (int) $package->declared_value_cents;
        }

        return array(
            'customerDetails' => array(
                'shipperDetails'  => array( 'postalAddress' => $this->dhl_address( $request->origin ) ),
                'receiverDetails' => array( 'postalAddress' => $this->dhl_address( $request->destination ) ),
            ),
            'accounts' => array(
                array( 'typeCode' => 'shipper', 'number' => $account_number ),
            ),
            'plannedShippingDateAndTime' => gmdate( 'Y-m-d\TH:i:s\G\M\T+00:00' ),
            'unitOfMeasurement'          => 'metric',
            'isCustomsDeclarable'        => $this->is_international( $request ),
            'monetaryAmount'             => array(
                array(
                    'typeCode' => 'declaredValue',
                    // DHL Express is fully ISO 4217 — JPY (×1) and
                    // KWD/BHD/OMR (×1000) shipments need the right
                    // subunit divisor or the declared value is wrong
                    // on the wire (and on customs paperwork).
                    'value'    => \TejCart\Money\Currency::from_minor_units( (int) $declared_total_c, (string) $request->currency ),
                    'currency' => $request->currency,
                ),
            ),
            'packages' => $packages,
        );
    }

    /**
     * @param array<string,string> $address
     * @return array<string,string>
     */
    private function dhl_address( array $address ): array {
        return array(
            'postalCode'              => $address['postcode'] ?? '',
            'cityName'                => $address['city'] ?? '',
            'countryCode'             => $address['country'] ?? '',
            'addressLine1'            => $address['line1'] ?? '',
            'provinceCode'            => $address['state'] ?? '',
        );
    }

    private function is_international( Rate_Request $request ): bool {
        $origin = $request->origin['country'] ?? '';
        $dest   = $request->destination['country'] ?? '';
        return '' !== $origin && '' !== $dest && $origin !== $dest;
    }

    /**
     * @return Rate_Quote[]
     */
    public function parse_rates_response( string $body, string $fallback_currency = 'USD' ): array {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $products = $decoded['products'] ?? array();
        if ( ! is_array( $products ) ) {
            return array();
        }

        $quotes = array();
        foreach ( $products as $product ) {
            if ( ! is_array( $product ) ) {
                continue;
            }
            $code = (string) ( $product['productCode'] ?? '' );
            if ( '' === $code ) {
                continue;
            }

            $price = $this->extract_total_price( $product, $fallback_currency );
            if ( null === $price ) {
                continue;
            }

            $eta = null;
            if ( isset( $product['deliveryCapabilities']['totalTransitDays'] ) ) {
                $eta = (int) $product['deliveryCapabilities']['totalTransitDays'];
            }

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $code,
                service_label: self::SERVICE_LABELS[ $code ] ?? ( 'DHL Express ' . $code ),
                cost_cents:    $price['cents'],
                currency:      $price['currency'],
                eta_days:      $eta,
                rate_id:       null,
                meta:          array()
            );
        }

        return $quotes;
    }

    /**
     * @return array{cents:int,currency:string}|null
     */
    private function extract_total_price( array $product, string $fallback_currency ): ?array {
        $total_prices = $product['totalPrice'] ?? array();
        if ( ! is_array( $total_prices ) || array() === $total_prices ) {
            return null;
        }

        $preferred = null;
        foreach ( $total_prices as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }
            if ( 'BILLC' === ( $entry['currencyType'] ?? '' ) ) {
                $preferred = $entry;
                break;
            }
        }
        if ( null === $preferred ) {
            // `totalPrice` is normally a 0-indexed list, but guard against an
            // object-shaped (associative) payload so `$total_prices[0]` can't
            // be an undefined index — that would surface as a PHP warning and
            // silently drop an otherwise-valid quote.
            $preferred = $total_prices[0] ?? reset( $total_prices );
        }
        if ( ! is_array( $preferred ) ) {
            return null;
        }

        $price    = $preferred['price'] ?? null;
        $currency = strtoupper( (string) ( $preferred['priceCurrency'] ?? $fallback_currency ) );
        if ( null === $price ) {
            return null;
        }

        // DHL Express quotes in many currencies including JPY (×1) and
        // KWD (×1000). Use the currency-aware multiplier so the stored
        // cents value matches ISO 4217 subunit semantics.
        return array(
            'cents'    => \TejCart\Money\Currency::to_minor_units( $price, $currency ),
            'currency' => $currency,
        );
    }

    /**
     * Buy a DHL Express label via /shipments.
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $api_key    = trim( (string) ( $credentials['api_key'] ?? '' ) );
        $api_secret = trim( (string) ( $credentials['api_secret'] ?? '' ) );
        $account    = trim( (string) ( $credentials['account_number'] ?? '' ) );
        if ( '' === $api_key || '' === $api_secret || '' === $account ) {
            throw new Carrier_Exception( 'DHL Express: missing api_key, api_secret, or account_number credential.' );
        }
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'DHL Express: rate_id (productCode) required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = $is_test ? self::SHIPMENTS_URL_TEST : self::SHIPMENTS_URL_LIVE;
        $idem    = (string) ( $credentials['__idempotency_key'] ?? '' );

        $response = $this->http->request( 'POST', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array_filter( array(
                'Authorization'  => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                'Content-Type'   => 'application/json',
                'Accept'         => 'application/json',
                'Message-Reference' => '' === $idem ? null : $idem,
            ) ),
            'body' => wp_json_encode( $this->build_ship_payload( $rate_id, $account, $credentials ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'DHL Express: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_label_response( $response['body'] );
    }

    /**
     * @param array<string,string> $credentials
     * @return array<string,mixed>
     */
    public function build_ship_payload( string $product_code, string $account, array $credentials ): array {
        return array(
            'plannedShippingDateAndTime' => gmdate( 'Y-m-d\TH:i:sP' ),
            'pickup'    => array( 'isRequested' => false ),
            'productCode' => $product_code,
            'accounts'  => array( array( 'typeCode' => 'shipper', 'number' => $account ) ),
            'customerDetails' => array(
                'shipperDetails' => array(
                    'postalAddress' => array(
                        'cityName'           => (string) ( $credentials['__from_city'] ?? '' ),
                        'countryCode'        => (string) ( $credentials['__from_country'] ?? '' ),
                        'postalCode'         => (string) ( $credentials['__from_zip'] ?? '' ),
                        'addressLine1'       => (string) ( $credentials['__from_line1'] ?? '' ),
                    ),
                    'contactInformation' => array(
                        'phone'        => '0000000000',
                        'companyName'  => 'Shipper',
                        'fullName'     => 'Shipper',
                    ),
                ),
                'receiverDetails' => array(
                    'postalAddress' => array(
                        'cityName'           => (string) ( $credentials['__to_city'] ?? '' ),
                        'countryCode'        => (string) ( $credentials['__to_country'] ?? '' ),
                        'postalCode'         => (string) ( $credentials['__to_zip'] ?? '' ),
                        'addressLine1'       => (string) ( $credentials['__to_line1'] ?? '' ),
                    ),
                    'contactInformation' => array(
                        'phone'        => '0000000000',
                        'companyName'  => 'Recipient',
                        'fullName'     => 'Recipient',
                    ),
                ),
            ),
            'content' => array(
                'packages' => array(
                    array(
                        'weight'      => (float) ( $credentials['__weight_kg'] ?? 1 ),
                        'dimensions'  => array(
                            'length' => (float) ( $credentials['__length_cm'] ?? 10 ),
                            'width'  => (float) ( $credentials['__width_cm'] ?? 10 ),
                            'height' => (float) ( $credentials['__height_cm'] ?? 10 ),
                        ),
                    ),
                ),
                'isCustomsDeclarable' => false,
                'description'         => 'Goods',
                'incoterm'            => 'DAP',
                'unitOfMeasurement'   => 'metric',
            ),
        );
    }

    public function parse_label_response( string $body ): Label {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'DHL Express: malformed ship response.' );
        }

        $tracking = (string) ( $decoded['shipmentTrackingNumber'] ?? '' );
        $docs     = $decoded['documents'] ?? array();
        $first    = is_array( $docs ) && isset( $docs[0] ) && is_array( $docs[0] ) ? $docs[0] : array();
        $b64      = (string) ( $first['content'] ?? '' );
        $format   = strtoupper( (string) ( $first['imageFormat'] ?? 'PDF' ) );
        $label_url = '' === $b64 ? '' : 'data:application/' . strtolower( $format ) . ';base64,' . $b64;

        if ( '' === $tracking || '' === $label_url ) {
            throw new Carrier_Exception( 'DHL Express: ship response missing tracking or label.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $tracking,
            label_url: $label_url,
            label_format: $format,
            cost_cents: 0,
            currency: 'USD'
        );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $api_key    = trim( (string) ( $credentials['api_key'] ?? '' ) );
        $api_secret = trim( (string) ( $credentials['api_secret'] ?? '' ) );
        if ( '' === $api_key || '' === $api_secret ) {
            throw new Carrier_Exception( 'DHL Express: missing credentials.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'DHL Express: tracking_number required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $url     = ( $is_test ? self::TRACK_URL_TEST : self::TRACK_URL_LIVE )
            . rawurlencode( $tracking_number ) . '/tracking';

        $response = $this->http->request( 'GET', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
                'Accept'        => 'application/json',
            ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'DHL Express: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'DHL Express: malformed tracking response.' );
        }

        $shipment = $decoded['shipments'][0] ?? array();
        $events   = array();
        $hist     = $shipment['events'] ?? array();
        $latest   = '';
        if ( is_array( $hist ) ) {
            foreach ( $hist as $event ) {
                if ( ! is_array( $event ) ) {
                    continue;
                }
                $when = (int) strtotime( ( (string) ( $event['date'] ?? '' ) ) . ' ' . ( (string) ( $event['time'] ?? '' ) ) );
                $events[] = array(
                    'timestamp'   => $when,
                    'status'      => $this->map_status( (string) ( $event['typeCode'] ?? '' ) ),
                    'description' => (string) ( $event['description'] ?? '' ),
                    'location'    => (string) ( $event['serviceArea'][0]['description'] ?? '' ),
                );
                if ( '' === $latest ) {
                    $latest = (string) ( $event['typeCode'] ?? '' );
                }
            }
        }

        return new Tracking(
            carrier_id: $this->id(),
            tracking_number: $tracking_number,
            status: $this->map_status( $latest ),
            events: $events
        );
    }

    private function map_status( string $code ): string {
        $u = strtoupper( $code );
        return match ( true ) {
            'OK' === $u, str_contains( $u, 'DELIVERED' )                                 => Tracking::STATUS_DELIVERED,
            'WC' === $u, str_contains( $u, 'OUT FOR DELIVERY' )                          => Tracking::STATUS_OUT_FOR_DELIVERY,
            'PU' === $u, 'AR' === $u, 'DF' === $u, 'PL' === $u,
            str_contains( $u, 'TRANSIT' ),
            str_contains( $u, 'DEPARTED' )                                               => Tracking::STATUS_IN_TRANSIT,
            'PD' === $u, str_contains( $u, 'PROCESSED' )                                 => Tracking::STATUS_PRE_TRANSIT,
            'RT' === $u, str_contains( $u, 'RETURN' )                                    => Tracking::STATUS_RETURNED,
            'SD' === $u, 'SE' === $u, str_contains( $u, 'EXCEPTION' )                    => Tracking::STATUS_EXCEPTION,
            default                                                                      => Tracking::STATUS_UNKNOWN,
        };
    }
}
