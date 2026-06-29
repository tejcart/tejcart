<?php
/**
 * FedEx direct driver.
 *
 * Uses the FedEx Rate v1 API with OAuth 2.0 client-credentials auth
 * (credentials are POSTed in the request body, not a Basic header).
 *
 * API reference: https://developer.fedex.com/api/en-us/catalog/rate.html
 *
 * @package TejCart\Shipping_Plugin\Carriers\Global\FedEx
 */

namespace TejCart\Shipping_Plugin\Carriers\Global\FedEx;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\HTTP_Client;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\OAuth_Token_Cache;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class FedEx_Driver extends Abstract_Carrier_Driver {
    public const TOKEN_URL_LIVE = 'https://apis.fedex.com/oauth/token';
    public const TOKEN_URL_TEST = 'https://apis-sandbox.fedex.com/oauth/token';
    public const RATES_URL_LIVE = 'https://apis.fedex.com/rate/v1/rates/quotes';
    public const RATES_URL_TEST = 'https://apis-sandbox.fedex.com/rate/v1/rates/quotes';
    public const SHIP_URL_LIVE  = 'https://apis.fedex.com/ship/v1/shipments';
    public const SHIP_URL_TEST  = 'https://apis-sandbox.fedex.com/ship/v1/shipments';
    public const VOID_URL_LIVE  = 'https://apis.fedex.com/ship/v1/shipments/cancel';
    public const VOID_URL_TEST  = 'https://apis-sandbox.fedex.com/ship/v1/shipments/cancel';
    public const TRACK_URL_LIVE = 'https://apis.fedex.com/track/v1/trackingnumbers';
    public const TRACK_URL_TEST = 'https://apis-sandbox.fedex.com/track/v1/trackingnumbers';

    private const SERVICE_LABELS = array(
        'FEDEX_GROUND'                  => 'FedEx Ground',
        'GROUND_HOME_DELIVERY'          => 'FedEx Home Delivery',
        'FEDEX_2_DAY'                   => 'FedEx 2Day',
        'FEDEX_2_DAY_AM'                => 'FedEx 2Day AM',
        'FEDEX_EXPRESS_SAVER'           => 'FedEx Express Saver',
        'STANDARD_OVERNIGHT'            => 'FedEx Standard Overnight',
        'PRIORITY_OVERNIGHT'            => 'FedEx Priority Overnight',
        'FIRST_OVERNIGHT'               => 'FedEx First Overnight',
        'INTERNATIONAL_ECONOMY'         => 'FedEx International Economy',
        'INTERNATIONAL_PRIORITY'        => 'FedEx International Priority',
        'INTERNATIONAL_FIRST'           => 'FedEx International First',
        'FEDEX_INTERNATIONAL_CONNECT_PLUS' => 'FedEx International Connect Plus',
    );

    private OAuth_Token_Cache $oauth;

    public function __construct( HTTP_Client $http, ?OAuth_Token_Cache $oauth = null ) {
        parent::__construct( $http );
        $this->oauth = $oauth ?? new OAuth_Token_Cache( $http );
    }

    public function id(): string {
        return 'fedex';
    }

    public function label(): string {
        return 'FedEx';
    }

    public function region(): string {
        return 'global';
    }

    public function credential_fields(): array {
        return array(
            'client_id'      => array( 'type' => 'text',     'title' => __( 'API key', 'tejcart' ),    'secret' => false ),
            'client_secret'  => array( 'type' => 'password', 'title' => __( 'Secret key', 'tejcart' ), 'secret' => true ),
            'account_number' => array(
                'type'        => 'text',
                'title'       => __( 'Account number', 'tejcart' ),
                'description' => __( 'FedEx account number for negotiated rates.', 'tejcart' ),
                'secret'      => false,
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
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret ) {
            throw new Carrier_Exception( 'FedEx: missing client_id or client_secret credential.' );
        }

        $is_test   = 'test' === ( $credentials['environment'] ?? 'test' );
        $token_url = $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE;
        $rates_url = $is_test ? self::RATES_URL_TEST : self::RATES_URL_LIVE;

        try {
            $token = $this->oauth->token(
                'fedex:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
                $token_url,
                $client_id,
                $client_secret,
                array(),
                'body'
            );

            $response = $this->http->request( 'POST', $rates_url, array(
                'headers' => array(
                    'Authorization'                  => 'Bearer ' . $token,
                    'Content-Type'                   => 'application/json',
                    'Accept'                         => 'application/json',
                    'X-locale'                       => 'en_US',
                    'x-customer-transaction-id'      => substr( (string) wp_generate_uuid4(), 0, 32 ),
                ),
                'body' => wp_json_encode( $this->build_rates_payload( $request, $credentials ) ),
            ) );
        } catch ( Carrier_Exception $e ) {
            return array();
        }

        if ( 401 === $response['status'] || 403 === $response['status'] ) {
            $this->oauth->forget( 'fedex:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ) );
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
        $account_number = (string) ( $credentials['account_number'] ?? '' );

        // Carts that exceed a single box (oversize items, mixed dim-weight
        // categories) come in here with N packages — FedEx Rate v1 wants
        // one `requestedPackageLineItems` entry per package and quotes the
        // multi-piece shipment as a whole. Sending only packages[0] would
        // systematically under-quote any multi-box cart.
        $line_items = array();
        foreach ( $request->packages as $package ) {
            $line_items[] = array(
                'weight'     => array(
                    'units' => 'LB',
                    'value' => round( $package->weight_grams / 453.592, 2 ),
                ),
                'dimensions' => array(
                    'length' => (int) round( $package->length_mm / 25.4 ),
                    'width'  => (int) round( $package->depth_mm / 25.4 ),
                    'height' => (int) round( $package->height_mm / 25.4 ),
                    'units'  => 'IN',
                ),
            );
        }

        return array(
            'accountNumber'    => array( 'value' => $account_number ),
            'requestedShipment' => array(
                'shipper'    => array( 'address' => $this->fedex_address( $request->origin ) ),
                'recipient'  => array( 'address' => $this->fedex_address( $request->destination ) ),
                'pickupType' => 'DROPOFF_AT_FEDEX_LOCATION',
                'rateRequestType' => array( 'ACCOUNT', 'LIST' ),
                'totalPackageCount'         => count( $line_items ),
                'requestedPackageLineItems' => $line_items,
            ),
        );
    }

    /**
     * @param array<string,string> $address
     * @return array<string,mixed>
     */
    private function fedex_address( array $address ): array {
        return array(
            'streetLines'         => array( $address['line1'] ?? '' ),
            'city'                => $address['city'] ?? '',
            'stateOrProvinceCode' => $address['state'] ?? '',
            'postalCode'          => $address['postcode'] ?? '',
            'countryCode'         => $address['country'] ?? '',
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

        $details = $decoded['output']['rateReplyDetails'] ?? array();
        if ( ! is_array( $details ) ) {
            return array();
        }

        $quotes = array();
        foreach ( $details as $detail ) {
            if ( ! is_array( $detail ) ) {
                continue;
            }
            $service = (string) ( $detail['serviceType'] ?? '' );
            if ( '' === $service ) {
                continue;
            }

            $rated = $detail['ratedShipmentDetails'] ?? array();
            if ( ! is_array( $rated ) || array() === $rated ) {
                continue;
            }

            $first = $rated[0];
            if ( ! is_array( $first ) ) {
                continue;
            }
            $charge   = (string) ( $first['totalNetCharge'] ?? '' );
            $currency = strtoupper( (string) ( $first['currency'] ?? 'USD' ) );
            if ( '' === $charge ) {
                continue;
            }

            // Carrier API returns major units (e.g. "12.50"). Use the
            // currency-aware multiplier so JPY (×1) and KWD/BHD/OMR
            // (×1000) are converted correctly — a hardcoded ×100 would
            // overstate JPY rates 100× and understate KWD rates 10×.
            $cents = \TejCart\Money\Currency::to_minor_units( $charge, $currency );

            $eta = null;
            if ( isset( $detail['commit']['dateDetail']['dayCxsFormat'] ) ) {
                $eta = (int) $detail['commit']['dateDetail']['dayCxsFormat'];
            } elseif ( isset( $detail['commit']['transitTime'] ) ) {
                $eta = $this->transit_to_days( (string) $detail['commit']['transitTime'] );
            }

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $service,
                service_label: self::SERVICE_LABELS[ $service ] ?? ( 'FedEx ' . $service ),
                cost_cents:    $cents,
                currency:      $currency,
                eta_days:      $eta,
                rate_id:       null,
                meta:          array()
            );
        }

        return $quotes;
    }

    private function transit_to_days( string $transit ): ?int {
        // Some responses carry a numeric form directly.
        if ( preg_match( '/(\d+)/', $transit, $m ) ) {
            return (int) $m[1];
        }

        // FedEx's commit.transitTime is normally a number-word enum such as
        // "ONE_DAY", "THREE_DAYS" or "TWELVE_DAYS" (never a digit), so the
        // numeric match above misses every real value. Map the leading
        // number word to an integer; unknown/"UNKNOWN" falls through to null.
        static $words = array(
            'ONE'       => 1,
            'TWO'       => 2,
            'THREE'     => 3,
            'FOUR'      => 4,
            'FIVE'      => 5,
            'SIX'       => 6,
            'SEVEN'     => 7,
            'EIGHT'     => 8,
            'NINE'      => 9,
            'TEN'       => 10,
            'ELEVEN'    => 11,
            'TWELVE'    => 12,
            'THIRTEEN'  => 13,
            'FOURTEEN'  => 14,
            'FIFTEEN'   => 15,
            'SIXTEEN'   => 16,
            'SEVENTEEN' => 17,
            'EIGHTEEN'  => 18,
            'NINETEEN'  => 19,
            'TWENTY'    => 20,
        );

        $first = (string) strtok( strtoupper( $transit ), '_' );

        return $words[ $first ] ?? null;
    }

    /**
     * Buy a FedEx label via /ship/v1/shipments.
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        $account       = trim( (string) ( $credentials['account_number'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret || '' === $account ) {
            throw new Carrier_Exception( 'FedEx: missing client_id, client_secret or account_number credential.' );
        }
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'FedEx: rate_id (service type) required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $token   = $this->oauth->token(
            'fedex:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE,
            $client_id,
            $client_secret,
            array(),
            'body'
        );
        // FedEx OAuth REST identifies a replay-safe idempotency token via
        // the `x-customer-transaction-id` header (per FedEx Ship API spec).
        // A duplicate submit with the same value within FedEx's dedup
        // window returns the original transactionShipments payload rather
        // than minting a second label. Covered by
        // tests/Unit/Idempotency_Header_Propagation_Test.
        $idem    = (string) ( $credentials['__idempotency_key'] ?? '' );

        $response = $this->http->request(
            'POST',
            $is_test ? self::SHIP_URL_TEST : self::SHIP_URL_LIVE,
            array(
                'driver_id' => $this->id(),
                'headers'   => array_filter( array(
                    'Authorization'    => 'Bearer ' . $token,
                    'Content-Type'     => 'application/json',
                    'Accept'           => 'application/json',
                    'x-customer-transaction-id' => '' === $idem ? null : $idem,
                ) ),
                'body' => wp_json_encode( $this->build_ship_payload( $rate_id, $account, $credentials ) ),
            )
        );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'FedEx: buy_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }
        return $this->parse_label_response( $response['body'] );
    }

    /**
     * @param array<string,string> $credentials
     * @return array<string,mixed>
     */
    public function build_ship_payload( string $service_type, string $account, array $credentials ): array {
        return array(
            'labelResponseOptions' => 'URL_ONLY',
            'accountNumber'        => array( 'value' => $account ),
            'requestedShipment'    => array(
                'shipper' => array(
                    'contact' => array( 'personName' => 'Shipper', 'phoneNumber' => '0000000000' ),
                    'address' => array(
                        'streetLines' => array( (string) ( $credentials['__from_line1'] ?? '' ) ),
                        'city'        => (string) ( $credentials['__from_city'] ?? '' ),
                        'stateOrProvinceCode' => (string) ( $credentials['__from_state'] ?? '' ),
                        'postalCode'  => (string) ( $credentials['__from_zip'] ?? '' ),
                        'countryCode' => (string) ( $credentials['__from_country'] ?? 'US' ),
                    ),
                ),
                'recipients' => array(
                    array(
                        'contact' => array( 'personName' => 'Recipient', 'phoneNumber' => '0000000000' ),
                        'address' => array(
                            'streetLines' => array( (string) ( $credentials['__to_line1'] ?? '' ) ),
                            'city'        => (string) ( $credentials['__to_city'] ?? '' ),
                            'stateOrProvinceCode' => (string) ( $credentials['__to_state'] ?? '' ),
                            'postalCode'  => (string) ( $credentials['__to_zip'] ?? '' ),
                            'countryCode' => (string) ( $credentials['__to_country'] ?? 'US' ),
                        ),
                    ),
                ),
                'shipDatestamp'      => gmdate( 'Y-m-d' ),
                'serviceType'        => $service_type,
                'packagingType'      => 'YOUR_PACKAGING',
                'pickupType'         => 'USE_SCHEDULED_PICKUP',
                'shippingChargesPayment' => array( 'paymentType' => 'SENDER' ),
                'labelSpecification' => array(
                    'imageType'  => 'PDF',
                    'labelStockType' => 'PAPER_85X11_TOP_HALF_LABEL',
                ),
                'requestedPackageLineItems' => array(
                    array(
                        'weight' => array(
                            'units' => 'LB',
                            'value' => (float) ( $credentials['__weight_lb'] ?? 1 ),
                        ),
                    ),
                ),
            ),
        );
    }

    public function parse_label_response( string $body ): Label {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'FedEx: malformed ship response.' );
        }

        $output = $decoded['output'] ?? array();
        $tx     = $output['transactionShipments'][0] ?? array();
        if ( ! is_array( $tx ) ) {
            throw new Carrier_Exception( 'FedEx: ship response missing transactionShipments.' );
        }

        $tracking   = (string) ( $tx['masterTrackingNumber'] ?? $tx['pieceResponses'][0]['trackingNumber'] ?? '' );
        $piece      = $tx['pieceResponses'][0] ?? array();
        $documents  = is_array( $piece ) ? ( $piece['packageDocuments'] ?? array() ) : array();
        $first      = is_array( $documents ) ? ( $documents[0] ?? array() ) : array();
        $label_url  = (string) ( $first['url'] ?? '' );
        if ( '' === $label_url ) {
            $b64 = (string) ( $first['encodedLabel'] ?? '' );
            if ( '' !== $b64 ) {
                $label_url = 'data:application/pdf;base64,' . $b64;
            }
        }
        $cost_str   = (string) ( $tx['shipmentRating']['shipmentRateDetails'][0]['totalNetCharge'] ?? '0' );
        $currency   = strtoupper( (string) ( $tx['shipmentRating']['shipmentRateDetails'][0]['currency'] ?? 'USD' ) );

        if ( '' === $tracking || '' === $label_url ) {
            throw new Carrier_Exception( 'FedEx: ship response missing tracking or label URL.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $tracking,
            label_url: $label_url,
            label_format: 'PDF',
            // Use the per-currency multiplier (see comment in get_rates).
            cost_cents: \TejCart\Money\Currency::to_minor_units( $cost_str, $currency ),
            currency: $currency
        );
    }

    public function void_label( string $shipment_token, array $credentials ): void {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        $account       = trim( (string) ( $credentials['account_number'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret || '' === $account ) {
            throw new Carrier_Exception( 'FedEx: missing credentials.' );
        }
        if ( '' === $shipment_token ) {
            throw new Carrier_Exception( 'FedEx: shipment_token (tracking number) required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $token   = $this->oauth->token(
            'fedex:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE,
            $client_id,
            $client_secret,
            array(),
            'body'
        );

        $response = $this->http->request(
            'PUT',
            $is_test ? self::VOID_URL_TEST : self::VOID_URL_LIVE,
            array(
                'driver_id' => $this->id(),
                'headers'   => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'accountNumber'  => array( 'value' => $account ),
                    'trackingNumber' => $shipment_token,
                ) ),
            )
        );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'FedEx: void_label failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        $client_id     = trim( (string) ( $credentials['client_id'] ?? '' ) );
        $client_secret = trim( (string) ( $credentials['client_secret'] ?? '' ) );
        if ( '' === $client_id || '' === $client_secret ) {
            throw new Carrier_Exception( 'FedEx: missing credentials.' );
        }
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'FedEx: tracking_number required.' );
        }

        $is_test = 'test' === ( $credentials['environment'] ?? 'test' );
        $token   = $this->oauth->token(
            'fedex:' . md5( $client_id . '|' . ( $is_test ? 'test' : 'live' ) ),
            $is_test ? self::TOKEN_URL_TEST : self::TOKEN_URL_LIVE,
            $client_id,
            $client_secret,
            array(),
            'body'
        );

        $response = $this->http->request(
            'POST',
            $is_test ? self::TRACK_URL_TEST : self::TRACK_URL_LIVE,
            array(
                'driver_id' => $this->id(),
                'headers'   => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ),
                'body' => wp_json_encode( array(
                    'includeDetailedScans' => true,
                    'trackingInfo' => array(
                        array( 'trackingNumberInfo' => array( 'trackingNumber' => $tracking_number ) ),
                    ),
                ) ),
            )
        );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'FedEx: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'FedEx: malformed tracking response.' );
        }

        $result = $decoded['output']['completeTrackResults'][0]['trackResults'][0] ?? array();
        if ( ! is_array( $result ) ) {
            return new Tracking( $this->id(), $tracking_number, Tracking::STATUS_UNKNOWN );
        }

        $latest = (string) ( $result['latestStatusDetail']['code'] ?? $result['latestStatusDetail']['statusByLocale'] ?? '' );
        $status = $this->map_tracking_status( $latest );

        $events = array();
        $hist   = $result['scanEvents'] ?? array();
        if ( is_array( $hist ) ) {
            foreach ( $hist as $event ) {
                if ( ! is_array( $event ) ) {
                    continue;
                }
                $location_parts = array_filter( array(
                    (string) ( $event['scanLocation']['city'] ?? '' ),
                    (string) ( $event['scanLocation']['stateOrProvinceCode'] ?? '' ),
                    (string) ( $event['scanLocation']['countryCode'] ?? '' ),
                ) );
                $events[] = array(
                    'timestamp'   => isset( $event['date'] ) ? (int) strtotime( (string) $event['date'] ) : 0,
                    'status'      => $this->map_tracking_status( (string) ( $event['eventType'] ?? '' ) ),
                    'description' => (string) ( $event['eventDescription'] ?? '' ),
                    'location'    => implode( ', ', $location_parts ),
                );
            }
        }

        $eta = isset( $result['estimatedDeliveryTimeWindow']['window']['ends'] )
            ? (int) strtotime( (string) $result['estimatedDeliveryTimeWindow']['window']['ends'] )
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

    private function map_tracking_status( string $code ): string {
        $u = strtoupper( $code );
        return match ( true ) {
            'DL' === $u, str_contains( $u, 'DELIVERED' )                => Tracking::STATUS_DELIVERED,
            'OD' === $u, str_contains( $u, 'OUT FOR DELIVERY' )         => Tracking::STATUS_OUT_FOR_DELIVERY,
            'IT' === $u, 'AR' === $u, 'DP' === $u,
            str_contains( $u, 'IN TRANSIT' ),
            str_contains( $u, 'ARRIVED' ),
            str_contains( $u, 'DEPARTED' )                              => Tracking::STATUS_IN_TRANSIT,
            'PU' === $u, 'AF' === $u,
            str_contains( $u, 'PICKED UP' ),
            str_contains( $u, 'LABEL' )                                 => Tracking::STATUS_PRE_TRANSIT,
            'RT' === $u, str_contains( $u, 'RETURN' )                   => Tracking::STATUS_RETURNED,
            'DE' === $u, 'CA' === $u, 'EX' === $u,
            str_contains( $u, 'EXCEPTION' ),
            str_contains( $u, 'CANCEL' )                                => Tracking::STATUS_EXCEPTION,
            default                                                     => Tracking::STATUS_UNKNOWN,
        };
    }
}
