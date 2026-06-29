<?php
/**
 * Google Analytics 4 Measurement Protocol driver.
 *
 * @package TejCart\Analytics\Drivers
 */

declare(strict_types=1);

namespace TejCart\Analytics\Drivers;

use TejCart\Analytics\Abstract_Analytics_Driver;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Server-side GA4 driver using the Measurement Protocol.
 *
 * Why server-side: client-side gtag is increasingly throttled by ad
 * blockers and Safari ITP, so any merchant running paid acquisition
 * needs a parallel server-side stream to keep the conversion graph
 * complete. This driver fires `purchase` and `refund` events with the
 * full e-commerce schema GA4 expects (currency, value, items[]).
 *
 * Configuration:
 *   - measurement_id: G-XXXXXXXX. Visible in the GA4 admin.
 *   - api_secret:     Created in Admin → Data Streams → Web → Measurement Protocol.
 *   - debug_mode:     Routes to /debug/mp/collect when enabled — useful for
 *                     verifying payload shape without polluting reports.
 *
 * GA4 requires a `client_id` per event. We use the site URL hash so the
 * stream is stable per store; sites that prefer to forward a real
 * web-client ID can filter `tejcart_ga4_client_id` to inject the value
 * captured client-side and persisted on the order.
 */
class GA4_Driver extends Abstract_Analytics_Driver {
    public static function credential_keys(): array {
        return array(
            array( 'id' => 'enabled',        'label' => __( 'Enabled', 'tejcart' ),        'type' => 'checkbox', 'required' => false,
                   'description' => __( 'Send purchase/refund events to GA4 via the Measurement Protocol.', 'tejcart' ) ),
            array( 'id' => 'measurement_id', 'label' => __( 'Measurement ID', 'tejcart' ), 'type' => 'text',     'required' => true,
                   'description' => __( 'GA4 stream measurement ID (e.g. G-XXXXXXX).', 'tejcart' ) ),
            array( 'id' => 'api_secret',     'label' => __( 'API secret', 'tejcart' ),     'type' => 'password', 'required' => true,
                   'description' => __( 'Generated in GA4 → Admin → Data Streams → Measurement Protocol API secrets. Stored encrypted.', 'tejcart' ) ),
            array( 'id' => 'debug_mode',     'label' => __( 'Debug mode', 'tejcart' ),     'type' => 'checkbox', 'required' => false,
                   'description' => __( 'Route to /debug/mp/collect for payload validation. Disable in production.', 'tejcart' ) ),
        );
    }

    public const SECRET_KEYS = array( 'api_secret' );

    public function __construct() {
        $this->id         = 'ga4';
        $this->title      = 'Google Analytics 4';
        $this->option_key = 'tejcart_analytics_ga4';
    }

    /**
     * @inheritDoc
     */
    public function send_purchase( array $payload ): bool {
        return $this->send_event( 'purchase', $payload );
    }

    /**
     * @inheritDoc
     */
    public function send_refund( array $payload ): bool {
        return $this->send_event( 'refund', $payload );
    }

    /**
     * Build and POST a Measurement Protocol payload.
     */
    private function send_event( string $event_name, array $payload ): bool {
        $measurement_id = (string) $this->get_setting( 'measurement_id', '' );
        $api_secret     = (string) $this->get_setting( 'api_secret', '' );
        if ( '' === $measurement_id || '' === $api_secret ) {
            return false;
        }

        $items = array();
        foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
            $items[] = array(
                'item_id'    => (string) ( $item['sku'] ?? $item['product_id'] ?? '' ),
                'item_name'  => (string) ( $item['name'] ?? '' ),
                'quantity'   => (int) ( $item['quantity'] ?? 1 ),
                'price'      => (float) ( $item['price'] ?? 0.0 ),
            );
        }

        $event_params = array(
            'currency'             => (string) ( $payload['currency'] ?? 'USD' ),
            'value'                => (float) ( $payload['total'] ?? 0.0 ),
            'transaction_id'       => (string) ( $payload['order_number'] ?? $payload['order_id'] ?? '' ),
            'tax'                  => (float) ( $payload['tax_total'] ?? 0.0 ),
            'shipping'             => (float) ( $payload['shipping_total'] ?? 0.0 ),
            'coupon'               => (string) ( $payload['coupon_code'] ?? '' ),
            'items'                => $items,
            'event_id'             => (string) ( $payload['event_id'] ?? '' ),
            'engagement_time_msec' => 100,
        );

        /**
         * Filter the GA4 client_id used for the event.
         *
         * The default derives a per-customer (or per-order for guests)
         * identifier so GA4 reports show distinct users. Stores that
         * capture the real `_ga` cookie on the order can filter this to
         * forward the actual web-client ID for session-level stitching.
         */
        $client_id = (string) apply_filters(
            'tejcart_ga4_client_id',
            $this->derive_client_id( $payload ),
            $payload
        );

        $body = array(
            'client_id' => $client_id,
            'events'    => array(
                array(
                    'name'   => $event_name,
                    'params' => $event_params,
                ),
            ),
        );

        $email = (string) ( $payload['customer_email'] ?? '' );
        if ( '' !== $email ) {
            // GA4 supports user_id for cross-device stitching when the
            // user has an account; we forward a hashed customer email
            // so reports correlate without leaking PII.
            $body['user_id'] = $this->hash_pii( $email );
        }

        $debug = 'yes' === $this->get_setting( 'debug_mode', 'no' );
        $url   = ( $debug ? 'https://www.google-analytics.com/debug/mp/collect' : 'https://www.google-analytics.com/mp/collect' )
            . '?measurement_id=' . rawurlencode( $measurement_id )
            . '&api_secret=' . rawurlencode( $api_secret );

        $response = $this->remote_post(
            $url,
            array(
                'timeout' => $this->http_timeout(),
                'headers' => array( 'Content-Type' => 'application/json' ),
                'body'    => wp_json_encode( $body ),
            ),
            $event_name
        );

        return $this->check_response( $response, $event_name );
    }

    /**
     * Derive a stable GA4 client_id from the payload. Uses customer_id
     * for logged-in customers, customer_email hash for guests, and falls
     * back to a per-order hash. GA4 client_id format is two integers
     * separated by a dot (e.g. "123456789.1234567890").
     */
    private function derive_client_id( array $payload ): string {
        $customer_id    = (int) ( $payload['customer_id'] ?? 0 );
        $customer_email = (string) ( $payload['customer_email'] ?? '' );
        $order_id       = (int) ( $payload['order_id'] ?? 0 );

        if ( $customer_id > 0 ) {
            $seed = 'customer:' . $customer_id . ':' . get_site_url();
        } elseif ( '' !== $customer_email ) {
            $seed = 'email:' . strtolower( trim( $customer_email ) ) . ':' . get_site_url();
        } elseif ( $order_id > 0 ) {
            $seed = 'order:' . $order_id . ':' . get_site_url();
        } else {
            $seed = 'site:' . get_site_url();
        }

        $hash = hash( 'sha256', $seed );
        $high = (string) abs( (int) hexdec( substr( $hash, 0, 8 ) ) );
        $low  = (string) abs( (int) hexdec( substr( $hash, 8, 8 ) ) );

        return $high . '.' . $low;
    }
}
