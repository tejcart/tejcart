<?php
/**
 * Shippo tracking provider.
 *
 * Maps to Shippo's Tracks API (https://docs.goshippo.com/docs/tracking/).
 * Auth is `Authorization: ShippoToken {key}`. Webhooks are signed with
 * `X-Shippo-Signature` (HMAC-SHA256) when the merchant has configured
 * the secret in their Shippo dashboard.
 *
 * Configure via:
 *   define( 'TEJCART_ORDER_TRACKING_SHIPPO_API_KEY', 'shippo_test_xxx' );
 *
 * @package TejCart\Tier2\Order_Tracking\Providers
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Providers;

use TejCart\Tier2\Order_Tracking\Shipment_Status;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Shippo_Provider implements Tracking_Provider {
    public const SLUG     = 'shippo';
    public const API_BASE = 'https://api.goshippo.com/';

    /** @var array<string, string> TejCart slug → Shippo carrier code. */
    private const CARRIER_MAP = array(
        'usps'           => 'usps',
        'ups'            => 'ups',
        'fedex'          => 'fedex',
        'dhl'            => 'dhl_express',
        'dhl_ecommerce'  => 'dhl_ecommerce',
        'canada_post'    => 'canada_post',
        'australia_post' => 'australia_post',
        'royal_mail'     => 'royal_mail',
    );

    private HTTP_Client $http;

    public function __construct( ?HTTP_Client $http = null ) {
        $this->http = $http ?? new HTTP_Client();
    }

    public function slug(): string  { return self::SLUG; }
    public function label(): string { return 'Shippo'; }

    public function is_configured(): bool {
        return '' !== $this->api_key();
    }

    public function supports( string $carrier ): bool {
        return isset( self::CARRIER_MAP[ $carrier ] );
    }

    public function fetch_status( string $tracking_number, string $carrier ): Provider_Status|\WP_Error {
        if ( ! $this->is_configured() ) {
            return new \WP_Error( 'not_configured', 'Shippo API key not set' );
        }
        if ( ! $this->supports( $carrier ) ) {
            return new \WP_Error( 'unsupported_carrier', 'Shippo does not support carrier: ' . $carrier );
        }

        $url = self::API_BASE . 'tracks/' . rawurlencode( self::CARRIER_MAP[ $carrier ] ) . '/' . rawurlencode( $tracking_number );

        $resp = $this->http->get_json(
            $url,
            array( 'Authorization' => 'ShippoToken ' . $this->api_key() )
        );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        if ( $resp['status'] >= 400 ) {
            return new \WP_Error( 'provider_4xx', sprintf( 'Shippo HTTP %d', $resp['status'] ), array( 'body' => $resp['body'] ) );
        }
        $payload = json_decode( $resp['body'], true );
        if ( ! is_array( $payload ) ) {
            return new \WP_Error( 'invalid_json', 'Shippo returned non-JSON' );
        }
        return $this->normalise( $payload, $tracking_number, $carrier );
    }

    public function verify_webhook( array $headers, string $body ): bool {
        $secret = $this->webhook_secret();
        if ( '' === $secret ) {
            return false;
        }
        $sent = $headers['x-shippo-signature'] ?? '';
        if ( '' === $sent ) {
            return false;
        }
        $expected = hash_hmac( 'sha256', $body, $secret );
        return hash_equals( $expected, $sent );
    }

    public function parse_webhook( array $headers, string $body ): Provider_Status|\WP_Error {
        $payload = json_decode( $body, true );
        if ( ! is_array( $payload ) ) {
            return new \WP_Error( 'invalid_json', 'Shippo webhook body was not JSON' );
        }
        // Shippo sends the track payload at the top level for track webhooks,
        // or under `data` for transaction webhooks. We only care about tracks.
        $tracker = isset( $payload['data'] ) && is_array( $payload['data'] )
            ? $payload['data']
            : $payload;

        $event_id = '';
        foreach ( array( 'event', 'event_id', 'object_id' ) as $candidate ) {
            if ( isset( $payload[ $candidate ] ) && is_string( $payload[ $candidate ] ) && '' !== $payload[ $candidate ] ) {
                $event_id = (string) $payload[ $candidate ];
                break;
            }
        }
        if ( '' === $event_id && isset( $tracker['object_id'] ) && is_string( $tracker['object_id'] ) ) {
            $event_id = (string) $tracker['object_id'] . '|' . (string) ( $tracker['tracking_status']['status'] ?? '' );
        }

        return $this->normalise(
            $tracker,
            (string) ( $tracker['tracking_number'] ?? '' ),
            $this->reverse_carrier( (string) ( $tracker['carrier'] ?? '' ) ),
            '' !== $event_id ? $event_id : null
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function normalise( array $payload, string $tracking_number, string $carrier, ?string $event_id = null ): Provider_Status {
        $status_block = is_array( $payload['tracking_status'] ?? null ) ? $payload['tracking_status'] : array();
        $status       = $this->map_status( (string) ( $status_block['status'] ?? '' ) );

        $events = array();
        $history = is_array( $payload['tracking_history'] ?? null ) ? $payload['tracking_history'] : array();
        foreach ( $history as $h ) {
            if ( ! is_array( $h ) ) { continue; }
            $events[] = array(
                'time'     => isset( $h['status_date'] ) ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $h['status_date'] ) ) : null,
                'status'   => $this->map_status( (string) ( $h['status'] ?? '' ) ),
                'message'  => (string) ( $h['status_details'] ?? '' ),
                'location' => (string) ( $h['location']['city'] ?? '' ),
            );
        }
        // Newest first.
        usort( $events, static fn ( $a, $b ) => strcmp( (string) ( $b['time'] ?? '' ), (string) ( $a['time'] ?? '' ) ) );

        $delivered_at = null;
        if ( Shipment_Status::DELIVERED === $status ) {
            foreach ( $events as $ev ) {
                if ( ( $ev['status'] ?? '' ) === Shipment_Status::DELIVERED && ! empty( $ev['time'] ) ) {
                    $delivered_at = (string) $ev['time'];
                    break;
                }
            }
        }

        $eta = isset( $payload['eta'] ) ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $payload['eta'] ) ) : null;

        return new Provider_Status(
            status:       $status,
            delivered_at: $delivered_at,
            shipped_at:   ! empty( $events ) ? (string) ( end( $events )['time'] ?? '' ) : null,
            eta:          $eta,
            events:       $events,
            raw:          array(
                'tracking_number'       => $tracking_number,
                'carrier'               => $carrier,
                'provider_carrier_code' => (string) ( $payload['carrier'] ?? '' ),
                'tracker'               => $payload,
            ),
            event_id:     $event_id,
        );
    }

    private function map_status( string $shippo_status ): string {
        // Shippo statuses: PRE_TRANSIT, TRANSIT, DELIVERED, RETURNED,
        // FAILURE, UNKNOWN.
        return match ( strtoupper( $shippo_status ) ) {
            'PRE_TRANSIT' => Shipment_Status::LABEL_CREATED,
            'TRANSIT'     => Shipment_Status::IN_TRANSIT,
            'DELIVERED'   => Shipment_Status::DELIVERED,
            'RETURNED'    => Shipment_Status::RETURNED,
            'FAILURE'     => Shipment_Status::EXCEPTION,
            default       => Shipment_Status::PENDING,
        };
    }

    private function reverse_carrier( string $shippo_code ): string {
        foreach ( self::CARRIER_MAP as $slug => $code ) {
            if ( strcasecmp( $code, $shippo_code ) === 0 ) {
                return $slug;
            }
        }
        return '';
    }

    private function api_key(): string {
        if ( defined( 'TEJCART_ORDER_TRACKING_SHIPPO_API_KEY' ) ) {
            return (string) TEJCART_ORDER_TRACKING_SHIPPO_API_KEY;
        }
        return (string) apply_filters( 'tejcart_order_tracking_shippo_api_key', '' );
    }

    private function webhook_secret(): string {
        if ( defined( 'TEJCART_ORDER_TRACKING_SHIPPO_WEBHOOK_SECRET' ) ) {
            return (string) TEJCART_ORDER_TRACKING_SHIPPO_WEBHOOK_SECRET;
        }
        return (string) apply_filters( 'tejcart_order_tracking_shippo_webhook_secret', '' );
    }
}
