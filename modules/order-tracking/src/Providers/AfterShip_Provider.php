<?php
/**
 * AfterShip tracking provider.
 *
 * Maps to the AfterShip Tracking API
 * (https://www.aftership.com/docs/api/4). Auth is `aftership-api-key`
 * header. Webhooks are signed with `aftership-hmac-sha256` containing
 * a base64-encoded HMAC-SHA256 of the body using the merchant's secret.
 *
 * Configure via:
 *   define( 'TEJCART_ORDER_TRACKING_AFTERSHIP_API_KEY', 'as-...' );
 *
 * @package TejCart\Tier2\Order_Tracking\Providers
 */

declare(strict_types=1);

namespace TejCart\Tier2\Order_Tracking\Providers;

use TejCart\Tier2\Order_Tracking\Shipment_Status;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class AfterShip_Provider implements Tracking_Provider {
    public const SLUG     = 'aftership';
    public const API_BASE = 'https://api.aftership.com/v4/';

    /** @var array<string, string> TejCart slug → AfterShip slug. */
    private const CARRIER_MAP = array(
        'usps'           => 'usps',
        'ups'            => 'ups',
        'fedex'          => 'fedex',
        'dhl'            => 'dhl',
        'dhl_ecommerce'  => 'dhl-ecommerce-us',
        'canada_post'    => 'canada-post',
        'australia_post' => 'australia-post',
        'royal_mail'     => 'royal-mail',
        'aramex'         => 'aramex',
        'sf_express'     => 'sf-express',
        'china_post'     => 'china-post',
    );

    private HTTP_Client $http;

    public function __construct( ?HTTP_Client $http = null ) {
        $this->http = $http ?? new HTTP_Client();
    }

    public function slug(): string  { return self::SLUG; }
    public function label(): string { return 'AfterShip'; }

    public function is_configured(): bool {
        return '' !== $this->api_key();
    }

    public function supports( string $carrier ): bool {
        return isset( self::CARRIER_MAP[ $carrier ] );
    }

    public function fetch_status( string $tracking_number, string $carrier ): Provider_Status|\WP_Error {
        if ( ! $this->is_configured() ) {
            return new \WP_Error( 'not_configured', 'AfterShip API key not set' );
        }
        if ( ! $this->supports( $carrier ) ) {
            return new \WP_Error( 'unsupported_carrier', 'AfterShip does not support carrier: ' . $carrier );
        }

        $url = self::API_BASE . 'trackings/'
            . rawurlencode( self::CARRIER_MAP[ $carrier ] )
            . '/' . rawurlencode( $tracking_number );

        $resp = $this->http->get_json(
            $url,
            array( 'aftership-api-key' => $this->api_key() )
        );

        if ( is_wp_error( $resp ) ) {
            return $resp;
        }
        if ( $resp['status'] >= 400 ) {
            return new \WP_Error( 'provider_4xx', sprintf( 'AfterShip HTTP %d', $resp['status'] ), array( 'body' => $resp['body'] ) );
        }
        $payload = json_decode( $resp['body'], true );
        if ( ! is_array( $payload ) ) {
            return new \WP_Error( 'invalid_json', 'AfterShip returned non-JSON' );
        }
        $tracking_block = is_array( $payload['data']['tracking'] ?? null ) ? $payload['data']['tracking'] : array();
        return $this->normalise( $tracking_block, $tracking_number, $carrier );
    }

    public function verify_webhook( array $headers, string $body ): bool {
        $secret = $this->webhook_secret();
        if ( '' === $secret ) {
            return false;
        }
        $sent = $headers['aftership-hmac-sha256'] ?? '';
        if ( '' === $sent ) {
            return false;
        }
        $expected = base64_encode( hash_hmac( 'sha256', $body, $secret, true ) );
        return hash_equals( $expected, $sent );
    }

    public function parse_webhook( array $headers, string $body ): Provider_Status|\WP_Error {
        $payload = json_decode( $body, true );
        if ( ! is_array( $payload ) ) {
            return new \WP_Error( 'invalid_json', 'AfterShip webhook body was not JSON' );
        }
        $tracking = is_array( $payload['msg'] ?? null ) ? $payload['msg'] : $payload;

        $event_id = '';
        if ( isset( $tracking['id'] ) && is_string( $tracking['id'] ) && '' !== $tracking['id'] ) {
            $event_id = (string) $tracking['id'] . '|' . (string) ( $tracking['tag'] ?? '' );
        }

        return $this->normalise(
            $tracking,
            (string) ( $tracking['tracking_number'] ?? '' ),
            $this->reverse_carrier( (string) ( $tracking['slug'] ?? '' ) ),
            '' !== $event_id ? $event_id : null
        );
    }

    /**
     * @param array<string, mixed> $tracking
     */
    private function normalise( array $tracking, string $tracking_number, string $carrier, ?string $event_id = null ): Provider_Status {
        $status = $this->map_status( (string) ( $tracking['tag'] ?? '' ) );

        $events  = array();
        $history = is_array( $tracking['checkpoints'] ?? null ) ? $tracking['checkpoints'] : array();
        foreach ( $history as $h ) {
            if ( ! is_array( $h ) ) { continue; }
            $events[] = array(
                'time'     => isset( $h['checkpoint_time'] ) ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $h['checkpoint_time'] ) ) : null,
                'status'   => $this->map_status( (string) ( $h['tag'] ?? '' ) ),
                'message'  => (string) ( $h['message'] ?? '' ),
                'location' => (string) ( $h['city'] ?? '' ),
            );
        }
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

        $eta = isset( $tracking['expected_delivery'] )
            ? gmdate( 'Y-m-d H:i:s', (int) strtotime( (string) $tracking['expected_delivery'] ) )
            : null;

        return new Provider_Status(
            status:       $status,
            delivered_at: $delivered_at,
            shipped_at:   ! empty( $events ) ? (string) ( end( $events )['time'] ?? '' ) : null,
            eta:          $eta,
            events:       $events,
            raw:          array(
                'tracking_number'       => $tracking_number,
                'carrier'               => $carrier,
                'provider_carrier_code' => (string) ( $tracking['slug'] ?? '' ),
                'tracker'               => $tracking,
            ),
            event_id:     $event_id,
        );
    }

    private function map_status( string $aftership_tag ): string {
        // AfterShip tag enum: Pending, InfoReceived, InTransit,
        // OutForDelivery, AttemptFail, Delivered, AvailableForPickup,
        // Exception, Expired.
        return match ( strtolower( $aftership_tag ) ) {
            'pending'              => Shipment_Status::PENDING,
            'inforeceived'         => Shipment_Status::LABEL_CREATED,
            'intransit'            => Shipment_Status::IN_TRANSIT,
            'outfordelivery'       => Shipment_Status::OUT_FOR_DELIVERY,
            'availableforpickup'   => Shipment_Status::OUT_FOR_DELIVERY,
            'attemptfail'          => Shipment_Status::EXCEPTION,
            'delivered'            => Shipment_Status::DELIVERED,
            'exception', 'expired' => Shipment_Status::EXCEPTION,
            default                => Shipment_Status::PENDING,
        };
    }

    private function reverse_carrier( string $aftership_slug ): string {
        foreach ( self::CARRIER_MAP as $slug => $code ) {
            if ( strcasecmp( $code, $aftership_slug ) === 0 ) {
                return $slug;
            }
        }
        return '';
    }

    private function api_key(): string {
        if ( defined( 'TEJCART_ORDER_TRACKING_AFTERSHIP_API_KEY' ) ) {
            return (string) TEJCART_ORDER_TRACKING_AFTERSHIP_API_KEY;
        }
        return (string) apply_filters( 'tejcart_order_tracking_aftership_api_key', '' );
    }

    private function webhook_secret(): string {
        if ( defined( 'TEJCART_ORDER_TRACKING_AFTERSHIP_WEBHOOK_SECRET' ) ) {
            return (string) TEJCART_ORDER_TRACKING_AFTERSHIP_WEBHOOK_SECRET;
        }
        return (string) apply_filters( 'tejcart_order_tracking_aftership_webhook_secret', '' );
    }
}
