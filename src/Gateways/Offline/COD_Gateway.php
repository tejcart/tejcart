<?php
/**
 * Cash on Delivery payment gateway.
 *
 * @package TejCart\Gateways\Offline
 */

declare( strict_types=1 );

namespace TejCart\Gateways\Offline;

use TejCart\Gateways\Abstract_Gateway;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Lets the customer pay with cash when their order is delivered.
 *
 * The order is created in the "on-hold" status (or processing for
 * digital-only orders) and is marked complete manually by the
 * shop manager once cash has been collected.
 *
 * Risk gates (#1208)
 * ==================
 *  - `max_order_total`     — refuse carts above this amount (0 = no cap).
 *  - `allowed_countries`   — pipe-delimited ISO codes, empty = any.
 *  - `refuse_virtual_only` — refuse carts containing only virtual items.
 *  - `refuse_downloadable` — refuse carts containing any downloadable item.
 *
 * Every gate is filterable via `tejcart_cod_is_available` for sites that
 * want bespoke logic.
 */
class COD_Gateway extends Abstract_Gateway {
    /**
     * Constructor.
     */
    public function __construct() {
        $this->id          = 'cod';
        $this->title       = __( 'Cash on Delivery', 'tejcart' );
        $this->description = __( 'Pay with cash upon delivery.', 'tejcart' );
        $this->supports    = array( 'products' );

        parent::__construct();
    }

    /**
     * Define admin settings fields.
     */
    public function init_form_fields(): void {
        $this->form_fields = array(
            'enabled'             => array(
                'type'        => 'checkbox',
                'title'       => __( 'Enable/Disable', 'tejcart' ),
                'description' => __( 'Enable Cash on Delivery as a payment method.', 'tejcart' ),
                'default'     => 'no',
            ),
            'title'               => array(
                'type'        => 'text',
                'title'       => __( 'Title', 'tejcart' ),
                'description' => __( 'The title displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Cash on Delivery', 'tejcart' ),
            ),
            'description'         => array(
                'type'        => 'textarea',
                'title'       => __( 'Description', 'tejcart' ),
                'description' => __( 'The description displayed to customers during checkout.', 'tejcart' ),
                'default'     => __( 'Pay with cash upon delivery.', 'tejcart' ),
            ),
            'max_order_total'     => array(
                'type'        => 'text',
                'title'       => __( 'Maximum order total', 'tejcart' ),
                'description' => __( 'Refuse COD for carts above this amount (in store currency). Set 0 for no cap.', 'tejcart' ),
                'default'     => '0',
            ),
            'allowed_countries'   => array(
                'type'        => 'text',
                'title'       => __( 'Allowed countries', 'tejcart' ),
                'description' => __( 'Pipe-delimited ISO 3166-1 alpha-2 country codes (e.g. "US|CA|MX"). Leave blank to allow any country.', 'tejcart' ),
                'default'     => '',
            ),
            'refuse_virtual_only' => array(
                'type'        => 'checkbox',
                'title'       => __( 'Refuse virtual-only carts', 'tejcart' ),
                'description' => __( 'Refuse COD when every item in the cart is virtual (no physical delivery to take cash on).', 'tejcart' ),
                'default'     => 'yes',
            ),
            'refuse_downloadable' => array(
                'type'        => 'checkbox',
                'title'       => __( 'Refuse downloadable carts', 'tejcart' ),
                'description' => __( 'Refuse COD when the cart contains any downloadable item.', 'tejcart' ),
                'default'     => 'yes',
            ),
        );
    }

    /**
     * Check whether COD is available for the current cart.
     *
     * Applies the four risk gates in order. The first failing gate
     * disables the method for this request; downstream gates short-
     * circuit. The final outcome runs through `tejcart_cod_is_available`
     * so sites can layer custom logic.
     */
    public function is_available(): bool {
        if ( ! parent::is_available() ) {
            return false;
        }

        $available = $this->passes_risk_gates();

        /**
         * Filter the final COD availability decision.
         *
         * @since 1.0.2
         *
         * @param bool        $available True if COD should be offered.
         * @param COD_Gateway $gateway   This gateway instance.
         */
        return (bool) apply_filters( 'tejcart_cod_is_available', $available, $this );
    }

    /**
     * Apply the configured risk gates to the current cart. Returns
     * false on the first gate that fails so the caller short-circuits.
     */
    private function passes_risk_gates(): bool {
        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( ! is_object( $cart ) ) {
            // No cart context (admin order create, REST probe). Accept
            // so the gateway still appears in admin gateway lists.
            return true;
        }

        // Gate 1: max order total.
        $cap = (float) $this->get_option( 'max_order_total', '0' );
        if ( $cap > 0 && method_exists( $cart, 'get_total' ) ) {
            $total = (float) $cart->get_total();
            if ( $total > $cap ) {
                return false;
            }
        }

        // Gate 2: allowed countries (shipping country preferred, falls
        // back to billing).
        $allowed = trim( (string) $this->get_option( 'allowed_countries', '' ) );
        if ( '' !== $allowed ) {
            $country = '';
            if ( method_exists( $cart, 'get_shipping_destination' ) ) {
                $destination = $cart->get_shipping_destination();
                $country     = (string) ( $destination['country'] ?? '' );
            }
            if ( '' === $country && function_exists( 'tejcart_get_customer_billing_country' ) ) {
                $country = (string) tejcart_get_customer_billing_country();
            }
            if ( '' !== $country ) {
                $codes = array_map( 'trim', explode( '|', strtoupper( $allowed ) ) );
                if ( ! in_array( strtoupper( $country ), $codes, true ) ) {
                    return false;
                }
            }
        }

        // Gates 3 + 4: cart-item composition.
        $refuse_virtual      = 'yes' === (string) $this->get_option( 'refuse_virtual_only', 'yes' );
        $refuse_downloadable = 'yes' === (string) $this->get_option( 'refuse_downloadable', 'yes' );

        if ( ( $refuse_virtual || $refuse_downloadable ) && method_exists( $cart, 'get_items' ) ) {
            $items                = $cart->get_items();
            $has_physical         = false;
            $has_downloadable     = false;
            foreach ( $items as $item ) {
                if ( ! is_object( $item ) ) {
                    continue;
                }
                $product = method_exists( $item, 'get_product' ) ? $item->get_product() : null;
                if ( ! is_object( $product ) ) {
                    continue;
                }
                if ( method_exists( $product, 'is_virtual' ) && ! $product->is_virtual() ) {
                    $has_physical = true;
                }
                if ( method_exists( $product, 'is_downloadable' ) && $product->is_downloadable() ) {
                    $has_downloadable = true;
                }
            }

            if ( $refuse_virtual && ! empty( $items ) && ! $has_physical ) {
                return false;
            }
            if ( $refuse_downloadable && $has_downloadable ) {
                return false;
            }
        }

        return true;
    }

    /**
     * Process payment by simply marking the order as on-hold.
     *
     * @param int $order_id Order ID.
     * @return array
     */
    public function process_payment( int $order_id ): array {
        $order = new \TejCart\Order\Order( $order_id );

        if ( ! $order->get_id() ) {
            return array(
                'result'   => 'failure',
                'redirect' => '',
                'message'  => __( 'Order not found.', 'tejcart' ),
            );
        }

        // Order_Cart_Cleanup listens on tejcart_order_status_on-hold
        // and empties the cart centrally. Inline empty_cart() removed.
        $order->update_status( 'on-hold', __( 'Awaiting cash on delivery payment.', 'tejcart' ) );

        return array(
            'result'   => 'success',
            'redirect' => $this->get_return_url( $order ),
        );
    }

    /**
     * Build the thank-you URL for an order.
     *
     * @param \TejCart\Order\Order $order Order instance.
     * @return string
     */
    private function get_return_url( $order ): string {
        $page_id = (int) get_option( 'tejcart_thankyou_page_id', 0 );
        $url     = $page_id ? get_permalink( $page_id ) : home_url( '/' );

        return add_query_arg(
            array(
                'order_id'  => $order->get_id(),
                'order_key' => method_exists( $order, 'get_order_key' ) ? $order->get_order_key() : '',
            ),
            $url
        );
    }
}
