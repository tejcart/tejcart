<?php
/**
 * USPS direct driver.
 *
 * Uses the USPS API Platform (api.usps.com) with OAuth 2.0
 * client-credentials authentication. The Prices Domestic API returns
 * total rates per mail class. Customers without a USPS API platform
 * account should use the EasyPost or Shippo aggregator drivers instead.
 *
 * API reference: https://developer.usps.com/
 *
 * @package TejCart\Shipping_Plugin\Carriers\NorthAmerica\USPS
 */

namespace TejCart\Shipping_Plugin\Carriers\NorthAmerica\USPS;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\HTTP_Client;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Manifest;
use TejCart\Shipping_Plugin\Core\OAuth_Token_Cache;
use TejCart\Shipping_Plugin\Core\Package;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class USPS_Driver extends Abstract_Carrier_Driver {
    public const TOKEN_URL_LIVE = 'https://apis.usps.com/oauth2/v3/token';
    public const TOKEN_URL_TEST = 'https://apis-tem.usps.com/oauth2/v3/token';
    public const RATES_URL_LIVE = 'https://apis.usps.com/prices/v3/total-rates/search';
    public const RATES_URL_TEST = 'https://apis-tem.usps.com/prices/v3/total-rates/search';
    public const LABELS_URL_LIVE = 'https://apis.usps.com/labels/v3/label';
    public const LABELS_URL_TEST = 'https://apis-tem.usps.com/labels/v3/label';
    public const TRACK_URL_LIVE  = 'https://apis.usps.com/tracking/v3/tracking/';
    public const TRACK_URL_TEST  = 'https://apis-tem.usps.com/tracking/v3/tracking/';
    public const SCAN_URL_LIVE   = 'https://apis.usps.com/manifest/v3/manifest';
    public const SCAN_URL_TEST   = 'https://apis-tem.usps.com/manifest/v3/manifest';

    private OAuth_Token_Cache $oauth;

    public function __construct( HTTP_Client $http, ?OAuth_Token_Cache $oauth = null ) {
        parent::__construct( $http );
        $this->oauth = $oauth ?? new OAuth_Token_Cache( $http );
    }

    public function id(): string {
        return 'usps';
    }

    public function label(): string {
        return 'USPS';
    }

    public function region(): string {
        return 'north_america';
    }

    public function credential_fields(): array {
        return array(
            'client_id' => array(
                'type'   => 'text',
                'title'  => __( 'Consumer key', 'tejcart' ),
                'secret' => false,
            ),
            'client_secret' => array(
                'type'   => 'password',
                'title'  => __( 'Consumer secret', 'tejcart' ),
                'secret' => true,
            ),
            'crid' => array(
                'type'        => 'text',
                'title'       => __( 'CRID', 'tejcart' ),
                'description' => __( 'Customer Registration ID associated with your USPS account.', 'tejcart' ),
                'secret'      => false,
            ),
            'mid' => array(
                'type'        => 'text',
                'title'       => __( 'MID', 'tejcart' ),
                'description' => __( 'Mailer ID associated with your USPS account.', 'tejcart' ),
                'secret'      => false,
            ),
            'environment' => array(
                'type'        => 'select',
                'title'       => __( 'Environment', 'tejcart' ),
                'description' => __( 'Sandbox uses the USPS Test Environment for Mailers (apis-tem.usps.com).', 'tejcart' ),
                'options'     => array( 'live' => 'Live', 'test' => 'Sandbox (TEM)' ),
                'default'     => 'test',
                'secret'      => false,
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );

        if ( '' === $client_id || '' === $client_secret ) {
            throw new Carrier_Exception( 'USPS: missing client_id or client_secret credential.' );
        }

        $is_test   = 'test' === ( $credentials['environment'] ?? 'test' );
        $token_url = $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE;
        $rates_url = $is_test ? self::RATES_URL_TEST : self::RATES_URL_LIVE;

        try {
            $token = $this->oauth->token(
                'usps:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
                $token_url,
                $client_id,
                $client_secret,
                array( 'scope' => 'prices' ),
                'body'
            );
        } catch ( Carrier_Exception $e ) {
            return array();
        }

        // USPS Prices v3 only quotes one parcel at a time. Carts that pack
        // into multiple boxes used to be silently under-quoted (one box
        // priced, N - 1 ignored). Fan out one request per package, then
        // sum cents per mail class so the customer pays for every box.
        /** @var array<string, Rate_Quote> $by_service */
        $by_service = array();
        foreach ( $request->packages as $package ) {
            try {
                $response = $this->http->request( 'POST', $rates_url, array(
                    'headers' => array(
                        'Authorization' => 'Bearer ' . $token,
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/json',
                    ),
                    'body' => wp_json_encode( $this->build_rates_payload_for_package( $request, $package, $credentials ) ),
                ) );
            } catch ( Carrier_Exception $e ) {
                return array();
            }

            if ( 401 === $response['status'] || 403 === $response['status'] ) {
                $this->oauth->forget( 'usps:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ) );
                return array();
            }

            if ( $response['status'] >= 400 ) {
                return array();
            }

            $quotes = $this->parse_rates_response( $response['body'] );
            if ( array() === $by_service ) {
                foreach ( $quotes as $quote ) {
                    $by_service[ $quote->service_code ] = $quote;
                }
                continue;
            }

            // Only keep services that every package in the cart can ship by
            // (a class returned for package #1 but not package #2 can't be
            // sold as a single shipping option to the customer).
            $next = array();
            foreach ( $quotes as $quote ) {
                if ( ! isset( $by_service[ $quote->service_code ] ) ) {
                    continue;
                }
                $running       = $by_service[ $quote->service_code ];
                $next[ $quote->service_code ] = new Rate_Quote(
                    carrier_id:    $running->carrier_id,
                    service_code:  $running->service_code,
                    service_label: $running->service_label,
                    cost_cents:    $running->cost_cents + $quote->cost_cents,
                    currency:      $running->currency,
                    eta_days:      max( $running->eta_days, $quote->eta_days ),
                    rate_id:       $running->rate_id,
                    meta:          $running->meta
                );
            }
            $by_service = $next;
        }

        return array_values( $by_service );
    }

    /**
     * @param array<string,string> $credentials
     * @return array<string,mixed>
     */
    public function build_rates_payload( Rate_Request $request, array $credentials ): array {
        return $this->build_rates_payload_for_package( $request, $request->packages[0], $credentials );
    }

    /**
     * Build the USPS Prices payload for a single package. Used both by the
     * legacy (single-package) `build_rates_payload()` entry point and by
     * the multi-package fan-out inside {@see rates()}.
     *
     * @param array<string,string> $credentials
     * @return array<string,mixed>
     */
    private function build_rates_payload_for_package( Rate_Request $request, Package $package, array $credentials ): array {
        return array(
            'originZIPCode'      => $request->origin['postcode'] ?? '',
            'destinationZIPCode' => $request->destination['postcode'] ?? '',
            'weight'             => round( $package->weight_grams / 453.592, 2 ),
            'length'             => round( $package->length_mm / 25.4, 2 ),
            'width'              => round( $package->depth_mm / 25.4, 2 ),
            'height'             => round( $package->height_mm / 25.4, 2 ),
            'mailingDate'        => gmdate( 'Y-m-d' ),
            'accountType'        => 'EPS',
            'accountNumber'      => (string) ( $credentials['crid'] ?? '' ),
            'mailerID'           => (string) ( $credentials['mid'] ?? '' ),
        );
    }

    /**
     * @return Rate_Quote[]
     */
    public function parse_rates_response( string $body ): array {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            return array();
        }

        $options = $decoded['rateOptions'] ?? array();
        if ( ! is_array( $options ) ) {
            return array();
        }

        $quotes = array();
        foreach ( $options as $option ) {
            if ( ! is_array( $option ) ) {
                continue;
            }

            $rates  = $option['rates'] ?? array();
            $total  = $option['totalBasePrice'] ?? null;
            $rate   = is_array( $rates ) ? ( $rates[0] ?? array() ) : array();
            $class  = (string) ( $rate['mailClass'] ?? '' );
            $desc   = (string) ( $rate['description'] ?? $class );
            $price  = $total ?? ( $rate['price'] ?? null );

            if ( '' === $class || null === $price ) {
                continue;
            }

            // USPS quotes in USD only, but route through
            // Currency::to_minor_units() for codebase consistency and
            // banker's rounding.
            $cents = \TejCart\Money\Currency::to_minor_units( $price, 'USD' );

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $class,
                service_label: 'USPS ' . $desc,
                cost_cents:    $cents,
                currency:      'USD',
                // No ETA: the USPS Prices API returns cost only. Delivery
                // estimates live in the separate USPS Service Standards
                // API, which would need its own keyed request per route.
                eta_days:      null,
                rate_id:       null,
                meta:          array( 'zone' => (string) ( $rate['zone'] ?? '' ) ),
            );
        }

        return $quotes;
    }

    /**
     * Buy a USPS label via /labels/v3/label.
     *
     * The rate_id is the USPS mail-class code (e.g. PRIORITY_MAIL); USPS
     * doesn't issue ephemeral rate-ids so the label endpoint takes the
     * full ratepayload again. The merchant supplies origin/destination
     * via credentials extras, populated by Label_Service from the order.
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret ) {
            throw new Carrier_Exception( 'USPS: missing client_id or client_secret credential.' );
        }
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'USPS: rate_id (mail class) required.' );
        }

        $is_test    = 'test' === ( $credentials['environment'] ?? 'test' );
        $token_url  = $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE;
        $labels_url = $is_test ? self::LABELS_URL_TEST : self::LABELS_URL_LIVE;
        // USPS OAuth v3 identifies a replay-safe label submit via the
        // `X-Idempotency-Key` header (per USPS Shipping Services spec).
        // A duplicate submit with the same value within USPS's dedup
        // window returns the original tracking number / postage instead
        // of minting a second label. Covered by
        // tests/Unit/Idempotency_Header_Propagation_Test.
        $idem       = (string) ( $credentials['__idempotency_key'] ?? '' );

        $token = $this->oauth->token(
            'usps:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $token_url,
            $client_id,
            $client_secret,
            array( 'scope' => 'labels' ),
            'body'
        );

        $response = $this->http->request( 'POST', $labels_url, array(
            'driver_id' => $this->id(),
            'headers'   => array_filter( array(
                'Authorization'   => 'Bearer ' . $token,
                'Content-Type'    => 'application/json',
                'Accept'          => 'application/json',
                'X-Idempotency-Key' => '' === $idem ? null : $idem,
            ) ),
            'body' => wp_json_encode( $this->build_label_payload( $rate_id, $credentials ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'USPS: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_label_response( $response['body'] );
    }

    /**
     * @param array<string,string> $credentials
     * @return array<string,mixed>
     */
    public function build_label_payload( string $mail_class, array $credentials ): array {
        return array(
            'imageInfo'     => array( 'imageType' => 'PDF' ),
            'mailClass'     => $mail_class,
            'processingCategory' => 'NON_MACHINABLE',
            'mailingDate'   => gmdate( 'Y-m-d' ),
            'accountNumber' => (string) ( $credentials['crid'] ?? '' ),
            'mailerID'      => (string) ( $credentials['mid'] ?? '' ),
            'fromAddress'   => array(
                'streetAddress' => (string) ( $credentials['__from_line1'] ?? '' ),
                'city'          => (string) ( $credentials['__from_city'] ?? '' ),
                'state'         => (string) ( $credentials['__from_state'] ?? '' ),
                'ZIPCode'       => (string) ( $credentials['__from_zip'] ?? '' ),
            ),
            'toAddress' => array(
                'streetAddress' => (string) ( $credentials['__to_line1'] ?? '' ),
                'city'          => (string) ( $credentials['__to_city'] ?? '' ),
                'state'         => (string) ( $credentials['__to_state'] ?? '' ),
                'ZIPCode'       => (string) ( $credentials['__to_zip'] ?? '' ),
            ),
            'packageDescription' => array(
                'weight' => (float) ( $credentials['__weight_lb'] ?? 0 ),
                'length' => (float) ( $credentials['__length_in'] ?? 0 ),
                'width'  => (float) ( $credentials['__width_in'] ?? 0 ),
                'height' => (float) ( $credentials['__height_in'] ?? 0 ),
            ),
        );
    }

    public function parse_label_response( string $body ): Label {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'USPS: malformed label response.' );
        }

        $tracking  = (string) ( $decoded['trackingNumber'] ?? $decoded['labelMetadata']['trackingNumber'] ?? '' );
        $label_url = (string) ( $decoded['labelImage'] ?? $decoded['labelMetadata']['labelDownloadURL'] ?? '' );
        $cost_str  = (string) ( $decoded['postagePrice'] ?? $decoded['labelMetadata']['postage'] ?? '0' );

        if ( '' === $tracking || '' === $label_url ) {
            throw new Carrier_Exception( 'USPS: response missing tracking or label URL.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $tracking,
            label_url: $label_url,
            label_format: 'PDF',
            cost_cents: \TejCart\Money\Currency::to_minor_units( $cost_str, 'USD' ),
            currency: 'USD'
        );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret ) {
            throw new Carrier_Exception( 'USPS: missing credentials.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'USPS: tracking_number required.' );
        }

        $is_test   = 'test' === ( $credentials['environment'] ?? 'test' );
        $token_url = $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE;
        $track_url = ( $is_test ? self::TRACK_URL_TEST : self::TRACK_URL_LIVE ) . rawurlencode( $tracking_number );

        $token = $this->oauth->token(
            'usps:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $token_url,
            $client_id,
            $client_secret,
            array( 'scope' => 'tracking' ),
            'body'
        );

        $response = $this->http->request( 'GET', $track_url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'USPS: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'USPS: malformed tracking response.' );
        }

        $status = $this->map_status( (string) ( $decoded['statusCategory'] ?? $decoded['eventSummary'] ?? '' ) );

        $events = array();
        $hist   = $decoded['trackingEvents'] ?? array();
        if ( is_array( $hist ) ) {
            foreach ( $hist as $event ) {
                if ( ! is_array( $event ) ) {
                    continue;
                }
                $events[] = array(
                    'timestamp'   => isset( $event['eventTimestamp'] ) ? (int) strtotime( (string) $event['eventTimestamp'] ) : 0,
                    'status'      => $this->map_status( (string) ( $event['eventType'] ?? '' ) ),
                    'description' => (string) ( $event['eventDescription'] ?? '' ),
                    'location'    => trim( (string) ( $event['city'] ?? '' ) . ', ' . (string) ( $event['state'] ?? '' ), ', ' ),
                );
            }
        }

        return new Tracking(
            carrier_id: $this->id(),
            tracking_number: $tracking_number,
            status: $status,
            events: $events,
            estimated_delivery: isset( $decoded['expectedDeliveryDate'] ) && '' !== $decoded['expectedDeliveryDate']
                ? (int) strtotime( (string) $decoded['expectedDeliveryDate'] )
                : null
        );
    }

    /**
     * Generate a USPS SCAN form (PS Form 5630) for one or more
     * previously-purchased labels.
     *
     * @param string[]             $shipment_tokens List of USPS tracking numbers.
     * @param array<string,string> $credentials
     */
    public function manifest( array $shipment_tokens, array $credentials ): Manifest {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret ) {
            throw new Carrier_Exception( 'USPS: missing credentials.' );
        }
        if ( array() === $shipment_tokens ) {
            throw new Carrier_Exception( 'USPS: at least one shipment token required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $token   = $this->oauth->token(
            'usps:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE,
            $client_id,
            $client_secret,
            array( 'scope' => 'manifest' ),
            'body'
        );

        $url = $is_test ? self::SCAN_URL_TEST : self::SCAN_URL_LIVE;

        $response = $this->http->request( 'POST', $url, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'mailingDate'      => gmdate( 'Y-m-d' ),
                'imageInfo'        => array( 'imageType' => 'PDF' ),
                'trackingNumbers'  => array_values( array_map( 'strval', $shipment_tokens ) ),
                'mailerID'         => (string) ( $credentials['mid'] ?? '' ),
            ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'USPS: manifest failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        $decoded   = json_decode( $response['body'], true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'USPS: malformed manifest response.' );
        }
        $form_id   = (string) ( $decoded['formNumber'] ?? $decoded['manifestId'] ?? '' );
        $form_url  = (string) ( $decoded['formImage'] ?? $decoded['manifestUrl'] ?? '' );
        if ( '' === $form_url ) {
            throw new Carrier_Exception( 'USPS: manifest response missing formImage.' );
        }

        return new Manifest(
            carrier_id: $this->id(),
            manifest_id: $form_id,
            manifest_url: $form_url,
            manifest_format: 'PDF',
            tracking_numbers: array_map( 'strval', $shipment_tokens )
        );
    }

    private function map_status( string $raw ): string {
        $u = strtoupper( $raw );
        return match ( true ) {
            str_contains( $u, 'DELIVERED' )      => Tracking::STATUS_DELIVERED,
            str_contains( $u, 'OUT FOR DELIVERY' ),
            str_contains( $u, 'OUT_FOR_DELIVERY' ) => Tracking::STATUS_OUT_FOR_DELIVERY,
            str_contains( $u, 'TRANSIT' ),
            str_contains( $u, 'PROCESSING' ),
            str_contains( $u, 'ACCEPT' ),
            str_contains( $u, 'IN ROUTE' )       => Tracking::STATUS_IN_TRANSIT,
            str_contains( $u, 'PRE_SHIPMENT' ),
            str_contains( $u, 'PRE-SHIPMENT' )   => Tracking::STATUS_PRE_TRANSIT,
            str_contains( $u, 'RETURN' )         => Tracking::STATUS_RETURNED,
            str_contains( $u, 'EXCEPTION' ),
            str_contains( $u, 'FAILURE' )        => Tracking::STATUS_EXCEPTION,
            default                              => Tracking::STATUS_UNKNOWN,
        };
    }
}
