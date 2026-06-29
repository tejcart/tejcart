<?php
/**
 * Mailchimp (Marketing API + E-commerce) driver.
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
 * Sync orders and customers into a Mailchimp Marketing audience using
 * the e-commerce store endpoints.
 *
 * Mailchimp's e-commerce model is bottom-up:
 *   1. A `store` represents the connected shop (one per audience).
 *   2. `customers` and `products` live under the store.
 *   3. `orders` reference both — and unlock the Mailchimp e-commerce
 *      automations (Abandoned Cart, Product Retargeting, Order
 *      Notifications) and the segment builder's purchase filters.
 *
 * This driver auto-creates the store on first use, upserts the
 * customer + product rows that an order references, then PUTs the
 * order itself. Refunds re-PUT the order with `financial_status =
 * refunded`.
 *
 * Configuration:
 *   - api_key:    Mailchimp API key in the form `xxxx-usN`. Stored encrypted.
 *   - audience_id: Mailchimp List/Audience ID (10-char hex).
 *   - store_id:   Optional. Defaults to "tejcart-{site-host}".
 *   - subscribe_status: pending | subscribed (controls double opt-in for
 *                 customer.created sync).
 */
class Mailchimp_Driver extends Abstract_Analytics_Driver {
    public static function credential_keys(): array {
        return array(
            array( 'id' => 'enabled',          'label' => __( 'Enabled', 'tejcart' ), 'type' => 'checkbox', 'required' => false,
                   'description' => __( 'Sync orders and customers into a Mailchimp audience.', 'tejcart' ) ),
            array( 'id' => 'api_key',          'label' => __( 'API key', 'tejcart' ), 'type' => 'password', 'required' => true,
                   'description' => __( 'Mailchimp API key (xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-usNN). Stored encrypted.', 'tejcart' ) ),
            array( 'id' => 'audience_id',      'label' => __( 'Audience ID', 'tejcart' ), 'type' => 'text', 'required' => true,
                   'description' => __( 'Mailchimp Audience (List) ID.', 'tejcart' ) ),
            array( 'id' => 'store_id',         'label' => __( 'Store ID', 'tejcart' ), 'type' => 'text', 'required' => false,
                   'description' => __( 'Optional. Defaults to tejcart-{site host} on first sync.', 'tejcart' ) ),
            array( 'id' => 'subscribe_status', 'label' => __( 'Default subscribe status', 'tejcart' ), 'type' => 'select',
                   'options' => array( 'pending' => __( 'Pending (double opt-in)', 'tejcart' ), 'subscribed' => __( 'Subscribed', 'tejcart' ) ),
                   'description' => __( 'Status applied when a new customer is synced.', 'tejcart' ), 'required' => false ),
        );
    }

    public const SECRET_KEYS = array( 'api_key' );

    public function __construct() {
        $this->id         = 'mailchimp';
        $this->title      = 'Mailchimp';
        $this->option_key = 'tejcart_analytics_mailchimp';
    }

    public function send_purchase( array $payload ): bool {
        return $this->upsert_order( $payload, 'paid' );
    }

    public function send_refund( array $payload ): bool {
        return $this->upsert_order( $payload, 'refunded' );
    }

    public function send_customer_created( array $payload ): bool {
        $email = (string) ( $payload['email'] ?? '' );
        if ( '' === $email ) {
            return false;
        }
        return $this->upsert_audience_member(
            $email,
            (string) ( $payload['first_name'] ?? '' ),
            (string) ( $payload['last_name'] ?? '' )
        );
    }

    /**
     * Resolve the Mailchimp datacenter prefix from the API key.
     * Mailchimp keys are formatted `secret-usN`; the dc tag picks the
     * regional API host. Returns null when malformed.
     */
    private function datacenter( string $api_key ): ?string {
        if ( ! str_contains( $api_key, '-' ) ) {
            return null;
        }
        $dc = substr( strrchr( $api_key, '-' ), 1 );
        return $dc ?: null;
    }

    /**
     * Build the API base URL.
     */
    private function base_url( string $api_key ): ?string {
        $dc = $this->datacenter( $api_key );
        if ( null === $dc ) {
            return null;
        }
        return sprintf( 'https://%s.api.mailchimp.com/3.0', $dc );
    }

    /**
     * Default headers for every call.
     */
    private function headers( string $api_key ): array {
        return array(
            'Authorization' => 'Basic ' . base64_encode( 'anystring:' . $api_key ),
            'Content-Type'  => 'application/json',
            'Accept'        => 'application/json',
        );
    }

    /**
     * Resolve (or auto-provision) the Mailchimp store linked to this
     * TejCart install. Returns the store_id on success, null on failure.
     */
    private function ensure_store( string $api_key ): ?string {
        $configured = (string) $this->get_setting( 'store_id', '' );
        if ( '' !== $configured ) {
            return $configured;
        }

        $cached = (string) get_option( 'tejcart_mailchimp_store_id', '' );
        if ( '' !== $cached ) {
            return $cached;
        }

        $base = $this->base_url( $api_key );
        if ( null === $base ) {
            return null;
        }

        $host     = wp_parse_url( get_site_url(), PHP_URL_HOST );
        $store_id = 'tejcart-' . preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( (string) $host ) );

        $audience_id = (string) $this->get_setting( 'audience_id', '' );
        if ( '' === $audience_id ) {
            return null;
        }

        $body = array(
            'id'              => $store_id,
            'list_id'         => $audience_id,
            'name'            => get_bloginfo( 'name' ) ?: 'TejCart Store',
            'currency_code'   => function_exists( 'tejcart_get_currency' )
                ? (string) tejcart_get_currency()
                : (string) get_option( 'tejcart_currency', 'USD' ),
            'platform'        => 'TejCart',
            'domain'          => get_site_url(),
            'email_address'   => get_option( 'admin_email' ),
        );

        $response = $this->remote_post(
            $base . '/ecommerce/stores',
            array(
                'timeout' => $this->http_timeout(),
                'headers' => $this->headers( $api_key ),
                'body'    => wp_json_encode( $body ),
            ),
            'ensure_store'
        );

        if ( ! is_wp_error( $response ) ) {
            $code = (int) wp_remote_retrieve_response_code( $response );
            // 200 created, 400 with title "Resource Exists" when the
            // store already exists from a prior sync — both are fine.
            if ( ( $code >= 200 && $code < 300 ) || 400 === $code ) {
                update_option( 'tejcart_mailchimp_store_id', $store_id, false );
                return $store_id;
            }
        }

        $this->check_response( $response, 'ensure_store' );
        return null;
    }

    /**
     * Upsert one customer onto the store.
     */
    private function upsert_customer( string $api_key, string $base, string $store_id, array $payload ): ?string {
        $email = (string) ( $payload['customer_email'] ?? '' );
        if ( '' === $email ) {
            return null;
        }

        $billing = is_array( $payload['billing_address'] ?? null ) ? $payload['billing_address'] : array();

        $customer_id = (string) ( $payload['customer_id'] ?? 0 );
        if ( '0' === $customer_id || '' === $customer_id ) {
            // Mailchimp requires a stable customer ID; for guest checkouts
            // we use a hash of the email so subsequent orders from the
            // same shopper roll up to a single Mailchimp customer.
            $customer_id = 'guest_' . substr( hash( 'sha256', strtolower( trim( $email ) ) ), 0, 24 );
        }

        // Audit 07 F-19 — honour the buyer's marketing-consent flag
        // when present on the payload. Fall back to the driver's
        // subscribe_status setting so a merchant that genuinely has
        // double-opt-in confidence can flip the default. Final fallback
        // remains `false` (the legacy behaviour).
        if ( array_key_exists( 'marketing_consent', $payload ) ) {
            $opt_in_status = (bool) $payload['marketing_consent'];
        } else {
            $subscribe_default = strtolower( (string) $this->get_setting( 'subscribe_status', '' ) );
            $opt_in_status     = in_array( $subscribe_default, array( 'subscribed', 'yes', 'true', '1' ), true );
        }

        $body = array(
            'id'             => $customer_id,
            'email_address'  => $email,
            'opt_in_status'  => $opt_in_status,
            'first_name'     => (string) ( $billing['first_name'] ?? '' ),
            'last_name'      => (string) ( $billing['last_name'] ?? '' ),
        );

        $response = $this->remote_request(
            $base . '/ecommerce/stores/' . rawurlencode( $store_id ) . '/customers/' . rawurlencode( $customer_id ),
            array(
                'method'  => 'PUT',
                'timeout' => $this->http_timeout(),
                'headers' => $this->headers( $api_key ),
                'body'    => wp_json_encode( $body ),
            ),
            'upsert_customer'
        );

        if ( ! $this->check_response( $response, 'upsert_customer' ) ) {
            return null;
        }
        return $customer_id;
    }

    /**
     * Ensure each ordered product exists on the store. Mailchimp rejects
     * order writes that reference unknown product IDs.
     */
    private function ensure_products( string $api_key, string $base, string $store_id, array $items ): void {
        foreach ( $items as $item ) {
            $product_id = (string) ( $item['product_id'] ?? '' );
            if ( '' === $product_id ) {
                continue;
            }

            $body = array(
                'id'       => $product_id,
                'title'    => (string) ( $item['name'] ?? 'Product ' . $product_id ),
                'variants' => array(
                    array(
                        'id'    => (string) ( $item['sku'] ?? $product_id ),
                        'title' => (string) ( $item['name'] ?? '' ),
                        'sku'   => (string) ( $item['sku'] ?? '' ),
                        'price' => (float) ( $item['price'] ?? 0.0 ),
                    ),
                ),
            );

            $response = $this->remote_post(
                $base . '/ecommerce/stores/' . rawurlencode( $store_id ) . '/products',
                array(
                    'timeout' => $this->http_timeout(),
                    'headers' => $this->headers( $api_key ),
                    'body'    => wp_json_encode( $body ),
                ),
                'ensure_products'
            );

            // 400 "Resource Exists" is fine — every other failure is logged
            // but not fatal; an order that references one missing product
            // will fail loudly downstream.
            if ( is_wp_error( $response ) ) {
                $this->log_failure( 'ensure_products', $response->get_error_message() );
                continue;
            }
            $code = (int) wp_remote_retrieve_response_code( $response );
            if ( ( $code < 200 || $code >= 300 ) && 400 !== $code ) {
                $this->log_failure( 'ensure_products', 'HTTP ' . $code );
            }
        }
    }

    /**
     * Upsert the order itself. `financial_status` of 'paid' is used for
     * a fresh purchase; 'refunded' for the refund event.
     */
    private function upsert_order( array $payload, string $financial_status ): bool {
        $api_key = (string) $this->get_setting( 'api_key', '' );
        if ( '' === $api_key ) {
            return false;
        }

        $base = $this->base_url( $api_key );
        if ( null === $base ) {
            return false;
        }

        $store_id = $this->ensure_store( $api_key );
        if ( null === $store_id ) {
            return false;
        }

        $customer_id = $this->upsert_customer( $api_key, $base, $store_id, $payload );
        if ( null === $customer_id ) {
            return false;
        }

        $items = (array) ( $payload['items'] ?? array() );
        $this->ensure_products( $api_key, $base, $store_id, $items );

        $lines = array();
        foreach ( $items as $i => $item ) {
            $lines[] = array(
                'id'                  => (string) ( $i + 1 ),
                'product_id'          => (string) ( $item['product_id'] ?? '' ),
                'product_variant_id'  => (string) ( $item['sku'] ?? $item['product_id'] ?? '' ),
                'quantity'            => (int) ( $item['quantity'] ?? 1 ),
                'price'               => (float) ( $item['price'] ?? 0.0 ),
            );
        }

        $order_id = (string) ( $payload['order_id'] ?? '' );
        if ( '' === $order_id ) {
            return false;
        }

        $billing = is_array( $payload['billing_address'] ?? null ) ? $payload['billing_address'] : array();

        $order = array(
            'id'                  => $order_id,
            'customer'            => array( 'id' => $customer_id ),
            'currency_code'       => (string) ( $payload['currency'] ?? 'USD' ),
            'order_total'         => (float) ( $payload['total'] ?? 0.0 ),
            'tax_total'           => (float) ( $payload['tax_total'] ?? 0.0 ),
            'shipping_total'      => (float) ( $payload['shipping_total'] ?? 0.0 ),
            'discount_total'      => (float) ( $payload['discount_total'] ?? 0.0 ),
            'financial_status'    => $financial_status,
            'lines'               => $lines,
            'billing_address'     => array_filter(
                array(
                    'name'         => trim( ( $billing['first_name'] ?? '' ) . ' ' . ( $billing['last_name'] ?? '' ) ),
                    'address1'     => (string) ( $billing['address_1'] ?? '' ),
                    'address2'     => (string) ( $billing['address_2'] ?? '' ),
                    'city'         => (string) ( $billing['city'] ?? '' ),
                    'province'     => (string) ( $billing['state'] ?? '' ),
                    'province_code' => (string) ( $billing['state'] ?? '' ),
                    'postal_code'  => (string) ( $billing['postcode'] ?? '' ),
                    'country_code' => (string) ( $billing['country'] ?? '' ),
                    'phone'        => (string) ( $billing['phone'] ?? '' ),
                ),
                static fn ( $value ) => '' !== $value
            ),
        );

        $response = $this->remote_request(
            $base . '/ecommerce/stores/' . rawurlencode( $store_id ) . '/orders/' . rawurlencode( $order_id ),
            array(
                'method'  => 'PUT',
                'timeout' => $this->http_timeout(),
                'headers' => $this->headers( $api_key ),
                'body'    => wp_json_encode( $order ),
            ),
            'upsert_order'
        );

        if ( ! is_wp_error( $response ) ) {
            $code = (int) wp_remote_retrieve_response_code( $response );
            // 404 from a PUT means "no such resource"; Mailchimp wants
            // POST for the first write. Fall through to POST in that case.
            if ( 404 === $code ) {
                $response = $this->remote_post(
                    $base . '/ecommerce/stores/' . rawurlencode( $store_id ) . '/orders',
                    array(
                        'timeout' => $this->http_timeout(),
                        'headers' => $this->headers( $api_key ),
                        'body'    => wp_json_encode( $order ),
                    ),
                    'upsert_order_create'
                );
            }
        }

        return $this->check_response( $response, 'upsert_order' );
    }

    /**
     * Add or update an audience member.
     */
    private function upsert_audience_member( string $email, string $first_name, string $last_name ): bool {
        $api_key = (string) $this->get_setting( 'api_key', '' );
        if ( '' === $api_key ) {
            return false;
        }

        $base = $this->base_url( $api_key );
        if ( null === $base ) {
            return false;
        }

        $audience_id = (string) $this->get_setting( 'audience_id', '' );
        if ( '' === $audience_id ) {
            return false;
        }

        $status = (string) $this->get_setting( 'subscribe_status', 'pending' );
        if ( ! in_array( $status, array( 'pending', 'subscribed' ), true ) ) {
            $status = 'pending';
        }

        $hash = md5( strtolower( trim( $email ) ) );
        $body = array(
            'email_address' => $email,
            'status_if_new' => $status,
            'merge_fields'  => array_filter(
                array(
                    'FNAME' => $first_name,
                    'LNAME' => $last_name,
                ),
                static fn ( $value ) => '' !== $value
            ),
        );

        $response = $this->remote_request(
            $base . '/lists/' . rawurlencode( $audience_id ) . '/members/' . $hash,
            array(
                'method'  => 'PUT',
                'timeout' => $this->http_timeout(),
                'headers' => $this->headers( $api_key ),
                'body'    => wp_json_encode( $body ),
            ),
            'upsert_audience_member'
        );

        return $this->check_response( $response, 'upsert_audience_member' );
    }
}
