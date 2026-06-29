<?php
/**
 * Shiprocket driver (India multi-carrier aggregator).
 *
 * Auth flow: POST email + password to /external/auth/login, receive a
 * JWT-shaped token (good for ~10 days). Token is cached through
 * Persistent_Cache (object cache when a persistent backend exists,
 * otherwise a 6-hour transient) so it survives across requests and the
 * driver doesn't re-authenticate on every checkout recalculation. The
 * transient fallback honours the `tejcart_shipping_persistent_cache`
 * filter for sites that prefer to keep the derived token out of the DB.
 *
 * Rate lookup: GET /external/courier/serviceability/ with origin and
 * destination pincodes + weight + COD flag, returns an array of
 * available couriers with freight charges.
 *
 * API reference: https://apidocs.shiprocket.in/
 *
 * @package TejCart\Shipping_Plugin\Carriers\APAC\Shiprocket
 */

namespace TejCart\Shipping_Plugin\Carriers\APAC\Shiprocket;

use TejCart\Shipping_Plugin\Core\Abstract_Carrier_Driver;
use TejCart\Shipping_Plugin\Core\Carrier_Exception;
use TejCart\Shipping_Plugin\Core\HTTP_Client;
use TejCart\Shipping_Plugin\Core\Label;
use TejCart\Shipping_Plugin\Core\Persistent_Cache;
use TejCart\Shipping_Plugin\Core\Rate_Quote;
use TejCart\Shipping_Plugin\Core\Rate_Request;
use TejCart\Shipping_Plugin\Core\Tracking;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shiprocket_Driver extends Abstract_Carrier_Driver {
    public const LOGIN_URL = 'https://apiv2.shiprocket.in/v1/external/auth/login';
    public const RATES_URL = 'https://apiv2.shiprocket.in/v1/external/courier/serviceability/';
    public const ORDER_URL = 'https://apiv2.shiprocket.in/v1/external/orders/create/adhoc';
    public const ASSIGN_URL = 'https://apiv2.shiprocket.in/v1/external/courier/assign/awb';
    public const LABEL_URL = 'https://apiv2.shiprocket.in/v1/external/courier/generate/label';
    public const PICKUP_URL = 'https://apiv2.shiprocket.in/v1/external/courier/generate/pickup';
    public const TRACK_URL = 'https://apiv2.shiprocket.in/v1/external/courier/track/awb/';

    public const TOKEN_CACHE_GROUP = 'tejcart_shipping_shiprocket';
    public const TOKEN_CACHE_TTL   = 60 * 60 * 6;

    private Persistent_Cache $token_cache;

    public function __construct( HTTP_Client $http, ?Persistent_Cache $token_cache = null ) {
        parent::__construct( $http );
        $this->token_cache = $token_cache ?? new Persistent_Cache();
    }

    public function id(): string {
        return 'shiprocket';
    }

    public function label(): string {
        return 'Shiprocket';
    }

    public function region(): string {
        return 'apac';
    }

    public function credential_fields(): array {
        // Shiprocket exposes a single production API (apiv2.shiprocket.in)
        // with no separate sandbox endpoint or sandbox token flow, so the
        // driver deliberately omits the Live/Sandbox segmented control
        // that other carriers expose — every Shiprocket account is "live".
        return array(
            'email' => array(
                'type'   => 'text',
                'title'  => __( 'Account email', 'tejcart' ),
                'secret' => false,
            ),
            'password' => array(
                'type'   => 'password',
                'title'  => __( 'Account password', 'tejcart' ),
                'secret' => true,
            ),
        );
    }

    /**
     * Probe Shiprocket's auth endpoint with the merchant's credentials.
     * Shiprocket returns a JWT on success; anything else is reported as
     * the human-readable reason from the response (when present).
     *
     * @param array<string,string> $credentials
     * @return array{ok:bool,message:string}
     */
    public function test_connection( array $credentials ): array {
        $email    = trim( (string) ( $credentials['email'] ?? '' ) );
        $password = trim( (string) ( $credentials['password'] ?? '' ) );
        if ( '' === $email || '' === $password ) {
            return array(
                'ok'      => false,
                'message' => __( 'Enter both the Shiprocket account email and password, save, then test again.', 'tejcart' ),
            );
        }

        try {
            $response = $this->http->request( 'POST', self::LOGIN_URL, array(
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ),
                'body' => wp_json_encode( array( 'email' => $email, 'password' => $password ) ),
            ) );
        } catch ( \Throwable $e ) {
            return array(
                'ok'      => false,
                'message' => sprintf(
                    /* translators: %s: transport error message. */
                    __( 'Could not reach Shiprocket: %s', 'tejcart' ),
                    $e->getMessage()
                ),
            );
        }

        $status  = (int) ( $response['status'] ?? 0 );
        $decoded = json_decode( (string) ( $response['body'] ?? '' ), true );
        $token   = is_array( $decoded ) ? (string) ( $decoded['token'] ?? '' ) : '';

        if ( $status < 400 && '' !== $token ) {
            $this->token_cache->set( 'token:' . md5( $email ), self::TOKEN_CACHE_GROUP, $token, self::TOKEN_CACHE_TTL );

            return array(
                'ok'      => true,
                'message' => __( 'Connected to Shiprocket.', 'tejcart' ),
            );
        }

        $reason = '';
        if ( is_array( $decoded ) ) {
            $reason = (string) ( $decoded['message'] ?? $decoded['error'] ?? '' );
        }
        if ( '' === $reason ) {
            $reason = sprintf(
                /* translators: %d: HTTP status code. */
                __( 'HTTP %d from Shiprocket.', 'tejcart' ),
                $status
            );
        }

        return array(
            'ok'      => false,
            'message' => sprintf(
                /* translators: %s: error reason returned by Shiprocket. */
                __( 'Shiprocket rejected the credentials: %s', 'tejcart' ),
                $reason
            ),
        );
    }

    public function rates( Rate_Request $request, array $credentials ): array {
        $email    = trim( (string) ( $credentials['email'] ?? '' ) );
        $password = trim( (string) ( $credentials['password'] ?? '' ) );
        if ( '' === $email || '' === $password ) {
            throw new Carrier_Exception( 'Shiprocket: missing email or password credential.' );
        }

        try {
            $token = $this->get_token( $email, $password );

            $url = self::RATES_URL . '?' . http_build_query( $this->build_query_params( $request ) );

            $response = $this->http->request( 'GET', $url, array(
                'headers' => array(
                    'Authorization' => 'Bearer ' . $token,
                    'Accept'        => 'application/json',
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

    /**
     * @return array<string,string|int>
     */
    public function build_query_params( Rate_Request $request ): array {
        $package = $request->packages[0];

        return array(
            'pickup_postcode'   => (string) ( $request->origin['postcode'] ?? '' ),
            'delivery_postcode' => (string) ( $request->destination['postcode'] ?? '' ),
            'weight'            => round( $package->weight_grams / 1000.0, 3 ),
            'cod'               => (int) ( ! empty( $request->meta['cod'] ) ),
            // Shiprocket operates in INR (2-decimal). Use the
            // currency-aware inverse so the wire value stays correct
            // across the ISO 4217 matrix instead of assuming 2dp.
            'declared_value'    => \TejCart\Money\Currency::from_minor_units( (int) $package->declared_value_cents, 'INR' ),
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

        $couriers = $decoded['data']['available_courier_companies'] ?? array();
        if ( ! is_array( $couriers ) ) {
            return array();
        }

        $quotes = array();
        foreach ( $couriers as $courier ) {
            if ( ! is_array( $courier ) ) {
                continue;
            }

            $id    = (string) ( $courier['courier_company_id'] ?? '' );
            $name  = (string) ( $courier['courier_name'] ?? '' );
            $rate  = (string) ( $courier['rate'] ?? $courier['freight_charge'] ?? '' );
            $etd   = $courier['estimated_delivery_days'] ?? null;
            if ( '' === $id || '' === $name || '' === $rate ) {
                continue;
            }

            // INR is a 2-decimal currency so ×100 is correct today, but
            // route through Currency::to_minor_units() to match the
            // rest of the codebase and use banker's rounding.
            $cents = \TejCart\Money\Currency::to_minor_units( $rate, 'INR' );

            $quotes[] = new Rate_Quote(
                carrier_id:    $this->id(),
                service_code:  $id,
                service_label: $name,
                cost_cents:    $cents,
                currency:      'INR',
                eta_days:      null !== $etd ? (int) $etd : null,
                rate_id:       null,
                meta:          array(
                    'cod_charges' => (string) ( $courier['cod_charges'] ?? '0' ),
                    // Shiprocket also returns a literal estimated-delivery
                    // date ("Jun 26, 2026") and an hour-precise window
                    // alongside the day count. Carry both so checkout can
                    // show "Delivery by <date>" — far more decision-useful
                    // to the buyer than a bare day count.
                    'etd'         => (string) ( $courier['etd'] ?? '' ),
                    'etd_hours'   => (string) ( $courier['etd_hours'] ?? '' ),
                ),
            );
        }

        return $quotes;
    }

    /**
     * Resolve a login token from the credentials map (label/track entry point).
     *
     * @param array<string,string> $credentials
     */
    private function login_token( array $credentials ): string {
        $email    = trim( (string) ( $credentials['email'] ?? '' ) );
        $password = trim( (string) ( $credentials['password'] ?? '' ) );
        if ( '' === $email || '' === $password ) {
            throw new Carrier_Exception( 'Shiprocket: missing email or password credential.' );
        }
        return $this->get_token( $email, $password );
    }

    private function get_token( string $email, string $password ): string {
        $cache_key = 'token:' . md5( $email );

        $cached = $this->token_cache->get( $cache_key, self::TOKEN_CACHE_GROUP );
        if ( is_string( $cached ) && '' !== $cached ) {
            return $cached;
        }

        $response = $this->http->request( 'POST', self::LOGIN_URL, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ),
            'body' => wp_json_encode( array( 'email' => $email, 'password' => $password ) ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Shiprocket login failed (%s).', esc_html( (string) $response['status'] ) ) );
        }

        $decoded = json_decode( $response['body'], true );
        $token   = is_array( $decoded ) ? (string) ( $decoded['token'] ?? '' ) : '';
        if ( '' === $token ) {
            throw new Carrier_Exception( 'Shiprocket login response missing token.' );
        }

        $this->token_cache->set( $cache_key, self::TOKEN_CACHE_GROUP, $token, self::TOKEN_CACHE_TTL );
        return $token;
    }

    /**
     * Multi-step Shiprocket label flow:
     *   1) create order (POST /orders/create/adhoc) — returns order_id + shipment_id.
     *   2) assign AWB (POST /courier/assign/awb) — assigns AWB number for the
     *      chosen courier_id (rate_id).
     *   3) generate label (POST /courier/generate/label) — returns label_url.
     *
     * The merchant supplies pickup_location, items, and addresses via
     * credential extras populated by Label_Service.
     *
     * @param array<string,string> $credentials
     */
    public function buy_label( string $rate_id, array $credentials ): Label {
        $token = $this->login_token( $credentials );
        if ( '' === $rate_id ) {
            throw new Carrier_Exception( 'Shiprocket: rate_id (courier_id) required.' );
        }

        // Step 1: create order
        $order_payload = array(
            'order_id'         => (string) ( $credentials['__order_ref'] ?? ( 'TEJ' . time() ) ),
            'order_date'       => gmdate( 'Y-m-d H:i' ),
            'pickup_location'  => (string) ( $credentials['__pickup_location'] ?? 'Primary' ),
            'billing_customer_name' => (string) ( $credentials['__to_name'] ?? 'Recipient' ),
            'billing_last_name' => '',
            'billing_address'  => (string) ( $credentials['__to_line1'] ?? '' ),
            'billing_city'     => (string) ( $credentials['__to_city'] ?? '' ),
            'billing_pincode'  => (string) ( $credentials['__to_zip'] ?? '' ),
            'billing_state'    => (string) ( $credentials['__to_state'] ?? '' ),
            'billing_country'  => (string) ( $credentials['__to_country'] ?? 'India' ),
            'billing_email'    => (string) ( $credentials['__to_email'] ?? '' ),
            'billing_phone'    => (string) ( $credentials['__to_phone'] ?? '0000000000' ),
            'shipping_is_billing' => true,
            'order_items'      => array(
                array(
                    'name'         => 'Goods',
                    'sku'          => 'TEJ-' . substr( hash( 'sha256', (string) ( $credentials['__order_ref'] ?? time() ) ), 0, 12 ),
                    'units'        => 1,
                    'selling_price' => (string) ( $credentials['__order_total'] ?? '0' ),
                ),
            ),
            'payment_method'   => 'Prepaid',
            'sub_total'        => (float) ( $credentials['__order_total'] ?? 0 ),
            'length'           => (float) ( $credentials['__length_cm'] ?? 10 ),
            'breadth'          => (float) ( $credentials['__width_cm'] ?? 10 ),
            'height'           => (float) ( $credentials['__height_cm'] ?? 10 ),
            'weight'           => (float) ( $credentials['__weight_kg'] ?? 1 ),
        );

        $order_resp = $this->http->request( 'POST', self::ORDER_URL, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( $order_payload ),
        ) );
        if ( $order_resp['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Shiprocket: create order failed (HTTP %s).', esc_html( (string) $order_resp['status'] ) ) );
        }
        $order = json_decode( $order_resp['body'], true );
        $shipment_id = (int) ( $order['shipment_id'] ?? 0 );
        if ( 0 === $shipment_id ) {
            throw new Carrier_Exception( 'Shiprocket: order response missing shipment_id.' );
        }

        // Step 2: assign AWB
        $assign_resp = $this->http->request( 'POST', self::ASSIGN_URL, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( array(
                'shipment_id' => $shipment_id,
                'courier_id'  => (int) $rate_id,
            ) ),
        ) );
        if ( $assign_resp['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Shiprocket: assign AWB failed (HTTP %s).', esc_html( (string) $assign_resp['status'] ) ) );
        }
        $assigned = json_decode( $assign_resp['body'], true );
        $awb      = (string) ( $assigned['response']['data']['awb_code'] ?? '' );
        $cost_str = (string) ( $assigned['response']['data']['freight_charges'] ?? '0' );
        if ( '' === $awb ) {
            throw new Carrier_Exception( 'Shiprocket: assign response missing awb_code.' );
        }

        // Step 3: generate label
        $label_resp = $this->http->request( 'POST', self::LABEL_URL, array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ),
            'body' => wp_json_encode( array( 'shipment_id' => array( $shipment_id ) ) ),
        ) );
        if ( $label_resp['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Shiprocket: generate label failed (HTTP %s).', esc_html( (string) $label_resp['status'] ) ) );
        }
        $lbl       = json_decode( $label_resp['body'], true );
        $label_url = (string) ( $lbl['label_url'] ?? '' );
        if ( '' === $label_url ) {
            throw new Carrier_Exception( 'Shiprocket: label response missing label_url.' );
        }

        return new Label(
            carrier_id: $this->id(),
            tracking_number: $awb,
            label_url: $label_url,
            label_format: 'PDF',
            cost_cents: \TejCart\Money\Currency::to_minor_units( $cost_str, 'INR' ),
            currency: 'INR',
            meta: array( 'shipment_id' => (string) $shipment_id, 'order_id' => (string) ( $order['order_id'] ?? '' ) )
        );
    }

    /**
     * @param array<string,string> $credentials
     */
    public function track( string $tracking_number, array $credentials ): Tracking {
        if ( '' === $tracking_number ) {
            throw new Carrier_Exception( 'Shiprocket: tracking_number (AWB) required.' );
        }
        $token = $this->login_token( $credentials );

        $response = $this->http->request( 'GET', self::TRACK_URL . rawurlencode( $tracking_number ), array(
            'driver_id' => $this->id(),
            'headers'   => array(
                'Authorization' => 'Bearer ' . $token,
                'Accept'        => 'application/json',
            ),
        ) );

        if ( $response['status'] >= 400 ) {
            throw new Carrier_Exception( sprintf( 'Shiprocket: track failed (HTTP %s).', esc_html( (string) $response['status'] ) ) );
        }

        return $this->parse_tracking_response( $tracking_number, $response['body'] );
    }

    public function parse_tracking_response( string $tracking_number, string $body ): Tracking {
        $decoded = json_decode( $body, true );
        if ( ! is_array( $decoded ) ) {
            throw new Carrier_Exception( 'Shiprocket: malformed tracking response.' );
        }

        $data        = $decoded['tracking_data'] ?? array();
        $shipment    = $data['shipment_track'][0] ?? array();
        $current     = (string) ( $shipment['current_status'] ?? '' );
        $status      = $this->map_status( $current );
        $events      = array();
        $activities  = $data['shipment_track_activities'] ?? array();

        if ( is_array( $activities ) ) {
            foreach ( $activities as $event ) {
                if ( ! is_array( $event ) ) {
                    continue;
                }
                $events[] = array(
                    'timestamp'   => isset( $event['date'] ) ? (int) strtotime( (string) $event['date'] ) : 0,
                    'status'      => $this->map_status( (string) ( $event['status'] ?? '' ) ),
                    'description' => (string) ( $event['activity'] ?? '' ),
                    'location'    => (string) ( $event['location'] ?? '' ),
                );
            }
        }

        return new Tracking( $this->id(), $tracking_number, $status, $events );
    }

    private function map_status( string $raw ): string {
        $u = strtoupper( $raw );
        return match ( true ) {
            str_contains( $u, 'DELIVERED' )       => Tracking::STATUS_DELIVERED,
            str_contains( $u, 'OUT FOR DELIVERY' ) => Tracking::STATUS_OUT_FOR_DELIVERY,
            str_contains( $u, 'IN TRANSIT' ),
            str_contains( $u, 'PICKED UP' ),
            str_contains( $u, 'SHIPPED' )         => Tracking::STATUS_IN_TRANSIT,
            str_contains( $u, 'AWB' ),
            str_contains( $u, 'PROCESSED' ),
            str_contains( $u, 'PICKUP SCHEDULED' ) => Tracking::STATUS_PRE_TRANSIT,
            str_contains( $u, 'RTO' ),
            str_contains( $u, 'RETURN' )          => Tracking::STATUS_RETURNED,
            str_contains( $u, 'EXCEPTION' ),
            str_contains( $u, 'UNDELIVERED' ),
            str_contains( $u, 'LOST' )            => Tracking::STATUS_EXCEPTION,
            default                               => Tracking::STATUS_UNKNOWN,
        };
    }
}
