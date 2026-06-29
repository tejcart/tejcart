<?php
/**
 * EasyPost tracking provider.
 *
 * Concrete implementation of `Tracking_Provider` against the EasyPost
 * Trackers API (https://docs.easypost.com/docs/trackers). Chosen as
 * the reference driver because it normalises across USPS, UPS, FedEx,
 * DHL, Canada Post, etc. behind one auth scheme — the perfect template
 * for site owners who want to roll their own driver against a different
 * aggregator (Shippo, AfterShip, 17track) or a direct carrier API.
 *
 * Configuration:
 *   define( 'TEJCART_ORDER_TRACKING_EASYPOST_API_KEY',        'EZTK...' );
 *   define( 'TEJCART_ORDER_TRACKING_EASYPOST_WEBHOOK_SECRET', 'super-secret' );
 *
 * Or via filters:
 *   add_filter( 'tejcart_order_tracking_easypost_api_key',        fn() => '...' );
 *   add_filter( 'tejcart_order_tracking_easypost_webhook_secret', fn() => '...' );
 *
 * @package TejCart\Tier2\Order_Tracking\Providers
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Providers;

use TejCart\Tier2\Order_Tracking\Shipment_Status;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class EasyPost_Provider implements Tracking_Provider {
    public const API_BASE = 'https://api.easypost.com/v2/';
    public const SLUG     = 'easypost';

    /**
     * Carrier slug → EasyPost carrier code. EasyPost is fairly close to
     * our slugs but uses upper-case codes for some.
     *
     * @var array<string, string>
     */
    private const CARRIER_MAP = array(
        'usps'           => 'USPS',
        'ups'            => 'UPS',
        'fedex'          => 'FedEx',
        'dhl'            => 'DHLExpress',
        'dhl_ecommerce'  => 'DHLEcommerceSolutions',
        'canada_post'    => 'CanadaPost',
        'australia_post' => 'AustraliaPost',
        'royal_mail'     => 'RoyalMail',
    );

    private HTTP_Client $http;

    public function __construct( ?HTTP_Client $http = null ) {
        $this->http = $http ?? new HTTP_Client();
    }

    public function slug(): string {
        return self::SLUG;
    }

    public function label(): string {
        return 'EasyPost';
    }

    public function is_configured(): bool {
        return '' !== $this->api_key();
    }

    public function supports( string $carrier ): bool {
        return isset( self::CARRIER_MAP[ $carrier ] );
    }

    public function fetch_status( string $tracking_number, string $carrier ): Provider_Status|\WP_Error {
        if ( ! $this->is_configured() ) {
            return new \WP_Error( 'not_configured', 'EasyPost API key not set' );
        }
        if ( ! $this->supports( $carrier ) ) {
            return new \WP_Error( 'unsupported_carrier', 'EasyPost does not support carrier: ' . $carrier );
        }

        $response = $this->http->post_json(
            self::API_BASE . 'trackers',
            array(
                'tracker' => array(
                    'tracking_code' => $tracking_number,
                    'carrier'       => self::CARRIER_MAP[ $carrier ],
                ),
            ),
            array( 'Authorization' => 'Basic ' . base64_encode( $this->api_key() . ':' ) )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( $response['status'] >= 400 ) {
            return new \WP_Error(
                'provider_4xx',
                sprintf( 'EasyPost returned HTTP %d', $response['status'] ),
                array( 'body' => $response['body'] )
            );
        }

        $payload = json_decode( $response['body'], true );
        if ( ! is_array( $payload ) ) {
            return new \WP_Error( 'invalid_json', 'EasyPost returned non-JSON body' );
        }

        return $this->normalise( $payload, $tracking_number, $carrier );
    }

    public function verify_webhook( array $headers, string $body ): bool {
        $secret = $this->webhook_secret();
        if ( '' === $secret ) {
            // Without a configured secret we cannot verify — fail closed.
            return false;
        }
        $sent = $headers['x-hmac-signature'] ?? ( $headers['x-easypost-signature'] ?? '' );
        if ( '' === $sent ) {
            return false;
        }
        $expected = hash_hmac( 'sha256', $body, $secret );
        return hash_equals( $expected, $sent );
    }

    public function parse_webhook( array $headers, string $body ): Provider_Status|\WP_Error {
        $payload = json_decode( $body, true );
        if ( ! is_array( $payload ) ) {
            return new \WP_Error( 'invalid_json', 'Webhook body was not JSON' );
        }
        // EasyPost wraps the tracker in `result` for its webhooks.
        $tracker = isset( $payload['result'] ) && is_array( $payload['result'] )
            ? $payload['result']
            : $payload;

        // EasyPost webhook envelopes include a top-level `id` (the event
        // id, prefixed `evt_`) plus the tracker's own `id` (prefixed
        // `trk_`). Prefer the event id when present so retries with the
        // same body but different timestamp metadata still hash to the
        // same idempotency key.
        $event_id = '';
        foreach ( array( 'id', 'event_id' ) as $candidate ) {
            if ( isset( $payload[ $candidate ] ) && is_string( $payload[ $candidate ] ) && '' !== $payload[ $candidate ] ) {
                $event_id = (string) $payload[ $candidate ];
                break;
            }
        }
        if ( '' === $event_id && isset( $tracker['id'] ) && is_string( $tracker['id'] ) ) {
            // Combine the tracker id with status so multiple deliveries
            // for the same tracker (each a real status change) don't
            // collide on a single idempotency key.
            $event_id = (string) $tracker['id'] . '|' . (string) ( $tracker['status'] ?? '' );
        }

        $tracking_number = (string) ( $tracker['tracking_code'] ?? '' );
        $carrier_code    = (string) ( $tracker['carrier'] ?? '' );

        $carrier = '';
        foreach ( self::CARRIER_MAP as $slug => $code ) {
            if ( strcasecmp( $code, $carrier_code ) === 0 ) {
                $carrier = $slug;
                break;
            }
        }

        $status   = $this->map_status( (string) ( $tracker['status'] ?? '' ) );
        $events   = $this->map_events( $tracker['tracking_details'] ?? array() );
        $delivered_at = null;
        if ( Shipment_Status::DELIVERED === $status ) {
            // Take the most-recent "delivered" event timestamp when present.
            foreach ( $events as $ev ) {
                if ( ( $ev['status'] ?? '' ) === Shipment_Status::DELIVERED && ! empty( $ev['time'] ) ) {
                    $delivered_at = (string) $ev['time'];
                    break;
                }
            }
        }
        $eta = isset( $tracker['est_delivery_date'] )
            ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $tracker['est_delivery_date'] ) )
            : null;

        return new Provider_Status(
            status:       $status,
            delivered_at: $delivered_at,
            shipped_at:   $this->extract_first_event_time( $events ),
            eta:          $eta,
            events:       $events,
            raw:          array(
                'tracking_number'        => $tracking_number,
                'carrier'                => $carrier,
                'provider_carrier_code'  => $carrier_code,
                'tracker'                => $tracker,
            ),
            event_id:     '' !== $event_id ? $event_id : null,
        );
    }

    /**
     * @param array<string, mixed> $payload  EasyPost tracker response.
     */
    private function normalise( array $payload, string $tracking_number, string $carrier ): Provider_Status {
        $events       = $this->map_events( $payload['tracking_details'] ?? array() );
        $status       = $this->map_status( (string) ( $payload['status'] ?? '' ) );
        $delivered_at = null;
        if ( Shipment_Status::DELIVERED === $status ) {
            foreach ( $events as $ev ) {
                if ( ( $ev['status'] ?? '' ) === Shipment_Status::DELIVERED && ! empty( $ev['time'] ) ) {
                    $delivered_at = (string) $ev['time'];
                    break;
                }
            }
        }

        return new Provider_Status(
            status:       $status,
            delivered_at: $delivered_at,
            shipped_at:   $this->extract_first_event_time( $events ),
            eta:          isset( $payload['est_delivery_date'] )
                ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $payload['est_delivery_date'] ) )
                : null,
            events:       $events,
            raw:          array(
                'tracking_number' => $tracking_number,
                'carrier'         => $carrier,
                'tracker'         => $payload,
            ),
        );
    }

    private function map_status( string $easypost_status ): string {
        // EasyPost canonical statuses:
        // pre_transit, in_transit, out_for_delivery, delivered,
        // available_for_pickup, return_to_sender, failure, cancelled,
        // error, unknown.
        return match ( strtolower( $easypost_status ) ) {
            'pre_transit'           => Shipment_Status::LABEL_CREATED,
            'in_transit'            => Shipment_Status::IN_TRANSIT,
            'out_for_delivery'      => Shipment_Status::OUT_FOR_DELIVERY,
            'available_for_pickup'  => Shipment_Status::OUT_FOR_DELIVERY,
            'delivered'             => Shipment_Status::DELIVERED,
            'return_to_sender'      => Shipment_Status::RETURNED,
            'failure', 'error', 'cancelled' => Shipment_Status::EXCEPTION,
            default                 => Shipment_Status::PENDING,
        };
    }

    /**
     * @param mixed $details
     * @return array<int, array<string, mixed>>
     */
    private function map_events( mixed $details ): array {
        if ( ! is_array( $details ) ) {
            return array();
        }
        $events = array();
        foreach ( $details as $detail ) {
            if ( ! is_array( $detail ) ) {
                continue;
            }
            $time = isset( $detail['datetime'] )
                ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $detail['datetime'] ) )
                : null;
            $events[] = array(
                'time'     => $time,
                'status'   => $this->map_status( (string) ( $detail['status'] ?? '' ) ),
                'message'  => (string) ( $detail['message']      ?? '' ),
                'location' => (string) ( $detail['tracking_location']['city'] ?? '' ),
            );
        }
        // Newest first.
        usort( $events, static fn ( $a, $b ) => strcmp( (string) ( $b['time'] ?? '' ), (string) ( $a['time'] ?? '' ) ) );
        return $events;
    }

    /**
     * @param array<int, array<string, mixed>> $events
     */
    private function extract_first_event_time( array $events ): ?string {
        if ( empty( $events ) ) {
            return null;
        }
        $oldest = end( $events );
        return ! empty( $oldest['time'] ) ? (string) $oldest['time'] : null;
    }

    private function api_key(): string {
        if ( defined( 'TEJCART_ORDER_TRACKING_EASYPOST_API_KEY' ) ) {
            return (string) TEJCART_ORDER_TRACKING_EASYPOST_API_KEY;
        }
        return (string) apply_filters( 'tejcart_order_tracking_easypost_api_key', '' );
    }

    private function webhook_secret(): string {
        if ( defined( 'TEJCART_ORDER_TRACKING_EASYPOST_WEBHOOK_SECRET' ) ) {
            return (string) TEJCART_ORDER_TRACKING_EASYPOST_WEBHOOK_SECRET;
        }
        return (string) apply_filters( 'tejcart_order_tracking_easypost_webhook_secret', '' );
    }
}
