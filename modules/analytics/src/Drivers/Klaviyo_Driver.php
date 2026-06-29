<?php
/**
 * Klaviyo (Events API + Profiles API) driver.
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
 * Push commerce events into Klaviyo so merchants can build flows on
 * Placed Order / Refunded Order / Started Checkout.
 *
 * Why Klaviyo: it's the most-deployed retention ESP for DTC commerce.
 * Klaviyo's flows (Welcome, Browse Abandonment, Cart Abandonment, Win
 * Back) drive repeat-purchase rate and LTV — which is exactly the gap
 * the existing Tier-2 abandoned-cart module can't close on its own.
 *
 * Configuration:
 *   - api_key:    Klaviyo Private API key. Stored encrypted.
 *   - list_id:    Optional. When set, every customer is subscribed to
 *                 this list at customer.created time.
 *
 * The driver uses the new Klaviyo APIs (a.klaviyo.com/api) — the
 * legacy /track endpoint is deprecated and was removed for new
 * accounts in 2024.
 */
class Klaviyo_Driver extends Abstract_Analytics_Driver {
    public static function credential_keys(): array {
        return array(
            array( 'id' => 'enabled',   'label' => __( 'Enabled', 'tejcart' ), 'type' => 'checkbox', 'required' => false,
                   'description' => __( 'Sync orders and customers to Klaviyo.', 'tejcart' ) ),
            array( 'id' => 'api_key',   'label' => __( 'Private API key', 'tejcart' ), 'type' => 'password', 'required' => true,
                   'description' => __( 'Klaviyo Private API key (pk_… or pk_live_…). Stored encrypted.', 'tejcart' ) ),
            array( 'id' => 'list_id',   'label' => __( 'Default list ID', 'tejcart' ), 'type' => 'text',  'required' => false,
                   'description' => __( 'Optional. Subscribe new customers to this Klaviyo list.', 'tejcart' ) ),
            array( 'id' => 'subscribe_consent', 'label' => __( 'List subscribe mode', 'tejcart' ), 'type' => 'select',
                   'options' => array(
                       'explicit_only' => __( 'Only with explicit marketing consent (recommended)', 'tejcart' ),
                       'all'           => __( 'All new customers (historical consent)', 'tejcart' ),
                   ),
                   'description' => __( 'Controls when customers are subscribed to the Klaviyo list. "Explicit only" requires a marketing_consent flag from the checkout form.', 'tejcart' ), 'required' => false ),
        );
    }

    public const SECRET_KEYS = array( 'api_key' );

    public function __construct() {
        $this->id         = 'klaviyo';
        $this->title      = 'Klaviyo';
        $this->option_key = 'tejcart_analytics_klaviyo';
    }

    public function send_purchase( array $payload ): bool {
        $ok = $this->track( 'Placed Order', $payload, (float) ( $payload['total'] ?? 0.0 ) );
        if ( $ok ) {
            $this->track_ordered_products( $payload );
        }
        return $ok;
    }

    public function send_refund( array $payload ): bool {
        return $this->track( 'Refunded Order', $payload, (float) ( $payload['total'] ?? 0.0 ) );
    }

    public function send_customer_created( array $payload ): bool {
        $api_key = (string) $this->get_setting( 'api_key', '' );
        $email   = (string) ( $payload['email'] ?? '' );
        if ( '' === $api_key || '' === $email ) {
            return false;
        }

        $profile = array(
            'data' => array(
                'type'       => 'profile',
                'attributes' => array_filter(
                    array(
                        'email'      => $email,
                        'phone_number' => (string) ( $payload['phone'] ?? '' ),
                        'first_name' => (string) ( $payload['first_name'] ?? '' ),
                        'last_name'  => (string) ( $payload['last_name'] ?? '' ),
                    ),
                    static fn ( $value ) => '' !== $value
                ),
            ),
        );

        $response = $this->remote_post(
            'https://a.klaviyo.com/api/profiles/',
            array(
                'timeout' => $this->http_timeout(),
                'headers' => $this->headers( $api_key ),
                'body'    => wp_json_encode( $profile ),
            ),
            'profiles'
        );

        // Klaviyo returns 409 when a profile already exists; treat that as success.
        if ( ! is_wp_error( $response ) ) {
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( 409 === $code ) {
                $this->maybe_subscribe_to_list( $email, $payload );
                return true;
            }
        }

        $ok = $this->check_response( $response, 'profiles' );
        if ( $ok ) {
            $this->maybe_subscribe_to_list( $email, $payload );
        }
        return $ok;
    }

    /**
     * Gate list subscription on marketing consent. In "explicit_only"
     * mode (the default), the customer is only subscribed when the
     * payload carries `marketing_consent => true` from the checkout
     * form. In "all" mode the subscribe fires unconditionally (the
     * merchant takes responsibility for having prior consent).
     */
    private function maybe_subscribe_to_list( string $email, array $payload ): bool {
        $mode = (string) $this->get_setting( 'subscribe_consent', 'explicit_only' );
        if ( 'all' !== $mode && empty( $payload['marketing_consent'] ) ) {
            return true;
        }
        return $this->subscribe_to_list( $email );
    }

    /**
     * Map cart-funnel events to Klaviyo metric names. Only fires for
     * events where we can resolve an email (logged-in users) — Klaviyo's
     * server-side Events API requires a profile identifier.
     */
    public function send_cart_event( string $event, array $payload ): bool {
        $metric_map = array(
            'view_item'      => 'Viewed Product',
            'add_to_cart'    => 'Added to Cart',
            'begin_checkout' => 'Started Checkout',
        );

        $metric_name = $metric_map[ $event ] ?? '';
        if ( '' === $metric_name ) {
            return true;
        }

        $email = $this->resolve_cart_email();
        if ( '' === $email ) {
            return true;
        }

        $api_key = (string) $this->get_setting( 'api_key', '' );
        if ( '' === $api_key ) {
            return false;
        }

        $properties = array(
            'event_id' => (string) ( $payload['event_id'] ?? '' ),
        );
        if ( isset( $payload['product_id'] ) ) {
            $properties['ProductID'] = (string) $payload['product_id'];
        }
        if ( isset( $payload['quantity'] ) ) {
            $properties['Quantity'] = (int) $payload['quantity'];
        }

        $event_body = array(
            'data' => array(
                'type'       => 'event',
                'attributes' => array(
                    'properties' => $properties,
                    'metric'     => array(
                        'data' => array(
                            'type'       => 'metric',
                            'attributes' => array( 'name' => $metric_name ),
                        ),
                    ),
                    'profile'    => array(
                        'data' => array(
                            'type'       => 'profile',
                            'attributes' => array( 'email' => $email ),
                        ),
                    ),
                    'unique_id'  => (string) ( $payload['event_id'] ?? '' ),
                ),
            ),
        );

        $response = $this->remote_post(
            'https://a.klaviyo.com/api/events/',
            array(
                'timeout' => $this->http_timeout(),
                'headers' => $this->headers( $api_key ),
                'body'    => wp_json_encode( $event_body ),
            ),
            $metric_name
        );

        return $this->check_response( $response, $metric_name );
    }

    private function resolve_cart_email(): string {
        if ( ! function_exists( 'get_current_user_id' ) ) {
            return '';
        }
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return '';
        }
        if ( ! function_exists( 'get_userdata' ) ) {
            return '';
        }
        $user = get_userdata( $user_id );
        return ( $user && ! empty( $user->user_email ) ) ? (string) $user->user_email : '';
    }

    /**
     * Fire a generic e-commerce event on the Klaviyo Events API.
     *
     * @param string $metric_name e.g. "Placed Order".
     * @param array  $payload     Normalised dispatcher payload.
     * @param float  $value       Event $value the metric reports.
     */
    private function track( string $metric_name, array $payload, float $value ): bool {
        $api_key = (string) $this->get_setting( 'api_key', '' );
        $email   = (string) ( $payload['customer_email'] ?? '' );
        if ( '' === $api_key || '' === $email ) {
            return false;
        }

        $items = array();
        foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
            $items[] = array(
                'ProductID'   => (string) ( $item['product_id'] ?? '' ),
                'SKU'         => (string) ( $item['sku'] ?? '' ),
                'ProductName' => (string) ( $item['name'] ?? '' ),
                'Quantity'    => (int) ( $item['quantity'] ?? 1 ),
                'ItemPrice'   => (float) ( $item['price'] ?? 0.0 ),
                'RowTotal'    => (float) ( $item['line_total'] ?? 0.0 ),
            );
        }

        $event = array(
            'data' => array(
                'type'       => 'event',
                'attributes' => array(
                    'properties' => array(
                        '$value'      => $value,
                        'OrderId'     => (string) ( $payload['order_id'] ?? '' ),
                        'Categories'  => array(),
                        'ItemNames'   => array_column( $items, 'ProductName' ),
                        'Items'       => $items,
                        'Currency'    => (string) ( $payload['currency'] ?? 'USD' ),
                        'Subtotal'    => (float) ( $payload['subtotal'] ?? 0.0 ),
                        'Tax'         => (float) ( $payload['tax_total'] ?? 0.0 ),
                        'Shipping'    => (float) ( $payload['shipping_total'] ?? 0.0 ),
                        'Discount'    => (float) ( $payload['discount_total'] ?? 0.0 ),
                        'Coupon'      => (string) ( $payload['coupon_code'] ?? '' ),
                    ),
                    'metric'     => array(
                        'data' => array(
                            'type'       => 'metric',
                            'attributes' => array( 'name' => $metric_name ),
                        ),
                    ),
                    'profile'    => array(
                        'data' => array(
                            'type'       => 'profile',
                            'attributes' => array(
                                'email'      => $email,
                                'first_name' => (string) ( $payload['billing_address']['first_name'] ?? '' ),
                                'last_name'  => (string) ( $payload['billing_address']['last_name'] ?? '' ),
                            ),
                        ),
                    ),
                    'unique_id'  => (string) ( $payload['event_id'] ?? '' ),
                ),
            ),
        );

        $response = $this->remote_post(
            'https://a.klaviyo.com/api/events/',
            array(
                'timeout' => $this->http_timeout(),
                'headers' => $this->headers( $api_key ),
                'body'    => wp_json_encode( $event ),
            ),
            $metric_name
        );

        return $this->check_response( $response, $metric_name );
    }

    /**
     * Fire one `Ordered Product` event per line item. Klaviyo requires
     * both `Placed Order` (per-order) and `Ordered Product` (per-item)
     * for product-level segmentation and cross-sell flows.
     */
    private function track_ordered_products( array $payload ): void {
        $api_key  = (string) $this->get_setting( 'api_key', '' );
        $email    = (string) ( $payload['customer_email'] ?? '' );
        $order_id = (string) ( $payload['order_id'] ?? '' );
        if ( '' === $api_key || '' === $email ) {
            return;
        }

        foreach ( (array) ( $payload['items'] ?? array() ) as $item ) {
            $product_id = (string) ( $item['product_id'] ?? '' );
            $row_total  = (float) ( $item['line_total'] ?? ( (float) ( $item['price'] ?? 0.0 ) * (int) ( $item['quantity'] ?? 1 ) ) );

            $event = array(
                'data' => array(
                    'type'       => 'event',
                    'attributes' => array(
                        'properties' => array(
                            '$value'      => $row_total,
                            'ProductID'   => $product_id,
                            'SKU'         => (string) ( $item['sku'] ?? '' ),
                            'ProductName' => (string) ( $item['name'] ?? '' ),
                            'Quantity'    => (int) ( $item['quantity'] ?? 1 ),
                            'ItemPrice'   => (float) ( $item['price'] ?? 0.0 ),
                            'RowTotal'    => $row_total,
                            'OrderId'     => $order_id,
                            'Currency'    => (string) ( $payload['currency'] ?? 'USD' ),
                        ),
                        'metric'     => array(
                            'data' => array(
                                'type'       => 'metric',
                                'attributes' => array( 'name' => 'Ordered Product' ),
                            ),
                        ),
                        'profile'    => array(
                            'data' => array(
                                'type'       => 'profile',
                                'attributes' => array( 'email' => $email ),
                            ),
                        ),
                        'unique_id'  => (string) ( $payload['event_id'] ?? '' ) . ':' . $product_id,
                    ),
                ),
            );

            $response = $this->remote_post(
                'https://a.klaviyo.com/api/events/',
                array(
                    'timeout' => $this->http_timeout(),
                    'headers' => $this->headers( $api_key ),
                    'body'    => wp_json_encode( $event ),
                ),
                'Ordered Product'
            );

            $this->check_response( $response, 'Ordered Product' );
        }
    }

    /**
     * Subscribe an email to the configured default list. No-op when no
     * list is configured. Failures are logged but don't propagate up;
     * a list-subscribe failure should never block the parent profile/
     * event call from succeeding.
     */
    private function subscribe_to_list( string $email ): bool {
        $api_key = (string) $this->get_setting( 'api_key', '' );
        $list_id = (string) $this->get_setting( 'list_id', '' );
        if ( '' === $api_key || '' === $list_id || '' === $email ) {
            return true;
        }

        $body = array(
            'data' => array(
                'type'          => 'profile-subscription-bulk-create-job',
                'attributes'    => array(
                    'profiles' => array(
                        'data' => array(
                            array(
                                'type'       => 'profile',
                                'attributes' => array(
                                    'email'         => $email,
                                    'subscriptions' => array(
                                        'email' => array( 'marketing' => array( 'consent' => 'SUBSCRIBED' ) ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
                'relationships' => array(
                    'list' => array( 'data' => array( 'type' => 'list', 'id' => $list_id ) ),
                ),
            ),
        );

        $response = $this->remote_post(
            'https://a.klaviyo.com/api/profile-subscription-bulk-create-jobs/',
            array(
                'timeout' => $this->http_timeout(),
                'headers' => $this->headers( $api_key ),
                'body'    => wp_json_encode( $body ),
            ),
            'list_subscribe'
        );

        return $this->check_response( $response, 'list_subscribe' );
    }

    /**
     * Standard headers Klaviyo requires for the new APIs.
     */
    private function headers( string $api_key ): array {
        return array(
            'Authorization' => 'Klaviyo-API-Key ' . $api_key,
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
            'revision'      => '2024-10-15',
        );
    }
}
