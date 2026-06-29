<?php
/**
 * Meta (Facebook) Conversions API driver.
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
 * Server-side Meta Pixel events via the Conversions API.
 *
 * iOS 14, Safari ITP, and most ad blockers strip client-side Pixel hits.
 * Without a CAPI stream, paid Meta campaigns lose 30–60 % of their
 * attribution signal. This driver fires the canonical commerce events:
 *
 *   - Purchase  → server-side transaction with full PII match keys
 *     (hashed email, phone, name, city, postcode, country) so Meta's
 *     advanced matching can rebuild lost identity.
 *   - Refund    → CustomEvent("Refund") with negative value, suitable
 *     for use as a custom conversion in the Ads Manager.
 *
 * Configuration:
 *   - pixel_id:      Meta Pixel ID.
 *   - access_token:  Generated in Events Manager → Settings → Conversions API.
 *   - test_event_code: Optional; routes events to the Test Events tab.
 *
 * The driver hashes user data on the way out with the standard
 * lowercase-trim-SHA256 contract. We deliberately do **not** ship the
 * `client_user_agent` / `client_ip_address` fields here because the
 * background worker runs out of the original request scope; merchants
 * who want full advanced matching can capture those at request time
 * and persist them onto the order under a meta key, then filter
 * `tejcart_meta_capi_user_data` to re-inject.
 */
class Meta_CAPI_Driver extends Abstract_Analytics_Driver {
    public static function credential_keys(): array {
        return array(
            array( 'id' => 'enabled',          'label' => __( 'Enabled', 'tejcart' ),          'type' => 'checkbox', 'required' => false,
                   'description' => __( 'Send Purchase and Refund events to Meta via the Conversions API.', 'tejcart' ) ),
            array( 'id' => 'pixel_id',         'label' => __( 'Pixel ID', 'tejcart' ),         'type' => 'text',     'required' => true,
                   'description' => __( 'Meta Pixel ID (15-16 digit numeric).', 'tejcart' ) ),
            array( 'id' => 'access_token',     'label' => __( 'Access token', 'tejcart' ),     'type' => 'password', 'required' => true,
                   'description' => __( 'Conversions API access token. Stored encrypted.', 'tejcart' ) ),
            array( 'id' => 'test_event_code',  'label' => __( 'Test event code', 'tejcart' ),  'type' => 'text',     'required' => false,
                   'description' => __( 'Optional. Route events to the Test Events tab in Events Manager.', 'tejcart' ) ),
            array( 'id' => 'api_version',      'label' => __( 'Graph API version', 'tejcart' ), 'type' => 'text',     'required' => false,
                   'description' => __( 'Defaults to v21.0. Override only when forced to by an upgrade window.', 'tejcart' ) ),
        );
    }

    public const SECRET_KEYS = array( 'access_token' );

    public function __construct() {
        $this->id         = 'meta_capi';
        $this->title      = 'Meta Conversions API';
        $this->option_key = 'tejcart_analytics_meta_capi';
    }

    public function send_purchase( array $payload ): bool {
        return $this->send_event( 'Purchase', $payload, (float) ( $payload['total'] ?? 0.0 ) );
    }

    public function send_refund( array $payload ): bool {
        // CAPI doesn't define "Refund" as a standard event; we fire it as
        // a custom event with a negative value so the merchant can build
        // a Custom Conversion against it in Ads Manager.
        $value = -1.0 * abs( (float) ( $payload['total'] ?? 0.0 ) );
        return $this->send_event( 'Refund', $payload, $value );
    }

    private function send_event( string $event_name, array $payload, float $value ): bool {
        $pixel_id     = (string) $this->get_setting( 'pixel_id', '' );
        $access_token = (string) $this->get_setting( 'access_token', '' );
        if ( '' === $pixel_id || '' === $access_token ) {
            return false;
        }

        $version = (string) $this->get_setting( 'api_version', '' );
        if ( '' === $version || ! preg_match( '/^v\d+\.\d+$/', $version ) ) {
            $version = 'v21.0';
        }

        $billing  = is_array( $payload['billing_address'] ?? null ) ? $payload['billing_address'] : array();
        $email    = (string) ( $payload['customer_email'] ?? '' );
        $phone    = (string) ( $billing['phone'] ?? '' );
        $first    = (string) ( $billing['first_name'] ?? '' );
        $last     = (string) ( $billing['last_name'] ?? '' );

        $fbc = (string) ( $payload['fbc'] ?? '' );
        $fbp = (string) ( $payload['fbp'] ?? '' );
        $ip  = (string) ( $payload['ip_address'] ?? '' );

        $user_data = array_filter(
            array(
                'em' => '' !== $email ? array( $this->hash_pii( $email ) ) : null,
                'ph' => '' !== $phone ? array( $this->hash_pii( preg_replace( '/\D+/', '', $phone ) ) ) : null,
                'fn' => '' !== $first ? array( $this->hash_pii( $first ) ) : null,
                'ln' => '' !== $last  ? array( $this->hash_pii( $last ) )  : null,
                'ct' => isset( $billing['city'] ) && '' !== $billing['city']
                    ? array( $this->hash_pii( (string) $billing['city'] ) )
                    : null,
                'zp' => isset( $billing['postcode'] ) && '' !== $billing['postcode']
                    ? array( $this->hash_pii( (string) $billing['postcode'] ) )
                    : null,
                'country' => isset( $billing['country'] ) && '' !== $billing['country']
                    ? array( $this->hash_pii( (string) $billing['country'] ) )
                    : null,
                'fbc'               => '' !== $fbc ? $fbc : null,
                'fbp'               => '' !== $fbp ? $fbp : null,
                'client_ip_address' => '' !== $ip  ? $ip  : null,
            )
        );

        /**
         * Filter the Meta CAPI user_data block.
         *
         * Stores can inject additional match keys (client_user_agent,
         * external_id) here for richer advanced matching.
         */
        $user_data = (array) apply_filters( 'tejcart_meta_capi_user_data', $user_data, $payload );

        $contents = array();
        foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
            $contents[] = array(
                'id'         => 'tejcart_' . (string) ( $item['product_id'] ?? '' ),
                'quantity'   => (int) ( $item['quantity'] ?? 1 ),
                'item_price' => (float) ( $item['price'] ?? 0.0 ),
            );
        }

        $event = array(
            'event_name'      => $event_name,
            'event_time'      => time(),
            'action_source'   => 'website',
            'event_id'        => (string) ( $payload['event_id'] ?? '' ),
            'event_source_url' => $this->resolve_event_source_url( $event_name ),
            'user_data'       => $user_data,
            'custom_data'     => array(
                'currency'     => (string) ( $payload['currency'] ?? 'USD' ),
                'value'        => $value,
                'order_id'     => (string) ( $payload['order_id'] ?? '' ),
                'content_type' => 'product',
                'content_ids'  => array_column( $contents, 'id' ),
                'contents'     => $contents,
                'num_items'    => count( $contents ),
            ),
        );

        $body = array(
            'data' => array( $event ),
        );

        $test_code = (string) $this->get_setting( 'test_event_code', '' );
        if ( '' !== $test_code ) {
            $body['test_event_code'] = $test_code;
        }

        $url = sprintf( 'https://graph.facebook.com/%s/%s/events?access_token=%s', $version, rawurlencode( $pixel_id ), rawurlencode( $access_token ) );

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
     * Resolve event_source_url per event type. Meta requires the actual
     * page URL where the event occurred; since the driver runs in a
     * background AS job we derive it from core's page settings.
     */
    private function resolve_event_source_url( string $event_name ): string {
        if ( function_exists( 'tejcart_get_page_url' ) ) {
            if ( in_array( $event_name, array( 'Purchase', 'Refund' ), true ) ) {
                $url = (string) tejcart_get_page_url( 'checkout' );
                if ( '' !== $url ) {
                    return $url;
                }
            }
        }
        return (string) get_site_url();
    }
}
