<?php
/**
 * Cart REST API Controller.
 *
 * @package TejCart\API\Controllers
 */

declare( strict_types=1 );

namespace TejCart\API\Controllers;

use TejCart\Cart\Cart;
use TejCart\Money\Currency;
use TejCart\Money\Money;
use WP_REST_Controller;
use WP_REST_Server;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * REST controller for the cart endpoint.
 */
class Cart_Controller extends WP_REST_Controller {
    /**
     * Route namespace.
     *
     * @var string
     */
    protected $namespace = 'tejcart/v1';

    /**
     * Route base.
     *
     * @var string
     */
    protected $rest_base = 'cart';

    /**
     * Register routes for the cart.
     *
     * @return void
     */
    public function register_routes(): void {
        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base,
            array(
                array(
                    'methods'             => WP_REST_Server::READABLE,
                    'callback'            => array( $this, 'get_cart' ),
                    'permission_callback' => array( $this, 'public_permissions_check' ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'empty_cart' ),
                    'permission_callback' => array( $this, 'public_permissions_check' ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/items',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'add_item' ),
                    'permission_callback' => array( $this, 'public_permissions_check' ),
                    'args'                => array(
                        'product_id' => array(
                            'description' => __( 'Product ID to add to the cart.', 'tejcart' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                        'quantity'   => array(
                            'description' => __( 'Quantity to add.', 'tejcart' ),
                            'type'        => 'integer',
                            'default'     => 1,
                        ),
                        'data'       => array(
                            'description' => __( 'Extra data for the cart item.', 'tejcart' ),
                            'type'        => 'object',
                            'default'     => array(),
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/items/(?P<key>[a-f0-9]{64})',
            array(
                array(
                    'methods'             => WP_REST_Server::EDITABLE,
                    'callback'            => array( $this, 'update_item' ),
                    'permission_callback' => array( $this, 'public_permissions_check' ),
                    'args'                => array(
                        'key'      => array(
                            'description' => __( 'Cart item key.', 'tejcart' ),
                            'type'        => 'string',
                            'required'    => true,
                        ),
                        'quantity' => array(
                            'description' => __( 'New quantity for the item.', 'tejcart' ),
                            'type'        => 'integer',
                            'required'    => true,
                        ),
                    ),
                ),
                array(
                    'methods'             => WP_REST_Server::DELETABLE,
                    'callback'            => array( $this, 'remove_item' ),
                    'permission_callback' => array( $this, 'public_permissions_check' ),
                    'args'                => array(
                        'key' => array(
                            'description' => __( 'Cart item key.', 'tejcart' ),
                            'type'        => 'string',
                            'required'    => true,
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/coupons',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'apply_coupon' ),
                    'permission_callback' => array( $this, 'public_permissions_check' ),
                    'args'                => array(
                        'code' => array(
                            'description' => __( 'Coupon code to apply.', 'tejcart' ),
                            'type'        => 'string',
                            'required'    => true,
                        ),
                    ),
                ),
            )
        );

        register_rest_route(
            $this->namespace,
            '/' . $this->rest_base . '/shipping-method',
            array(
                array(
                    'methods'             => WP_REST_Server::CREATABLE,
                    'callback'            => array( $this, 'set_shipping_method' ),
                    'permission_callback' => array( $this, 'public_permissions_check' ),
                    'args'                => array(
                        'method' => array(
                            'description' => __( 'Shipping method ID to apply to the cart.', 'tejcart' ),
                            'type'        => 'string',
                            'required'    => true,
                        ),
                    ),
                ),
            )
        );
    }

    /**
     * Persist the customer's chosen shipping method on the cart and
     * return the recalculated cart totals.
     *
     * @param \WP_REST_Request $request Request.
     * @return \WP_REST_Response|\WP_Error
     */
    public function set_shipping_method( $request ) {
        $cart = function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : null;
        if ( ! $cart || ! method_exists( $cart, 'set_chosen_shipping_method' ) ) {
            return new \WP_Error( 'cart_unavailable', __( 'Cart is not available.', 'tejcart' ), array( 'status' => 500 ) );
        }

        $method = sanitize_text_field( (string) $request->get_param( 'method' ) );
        if ( '' === $method ) {
            return new \WP_Error( 'invalid_method', __( 'A shipping method is required.', 'tejcart' ), array( 'status' => 400 ) );
        }

        if ( class_exists( '\\TejCart\\Shipping\\Shipping_Manager' ) ) {
            $manager   = new \TejCart\Shipping\Shipping_Manager();
            $country   = '';
            $state     = '';
            $postcode  = '';

            if ( method_exists( $cart, 'get_customer' ) ) {
                $customer = $cart->get_customer();
                if ( is_object( $customer ) ) {
                    if ( method_exists( $customer, 'get_shipping_country' ) ) {
                        $country = (string) $customer->get_shipping_country();
                    }
                    if ( method_exists( $customer, 'get_shipping_state' ) ) {
                        $state = (string) $customer->get_shipping_state();
                    }
                    if ( method_exists( $customer, 'get_shipping_postcode' ) ) {
                        $postcode = (string) $customer->get_shipping_postcode();
                    }
                }
            }

            if ( '' === $country ) {
                $country = (string) get_option( 'tejcart_store_country', 'US' );
            }

            $available = $manager->get_available_methods( $country, $state, $cart, $postcode );
            $matched   = false;
            foreach ( $available as $instance ) {
                if ( method_exists( $instance, 'get_id' ) && $instance->get_id() === $method ) {
                    $matched = true;
                    break;
                }
            }

            if ( ! $matched ) {
                return new \WP_Error(
                    'method_not_available',
                    __( 'The selected shipping method is not available for your address.', 'tejcart' ),
                    array( 'status' => 400 )
                );
            }
        }

        $cart->set_chosen_shipping_method( $method );

        return rest_ensure_response(
            array(
                'success'        => true,
                'method'         => $method,
                'shipping_total' => method_exists( $cart, 'get_shipping_total' ) ? $cart->get_shipping_total() : 0,
                'total'          => method_exists( $cart, 'get_total' ) ? $cart->get_total() : 0,
            )
        );
    }

    /**
     * All cart routes are public (session-based).
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return true
     */
    public function public_permissions_check( $request ) {
        $ip = class_exists( '\TejCart\Security\Rate_Limiter' )
            ? \TejCart\Security\Rate_Limiter::get_client_ip()
            : ( isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown' );

        $session = isset( $_COOKIE['tejcart_session'] )
            ? substr( sanitize_text_field( wp_unslash( $_COOKIE['tejcart_session'] ) ), 0, 64 )
            : 'guest';

        $method = method_exists( $request, 'get_method' ) ? $request->get_method() : 'GET';
        $limit  = in_array( $method, array( 'POST', 'PUT', 'DELETE', 'PATCH' ), true ) ? 30 : 120;

        /**
         * Filter the cart API rate limit (requests per minute) per IP+session.
         *
         * @param int    $limit   Request limit per 60-second window.
         * @param string $method  HTTP method.
         */
        $limit = (int) apply_filters( 'tejcart_cart_api_rate_limit', $limit, $method );

        // F-SEC-001: use the atomic Rate_Limiter::check_and_record() path
        // (wp_cache_incr under Redis/Memcached) instead of the non-atomic
        // get → check → set sequence that had a TOCTOU race.  The bucket key
        // is hashed from ip|session|method so concurrent same-bucket requests
        // are serialised through the cache layer's atomic increment.
        $bucket = substr( hash( 'sha256', $ip . '|' . $session . '|' . $method ), 0, 32 );

        if ( class_exists( '\TejCart\Security\Rate_Limiter' )
            && \TejCart\Security\Rate_Limiter::check_and_record( 'cart_api', $bucket, $limit, 60 )
        ) {
            return new WP_Error(
                'tejcart_rate_limited',
                __( 'Too many cart requests. Please slow down and try again.', 'tejcart' ),
                array( 'status' => 429 )
            );
        }

        return true;
    }

    /**
     * Resolve the cart instance for this request.
     *
     * Uses the shared per-request singleton (`tejcart_get_cart()`) rather than
     * `new Cart()`. A fresh `new Cart()` per call meant a single request could
     * build several Cart_Session objects; on a first request (no cookie yet)
     * each minted its own session key and emitted its own `Set-Cookie`, so the
     * client kept one key while the item was persisted under another — the
     * cart read back empty. Sharing the singleton keeps one session, one
     * cookie, one persisted state. Falls back to `new Cart()` only if the
     * global helper is somehow unavailable.
     *
     * @return Cart
     */
    private function resolve_cart() {
        return function_exists( 'tejcart_get_cart' ) ? tejcart_get_cart() : new Cart();
    }

    /**
     * Get the current cart contents.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response
     */
    public function get_cart( $request ) {
        $cart     = $this->resolve_cart();
        $response = $this->prepare_cart_response( $cart, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Add a product to the cart.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function add_item( $request ) {
        $product_id = absint( $request->get_param( 'product_id' ) );
        $quantity   = absint( $request->get_param( 'quantity' ) );
        if ( $quantity < 1 ) $quantity = 1;
        if ( $quantity > 10000 ) {
            return new WP_Error( 'invalid_quantity', __( 'Quantity too large.', 'tejcart' ), array( 'status' => 400 ) );
        }
        $data       = $request->get_param( 'data' );

        if ( ! is_array( $data ) ) {
            $data = array();
        }

        $data = self::sanitize_cart_data( $data );

        if ( ! $product_id ) {
            return new WP_Error(
                'tejcart_rest_invalid_product_id',
                __( 'A valid product ID is required.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $cart          = $this->resolve_cart();
        $cart_item_key = $cart->add( $product_id, $quantity, $data );

        if ( is_wp_error( $cart_item_key ) ) {
            $cart_item_key->add_data( array( 'status' => 400 ) );
            return $cart_item_key;
        }

        if ( false === $cart_item_key ) {
            return new WP_Error(
                'tejcart_rest_cart_add_failed',
                __( 'Could not add the item to the cart.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $response = $this->prepare_cart_response( $cart, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Update a cart item quantity.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function update_item( $request ) {
        $key      = sanitize_text_field( $request->get_param( 'key' ) );
        $quantity = absint( $request->get_param( 'quantity' ) );
        if ( $quantity < 1 ) $quantity = 1;
        if ( $quantity > 10000 ) {
            return new WP_Error( 'invalid_quantity', __( 'Quantity too large.', 'tejcart' ), array( 'status' => 400 ) );
        }

        $cart = $this->resolve_cart();
        $item = $cart->get_item( $key );

        if ( ! $item ) {
            return new WP_Error(
                'tejcart_rest_cart_item_not_found',
                __( 'Cart item not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $result = $cart->update_quantity( $key, $quantity );

        if ( ! $result ) {
            return new WP_Error(
                'tejcart_rest_cart_update_failed',
                __( 'Could not update the cart item.', 'tejcart' ),
                array( 'status' => 500 )
            );
        }

        $response = $this->prepare_cart_response( $cart, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Remove an item from the cart.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function remove_item( $request ) {
        $key = sanitize_text_field( $request->get_param( 'key' ) );

        $cart   = $this->resolve_cart();
        $result = $cart->remove( $key );

        if ( ! $result ) {
            return new WP_Error(
                'tejcart_rest_cart_item_not_found',
                __( 'Cart item not found.', 'tejcart' ),
                array( 'status' => 404 )
            );
        }

        $response = $this->prepare_cart_response( $cart, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Empty the entire cart.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response
     */
    public function empty_cart( $request ) {
        $cart = $this->resolve_cart();
        $cart->empty_cart();

        $response = $this->prepare_cart_response( $cart, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Apply a coupon to the cart.
     *
     * @param WP_REST_Request $request Full details about the request.
     * @return WP_REST_Response|WP_Error
     */
    public function apply_coupon( $request ) {
        $code = sanitize_text_field( $request->get_param( 'code' ) );

        if ( empty( $code ) ) {
            return new WP_Error(
                'tejcart_rest_coupon_code_required',
                __( 'A coupon code is required.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $cart   = $this->resolve_cart();
        $result = $cart->apply_coupon( $code );

        if ( is_wp_error( $result ) ) {
            $result->add_data( array( 'status' => 400 ) );
            return $result;
        }

        if ( ! $result ) {
            return new WP_Error(
                'tejcart_rest_coupon_invalid',
                __( 'The coupon could not be applied.', 'tejcart' ),
                array( 'status' => 400 )
            );
        }

        $response = $this->prepare_cart_response( $cart, $request );

        return rest_ensure_response( $response );
    }

    /**
     * Recursively sanitize cart item data.
     *
     * Underscore-prefixed keys are dropped at every depth: those are
     * reserved for plugin-internal snapshots (e.g. `_price_at_add`)
     * that a REST caller must NOT be able to seed. See report finding
     * C-1 (price manipulation) for the threat model.
     *
     * @param array $data  Data to sanitize.
     * @param int   $depth Current recursion depth.
     * @return array Sanitized data.
     */
    private static function sanitize_cart_data( array $data, int $depth = 0 ): array {
        if ( $depth > 3 ) return array();
        $clean = array();
        foreach ( $data as $key => $value ) {
            $k = sanitize_text_field( (string) $key );
            if ( '' === $k || '_' === $k[0] ) {
                continue;
            }
            if ( is_array( $value ) ) {
                $clean[ $k ] = self::sanitize_cart_data( $value, $depth + 1 );
            } elseif ( is_string( $value ) ) {
                $clean[ $k ] = sanitize_text_field( $value );
            } elseif ( is_int( $value ) || is_float( $value ) || is_bool( $value ) ) {
                $clean[ $k ] = $value;
            }
        }
        return $clean;
    }

    /**
     * Prepare cart data for response.
     *
     * L-01: every monetary field is round-tripped through the
     * {@see Money} value object so headless clients see consistent
     * precision across currency decimals (zero-decimal JPY/KRW,
     * three-decimal KWD/BHD/OMR). Callers can pass `?format=minor` to
     * receive integer minor units instead of decimal strings.
     *
     * @param Cart                 $cart    Cart instance.
     * @param WP_REST_Request|null $request REST request (for `format`).
     * @return array
     */
    protected function prepare_cart_response( $cart, $request = null ) {
        $items      = $cart->get_items();
        $items_data = array();

        foreach ( $items as $key => $item ) {
            $items_data[] = $item->to_array();
        }

        $currency = function_exists( 'tejcart_get_currency' ) ? tejcart_get_currency() : 'USD';
        if ( ! is_string( $currency ) || ! Currency::is_valid_shape( strtoupper( $currency ) ) ) {
            $currency = 'USD';
        }
        $currency = strtoupper( $currency );

        $format = '';
        if ( null !== $request && method_exists( $request, 'get_param' ) ) {
            $format = (string) $request->get_param( 'format' );
        }
        $emit_minor = 'minor' === strtolower( $format );

        $coupons      = $cart->get_coupons();
        $coupons_data = array();

        foreach ( $coupons as $code => $coupon ) {
            $amount = isset( $coupon['amount'] ) ? (float) $coupon['amount'] : 0.0;
            $type   = isset( $coupon['discount_type'] ) ? (string) $coupon['discount_type'] : '';

            $coupons_data[] = array(
                'code'          => $code,
                'discount_type' => $type,
                // Percent coupons carry `amount` as a percent rate
                // (e.g. 10 for 10%), not a Money amount, so they stay
                // numeric. Fixed coupons go through Money.
                'amount'        => 'percent' === $type
                    ? $amount
                    : $this->money_to_response( $amount, $currency, $emit_minor ),
            );
        }

        return array(
            'items'          => $items_data,
            'item_count'     => $cart->get_item_count(),
            'currency'       => $currency,
            'coupons'        => $coupons_data,
            'subtotal'       => $this->money_to_response( $cart->get_subtotal(), $currency, $emit_minor ),
            'discount_total' => $this->money_to_response( $cart->get_discount_total(), $currency, $emit_minor ),
            'shipping_total' => $this->money_to_response( $cart->get_shipping_total(), $currency, $emit_minor ),
            'tax_total'      => $this->money_to_response( $cart->get_tax_total(), $currency, $emit_minor ),
            'total'          => $this->money_to_response( $cart->get_total(), $currency, $emit_minor ),
            'needs_shipping' => $cart->needs_shipping(),
            'is_empty'       => $cart->is_empty(),
        );
    }

    /**
     * L-01: serialise a major-unit float through Money so the wire
     * representation matches the currency's actual decimal count.
     *
     * Returns an integer minor-unit value when `?format=minor` was
     * requested, otherwise a decimal string with the right number of
     * trailing zeros for the currency.
     *
     * @param float|int|string $amount     Major-unit amount.
     * @param string           $currency   ISO 4217 code.
     * @param bool             $emit_minor Emit minor units instead of decimal string.
     * @return int|string
     */
    private function money_to_response( $amount, string $currency, bool $emit_minor ) {
        $minor = Currency::to_minor_units( $amount, $currency );
        $money = Money::from_minor_units( $minor, $currency );
        return $emit_minor ? $money->as_minor_units() : $money->as_decimal_string();
    }
}
