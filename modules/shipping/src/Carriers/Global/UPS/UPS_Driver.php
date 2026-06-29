<?php
/**
 * UPS direct driver.
 *
 * Uses the UPS Rating API v2403 (or compatible) with OAuth 2.0
 * client-credentials auth (Basic auth on the token endpoint).
 *
 * Service code reference (returned in RatedShipment.Service.Code):
 *   01 = Next Day Air, 02 = 2nd Day Air, 03 = Ground,
 *   12 = 3 Day Select, 13 = Next Day Air Saver, 14 = Next Day Air Early AM,
 *   59 = 2nd Day Air AM, 65 = UPS Saver.
 *
 * API reference: https://developer.ups.com/api/reference?loc=en_US
 *
 * @package TejCart\Shipping_Plugin\Carriers\Global\UPS
 */

namespace TejCart\Shipping_Plugin\Carriers\Global\UPS;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\HTTP_Client;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Manifest;
use TejCart\Shipping_Plugin\Core\OAuth_Token_Cache;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UPS_Driver extends Abstract_Carrier_Driver {
    public const TOKEN_URL_LIVE = 'https://onlinetools.ups.com/security/v1/oauth/token';
    public const TOKEN_URL_TEST = 'https://wwwcie.ups.com/security/v1/oauth/token';
    // "Shoptimeintransit" returns rates AND time-in-transit for every UPS
    // product (including Ground, which carries no GuaranteedDelivery block
    // under a plain "Shop" request) — so the checkout can show an
    // estimated delivery for all services. Requires DeliveryTimeInformation
    // in the request body (see build_rates_payload()).
    public const RATES_URL_LIVE = 'https://onlinetools.ups.com/api/rating/v2403/Shoptimeintransit';
    public const RATES_URL_TEST = 'https://wwwcie.ups.com/api/rating/v2403/Shoptimeintransit';
    public const SHIP_URL_LIVE  = 'https://onlinetools.ups.com/api/shipments/v2403/ship';
    public const SHIP_URL_TEST  = 'https://wwwcie.ups.com/api/shipments/v2403/ship';
    public const VOID_URL_LIVE  = 'https://onlinetools.ups.com/api/shipments/v2403/void/cancel/';
    public const VOID_URL_TEST  = 'https://wwwcie.ups.com/api/shipments/v2403/void/cancel/';
    public const TRACK_URL_LIVE  = 'https://onlinetools.ups.com/api/track/v1/details/';
    public const TRACK_URL_TEST  = 'https://wwwcie.ups.com/api/track/v1/details/';
    public const PICKUP_URL_LIVE = 'https://onlinetools.ups.com/api/shipments/v2403/pickup';
    public const PICKUP_URL_TEST = 'https://wwwcie.ups.com/api/shipments/v2403/pickup';

    private const SERVICE_LABELS = array(
        '01' => 'UPS Next Day Air',
        '02' => 'UPS 2nd Day Air',
        '03' => 'UPS Ground',
        '07' => 'UPS Worldwide Express',
        '08' => 'UPS Worldwide Expedited',
        '11' => 'UPS Standard',
        '12' => 'UPS 3 Day Select',
        '13' => 'UPS Next Day Air Saver',
        '14' => 'UPS Next Day Air Early AM',
        '54' => 'UPS Worldwide Express Plus',
        '59' => 'UPS 2nd Day Air AM',
        '65' => 'UPS Worldwide Saver',
    );

    private OAuth_Token_Cache $oauth;

    public function __construct( HTTP_Client $http, ?OAuth_Token_Cache $oauth = null ) {
        parent::__construct( $http );
        $this->oauth = $oauth ?? new OAuth_Token_Cache( $http );
    }

    public function id(): string {
        return 'ups';
    }

    public function label(): string {
        return 'UPS';
    }

    public function region(): string {
        return 'global';
    }

    public function credential_fields(): array {
        return array(
            'client_id'      => array( 'type' => 'text',     'title' => __( 'Client ID', 'tejcart' ),     'secret' => false ),
            'client_secret'  => array( 'type' => 'password', 'title' => __( 'Client secret', 'tejcart' ), 'secret' => true ),
            'shipper_number' => array(
                'type'        => 'text',
                'title'       => __( 'Shipper number (account)', 'tejcart' ),
                'description' => __( 'Six-character UPS account number used for negotiated rates.', 'tejcart' ),
                'secret'      => false,
            ),
            'environment' => array(
                'type'    => 'select',
                'title'   => __( 'Environment', 'tejcart' ),
                'options' => array( 'live' => 'Live', 'test' => 'Customer Integration Environment (test)' ),
                'default' => 'test',
                'secret'  => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret ) {
            throw new Carrier_Exception( 'UPS: missing client_id or client_secret credential.' );
        }

        $is_test   = 'test' === ( $credentials['environment'] ?? 'test' );
        $token_url = $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE;
        $rates_url = $is_test ? self::RATES_URL_TEST : self::RATES_URL_LIVE;

        try {
            $token = $this->oauth->token(
                'ups:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
                $token_url,
                $client_id,
                $client_secret
            );

            $response = $this->http->request( 'POST', $rates_url, array(
                'headers' => array(
                    'Authorization'   => 'Bearer ' . $token,
                    'Content-Type'    => 'application/json',
                    'Accept'          => 'application/json',
                    'transId'         => substr( (string) wp_generate_uuid4(), 0, 32 ),
                    'transactionSrc'  => 'tejcart',
                ),
                'body' => wp_json_encode( $this->build_rates_payload( $request, $credentials ) ),
            ) );
        } catch ( Carrier_Exception $e ) {
            return array();
        }

        if ( 401 === $response['status'] || 403 === $response['status'] ) {
            $this->oauth->forget( 'ups:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ) );
            return array();
        }

        if ( $response['status'] >= 400 ) {
            return array();
        }

        return $this->parse_rates_response( $response['body'] );
    }

    /**
     * @param array<string,string> $credentials
     * @return array<string,mixed>
     */
    public function build_rates_payload( Rate_Request $request, array $credentials ): array {
        $shipper_number = (string) ( $credentials['shipper_number'] ?? '' );

        // UPS Rating v1 accepts a `Package` array — quote every box in the
        // shipment, not just the first. Sending only packages[0] is the
        // canonical "all my multi-box carts came back too cheap" bug.
        $packages = array();
        foreach ( $request->packages as $package ) {
            $packages[] = array(
                'PackagingType' => array( 'Code' => '02' ),
                'Dimensions'    => array(
                    'UnitOfMeasurement' => array( 'Code' => 'IN' ),
                    'Length'            => (string) round( $package->length_mm / 25.4, 2 ),
                    'Width'             => (string) round( $package->depth_mm / 25.4, 2 ),
                    'Height'            => (string) round( $package->height_mm / 25.4, 2 ),
                ),
                'PackageWeight' => array(
                    'UnitOfMeasurement' => array( 'Code' => 'LBS' ),
                    'Weight'            => (string) round( $package->weight_grams / 453.592, 2 ),
                ),
            );
        }

        return array(
            'RateRequest' => array(
                'Request' => array(
                    'TransactionReference' => array( 'CustomerContext' => 'tejcart' ),
                ),
                'Shipment' => array(
                    'Shipper' => array(
                        'Name'          => 'TejCart',
                        'ShipperNumber' => $shipper_number,
                        'Address'       => $this->ups_address( $request->origin ),
                    ),
                    'ShipTo' => array(
                        'Name'    => 'Customer',
                        'Address' => $this->ups_address( $request->destination ),
                    ),
                    'ShipFrom' => array(
                        'Name'    => 'TejCart',
                        'Address' => $this->ups_address( $request->origin ),
                    ),
                    'PaymentDetails' => array(
                        'ShipmentCharge' => array(
                            'Type'        => '01',
                            'BillShipper' => array( 'AccountNumber' => $shipper_number ),
                        ),
                    ),
                    // Ask UPS to compute time-in-transit for every product.
                    // PackageBillType 03 = non-document; the pickup date
                    // anchors the transit calculation (today, store TZ).
                    'DeliveryTimeInformation' => array(
                        'PackageBillType' => '03',
                        'Pickup'          => array(
                            'Date' => function_exists( 'current_time' ) ? (string) current_time( 'Ymd' ) : gmdate( 'Ymd' ),
                        ),
                    ),
                    'Package' => $packages,
                ),
            ),
        );
    }

    /**
     * @param array<string,string> $address
     * @return array<string,mixed>
     */
    private function ups_address( array $address ): array {
        return array(
            'AddressLine'       => array( $address['line1'] ?? '' ),
            'City'              => $address['city'] ?? '',
            'StateProvinceCode' => $address['state'] ?? '',
            'PostalCode'        => $address['postcode'] ?? '',
            'CountryCode'       => $address['country'] ?? '',
        );
    }

    /**
     * Normalise a UPS `YYYYMMDD` date (its rating responses omit the
     * separators) into the `YYYY-MM-DD` form the checkout ETA formatter
     * parses. Returns '' for anything that isn't 8 digits.
     */
    private function ups_date_to_iso( string $date ): string {
        if ( 1 === preg_match( '/^(\d{4})(\d{2})(\d{2})$/', $date, $m ) ) {
            return $m[1] . '-' . $m[2] . '-' . $m[3];
        }
        return '';
    }

    /**
     * @return Rate_Quote[]
     */
    public function parse_rates_response( string $body ): array {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $rated = $decoded['RateResponse']['RatedShipment'] ?? array();
        if ( is_array( $rated ) && isset( $rated['Service'] ) ) {
            $rated = array( $rated );
        }
        if ( ! is_array( $rated ) ) {
            return array();
        }

        $quotes = array();
        foreach ( $rated as $shipment ) {
            if ( ! is_array( $shipment ) ) {
                continue;
            }
            $code     = (string) ( $shipment['Service']['Code'] ?? '' );
            $charges  = $shipment['TotalCharges'] ?? array();
            $value    = (string) ( $charges['MonetaryValue'] ?? '' );
            $currency = strtoupper( (string) ( $charges['CurrencyCode'] ?? 'USD' ) );

            if ( '' === $code || '' === $value ) {
                continue;
            }

            // Carrier API returns major units. Use the currency-aware
            // multiplier so JPY (×1) and KWD/BHD/OMR (×1000) are
            // converted correctly.
            $cents = \TejCart\Money\Currency::to_minor_units( $value, $currency );

            // Estimated delivery: prefer the Shoptimeintransit TimeInTransit
            // block (present for every service, incl. Ground), then fall
            // back to GuaranteedDelivery (guaranteed services only). Capture
            // the concrete arrival date too so checkout shows an exact date
            // rather than a derived one.
            $eta      = null;
            $eta_date = '';

            $arrival = $shipment['TimeInTransit']['ServiceSummary']['EstimatedArrival'] ?? null;
            if ( is_array( $arrival ) ) {
                if ( isset( $arrival['BusinessDaysInTransit'] ) && '' !== (string) $arrival['BusinessDaysInTransit'] ) {
                    $eta = (int) $arrival['BusinessDaysInTransit'];
                }
                $eta_date = $this->ups_date_to_iso( (string) ( $arrival['Arrival']['Date'] ?? '' ) );
            }

            $guaranteed = $shipment['GuaranteedDelivery'] ?? array();
            if ( is_array( $guaranteed ) ) {
                if ( null === $eta && isset( $guaranteed['BusinessDaysInTransit'] ) && '' !== (string) $guaranteed['BusinessDaysInTransit'] ) {
                    $eta = (int) $guaranteed['BusinessDaysInTransit'];
                }
                if ( '' === $eta_date && isset( $guaranteed['ScheduledDeliveryDate'] ) ) {
                    $eta_date = $this->ups_date_to_iso( (string) $guaranteed['ScheduledDeliveryDate'] );
                }
            }

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $code,
                service_label: self::SERVICE_LABELS[ $code ] ?? ( 'UPS service ' . $code ),
                cost_cents:    $cents,
                currency:      $currency,
                eta_days:      $eta,
                rate_id:       null,
                meta:          '' !== $eta_date ? array( 'etd' => $eta_date ) : array()
            );
        }

        return $quotes;
    }

    /**
     * Buy a UPS label via /api/shipments/v2403/ship.
     *
     * UPS doesn't issue ephemeral rate tokens — the rate_id is the
     * service code (e.g. "03" for Ground). The full ship payload is
     * built from credential extras supplied by Label_Service.
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        // The UPS account number IS the shipper number — that's the field
        // exposed in credential_fields() and used for negotiated rates.
        // Reading 'account_number' (never a declared field) meant every
        // label purchase failed the credential check even when configured.
        $account       = trim( (string) ( $credentials['shipper_number'] ?? $credentials['account_number'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret || '' === $account ) {
            throw new Carrier_Exception( 'UPS: missing client_id, client_secret, or shipper number credential.' );
        }
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'UPS: rate_id (service code) required.' );
        }

        $is_test  = 'test' === ( $credentials['environment'] ?? 'test' );
        $token    = $this->oauth->token(
            'ups:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE,
            $client_id,
            $client_secret,
            array(),
            'basic'
        );
        // UPS OAuth REST identifies a replay-safe transaction via the
        // `transId` header (per UPS Shipping API spec); `transactionSrc`
        // is the calling-system identifier for support diagnostics. A
        // duplicate submit with the same `transId` returns the original
        // ShipmentResults payload rather than minting a second label.
        // Covered by tests/Unit/Idempotency_Header_Propagation_Test.
        $idem     = (string) ( $credentials['__idempotency_key'] ?? '' );
        $ship_url = $is_test ? self::SHIP_URL_TEST : self::SHIP_URL_LIVE;

        $response = $this->http->request( 'POST', $ship_url, array(
            'driver_id' => $this->id(),
            'headers'   => array_filter( array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'transId'       => '' === $idem ? null : $idem,
                'transactionSrc'=> 'tejcart',
            ) ),
            'body' => wp_json_encode( $this->build_ship_payload( $rate_id, $account, $credentials ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'UPS: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_label_response( $response['body'] );
    }

    /**
     * @param array<string,string> $credentials
     * @return array<string,mixed>
     */
    public function build_ship_payload( string $service_code, string $account, array $credentials ): array {
        return array(
            'ShipmentRequest' => array(
                'Request' => array( 'RequestOption' => 'nonvalidate' ),
                'Shipment' => array(
                    'Description' => 'Goods',
                    'Shipper' => array(
                        'ShipperNumber' => $account,
                        'Address' => array(
                            'AddressLine'       => array( (string) ( $credentials['__from_line1'] ?? '' ) ),
                            'City'              => (string) ( $credentials['__from_city'] ?? '' ),
                            'StateProvinceCode' => (string) ( $credentials['__from_state'] ?? '' ),
                            'PostalCode'        => (string) ( $credentials['__from_zip'] ?? '' ),
                            'CountryCode'       => (string) ( $credentials['__from_country'] ?? 'US' ),
                        ),
                    ),
                    'ShipTo' => array(
                        'Address' => array(
                            'AddressLine'       => array( (string) ( $credentials['__to_line1'] ?? '' ) ),
                            'City'              => (string) ( $credentials['__to_city'] ?? '' ),
                            'StateProvinceCode' => (string) ( $credentials['__to_state'] ?? '' ),
                            'PostalCode'        => (string) ( $credentials['__to_zip'] ?? '' ),
                            'CountryCode'       => (string) ( $credentials['__to_country'] ?? 'US' ),
                        ),
                    ),
                    'ShipFrom' => array(
                        'Address' => array(
                            'AddressLine'       => array( (string) ( $credentials['__from_line1'] ?? '' ) ),
                            'City'              => (string) ( $credentials['__from_city'] ?? '' ),
                            'StateProvinceCode' => (string) ( $credentials['__from_state'] ?? '' ),
                            'PostalCode'        => (string) ( $credentials['__from_zip'] ?? '' ),
                            'CountryCode'       => (string) ( $credentials['__from_country'] ?? 'US' ),
                        ),
                    ),
                    'PaymentInformation' => array(
                        'ShipmentCharge' => array(
                            'Type'           => '01',
                            'BillShipper'    => array( 'AccountNumber' => $account ),
                        ),
                    ),
                    'Service' => array( 'Code' => $service_code ),
                    'Package' => array(
                        'PackagingType' => array( 'Code' => '02' ),
                        'PackageWeight' => array(
                            'UnitOfMeasurement' => array( 'Code' => 'LBS' ),
                            'Weight'            => (string) ( $credentials['__weight_lb'] ?? '1' ),
                        ),
                    ),
                ),
                'LabelSpecification' => array(
                    'LabelImageFormat' => array( 'Code' => 'PDF' ),
                ),
            ),
        );
    }

    public function parse_label_response( string $body ): Label {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'UPS: malformed ship response.' );
        }

        $shipment = $decoded['ShipmentResponse']['ShipmentResults'] ?? array();
        if ( ! is_array( $shipment ) ) {
            throw new Carrier_Exception( 'UPS: ship response missing ShipmentResults.' );
        }

        $package = $shipment['PackageResults'] ?? array();
        if ( isset( $package[0] ) && is_array( $package[0] ) ) {
            $package = $package[0];
        }

        $tracking   = (string) ( $package['TrackingNumber'] ?? '' );
        $label_b64  = (string) ( $package['ShippingLabel']['GraphicImage'] ?? '' );
        $cost_str   = (string) ( $shipment['ShipmentCharges']['TotalCharges']['MonetaryValue'] ?? '0' );
        $currency   = strtoupper( (string) ( $shipment['ShipmentCharges']['TotalCharges']['CurrencyCode'] ?? 'USD' ) );
        $shipment_id = (string) ( $shipment['ShipmentIdentificationNumber'] ?? '' );

        if ( '' === $tracking || '' === $label_b64 ) {
            throw new Carrier_Exception( 'UPS: ship response missing tracking or label.' );
        }

        // UPS returns the label as a base64 string. Persist as a
        // data-URL so the calling code can render it without an extra
        // round-trip.
        $label_url = 'data:application/pdf;base64,' . $label_b64;

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $tracking,
            label_url: $label_url,
            label_format: 'PDF',
            cost_cents: \TejCart\Money\Currency::to_minor_units( $cost_str, $currency ),
            currency: $currency,
            meta: array( 'shipment_id' => $shipment_id )
        );
    }

    public function void_label( string $shipment_token, array $credentials ): void {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret ) {
            throw new Carrier_Exception( 'UPS: missing credentials.' );
        }
        if ( '' === $shipment_token ) {
            throw new Carrier_Exception( 'UPS: shipment_token required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $token   = $this->oauth->token(
            'ups:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE,
            $client_id,
            $client_secret,
            array(),
            'basic'
        );

        $url = ( $is_test ? self::VOID_URL_TEST : self::VOID_URL_LIVE ) . rawurlencode( $shipment_token );

        $response = $this->http->request( 'DELETE', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'UPS: void_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret ) {
            throw new Carrier_Exception( 'UPS: missing credentials.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'UPS: tracking_number required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $token   = $this->oauth->token(
            'ups:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE,
            $client_id,
            $client_secret,
            array(),
            'basic'
        );

        $url = ( $is_test ? self::TRACK_URL_TEST : self::TRACK_URL_LIVE ) . rawurlencode( $tracking_number );

        $response = $this->http->request( 'GET', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'transId'       => bin2hex( random_bytes( 8 ) ),
                'transactionSrc'=> 'tejcart',
                'Accept'        => 'application/json',
            ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'UPS: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'UPS: malformed tracking response.' );
        }

        $shipment = $decoded['trackResponse']['shipment'] ?? array();
        if ( isset( $shipment[0] ) && is_array( $shipment[0] ) ) {
            $shipment = $shipment[0];
        }

        $packages = $shipment['package'] ?? array();
        if ( isset( $packages[0] ) && is_array( $packages[0] ) ) {
            $packages = $packages[0];
        }

        $current_status = (string) ( $packages['currentStatus']['description'] ?? '' );
        $status         = $this->map_tracking_status( $current_status );

        $events = array();
        $hist   = $packages['activity'] ?? array();
        if ( is_array( $hist ) ) {
            foreach ( $hist as $event ) {
                if ( ! is_array( $event ) ) {
                    continue;
                }
                $location_parts = array_filter( array(
                    (string) ( $event['location']['address']['city'] ?? '' ),
                    (string) ( $event['location']['address']['stateProvince'] ?? '' ),
                    (string) ( $event['location']['address']['country'] ?? '' ),
                ) );
                $events[] = array(
                    'timestamp'   => isset( $event['date'], $event['time'] )
                        ? (int) strtotime( (string) $event['date'] . ' ' . (string) $event['time'] )
                        : 0,
                    'status'      => $this->map_tracking_status( (string) ( $event['status']['description'] ?? '' ) ),
                    'description' => (string) ( $event['status']['description'] ?? '' ),
                    'location'    => implode( ', ', $location_parts ),
                );
            }
        }

        return new Tracking(
            carrier_id: $this->id(),
            tracking_number: $tracking_number,
            status: $status,
            events: $events,
            estimated_delivery: null
        );
    }

    /**
     * Schedule a UPS pickup for a batch of previously purchased labels.
     *
     * @param string[]             $shipment_tokens UPS tracking numbers in the pickup.
     * @param array<string,string> $credentials
     */
    public function manifest( array $shipment_tokens, array $credentials ): Manifest {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        $account       = trim( (string) ( $credentials['shipper_number'] ?? $credentials['account_number'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret || '' === $account ) {
            throw new Carrier_Exception( 'UPS: missing credentials.' );
        }
        if ( array() === $shipment_tokens ) {
            throw new Carrier_Exception( 'UPS: at least one shipment token required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $token   = $this->oauth->token(
            'ups:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE,
            $client_id,
            $client_secret,
            array(),
            'basic'
        );

        $url = $is_test ? self::PICKUP_URL_TEST : self::PICKUP_URL_LIVE;

        $response = $this->http->request( 'POST', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'transId'       => bin2hex( random_bytes( 8 ) ),
                'transactionSrc'=> 'tejcart',
            ),
            'body' => wp_json_encode( array(
                'PickupCreationRequest' => array(
                    'PickupDateInfo' => array(
                        'CloseTime'        => '1700',
                        'ReadyTime'        => '0900',
                        'PickupDate'       => gmdate( 'Ymd' ),
                    ),
                    'PickupAddress' => array(
                        'CompanyName'      => (string) ( $credentials['__from_name'] ?? 'Shipper' ),
                        'AddressLine'      => (string) ( $credentials['__from_line1'] ?? '' ),
                        'City'             => (string) ( $credentials['__from_city'] ?? '' ),
                        'StateProvince'    => (string) ( $credentials['__from_state'] ?? '' ),
                        'Urbanization'     => '',
                        'PostalCode'       => (string) ( $credentials['__from_zip'] ?? '' ),
                        'CountryCode'      => (string) ( $credentials['__from_country'] ?? 'US' ),
                        'ResidentialIndicator' => 'N',
                    ),
                    'AlternateAddressIndicator' => 'N',
                    'PickupPiece' => array(
                        array(
                            'ServiceCode'         => '003',
                            'Quantity'            => (string) count( $shipment_tokens ),
                            'DestinationCountryCode' => 'US',
                        ),
                    ),
                    'TotalWeight' => array(
                        'Weight'             => '5',
                        'UnitOfMeasurement'  => 'LBS',
                    ),
                    'PaymentMethod' => '00',
                    'ShippingLabelsAvailable' => 'Y',
                ),
            ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'UPS: pickup failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        $decoded = json_decode( $response['body'], true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'UPS: malformed pickup response.' );
        }

        $confirmation = (string) ( $decoded['PickupCreationResponse']['PRN'] ?? '' );
        if ( '' === $confirmation ) {
            throw new Carrier_Exception( 'UPS: pickup response missing PRN.' );
        }

        return new Manifest(
            carrier_id: $this->id(),
            manifest_id: $confirmation,
            manifest_url: '',
            manifest_format: 'CONFIRMATION',
            tracking_numbers: array_map( 'strval', $shipment_tokens ),
            meta: array( 'prn' => $confirmation )
        );
    }

    private function map_tracking_status( string $raw ): string {
        $u = strtoupper( $raw );
        return match ( true ) {
            str_contains( $u, 'DELIVERED' )                                       => Tracking::STATUS_DELIVERED,
            str_contains( $u, 'OUT FOR DELIVERY' )                                => Tracking::STATUS_OUT_FOR_DELIVERY,
            str_contains( $u, 'IN TRANSIT' ),
            str_contains( $u, 'DEPARTED' ),
            str_contains( $u, 'ARRIVED' ),
            str_contains( $u, 'ORIGIN SCAN' ),
            str_contains( $u, 'DESTINATION' )                                     => Tracking::STATUS_IN_TRANSIT,
            str_contains( $u, 'LABEL CREATED' ),
            str_contains( $u, 'PICKUP' ),
            str_contains( $u, 'ORDER PROCESSED' )                                 => Tracking::STATUS_PRE_TRANSIT,
            str_contains( $u, 'RETURN' )                                          => Tracking::STATUS_RETURNED,
            str_contains( $u, 'EXCEPTION' ), str_contains( $u, 'FAILURE' )        => Tracking::STATUS_EXCEPTION,
            default                                                               => Tracking::STATUS_UNKNOWN,
        };
    }
}
